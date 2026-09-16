<?php
defined( 'ABSPATH' ) || exit;

/**
 * SEO Score Analyzer
 * Performs a multi-factor SEO evaluation for a single post
 * and displays the result inside the meta box.
 */
class AumViso_SEOScore {

    private static ?AumViso_SEOScore $instance = null;

    public static function instance(): AumViso_SEOScore {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'wp_ajax_aumviso_get_score', [ $this, 'ajax_get_score' ] );
    }

    public function add_meta_box(): void {
        $enabled_pts = AumViso_Options::get_enabled_post_types();
        $screen      = get_current_screen();
        if ( ! in_array( $screen?->post_type, $enabled_pts, true ) ) return;

        add_meta_box(
            'aumviso_score',
            '<span class="dashicons dashicons-chart-bar" style="color:#e8501a;vertical-align:middle;"></span> ' . __( 'SEO Score', 'aumviso' ),
            [ $this, 'render' ],
            null,
            'side',
            'high'
        );
    }

    public function render( WP_Post $post ): void {
        $score_data = $this->analyze( $post );
        $total      = $score_data['total'];
        $grade      = $this->get_grade( $total );
        ?>
        <div class="aumviso-score-widget">

            <!-- Score Circle -->
            <div class="aumviso-score-circle aumviso-score-<?php echo esc_attr( $grade['class'] ); ?>">
                <span class="aumviso-score-number"><?php echo esc_html( $total ); ?></span>
                <span class="aumviso-score-label"><?php echo esc_html( $grade['label'] ); ?></span>
            </div>

            <!-- Focus keyword -->
            <div class="aumviso-focus-kw" style="margin:12px 0;">
                <label for="aumviso_focus_kw"><strong><?php esc_html_e( 'Focus Keyword', 'aumviso' ); ?></strong></label>
                <div style="display:flex;gap:6px;margin-top:4px;">
                    <input type="text" id="aumviso_focus_kw" name="aumviso_focus_kw"
                        value="<?php echo esc_attr( get_post_meta( $post->ID, '_aumviso_focus_kw', true ) ); ?>"
                        placeholder="<?php esc_attr_e( 'e.g. CNC machining', 'aumviso' ); ?>"
                        style="flex:1;">
                    <button type="button" class="button button-small aumviso-reanalyze" id="aumviso-reanalyze"
                        data-post="<?php echo esc_attr( $post->ID ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'aumviso_ajax' ) ); ?>"
                        aria-label="<?php esc_attr_e( 'Re-analyze', 'aumviso' ); ?>"
                        title="<?php esc_attr_e( 'Re-analyze', 'aumviso' ); ?>">
                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    </button>
                </div>
            </div>

            <!-- Checks list -->
            <ul class="aumviso-checks-list" id="aumviso-checks-list">
                <?php foreach ( $score_data['checks'] as $check ) : ?>
                <li class="aumviso-check aumviso-check--<?php echo esc_attr( $check['status'] ); ?>">
                    <span class="aumviso-check__icon dashicons <?php echo esc_attr( self::status_icon( $check['status'] ) ); ?>" aria-hidden="true"></span>
                    <span class="aumviso-check__text"><?php echo esc_html( $check['label'] ); ?></span>
                    <span class="aumviso-check__pts">+<?php echo esc_html( $check['points'] ); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>

        </div>
        <?php
        // The re-analyze handler lives in admin/assets/js/admin.js (it also feeds
        // live editor content into scoring), so no inline script is needed here.
    }

    /** Dashicon class for a check status. */
    public static function status_icon( string $status ): string {
        return $status === 'good' ? 'dashicons-yes-alt' : ( $status === 'ok' ? 'dashicons-warning' : 'dashicons-dismiss' );
    }

    public function ajax_get_score(): void {
        check_ajax_referer( 'aumviso_ajax', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();

        $post_id  = (int) ( $_POST['post_id'] ?? 0 );
        $focus_kw = sanitize_text_field( wp_unslash( $_POST['focus_kw'] ?? '' ) );
        $post     = get_post( $post_id );

        if ( ! $post ) wp_send_json_error( [ 'message' => 'Post not found' ] );

        // Receive live field values from the editor (unsaved state)
        // and override stored database values for real-time scoring.
        $live = [
            'seo_title'       => isset( $_POST['live_seo_title'] )      ? sanitize_text_field( wp_unslash( $_POST['live_seo_title'] ) )    : null,
            'seo_desc'        => isset( $_POST['live_seo_desc'] )       ? sanitize_textarea_field( wp_unslash( $_POST['live_seo_desc'] ) ) : null,
            'post_title'      => isset( $_POST['live_post_title'] )     ? sanitize_text_field( wp_unslash( $_POST['live_post_title'] ) )   : null,
            'post_content'    => isset( $_POST['live_post_content'] )   ? wp_kses_post( wp_unslash( $_POST['live_post_content'] ) )        : null,
            'has_thumbnail'   => isset( $_POST['live_has_thumbnail'] )  ? (bool) intval( $_POST['live_has_thumbnail'] )      : null,
        ];

        // Persist the focus keyword only when the re-analyze button is clicked manually
        // and the request explicitly includes the save_kw flag.
        if ( $focus_kw && ! empty( $_POST['save_kw'] ) ) {
            update_post_meta( $post_id, '_aumviso_focus_kw', $focus_kw );
        }

        $score_data = $this->analyze( $post, $focus_kw, $live );
        $grade      = $this->get_grade( $score_data['total'] );

        wp_send_json_success( [
            'total'       => $score_data['total'],
            'grade_class' => $grade['class'],
            'grade_label' => $grade['label'],
            'checks'      => $score_data['checks'],
        ] );
    }

    // ------------------------------------
    // Core analysis
    // ------------------------------------

    public function analyze( WP_Post $post, string $focus_kw = '', array $live = [] ): array {
        if ( ! $focus_kw ) {
            $focus_kw = get_post_meta( $post->ID, '_aumviso_focus_kw', true );
        }

        // Prioritize live values from the editor and fall back to stored post data.
        $raw_content = $live['post_content'] ?? $post->post_content;

        /**
         * The content this score is calculated from.
         *
         * `post_content` is not where every site keeps its words. A page built with a visual builder —
         * Elementor, Divi, Bricks, or anything that renders from its own stored layout — routinely has an
         * **empty** `post_content` while the visitor sees several hundred words. Scored as-is, such a page
         * reports almost no content, fails the word-count and keyword checks, and the author is told their
         * best page is their worst. The score is wrong in a way that is worse than absent, because it looks
         * authoritative.
         *
         * A builder answers this filter with the text it actually renders. Return the raw string; it is
         * stripped of tags immediately below.
         *
         * @param string  $raw_content Content as stored (may be empty).
         * @param WP_Post $post
         * @param array   $live        Unsaved values posted from the editor, when scoring live.
         */
        $raw_content = (string) apply_filters( 'aumviso_analyzable_content', $raw_content, $post, $live );

        $content     = wp_strip_all_tags( $raw_content );
        $title       = $live['post_title'] ?? $post->post_title;
        $seo_title   = $live['seo_title'] ?? get_post_meta( $post->ID, '_aumviso_seo_title', true ) ?: $title;
        $seo_desc    = $live['seo_desc'] ?? get_post_meta( $post->ID, '_aumviso_seo_description', true );
        $word_count  = $this->count_words( $content );
        $is_cjk      = $this->has_cjk( $title . $seo_title . $seo_desc . $content . $focus_kw );
        $kw_lower    = mb_strtolower( $focus_kw );

        $checks = [];
        $total  = 0;

        // ---- 1. Custom SEO title defined (5 pts)
        $checks[] = $this->check(
            get_post_meta( $post->ID, '_aumviso_seo_title', true ) !== '',
            __( 'Custom SEO title set', 'aumviso' ),
            5
        );
        $total += end( $checks )['points_earned'];

        // ---- 2. SEO title length between 30 and 60 characters (5 pts)
        $title_len = mb_strlen( $seo_title );
        $checks[]  = $this->check(
            $title_len >= 30 && $title_len <= 60,
            /* translators: %d: character count of the SEO title. */
            sprintf( __( 'SEO title length (%d chars, ideal 30–60)', 'aumviso' ), $title_len ),
            5,
            $title_len >= 20 && $title_len <= 70
        );
        $total += end( $checks )['points_earned'];

        // ---- 3. Meta description quality (10 pts)
        $desc_len    = mb_strlen( $seo_desc );
        $has_desc    = $desc_len > 0;
        $desc_min    = $is_cjk ? 50 : 80;
        $desc_ideal  = $is_cjk ? '50–155' : '80–155';
        $good_desc   = $desc_len >= $desc_min && $desc_len <= 155;
        $checks[]    = $this->check(
            $good_desc,
            /* translators: 1: character count of the meta description, 2: the ideal range. */
            sprintf( __( 'Meta description (%1$d chars, ideal %2$s)', 'aumviso' ), $desc_len, $desc_ideal ),
            10,
            $has_desc
        );
        $total += end( $checks )['points_earned'];

        // ---- 4. Content length (10 pts)
        $checks[] = $this->check(
            $word_count >= 600,
            /* translators: %d: word count of the post content. */
            sprintf( __( 'Content length (%d words, ideal 600+)', 'aumviso' ), $word_count ),
            10,
            $word_count >= 300
        );
        $total += end( $checks )['points_earned'];

        // ---- 5. Featured image availability (10 pts)
        $has_thumb = $live['has_thumbnail'] ?? (bool) get_post_thumbnail_id( $post->ID );
        $checks[]  = $this->check(
            $has_thumb,
            __( 'Featured image set', 'aumviso' ),
            10
        );
        $total += end( $checks )['points_earned'];

        // ---- 6. Open Graph image availability (5 pts)
        $has_og   = (bool) get_post_meta( $post->ID, '_aumviso_og_image', true );
        $checks[] = $this->check(
            $has_og || $has_thumb,
            __( 'OG / social image set', 'aumviso' ),
            5
        );
        $total += end( $checks )['points_earned'];

        // ---- 7. H1/page title availability (5 pts)
        $has_h1   = preg_match( '/<h1[^>]*>/i', $raw_content ) || '' !== trim( $title );
        $checks[] = $this->check(
            (bool) $has_h1,
            __( 'H1/page title available', 'aumviso' ),
            5
        );
        $total += end( $checks )['points_earned'];

        // ---- 8. Internal links present in content (5 pts)
        $internal_links = preg_match_all(
            '/<a[^>]+href=["\']' . preg_quote( home_url(), '/' ) . '[^"\']*["\'][^>]*>/i',
            $raw_content
        );
        $checks[] = $this->check(
            $internal_links >= 2,
            /* translators: %d: number of internal links found. */
            sprintf( __( 'Internal links (%d found, ideal 2+)', 'aumviso' ), $internal_links ),
            5,
            $internal_links >= 1
        );
        $total += end( $checks )['points_earned'];

        // ---- 9. Image alt attribute coverage (5 pts)
        $total_imgs    = preg_match_all( '/<img[^>]+>/i', $raw_content );
        $imgs_with_alt = preg_match_all( '/<img[^>]+alt=["\'][^"\']+["\'][^>]*>/i', $raw_content );
        $all_alts      = $total_imgs === 0 || $imgs_with_alt >= $total_imgs;
        $checks[]      = $this->check(
            $all_alts,
            /* translators: 1: number of images that have alt text, 2: total number of images. */
            sprintf( __( 'Image alt tags (%1$d/%2$d images have alt)', 'aumviso' ), $imgs_with_alt, max( 1, $total_imgs ) ),
            5,
            $total_imgs === 0 || $imgs_with_alt / max( 1, $total_imgs ) >= 0.5
        );
        $total += end( $checks )['points_earned'];

        // ---- 10. Canonical URL defined (5 pts)
        $checks[] = $this->check(
            true, // Automatically generated by the plugin.
            __( 'Canonical URL configured', 'aumviso' ),
            5
        );
        $total += end( $checks )['points_earned'];

        // ---- Focus keyword checks (only applied when a keyword is provided) ----
        if ( $kw_lower ) {
            // 11. Focus keyword in SEO title (10 pts)
            $checks[] = $this->check(
                str_contains( strtolower( $seo_title ), $kw_lower ),
                /* translators: %s: the focus keyword. */
                sprintf( __( 'Focus keyword "%s" in SEO title', 'aumviso' ), $focus_kw ),
                10
            );
            $total += end( $checks )['points_earned'];

            // 12. Focus keyword in meta description (5 pts)
            $checks[] = $this->check(
                str_contains( strtolower( $seo_desc ), $kw_lower ),
                sprintf( __( 'Focus keyword in meta description', 'aumviso' ), $focus_kw ),
                5
            );
            $total += end( $checks )['points_earned'];

            // 13. Focus keyword within the first 100 words (5 pts)
            $first_100 = mb_substr( $content, 0, 200 );
            $checks[]  = $this->check(
                str_contains( mb_strtolower( $first_100 ), $kw_lower ),
                __( 'Focus keyword in first 100 words', 'aumviso' ),
                5
            );
            $total += end( $checks )['points_earned'];

            // 14. Focus keyword in H2/H3 headings (5 pts)
            $headings = '';
            preg_match_all( '/<h[2-3][^>]*>(.*?)<\/h[2-3]>/is', $raw_content, $hm );
            if ( ! empty( $hm[1] ) ) {
                $headings = strtolower( implode( ' ', array_map( 'wp_strip_all_tags', $hm[1] ) ) );
            }
            $checks[] = $this->check(
                str_contains( $headings, $kw_lower ),
                __( 'Focus keyword in H2/H3 heading', 'aumviso' ),
                5
            );
            $total += end( $checks )['points_earned'];

            // 15. Keyword density between 0.5% and 2.5% (5 pts)
            $kw_words     = $this->count_words( $focus_kw );
            $kw_count     = substr_count( mb_strtolower( $content ), $kw_lower );
            $density      = $word_count > 0 ? ( $kw_count * max( 1, $kw_words ) / $word_count ) * 100 : 0;
            $good_density = $density >= 0.5 && $density <= 2.5;
            $ok_density   = $density > 0;
            $checks[]     = $this->check(
                $good_density,
                /* translators: %1.1f: keyword density as a percentage. */
                sprintf( __( 'Keyword density %.1f%% (ideal 0.5%%–2.5%%)', 'aumviso' ), $density ),
                5,
                $ok_density
            );
            $total += end( $checks )['points_earned'];
        }

        return [
            'total'  => min( 100, $total ),
            'checks' => $checks,
        ];
    }

    // ------------------------------------
    // Helpers
    // ------------------------------------

    /**
     * Builds a check result.
     * $good awards full points, while $ok awards partial points (half).
     */
    private function check( bool $good, string $label, int $max_points, bool $ok = false ): array {
        if ( $good ) {
            return [ 'status' => 'good', 'label' => $label, 'points' => $max_points, 'points_earned' => $max_points ];
        }
        if ( $ok ) {
            $half = (int) ceil( $max_points / 2 );
            return [ 'status' => 'ok', 'label' => $label, 'points' => $half, 'points_earned' => $half ];
        }
        return [ 'status' => 'bad', 'label' => $label, 'points' => 0, 'points_earned' => 0 ];
    }

    private function get_grade( int $score ): array {
        if ( $score >= 80 ) return [ 'class' => 'great',   'label' => __( 'Great',   'aumviso' ) ];
        if ( $score >= 60 ) return [ 'class' => 'good',    'label' => __( 'Good',    'aumviso' ) ];
        if ( $score >= 40 ) return [ 'class' => 'average', 'label' => __( 'Average', 'aumviso' ) ];
        return                     [ 'class' => 'poor',    'label' => __( 'Poor',    'aumviso' ) ];
    }

    /**
     * Counts words with support for CJK languages such as
     * Chinese, Japanese, and Korean.
     * Latin-based text is counted by word boundaries,
     * while each CJK character is counted as one word unit.
     */
    private function has_cjk( string $text ): bool {
        return (bool) preg_match(
            '/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u',
            $text
        );
    }
    private function count_words( string $text ): int {
        $text = trim( $text );
        if ( $text === '' ) return 0;

        // Count CJK characters (Chinese, Japanese, Korean, etc.).
        $cjk_count = preg_match_all(
            '/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{20000}-\x{2A6DF}' .
            '\x{2A700}-\x{2B73F}\x{2B740}-\x{2B81F}\x{2B820}-\x{2CEAF}' .
            '\x{F900}-\x{FAFF}\x{2F800}-\x{2FA1F}' .
            '\x{3040}-\x{309F}\x{30A0}-\x{30FF}' . // Hiragana and Katakana
            '\x{AC00}-\x{D7AF}]/u',                // Hangul
            $text
        );

        // Remove CJK characters, then count remaining Latin-based words.
        $latin_text  = preg_replace(
            '/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u',
            ' ',
            $text
        );
        $latin_count = str_word_count( $latin_text );

        return (int) $cjk_count + (int) $latin_count;
    }
}
