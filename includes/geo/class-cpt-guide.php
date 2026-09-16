<?php
defined( 'ABSPATH' ) || exit;

class AumViso_CPT_Guide {

    private static ?AumViso_CPT_Guide $instance = null;

    public static function instance(): AumViso_CPT_Guide {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post_aum_guide', [ $this, 'save_meta' ], 10, 2 );
        add_filter( 'manage_aum_guide_posts_columns', [ $this, 'add_id_column' ] );
        add_action( 'manage_aum_guide_posts_custom_column', [ $this, 'render_id_column' ], 10, 2 );
        add_shortcode( 'aumviso_guide_steps', [ $this, 'shortcode_steps' ] );
    }

    public function register_cpt(): void {
        register_post_type( 'aumviso_guide', [
            'labels' => [
                'name'          => __( 'Guides', 'aumviso' ),
                'singular_name' => __( 'Guide', 'aumviso' ),
                'add_new_item'  => __( 'Add New Guide', 'aumviso' ),
                'edit_item'     => __( 'Edit Guide', 'aumviso' ),
                'menu_name'     => __( 'Guides', 'aumviso' ),
            ],
            'public'          => true,
            'show_ui'         => true,
            'show_in_menu'    => true,
            'show_in_rest'    => true,
            'supports'        => [ 'title', 'editor', 'author', 'thumbnail', 'excerpt' ],
            'has_archive'     => 'guides',
            'rewrite'         => [ 'slug' => 'guide', 'with_front' => false ],
            'capability_type' => 'post',
        ] );
    }

    public function add_meta_boxes(): void {
        add_meta_box(
            'aumviso_guide_details',
            __( 'Guide Details (HowTo Schema)', 'aumviso' ),
            [ $this, 'render_meta_box' ],
            'aumviso_guide',
            'normal',
            'high'
        );
    }

    public function render_meta_box( WP_Post $post ): void {

        wp_nonce_field( 'aumviso_guide_save', 'aumviso_guide_nonce' );

        $time       = get_post_meta( $post->ID, '_aumviso_guide_time', true );
        $difficulty = get_post_meta( $post->ID, '_aumviso_guide_difficulty', true );
        $tools      = get_post_meta( $post->ID, '_aumviso_guide_tools', true );
        $tools      = is_array( $tools ) ? $tools : [];
        $materials  = get_post_meta( $post->ID, '_aumviso_guide_materials', true );
        $materials  = is_array( $materials ) ? $materials : [];
        // Guard the shape, not just emptiness: a stray string in post meta
        // would otherwise reach foreach() and print a PHP warning into the editor.
        $steps      = get_post_meta( $post->ID, '_aumviso_guide_steps', true );
        $steps      = is_array( $steps ) ? $steps : [];

        ?>

        <p style="margin:8px 0 16px;color:#646970;font-style:italic;">
            <?php esc_html_e(
                'Fields in this panel are used to generate HowTo structured data (Schema). The main content editor controls the page presentation, while the fields below—such as steps, tools, and time—are used specifically for Schema markup. This helps search engines present the content as a step-by-step guide in search results.',
                'aumviso'
            ); ?>
        </p>

        <table class="form-table aumviso-meta-table">

            <tr>
                <th>
                    <label for="guide_time">
                        <?php esc_html_e( 'Estimated Time (minutes)', 'aumviso' ); ?>
                    </label>
                </th>

                <td>
                    <input
                        type="number"
                        id="guide_time"
                        name="guide_time"
                        value="<?php echo esc_attr( $time ); ?>"
                        min="1"
                        style="width:100px;">
                </td>
            </tr>

            <tr>
                <th>
                    <label for="guide_difficulty">
                        <?php esc_html_e( 'Difficulty', 'aumviso' ); ?>
                    </label>
                </th>

                <td>

                    <select id="guide_difficulty" name="guide_difficulty">

                        <?php foreach ( [ 'beginner', 'intermediate', 'advanced' ] as $level ) : ?>

                            <option
                                value="<?php echo esc_attr( $level ); ?>"
                                <?php selected( $difficulty, $level ); ?>>

                                <?php echo esc_html( ucfirst( $level ) ); ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </td>
            </tr>

            <tr>
                <th>
                    <label><?php esc_html_e( 'Tools Required', 'aumviso' ); ?></label>
                </th>

                <td>

                    <div id="aum-guide-tools">

                        <?php foreach ( $tools as $tool ) : ?>

                            <div class="aum-tool-item" style="display:flex;gap:4px;margin-bottom:4px;">

                                <input
                                    type="text"
                                    name="guide_tools[]"
                                    value="<?php echo esc_attr( $tool ); ?>"
                                    style="width:250px;">

                                <button type="button" class="button aum-remove-tool" aria-label="<?php esc_attr_e( 'Remove', 'aumviso' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>

                            </div>

                        <?php endforeach; ?>

                    </div>

                    <button type="button" class="button" id="aum-add-tool">
                        + <?php esc_html_e( 'Add Tool', 'aumviso' ); ?>
                    </button>

                </td>
            </tr>

            <tr>
                <th>
                    <label><?php esc_html_e( 'Materials Required', 'aumviso' ); ?></label>
                </th>

                <td>

                    <div id="aum-guide-materials">

                        <?php foreach ( $materials as $mat ) : ?>

                            <div class="aum-material-item" style="display:flex;gap:4px;margin-bottom:4px;">

                                <input
                                    type="text"
                                    name="guide_materials[]"
                                    value="<?php echo esc_attr( $mat ); ?>"
                                    style="width:250px;">

                                <button type="button" class="button aum-remove-material" aria-label="<?php esc_attr_e( 'Remove', 'aumviso' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>

                            </div>

                        <?php endforeach; ?>

                    </div>

                    <button type="button" class="button" id="aum-add-material">
                        + <?php esc_html_e( 'Add Material', 'aumviso' ); ?>
                    </button>

                </td>
            </tr>

        </table>

        <h3><?php esc_html_e( 'Steps', 'aumviso' ); ?></h3>

        <div id="aum-guide-steps">

            <?php foreach ( $steps as $i => $step ) : ?>

                <div class="aum-step-item"
                     style="border:1px solid #ddd;padding:12px;margin-bottom:8px;background:#fafafa;">

                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;">

                        <strong>
                            <?php /* translators: %d: the step number. */ echo esc_html( sprintf( __( 'Step %d', 'aumviso' ), $i + 1 ) ); ?>
                        </strong>

                        <button
                            type="button"
                            class="button button-small aum-remove-step">

                            <?php esc_html_e( 'Remove', 'aumviso' ); ?>

                        </button>

                    </div>

                    <input
                        type="text"
                        name="guide_step_name[]"
                        value="<?php echo esc_attr( $step['name'] ?? '' ); ?>"
                        placeholder="<?php esc_attr_e( 'Step name', 'aumviso' ); ?>"
                        class="large-text"
                        style="margin-bottom:6px;">

                    <textarea
                        name="guide_step_text[]"
                        class="large-text"
                        rows="3"
                        placeholder="<?php esc_attr_e( 'Step description', 'aumviso' ); ?>"><?php echo esc_textarea( $step['text'] ?? '' ); ?></textarea>

                </div>

            <?php endforeach; ?>

        </div>

        <button type="button" class="button" id="aum-add-step">
            + <?php esc_html_e( 'Add Step', 'aumviso' ); ?>
        </button>

        <?php
    }

    public function save_meta( int $post_id ): void {

        if ( ! isset( $_POST['aumviso_guide_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aumviso_guide_nonce'] ) ), 'aumviso_guide_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        update_post_meta( $post_id, '_aumviso_guide_time', absint( wp_unslash( $_POST['guide_time'] ?? 0 ) ) );
        update_post_meta( $post_id, '_aumviso_guide_difficulty', sanitize_text_field( wp_unslash( $_POST['guide_difficulty'] ?? '' ) ) );

        $tools = isset( $_POST['guide_tools'] )
            ? array_filter( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['guide_tools'] ) ) )
            : [];

        update_post_meta( $post_id, '_aumviso_guide_tools', array_values( $tools ) );

        $materials = isset( $_POST['guide_materials'] )
            ? array_filter( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['guide_materials'] ) ) )
            : [];

        update_post_meta( $post_id, '_aumviso_guide_materials', array_values( $materials ) );

        $step_names = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['guide_step_name'] ?? [] ) );
        $step_texts = array_map( 'sanitize_textarea_field', (array) wp_unslash( $_POST['guide_step_text'] ?? [] ) );

        $steps = [];

        foreach ( $step_names as $i => $name ) {

            if ( $name || ! empty( $step_texts[ $i ] ) ) {

                $steps[] = [
                    'name' => $name,
                    'text' => $step_texts[ $i ] ?? '',
                ];

            }

        }

        update_post_meta( $post_id, '_aumviso_guide_steps', $steps );
    }

    public function shortcode_steps( array $atts ): string {

        $atts    = shortcode_atts( [ 'id' => get_the_ID() ], $atts );
        $post_id = (int) $atts['id'];

        $steps = get_post_meta( $post_id, '_aumviso_guide_steps', true );

        if ( ! is_array( $steps ) || empty( $steps ) ) return '';

        ob_start();

        include AUMVISO_DIR . 'templates/guide/steps.php';

        return ob_get_clean();
    }

    public function add_id_column( array $cols ): array {

        $new = [];

        foreach ( $cols as $k => $v ) {

            $new[$k] = $v;

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
