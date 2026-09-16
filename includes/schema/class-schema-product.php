<?php
defined( 'ABSPATH' ) || exit;

/**
 * Product Schema — Suitable for WooCommerce product pages or pages manually marked as Product
 */
class AumViso_Schema_Product {

    private static ?AumViso_Schema_Product $instance = null;

    public static function instance(): AumViso_Schema_Product {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'aumviso_collect_schemas', [ $this, 'collect' ] );
    }

    public function collect( AumViso_SchemaEngine $engine ): void {
        if ( ! is_singular() ) return;

        $post_id     = get_queried_object_id();
        $schema_type = get_post_meta( $post_id, '_aumviso_schema_type', true );

        // Automatically detect WooCommerce product pages
        $is_woo_product = class_exists( 'WooCommerce' ) && is_singular( 'product' );
        $is_manual      = $schema_type === 'Product';

        if ( ! $is_woo_product && ! $is_manual ) {
            // Unified product sources (e.g. AumNexCart) own their own CPTs and
            // register through the `aumviso_product_sources` filter.
            if ( class_exists( 'AumViso_Product_Registry' ) ) {
                $data = AumViso_Product_Registry::instance()->current_product();
                if ( $data ) {
                    $engine->register( $this->build_from_source( $data ) );
                }
            }
            return;
        }

        $schema = [
            '@type'       => 'Product',
            'name'        => get_the_title( $post_id ),
            'description' => get_post_meta( $post_id, '_aumviso_seo_description', true )
                          ?: wp_trim_words( get_the_excerpt( $post_id ), 30 ),
            'url'         => get_permalink( $post_id ),
        ];

        // Featured image
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            $src = wp_get_attachment_image_url( $thumb_id, 'large' );
            if ( $src ) $schema['image'] = $src;
        }

        // WooCommerce specific data
        if ( $is_woo_product && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post_id );
            if ( $product ) {
                $schema['sku'] = $product->get_sku();

                if ( $product->get_price() ) {
                    $schema['offers'] = [
                        '@type'         => 'Offer',
                        'price'         => $product->get_price(),
                        'priceCurrency' => get_woocommerce_currency(),
                        'availability'  => $product->is_in_stock()
                            ? 'https://schema.org/InStock'
                            : 'https://schema.org/OutOfStock',
                        'url'           => get_permalink( $post_id ),
                        'seller'        => [ '@id' => AumViso_Options::schema_org()['url'] . '#organization' ],
                    ];
                }

                // WooCommerce reviews
                if ( $product->get_rating_count() > 0 ) {
                    $schema['aggregateRating'] = [
                        '@type'       => 'AggregateRating',
                        'ratingValue' => number_format( $product->get_average_rating(), 1 ),
                        'reviewCount' => $product->get_rating_count(),
                        'bestRating'  => '5',
                        'worstRating' => '1',
                    ];
                }

                // Brand (from custom field or product attribute)
                $brand = get_post_meta( $post_id, '_aumviso_product_brand', true );
                if ( $brand ) {
                    $schema['brand'] = [ '@type' => 'Brand', 'name' => $brand ];
                }
            }
        } else {
            // Manual product fields
            $price    = get_post_meta( $post_id, '_aumviso_product_price', true );
            $currency = get_post_meta( $post_id, '_aumviso_product_currency', true ) ?: 'USD';
            $sku      = get_post_meta( $post_id, '_aumviso_product_sku', true );
            $avail    = get_post_meta( $post_id, '_aumviso_product_availability', true ) ?: 'InStock';

            if ( $price ) {
                $schema['offers'] = [
                    '@type'         => 'Offer',
                    'price'         => $price,
                    'priceCurrency' => $currency,
                    'availability'  => 'https://schema.org/' . $avail,
                    'url'           => get_permalink( $post_id ),
                ];
            }
            if ( $sku ) $schema['sku'] = $sku;

            // Manual aggregate rating
            $rating_value = get_post_meta( $post_id, '_aumviso_rating_value', true );
            $rating_count = get_post_meta( $post_id, '_aumviso_rating_count', true );
            if ( $rating_value && $rating_count ) {
                $schema['aggregateRating'] = [
                    '@type'       => 'AggregateRating',
                    'ratingValue' => $rating_value,
                    'reviewCount' => $rating_count,
                    'bestRating'  => '5',
                    'worstRating' => '1',
                ];
            }
        }

        $engine->register( $schema );
    }

    /**
     * Builds Product JSON-LD from a normalized product-source record
     * (see AumViso_Product_Source). Inquiry-first sources may have no
     * structured price; descriptive fields (name, image, attributes) are
     * always emitted because they are what makes a product citable by AI.
     */
    private function build_from_source( array $d ): array {
        $schema = [
            '@type' => 'Product',
            'name'  => (string) ( $d['title'] ?? '' ),
            'url'   => (string) ( $d['permalink'] ?? '' ),
        ];

        if ( ! empty( $d['description'] ) ) {
            $schema['description'] = (string) $d['description'];
        }
        if ( ! empty( $d['images'] ) && is_array( $d['images'] ) ) {
            $schema['image'] = array_values( $d['images'] );
        }
        if ( ! empty( $d['sku'] ) ) {
            $schema['sku'] = (string) $d['sku'];
        }
        if ( ! empty( $d['categories'][0]['name'] ) ) {
            $schema['category'] = (string) $d['categories'][0]['name'];
        }

        if ( ! empty( $d['attributes'] ) && is_array( $d['attributes'] ) ) {
            $props = [];
            foreach ( $d['attributes'] as $attr ) {
                $key = isset( $attr['key'] ) ? trim( (string) $attr['key'] ) : '';
                if ( '' === $key ) continue;
                $props[] = [
                    '@type' => 'PropertyValue',
                    'name'  => $key,
                    'value' => isset( $attr['value'] ) ? (string) $attr['value'] : '',
                ];
            }
            if ( $props ) $schema['additionalProperty'] = $props;
        }

        $offers = $this->offers_from_source_price( (array) ( $d['price'] ?? [] ), $schema['url'] );
        if ( $offers ) $schema['offers'] = $offers;

        return $schema;
    }

    /**
     * Maps a normalized price block to an Offer/AggregateOffer. Only ranged
     * prices with a valid ISO 4217 currency produce structured offers; text /
     * hidden prices (inquiry-only) intentionally emit none, since a bare symbol
     * like "$" is not valid schema.org priceCurrency.
     */
    private function offers_from_source_price( array $price, string $url ): array {
        $type = $price['type'] ?? 'hidden';
        if ( 'range' !== $type && 'fixed' !== $type ) return [];

        $currency = isset( $price['currency'] ) ? strtoupper( trim( (string) $price['currency'] ) ) : '';
        if ( 3 !== strlen( $currency ) || ! ctype_alpha( $currency ) ) return [];

        $min = ( isset( $price['min'] ) && '' !== $price['min'] ) ? (float) $price['min'] : null;
        $max = ( isset( $price['max'] ) && '' !== $price['max'] ) ? (float) $price['max'] : null;
        if ( null === $min && null === $max ) return [];

        if ( 'fixed' === $type || $min === $max ) {
            return [
                '@type'         => 'Offer',
                'priceCurrency' => $currency,
                'price'         => (string) ( $min ?? $max ),
                'url'           => $url,
                'availability'  => 'https://schema.org/InStock',
            ];
        }

        $offer = [
            '@type'         => 'AggregateOffer',
            'priceCurrency' => $currency,
            'url'           => $url,
            'availability'  => 'https://schema.org/InStock',
        ];
        if ( null !== $min ) $offer['lowPrice']  = $min;
        if ( null !== $max ) $offer['highPrice'] = $max;
        return $offer;
    }
}

/**
 * Review Schema - Embed a single evaluation output in the article
 * Usage: Set _Systema_type=Review in the article meta
 */
class AumViso_Schema_Review {

    private static ?AumViso_Schema_Review $instance = null;

    public static function instance(): AumViso_Schema_Review {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'aumviso_collect_schemas', [ $this, 'collect' ] );
    }

    public function collect( AumViso_SchemaEngine $engine ): void {
        if ( ! is_singular() ) return;

        $post_id     = get_queried_object_id();
        $schema_type = get_post_meta( $post_id, '_aumviso_schema_type', true );
        if ( $schema_type !== 'Review' ) return;

        $item_name  = get_post_meta( $post_id, '_aumviso_review_item_name', true );
        $item_type  = get_post_meta( $post_id, '_aumviso_review_item_type', true ) ?: 'Product';
        $rating     = get_post_meta( $post_id, '_aumviso_review_rating', true );
        $body       = get_post_meta( $post_id, '_aumviso_review_body', true )
                   ?: wp_trim_words( wp_strip_all_tags( get_the_content( null, false, $post_id ) ), 60 );

        if ( ! $item_name || ! $rating ) return;

        $post    = get_post( $post_id );
        $schema  = [
            '@type'        => 'Review',
            'name'         => get_the_title( $post_id ),
            'reviewBody'   => $body,
            'reviewRating' => [
                '@type'       => 'Rating',
                'ratingValue' => (float) $rating,
                'bestRating'  => 5,
                'worstRating' => 1,
            ],
            'author'       => [
                '@type' => 'Person',
                'name'  => get_the_author_meta( 'display_name', $post->post_author ),
            ],
            'datePublished' => get_the_date( 'c', $post_id ),
            'itemReviewed'  => [
                '@type' => $item_type,
                'name'  => $item_name,
            ],
        ];

        $engine->register( $schema );
    }
}
