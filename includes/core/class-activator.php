<?php
defined( 'ABSPATH' ) || exit;

class AumViso_Activator {

    /**
     * Bumped whenever stored data needs rewriting. See maybe_migrate().
     */
    const DATA_VERSION = 2;

    public static function activate(): void {
        self::create_tables();
        self::set_default_options();

        // A brand-new install already stores the current names, so record the
        // data version up front and the migration never runs on it.
        if ( false === get_option( 'aumviso_data_version' ) ) {
            update_option( 'aumviso_data_version', self::DATA_VERSION );
        }

        /*
         * ⚠️ **A flush here would do nothing**, which is why this sets a flag instead.
         *
         * Activation callbacks run on `admin_init`, and in that request the plugin's own `init` hooks never
         * fired — `activate_plugin()` loads the file on its own, outside the normal lifecycle. So the rules
         * this plugin adds (`^sitemap\.xml$` and friends) are **not registered yet** at this moment, and
         * flushing writes a rule set without them.
         *
         * Observed 2026-09-03 on a fresh activation: `/sitemap.xml` returned 404 while
         * `?aumviso_sitemap=index` returned the sitemap perfectly well — the handler was fine, the URL was
         * not. Every buyer installing by hand would hit that, and "the sitemap 404s" is a support ticket
         * that looks like a broken feature.
         */
        update_option( 'aumviso_needs_flush', 1 );
    }

    /**
     * Flush once, on the first `init` that has the rules in it.
     *
     * Late priority so every rewrite rule this plugin adds is registered before the flush runs.
     */
    public static function maybe_flush(): void {
        if ( ! get_option( 'aumviso_needs_flush' ) ) {
            return;
        }
        delete_option( 'aumviso_needs_flush' );
        flush_rewrite_rules();
    }

    /**
     * One-time rename of stored data to fully prefixed names.
     *
     * Post types, the FAQ taxonomy, meta keys and shortcodes all used to carry
     * a three-letter "aum" prefix, or in the case of meta keys no prefix at
     * all -- `_seo_title` and `_og_image` are exactly the sort of key another
     * SEO plugin would also claim, which would silently mix two plugins' data
     * together.
     *
     * Public URLs are unaffected: the post types register their own rewrite
     * slugs ('faq', 'glossary', 'guide'), which are independent of the
     * post_type key being renamed here.
     *
     * Safe to run on a site that never held the old names -- every statement
     * simply matches zero rows -- and guarded so it runs at most once.
     *
     * @return void
     */
    public static function maybe_migrate(): void {
        if ( (int) get_option( 'aumviso_data_version', 0 ) >= self::DATA_VERSION ) {
            return;
        }

        global $wpdb;

        $post_types = array(
            'aum_faq'      => 'aumviso_faq',
            'aum_glossary' => 'aumviso_glossary',
            'aum_guide'    => 'aumviso_guide',
        );

        foreach ( $post_types as $from => $to ) {
            $wpdb->update( $wpdb->posts, array( 'post_type' => $to ), array( 'post_type' => $from ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time data migration; no caching layer applies.
        }

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time data migration.
            $wpdb->term_taxonomy,
            array( 'taxonomy' => 'aumviso_faq_category' ),
            array( 'taxonomy' => 'aum_faq_category' )
        );

        $meta_keys = array(
            '_seo_title', '_seo_description', '_seo_canonical', '_seo_index', '_seo_follow',
            '_og_title', '_og_description', '_og_image', '_schema_type',
            '_faq_answer', '_faq_related_questions', '_faq_related_content',
            '_glossary_short_def', '_glossary_full_explanation', '_glossary_related_terms',
            '_guide_steps', '_guide_tools', '_guide_time', '_guide_materials', '_guide_difficulty',
            '_inline_faqs', '_linked_faq_ids',
        );

        foreach ( $meta_keys as $key ) {
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time data migration.
                $wpdb->postmeta,
                array( 'meta_key' => '_aumviso' . $key ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                array( 'meta_key' => $key )               // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            );
        }

        // Shortcodes already written into post content.
        $shortcodes = array( 'faq', 'glossary', 'guide', 'related', 'breadcrumb' );

        foreach ( $shortcodes as $tag ) {
            foreach ( array( '[aum_' => '[aumviso_', '[/aum_' => '[/aumviso_' ) as $from => $to ) {
                $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is not user input; values are prepared below.
                    $wpdb->prepare(
                        "UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, %s, %s ) WHERE post_content LIKE %s",
                        $from . $tag,
                        $to . $tag,
                        '%' . $wpdb->esc_like( $from . $tag ) . '%'
                    )
                );
            }
        }

        update_option( 'aumviso_data_version', self::DATA_VERSION );
        flush_rewrite_rules();
    }

    /**
     * Check whether the database table exists when plugins_loaded fires.
     * If the table does not exist, create it automatically.
     * This solves cases where the plugin is already activated but the table
     * was not created (for example after upgrades or environment migrations).
     */
    public static function maybe_create_tables(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'aumviso_link_keywords';

        // Only run when the table does not exist to avoid unnecessary queries on every request
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            self::create_tables();
        }
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    private static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Internal link keyword mapping table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}aumviso_link_keywords (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            keyword varchar(255) NOT NULL,
            target_url varchar(2083) NOT NULL,
            post_id bigint(20) DEFAULT NULL,
            max_links int(3) DEFAULT 1,
            case_sensitive tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY keyword (keyword(100))
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'aumviso_db_version', AUMVISO_VERSION );
    }

    private static function set_default_options(): void {
        $defaults = [
            // Global templates
            'aumviso_title_template'       => '{post_title} | {site_name}',
            'aumviso_desc_template'        => '{post_excerpt}',
            'aumviso_og_image_fallback'    => '',

            // Enabled post types
            'aumviso_enabled_post_types'   => [ 'post', 'page' ],
            'aumviso_enabled_taxonomies'   => [ 'category', 'post_tag' ],

            // Sitemap settings
            'aumviso_sitemap_enabled'      => true,
            'aumviso_sitemap_post_types'   => [ 'post', 'page', 'aumviso_faq', 'aumviso_glossary', 'aumviso_guide' ],
            'aumviso_sitemap_taxonomies'   => [ 'category', 'post_tag' ],
            'aumviso_sitemap_images'       => true,

            // Schema settings
            'aumviso_schema_org_name'      => get_bloginfo( 'name' ),
            'aumviso_schema_org_url'       => home_url(),
            'aumviso_schema_org_logo'      => '',
            'aumviso_schema_org_type'      => 'Organization',

            // Social profiles
            'aumviso_social_twitter'       => '',
            'aumviso_social_facebook'      => '',

            // AI settings
            // Prefer the core AI Client on new installs; the site owner can still
            // pick a direct provider, which is what users on networks that cannot
            // reach the official connectors need.
            'aumviso_ai_provider'          => 'core',
            'aumviso_ai_key'               => '',
            'aumviso_ai_model'             => 'gpt-4o-mini',

            // Internal linking
            'aumviso_ilinks_enabled'       => true,
            'aumviso_ilinks_post_types'    => [ 'post', 'page' ],
        ];

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                update_option( $key, $value );
            }
        }
    }

}