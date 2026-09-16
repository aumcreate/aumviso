<?php
defined( 'ABSPATH' ) || exit;

class AumViso_Sitemap {

    private static ?AumViso_Sitemap $instance = null;
    private const MAX_URLS = 50000;

    public static function instance(): AumViso_Sitemap {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Disable the built-in WordPress 5.5+ sitemap to prevent duplicate sitemaps.
        add_filter( 'wp_sitemaps_enabled', '__return_false' );

        add_action( 'init', [ $this, 'register_rewrites' ] );
        add_filter( 'query_vars', [ $this, 'query_vars' ] );
        add_action( 'template_redirect', [ $this, 'handle_request' ] );
        add_action( 'save_post', [ $this, 'clear_cache' ] );
        add_action( 'edited_term', [ $this, 'clear_cache' ] );
    }

    // ------------------------------------
    // Rewrite rules
    // ------------------------------------

    public function register_rewrites(): void {
        add_rewrite_rule( '^sitemap\.xml$', 'index.php?aumviso_sitemap=index', 'top' );
        add_rewrite_rule( '^([a-z0-9_-]+)-sitemap\.xml$', 'index.php?aumviso_sitemap=$matches[1]', 'top' );
        add_rewrite_rule( '^([a-z0-9_-]+)-sitemap(\d+)\.xml$', 'index.php?aumviso_sitemap=$matches[1]&aumviso_sitemap_page=$matches[2]', 'top' );
    }

    public function query_vars( array $vars ): array {
        $vars[] = 'aumviso_sitemap';
        $vars[] = 'aumviso_sitemap_page';
        return $vars;
    }

    // ------------------------------------
    // Request handler
    // ------------------------------------

    private function xsl_url(): string {
        return plugins_url( 'public/assets/xsl/sitemap.xsl', AUMVISO_FILE );
    }

    public function handle_request(): void {
        $sitemap = get_query_var( 'aumviso_sitemap' );
        if ( ! $sitemap ) return;

        if ( ! AumViso_Options::get( 'aumviso_sitemap_enabled', true ) ) {
            wp_die( 'Sitemap disabled.', 404 );
        }

        $page = max( 1, (int) get_query_var( 'aumviso_sitemap_page', 1 ) );

        status_header( 200 );
        header( 'Content-Type: application/xml; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex, follow' );

        if ( $sitemap === 'index' ) {
            echo $this->build_index(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML document assembled in build_index(), where every dynamic value is passed through esc_url().
        } else {
            echo $this->build_sitemap( $sitemap, $page ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML document assembled in build_sitemap(), where every dynamic value is escaped at the point it is written.
        }
        exit;
    }

    // ------------------------------------
    // Sitemap Index
    // ------------------------------------

    private function build_index(): string {
        $enabled_pts = (array) AumViso_Options::get( 'aumviso_sitemap_post_types', [] );
        $enabled_tax = (array) AumViso_Options::get( 'aumviso_sitemap_taxonomies', [] );

        $xsl = $this->xsl_url();
        ob_start();
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?xml-stylesheet type="text/xsl" href="' . esc_url( $xsl ) . '"?>' . "\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ( $enabled_pts as $pt ) {
            $count  = wp_count_posts( $pt )->publish ?? 0;
            $pages  = max( 1, ceil( $count / self::MAX_URLS ) );
            for ( $p = 1; $p <= $pages; $p++ ) {
                $suffix = $p > 1 ? $p : '';
                $this->sitemap_entry( home_url( "/{$pt}-sitemap{$suffix}.xml" ), $this->last_modified_pt( $pt ) );
            }
        }

        foreach ( $enabled_tax as $tax ) {
            $count = wp_count_terms( [ 'taxonomy' => $tax, 'hide_empty' => true ] );
            if ( $count > 0 ) {
                $this->sitemap_entry( home_url( "/{$tax}-sitemap.xml" ), null );
            }
        }

        // Image sitemap
        if ( AumViso_Options::get( 'aumviso_sitemap_images', true ) ) {
            $this->sitemap_entry( home_url( '/image-sitemap.xml' ), null );
        }

        echo '</sitemapindex>';
        return ob_get_clean();
    }

    private function sitemap_entry( string $url, ?string $lastmod ): void {
        echo "  <sitemap>\n";
        echo "    <loc>" . esc_url( $url ) . "</loc>\n";
        if ( $lastmod ) {
            echo "    <lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
        }
        echo "  </sitemap>\n";
    }

    // ------------------------------------
    // Individual sitemaps
    // ------------------------------------

    private function build_sitemap( string $type, int $page ): string {
        $enabled_pts = (array) AumViso_Options::get( 'aumviso_sitemap_post_types', [] );
        $enabled_tax = (array) AumViso_Options::get( 'aumviso_sitemap_taxonomies', [] );

        $xsl = $this->xsl_url();
        ob_start();
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?xml-stylesheet type="text/xsl" href="' . esc_url( $xsl ) . '"?>' . "\n";

        $include_images = AumViso_Options::get( 'aumviso_sitemap_images', true );
        $ns = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        if ( $include_images && in_array( $type, $enabled_pts, true ) ) {
            $ns .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
        }
        echo "<urlset {$ns}>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $ns is built from string literals in this method only.

        if ( $type === 'image' ) {
            $this->output_image_sitemap();
        } elseif ( in_array( $type, $enabled_pts, true ) ) {
            $this->output_post_type_urls( $type, $page, $include_images );
        } elseif ( in_array( $type, $enabled_tax, true ) ) {
            $this->output_taxonomy_urls( $type );
        }

        echo '</urlset>';
        return ob_get_clean();
    }

    private function output_post_type_urls( string $pt, int $page, bool $include_images ): void {
        $offset = ( $page - 1 ) * self::MAX_URLS;
        // Use an OR meta_query: include posts that do not have _aumviso_seo_index set,
        // as well as posts where it is set but not equal to 'noindex'.
        $posts  = get_posts( [
            'post_type'      => $pt,
            'post_status'    => 'publish',
            'posts_per_page' => self::MAX_URLS,
            'offset'         => $offset,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_aumviso_seo_index',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_aumviso_seo_index',
                    'value'   => 'noindex',
                    'compare' => '!=',
                ],
            ],
        ] );

        foreach ( $posts as $post_id ) {
            $post    = get_post( $post_id );
            $lastmod = mysql2date( 'c', $post->post_modified_gmt );
            echo "  <url>\n";
            echo "    <loc>" . esc_url( get_permalink( $post_id ) ) . "</loc>\n";
            echo '    <lastmod>' . esc_html( $lastmod ) . "</lastmod>\n";
            echo "    <changefreq>weekly</changefreq>\n";
            echo "    <priority>" . esc_html( $this->get_priority( $pt ) ) . "</priority>\n";

            // Image tags
            if ( $include_images ) {
                $this->output_image_tags( $post_id );
            }

            echo "  </url>\n";
        }
    }

    private function output_taxonomy_urls( string $taxonomy ): void {
        $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => true ] );
        if ( is_wp_error( $terms ) ) return;

        foreach ( $terms as $term ) {
            echo "  <url>\n";
            echo "    <loc>" . esc_url( get_term_link( $term ) ) . "</loc>\n";
            echo "    <changefreq>weekly</changefreq>\n";
            echo "    <priority>0.6</priority>\n";
            echo "  </url>\n";
        }
    }

    private function output_image_sitemap(): void {
        global $wpdb;
        $attachments = $wpdb->get_results(
            "SELECT ID, guid, post_title FROM {$wpdb->posts}
             WHERE post_type='attachment' AND post_status='inherit'
             AND post_mime_type LIKE 'image/%'
             ORDER BY ID DESC LIMIT 50000"
        );

        foreach ( $attachments as $att ) {
            echo "  <url>\n";
            echo "    <loc>" . esc_url( $att->guid ) . "</loc>\n";
            echo "  </url>\n";
        }
    }

    private function output_image_tags( int $post_id ): void {
        // Featured image
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            $src = wp_get_attachment_image_url( $thumb_id, 'full' );
            $alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
            if ( $src ) {
                echo "    <image:image>\n";
                echo "      <image:loc>" . esc_url( $src ) . "</image:loc>\n";
                if ( $alt ) echo "      <image:title>" . esc_html( $alt ) . "</image:title>\n";
                echo "    </image:image>\n";
            }
        }

        // Content images
        $post    = get_post( $post_id );
        $content = $post->post_content;
        preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );
        foreach ( array_unique( $matches[1] ) as $img_url ) {
            if ( strpos( $img_url, home_url() ) !== false ) {
                echo "    <image:image>\n";
                echo "      <image:loc>" . esc_url( $img_url ) . "</image:loc>\n";
                echo "    </image:image>\n";
            }
        }
    }

    // ------------------------------------
    // Helpers
    // ------------------------------------

    private function get_priority( string $pt ): string {
        return match ( $pt ) {
            'page'  => '0.8',
            'post'  => '0.7',
            default => '0.6',
        };
    }

    private function last_modified_pt( string $pt ): ?string {
        global $wpdb;
        $date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}
             WHERE post_type=%s AND post_status='publish'",
            $pt
        ) );
        return $date ? mysql2date( 'c', $date ) : null;
    }

    public function clear_cache(): void {
        // Future: clear object cache key if using transients
    }
}
