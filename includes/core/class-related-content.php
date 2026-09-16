<?php
defined( 'ABSPATH' ) || exit;

class AumViso_RelatedContent {

    private static ?AumViso_RelatedContent $instance = null;

    public static function instance(): AumViso_RelatedContent {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'aumviso_related', [ $this, 'shortcode' ] );
        add_filter( 'the_content', [ $this, 'auto_append' ], 30 );
    }

    public function auto_append( string $content ): string {
        if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) return $content;
        if ( ! AumViso_Options::get( 'aumviso_related_auto_append', false ) ) return $content;

        $content .= $this->render( get_the_ID() );
        return $content;
    }

    public function shortcode( array $atts ): string {
        $atts = shortcode_atts( [
            'post_id' => get_the_ID(),
            'count'   => 4,
            'type'    => 'all', // all | faq | guide | post
        ], $atts );
        return $this->render( (int) $atts['post_id'], (int) $atts['count'], $atts['type'] );
    }

    public function render( int $post_id, int $count = 4, string $type = 'all' ): string {
        $sections = [];

        if ( in_array( $type, [ 'all', 'faq' ], true ) ) {
            $faqs = $this->get_related_faqs( $post_id, $count );
            if ( $faqs ) {
                $sections[] = $this->render_faq_section( $faqs );
            }
        }

        if ( in_array( $type, [ 'all', 'guide' ], true ) ) {
            $guides = $this->get_related_posts( $post_id, 'aumviso_guide', $count );
            if ( $guides ) {
                $sections[] = $this->render_post_section( $guides, __( 'Related Guides', 'aumviso' ) );
            }
        }

        if ( in_array( $type, [ 'all', 'post' ], true ) ) {
            $posts = $this->get_related_posts( $post_id, 'post', $count );
            if ( $posts ) {
                $sections[] = $this->render_post_section( $posts, __( 'Related Articles', 'aumviso' ) );
            }
        }

        if ( empty( $sections ) ) return '';

        return '<div class="aum-related-content">' . implode( '', $sections ) . '</div>';
    }

    // ------------------------------------
    // Query helpers
    // ------------------------------------

    private function get_related_faqs( int $post_id, int $count ): array {
        $cats = wp_get_post_terms( $post_id, 'aumviso_faq_category', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $cats ) || empty( $cats ) ) {
            // Fallback: just get recent FAQs
            return get_posts( [
                'post_type'      => 'aumviso_faq',
                'posts_per_page' => $count,
                'post__not_in'   => [ $post_id ],
                'orderby'        => 'rand',
            ] );
        }

        return get_posts( [
            'post_type'      => 'aumviso_faq',
            'posts_per_page' => $count,
            'post__not_in'   => [ $post_id ],
            'tax_query'      => [ [
                'taxonomy' => 'aumviso_faq_category',
                'terms'    => $cats,
            ] ],
        ] );
    }

    private function get_related_posts( int $post_id, string $post_type, int $count ): array {
        $tags = wp_get_post_tags( $post_id, [ 'fields' => 'ids' ] );
        $cats = wp_get_post_categories( $post_id );

        $args = [
            'post_type'      => $post_type,
            'posts_per_page' => $count,
            'post__not_in'   => [ $post_id ],
            'orderby'        => 'relevance',
        ];

        if ( $tags || $cats ) {
            $args['tax_query'] = [ 'relation' => 'OR' ];
            if ( $tags ) {
                $args['tax_query'][] = [ 'taxonomy' => 'post_tag', 'terms' => $tags ];
            }
            if ( $cats ) {
                $args['tax_query'][] = [ 'taxonomy' => 'category', 'terms' => $cats ];
            }
        }

        return get_posts( $args );
    }

    // ------------------------------------
    // Render helpers
    // ------------------------------------

    private function render_faq_section( array $faqs ): string {
        $html  = '<div class="aum-related__faqs">';
        $html .= '<h3 class="aum-related__title">' . esc_html__( 'Related Questions', 'aumviso' ) . '</h3>';
        $html .= '<ul class="aum-related__list">';
        foreach ( $faqs as $faq ) {
            $html .= sprintf(
                '<li><a href="%s">%s</a></li>',
                esc_url( get_permalink( $faq->ID ) ),
                esc_html( $faq->post_title )
            );
        }
        $html .= '</ul></div>';
        return $html;
    }

    private function render_post_section( array $posts, string $title ): string {
        $html  = '<div class="aum-related__posts">';
        $html .= '<h3 class="aum-related__title">' . esc_html( $title ) . '</h3>';
        $html .= '<ul class="aum-related__list">';
        foreach ( $posts as $post ) {
            $html .= sprintf(
                '<li><a href="%s">%s</a></li>',
                esc_url( get_permalink( $post->ID ) ),
                esc_html( $post->post_title )
            );
        }
        $html .= '</ul></div>';
        return $html;
    }
}
