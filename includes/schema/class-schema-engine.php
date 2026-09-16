<?php
defined( 'ABSPATH' ) || exit;

/**
 * Schema Engine: collects schemas from sub-classes, outputs JSON-LD in <head>.
 */
class AumViso_SchemaEngine {

    private static ?AumViso_SchemaEngine $instance = null;
    private array $schemas = [];

    public static function instance(): AumViso_SchemaEngine {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_head', [ $this, 'output' ], 5 );

        // Init sub-schemas
        AumViso_Schema_Organization::instance();
        AumViso_Schema_Article::instance();
        AumViso_Schema_FAQ::instance();
        AumViso_Schema_HowTo::instance();
        AumViso_Schema_Breadcrumb::instance();
        AumViso_Schema_DefinedTerm::instance();
    }

    /**
     * Called by sub-classes to register a schema.
     */
    public function register( array $schema ): void {
        $this->schemas[] = $schema;
    }

    /**
     * Output all registered schemas as JSON-LD.
     */
    public function output(): void {
        // Reuse MetaManager's context judgment, inactive pages do not output schema
        if ( ! AumViso_MetaManager::detect_context() ) return;

        // Let sub-classes hook and register their schemas
        do_action( 'aumviso_collect_schemas', $this );

        if ( empty( $this->schemas ) ) return;

        $graph = [
            '@context' => 'https://schema.org',
            '@graph'   => $this->schemas,
        ];

        // JSON-LD is structured data, not executable JavaScript: search engines
        // only read it when it sits inline in the document, so it cannot be
        // enqueued from a file. wp_print_inline_script_tag() is core's own
        // helper for emitting an inline script tag, and it applies the
        // wp_inline_script_attributes filters along the way.
        $json = wp_json_encode(
            $graph,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        if ( false === $json ) {
            return;
        }

        echo "\n<!-- AumViso: Schema JSON-LD -->\n";
        wp_print_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
    }

    /**
     * Global schema engine accessor.
     */
    public static function get(): AumViso_SchemaEngine {
        return self::instance();
    }
}
