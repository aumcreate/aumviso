<?php
/**
 * The noai response header.
 *
 * "X-Robots-Tag: noai" is a convention rather than a standard -- it came out
 * of the image-hosting world and only some crawlers act on it. It costs one
 * header, so it is worth sending, but the settings screen says plainly that
 * it is a request and not a fence.
 *
 * @package AumCrawl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Response headers.
 */
class AumViso_Crawl_Headers {

	/**
	 * Hook up.
	 */
	public function __construct() {
		add_filter( 'wp_headers', array( $this, 'add_noai' ) );
	}

	/**
	 * Append noai to any X-Robots-Tag already being sent.
	 *
	 * Appending matters: SEO plugins put indexing directives in this same
	 * header, and replacing it would quietly undo them.
	 *
	 * @param array $headers Outgoing headers.
	 * @return array
	 */
	public function add_noai( $headers ) {
		$settings = aumviso_crawl_get_settings();

		if ( empty( $settings['send_noai_header'] ) || is_admin() ) {
			return $headers;
		}

		$existing = isset( $headers['X-Robots-Tag'] ) ? trim( (string) $headers['X-Robots-Tag'] ) : '';
		$add      = 'noai, noimageai';

		$headers['X-Robots-Tag'] = '' === $existing ? $add : $existing . ', ' . $add;

		return $headers;
	}
}
