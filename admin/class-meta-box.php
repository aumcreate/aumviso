<?php
defined( 'ABSPATH' ) || exit;

class AumViso_MetaBox {

    private static ?AumViso_MetaBox $instance = null;

    public static function instance(): AumViso_MetaBox {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post',      [ $this, 'save_meta' ], 10, 2 );
    }

    public function add_meta_boxes(): void {
        $post_type   = get_current_screen()?->post_type ?? '';
        $enabled_pts = AumViso_Options::get_enabled_post_types();

        if ( ! in_array( $post_type, $enabled_pts, true ) ) return;

        add_meta_box(
            'aumviso_meta',
            '<span class="dashicons dashicons-search" style="color:#e8501a;vertical-align:middle;"></span> ' . __( 'AumViso', 'aumviso' ),
            [ $this, 'render' ],
            null,
            'normal',
            'high'
        );
    }

    public function render( WP_Post $post ): void {
        wp_nonce_field( 'aumviso_meta_save', 'aumviso_meta_nonce' );

        $seo_title  = get_post_meta( $post->ID, '_aumviso_seo_title', true );
        $seo_desc   = get_post_meta( $post->ID, '_aumviso_seo_description', true );
        $canonical  = get_post_meta( $post->ID, '_aumviso_seo_canonical', true );
        $index      = get_post_meta( $post->ID, '_aumviso_seo_index', true ) ?: 'index';
        $follow     = get_post_meta( $post->ID, '_aumviso_seo_follow', true ) ?: 'follow';
        $og_title   = get_post_meta( $post->ID, '_aumviso_og_title', true );
        $og_desc    = get_post_meta( $post->ID, '_aumviso_og_description', true );
        $og_image   = get_post_meta( $post->ID, '_aumviso_og_image', true );
        $schema_type= get_post_meta( $post->ID, '_aumviso_schema_type', true );
        $linked_faq_ids = (array) ( get_post_meta( $post->ID, '_aumviso_linked_faq_ids', true ) ?: [] );
        $all_faqs       = get_posts( [
            'post_type'      => 'aumviso_faq',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        $title_tpl  = AumViso_Options::resolve_template( AumViso_Options::title_template(), $post );
        $desc_tpl   = AumViso_Options::resolve_template( AumViso_Options::desc_template(), $post );
        ?>
        <div class="aumviso-metabox">

            <!-- Tab nav -->
            <nav class="aumviso-tabs">
                <button type="button" class="aumviso-tab active" data-tab="general"><?php esc_html_e( 'General', 'aumviso' ); ?></button>
                <button type="button" class="aumviso-tab" data-tab="social"><?php esc_html_e( 'Social', 'aumviso' ); ?></button>
                <button type="button" class="aumviso-tab" data-tab="advanced"><?php esc_html_e( 'Advanced', 'aumviso' ); ?></button>
                <button type="button" class="aumviso-tab" data-tab="ai"><?php esc_html_e( 'AI Tools', 'aumviso' ); ?></button>
            </nav>

            <!-- General -->
            <div class="aumviso-tab-content active" data-content="general">

                <div class="aumviso-field">
                    <label for="aumviso_seo_title">
                        <?php esc_html_e( 'SEO Title', 'aumviso' ); ?>
                        <span class="aumviso-char-count" id="aumviso_title_count">0 / 60</span>
                    </label>
                    <input type="text" id="aumviso_seo_title" name="aumviso_seo_title"
                        value="<?php echo esc_attr( $seo_title ); ?>"
                        placeholder="<?php echo esc_attr( $title_tpl ); ?>"
                        maxlength="100" class="large-text aumviso-char-input" data-max="60" data-count="aumviso_title_count">
                    <p class="description"><?php esc_html_e( 'Leave blank to use the global template.', 'aumviso' ); ?></p>
                </div>

                <div class="aumviso-field">
                    <label for="aumviso_seo_description">
                        <?php esc_html_e( 'Meta Description', 'aumviso' ); ?>
                        <span class="aumviso-char-count" id="aumviso_desc_count">0 / 155</span>
                    </label>
                    <textarea id="aumviso_seo_description" name="aumviso_seo_description"
                        rows="3" class="large-text aumviso-char-input" data-max="155" data-count="aumviso_desc_count"
                        placeholder="<?php echo esc_attr( $desc_tpl ); ?>"><?php echo esc_textarea( $seo_desc ); ?></textarea>
                </div>

                <!-- Google SERP Preview -->
                <div class="aumviso-serp-preview">
                    <p class="aumviso-preview-label"><?php esc_html_e( 'Search Preview', 'aumviso' ); ?></p>
                    <div class="aumviso-serp">
                        <div class="aumviso-serp__url"><?php echo esc_html( get_permalink( $post->ID ) ?: home_url() ); ?></div>
                        <div class="aumviso-serp__title" id="aumviso_preview_title"><?php echo esc_html( $seo_title ?: $title_tpl ); ?></div>
                        <div class="aumviso-serp__desc" id="aumviso_preview_desc"><?php echo esc_html( $seo_desc ?: $desc_tpl ); ?></div>
                    </div>
                </div>

                <!-- Robots -->
                <div class="aumviso-field aumviso-inline-fields">
                    <div>
                        <label><?php esc_html_e( 'Indexing', 'aumviso' ); ?></label>
                        <select name="aumviso_seo_index">
                            <option value="index"   <?php selected( $index, 'index' ); ?>><?php esc_html_e( 'Index', 'aumviso' ); ?></option>
                            <option value="noindex" <?php selected( $index, 'noindex' ); ?>><?php esc_html_e( 'No Index', 'aumviso' ); ?></option>
                        </select>
                    </div>
                    <div>
                        <label><?php esc_html_e( 'Following', 'aumviso' ); ?></label>
                        <select name="aumviso_seo_follow">
                            <option value="follow"   <?php selected( $follow, 'follow' ); ?>><?php esc_html_e( 'Follow', 'aumviso' ); ?></option>
                            <option value="nofollow" <?php selected( $follow, 'nofollow' ); ?>><?php esc_html_e( 'No Follow', 'aumviso' ); ?></option>
                        </select>
                    </div>
                </div>

            </div>

            <!-- Social -->
            <div class="aumviso-tab-content" data-content="social">

                <div class="aumviso-field">
                    <label for="aumviso_og_title"><?php esc_html_e( 'OG Title', 'aumviso' ); ?></label>
                    <input type="text" id="aumviso_og_title" name="aumviso_og_title"
                        value="<?php echo esc_attr( $og_title ); ?>"
                        placeholder="<?php echo esc_attr( $seo_title ?: $title_tpl ); ?>"
                        class="large-text">
                </div>

                <div class="aumviso-field">
                    <label for="aumviso_og_description"><?php esc_html_e( 'OG Description', 'aumviso' ); ?></label>
                    <textarea id="aumviso_og_description" name="aumviso_og_description"
                        rows="3" class="large-text"
                        placeholder="<?php echo esc_attr( $seo_desc ?: $desc_tpl ); ?>"><?php echo esc_textarea( $og_desc ); ?></textarea>
                </div>

                <div class="aumviso-field">
                    <label><?php esc_html_e( 'OG Image', 'aumviso' ); ?></label>
                    <div class="aumviso-media-field">
                        <input type="hidden" id="aumviso_og_image" name="aumviso_og_image"
                            value="<?php echo esc_attr( $og_image ); ?>">
                        <div id="aumviso_og_preview" class="aumviso-og-preview">
                            <?php if ( $og_image ) : ?>
                            <img src="<?php echo esc_url( $og_image ); ?>" alt="">
                            <?php endif; ?>
                        </div>
                        <button type="button" class="button" id="aumviso_og_select">
                            <?php esc_html_e( 'Select Image', 'aumviso' ); ?>
                        </button>
                        <button type="button" class="button" id="aumviso_og_remove" <?php echo $og_image ? '' : 'style="display:none"'; ?>>
                            <?php esc_html_e( 'Remove', 'aumviso' ); ?>
                        </button>
                    </div>
                </div>

            </div>

            <!-- Advanced -->
            <div class="aumviso-tab-content" data-content="advanced">

                <div class="aumviso-field">
                    <label for="aumviso_canonical"><?php esc_html_e( 'Canonical URL', 'aumviso' ); ?></label>
                    <input type="url" id="aumviso_canonical" name="aumviso_canonical"
                        value="<?php echo esc_attr( $canonical ); ?>"
                        placeholder="<?php echo esc_url( get_permalink( $post->ID ) ); ?>"
                        class="large-text">
                    <p class="description"><?php esc_html_e( 'Leave blank to use the post permalink.', 'aumviso' ); ?></p>
                </div>

                <?php if ( ! empty( $all_faqs ) ) : ?>
                <div class="aumviso-field">
                    <label><?php esc_html_e( 'Linked FAQs', 'aumviso' ); ?></label>
                    <p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'When checked, these FAQs are injected into this post as FAQPage Schema, helping search engines display Q&A rich results.', 'aumviso' ); ?></p>
                    <div style="max-height:160px;overflow-y:auto;border:1px solid #ddd;padding:8px;border-radius:3px;">
                        <?php foreach ( $all_faqs as $faq ) : ?>
                        <label style="display:block;margin:3px 0;">
                            <input type="checkbox" name="aumviso_linked_faq_ids[]"
                                value="<?php echo esc_attr( $faq->ID ); ?>"
                                <?php checked( in_array( $faq->ID, $linked_faq_ids, true ) ); ?>>
                            <?php echo esc_html( $faq->post_title ); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="aumviso-field">
                    <label for="aumviso_schema_type"><?php esc_html_e( 'Schema Type Override', 'aumviso' ); ?></label>
                    <select id="aumviso_schema_type" name="aumviso_schema_type">
                        <option value=""><?php esc_html_e( '— Auto —', 'aumviso' ); ?></option>
                        <?php foreach ( [ 'Article', 'BlogPosting', 'WebPage', 'FAQPage', 'HowTo', 'Product', 'Review', 'VideoObject' ] as $type ) : ?>
                        <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $schema_type, $type ); ?>>
                            <?php echo esc_html( $type ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Product fields -->
                <div id="aumviso-product-fields" style="<?php echo $schema_type === 'Product' ? '' : 'display:none;'; ?>">
                    <hr>
                    <h4><?php esc_html_e( 'Product Details', 'aumviso' ); ?></h4>
                    <?php
                    $p_price   = get_post_meta( $post->ID, '_aumviso_product_price', true );
                    $p_cur     = get_post_meta( $post->ID, '_aumviso_product_currency', true ) ?: 'USD';
                    $p_sku     = get_post_meta( $post->ID, '_aumviso_product_sku', true );
                    $p_avail   = get_post_meta( $post->ID, '_aumviso_product_availability', true ) ?: 'InStock';
                    $p_brand   = get_post_meta( $post->ID, '_aumviso_product_brand', true );
                    $p_rating  = get_post_meta( $post->ID, '_aumviso_rating_value', true );
                    $p_rcount  = get_post_meta( $post->ID, '_aumviso_rating_count', true );
                    ?>
                    <div class="aumviso-field aumviso-inline-fields">
                        <div>
                            <label><?php esc_html_e( 'Price', 'aumviso' ); ?></label>
                            <input type="text" name="aumviso_product_price" value="<?php echo esc_attr( $p_price ); ?>" style="width:100px;" placeholder="99.00">
                        </div>
                        <div>
                            <label><?php esc_html_e( 'Currency', 'aumviso' ); ?></label>
                            <input type="text" name="aumviso_product_currency" value="<?php echo esc_attr( $p_cur ); ?>" style="width:60px;" placeholder="USD">
                        </div>
                        <div>
                            <label><?php esc_html_e( 'SKU', 'aumviso' ); ?></label>
                            <input type="text" name="aumviso_product_sku" value="<?php echo esc_attr( $p_sku ); ?>" style="width:120px;">
                        </div>
                    </div>
                    <div class="aumviso-field aumviso-inline-fields">
                        <div>
                            <label><?php esc_html_e( 'Availability', 'aumviso' ); ?></label>
                            <select name="aumviso_product_availability">
                                <?php foreach ( [ 'InStock', 'OutOfStock', 'PreOrder', 'Discontinued' ] as $av ) : ?>
                                <option value="<?php echo esc_attr( $av ); ?>" <?php selected( $p_avail, $av ); ?>><?php echo esc_html( $av ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label><?php esc_html_e( 'Brand', 'aumviso' ); ?></label>
                            <input type="text" name="aumviso_product_brand" value="<?php echo esc_attr( $p_brand ); ?>" style="width:150px;">
                        </div>
                    </div>
                    <div class="aumviso-field aumviso-inline-fields">
                        <div>
                            <label><?php esc_html_e( 'Avg Rating (1-5)', 'aumviso' ); ?></label>
                            <input type="number" name="aumviso_rating_value" value="<?php echo esc_attr( $p_rating ); ?>" min="1" max="5" step="0.1" style="width:70px;">
                        </div>
                        <div>
                            <label><?php esc_html_e( 'Review Count', 'aumviso' ); ?></label>
                            <input type="number" name="aumviso_rating_count" value="<?php echo esc_attr( $p_rcount ); ?>" min="1" style="width:80px;">
                        </div>
                    </div>
                </div>

                <!-- Review fields -->
                <div id="aumviso-review-fields" style="<?php echo $schema_type === 'Review' ? '' : 'display:none;'; ?>">
                    <hr>
                    <h4><?php esc_html_e( 'Review Details', 'aumviso' ); ?></h4>
                    <?php
                    $rv_item_name = get_post_meta( $post->ID, '_aumviso_review_item_name', true );
                    $rv_item_type = get_post_meta( $post->ID, '_aumviso_review_item_type', true ) ?: 'Product';
                    $rv_rating    = get_post_meta( $post->ID, '_aumviso_review_rating', true );
                    $rv_body      = get_post_meta( $post->ID, '_aumviso_review_body', true );
                    ?>
                    <div class="aumviso-field">
                        <label><?php esc_html_e( 'Item Being Reviewed', 'aumviso' ); ?></label>
                        <input type="text" name="aumviso_review_item_name" value="<?php echo esc_attr( $rv_item_name ); ?>" class="large-text" placeholder="Product / Service name">
                    </div>
                    <div class="aumviso-field aumviso-inline-fields">
                        <div>
                            <label><?php esc_html_e( 'Item Type', 'aumviso' ); ?></label>
                            <select name="aumviso_review_item_type">
                                <?php foreach ( [ 'Product', 'Book', 'Movie', 'Software', 'LocalBusiness', 'Course' ] as $rt ) : ?>
                                <option value="<?php echo esc_attr( $rt ); ?>" <?php selected( $rv_item_type, $rt ); ?>><?php echo esc_html( $rt ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label><?php esc_html_e( 'Your Rating (1-5)', 'aumviso' ); ?></label>
                            <input type="number" name="aumviso_review_rating" value="<?php echo esc_attr( $rv_rating ); ?>" min="1" max="5" step="0.5" style="width:70px;">
                        </div>
                    </div>
                    <div class="aumviso-field">
                        <label><?php esc_html_e( 'Review Body (optional)', 'aumviso' ); ?></label>
                        <textarea name="aumviso_review_body" class="large-text" rows="3"><?php echo esc_textarea( $rv_body ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Leave blank to use post content.', 'aumviso' ); ?></p>
                    </div>
                </div>

                <!-- Video fields -->
                <div id="aumviso-video-fields" style="<?php echo $schema_type === 'VideoObject' ? '' : 'display:none;'; ?>">
                    <hr>
                    <h4><?php esc_html_e( 'Video Details', 'aumviso' ); ?></h4>
                    <?php
                    $v_url      = get_post_meta( $post->ID, '_aumviso_video_url', true );
                    $v_name     = get_post_meta( $post->ID, '_aumviso_video_name', true );
                    $v_thumb    = get_post_meta( $post->ID, '_aumviso_video_thumb', true );
                    $v_duration = get_post_meta( $post->ID, '_aumviso_video_duration', true );
                    $v_upload   = get_post_meta( $post->ID, '_aumviso_video_upload_date', true );
                    ?>
                    <div class="aumviso-field">
                        <label><?php esc_html_e( 'Video URL', 'aumviso' ); ?></label>
                        <input type="url" name="aumviso_video_url" value="<?php echo esc_attr( $v_url ); ?>" class="large-text" placeholder="https://youtube.com/watch?v=...">
                        <p class="description"><?php esc_html_e( 'Leave blank to auto-detect YouTube/Vimeo in post content.', 'aumviso' ); ?></p>
                    </div>
                    <div class="aumviso-field">
                        <label><?php esc_html_e( 'Video Name', 'aumviso' ); ?></label>
                        <input type="text" name="aumviso_video_name" value="<?php echo esc_attr( $v_name ); ?>" class="large-text">
                    </div>
                    <div class="aumviso-field">
                        <label><?php esc_html_e( 'Thumbnail URL', 'aumviso' ); ?></label>
                        <input type="url" name="aumviso_video_thumb" value="<?php echo esc_attr( $v_thumb ); ?>" class="large-text">
                        <p class="description"><?php esc_html_e( 'Leave blank to use featured image.', 'aumviso' ); ?></p>
                    </div>
                    <div class="aumviso-field aumviso-inline-fields">
                        <div>
                            <label><?php esc_html_e( 'Duration (ISO 8601)', 'aumviso' ); ?></label>
                            <input type="text" name="aumviso_video_duration" value="<?php echo esc_attr( $v_duration ); ?>" placeholder="PT5M30S" style="width:120px;">
                        </div>
                        <div>
                            <label><?php esc_html_e( 'Upload Date', 'aumviso' ); ?></label>
                            <input type="date" name="aumviso_video_upload_date" value="<?php echo esc_attr( substr( $v_upload, 0, 10 ) ); ?>">
                        </div>
                    </div>
                </div>

            </div>

            <!-- AI Tools -->
            <div class="aumviso-tab-content" data-content="ai">
                <p class="description"><?php esc_html_e( 'Generate SEO content using AI (requires API key in Settings).', 'aumviso' ); ?></p>

                <div class="aumviso-ai-actions">
                    <button type="button" class="button aumviso-ai-btn" data-action="generate_meta_desc" data-post="<?php echo esc_attr( $post->ID ); ?>">
                        <span class="dashicons dashicons-superhero-alt" aria-hidden="true"></span> <?php esc_html_e( 'Generate Meta Description', 'aumviso' ); ?>
                    </button>
                    <button type="button" class="button aumviso-ai-btn" data-action="generate_faqs" data-post="<?php echo esc_attr( $post->ID ); ?>">
                        <span class="dashicons dashicons-superhero-alt" aria-hidden="true"></span> <?php esc_html_e( 'Generate FAQs', 'aumviso' ); ?>
                    </button>
                    <button type="button" class="button aumviso-ai-btn" data-action="generate_related_questions" data-post="<?php echo esc_attr( $post->ID ); ?>">
                        <span class="dashicons dashicons-superhero-alt" aria-hidden="true"></span> <?php esc_html_e( 'Generate Related Questions', 'aumviso' ); ?>
                    </button>
                </div>

                <div id="aumviso-ai-result" style="margin-top:12px;display:none;">
                    <div class="aumviso-ai-output"></div>
                    <button type="button" class="button button-small" id="aumviso-ai-copy"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span> <?php esc_html_e( 'Copy', 'aumviso' ); ?></button>
                </div>

            </div>

        </div>
        <?php
    }

    // ------------------------------------
    // Save
    // ------------------------------------

    public function save_meta( int $post_id, WP_Post $post ): void {
        if ( ! isset( $_POST['aumviso_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aumviso_meta_nonce'] ) ), 'aumviso_meta_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $enabled_pts = AumViso_Options::get_enabled_post_types();
        if ( ! in_array( $post->post_type, $enabled_pts, true ) ) return;

        $fields = [
            '_aumviso_seo_title'                  => 'sanitize_text_field',
            '_aumviso_seo_description'            => 'sanitize_textarea_field',
            '_aumviso_seo_canonical'              => 'esc_url_raw',
            '_aumviso_seo_index'                  => 'sanitize_text_field',
            '_aumviso_seo_follow'                 => 'sanitize_text_field',
            '_aumviso_og_title'                   => 'sanitize_text_field',
            '_aumviso_og_description'             => 'sanitize_textarea_field',
            '_aumviso_og_image'                   => 'esc_url_raw',
            '_aumviso_schema_type'                => 'sanitize_text_field',
            // Product
            '_aumviso_product_price'       => 'sanitize_text_field',
            '_aumviso_product_currency'    => 'sanitize_text_field',
            '_aumviso_product_sku'         => 'sanitize_text_field',
            '_aumviso_product_availability'=> 'sanitize_text_field',
            '_aumviso_product_brand'       => 'sanitize_text_field',
            '_aumviso_rating_value'        => 'sanitize_text_field',
            '_aumviso_rating_count'        => 'sanitize_text_field',
            // Review
            '_aumviso_review_item_name'    => 'sanitize_text_field',
            '_aumviso_review_item_type'    => 'sanitize_text_field',
            '_aumviso_review_rating'       => 'sanitize_text_field',
            '_aumviso_review_body'         => 'sanitize_textarea_field',
            // Video
            '_aumviso_video_url'           => 'esc_url_raw',
            '_aumviso_video_name'          => 'sanitize_text_field',
            '_aumviso_video_thumb'         => 'esc_url_raw',
            '_aumviso_video_duration'      => 'sanitize_text_field',
            '_aumviso_video_upload_date'   => 'sanitize_text_field',
        ];

        $post_keys = [
            '_aumviso_seo_title'                  => 'aumviso_seo_title',
            '_aumviso_seo_description'            => 'aumviso_seo_description',
            '_aumviso_seo_canonical'              => 'aumviso_canonical',
            '_aumviso_seo_index'                  => 'aumviso_seo_index',
            '_aumviso_seo_follow'                 => 'aumviso_seo_follow',
            '_aumviso_og_title'                   => 'aumviso_og_title',
            '_aumviso_og_description'             => 'aumviso_og_description',
            '_aumviso_og_image'                   => 'aumviso_og_image',
            '_aumviso_schema_type'                => 'aumviso_schema_type',
            // Product
            '_aumviso_product_price'       => 'aumviso_product_price',
            '_aumviso_product_currency'    => 'aumviso_product_currency',
            '_aumviso_product_sku'         => 'aumviso_product_sku',
            '_aumviso_product_availability'=> 'aumviso_product_availability',
            '_aumviso_product_brand'       => 'aumviso_product_brand',
            '_aumviso_rating_value'        => 'aumviso_rating_value',
            '_aumviso_rating_count'        => 'aumviso_rating_count',
            // Review
            '_aumviso_review_item_name'    => 'aumviso_review_item_name',
            '_aumviso_review_item_type'    => 'aumviso_review_item_type',
            '_aumviso_review_rating'       => 'aumviso_review_rating',
            '_aumviso_review_body'         => 'aumviso_review_body',
            // Video
            '_aumviso_video_url'           => 'aumviso_video_url',
            '_aumviso_video_name'          => 'aumviso_video_name',
            '_aumviso_video_thumb'         => 'aumviso_video_thumb',
            '_aumviso_video_duration'      => 'aumviso_video_duration',
            '_aumviso_video_upload_date'   => 'aumviso_video_upload_date',
        ];

        foreach ( $fields as $meta_key => $sanitizer ) {
            $post_key = $post_keys[ $meta_key ];
            if ( isset( $_POST[ $post_key ] ) ) {
                $raw   = wp_unslash( $_POST[ $post_key ] );
                $value = call_user_func( $sanitizer, $raw );
                if ( $value ) {
                    update_post_meta( $post_id, $meta_key, $value );
                } else {
                    delete_post_meta( $post_id, $meta_key );
                }
            }
        }

        // Linked FAQ checkboxes are absent from POST when unchecked; persist explicitly.
        $linked_ids = isset( $_POST['aumviso_linked_faq_ids'] )
            ? array_map( 'absint', (array) wp_unslash( $_POST['aumviso_linked_faq_ids'] ) )
            : [];
        update_post_meta( $post_id, '_aumviso_linked_faq_ids', $linked_ids );
    }
}
