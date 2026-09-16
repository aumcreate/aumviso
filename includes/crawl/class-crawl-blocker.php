<?php
/**
 * Turning away crawlers that ignore robots.txt.
 *
 * robots.txt is a request. This is the part with teeth -- but only against
 * crawlers that identify themselves honestly, which is worth being clear
 * about: anything determined to hide will simply not say who it is.
 *
 * @package AumCrawl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User-Agent refusal.
 */
class AumViso_Crawl_Blocker {

	/**
	 * Runs after the logger, so a refused visit is still counted. Seeing that
	 * a crawler tried four hundred times and was turned away every time is
	 * more useful than seeing nothing.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'maybe_refuse' ), 6 );
	}

	/**
	 * The requested path, without the query string.
	 *
	 * @return string
	 */
	protected function request_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		return (string) wp_parse_url( $uri, PHP_URL_PATH );
	}

	/**
	 * Refuse the request when the crawler is switched off.
	 *
	 * @return void
	 */
	public function maybe_refuse() {
		$settings = aumviso_crawl_get_settings();

		if ( empty( $settings['hard_block'] ) ) {
			return;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// A blocked crawler still has to be able to read robots.txt, or it can
		// never learn that it is unwelcome and will simply keep trying.
		//
		// The path is read straight from the request rather than through
		// is_robots(), which answers from the main query and is therefore
		// still false this early on `init`.
		if ( '/robots.txt' === $this->request_path() ) {
			return;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 400 )
			: '';

		$slug = AumViso_Crawl_Bots::identify( $ua );

		if ( '' === $slug ) {
			return;
		}

		// Second gate on top of the settings screen. A search engine or a link
		// preview must never be refused because of a bad option value.
		if ( ! AumViso_Crawl_Bots::is_switchable( $slug ) || ! aumviso_crawl_is_blocked( $slug ) ) {
			return;
		}

		nocache_headers();
		status_header( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo "Blocked by robots policy.\n";
		exit;
	}
}
