<?php
defined( 'ABSPATH' ) || exit;

/**
 * Contract for a product data source.
 *
 * A "product source" abstracts where products come from — WooCommerce, the
 * AumNexCart catalog, or manually-marked pages — behind one interface so the
 * rest of AumViso (Product schema, the GEO console, llms.txt, the AI article
 * factory) can read products without caring about the underlying plugin.
 *
 * Sources register themselves through the `aumviso_product_sources` filter and
 * are aggregated by {@see AumViso_Product_Registry}. Every source returns
 * product data in the normalized shape:
 *
 *   [
 *     'source'      => 'nexcart',                 // source id
 *     'id'          => 123,                       // post id
 *     'title'       => 'Stainless Gearbox X200',
 *     'permalink'   => 'https://example.com/products/x200',
 *     'description' => 'Plain-text description…',
 *     'sku'         => 'X200',                    // '' if none
 *     'price'       => [
 *         'type'     => 'range|text|hidden|fixed',
 *         'min'      => 500.0, 'max' => 800.0,    // '' when not applicable
 *         'currency' => 'USD',                    // ISO code or symbol
 *         'text'     => '',                       // free text when type=text
 *     ],
 *     'attributes'  => [ [ 'key' => 'Material', 'value' => 'Steel' ], … ],
 *     'images'      => [ 'https://example.com/1.jpg', ... ],  // first is the main image
 *     'categories'  => [ [ 'name' => 'Gearboxes', 'slug' => 'gearboxes' ], … ],
 *     'in_stock'    => null,                      // bool, or null when N/A
 *   ]
 */
interface AumViso_Product_Source {

	/** Stable machine id, e.g. 'woocommerce' | 'nexcart' | 'manual'. */
	public function get_id(): string;

	/** Human-readable label for admin display. */
	public function get_label(): string;

	/** Whether the underlying plugin/data is present and usable. */
	public function is_available(): bool;

	/**
	 * Post types this source manages, used to map a singular request to a
	 * source.
	 *
	 * @return string[]
	 */
	public function get_post_types(): array;

	/** Total number of published products in this source. */
	public function count(): int;

	/**
	 * Query product IDs.
	 *
	 * @param array $args WP_Query-style args (per_page, paged, search, …).
	 * @return int[] Post IDs.
	 */
	public function query( array $args ): array;

	/**
	 * Normalized product data for one product, or null if not found/owned.
	 *
	 * @param int $id Post ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array;

	/** Whether AumViso should emit Product JSON-LD for this source. */
	public function supports_schema(): bool;
}
