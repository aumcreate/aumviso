<?php
defined( 'ABSPATH' ) || exit;

class AumViso_Schema_FAQ {

    private static ?AumViso_Schema_FAQ $instance = null;

    public static function instance(): AumViso_Schema_FAQ {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'aumviso_collect_schemas', [ $this, 'collect' ] );
    }

    public function collect( AumViso_SchemaEngine $engine ): void {
        // On single FAQ CPT
        if ( is_singular( 'aumviso_faq' ) ) {
            $post = get_queried_object();
            $engine->register( $this->build_single( $post ) );
            return;
        }

        // On any singular post/page — embed FAQs attached to it
        if ( is_singular() ) {
            $post_id = get_queried_object_id();
            $faqs    = $this->get_attached_faqs( $post_id );
            if ( $faqs ) {
                $engine->register( $this->build_from_array( $faqs ) );
            }
        }
    }

    private function build_single( WP_Post $post ): array {
        $answer = get_post_meta( $post->ID, '_aumviso_faq_answer', true ) ?: $post->post_content;
        return $this->build_from_array( [ [
            'question' => $post->post_title,
            'answer'   => $answer,
        ] ] );
    }

    private function get_attached_faqs( int $post_id ): array {
        // Inline FAQ blocks stored as meta
        $stored = get_post_meta( $post_id, '_aumviso_inline_faqs', true );
        if ( is_array( $stored ) && ! empty( $stored ) ) return $stored;

        // Linked FAQ CPT posts
        $linked_ids = get_post_meta( $post_id, '_aumviso_linked_faq_ids', true );
        if ( is_array( $linked_ids ) && ! empty( $linked_ids ) ) {
            $posts = get_posts( [
                'post_type'      => 'aumviso_faq',
                'post__in'       => $linked_ids,
                'posts_per_page' => -1,
                'orderby'        => 'post__in',
            ] );
            $faqs = [];
            foreach ( $posts as $faq_post ) {
                $faqs[] = [
                    'question' => $faq_post->post_title,
                    'answer'   => get_post_meta( $faq_post->ID, '_aumviso_faq_answer', true ) ?: $faq_post->post_content,
                ];
            }
            return $faqs;
        }

        return [];
    }

    private function build_from_array( array $faqs ): array {
        $entities = [];
        foreach ( $faqs as $faq ) {
            if ( empty( $faq['question'] ) || empty( $faq['answer'] ) ) continue;
            $entities[] = [
                '@type'          => 'Question',
                'name'           => wp_strip_all_tags( $faq['question'] ),
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => wp_strip_all_tags( $faq['answer'] ),
                ],
            ];
        }

        return [
            '@type'            => 'FAQPage',
            'mainEntity'       => $entities,
        ];
    }

    /**
     * Public helper: build FAQPage schema from Q&A array.
     * Used by admin preview / other classes.
     */
    public static function build( array $faqs ): array {
        return self::instance()->build_from_array( $faqs );
    }
}
