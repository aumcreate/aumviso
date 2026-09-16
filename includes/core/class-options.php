<?php
defined( 'ABSPATH' ) || exit;

/**
 * Centralized options helper with template variable resolution.
 */
class AumViso_Options {

    private static ?AumViso_Options $instance = null;

    public static function instance(): AumViso_Options {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ------------------------------------
    // Generic get/set
    // ------------------------------------

    public static function get( string $key, $default = false ) {
        return get_option( $key, $default );
    }

    public static function set( string $key, $value ): void {
        update_option( $key, $value );
    }

    // ------------------------------------
    // Post-type / taxonomy checks
    // ------------------------------------

    public static function is_post_type_enabled( string $post_type ): bool {
        return in_array( $post_type, self::get_enabled_post_types(), true );
    }

    public static function is_taxonomy_enabled( string $taxonomy ): bool {
        $enabled = (array) self::get( 'aumviso_enabled_taxonomies', [] );
        return in_array( $taxonomy, $enabled, true );
    }

    public static function get_enabled_post_types(): array {
        $enabled = (array) self::get( 'aumviso_enabled_post_types', [ 'post', 'page' ] );

        /**
         * Filters the post types that receive AumViso SEO/GEO coverage.
         *
         * Extension point (Module 0): product sources and other add-ons can opt
         * their own CPTs into AumViso's meta, Open Graph, breadcrumb and schema
         * output by appending to this list.
         *
         * @param string[] $enabled Enabled post type slugs.
         */
        $enabled = (array) apply_filters( 'aumviso_enabled_post_types', $enabled );

        return array_values( array_unique( array_filter( array_map( 'strval', $enabled ) ) ) );
    }

    public static function get_enabled_taxonomies(): array {
        return (array) self::get( 'aumviso_enabled_taxonomies', [ 'category', 'post_tag' ] );
    }

    // ------------------------------------
    // Three-level control: singular / archive / tax_archive
    // ------------------------------------

    /**
     * Check whether a specific SEO level is enabled for a given CPT
     * $level: 'singular' | 'archive' | 'tax_archive'
     */
    public static function is_pt_level_enabled( string $post_type, string $level ): bool {
        $levels = self::get_pt_levels( $post_type );
        return in_array( $level, $levels, true );
    }

    public static function get_pt_levels( string $post_type ): array {
        $key = 'aumviso_pt_levels_' . $post_type;
        return (array) self::get( $key, [ 'singular', 'archive', 'tax_archive' ] );
    }

    public static function set_pt_levels( string $post_type, array $levels ): void {
        self::set( 'aumviso_pt_levels_' . $post_type, $levels );
    }

    /**
     * Determine whether SEO meta should be output for the current request
     * (combines CPT enablement and level-based control)
     */
    public static function is_current_context_enabled(): bool {
        if ( is_singular() ) {
            $post_type = get_post_type();
            return self::is_post_type_enabled( $post_type )
                && self::is_pt_level_enabled( $post_type, 'singular' );
        }

        if ( is_post_type_archive() ) {
            $post_type = get_query_var( 'post_type' );
            return self::is_post_type_enabled( $post_type )
                && self::is_pt_level_enabled( $post_type, 'archive' );
        }

        if ( is_category() || is_tag() || is_tax() ) {
            $term    = get_queried_object();
            $tax     = $term->taxonomy ?? ( is_category() ? 'category' : 'post_tag' );
            $tax_obj = get_taxonomy( $tax );
            $pt      = $tax_obj->object_type[0] ?? '';

            if ( ! $pt || ! self::is_post_type_enabled( $pt ) ) {
                return false;
            }

            return self::is_pt_level_enabled( $pt, 'tax_archive' );
        }

        return true;
    }

    // ------------------------------------
    // Template variable resolver
    // ------------------------------------

    public static function resolve_template( string $template, $object = null ): string {
        $vars = [
            '{site_name}' => get_bloginfo( 'name' ),
            '{sep}'       => '|',
            '{year}'      => gmdate( 'Y' ),
        ];

        if ( $object instanceof WP_Post ) {
            $vars['{post_title}']   = $object->post_title;
            $vars['{post_excerpt}'] = wp_trim_words( $object->post_excerpt ?: wp_strip_all_tags( $object->post_content ), 25 );
            $vars['{author}']       = get_the_author_meta( 'display_name', $object->post_author );

            $cats = get_the_category( $object->ID );
            $vars['{category}']     = $cats ? $cats[0]->name : '';

        } elseif ( $object instanceof WP_Term ) {
            $vars['{post_title}']   = $object->name;
            $vars['{post_excerpt}'] = $object->description;
            $vars['{author}']       = '';
            $vars['{category}']     = $object->name;
        }

        /*
         * ⚠️ **Every context that has no object still has to resolve.**
         *
         * `{post_title}` and `{post_excerpt}` were only ever filled in for a WP_Post or a
         * WP_Term. Every other context AumViso itself detects — a post-type archive, the
         * blog page, search, date and author archives — passes null, so the template went
         * out **unsubstituted**: `<title>{post_title} | Fulcrum Movement Clinic</title>` in
         * the browser tab and `<meta name="description" content="{post_excerpt}">` in the
         * head. It raises nothing, and it is on the shipped default templates, so it
         * happened on every site.
         * (Found 2026-09-03 on `/services/`, `/guides/`, `/glossary/`, `/faqs/` and
         * `/journal/` of a booking template.)
         */
        if ( ! isset( $vars['{post_title}'] ) ) {
            $vars['{post_title}']   = self::context_title();
            $vars['{post_excerpt}'] = self::context_excerpt();
            $vars['{author}']       = '';
            $vars['{category}']     = '';
        }

        $out = str_replace( array_keys( $vars ), array_values( $vars ), $template );

        /*
         * The last line of defence. Whatever happens above, **a raw `{placeholder}` must
         * never reach the page** — an author's custom template can contain a variable this
         * version does not know, and printing it verbatim is worse than printing nothing.
         * The separator tidy-up is what stops "Services | " and " | Fulcrum" from the
         * removal.
         */
        $out = (string) preg_replace( '/\{[a-z_]+\}/', '', $out );
        $out = (string) preg_replace( '/\s+/', ' ', $out );
        $out = (string) preg_replace( '/(?:\s*\|\s*){2,}/', ' | ', $out );

        return trim( $out, " \t\n|-\xe2\x80\x93\xe2\x80\x94\xc2\xb7" );
    }

    /**
     * `{post_title}` for a context that has no post and no term.
     *
     * Each branch mirrors one of `AumViso_Meta_Manager::detect_context()`'s object-less
     * types, so adding a context there without adding one here brings the raw placeholder
     * straight back.
     */
    private static function context_title(): string {
        if ( is_post_type_archive() ) {
            return (string) post_type_archive_title( '', false );
        }
        if ( is_home() ) {
            $id = (int) get_option( 'page_for_posts' );
            return $id ? (string) get_the_title( $id ) : (string) get_bloginfo( 'name' );
        }
        if ( is_search() ) {
            /* translators: %s: the search term. */
            return sprintf( __( 'Search results for “%s”', 'aumviso' ), get_search_query() );
        }
        if ( is_author() ) {
            $user = get_queried_object();
            return $user instanceof WP_User ? (string) $user->display_name : '';
        }
        if ( is_date() ) {
            return (string) wp_strip_all_tags( get_the_archive_title() );
        }
        if ( is_front_page() ) {
            return (string) get_bloginfo( 'name' );
        }
        return '';
    }

    /** `{post_excerpt}` for the same object-less contexts. Empty is a valid answer. */
    private static function context_excerpt(): string {
        if ( is_post_type_archive() ) {
            $obj = get_queried_object();
            return $obj instanceof WP_Post_Type ? (string) $obj->description : '';
        }
        if ( is_home() ) {
            $id   = (int) get_option( 'page_for_posts' );
            $post = $id ? get_post( $id ) : null;
            return $post
                ? wp_trim_words( $post->post_excerpt ?: wp_strip_all_tags( $post->post_content ), 25 )
                : (string) get_bloginfo( 'description' );
        }
        if ( is_author() ) {
            return (string) get_the_author_meta( 'description', (int) get_queried_object_id() );
        }
        if ( is_front_page() ) {
            return (string) get_bloginfo( 'description' );
        }
        return '';
    }

    // ------------------------------------
    // Shorthand getters
    // ------------------------------------

    public static function title_template(): string {
        return (string) self::get( 'aumviso_title_template', '{post_title} | {site_name}' );
    }

    public static function desc_template(): string {
        return (string) self::get( 'aumviso_desc_template', '{post_excerpt}' );
    }

    public static function og_fallback_image(): string {
        return (string) self::get( 'aumviso_og_image_fallback', '' );
    }

    public static function schema_org(): array {
        return [
            'name'  => self::get( 'aumviso_schema_org_name', get_bloginfo( 'name' ) ),
            'url'   => self::get( 'aumviso_schema_org_url',  home_url() ),
            'logo'  => self::get( 'aumviso_schema_org_logo', '' ),
            'type'  => self::get( 'aumviso_schema_org_type', 'Organization' ),
        ];
    }

    public static function ai_config(): array {
        return [
            'provider' => self::get( 'aumviso_ai_provider', 'openai' ),
            'key'      => self::get( 'aumviso_ai_key', '' ),
            'model'    => self::get( 'aumviso_ai_model', 'gpt-4o-mini' ),
        ];
    }
}