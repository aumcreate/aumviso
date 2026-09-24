<?php

/**
 * Plugin Name: AumViso – llms.txt, Schema & Sitemaps for AI Search & SEO
 * Plugin URI: https://aumcreate.com/plugins/aumviso
 * Description: SEO and AI search in one: llms.txt, Schema, XML sitemaps, meta tags, internal links, FAQ/Glossary/Guide content types, and a log of which AI crawlers read your site.
 * Version:     2.0.12
 * Author:      AumCreate
 * Author URI:  https://aumcreate.com
 * Text Domain: aumviso
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

// Activator must be loaded at file level — before plugins_loaded fires
require_once plugin_dir_path(__FILE__) . 'includes/core/class-activator.php';

// Constants
define('AUMVISO_VERSION',   '2.0.12');
define('AUMVISO_FILE',      __FILE__);
define('AUMVISO_DIR',       plugin_dir_path(__FILE__));
define('AUMVISO_URL',       plugin_dir_url(__FILE__));
define('AUMVISO_BASENAME',  plugin_basename(__FILE__));

/**
 * Main plugin class — singleton.
 */
final class AumViso
{

    private static ?AumViso $instance = null;

    public static function instance(): AumViso
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies(): void
    {
        // Core
        require_once AUMVISO_DIR . 'includes/core/class-activator.php';
        require_once AUMVISO_DIR . 'includes/core/class-options.php';
        require_once AUMVISO_DIR . 'includes/core/class-meta-manager.php';
        require_once AUMVISO_DIR . 'includes/core/class-open-graph.php';
        require_once AUMVISO_DIR . 'includes/core/class-sitemap.php';
        require_once AUMVISO_DIR . 'includes/core/class-robots.php';
        require_once AUMVISO_DIR . 'includes/core/class-breadcrumb.php';
        require_once AUMVISO_DIR . 'includes/core/class-internal-links.php';
        require_once AUMVISO_DIR . 'includes/core/class-related-content.php';
        require_once AUMVISO_DIR . 'includes/core/class-seo-score.php';
        require_once AUMVISO_DIR . 'includes/core/class-crawl-moved.php';

        // Product Sources (unified registry — feeds Product schema, GEO console, llms.txt, AI factory)
        require_once AUMVISO_DIR . 'includes/sources/interface-product-source.php';
        require_once AUMVISO_DIR . 'includes/sources/class-product-registry.php';

        // Schema
        require_once AUMVISO_DIR . 'includes/schema/class-schema-engine.php';
        require_once AUMVISO_DIR . 'includes/schema/class-schema-product.php';
        require_once AUMVISO_DIR . 'includes/schema/class-schema-advanced.php';
        require_once AUMVISO_DIR . 'includes/schema/class-schema-organization.php';
        require_once AUMVISO_DIR . 'includes/schema/class-schema-article.php';
        require_once AUMVISO_DIR . 'includes/schema/class-schema-faq.php';
        require_once AUMVISO_DIR . 'includes/schema/class-schema-howto.php';
        require_once AUMVISO_DIR . 'includes/schema/class-schema-breadcrumb.php';
        require_once AUMVISO_DIR . 'includes/schema/class-schema-defined-term.php';

        // GEO Content Types
        require_once AUMVISO_DIR . 'includes/geo/class-cpt-faq.php';
        require_once AUMVISO_DIR . 'includes/geo/class-cpt-glossary.php';
        require_once AUMVISO_DIR . 'includes/geo/class-cpt-guide.php';
        require_once AUMVISO_DIR . 'includes/geo/class-related-questions.php';

        // AI Tools
        require_once AUMVISO_DIR . 'includes/ai/class-ai-connector.php';
        require_once AUMVISO_DIR . 'includes/ai/class-ai-generator.php';

        // Advanced modules: GEO console, knowledge builder, automation,
        // images, brand entity, llms.txt. Loaded unconditionally because the
        // cron scheduler reaches into several of them outside of admin.
        require_once AUMVISO_DIR . 'includes/adv/class-setup.php';
        require_once AUMVISO_DIR . 'includes/adv/class-overview.php';
        require_once AUMVISO_DIR . 'includes/adv/class-brand.php';
        require_once AUMVISO_DIR . 'includes/adv/class-factory.php';
        require_once AUMVISO_DIR . 'includes/adv/class-batch.php';
        require_once AUMVISO_DIR . 'includes/adv/class-auto.php';
        require_once AUMVISO_DIR . 'includes/adv/class-images.php';
        require_once AUMVISO_DIR . 'includes/adv/class-llms.php';
        require_once AUMVISO_DIR . 'includes/adv/class-console.php';

        // Admin
        if (is_admin()) {
            require_once AUMVISO_DIR . 'admin/class-admin.php';
            require_once AUMVISO_DIR . 'admin/class-meta-box.php';
            require_once AUMVISO_DIR . 'admin/class-term-meta.php';
            require_once AUMVISO_DIR . 'admin/class-dashboard-widget.php';
            require_once AUMVISO_DIR . 'admin/class-settings-page.php';
            require_once AUMVISO_DIR . 'admin/class-ajax-handler.php';

            AumViso_Crawl_Moved::register();
        }
    }

    private function init_hooks(): void
    {
        add_action('plugins_loaded', [$this, 'maybe_create_tables'], 1);
        add_action('plugins_loaded', [$this, 'maybe_migrate'], 2);
        add_action('plugins_loaded', [$this, 'init_components'], 5);
    }

    public function maybe_create_tables(): void
    {
        AumViso_Activator::maybe_create_tables();
    }

    public function maybe_migrate(): void
    {
        AumViso_Activator::maybe_migrate();
    }

    public function init_components(): void
    {
        AumViso_Options::instance();
        AumViso_Product_Registry::instance();
        AumViso_MetaManager::instance();
        AumViso_OpenGraph::instance();
        AumViso_Sitemap::instance();
        AumViso_Robots::instance();
        AumViso_SchemaEngine::instance();
        AumViso_Breadcrumb::instance();
        AumViso_InternalLinks::instance();
        AumViso_RelatedContent::instance();

        // GEO CPTs
        AumViso_CPT_FAQ::instance();
        AumViso_CPT_Glossary::instance();
        AumViso_CPT_Guide::instance();
        AumViso_RelatedQuestions::instance();
        AumViso_Glossary_LinkSync::instance();
        AumViso_Schema_Product::instance();
        AumViso_Schema_Review::instance();
        AumViso_Schema_LocalBusiness::instance();
        AumViso_Schema_VideoObject::instance();
        AumViso_Schema_Person::instance();
        AumViso_SEOScore::instance();

        // Admin
        if (is_admin()) {
            AumViso_Admin::instance();
            AumViso_MetaBox::instance();
            AumViso_TermMeta::instance();
            AumViso_DashboardWidget::instance();
            AumViso_SettingsPage::instance();
            AumViso_AjaxHandler::instance();
        }

        // Advanced modules
        AumViso_Adv_Brand::instance();
        AumViso_Adv_LLMS::instance();
        AumViso_Adv_Images::instance();
        AumViso_Adv_Auto::instance();
        if (is_admin()) {
            AumViso_Adv_Console::instance();
        }

        // Frontend assets
        add_action('wp_enqueue_scripts', function () {
            wp_enqueue_style(
                'aumviso-public',
                AUMVISO_URL . 'public/assets/css/public.css',
                [],
                AUMVISO_VERSION
            );
            wp_enqueue_script(
                'aumviso-public',
                AUMVISO_URL . 'public/assets/js/public.js',
                [],
                AUMVISO_VERSION,
                true
            );
        });
    }
}

// Boot
function aumviso_plugin(): AumViso
{
    return AumViso::instance();
}
add_action('plugins_loaded', 'aumviso_plugin', 0);

// Activation / Deactivation hooks
register_activation_hook(__FILE__, ['AumViso_Activator', 'activate']);

/* The activation flush is deferred to here — see AumViso_Activator::activate(). */
add_action('init', ['AumViso_Activator', 'maybe_flush'], 99);
register_deactivation_hook(__FILE__, ['AumViso_Activator', 'deactivate']);
register_deactivation_hook(__FILE__, function () {
    // Leave no orphaned scheduled event behind.
    wp_clear_scheduled_hook('aumviso_adv_auto_tick');
});
