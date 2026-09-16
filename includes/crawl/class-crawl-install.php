<?php
/**
 * Tables, scheduling and teardown.
 *
 * @package AumCrawl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema owner.
 */
class AumViso_Crawl_Install {

	/**
	 * Bumped when the schema changes.
	 */
	const DB_VERSION = 1;

	/**
	 * Cron hook that trims old rows.
	 */
	const CRON_PRUNE = 'aumviso_crawl_prune';

	/**
	 * Detail rows kept per day before the cap kicks in.
	 *
	 * A site with a hundred thousand URLs would otherwise write a row per
	 * crawled URL per bot per day. The daily totals stay complete either way,
	 * so the cap costs detail, never the headline numbers.
	 */
	const PAGE_ROW_CAP = 5000;

	/**
	 * Days of per-URL detail. Deliberately shorter than the totals: the detail
	 * is what grows, and "which pages did GPTBot read last week" stops being
	 * interesting long before "how often did it visit last month" does.
	 */
	const PAGE_RETAIN_DAYS = 7;

	/**
	 * Create tables and schedule the prune.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();

		if ( ! wp_next_scheduled( self::CRON_PRUNE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PRUNE );
		}

		update_option( 'aumviso_crawl_db_version', self::DB_VERSION );
	}

	/**
	 * Leave no orphaned schedule behind.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_PRUNE );
	}

	/**
	 * Table names.
	 *
	 * @param string $which daily|pages|suspect.
	 * @return string
	 */
	public static function table( $which ) {
		global $wpdb;

		return $wpdb->prefix . 'aumviso_crawl_' . $which;
	}

	/**
	 * Create or update the schema.
	 *
	 * Three tables rather than one raw log: totals that must always be right,
	 * per-URL detail that is allowed to be lossy, and the networks that failed
	 * verification.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$daily   = self::table( 'daily' );
		$pages   = self::table( 'pages' );
		$suspect = self::table( 'suspect' );

		dbDelta(
			"CREATE TABLE {$daily} (
				hit_date DATE NOT NULL,
				bot VARCHAR(48) NOT NULL,
				hits INT UNSIGNED NOT NULL DEFAULT 0,
				verified INT UNSIGNED NOT NULL DEFAULT 0,
				unverified INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (hit_date,bot)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$pages} (
				hit_date DATE NOT NULL,
				bot VARCHAR(48) NOT NULL,
				url_hash CHAR(32) NOT NULL,
				url VARCHAR(255) NOT NULL,
				hits INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (hit_date,bot,url_hash)
			) {$charset};"
		);

		// `net` holds an anonymised range, never a full address: the last IPv4
		// octet and everything below the IPv6 /48 are zeroed before it gets
		// here. See AumViso_Crawl_Verify::anonymise_ip().
		dbDelta(
			"CREATE TABLE {$suspect} (
				hit_date DATE NOT NULL,
				bot VARCHAR(48) NOT NULL,
				net VARCHAR(45) NOT NULL,
				hits INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (hit_date,bot,net)
			) {$charset};"
		);
	}

	/**
	 * Create tables if they went missing, without running on every request.
	 *
	 * @return void
	 */
	public static function maybe_create_tables() {
		if ( (int) get_option( 'aumviso_crawl_db_version', 0 ) === self::DB_VERSION ) {
			return;
		}

		self::create_tables();
		update_option( 'aumviso_crawl_db_version', self::DB_VERSION );
	}

	/**
	 * Drop rows past their retention window.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;

		$settings = aumviso_crawl_get_settings();
		$days     = max( 7, min( 365, (int) $settings['retain_days'] ) );

		$cutoff_totals = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
		$cutoff_pages  = gmdate( 'Y-m-d', time() - ( self::PAGE_RETAIN_DAYS * DAY_IN_SECONDS ) );

		foreach ( array( 'daily' => $cutoff_totals, 'suspect' => $cutoff_totals, 'pages' => $cutoff_pages ) as $which => $cutoff ) {
			$table = self::table( $which );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is built from $wpdb->prefix, the date is prepared.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE hit_date < %s", $cutoff ) );
		}
	}
}

add_action( AumViso_Crawl_Install::CRON_PRUNE, array( 'AumViso_Crawl_Install', 'prune' ) );
add_action( 'plugins_loaded', array( 'AumViso_Crawl_Install', 'maybe_create_tables' ), 1 );
