<?php
/**
 * Confirms a crawler is who it says it is, without keeping the address.
 *
 * A User-Agent is a claim, not an identity: anyone can send
 * "User-Agent: GPTBot". The check here is forward-confirmed reverse DNS, the
 * method the crawler vendors themselves document. The address is used inside
 * one request and thrown away; only the verdict, and at most an anonymised
 * network range, is ever written down.
 *
 * @package AumCrawl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Crawler authenticity checks.
 */
class AumViso_Crawl_Verify {

	/**
	 * Lookups allowed per hour, so a flood of spoofed agents from many
	 * addresses cannot turn this plugin into a DNS amplifier against its own
	 * site.
	 */
	const LOOKUP_BUDGET = 120;

	/**
	 * How long a verdict for one address stays cached.
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Reverse-DNS suffixes of the big CDNs. When the connecting address is one
	 * of these, the real client is hidden behind it and any verdict we reach
	 * would be about the CDN, not the crawler.
	 */
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- these are not resources this plugin loads; they are hostname suffixes compared against a reverse-DNS result, so that traffic arriving through a CDN is reported as unverifiable instead of being called forged.
	const PROXY_HOSTS = array( 'cloudflare.com', 'cloudfront.net', 'fastly.net', 'akamaitechnologies.com' );

	/**
	 * Hook the budget reset.
	 */
	public function __construct() {}

	/**
	 * Verdict for the current request.
	 *
	 * @param string $slug Bot slug.
	 * @return string ok|bad|unknown
	 */
	public static function check( $slug ) {
		$settings = aumviso_crawl_get_settings();

		if ( empty( $settings['verify_bots'] ) ) {
			return 'unknown';
		}

		$bots = AumViso_Crawl_Bots::all();

		// Nothing to check against: several vendors publish no verifiable
		// hostname, and Google-Extended is a robots.txt token with no crawler
		// behind it at all.
		if ( empty( $bots[ $slug ]['verify'] ) ) {
			return 'unknown';
		}

		$ip = self::client_ip();

		if ( '' === $ip ) {
			return 'unknown';
		}

		// A private or loopback address means the real client is on the other
		// side of something -- nginx in front of php-fpm, a container network,
		// a load balancer. Reverse DNS would describe that hop, not the
		// crawler, and calling honest traffic forged is the one mistake that
		// would make this whole report worthless.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return 'unknown';
		}

		$key    = 'aumviso_crawl_v_' . md5( $slug . '|' . $ip . '|' . wp_salt() );
		$cached = get_transient( $key );

		if ( false !== $cached ) {
			return (string) $cached;
		}

		if ( ! self::spend_budget() ) {
			return 'unknown';
		}

		$verdict = self::forward_confirmed_reverse_dns( $ip, $bots[ $slug ]['verify'] );

		set_transient( $key, $verdict, self::CACHE_TTL );

		return $verdict;
	}

	/**
	 * Forward-confirmed reverse DNS.
	 *
	 * Deliberately reluctant to say "bad". A missing PTR record is ordinary
	 * and proves nothing, so it reads as unknown; only a hostname that
	 * resolves and belongs to somebody else counts as a forged agent. Crying
	 * fraud at honest traffic would make the whole report worthless.
	 *
	 * @param string $ip      Connecting address.
	 * @param array  $domains Hostname suffixes the vendor publishes.
	 * @return string ok|bad|unknown
	 */
	protected static function forward_confirmed_reverse_dns( $ip, array $domains ) {
		$host = @gethostbyaddr( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a failed lookup is an expected outcome, handled below.

		if ( ! $host || $host === $ip ) {
			return 'unknown';
		}

		$host = strtolower( rtrim( $host, '.' ) );

		// Behind a CDN the connecting address belongs to the CDN, so no
		// verdict about the crawler is possible from here.
		foreach ( self::PROXY_HOSTS as $proxy ) {
			if ( self::host_ends_with( $host, $proxy ) ) {
				return 'unknown';
			}
		}

		$claimed = false;

		foreach ( $domains as $domain ) {
			if ( self::host_ends_with( $host, strtolower( $domain ) ) ) {
				$claimed = true;
				break;
			}
		}

		if ( ! $claimed ) {
			return 'bad';
		}

		return self::forward_matches( $host, $ip ) ? 'ok' : 'bad';
	}

	/**
	 * Does the hostname resolve back to the same address?
	 *
	 * Without this step a PTR record alone proves nothing: whoever controls
	 * the reverse zone for an address can point it anywhere.
	 *
	 * @param string $host Hostname from the PTR record.
	 * @param string $ip   Original address.
	 * @return bool
	 */
	protected static function forward_matches( $host, $ip ) {
		if ( false !== strpos( $ip, ':' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- lookup failure is handled.

			if ( ! is_array( $records ) ) {
				return false;
			}

			foreach ( $records as $record ) {
				if ( isset( $record['ipv6'] ) && 0 === strcasecmp( $record['ipv6'], $ip ) ) {
					return true;
				}
			}

			return false;
		}

		$resolved = @gethostbyname( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- lookup failure is handled.

		return $resolved === $ip;
	}

	/**
	 * Suffix match on a dot boundary, so "notopenai.com" cannot pass as
	 * "openai.com".
	 *
	 * @param string $host   Hostname.
	 * @param string $suffix Domain suffix.
	 * @return bool
	 */
	protected static function host_ends_with( $host, $suffix ) {
		return $host === $suffix || substr( $host, -strlen( $suffix ) - 1 ) === '.' . $suffix;
	}

	/**
	 * Take one lookup from this hour's budget.
	 *
	 * @return bool True when a lookup may proceed.
	 */
	protected static function spend_budget() {
		$key   = 'aumviso_crawl_dns_budget';
		$spent = (int) get_transient( $key );

		if ( $spent >= self::LOOKUP_BUDGET ) {
			return false;
		}

		set_transient( $key, $spent + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * The connecting address.
	 *
	 * Only REMOTE_ADDR is trusted by default. Forwarded-for headers are
	 * attacker-controlled, so a site behind a proxy has to opt in through the
	 * filter and take responsibility for which header it trusts.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		/**
		 * Filters the address used for crawler verification.
		 *
		 * @param string $ip Address from REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'aumviso_crawl_client_ip', $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Reduce an address to a range that no longer points at one person.
	 *
	 * IPv4 loses its last octet, IPv6 everything below the /48 -- the same
	 * treatment analytics products apply before storage. Crawlers run from
	 * datacentre blocks, so the range still identifies the operator, which is
	 * all the report needs.
	 *
	 * @param string $ip Address.
	 * @return string Anonymised range, or '' when the address is unusable.
	 */
	public static function anonymise_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';

			return implode( '.', $parts );
		}

		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- validated above; failure handled.

		if ( false === $packed ) {
			return '';
		}

		// Keep the first 48 bits, zero the remaining 80.
		$packed = substr( $packed, 0, 6 ) . str_repeat( "\0", 10 );

		return (string) inet_ntop( $packed );
	}

	/**
	 * Note that a request failed verification.
	 *
	 * @param string $slug Bot slug.
	 * @return void
	 */
	public static function record_suspect( $slug ) {
		global $wpdb;

		$net = self::anonymise_ip( self::client_ip() );

		if ( '' === $net ) {
			return;
		}

		$table = AumViso_Crawl_Install::table( 'suspect' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix; every value is prepared.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (hit_date, bot, net, hits)
				 VALUES (%s, %s, %s, 1)
				 ON DUPLICATE KEY UPDATE hits = hits + 1",
				gmdate( 'Y-m-d' ),
				$slug,
				$net
			)
		);
	}
}
