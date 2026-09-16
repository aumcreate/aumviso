<?php
/**
 * The settings screen.
 *
 * One page, read top to bottom: what is working, who came, what to do about
 * it. The switches sit next to the evidence on purpose -- deciding whether to
 * turn GPTBot away is a different question once you can see it was here two
 * hours ago.
 *
 * @package AumCrawl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen.
 */
class AumViso_Crawl_Admin {

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	protected $screen = '';

	/**
	 * Hook up.
	 */
	public function __construct() {
		/* No page of its own — AumViso's main screen carries this as a tab. See add_page(). */
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_aumviso_crawl_save', array( $this, 'save' ) );
		add_action( 'admin_post_aumviso_crawl_clear', array( $this, 'clear' ) );

	}

	/**
	 * Register the page.
	 *
	 * @return void
	 */
	/**
	 * No submenu.
	 *
	 * 🔴 It had one at first, because it arrived as a port of a standalone plugin that owned its own page —
	 * a faithful move that never asked whether the destination wanted it. The result was two entries under
	 * the theme menu, "SEO/GEO" and "Crawlers", for one plugin: the buyer has to learn that the same
	 * product lives in two places and guess which holds what.
	 *
	 * That is the same complaint that merged the "Pro" console and folded five technical tabs into one, and
	 * it applies here for the same reason. Crawlers is a tab.
	 *
	 * @return void
	 */
	public function add_page(): void {
	}





	/** True on AumViso's page while the Crawlers tab is showing. */
	private function is_crawler_tab( $hook ): bool {
		if ( strpos( (string) $hook, 'aumviso' ) === false ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['tab'] ) && 'crawlers' === sanitize_key( wp_unslash( $_GET['tab'] ) );
	}

	/**
	 * Stylesheet, on this screen only.
	 *
	 * @param string $hook Current screen hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		/*
		 * ⚠️ Keyed to **AumViso's** page, not one of this class's own.
		 *
		 * `$this->screen` was the hook returned by the submenu this module used to register. It no longer
		 * registers one, so that property stays empty and the old guard rejected every request — the
		 * stylesheet would simply never load, and the tab would render unstyled with nothing to explain it.
		 */
		if ( ! $this->is_crawler_tab( $hook ) ) {
			return;
		}

		$rel  = 'assets/crawl.css';
		$path = AUMVISO_DIR . 'includes/crawl/' . $rel;

		wp_enqueue_style(
			'aumcrawl-admin',
			plugins_url( 'includes/crawl/' . $rel, AUMVISO_FILE ),
			array( 'dashicons' ),
			file_exists( $path ) ? (string) filemtime( $path ) : AUMVISO_VERSION
		);
	}

	// ------------------------------------
	// Data
	// ------------------------------------

	/**
	 * Start of the reporting window.
	 *
	 * @return string Y-m-d
	 */
	public function window_start() {
		$settings = aumviso_crawl_get_settings();
		$days     = max( 7, min( 365, (int) $settings['retain_days'] ) );

		return gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Per-crawler totals for the window.
	 *
	 * @return array slug => array{hits:int,verified:int,unverified:int,last:string}
	 */
	public function bot_stats() {
		global $wpdb;

		$table = AumViso_Crawl_Install::table( 'daily' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix; the date is prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bot, SUM(hits) AS hits, SUM(verified) AS verified,
				        SUM(unverified) AS unverified, MAX(hit_date) AS last
				 FROM {$table} WHERE hit_date >= %s GROUP BY bot",
				$this->window_start()
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row['bot'] ] = array(
				'hits'       => (int) $row['hits'],
				'verified'   => (int) $row['verified'],
				'unverified' => (int) $row['unverified'],
				'last'       => (string) $row['last'],
			);
		}

		return $out;
	}

	/**
	 * Most crawled paths.
	 *
	 * @param int $limit Rows to return.
	 * @return array
	 */
	public function top_pages( $limit = 10 ) {
		global $wpdb;

		$table = AumViso_Crawl_Install::table( 'pages' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix; values prepared.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url, SUM(hits) AS hits FROM {$table}
				 WHERE hit_date >= %s GROUP BY url ORDER BY hits DESC LIMIT %d",
				$this->window_start(),
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Network ranges that claimed to be a crawler and failed the check.
	 *
	 * @param int $limit Rows to return.
	 * @return array
	 */
	public function suspects( $limit = 10 ) {
		global $wpdb;

		$table = AumViso_Crawl_Install::table( 'suspect' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix; values prepared.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bot, net, SUM(hits) AS hits FROM {$table}
				 WHERE hit_date >= %s GROUP BY bot, net ORDER BY hits DESC LIMIT %d",
				$this->window_start(),
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Whether the identity check is actually producing verdicts.
	 *
	 * The check itself is subject to the same rule as everything else here:
	 * do not assume a mechanism works because it is switched on. Behind a CDN
	 * or a reverse proxy the connecting address belongs to that hop, so no
	 * verdict is reachable and the feature quietly does nothing.
	 *
	 * @param array $stats Per-crawler totals.
	 * @return string off|no-data|no-results|working
	 */
	public function verification_status( $stats ) {
		$settings = aumviso_crawl_get_settings();

		if ( empty( $settings['verify_bots'] ) ) {
			return 'off';
		}

		$hits    = 0;
		$verdict = 0;

		foreach ( $stats as $row ) {
			$hits    += $row['hits'];
			$verdict += $row['verified'] + $row['unverified'];
		}

		if ( 0 === $hits ) {
			return 'no-data';
		}

		return $verdict > 0 ? 'working' : 'no-results';
	}

	/**
	 * Which page cache, if any, is intercepting requests before PHP runs.
	 *
	 * Needed because those requests never reach the logger, so the totals on
	 * this screen would otherwise look complete when they are not.
	 *
	 * @return string Plugin name, or ''.
	 */
	public function detect_page_cache() {
		$known = array(
			'WP_ROCKET_VERSION'   => 'WP Rocket',
			'W3TC'                => 'W3 Total Cache',
			'WPCACHEHOME'         => 'WP Super Cache',
			'LSCWP_V'             => 'LiteSpeed Cache',
			'WPFC_MAIN_PATH'      => 'WP Fastest Cache',
		);

		foreach ( $known as $constant => $label ) {
			if ( defined( $constant ) ) {
				return $label;
			}
		}

		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'endurance-page-cache/endurance-page-cache.php' ) ) {
			return 'Endurance Page Cache';
		}

		return '';
	}

	// ------------------------------------
	// Save
	// ------------------------------------

	/**
	 * Handle the form.
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'aumviso' ) );
		}

		check_admin_referer( 'aumviso_crawl_save' );

		$settings = aumviso_crawl_get_settings();
		$posted   = isset( $_POST['aumviso_crawl_blocked'] )
			? array_map( 'sanitize_key', (array) wp_unslash( $_POST['aumviso_crawl_blocked'] ) )
			: array();

		$blocked = array();

		foreach ( $posted as $slug ) {
			$slug = sanitize_key( $slug );

			// Only crawlers the plugin offers a switch for can ever land here,
			// whatever the form said.
			if ( AumViso_Crawl_Bots::is_switchable( $slug ) ) {
				$blocked[] = $slug;
			}
		}

		$settings['blocked']          = array_values( array_unique( $blocked ) );
		$settings['send_noai_header'] = isset( $_POST['aumviso_crawl_noai'] ) ? 1 : 0;
		$settings['hard_block']       = isset( $_POST['aumviso_crawl_hard'] ) ? 1 : 0;
		$settings['verify_bots']      = isset( $_POST['aumviso_crawl_verify'] ) ? 1 : 0;
		$retain                       = isset( $_POST['aumviso_crawl_retain'] ) ? absint( wp_unslash( $_POST['aumviso_crawl_retain'] ) ) : 30;
		$settings['retain_days']      = max( 7, min( 365, $retain ) );

		update_option( AumViso_Crawl_Module::OPTION, $settings );

		// robots.txt just changed, so the cached view of the live file is stale.
		delete_transient( 'aumviso_crawl_robots_status' );

		wp_safe_redirect(
			add_query_arg(
				/* Back to this tab, not the front of the page — see the note on add_page(). */
				array( 'tab' => 'crawlers', 'updated' => '1' ),
				menu_page_url( 'aumviso', false )
			)
		);
		exit;
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public function clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'aumviso' ) );
		}

		check_admin_referer( 'aumviso_crawl_clear' );

		global $wpdb;

		foreach ( array( 'daily', 'pages', 'suspect' ) as $which ) {
			$table = AumViso_Crawl_Install::table( $which );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name built from $wpdb->prefix.
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}

		delete_transient( AumViso_Crawl_Logger::CAP_KEY );

		wp_safe_redirect(
			add_query_arg(
				/* Back to this tab, not the front of the page — see the note on add_page(). */
				array( 'tab' => 'crawlers', 'cleared' => '1' ),
				menu_page_url( 'aumviso', false )
			)
		);
		exit;
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require AUMVISO_DIR . 'includes/crawl/view-crawl.php';
	}
}
