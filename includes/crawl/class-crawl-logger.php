<?php
/**
 * Records crawler visits.
 *
 * This runs on every single request, so the cheap checks come first and the
 * database is only touched once a User-Agent has actually matched a known
 * crawler. Ordinary visitors cost one strlen and one loop of stripos calls.
 *
 * @package AumCrawl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visit logger.
 */
class AumViso_Crawl_Logger {

	/**
	 * Transient holding today's count of distinct detail rows.
	 */
	const CAP_KEY = 'aumviso_crawl_page_rows';

	/**
	 * Hook up.
	 */
	public function __construct() {
		// `init` rather than `template_redirect`: robots.txt, feeds and REST
		// requests are crawler traffic too, and never reach template_redirect.
		add_action( 'init', array( $this, 'record' ), 5 );
	}

	/**
	 * Identify and count the current request.
	 *
	 * @return void
	 */
	public function record() {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 400 )
			: '';

		if ( '' === $ua ) {
			return;
		}

		$slug = AumViso_Crawl_Bots::identify( $ua );

		if ( '' === $slug ) {
			return;
		}

		$verdict = AumViso_Crawl_Verify::check( $slug );

		$this->bump_daily( $slug, $verdict );
		$this->bump_page( $slug );

		if ( 'bad' === $verdict ) {
			AumViso_Crawl_Verify::record_suspect( $slug );
		}

		/**
		 * Fires once a request has been attributed to a known crawler.
		 *
		 * @param string $slug    Bot slug.
		 * @param string $verdict ok|bad|unknown.
		 */
		do_action( 'aumviso_crawl_crawler_seen', $slug, $verdict );
	}

	/**
	 * Increment the daily totals. Always runs, never capped -- these are the
	 * numbers the whole plugin reports, so they have to stay complete.
	 *
	 * @param string $slug    Bot slug.
	 * @param string $verdict ok|bad|unknown.
	 * @return void
	 */
	protected function bump_daily( $slug, $verdict ) {
		global $wpdb;

		$table    = AumViso_Crawl_Install::table( 'daily' );
		$verified = 'ok' === $verdict ? 1 : 0;
		$bad      = 'bad' === $verdict ? 1 : 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix; every value is prepared.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (hit_date, bot, hits, verified, unverified)
				 VALUES (%s, %s, 1, %d, %d)
				 ON DUPLICATE KEY UPDATE hits = hits + 1, verified = verified + %d, unverified = unverified + %d",
				gmdate( 'Y-m-d' ),
				$slug,
				$verified,
				$bad,
				$verified,
				$bad
			)
		);
	}

	/**
	 * Increment the per-URL detail, subject to the daily row cap.
	 *
	 * The cap counts only rows that were newly inserted, which
	 * $wpdb->rows_affected reports as 1 (an update reports 2). That avoids a
	 * SELECT COUNT on every request just to know whether the cap was reached.
	 *
	 * @param string $slug Bot slug.
	 * @return void
	 */
	protected function bump_page( $slug ) {
		global $wpdb;

		$rows = (int) get_transient( self::CAP_KEY );

		if ( $rows >= AumViso_Crawl_Install::PAGE_ROW_CAP ) {
			return;
		}

		$url = $this->request_path();

		if ( '' === $url ) {
			return;
		}

		$table = AumViso_Crawl_Install::table( 'pages' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix; every value is prepared.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (hit_date, bot, url_hash, url, hits)
				 VALUES (%s, %s, %s, %s, 1)
				 ON DUPLICATE KEY UPDATE hits = hits + 1",
				gmdate( 'Y-m-d' ),
				$slug,
				md5( $url ),
				$url
			)
		);

		if ( 1 === (int) $wpdb->rows_affected ) {
			set_transient( self::CAP_KEY, $rows + 1, DAY_IN_SECONDS );
		}
	}

	/**
	 * The requested path, without the query string.
	 *
	 * Query strings are dropped on purpose: campaign parameters would give
	 * every visit a unique key and blow the row cap on a single crawl.
	 *
	 * @return string
	 */
	protected function request_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$uri  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( '' === $path ) {
			$path = '/';
		}

		return substr( $path, 0, 255 );
	}
}
