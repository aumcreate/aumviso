<?php
defined( 'ABSPATH' ) || exit;

/**
 * Outputs <title>, meta description, robots, canonical tags.
 * Supports: singular posts, archive pages, taxonomy terms,
 *           front page, author archives, search (noindex), date archives.
 */
class AumViso_MetaManager {

    private static ?AumViso_MetaManager $instance = null;

    public static function instance(): AumViso_MetaManager {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        AumViso_HeadGate::init();
        // Remove default WP title tag; we handle it.
        add_filter( 'pre_get_document_title', [ $this, 'get_title' ], 10 );
        add_action( 'wp_head', [ $this, 'output_meta' ], 1 );
        // Disable the default output of canonical/shortlink in WordPress to avoid duplication with plugins
        remove_action( 'wp_head', 'rel_canonical' );
        remove_action( 'wp_head', 'wp_shortlink_wp_head' );
    }

    // ------------------------------------
    // Title
    // ------------------------------------

    public function get_title( string $title ): string {
        /*
         * 门控时不用我们存的 SEO 标题去覆盖——把这一页交回给 WordPress。
         *
         * ⚠️ 交回去之后 <title> 仍然是真实页面标题（核心 wp_get_document_title()
         * 的固有行为，Woo 的门控没有改它）。**那不是我们的泄漏，我们也不去改它**：
         * 一个 SEO 插件在门控页上悄悄改写文档标题，是「比核心更严」而不是
         * 「补自己的漏」，而且出问题时没人会想到来这里找。
         * 想要那个行为的人挂 aumviso_output_head_meta 之外的自己那一层——
         * 我们的主题就是这么做的。
         */
        if ( ! AumViso_HeadGate::should_output( 'title' ) ) {
            return $title;
        }
        $context = self::detect_context();
        if ( ! $context ) return $title;

        $custom = $this->get_field( $context, '_aumviso_seo_title' );
        if ( $custom ) return esc_html( $custom );

        /*
         * ⚠️ If the template resolves to nothing usable, **hand the title back to
         * WordPress** rather than emitting an empty or half-built one. `wp_title`'s own
         * value is already a correct, translated document title for every archive.
         */
        $template = AumViso_Options::title_template();
        $built    = AumViso_Options::resolve_template( $template, $context['object'] ?? null );

        return $built === '' ? $title : esc_html( $built );
    }

    // ------------------------------------
    // Head output
    // ------------------------------------

    public function output_meta(): void {
        if ( ! AumViso_HeadGate::should_output( 'meta' ) ) {
            return;
        }
        $context = self::detect_context();
        if ( ! $context ) return;

        $desc      = $this->resolve_description( $context );
        $robots    = $this->resolve_robots( $context );
        $canonical = $this->resolve_canonical( $context );

        if ( $desc ) {
            printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
        }

        if ( $robots ) {
            printf( '<meta name="robots" content="%s">' . "\n", esc_attr( $robots ) );
        }

        if ( $canonical ) {
            printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $canonical ) );
        }
    }

    // ------------------------------------
    // Context detection
    // ------------------------------------

    /**
     * Returns array with:
     *   type   => singular | archive | taxonomy | front | author | search | date
     *   object => WP_Post | WP_Term | WP_User | null
     *   id     => int|null
     */
    public static function detect_context(): ?array {
        if ( is_singular() ) {
            $post = get_queried_object();
            if ( ! $post instanceof WP_Post ) return null;
            if ( ! AumViso_Options::is_post_type_enabled( $post->post_type ) ) return null;

            // Level 3 control: single post/page level toggle
            if ( ! AumViso_Options::is_pt_level_enabled( $post->post_type, 'singular' ) ) return null;

            return [ 'type' => 'singular', 'object' => $post, 'id' => $post->ID ];
        }

        if ( is_front_page() ) {
            return [ 'type' => 'front', 'object' => null, 'id' => null ];
        }

        if ( is_home() ) {
            return [ 'type' => 'blog', 'object' => null, 'id' => null ];
        }

        if ( is_category() || is_tag() || is_tax() ) {
            $term    = get_queried_object();
            if ( ! $term instanceof WP_Term ) return null;

            $tax     = $term->taxonomy;
            $tax_obj = get_taxonomy( $tax );

            // Controlled through the Tax Archive setting of the related post type
            // category/tag belong to "post", while custom taxonomies belong to their respective CPT
            $pt = $tax_obj->object_type[0] ?? '';

            if ( ! $pt || ! AumViso_Options::is_post_type_enabled( $pt ) ) return null;
            if ( ! AumViso_Options::is_pt_level_enabled( $pt, 'tax_archive' ) ) return null;

            return [ 'type' => 'taxonomy', 'object' => $term, 'id' => $term->term_id ];
        }

        if ( is_author() ) {
            $user = get_queried_object();
            return [ 'type' => 'author', 'object' => $user, 'id' => $user->ID ?? null ];
        }

        if ( is_search() ) {
            return [ 'type' => 'search', 'object' => null, 'id' => null ];
        }

        if ( is_date() ) {
            return [ 'type' => 'date', 'object' => null, 'id' => null ];
        }

        if ( is_post_type_archive() ) {
            $post_type = get_query_var( 'post_type' );

            if ( ! AumViso_Options::is_post_type_enabled( $post_type ) ) return null;

            // Level 3 control: archive page toggle
            if ( ! AumViso_Options::is_pt_level_enabled( $post_type, 'archive' ) ) return null;

            return [ 'type' => 'pt_archive', 'object' => null, 'id' => null, 'post_type' => $post_type ];
        }

        return null;
    }

    // ------------------------------------
    // Field resolver helpers
    // ------------------------------------

    private function get_field( array $context, string $meta_key ): string {
        if ( $context['type'] === 'singular' && $context['id'] ) {
            return (string) get_post_meta( $context['id'], $meta_key, true );
        }
        if ( $context['type'] === 'taxonomy' && $context['id'] ) {
            return (string) get_term_meta( $context['id'], $meta_key, true );
        }
        return '';
    }

    private function resolve_description( array $context ): string {
        $custom = $this->get_field( $context, '_aumviso_seo_description' );
        if ( $custom ) return $custom;

        $template = AumViso_Options::desc_template();
        return AumViso_Options::resolve_template( $template, $context['object'] ?? null );
    }

    private function resolve_robots( array $context ): string {
        // Search pages are always noindex
        if ( $context['type'] === 'search' ) return 'noindex, follow';
        // Date archives — noindex by default
        if ( $context['type'] === 'date' ) return 'noindex, follow';

        // Paged results — noindex (optional, configurable later)
        if ( is_paged() ) {
            // keep index but add noarchive as mild signal
        }

        $index  = $this->get_field( $context, '_aumviso_seo_index' );
        $follow = $this->get_field( $context, '_aumviso_seo_follow' );

        $parts = [];
        $parts[] = ( $index  === 'noindex'  ) ? 'noindex'  : 'index';
        $parts[] = ( $follow === 'nofollow' ) ? 'nofollow' : 'follow';

        $robots = implode( ', ', $parts );

        // If it's the default "index, follow" skip outputting it (redundant)
        return $robots === 'index, follow' ? '' : $robots;
    }

    private function resolve_canonical( array $context ): string {
        $custom = $this->get_field( $context, '_aumviso_seo_canonical' );
        if ( $custom ) return $custom;

        // Auto-canonical
        if ( $context['type'] === 'singular' ) {
            return get_permalink( $context['id'] );
        }
        if ( $context['type'] === 'taxonomy' ) {
            return get_term_link( $context['id'] );
        }
        if ( $context['type'] === 'front' ) {
            return home_url( '/' );
        }

        // Strip query params for paged archives
        global $wp;
        $url = home_url( $wp->request );
        // Remove page param
        $url = preg_replace( '#/page/\d+/?$#', '', $url );
        return trailingslashit( $url );
    }

    // ------------------------------------
    // Public API for meta box / other classes
    // ------------------------------------

    /**
     * Get resolved SEO data for a post (used by meta box, sitemap, etc.)
     */
    public static function get_post_seo( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post ) return [];

        $title = get_post_meta( $post_id, '_aumviso_seo_title', true )
            ?: AumViso_Options::resolve_template( AumViso_Options::title_template(), $post );

        $desc = get_post_meta( $post_id, '_aumviso_seo_description', true )
            ?: AumViso_Options::resolve_template( AumViso_Options::desc_template(), $post );

        return [
            'title'     => $title,
            'desc'      => $desc,
            'canonical' => get_post_meta( $post_id, '_aumviso_seo_canonical', true ) ?: get_permalink( $post_id ),
            'index'     => get_post_meta( $post_id, '_aumviso_seo_index',     true ) ?: 'index',
            'follow'    => get_post_meta( $post_id, '_aumviso_seo_follow',    true ) ?: 'follow',
        ];
    }
}
