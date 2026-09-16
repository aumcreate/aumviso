<?php
defined( 'ABSPATH' ) || exit;

class AumViso_Robots {

    private static ?AumViso_Robots $instance = null;

    public static function instance(): AumViso_Robots {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Override WordPress default robots output via virtual file
        add_filter( 'robots_txt', [ $this, 'filter_robots' ], 10, 2 );
    }

    public function filter_robots( string $output, bool $public ): string {
        $saved = AumViso_Options::get( 'aumviso_robots_txt', '' );
        if ( $saved ) {
            return $saved;
        }

        // Build default
        return $this->default_robots( $public );
    }

    public function default_robots( bool $public ): string {
        $sitemap = home_url( '/sitemap.xml' );

        if ( ! $public ) {
            return "User-agent: *\nDisallow: /\n\nSitemap: {$sitemap}\n";
        }

        $lines   = [];
        $lines[] = 'User-agent: *';
        $lines[] = 'Disallow: /wp-admin/';
        $lines[] = 'Allow: /wp-admin/admin-ajax.php';
        $lines[] = '';
        $lines[] = '# Common crawl traps';
        $lines[] = 'Disallow: /wp-content/plugins/';
        $lines[] = 'Disallow: /wp-content/themes/';
        $lines[] = 'Disallow: /?s=';
        $lines[] = 'Disallow: /search/';
        $lines[] = '';
        $lines[] = "Sitemap: {$sitemap}";

        return implode( "\n", $lines ) . "\n";
    }

    /**
     * Save custom robots.txt content.
     */
    public static function save( string $content ): void {
        AumViso_Options::set( 'aumviso_robots_txt', sanitize_textarea_field( $content ) );
    }

    /**
     * Get current robots.txt content for display in admin.
     */
    public static function get_content(): string {
        $saved = AumViso_Options::get( 'aumviso_robots_txt', '' );
        if ( $saved ) return $saved;

        $instance = self::instance();
        return $instance->default_robots( (bool) get_option( 'blog_public' ) );
    }
}
