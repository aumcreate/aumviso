<?php
defined( 'ABSPATH' ) || exit;

class AumViso_Schema_Article {

    private static ?AumViso_Schema_Article $instance = null;

    public static function instance(): AumViso_Schema_Article {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'aumviso_collect_schemas', [ $this, 'collect' ] );
    }

    public function collect( AumViso_SchemaEngine $engine ): void {
        if ( ! is_singular( [ 'post', 'page' ] ) ) return;

        $post    = get_queried_object();
        $post_id = $post->ID;

        // Determine schema type
        $schema_type = get_post_meta( $post_id, '_aumviso_schema_type', true );
        if ( ! $schema_type ) {
            $schema_type = $post->post_type === 'post' ? 'BlogPosting' : 'WebPage';
        }

        $seo   = AumViso_MetaManager::get_post_seo( $post_id );
        $org   = AumViso_Options::schema_org();

        $schema = [
            '@type'            => $schema_type,
            '@id'              => get_permalink( $post_id ) . '#article',
            'headline'         => $seo['title'],
            'description'      => $seo['desc'],
            'url'              => get_permalink( $post_id ),
            'datePublished'    => get_the_date( 'c', $post_id ),
            'dateModified'     => get_the_modified_date( 'c', $post_id ),
            'isPartOf'         => [ '@id' => $org['url'] . '#website' ],
            'publisher'        => [ '@id' => $org['url'] . '#organization' ],
            'author'           => $this->build_author( $post->post_author ),
        ];

        // Featured image
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            $src = wp_get_attachment_image_url( $thumb_id, 'large' );
            $meta = wp_get_attachment_metadata( $thumb_id );
            if ( $src ) {
                $schema['image'] = [
                    '@type'   => 'ImageObject',
                    'url'     => $src,
                    'width'   => $meta['width']  ?? null,
                    'height'  => $meta['height'] ?? null,
                ];
            }
        }

        // Breadcrumb ref
        $schema['breadcrumb'] = [ '@id' => get_permalink( $post_id ) . '#breadcrumb' ];

        // Word count & reading time
        $word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );
        if ( $word_count > 0 ) {
            $schema['wordCount']        = $word_count;
            $schema['timeRequired']     = 'PT' . max( 1, round( $word_count / 200 ) ) . 'M';
        }

        $engine->register( $schema );
    }

    private function build_author( int $user_id ): array {
        $user = get_userdata( $user_id );
        return [
            '@type' => 'Person',
            'name'  => $user ? $user->display_name : '',
            'url'   => get_author_posts_url( $user_id ),
        ];
    }
}
