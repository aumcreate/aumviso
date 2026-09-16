<?php
/**
 * SEO fields on the term edit screen.
 *
 * The meta manager has read `_aumviso_seo_title` / `_aumviso_seo_description` / index from **term** meta
 * since day one (a taxonomy archive is a landing page like any other), but nothing wrote them: a kit could
 * ship them, and then nobody could change them. A buyer who renamed a practice area kept the old area's
 * description in search results with no place to fix it (2026-09-13, buyer simulation). This adds the three
 * fields to the term edit form of every taxonomy AumViso is enabled for.
 *
 * Deliberately no fields on the quick "add" form: a new term rarely needs a hand-written description on the
 * day it is created, and the description template covers it until it does.
 */
defined( 'ABSPATH' ) || exit;

class AumViso_TermMeta {

    private static ?AumViso_TermMeta $instance = null;

    public static function instance(): AumViso_TermMeta {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_init', [ $this, 'hook_taxonomies' ] );
    }

    public function hook_taxonomies(): void {
        // The same rule the front end applies (Meta_Manager::detect_context): a taxonomy archive is
        // handled when the post type it belongs to is enabled — plus the explicit taxonomy list
        $taxes = AumViso_Options::get_enabled_taxonomies();
        foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $obj ) {
            $pt = $obj->object_type[0] ?? '';
            if ( $pt && AumViso_Options::is_post_type_enabled( $pt ) ) $taxes[] = $obj->name;
        }
        foreach ( array_unique( $taxes ) as $tax ) {
            if ( ! taxonomy_exists( $tax ) ) continue;
            add_action( "{$tax}_edit_form_fields", [ $this, 'render' ], 20, 2 );
            add_action( "edited_{$tax}",           [ $this, 'save' ], 10, 2 );
        }
    }

    public function render( WP_Term $term, string $taxonomy ): void {
        if ( ! current_user_can( 'manage_categories' ) ) return;
        $title = (string) get_term_meta( $term->term_id, '_aumviso_seo_title', true );
        $desc  = (string) get_term_meta( $term->term_id, '_aumviso_seo_description', true );
        $index = (string) get_term_meta( $term->term_id, '_aumviso_seo_index', true ) ?: 'index';
        wp_nonce_field( 'aumviso_term_meta_save', 'aumviso_term_meta_nonce' );
        ?>
        <tr class="form-field">
            <th scope="row" colspan="2" style="padding-bottom:0">
                <h3 style="margin:0"><span class="dashicons dashicons-search" style="color:#e8501a;vertical-align:middle;"></span> <?php esc_html_e( 'AumViso', 'aumviso' ); ?></h3>
            </th>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="aumviso_seo_title"><?php esc_html_e( 'SEO title', 'aumviso' ); ?></label></th>
            <td>
                <input type="text" id="aumviso_seo_title" name="aumviso_seo_title" value="<?php echo esc_attr( $title ); ?>" maxlength="120">
                <p class="description"><?php esc_html_e( 'Leave empty to use the site title template (the term name, then the site name).', 'aumviso' ); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="aumviso_seo_description"><?php esc_html_e( 'Meta description', 'aumviso' ); ?></label></th>
            <td>
                <textarea id="aumviso_seo_description" name="aumviso_seo_description" rows="3" maxlength="320"><?php echo esc_textarea( $desc ); ?></textarea>
                <p class="description"><?php esc_html_e( 'Leave empty to use the term description. 120–160 characters reads best in search results.', 'aumviso' ); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="aumviso_seo_index"><?php esc_html_e( 'Search engines', 'aumviso' ); ?></label></th>
            <td>
                <select id="aumviso_seo_index" name="aumviso_seo_index">
                    <option value="index" <?php selected( $index, 'index' ); ?>><?php esc_html_e( 'Index this archive', 'aumviso' ); ?></option>
                    <option value="noindex" <?php selected( $index, 'noindex' ); ?>><?php esc_html_e( 'Do not index', 'aumviso' ); ?></option>
                </select>
            </td>
        </tr>
        <?php
    }

    public function save( int $term_id, int $tt_id ): void {
        if ( ! isset( $_POST['aumviso_term_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aumviso_term_meta_nonce'] ) ), 'aumviso_term_meta_save' ) ) return;
        if ( ! current_user_can( 'manage_categories' ) ) return;

        $fields = [
            '_aumviso_seo_title'       => [ 'aumviso_seo_title', 'sanitize_text_field' ],
            '_aumviso_seo_description' => [ 'aumviso_seo_description', 'sanitize_textarea_field' ],
            '_aumviso_seo_index'       => [ 'aumviso_seo_index', 'sanitize_text_field' ],
        ];
        foreach ( $fields as $meta_key => [ $post_key, $sanitize ] ) {
            if ( ! isset( $_POST[ $post_key ] ) ) continue;
            $value = call_user_func( $sanitize, wp_unslash( $_POST[ $post_key ] ) );
            if ( $meta_key === '_aumviso_seo_index' && $value === 'index' ) $value = '';
            if ( $value === '' ) {
                delete_term_meta( $term_id, $meta_key );
            } else {
                update_term_meta( $term_id, $meta_key, $value );
            }
        }
    }
}
