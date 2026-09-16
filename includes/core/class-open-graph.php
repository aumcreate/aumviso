<?php
defined( 'ABSPATH' ) || exit;

class AumViso_OpenGraph {

    private static ?AumViso_OpenGraph $instance = null;

    public static function instance(): AumViso_OpenGraph {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_head', [ $this, 'output_og' ], 2 );
    }

    public function output_og(): void {
        if ( is_admin() ) return;
        // Reuse MetaManager context detection. Skip OG output if the page is not enabled.
        if ( ! AumViso_MetaManager::detect_context() ) return;

        $data = $this->collect_data();
        if ( empty( $data ) ) return;

        echo "\n<!-- AumViso: Open Graph -->\n";

        foreach ( $data['og'] as $property => $content ) {
            if ( $content ) {
                printf( '<meta property="%s" content="%s">' . "\n", esc_attr( $property ), esc_attr( $content ) );
            }
        }

        foreach ( $data['twitter'] as $name => $content ) {
            if ( $content ) {
                printf( '<meta name="%s" content="%s">' . "\n", esc_attr( $name ), esc_attr( $content ) );
            }
        }

        echo "\n";
    }

    private function collect_data(): array {
        $og = [];

        // --- Type ---
        if ( is_singular() ) {
            $og['og:type'] = is_home() || get_post_type() === 'post' ? 'article' : 'website';
        } else {
            $og['og:type'] = 'website';
        }

        // --- URL ---
        global $wp;
        $og['og:url'] = is_singular() ? get_permalink() : home_url( add_query_arg( [], $wp->request ) );

        // --- Site name ---
        $og['og:site_name'] = get_bloginfo( 'name' );

        // --- Title ---
        if ( is_singular() ) {
            $post_id = get_the_ID();
            $og['og:title'] = get_post_meta( $post_id, '_aumviso_og_title', true )
                ?: get_the_title();
        } elseif ( is_tax() || is_category() || is_tag() ) {
            $og['og:title'] = single_term_title( '', false );
        } else {
            $og['og:title'] = get_bloginfo( 'name' );
        }

        // --- Description ---
        if ( is_singular() ) {
            $post_id = get_the_ID();
            $desc = get_post_meta( $post_id, '_aumviso_og_description', true )
                ?: get_post_meta( $post_id, '_aumviso_seo_description', true )
                ?: wp_trim_words( get_the_excerpt(), 25 );
            $og['og:description'] = $desc;
        } elseif ( is_tax() || is_category() || is_tag() ) {
            $og['og:description'] = wp_strip_all_tags( term_description() );
        } else {
            $og['og:description'] = get_bloginfo( 'description' );
        }

        // --- Image ---
        $og['og:image'] = $this->resolve_image();

        // --- Article-specific ---
        if ( is_singular( 'post' ) ) {
            $og['article:published_time'] = get_the_date( 'c' );
            $og['article:modified_time']  = get_the_modified_date( 'c' );
            $og['article:author']         = get_the_author_meta( 'display_name' );
        }

        // --- Twitter Card ---
        $twitter = [
            'twitter:card'        => $og['og:image'] ? 'summary_large_image' : 'summary',
            'twitter:title'       => $og['og:title'],
            'twitter:description' => $og['og:description'],
            'twitter:image'       => $og['og:image'],
        ];

        $twitter_handle = AumViso_Options::get( 'aumviso_social_twitter' );
        if ( $twitter_handle ) {
            $twitter['twitter:site'] = '@' . ltrim( $twitter_handle, '@' );
        }

        return [ 'og' => $og, 'twitter' => $twitter ];
    }

    private function resolve_image(): string {
        // 1. Custom OG image set in meta box
        if ( is_singular() ) {
            $post_id  = get_the_ID();
            $custom   = get_post_meta( $post_id, '_aumviso_og_image', true );
            if ( $custom ) return $custom;

            // 2. Featured image
            $thumb_id = get_post_thumbnail_id( $post_id );
            if ( $thumb_id ) {
                $src = wp_get_attachment_image_url( $thumb_id, 'large' );
                if ( $src ) return $src;
            }
        }

        // 3. Global fallback
        $fallback = AumViso_Options::og_fallback_image();
        return $fallback ?: '';
    }
}
