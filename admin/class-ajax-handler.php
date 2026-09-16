<?php
defined( 'ABSPATH' ) || exit;

class AumViso_AjaxHandler {

    private static ?AumViso_AjaxHandler $instance = null;

    public static function instance(): AumViso_AjaxHandler {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $actions = [
            'aumviso_generate_meta_desc',
            'aumviso_generate_faqs',
            'aumviso_generate_related_questions',
            'aumviso_generate_summary',
            'aumviso_ilink_add',
            'aumviso_ilink_delete',
        ];

        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, str_replace( 'aumviso_', 'handle_', $action ) ] );
        }
    }

    private function verify(): void {
        if ( ! check_ajax_referer( 'aumviso_ajax', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'aumviso' ) ], 403 );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'aumviso' ) ], 403 );
        }
    }

    private function get_post_content( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( [ 'message' => __( 'Post not found.', 'aumviso' ) ] );
        }
        return [ $post->post_title, $post->post_content ];
    }

    // ------------------------------------
    // Generate meta description
    // ------------------------------------

    public function handle_generate_meta_desc(): void {
        $this->verify();
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        [ $title, $content ] = $this->get_post_content( $post_id );

        $result = AumViso_AI_Generator::generate_meta_description( $title, $content );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( [ 'text' => $result ] );
    }

    // ------------------------------------
    // Generate FAQs
    // ------------------------------------

    public function handle_generate_faqs(): void {
        $this->verify();
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        [ $title, $content ] = $this->get_post_content( $post_id );

        $result = AumViso_AI_Generator::generate_faqs( $title, $content );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        // Format for display
        $text = '';
        foreach ( $result as $qa ) {
            $text .= 'Q: ' . ( $qa['question'] ?? '' ) . "\n";
            $text .= 'A: ' . ( $qa['answer'] ?? '' ) . "\n\n";
        }
        wp_send_json_success( [ 'text' => trim( $text ), 'raw' => $result ] );
    }

    // ------------------------------------
    // Generate related questions
    // ------------------------------------

    public function handle_generate_related_questions(): void {
        $this->verify();
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        [ $title, $content ] = $this->get_post_content( $post_id );

        $result = AumViso_AI_Generator::generate_related_questions( $title, $content );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [
            'text' => implode( "\n", $result ),
            'raw'  => $result,
        ] );
    }

    // ------------------------------------
    // Generate summary
    // ------------------------------------

    public function handle_generate_summary(): void {
        $this->verify();
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        [ $title, $content ] = $this->get_post_content( $post_id );

        $result = AumViso_AI_Generator::generate_summary( $title, $content );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( [ 'text' => $result ] );
    }

    // ------------------------------------
    // Internal link add
    // ------------------------------------

    public function handle_ilink_add(): void {
        $this->verify();
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'aumviso' ) ], 403 );
        }

        $data = [
            'keyword'        => sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) ),
            'target_url'     => esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) ),
            'max_links'      => (int) ( $_POST['max_links'] ?? 1 ),
            'case_sensitive' => ! empty( $_POST['case_sensitive'] ),
        ];

        if ( ! $data['keyword'] || ! $data['target_url'] ) {
            wp_send_json_error( [ 'message' => __( 'Keyword and URL are required.', 'aumviso' ) ] );
        }

        $id = AumViso_InternalLinks::save_keyword( $data );
        wp_send_json_success( [ 'id' => $id, 'keyword' => $data ] );
    }

    // ------------------------------------
    // Internal link delete
    // ------------------------------------

    public function handle_ilink_delete(): void {
        $this->verify();
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'aumviso' ) ], 403 );
        }

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'aumviso' ) ] );

        AumViso_InternalLinks::delete_keyword( $id );
        wp_send_json_success( [ 'deleted' => $id ] );
    }
}
