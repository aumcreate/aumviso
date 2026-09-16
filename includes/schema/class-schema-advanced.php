<?php
defined( 'ABSPATH' ) || exit;

/**
 * LocalBusiness Schema
 * Outputs extended fields when Organization Type is set to LocalBusiness in Schema settings.
 */
class AumViso_Schema_LocalBusiness {

    private static ?AumViso_Schema_LocalBusiness $instance = null;

    public static function instance(): AumViso_Schema_LocalBusiness {
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

        // Only output if type is LocalBusiness or a subtype
        $lb_types = [
            'LocalBusiness', 'Restaurant', 'Store', 'Hotel',
            'MedicalBusiness', 'LegalService', 'FinancialService',
            'AutomotiveBusiness', 'SportsActivityLocation',
        ];
        if ( ! in_array( $org['type'], $lb_types, true ) ) return;

        // Only on homepage / front page
        if ( ! is_front_page() && ! is_home() ) return;

        $address     = AumViso_Options::get( 'aumviso_lb_address', [] );
        $phone       = AumViso_Options::get( 'aumviso_lb_phone', '' );
        $email       = AumViso_Options::get( 'aumviso_lb_email', '' );
        $price_range = AumViso_Options::get( 'aumviso_lb_price_range', '' );
        $geo_lat     = AumViso_Options::get( 'aumviso_lb_geo_lat', '' );
        $geo_lng     = AumViso_Options::get( 'aumviso_lb_geo_lng', '' );

        $schema = [
            '@type'       => $org['type'],
            '@id'         => $org['url'] . '#localbusiness',
            'name'        => $org['name'],
            'url'         => $org['url'],
            'description' => get_bloginfo( 'description' ),
        ];

        if ( $org['logo'] ) {
            $schema['image'] = $org['logo'];
        }
        if ( $phone ) $schema['telephone'] = $phone;
        if ( $email ) $schema['email']     = $email;
        if ( $price_range ) $schema['priceRange'] = $price_range;

        // Address
        if ( ! empty( $address['street'] ) ) {
            $schema['address'] = [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $address['street']   ?? '',
                'addressLocality' => $address['city']     ?? '',
                'addressRegion'   => $address['region']   ?? '',
                'postalCode'      => $address['postcode'] ?? '',
                'addressCountry'  => $address['country']  ?? '',
            ];
        }

        // Geo coordinates
        if ( $geo_lat && $geo_lng ) {
            $schema['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $geo_lat,
                'longitude' => (float) $geo_lng,
            ];
        }

        // Social / sameAs
        $social = [];
        $fb     = AumViso_Options::get( 'aumviso_social_facebook' );
        $tw     = AumViso_Options::get( 'aumviso_social_twitter' );
        if ( $fb ) $social[] = 'https://facebook.com/' . ltrim( $fb, '@/' );
        if ( $tw ) $social[] = 'https://twitter.com/' . ltrim( $tw, '@' );
        if ( $social ) $schema['sameAs'] = $social;

        $engine->register( $schema );
    }
}

/**
 * VideoObject Schema
 * Auto-detects embedded YouTube/Vimeo in post content, or reads manually set video meta.
 */
class AumViso_Schema_VideoObject {

    private static ?AumViso_Schema_VideoObject $instance = null;

    public static function instance(): AumViso_Schema_VideoObject {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'aumviso_collect_schemas', [ $this, 'collect' ] );
    }

    public function collect( AumViso_SchemaEngine $engine ): void {
        if ( ! is_singular() ) return;

        $post_id = get_queried_object_id();
        $post    = get_post( $post_id );

        // 1. Manual video meta
        $video_url       = get_post_meta( $post_id, '_aumviso_video_url', true );
        $video_name      = get_post_meta( $post_id, '_aumviso_video_name', true ) ?: get_the_title( $post_id );
        $video_desc      = get_post_meta( $post_id, '_aumviso_video_desc', true )
                        ?: wp_trim_words( get_the_excerpt( $post_id ), 25 );
        $video_thumb     = get_post_meta( $post_id, '_aumviso_video_thumb', true );
        $video_duration  = get_post_meta( $post_id, '_aumviso_video_duration', true ); // ISO 8601 e.g. PT5M30S
        $video_upload    = get_post_meta( $post_id, '_aumviso_video_upload_date', true )
                        ?: get_the_date( 'c', $post_id );

        if ( ! $video_url ) {
            // 2. Auto-detect YouTube/Vimeo in post content
            $video_url = $this->detect_video_url( $post->post_content );
            if ( ! $video_url ) return;
        }

        $schema = [
            '@type'           => 'VideoObject',
            'name'            => $video_name,
            'description'     => $video_desc,
            'contentUrl'      => $video_url,
            'embedUrl'        => $this->to_embed_url( $video_url ),
            'uploadDate'      => $video_upload,
            'url'             => get_permalink( $post_id ),
        ];

        if ( $video_thumb ) {
            $schema['thumbnailUrl'] = $video_thumb;
        } elseif ( $thumb_id = get_post_thumbnail_id( $post_id ) ) {
            $src = wp_get_attachment_image_url( $thumb_id, 'large' );
            if ( $src ) $schema['thumbnailUrl'] = $src;
        }

        if ( $video_duration ) $schema['duration'] = $video_duration;

        $engine->register( $schema );
    }

    private function detect_video_url( string $content ): string {
        // YouTube
        if ( preg_match( '/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $content, $m ) ) {
            return 'https://www.youtube.com/watch?v=' . $m[1];
        }
        // Vimeo
        if ( preg_match( '/vimeo\.com\/(\d+)/', $content, $m ) ) {
            return 'https://vimeo.com/' . $m[1];
        }
        return '';
    }

    private function to_embed_url( string $url ): string {
        if ( preg_match( '/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m ) ) {
            return 'https://www.youtube.com/embed/' . $m[1];
        }
        if ( preg_match( '/vimeo\.com\/(\d+)/', $url, $m ) ) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }
        return $url;
    }
}

/**
 * Person Schema for author archive pages.
 */
class AumViso_Schema_Person {

    private static ?AumViso_Schema_Person $instance = null;

    public static function instance(): AumViso_Schema_Person {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'aumviso_collect_schemas', [ $this, 'collect' ] );
    }

    public function collect( AumViso_SchemaEngine $engine ): void {
        if ( ! is_author() ) return;

        $user = get_queried_object();
        if ( ! $user instanceof WP_User ) return;

        $schema = [
            '@type'       => 'Person',
            '@id'         => get_author_posts_url( $user->ID ) . '#person',
            'name'        => $user->display_name,
            'url'         => get_author_posts_url( $user->ID ),
            'description' => get_the_author_meta( 'description', $user->ID ),
        ];

        // Avatar
        $avatar_url = get_avatar_url( $user->ID, [ 'size' => 200 ] );
        if ( $avatar_url ) $schema['image'] = $avatar_url;

        // Social links (stored in user meta or plugin option)
        $same_as = [];
        $tw      = get_user_meta( $user->ID, 'twitter', true );
        $li      = get_user_meta( $user->ID, 'linkedin', true );
        $website = $user->user_url;

        if ( $tw )      $same_as[] = 'https://twitter.com/' . ltrim( $tw, '@' );
        if ( $li )      $same_as[] = $li;
        if ( $website ) $same_as[] = $website;

        if ( $same_as ) $schema['sameAs'] = $same_as;

        // Job title
        $job_title = get_user_meta( $user->ID, 'job_title', true );
        if ( $job_title ) $schema['jobTitle'] = $job_title;

        // worksFor → Organization
        $org = AumViso_Options::schema_org();
        $schema['worksFor'] = [ '@id' => $org['url'] . '#organization' ];

        $engine->register( $schema );
    }
}
