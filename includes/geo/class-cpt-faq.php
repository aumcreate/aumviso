<?php
defined( 'ABSPATH' ) || exit;

class AumViso_CPT_FAQ {

    private static ?AumViso_CPT_FAQ $instance = null;

    public static function instance(): AumViso_CPT_FAQ {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'init', [ $this, 'register_taxonomy' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post_aum_faq', [ $this, 'save_meta' ], 10, 2 );
        add_filter( 'manage_aum_faq_posts_columns', [ $this, 'add_columns' ] );
        add_action( 'manage_aum_faq_posts_custom_column', [ $this, 'render_columns' ], 10, 2 );

        // Shortcode: [aumviso_faq] — renders FAQ list
        add_shortcode( 'aumviso_faq', [ $this, 'shortcode' ] );
    }

    // ------------------------------------
    // Registration
    // ------------------------------------

    public function register_cpt(): void {
        register_post_type( 'aumviso_faq', [
            'labels' => [
                'name'               => __( 'FAQs', 'aumviso' ),
                'singular_name'      => __( 'FAQ', 'aumviso' ),
                'add_new'            => __( 'Add New FAQ', 'aumviso' ),
                'add_new_item'       => __( 'Add New FAQ', 'aumviso' ),
                'edit_item'          => __( 'Edit FAQ', 'aumviso' ),
                'search_items'       => __( 'Search FAQs', 'aumviso' ),
                'not_found'          => __( 'No FAQs found', 'aumviso' ),
                'menu_name'          => __( 'FAQs', 'aumviso' ),
            ],
            'public'            => true,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_in_rest'      => true,
            'supports'          => [ 'title', 'editor', 'author', 'thumbnail', 'excerpt' ],
            'has_archive'       => 'faqs',
            'rewrite'           => [ 'slug' => 'faq', 'with_front' => false ],
            'menu_icon'         => 'dashicons-editor-help',
            'capability_type'   => 'post',
        ] );
    }

    public function register_taxonomy(): void {
        register_taxonomy( 'aumviso_faq_category', 'aumviso_faq', [
            'labels' => [
                'name'          => __( 'FAQ Categories', 'aumviso' ),
                'singular_name' => __( 'FAQ Category', 'aumviso' ),
                'add_new_item'  => __( 'Add New Category', 'aumviso' ),
                'edit_item'     => __( 'Edit Category', 'aumviso' ),
            ],
            'public'            => true,
            'hierarchical'      => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => 'faq-category' ],
        ] );
    }

    // ------------------------------------
    // Meta Boxes
    // ------------------------------------

    public function add_meta_boxes(): void {
        add_meta_box(
            'aumviso_faq_details',
            __( 'FAQ Details', 'aumviso' ),
            [ $this, 'render_meta_box' ],
            'aumviso_faq',
            'normal',
            'high'
        );

        add_meta_box(
            'aumviso_faq_related_questions',
            __( 'Related Questions (GEO)', 'aumviso' ),
            [ $this, 'render_related_questions_box' ],
            'aumviso_faq',
            'side',
            'default'
        );
    }

    public function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'aumviso_faq_save', 'aumviso_faq_nonce' );
        $answer        = get_post_meta( $post->ID, '_aumviso_faq_answer', true );
        $related_ids   = get_post_meta( $post->ID, '_aumviso_faq_related_content', true );
        $related_ids   = is_array( $related_ids ) ? $related_ids : [];
        ?>
        <p style="margin:8px 0 16px;color:#646970;font-style:italic;">
            <?php esc_html_e( 'Fields in this panel generate structured data (Schema) to help search engines understand the content. The main editor is for on-page display; the Answer field below is used exclusively for FAQ Schema, falling back to the post content when left empty.', 'aumviso' ); ?>
        </p>
        <table class="form-table aumviso-meta-table">
            <tr>
                <th><label for="aumviso_faq_answer"><?php esc_html_e( 'Answer', 'aumviso' ); ?></label></th>
                <td>
                    <p class="description"><?php esc_html_e( 'Concise answer text used exclusively for FAQ Schema. Leave empty to fall back to the post content.', 'aumviso' ); ?></p>
                    <textarea
                        id="aumviso_faq_answer"
                        name="aumviso_faq_answer"
                        rows="6"
                        class="large-text"
                    ><?php echo esc_textarea( $answer ); ?></textarea>
                </td>
            </tr>
        </table>
        <?php
    }

    public function render_related_questions_box( WP_Post $post ): void {
        // Guard the shape rather than the emptiness: post meta can hold anything
        // an import or another plugin left behind, and a bare foreach over a
        // string prints a PHP warning straight into the editor.
        $related = get_post_meta( $post->ID, '_aumviso_faq_related_questions', true );
        $related = is_array( $related ) ? $related : [];
        ?>
        <div id="aum-related-questions">
            <p class="description"><?php esc_html_e( 'Add related questions that will appear in a "People Also Ask" block.', 'aumviso' ); ?></p>
            <div id="aum-rq-list">
                <?php foreach ( $related as $i => $rq ) : ?>
                <div class="aum-rq-item" style="margin-bottom:8px;display:flex;gap:4px;">
                    <input type="text" name="aumviso_related_questions[]"
                        value="<?php echo esc_attr( $rq ); ?>"
                        placeholder="<?php esc_attr_e( 'Related question...', 'aumviso' ); ?>"
                        style="flex:1;">
                    <button type="button" class="button aum-rq-remove" aria-label="<?php esc_attr_e( 'Remove', 'aumviso' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="aum-rq-add">
                + <?php esc_html_e( 'Add Question', 'aumviso' ); ?>
            </button>

            <hr style="margin:12px 0;">
            <p><strong><?php esc_html_e( 'AI Prompt Template', 'aumviso' ); ?></strong></p>
            <p class="description"><?php esc_html_e( 'Copy this prompt to generate related questions with AI:', 'aumviso' ); ?></p>
            <textarea id="aum-ai-prompt-template" rows="6" readonly style="width:100%;font-size:11px;background:#f6f7f7;"
            ><?php echo esc_textarea( $this->get_ai_prompt_template( $post ) ); ?></textarea>
            <button type="button" class="button" id="aum-copy-prompt">
                <span class="dashicons dashicons-clipboard" aria-hidden="true"></span> <?php esc_html_e( 'Copy Prompt', 'aumviso' ); ?>
            </button>
        </div>

        <?php
    }

    private function get_ai_prompt_template( WP_Post $post ): string {
        $title   = $post->post_title ?: __( '[FAQ Title]', 'aumviso' );
        $excerpt = wp_trim_words( get_the_excerpt( $post->ID ) ?: $post->post_content, 30 );
        return sprintf(
            "Generate 5 related questions (with short answers, max 50 words each) for the following FAQ:\n\nTitle: %s\nContent: %s\n\nFormat:\nQ: [question]\nA: [answer]\n\n(Repeat for all 5 questions)",
            $title,
            $excerpt
        );
    }

    // ------------------------------------
    // Save
    // ------------------------------------

    public function save_meta( int $post_id, WP_Post $post ): void {
        if ( ! isset( $_POST['aumviso_faq_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aumviso_faq_nonce'] ) ), 'aumviso_faq_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        if ( isset( $_POST['aumviso_faq_answer'] ) ) {
            update_post_meta( $post_id, '_aumviso_faq_answer', wp_kses_post( wp_unslash( $_POST['aumviso_faq_answer'] ) ) );
        }

        $related_qs = isset( $_POST['aumviso_related_questions'] )
            ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['aumviso_related_questions'] ) )
            : [];
        $related_qs = array_filter( $related_qs );
        update_post_meta( $post_id, '_aumviso_faq_related_questions', array_values( $related_qs ) );
    }

    // ------------------------------------
    // Admin columns
    // ------------------------------------

    public function add_columns( array $cols ): array {
        $new = [];
        foreach ( $cols as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'title' ) {
                $new['faq_answer_preview'] = __( 'Answer Preview', 'aumviso' );
                $new['post_id_col'] = __( 'ID', 'aumviso' );
            }
        }
        return $new;
    }

    public function render_columns( string $column, int $post_id ): void {
        if ( $column === 'post_id_col' ) {
            echo '<code>' . (int) $post_id . '</code>';
        }
        if ( $column === 'faq_answer_preview' ) {
            $answer = get_post_meta( $post_id, '_aumviso_faq_answer', true );
            echo esc_html( wp_trim_words( wp_strip_all_tags( $answer ), 15, '…' ) );
        }
    }

    // ------------------------------------
    // Shortcode
    // ------------------------------------

    public function shortcode( array $atts ): string {
        $atts = shortcode_atts( [
            'category' => '',
            'count'    => 10,
            'style'    => 'accordion', // accordion | list
        ], $atts );

        $args = [
            'post_type'      => 'aumviso_faq',
            'posts_per_page' => (int) $atts['count'],
            'post_status'    => 'publish',
        ];

        if ( $atts['category'] ) {
            $args['tax_query'] = [ [
                'taxonomy' => 'aumviso_faq_category',
                'field'    => 'slug',
                'terms'    => explode( ',', $atts['category'] ),
            ] ];
        }

        $faqs = get_posts( $args );
        if ( empty( $faqs ) ) return '';

        ob_start();
        include AUMVISO_DIR . 'templates/faq/list.php';
        return ob_get_clean();
    }
}
