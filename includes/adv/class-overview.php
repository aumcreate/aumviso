<?php
/**
 * Site-wide GEO/SEO overview scan.
 *
 * Aggregates AumViso's per-post score (AumViso_SEOScore::analyze) across all
 * enabled content, plus product coverage from the shared product registry, into
 * the numbers shown on the GEO Console overview tab.
 *
 * The scan is capped and cached (transient) so it never re-runs on every page
 * load or stalls a large site; callers can force a refresh.
 */

defined( 'ABSPATH' ) || exit;

final class AumViso_Adv_Overview {

	private const CACHE_KEY = 'aumviso_adv_overview';
	private const CACHE_TTL = HOUR_IN_SECONDS;

	/** Max posts scanned per post type, to bound work on large sites. */
	private const SCAN_CAP = 300;

	/**
	 * Returns the overview data, from cache unless $force is true.
	 *
	 * @param bool $force Recompute and refresh the cache.
	 * @return array
	 */
	public static function get( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$data = self::scan();
		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	/** Clears the cached scan. */
	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Performs the full scan. Heavy-ish (regex content analysis per post) — only
	 * called on cache miss or explicit refresh.
	 */
	private static function scan(): array {
		$score = AumViso_SEOScore::instance();
		$types = AumViso_Options::get_enabled_post_types();

		$bands         = [ 'great' => 0, 'good' => 0, 'average' => 0, 'poor' => 0 ];
		$per_type      = [];
		$sum           = 0;
		$count         = 0;
		$missing_desc  = 0;
		$missing_thumb = 0;
		$capped        = false;

		foreach ( $types as $type ) {
			if ( ! post_type_exists( $type ) ) {
				continue;
			}

			$query = new WP_Query(
				[
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => self::SCAN_CAP,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'no_found_rows'  => false,
				]
			);

			$type_total = (int) $query->found_posts;
			$type_sum   = 0;
			$scanned    = 0;

			foreach ( $query->posts as $post ) {
				$total = (int) ( $score->analyze( $post )['total'] ?? 0 );

				$sum      += $total;
				$type_sum += $total;
				++$count;
				++$scanned;

				$band = $total >= 80 ? 'great' : ( $total >= 60 ? 'good' : ( $total >= 40 ? 'average' : 'poor' ) );
				++$bands[ $band ];

				if ( '' === (string) get_post_meta( $post->ID, '_aumviso_seo_description', true ) ) {
					++$missing_desc;
				}
				if ( ! get_post_thumbnail_id( $post->ID ) ) {
					++$missing_thumb;
				}
			}

			if ( $type_total > $scanned ) {
				$capped = true;
			}

			$pt_obj = get_post_type_object( $type );

			$per_type[ $type ] = [
				'label'   => $pt_obj ? $pt_obj->labels->name : $type,
				'total'   => $type_total,
				'scanned' => $scanned,
				'avg'     => $scanned ? (int) round( $type_sum / $scanned ) : 0,
			];

			wp_reset_postdata();
		}

		// Products via the shared registry.
		$products = [ 'sources' => 0, 'total' => 0, 'list' => [] ];
		if ( class_exists( 'AumViso_Product_Registry' ) ) {
			$registry          = AumViso_Product_Registry::instance();
			$sources           = $registry->get_sources();
			$products['sources'] = count( $sources );
			$products['total']   = $registry->total_count();
			foreach ( $sources as $source ) {
				$products['list'][] = [ 'label' => $source->get_label(), 'count' => (int) $source->count() ];
			}
		}

		return [
			'avg'           => $count ? (int) round( $sum / $count ) : 0,
			'count'         => $count,
			'bands'         => $bands,
			'missing_desc'  => $missing_desc,
			'missing_thumb' => $missing_thumb,
			'per_type'      => $per_type,
			'products'      => $products,
			'capped'        => $capped,
			'cap'           => self::SCAN_CAP,
			'generated'     => current_time( 'mysql' ),
		];
	}
}
