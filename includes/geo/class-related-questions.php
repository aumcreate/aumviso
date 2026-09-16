<?php
defined( 'ABSPATH' ) || exit;

class AumViso_RelatedQuestions {

    private static ?AumViso_RelatedQuestions $instance = null;

    public static function instance(): AumViso_RelatedQuestions {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'aumviso_related_questions', [ $this, 'shortcode' ] );
        add_filter( 'the_content', [ $this, 'auto_append' ], 25 );
    }

    public function auto_append( string $content ): string {
        if ( ! is_singular( 'aumviso_faq' ) || ! in_the_loop() || ! is_main_query() ) return $content;

        $post_id  = get_the_ID();
        $rendered = $this->render( $post_id );
        return $rendered ? $content . $rendered : $content;
    }

    public function shortcode( array $atts ): string {
        $atts = shortcode_atts( [ 'id' => get_the_ID() ], $atts );
        return $this->render( (int) $atts['id'] );
    }

    public function render( int $post_id ): string {
        $questions = get_post_meta( $post_id, '_aumviso_faq_related_questions', true );
        if ( ! is_array( $questions ) || empty( $questions ) ) return '';

        $html  = '<div class="aum-related-questions">';
        $html .= '<h3 class="aum-related-questions__title">';
        $html .= esc_html__( 'People Also Ask', 'aumviso' );
        $html .= '</h3>';
        $html .= '<ul class="aum-related-questions__list">';

        foreach ( $questions as $question ) {
            if ( ! $question ) continue;
            $html .= '<li class="aum-related-questions__item">';
            $html .= '<span class="aum-rq-icon" aria-hidden="true"><svg viewBox="0 0 20 20" width="16" height="16" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="9" fill="currentColor" opacity="0.12"/><path d="M7.5 7.6a2.5 2.5 0 0 1 4.9.6c0 1.7-2.4 1.9-2.4 3.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="14.6" r="0.95" fill="currentColor"/></svg></span> ';
            $html .= esc_html( $question );
            $html .= '</li>';
        }

        $html .= '</ul></div>';
        return $html;
    }
}
