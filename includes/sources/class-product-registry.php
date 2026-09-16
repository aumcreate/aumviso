<?php
defined( 'ABSPATH' ) || exit;

/**
 * Unified product source registry.
 *
 * Collects every {@see AumViso_Product_Source} registered via the
 * `aumviso_product_sources` filter and exposes a single, source-agnostic view of
 * the site's products. Consumed by the Product schema today, and by the GEO
 * console / llms.txt / AI article factory in later phases.
 *
 * Extension point (Module 0): a third-party plugin registers a source with
 *
 *   add_filter( 'aumviso_product_sources', function ( $sources ) {
 *       $sources[] = new My_Product_Source();
 *       return $sources;
 *   } );
 */
final class AumViso_Product_Registry {

	private static ?AumViso_Product_Registry $instance = null;

	/** @var AumViso_Product_Source[]|null Lazily resolved, keyed by source id. */
	private ?array $sources = null;

	public static function instance(): AumViso_Product_Registry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * All available sources, keyed by id. Resolved once per request.
	 *
	 * @return AumViso_Product_Source[]
	 */
	public function get_sources(): array {
		if ( null === $this->sources ) {
			$this->sources = [];
			$collected     = apply_filters( 'aumviso_product_sources', [] );

			foreach ( (array) $collected as $source ) {
				if ( $source instanceof AumViso_Product_Source && $source->is_available() ) {
					$this->sources[ $source->get_id() ] = $source;
				}
			}
		}
		return $this->sources;
	}

	public function get_source( string $id ): ?AumViso_Product_Source {
		return $this->get_sources()[ $id ] ?? null;
	}

	/**
	 * The source that owns a given post type, if any.
	 */
	public function source_for_post_type( string $post_type ): ?AumViso_Product_Source {
		foreach ( $this->get_sources() as $source ) {
			if ( in_array( $post_type, $source->get_post_types(), true ) ) {
				return $source;
			}
		}
		return null;
	}

	/**
	 * Normalized product data for the current singular request, when it belongs
	 * to a schema-supporting registered source. Null otherwise.
	 *
	 * @return array|null
	 */
	public function current_product(): ?array {
		if ( ! is_singular() ) {
			return null;
		}
		$id = get_queried_object_id();
		if ( ! $id ) {
			return null;
		}
		$source = $this->source_for_post_type( (string) get_post_type( $id ) );
		if ( ! $source || ! $source->supports_schema() ) {
			return null;
		}
		return $source->get( (int) $id );
	}

	/**
	 * Total published product count across all sources (GEO console).
	 */
	public function total_count(): int {
		$total = 0;
		foreach ( $this->get_sources() as $source ) {
			$total += $source->count();
		}
		return $total;
	}
}
