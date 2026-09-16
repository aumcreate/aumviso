<?php
/**
 * Crawler visibility, inside AumViso.
 *
 * Ported 2026-09-03 from the standalone **AI Crawler Control** plugin, which stays published on its own.
 * The two answer different needs: someone who only wants to see who is crawling installs that one; someone
 * running AumViso should not have to install a second plugin to get the one screen in this whole product
 * that a non-specialist can read at a glance.
 *
 * ── Why it is worth carrying ─────────────────────────────────────
 * Every other feature here — schema, canonicals, llms.txt, internal links — is invisible and takes a
 * document to appreciate. "Baidu came 40 times this week and it really was Baidu" is legible on sight, and
 * it is the same underlying question the rest of the plugin exists to answer.
 *
 * ── 🔴 It stands down when the standalone plugin is active ───────
 * Both write robots.txt rules and both log visits. Two copies running would produce **duplicated crawler
 * directives** and two divergent visit tables, and whichever wrote last would appear to be the truth. So
 * when AI Crawler Control is present it owns the job entirely and this module does nothing — the same rule
 * this stack already applies to SEO metadata: whatever owns a thing owns all of it.
 *
 * The check is one-directional. AI Crawler Control is published separately and knows nothing about AumViso,
 * which is how it should stay.
 *
 * ⚠️ **Separate tables**, not shared with the standalone plugin (`aumviso_crawl_*` against `aumcrawl_*`).
 * Sharing would look tidier and would mean history carried over, but that plugin's `uninstall.php` drops
 * its own tables — a buyer removing it would silently take AumViso's crawler history with it.
 *
 * @package AumViso
 */

defined( 'ABSPATH' ) || exit;

final class AumViso_Crawl_Module {

	const OPTION = 'aumviso_crawl_settings';

	/** True when the standalone plugin is running and should be left to it. */
	public static function superseded(): bool {
		return defined( 'AUMCRAWL_VERSION' ) || class_exists( 'AumCrawl_Logger' );
	}

	public static function boot(): void {
		if ( self::superseded() ) {
			return;
		}

		$dir = AUMVISO_DIR . 'includes/crawl/';
		foreach ( array( 'bots', 'install', 'logger', 'robots', 'headers', 'blocker', 'verify', 'privacy' ) as $part ) {
			require_once $dir . 'class-crawl-' . $part . '.php';
		}

		AumViso_Crawl_Install::maybe_create_tables();

		new AumViso_Crawl_Logger();
		new AumViso_Crawl_Robots();
		new AumViso_Crawl_Headers();
		new AumViso_Crawl_Blocker();
		new AumViso_Crawl_Verify();
		new AumViso_Crawl_Privacy();

		if ( is_admin() ) {
			require_once $dir . 'class-crawl-admin.php';
			new AumViso_Crawl_Admin();
		}
	}

	/**
	 * A few numbers for the Overview screen.
	 *
	 * The full crawler page has thirty-five rows and a column of switches; putting that on Overview would
	 * replace one wall with another. This is the headline — how many came, how many visits, whether any
	 * failed identity checks — with the page itself one click away.
	 *
	 * Returns null when there is nothing to say (module standing down, tables absent, no visits yet), so
	 * the caller can leave the card out rather than print a row of zeroes that reads like a problem.
	 *
	 * @return array{bots:int,hits:int,verified:int,suspect:int}|null
	 */
	public static function summary(): ?array {
		global $wpdb;

		if ( self::superseded() || ! class_exists( 'AumViso_Crawl_Install' ) ) {
			return null;
		}

		$daily = AumViso_Crawl_Install::table( 'daily' );
		$since = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT bot) AS bots, SUM(hits) AS hits, SUM(verified) AS verified
				 FROM {$daily} WHERE hit_date >= %s",
				$since
			),
			ARRAY_A
		);

		if ( empty( $row['hits'] ) ) {
			return null;
		}

		$suspect = AumViso_Crawl_Install::table( 'suspect' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$forged = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$suspect} WHERE hit_date >= %s", $since ) );

		return [
			'bots'     => (int) $row['bots'],
			'hits'     => (int) $row['hits'],
			'verified' => (int) $row['verified'],
			'suspect'  => $forged,
		];
	}

	/** The tab this module contributes to AumViso's page, or none when standing down. */
	public static function tabs(): array {
		if ( self::superseded() ) {
			return [];
		}
		return [
			'crawlers' => [
				'label' => __( 'Crawlers', 'aumviso' ),
				'icon'  => 'dashicons-visibility',
				'ready' => true,
			],
		];
	}

	public static function render_body(): void {
		if ( class_exists( 'AumViso_Crawl_Admin' ) ) {
			( new AumViso_Crawl_Admin() )->render();
		}
	}

	/** Defaults, matching the standalone plugin so a buyer moving between them finds the same behaviour. */
	public static function defaults(): array {
		return array(
			'blocked'          => array(),
			'send_noai_header' => 0,
			'hard_block'       => 0,
			'verify_bots'      => 1,
			'retain_days'      => 30,
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function is_blocked( string $slug ): bool {
		return in_array( $slug, (array) self::settings()['blocked'], true );
	}
}

/**
 * The ported files call these as plain functions, exactly as they did in the standalone plugin.
 *
 * Kept as wrappers rather than rewritten across ten files: the two copies stay diffable, so a fix made in
 * one can still be read across into the other. That matters more than tidiness while both are maintained.
 */
if ( ! function_exists( 'aumviso_crawl_get_settings' ) ) {
	function aumviso_crawl_get_settings(): array {
		return AumViso_Crawl_Module::settings();
	}
}
if ( ! function_exists( 'aumviso_crawl_is_blocked' ) ) {
	function aumviso_crawl_is_blocked( $slug ): bool {
		return AumViso_Crawl_Module::is_blocked( (string) $slug );
	}
}
