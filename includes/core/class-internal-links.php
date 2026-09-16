<?php
defined( 'ABSPATH' ) || exit;

class AumViso_InternalLinks {

    private static ?AumViso_InternalLinks $instance = null;

    public static function instance(): AumViso_InternalLinks {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( AumViso_Options::get( 'aumviso_ilinks_enabled', true ) ) {
            add_filter( 'the_content', [ $this, 'process_content' ], 20 );
        }
    }

    /**
     * Replace keywords in post content with internal links.
     */
    public function process_content( string $content ): string {
        if ( is_admin() ) return $content;
        if ( ! is_singular() ) return $content;

        $post = get_post();
        if ( ! $post ) return $content;

        $enabled_pts = (array) AumViso_Options::get( 'aumviso_ilinks_post_types', [] );
        if ( ! in_array( $post->post_type, $enabled_pts, true ) ) return $content;

        $keywords = $this->get_keywords();
        if ( empty( $keywords ) ) return $content;

        return $this->replace_keywords( $content, $keywords, $post->ID );
    }

    private function replace_keywords( string $content, array $keywords, int $current_post_id ): string {
        $parts           = preg_split( '/(<[^>]+>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
        $inside_link     = false;
        $inside_skip_tag = false;
        $skip_tags       = [ 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'code', 'pre', 'script', 'style' ];

        // The counter shared throughout the entire article, key = keyword id， Accumulate across text nodes
        $counts = [];

        foreach ( $parts as &$part ) {
            if ( preg_match( '/^<([a-z0-9]+)/i', $part, $m ) ) {
                $tag = strtolower( $m[1] );
                if ( in_array( $tag, $skip_tags, true ) ) $inside_skip_tag = true;
                if ( $tag === 'a' ) $inside_link = true;
                continue;
            }
            if ( preg_match( '/^<\/([a-z0-9]+)/i', $part, $m ) ) {
                $tag = strtolower( $m[1] );
                if ( in_array( $tag, $skip_tags, true ) ) $inside_skip_tag = false;
                if ( $tag === 'a' ) $inside_link = false;
                continue;
            }

            if ( ! $inside_link && ! $inside_skip_tag ) {
                $part = $this->apply_keywords( $part, $keywords, $current_post_id, $counts );
            }
        }

        return implode( '', $parts );
    }

    private function apply_keywords( string $text, array $keywords, int $current_post_id, array &$counts ): string {
        foreach ( $keywords as $kw ) {
            if ( $kw['post_id'] && (int) $kw['post_id'] === $current_post_id ) continue;

            $id  = $kw['id'];
            $max = max( 1, (int) $kw['max_links'] );
            if ( ( $counts[ $id ] ?? 0 ) >= $max ) continue; // Exceeded limit, skip

            $flags  = $kw['case_sensitive'] ? 'u' : 'iu';
            $kw_str = $kw['keyword'];
            if ( preg_match( '/[\x{4e00}-\x{9fff}]/u', $kw_str ) ) {
                $pattern = '/(' . preg_quote( $kw_str, '/' ) . ')/' . $flags;
            } else {
                $pattern = '/\b(' . preg_quote( $kw_str, '/' ) . ')\b/' . $flags;
            }

            $text = preg_replace_callback( $pattern, function( $matches ) use ( $kw, $max, $id, &$counts ) {
                if ( ( $counts[ $id ] ?? 0 ) >= $max ) return $matches[0];
                $counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
                return sprintf(
                    '<a href="%s" class="aum-ilink">%s</a>',
                    esc_url( $kw['target_url'] ),
                    esc_html( $matches[1] )
                );
            }, $text );
        }
        return $text;
    }

    // ------------------------------------
    // Data access
    // ------------------------------------

    private function get_keywords(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'aumviso_link_keywords';
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY LENGTH(keyword) DESC", ARRAY_A ) ?: [];
    }

    // ------------------------------------
    // CRUD (called from admin)
    // ------------------------------------

    public static function save_keyword( array $data ): int|false {
        global $wpdb;
        $table = $wpdb->prefix . 'aumviso_link_keywords';

        $row = [
            'keyword'        => sanitize_text_field( $data['keyword'] ),
            'target_url'     => esc_url_raw( $data['target_url'] ),
            'post_id'        => isset( $data['post_id'] ) ? (int) $data['post_id'] : null,
            'max_links'      => isset( $data['max_links'] ) ? (int) $data['max_links'] : 1,
            'case_sensitive' => ! empty( $data['case_sensitive'] ) ? 1 : 0,
        ];

        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( $table, $row, [ 'id' => (int) $data['id'] ] );
            return (int) $data['id'];
        }

        $wpdb->insert( $table, $row );
        return $wpdb->insert_id;
    }

    public static function delete_keyword( int $id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'aumviso_link_keywords', [ 'id' => $id ] );
    }

    public static function get_all_keywords(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}aumviso_link_keywords ORDER BY keyword",
            ARRAY_A
        ) ?: [];
    }
}
