<?php
/**
 * robots.txt rules, written alongside whatever else already writes there.
 *
 * Every SEO plugin edits robots.txt, and two of the big ones can replace the
 * whole file. So this class never assumes its filter had the last word: it
 * appends, it steps aside when another plugin already speaks for a crawler,
 * and the admin screen checks the live file to see what actually shipped.
 *
 * @package AumCrawl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * robots.txt integration.
 */
class AumViso_Crawl_Robots {

	const BEGIN = '# BEGIN AI Crawler Control';
	const END   = '# END AI Crawler Control';

	/**
	 * Option recording which crawlers another plugin already claimed.
	 */
	const DEFERRED = 'aumviso_crawl_deferred';

	/**
	 * Hook up at a late priority so the SEO plugins have already written
	 * theirs and we can see what is there before adding to it.
	 */
	public function __construct() {
		add_filter( 'robots_txt', array( $this, 'append_rules' ), 20, 2 );
	}

	/**
	 * Append our block to the robots.txt body.
	 *
	 * @param string $output Existing body.
	 * @param bool   $public Whether the site is set to be indexed.
	 * @return string
	 */
	public function append_rules( $output, $public ) {
		$blocked = $this->blocked_tokens();
		$taken   = $this->tokens_already_claimed( (string) $output );

		$deferred = array();
		$lines    = array();

		foreach ( $blocked as $slug => $token ) {
			if ( in_array( strtolower( $token ), $taken, true ) ) {
				$deferred[] = $slug;
				continue;
			}

			$lines[] = 'User-agent: ' . $token;
			$lines[] = 'Disallow: /';
			$lines[] = '';
		}

		// Remembered so the settings screen can name the plugin that owns
		// those crawlers instead of silently dropping our rule.
		update_option( self::DEFERRED, $deferred, false );

		if ( ! $lines ) {
			return $output;
		}

		array_pop( $lines );

		return rtrim( (string) $output ) . "\n\n" . self::BEGIN . "\n" . implode( "\n", $lines ) . "\n" . self::END . "\n";
	}

	/**
	 * Crawler tokens the site owner has switched off.
	 *
	 * @return array slug => robots token
	 */
	public function blocked_tokens() {
		$out = array();

		foreach ( AumViso_Crawl_Bots::all() as $slug => $bot ) {
			if ( ! AumViso_Crawl_Bots::is_switchable( $slug ) ) {
				continue;
			}

			if ( aumviso_crawl_is_blocked( $slug ) && ! empty( $bot['robots'] ) ) {
				$out[ $slug ] = $bot['robots'];
			}
		}

		return $out;
	}

	/**
	 * User-agent tokens already present in the body.
	 *
	 * A wildcard group is not a claim on any particular crawler, so it is
	 * ignored here; only an exact token counts.
	 *
	 * @param string $output Existing robots.txt body.
	 * @return array Lower-cased tokens.
	 */
	protected function tokens_already_claimed( $output ) {
		if ( '' === $output ) {
			return array();
		}

		// Our own previous block must not count as somebody else's claim.
		$output = preg_replace(
			'/' . preg_quote( self::BEGIN, '/' ) . '.*?' . preg_quote( self::END, '/' ) . '/s',
			'',
			$output
		);

		preg_match_all( '/^\s*User-agent:\s*(\S+)/mi', (string) $output, $matches );

		$tokens = array_map( 'strtolower', $matches[1] );

		return array_values( array_diff( array_unique( $tokens ), array( '*' ) ) );
	}

	/**
	 * The rule block as plain text, for the copy-and-paste fallback.
	 *
	 * @return string
	 */
	public function rules_text() {
		$lines = array( self::BEGIN );

		foreach ( $this->blocked_tokens() as $token ) {
			$lines[] = 'User-agent: ' . $token;
			$lines[] = 'Disallow: /';
			$lines[] = '';
		}

		if ( 1 === count( $lines ) ) {
			return '';
		}

		array_pop( $lines );
		$lines[] = self::END;

		return implode( "\n", $lines );
	}

	/**
	 * What the site actually serves at /robots.txt right now.
	 *
	 * Fetching the real file is the only honest check. A filter can be
	 * overwritten by a plugin running later, and a physical robots.txt on disk
	 * stops WordPress consulting the filters at all -- neither is visible from
	 * inside our own hook.
	 *
	 * @param bool $refresh Skip the cache.
	 * @return array {
	 *     @type bool   $reachable   Whether the request succeeded.
	 *     @type bool   $has_rules   Whether our block is in the served file.
	 *     @type bool   $physical    Whether a robots.txt file exists on disk.
	 *     @type string $body        The served body.
	 * }
	 */
	public static function live_status( $refresh = false ) {
		$cached = get_transient( 'aumviso_crawl_robots_status' );

		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			home_url( '/robots.txt' ),
			array(
				'timeout'   => 8,
				'sslverify' => false,
			)
		);

		$body   = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
		$status = array(
			'reachable' => ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ),
			'has_rules' => false !== strpos( $body, self::BEGIN ),
			'physical'  => file_exists( ABSPATH . 'robots.txt' ),
			'body'      => $body,
		);

		set_transient( 'aumviso_crawl_robots_status', $status, 5 * MINUTE_IN_SECONDS );

		return $status;
	}
}
