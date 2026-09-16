<?php
defined( 'ABSPATH' ) || exit;

class AumViso_Breadcrumb {

    private static ?AumViso_Breadcrumb $instance = null;

    public static function instance(): AumViso_Breadcrumb {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // No hooks needed — called by shortcode or template function.
        add_shortcode( 'aumviso_breadcrumb', [ $this, 'shortcode' ] );
    }

    public function shortcode( array $atts ): string {
        return $this->render();
    }

    public function render(): string {
        $crumbs = $this->build_crumbs();
        if ( empty( $crumbs ) ) return '';

        $html = '<nav class="aum-breadcrumb" aria-label="' . esc_attr__( 'Breadcrumb', 'aumviso' ) . '">';
        $html .= '<ol itemscope itemtype="https://schema.org/BreadcrumbList">';

        foreach ( $crumbs as $i => $crumb ) {
            $pos      = $i + 1;
            $is_last  = $i === count( $crumbs ) - 1;
            $html    .= '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';

            if ( ! $is_last && $crumb['url'] ) {
                $html .= '<a itemprop="item" href="' . esc_url( $crumb['url'] ) . '">';
                $html .= '<span itemprop="name">' . esc_html( $crumb['label'] ) . '</span>';
                $html .= '</a>';
            } else {
                $html .= '<span itemprop="name">' . esc_html( $crumb['label'] ) . '</span>';
            }

            $html .= '<meta itemprop="position" content="' . $pos . '">';
            $html .= '</li>';

            if ( ! $is_last ) {
                $html .= '<li class="aum-breadcrumb__sep" aria-hidden="true">›</li>';
            }
        }

        $html .= '</ol></nav>';
        return $html;
    }

    /**
     * Build crumb array — also used by Schema engine.
     * Returns [ ['label' => string, 'url' => string|null], ... ]
     */
    public function build_crumbs(): array {
        $crumbs = [];

        // Home
        $crumbs[] = [ 'label' => __( 'Home', 'aumviso' ), 'url' => home_url( '/' ) ];

        if ( is_singular() ) {
            $post      = get_queried_object();
            $post_type = $post->post_type;

            // Post type archive link (if exists)
            $pt_obj = get_post_type_object( $post_type );
            if ( $pt_obj && $pt_obj->has_archive ) {
                $crumbs[] = [
                    'label' => $pt_obj->labels->name,
                    'url'   => get_post_type_archive_link( $post_type ),
                ];
            }

            // Primary category (for posts)
            if ( $post_type === 'post' ) {
                $cats = get_the_category( $post->ID );
                if ( $cats ) {
                    $cat      = $cats[0];
                    // Walk category ancestors
                    $ancestry = array_reverse( get_ancestors( $cat->term_id, 'category' ) );
                    foreach ( $ancestry as $ancestor_id ) {
                        $ancestor = get_category( $ancestor_id );
                        $crumbs[] = [ 'label' => $ancestor->name, 'url' => get_category_link( $ancestor_id ) ];
                    }
                    $crumbs[] = [ 'label' => $cat->name, 'url' => get_category_link( $cat->term_id ) ];
                }
            }

            // Post ancestors (for pages / hierarchical CPTs)
            if ( $post->post_parent ) {
                $ancestors = array_reverse( get_post_ancestors( $post->ID ) );
                foreach ( $ancestors as $ancestor_id ) {
                    $crumbs[] = [ 'label' => get_the_title( $ancestor_id ), 'url' => get_permalink( $ancestor_id ) ];
                }
            }

            // Current post
            $crumbs[] = [ 'label' => get_the_title( $post->ID ), 'url' => null ];

        } elseif ( is_category() || is_tag() || is_tax() ) {
            $term     = get_queried_object();
            $ancestry = array_reverse( get_ancestors( $term->term_id, $term->taxonomy ) );
            foreach ( $ancestry as $ancestor_id ) {
                $anc      = get_term( $ancestor_id, $term->taxonomy );
                $crumbs[] = [ 'label' => $anc->name, 'url' => get_term_link( $anc ) ];
            }
            $crumbs[] = [ 'label' => $term->name, 'url' => null ];

        } elseif ( is_search() ) {
            /* translators: %s: the search query. */
            $crumbs[] = [ 'label' => sprintf( __( 'Search: %s', 'aumviso' ), get_search_query() ), 'url' => null ];

        } elseif ( is_author() ) {
            $crumbs[] = [ 'label' => get_the_author_meta( 'display_name' ), 'url' => null ];

        } elseif ( is_date() ) {
            $crumbs[] = [ 'label' => get_the_date(), 'url' => null ];

        } elseif ( is_post_type_archive() ) {
            $pt_obj   = get_queried_object();
            $crumbs[] = [ 'label' => $pt_obj->labels->name ?? '', 'url' => null ];
        }

        return $crumbs;
    }
}

/**
 * Template function.
 */
function aumviso_breadcrumb(): void {
    echo AumViso_Breadcrumb::instance()->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes every label with esc_html() and every URL with esc_url().
}
