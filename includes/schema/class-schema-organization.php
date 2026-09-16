<?php
defined( 'ABSPATH' ) || exit;

class AumViso_Schema_Organization {

    private static ?AumViso_Schema_Organization $instance = null;

    public static function instance(): AumViso_Schema_Organization {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'aumviso_collect_schemas', [ $this, 'collect' ] );
    }

    public function collect( AumViso_SchemaEngine $engine ): void {
        $org = AumViso_Options::schema_org();

        // Organization / LocalBusiness
        $schema = [
            '@type' => $org['type'],
            '@id'   => $org['url'] . '#organization',
            'name'  => $org['name'],
            'url'   => $org['url'],
        ];

        if ( $org['logo'] ) {
            $schema['logo'] = [
                '@type'      => 'ImageObject',
                'url'        => $org['logo'],
                'contentUrl' => $org['logo'],
            ];
        }

        // Social profiles
        $social = [];
        $fb     = AumViso_Options::get( 'aumviso_social_facebook' );
        $tw     = AumViso_Options::get( 'aumviso_social_twitter' );
        if ( $fb ) $social[] = 'https://facebook.com/' . ltrim( $fb, '@/' );
        if ( $tw ) $social[] = 'https://twitter.com/' . ltrim( $tw, '@' );
        if ( $social ) $schema['sameAs'] = $social;

        /**
         * Filters the Organization/LocalBusiness schema before output.
         *
         * Extension point (Module 0): the Brand Entity module augments this
         * with description, foundingDate, founder, richer sameAs, contactPoint,
         * address, awards and other E-E-A-T entity signals.
         *
         * @param array $schema The Organization schema array.
         */
        $schema = apply_filters( 'aumviso_organization_schema', $schema );

        $engine->register( $schema );

        // WebSite schema (for sitelinks searchbox)
        $engine->register( [
            '@type'           => 'WebSite',
            '@id'             => $org['url'] . '#website',
            'url'             => $org['url'],
            'name'            => $org['name'],
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => $org['url'] . '/?s={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ] );
    }
}
