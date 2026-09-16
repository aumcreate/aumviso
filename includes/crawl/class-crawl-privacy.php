<?php
/**
 * Privacy policy text.
 *
 * The plugin stores no personal data: addresses are used inside a single
 * request and never written down, and what does get stored is reduced to a
 * network range first. Saying so in the site's own privacy policy is still
 * worth doing, because "we looked and there was nothing to declare" is only
 * useful to a site owner if somebody writes it down.
 *
 * @package AumCrawl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggested privacy policy content.
 */
class AumViso_Crawl_Privacy {

	/**
	 * Hook up.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * Contribute a section to the site's privacy policy draft.
	 *
	 * @return void
	 */
	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'This site records visits from automated crawlers, such as search engine and AI crawlers, so the site owner can see which of them read the site.', 'aumviso' ) . '</p>'
			. '<p>' . esc_html__( 'What is stored: the crawler name, the date, the paths it requested, and a visit count. No information about human visitors is recorded.', 'aumviso' ) . '</p>'
			. '<p>' . esc_html__( 'IP addresses are not stored. When a crawler is checked against the hostname its operator publishes, the address is used during that single request and then discarded. If the check fails, only a shortened network range is kept - the final part of the address is removed first, so it no longer points to an individual connection.', 'aumviso' ) . '</p>'
			. '<p>' . esc_html__( 'Records are deleted automatically after the retention period set by the site owner.', 'aumviso' ) . '</p>';

		wp_add_privacy_policy_content( __( 'AI Crawler Control', 'aumviso' ), $content );
	}
}
