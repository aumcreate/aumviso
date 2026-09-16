<?php
/**
 * llms.txt generator.
 *
 * Serves a virtual /llms.txt (and /llms-full.txt) — the emerging convention for
 * telling large language models what a site contains and how to use it. Built
 * dynamically on request (no file written to the web root) and cached, so it
 * always reflects current content. Aggregates pages, posts, AumViso GEO content
 * (FAQ / Glossary / Guides) and products from the shared registry.
 *
 * Singleton: serving runs on the frontend, the config UI + save in admin.
 * Serves /llms.txt and /llms-full.txt on the front end.
 */

defined( 'ABSPATH' ) || exit;

final class AumViso_Adv_LLMS {

	const OPTION_KEY   = 'aumviso_adv_llms';
	const NONCE_ACTION = 'aumviso_adv_llms_save';
	const NONCE_NAME   = 'aumviso_adv_llms_nonce';
	const CACHE_SHORT  = 'aumviso_adv_llms_txt';
	const CACHE_FULL   = 'aumviso_adv_llms_full_txt';

	private static ?AumViso_Adv_LLMS $instance = null;

	public static function instance(): AumViso_Adv_LLMS {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'template_redirect', [ $this, 'maybe_serve' ] );
		add_action( 'save_post', [ $this, 'flush_cache' ] );
		if ( is_admin() ) {
			add_action( 'admin_init', [ $this, 'handle_save' ] );
		}
	}

	public static function defaults(): array {
		return [
			'enabled'      => 1,
			'description'  => '',
			'inc_pages'    => 1,
			'inc_posts'    => 1,
			'inc_geo'      => 1,
			'inc_products' => 1,
			'max'          => 50,
		];
	}

	public function get_all(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
	}

	public function flush_cache(): void {
		delete_transient( self::CACHE_SHORT );
		delete_transient( self::CACHE_FULL );
	}

	// ------------------------------------
	// Serve
	// ------------------------------------

	public function maybe_serve(): void {
		if ( empty( $this->get_all()['enabled'] ) ) {
			return;
		}

		$path = $this->request_path_relative_to_home();

		if ( 'llms.txt' !== $path && 'llms-full.txt' !== $path ) {
			return;
		}

		$full = ( 'llms-full.txt' === $path );

		// WordPress has already queued a 404 for this path by the time
		// template_redirect fires, because llms.txt matches no rewrite rule.
		// Without resetting the status the file is served with a 404, and
		// crawlers discard the body.
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $this->generate( $full ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text body.
		exit;
	}

	/**
	 * The request path with the site's own base path removed.
	 *
	 * On a subdirectory multisite (or any install living under /something/)
	 * the request for the sub-site's llms.txt is /something/llms.txt. Comparing
	 * the raw path against 'llms.txt' can therefore never match there, which
	 * is how every sub-site on a subdirectory network was answering 404.
	 */
	private function request_path_relative_to_home(): string {
		$path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
		$base = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( '' !== $base && 0 === strpos( $path . '/', $base . '/' ) ) {
			$path = trim( substr( $path, strlen( $base ) ), '/' );
		}

		return $path;
	}

	/**
	 * Other things on this site that also produce an llms.txt, with what that
	 * means for the request. Read from each plugin's stored options rather
	 * than its API, so nothing here depends on those plugins being loaded.
	 *
	 * @return array<int, array{name:string, wins:bool, note:string}>
	 */
	public function competing_sources(): array {
		$found = [];

		if ( file_exists( ABSPATH . 'llms.txt' ) ) {
			$found[] = [
				'name' => __( 'A file named llms.txt in the site root', 'aumviso' ),
				'wins' => true,
				'note' => __( 'A file on disk is served by the web server before WordPress runs, so it is what visitors and crawlers get; the one AumViso generates is not used. Yoast SEO and All in One SEO write their llms.txt this way. To use AumViso\'s, delete the file and turn the feature off in the plugin that wrote it.', 'aumviso' ),
			];
		}

		$wpseo = get_option( 'wpseo' );
		if ( defined( 'WPSEO_VERSION' ) && is_array( $wpseo ) && ! empty( $wpseo['enable_llms_txt'] ) ) {
			$found[] = [
				'name' => __( 'Yoast SEO: llms.txt is enabled', 'aumviso' ),
				'wins' => true,
				'note' => __( 'Yoast writes a real file into the site root on a schedule. Once it exists it wins over AumViso\'s; see the row above if it already does.', 'aumviso' ),
			];
		}

		if ( defined( 'AIOSEO_VERSION' ) ) {
			$aio = json_decode( (string) get_option( 'aioseo_options', '' ), true );
			if ( ! empty( $aio['sitemap']['llms']['enable'] ) ) {
				$found[] = [
					'name' => __( 'All in One SEO: llms.txt is enabled', 'aumviso' ),
					'wins' => true,
					'note' => __( 'All in One SEO writes a real file into the site root on a schedule. Once it exists it wins over AumViso\'s; see the row above if it already does.', 'aumviso' ),
				];
			}
		}

		$rm = get_option( 'rank_math_modules', [] );
		if ( defined( 'RANK_MATH_VERSION' ) && is_array( $rm ) && in_array( 'llms-txt', $rm, true ) ) {
			$found[] = [
				'name' => __( 'Rank Math: LLMS Txt module is active', 'aumviso' ),
				'wins' => false,
				'note' => __( 'Rank Math serves llms.txt from WordPress the same way AumViso does. AumViso registers first, so while both are on it is AumViso\'s llms.txt that is served. Turn AumViso\'s off above if you want Rank Math\'s instead.', 'aumviso' ),
			];
		}

		return $found;
	}

	private function generate( bool $full ): string {
		$key    = $full ? self::CACHE_FULL : self::CACHE_SHORT;
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$out = $this->build( $full );
		set_transient( $key, $out, DAY_IN_SECONDS );
		return $out;
	}

	// ------------------------------------
	// Build
	// ------------------------------------

	private function build( bool $full ): string {
		$s    = $this->get_all();
		$max  = max( 1, (int) $s['max'] );
		$name = wp_strip_all_tags( get_bloginfo( 'name' ) );
		$desc = '' !== (string) $s['description'] ? $s['description'] : wp_strip_all_tags( get_bloginfo( 'description' ) );

		$lines   = [];
		$lines[] = '# ' . $name;
		$lines[] = '';
		if ( '' !== (string) $desc ) {
			$lines[] = '> ' . $desc;
			$lines[] = '';
		}
		$lines[] = sprintf( '> Generated by AumViso. Base URL: %s', home_url( '/' ) );
		$lines[] = '';

		if ( ! empty( $s['inc_pages'] ) ) {
			$this->section( $lines, 'Pages', $this->post_items( 'page', $max, $full ), $full );
		}
		if ( ! empty( $s['inc_posts'] ) ) {
			$this->section( $lines, 'Posts', $this->post_items( 'post', $max, $full ), $full );
		}
		if ( ! empty( $s['inc_geo'] ) ) {
			foreach ( [ 'aumviso_faq' => 'FAQ', 'aumviso_glossary' => 'Glossary', 'aumviso_guide' => 'Guides' ] as $pt => $label ) {
				if ( post_type_exists( $pt ) ) {
					$this->section( $lines, $label, $this->post_items( $pt, $max, $full ), $full );
				}
			}
		}
		if ( ! empty( $s['inc_products'] ) ) {
			$this->section( $lines, 'Products', $this->product_items( $max, $full ), $full );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Appends a "## Label" section of bullet links to $lines.
	 *
	 * @param array $lines Reference to output lines.
	 * @param array $items Each: [ 'title', 'url', 'desc' ].
	 */
	private function section( array &$lines, string $label, array $items, bool $full ): void {
		if ( empty( $items ) ) {
			return;
		}
		$lines[] = '## ' . $label;
		foreach ( $items as $item ) {
			$line = sprintf( '- [%s](%s)', $item['title'], $item['url'] );
			if ( $full && '' !== $item['desc'] ) {
				$line .= ': ' . $item['desc'];
			}
			$lines[] = $line;
		}
		$lines[] = '';
	}

	private function post_items( string $post_type, int $max, bool $full ): array {
		$q = new WP_Query(
			[
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $max,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			]
		);

		$items = [];
		foreach ( $q->posts as $post ) {
			$items[] = [
				'title' => $this->clean( get_the_title( $post ) ),
				'url'   => get_permalink( $post ),
				'desc'  => $full ? $this->clean( wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 30, '' ) ) : '',
			];
		}
		wp_reset_postdata();
		return $items;
	}

	private function product_items( int $max, bool $full ): array {
		if ( ! class_exists( 'AumViso_Product_Registry' ) ) {
			return [];
		}
		$items = [];
		foreach ( AumViso_Product_Registry::instance()->get_sources() as $source ) {
			$ids = $source->query( [ 'posts_per_page' => $max ] );
			foreach ( $ids as $id ) {
				$data    = $source->get( (int) $id );
				if ( ! $data ) {
					continue;
				}
				$items[] = [
					'title' => $this->clean( (string) $data['title'] ),
					'url'   => (string) $data['permalink'],
					'desc'  => $full ? $this->clean( (string) ( $data['description'] ?? '' ) ) : '',
				];
				if ( count( $items ) >= $max ) {
					break 2;
				}
			}
		}
		return $items;
	}

	/** Collapse whitespace / strip newlines so a line stays one line. */
	private function clean( string $s ): string {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $s ) ) );
	}

	// ------------------------------------
	// Save (admin)
	// ------------------------------------

	public function handle_save(): void {
		if ( empty( $_POST[ self::NONCE_NAME ] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		$raw   = isset( $_POST['aumviso_adv_llms'] ) && is_array( $_POST['aumviso_adv_llms'] ) ? wp_unslash( $_POST['aumviso_adv_llms'] ) : [];
		$clean = [
			'enabled'      => empty( $raw['enabled'] ) ? 0 : 1,
			'description'  => sanitize_textarea_field( $raw['description'] ?? '' ),
			'inc_pages'    => empty( $raw['inc_pages'] ) ? 0 : 1,
			'inc_posts'    => empty( $raw['inc_posts'] ) ? 0 : 1,
			'inc_geo'      => empty( $raw['inc_geo'] ) ? 0 : 1,
			'inc_products' => empty( $raw['inc_products'] ) ? 0 : 1,
			'max'          => max( 1, min( 500, (int) ( $raw['max'] ?? 50 ) ) ),
		];

		update_option( self::OPTION_KEY, $clean );
		$this->flush_cache();

		wp_safe_redirect( AumViso_Adv_Console::url( 'llms', [ 'updated' => 1 ] ) );
		exit;
	}

	// ------------------------------------
	// Render tab
	// ------------------------------------

	public function render_tab(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s   = $this->get_all();
		$url = home_url( '/llms.txt' );

		echo '<p class="aml-tab-intro">' . esc_html__( 'Publish an llms.txt — a map of your site for AI models, served live at /llms.txt (and /llms-full.txt with excerpts). No file is written; it always reflects current content.', 'aumviso' ) . '</p>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'llms.txt settings saved.', 'aumviso' ) . '</p></div>';
		}

		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-media-text"></span>' . esc_html__( 'Your llms.txt', 'aumviso' ) . '</h2>';
		printf(
			'<p><a class="aml-btn" href="%1$s" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span> %2$s</a> <code>%1$s</code></p>',
			esc_url( $url ),
			esc_html__( 'View /llms.txt', 'aumviso' )
		);
		echo '<p class="aml-field-hint">' . esc_html__( 'Requires pretty permalinks. If you see a 404, go to Settings → Permalinks and click Save once.', 'aumviso' ) . '</p>';
		echo '</div>';

		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$others = $this->competing_sources();
		if ( $others ) {
			echo '<div class="aml-card" style="border-left:4px solid #dba617">';
			echo '<h2 class="aml-card-h"><span class="dashicons dashicons-info-outline"></span>' . esc_html__( 'Something else on this site also produces an llms.txt', 'aumviso' ) . '</h2>';
			foreach ( $others as $o ) {
				printf(
					'<p style="margin:6px 0"><strong>%1$s</strong> — %2$s<br><span class="aml-field-hint">%3$s</span></p>',
					esc_html( $o['name'] ),
					$o['wins'] ? esc_html__( 'it wins over AumViso\'s.', 'aumviso' ) : esc_html__( 'AumViso\'s is the one being served.', 'aumviso' ),
					esc_html( $o['note'] )
				);
			}
			echo '</div>';
		}

		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-admin-settings"></span>' . esc_html__( 'Configuration', 'aumviso' ) . '</h2>';

		$this->toggle( 'enabled', __( 'Serve /llms.txt from this site', 'aumviso' ), $s['enabled'] );
		echo '<p class="aml-field-hint" style="margin:4px 0 14px">' . esc_html__( 'Turn this off to let another plugin own llms.txt, or to not publish one at all. Everything below only applies while it is on.', 'aumviso' ) . '</p>';

		printf(
			'<div class="aml-field"><label class="aml-label" for="avp-llms-desc">%1$s</label><textarea id="avp-llms-desc" name="aumviso_adv_llms[description]" rows="3" class="large-text" placeholder="%2$s">%3$s</textarea><p class="aml-field-hint">%4$s</p></div>',
			esc_html__( 'Site description', 'aumviso' ),
			esc_attr( wp_strip_all_tags( get_bloginfo( 'description' ) ) ),
			esc_textarea( $s['description'] ),
			esc_html__( 'Shown at the top of llms.txt. Leave blank to use the site tagline.', 'aumviso' )
		);

		$this->toggle( 'inc_pages', __( 'Include Pages', 'aumviso' ), $s['inc_pages'] );
		$this->toggle( 'inc_posts', __( 'Include Posts', 'aumviso' ), $s['inc_posts'] );
		$this->toggle( 'inc_geo', __( 'Include FAQ / Glossary / Guides', 'aumviso' ), $s['inc_geo'] );
		$this->toggle( 'inc_products', __( 'Include Products', 'aumviso' ), $s['inc_products'] );

		printf(
			'<div class="aml-field" style="margin-top:12px"><label class="aml-label" for="avp-llms-max">%1$s</label><input type="number" id="avp-llms-max" name="aumviso_adv_llms[max]" value="%2$d" min="1" max="500" style="width:100px" /></div>',
			esc_html__( 'Max items per section', 'aumviso' ),
			(int) $s['max']
		);

		echo '<p class="aml-actions-bar" style="margin-top:14px"><button type="submit" class="aml-btn aml-btn-primary">' . esc_html__( 'Save', 'aumviso' ) . '</button></p>';
		echo '</div>';
		echo '</form>';
	}

	private function toggle( string $key, string $label, $checked ): void {
		printf(
			'<label class="aml-switch-row" style="display:inline-flex;margin-right:24px"><span class="aml-switch"><input type="checkbox" name="aumviso_adv_llms[%1$s]" value="1" %2$s /><span class="aml-track" aria-hidden="true"></span></span><span class="aml-switch-label">%3$s</span></label>',
			esc_attr( $key ),
			checked( (int) $checked, 1, false ),
			esc_html( $label )
		);
	}
}
