<?php
defined( 'ABSPATH' ) || exit;

class AumViso_DashboardWidget {

    private static ?AumViso_DashboardWidget $instance = null;

    public static function instance(): AumViso_DashboardWidget {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_dashboard_setup', [ $this, 'register' ] );
    }

    public function register(): void {
        wp_add_dashboard_widget(
            'aumviso_dashboard_widget',
            '<span class="dashicons dashicons-search" style="color:#e8501a;vertical-align:middle;"></span> ' . __( 'AumViso — SEO Status', 'aumviso' ),
            [ $this, 'render' ]
        );
    }

    public function render(): void {
        global $wpdb;

        $missing_desc = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id AND pm.meta_key='_aumviso_seo_description'
             WHERE p.post_type IN ('post','page') AND p.post_status='publish'
             AND (pm.meta_value IS NULL OR pm.meta_value=%s)", ''
        ) );

        $missing_og = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} th ON p.ID=th.post_id AND th.meta_key='_thumbnail_id'
             LEFT JOIN {$wpdb->postmeta} og ON p.ID=og.post_id AND og.meta_key='_aumviso_og_image'
             WHERE p.post_type='post' AND p.post_status='publish'
             AND th.meta_value IS NULL AND og.meta_value IS NULL"
        );

        $noindex = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_aumviso_seo_index' AND meta_value=%s", 'noindex'
        ) );

        $sitemap_ok = (bool) AumViso_Options::get( 'aumviso_sitemap_enabled', true );

        $items = [
            [
                'label'  => $missing_desc > 0
                    /* translators: %d: number of posts with no meta description. */
                    ? sprintf( __( '%d posts missing meta description', 'aumviso' ), $missing_desc )
                    : __( 'All posts have meta descriptions', 'aumviso' ),
                'status' => $missing_desc > 0 ? 'warn' : 'ok',
                'link'   => $missing_desc > 0 ? admin_url( 'edit.php?aumviso_filter=missing_desc' ) : '',
            ],
            [
                'label'  => $missing_og > 0
                    /* translators: %d: number of posts with no Open Graph image. */
                    ? sprintf( __( '%d posts missing OG image', 'aumviso' ), $missing_og )
                    : __( 'All posts have OG images', 'aumviso' ),
                'status' => $missing_og > 0 ? 'warn' : 'ok',
                'link'   => $missing_og > 0 ? admin_url( 'edit.php?aumviso_filter=missing_og' ) : '',
            ],
            [
                'label'  => $noindex > 0
                    /* translators: %d: number of pages marked noindex. */
                    ? sprintf( __( '%d pages set to noindex', 'aumviso' ), $noindex )
                    : __( 'No noindex pages found', 'aumviso' ),
                'status' => $noindex > 0 ? 'error' : 'ok',
                'link'   => $noindex > 0 ? admin_url( 'edit.php?aumviso_filter=noindex' ) : '',
            ],
            [
                'label'  => $sitemap_ok
                    ? __( 'Sitemap active', 'aumviso' )
                    : __( 'Sitemap disabled', 'aumviso' ),
                'status' => $sitemap_ok ? 'ok' : 'error',
                'link'   => $sitemap_ok ? home_url( '/sitemap.xml' ) : AumViso_Admin::tab_url( 'sitemap' ),
            ],
        ];

        $icons = [ 'ok' => 'dashicons-yes-alt', 'warn' => 'dashicons-warning', 'error' => 'dashicons-dismiss' ];
        $tints = [ 'ok' => '#0f6e56', 'warn' => '#8a6d1a', 'error' => '#b32d2e' ];
        ?>
        <ul class="aumviso-widget-list" style="margin:0;padding:0;list-style:none;">
            <?php foreach ( $items as $item ) :
                $status = $item['status'];
            ?>
            <li style="padding:8px 0;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;gap:8px;">
                <span style="display:inline-flex;align-items:center;gap:7px;">
                    <span class="dashicons <?php echo esc_attr( $icons[ $status ] ?? 'dashicons-marker' ); ?>" style="color:<?php echo esc_attr( $tints[ $status ] ?? '#646970' ); ?>;" aria-hidden="true"></span>
                    <?php echo esc_html( $item['label'] ); ?>
                </span>
                <?php if ( $item['link'] ) : ?>
                <a href="<?php echo esc_url( $item['link'] ); ?>" class="button button-small" target="<?php echo str_starts_with( $item['link'], home_url() ) ? '_blank' : '_self'; ?>">
                    <?php esc_html_e( 'View', 'aumviso' ); ?>
                </a>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <p style="margin-top:10px;">
            <a href="<?php echo esc_url( AumViso_Admin::tab_url( 'overview' ) ); ?>" class="button button-primary">
                <?php esc_html_e( 'Open AumViso', 'aumviso' ); ?>
            </a>
        </p>
        <?php
    }
}
