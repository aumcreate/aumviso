<?php
defined( 'ABSPATH' ) || exit;

class AumViso_CPT_Glossary {

    private static ?AumViso_CPT_Glossary $instance = null;

    public static function instance(): AumViso_CPT_Glossary {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post_aum_glossary', [ $this, 'save_meta' ], 10, 2 );
        // NOTE: the ID-column callbacks live on AumViso_Glossary_LinkSync, so they
        // are registered in that class's constructor — not here. Hooking them on
        // $this (AumViso_CPT_Glossary) called undefined methods and fatally blanked
        // the Glossary list screen.
        add_shortcode( 'aumviso_glossary', [ $this, 'shortcode' ] );
    }

    public function register_cpt(): void {
        register_post_type( 'aumviso_glossary', [
            'labels' => [
                'name'          => __( 'Glossary', 'aumviso' ),
                'singular_name' => __( 'Term', 'aumviso' ),
                'add_new_item'  => __( 'Add New Term', 'aumviso' ),
                'edit_item'     => __( 'Edit Term', 'aumviso' ),
                'menu_name'     => __( 'Glossary', 'aumviso' ),
            ],
            'public'          => true,
            'show_ui'         => true,
            'show_in_menu'    => true,
            'show_in_rest'    => true,
            'supports'        => [ 'title', 'editor', 'author', 'thumbnail', 'excerpt' ],
            'has_archive'     => 'glossary',
            'rewrite'         => [ 'slug' => 'glossary', 'with_front' => false ],
            'capability_type' => 'post',
        ] );
    }

    public function add_meta_boxes(): void {
        add_meta_box(
            'aumviso_glossary_details',
            __( 'Term Details', 'aumviso' ),
            [ $this, 'render_meta_box' ],
            'aumviso_glossary',
            'normal',
            'high'
        );
    }

    public function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'aumviso_glossary_save', 'aumviso_glossary_nonce' );

        $short_def    = get_post_meta( $post->ID, '_aumviso_glossary_short_def', true );
        $full_exp     = get_post_meta( $post->ID, '_aumviso_glossary_full_explanation', true );
        // Guard the shape, not just emptiness -- see the note in class-cpt-faq.php.
        $related_ids  = get_post_meta( $post->ID, '_aumviso_glossary_related_terms', true );
        $related_ids  = is_array( $related_ids ) ? $related_ids : [];

        ?>
        <p style="margin:8px 0 16px;color:#646970;font-style:italic;">
            <?php esc_html_e( 'Fields in this panel are used to generate structured data (Schema). The main content editor controls the page display. The "Short Definition" is injected into the DefinedTerm schema and site tooltips, while the "Full Explanation" provides richer context for AI and GEO crawlers.', 'aumviso' ); ?>
        </p>

        <table class="form-table aumviso-meta-table">

            <tr>
                <th><label for="glossary_short_def"><?php esc_html_e( 'Short Definition', 'aumviso' ); ?></label></th>
                <td>
                    <input type="text"
                        id="glossary_short_def"
                        name="glossary_short_def"
                        value="<?php echo esc_attr( $short_def ); ?>"
                        class="large-text">

                    <p class="description">
                        <?php esc_html_e( 'A concise 1–2 sentence definition used in Schema markup and tooltip displays.', 'aumviso' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th><label for="glossary_full_explanation"><?php esc_html_e( 'Full Explanation', 'aumviso' ); ?></label></th>
                <td>
                    <textarea
                        id="glossary_full_explanation"
                        name="glossary_full_explanation"
                        class="large-text"
                        rows="5"><?php echo esc_textarea( $full_exp ); ?></textarea>

                    <p class="description">
                        <?php esc_html_e( 'Extended explanation providing richer context for GEO / AI indexing.', 'aumviso' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th><label><?php esc_html_e( 'Related Terms', 'aumviso' ); ?></label></th>
                <td>

                    <?php
                    $all_terms = get_posts( [
                        'post_type'      => 'aumviso_glossary',
                        'posts_per_page' => -1,
                        'post_status'    => 'publish',
                        'post__not_in'   => [ $post->ID ],
                        'orderby'        => 'title',
                        'order'          => 'ASC',
                    ] );

                    foreach ( $all_terms as $term ) :
                    ?>

                        <label style="display:inline-block;margin-right:12px;">
                            <input type="checkbox"
                                name="glossary_related_terms[]"
                                value="<?php echo esc_attr( $term->ID ); ?>"
                                <?php checked( in_array( $term->ID, $related_ids, true ) ); ?>>

                            <?php echo esc_html( $term->post_title ); ?>
                        </label>

                    <?php endforeach; ?>

                    <p class="description">
                        <?php esc_html_e( 'Select related glossary terms (adds seeAlso references to the DefinedTerm schema).', 'aumviso' ); ?>
                    </p>

                </td>
            </tr>

        </table>
        <?php
    }

    public function save_meta( int $post_id ): void {

        if ( ! isset( $_POST['aumviso_glossary_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aumviso_glossary_nonce'] ) ), 'aumviso_glossary_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        update_post_meta(
            $post_id,
            '_aumviso_glossary_short_def',
            sanitize_text_field( wp_unslash( $_POST['glossary_short_def'] ?? '' ) )
        );

        update_post_meta(
            $post_id,
            '_aumviso_glossary_full_explanation',
            sanitize_textarea_field( wp_unslash( $_POST['glossary_full_explanation'] ?? '' ) )
        );

        $related = isset( $_POST['glossary_related_terms'] )
            ? array_map( 'intval', (array) wp_unslash( $_POST['glossary_related_terms'] ) )
            : [];

        update_post_meta(
            $post_id,
            '_aumviso_glossary_related_terms',
            $related
        );
    }

    public function shortcode( array $atts ): string {

        $atts = shortcode_atts( [ 'count' => 50 ], $atts );

        $terms = get_posts( [
            'post_type'      => 'aumviso_glossary',
            'posts_per_page' => (int) $atts['count'],
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post_status'    => 'publish',
        ] );

        if ( empty( $terms ) ) return '';

        ob_start();

        include AUMVISO_DIR . 'templates/glossary/list.php';

        return ob_get_clean();
    }

}


/**
 * Glossary Internal Link Synchronization Helper
 */
class AumViso_Glossary_LinkSync {

    private static ?AumViso_Glossary_LinkSync $instance = null;

    public static function instance(): AumViso_Glossary_LinkSync {

        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {

        // Synchronize the glossary term with the internal link table
        // when the term is published or updated
        add_action( 'save_post_aum_glossary', [ $this, 'sync_to_ilinks' ], 20, 2 );

        // Remove the term from the internal link table when deleted
        add_action( 'before_delete_post', [ $this, 'remove_from_ilinks' ] );

        // Synchronize when a glossary term changes from draft to publish
        add_action( 'transition_post_status', [ $this, 'on_status_change' ], 10, 3 );

        // Glossary list-table ID column (these callbacks live on this class).
        add_filter( 'manage_aum_glossary_posts_columns', [ $this, 'add_id_column' ] );
        add_action( 'manage_aum_glossary_posts_custom_column', [ $this, 'render_id_column' ], 10, 2 );
    }

    public function sync_to_ilinks( int $post_id, WP_Post $post ): void {

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( $post->post_status !== 'publish' ) return;

        $term  = $post->post_title;
        $url   = get_permalink( $post_id );

        if ( ! $term || ! $url ) return;

        global $wpdb;

        $table = $wpdb->prefix . 'aumviso_link_keywords';

        // Check whether the entry already exists
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d LIMIT 1",
            $post_id
        ) );

        if ( $existing ) {

            // Update keyword and URL if the title or permalink changed
            $wpdb->update(
                $table,
                [
                    'keyword'    => $term,
                    'target_url' => $url,
                ],
                [ 'id' => $existing ]
            );

        } else {

            $wpdb->insert(
                $table,
                [
                    'keyword'        => $term,
                    'target_url'     => $url,
                    'post_id'        => $post_id,
                    'max_links'      => 1,
                    'case_sensitive' => 0,
                ]
            );

        }
    }

    public function remove_from_ilinks( int $post_id ): void {

        if ( get_post_type( $post_id ) !== 'aumviso_glossary' ) return;

        global $wpdb;

        $wpdb->delete(
            $wpdb->prefix . 'aumviso_link_keywords',
            [ 'post_id' => $post_id ]
        );
    }

    public function on_status_change( string $new, string $old, WP_Post $post ): void {

        if ( $post->post_type !== 'aumviso_glossary' ) return;

        if ( $new === 'publish' && $old !== 'publish' ) {
            $this->sync_to_ilinks( $post->ID, $post );
        }

        if ( $new !== 'publish' && $old === 'publish' ) {
            $this->remove_from_ilinks( $post->ID );
        }
    }

    public function add_id_column( array $cols ): array {

        $new = [];

        foreach ( $cols as $k => $v ) {

            $new[ $k ] = $v;

            if ( $k === 'title' ) {
                $new['post_id_col'] = __( 'ID', 'aumviso' );
            }

        }

        return $new;
    }

    public function render_id_column( string $column, int $post_id ): void {

        if ( $column === 'post_id_col' ) {
            echo '<code>' . (int) $post_id . '</code>';
        }

    }

}