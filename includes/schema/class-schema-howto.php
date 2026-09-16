<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   HowTo Schema
   ============================================================ */
class AumViso_Schema_HowTo {

    private static ?AumViso_Schema_HowTo $instance = null;

    public static function instance(): AumViso_Schema_HowTo {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'aumviso_collect_schemas', [ $this, 'collect' ] );
    }

    public function collect( AumViso_SchemaEngine $engine ): void {
        if ( ! is_singular( 'aumviso_guide' ) ) return;

        $post_id = get_queried_object_id();
        $steps   = get_post_meta( $post_id, '_aumviso_guide_steps', true );
        if ( ! is_array( $steps ) || empty( $steps ) ) return;

        $schema = [
            '@type'       => 'HowTo',
            'name'        => get_the_title( $post_id ),
            'description' => get_post_meta( $post_id, '_aumviso_seo_description', true ) ?: wp_trim_words( get_the_excerpt( $post_id ), 25 ),
            'step'        => [],
        ];

        // Optional fields
        $tools     = get_post_meta( $post_id, '_aumviso_guide_tools', true );
        $materials = get_post_meta( $post_id, '_aumviso_guide_materials', true );
        $time      = get_post_meta( $post_id, '_aumviso_guide_time', true );
        $diff      = get_post_meta( $post_id, '_aumviso_guide_difficulty', true );
        if ( $time ) $schema['totalTime'] = 'PT' . (int) $time . 'M';

        if ( is_array( $tools ) && $tools ) {
            $schema['tool'] = array_map( fn( $t ) => [ '@type' => 'HowToTool', 'name' => $t ], $tools );
        }
        if ( is_array( $materials ) && $materials ) {
            $schema['supply'] = array_map( fn( $m ) => [ '@type' => 'HowToSupply', 'name' => $m ], $materials );
        }
        if ( $diff ) {
            $schema['difficulty'] = $diff;
        }

        foreach ( $steps as $i => $step ) {
            $schema['step'][] = [
                '@type'    => 'HowToStep',
                'position' => $i + 1,
                'name'     => $step['name'] ?? '',
                'text'     => $step['text'] ?? '',
                'url'      => get_permalink( $post_id ) . '#step-' . ( $i + 1 ),
            ];
        }

        $engine->register( $schema );
    }
}

/* ============================================================
   BreadcrumbList Schema
   ============================================================ */
class AumViso_Schema_Breadcrumb {

    private static ?AumViso_Schema_Breadcrumb $instance = null;

    public static function instance(): AumViso_Schema_Breadcrumb {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'aumviso_collect_schemas', [ $this, 'collect' ] );
    }

    public function collect( AumViso_SchemaEngine $engine ): void {
        $crumbs  = AumViso_Breadcrumb::instance()->build_crumbs();
        if ( empty( $crumbs ) ) return;

        $items = [];
        $url   = is_singular() ? get_permalink() : home_url();

        foreach ( $crumbs as $i => $crumb ) {
            $item = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $crumb['label'],
            ];
            if ( $crumb['url'] ) {
                $item['item'] = $crumb['url'];
            }
            $items[] = $item;
        }

        $engine->register( [
            '@type'           => 'BreadcrumbList',
            '@id'             => $url . '#breadcrumb',
            'itemListElement' => $items,
        ] );
    }
}

/* ============================================================
   DefinedTerm Schema (Glossary)
   ============================================================ */
class AumViso_Schema_DefinedTerm {

    private static ?AumViso_Schema_DefinedTerm $instance = null;

    public static function instance(): AumViso_Schema_DefinedTerm {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'aumviso_collect_schemas', [ $this, 'collect' ] );
    }

    public function collect( AumViso_SchemaEngine $engine ): void {
        if ( ! is_singular( 'aumviso_glossary' ) ) return;

        $post_id    = get_queried_object_id();
        $short_def  = get_post_meta( $post_id, '_aumviso_glossary_short_def', true );
        $full_exp   = get_post_meta( $post_id, '_aumviso_glossary_full_explanation', true );

        $schema = [
            '@type'           => 'DefinedTerm',
            'name'            => get_the_title( $post_id ),
            'description'     => $short_def ?: wp_trim_words( get_the_excerpt( $post_id ), 25 ),
            'url'             => get_permalink( $post_id ),
            'inDefinedTermSet' => [
                '@type' => 'DefinedTermSet',
                'name'  => AumViso_Options::get( 'aumviso_schema_org_name', get_bloginfo( 'name' ) ) . ' Glossary',
                'url'   => get_post_type_archive_link( 'aumviso_glossary' ),
            ],
        ];

        if ( $full_exp ) {
            $schema['disambiguatingDescription'] = wp_strip_all_tags( $full_exp );
        }

        // Related terms
        $related_ids = get_post_meta( $post_id, '_aumviso_glossary_related_terms', true );
        if ( is_array( $related_ids ) && ! empty( $related_ids ) ) {
            $schema['seeAlso'] = array_map( 'get_permalink', $related_ids );
        }

        $engine->register( $schema );
    }
}
