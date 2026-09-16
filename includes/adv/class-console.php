<?php
/**
 * Pro admin orchestrator — the GEO Console.
 *
 * Mounts into AumViso's menu via the `aumviso_admin_menu_registered` hook and
 * renders a tabbed `.aml-app` screen sharing the suite's design system. The
 * Overview tab is live; the remaining tabs are placeholders for upcoming
 * modules (batch AI, brand entity, llms.txt).
 */

defined( 'ABSPATH' ) || exit;

final class AumViso_Adv_Console {

	private static ?AumViso_Adv_Console $instance = null;

	public const SLUG = 'aumviso';

	private string $hook = '';

	private ?AumViso_Adv_Setup $setup = null;
	private ?AumViso_Adv_Batch $batch = null;
	private ?AumViso_Adv_Factory $factory = null;

	public static function instance(): AumViso_Adv_Console {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->setup   = AumViso_Adv_Setup::instance();
		$this->batch   = new AumViso_Adv_Batch();
		$this->factory = AumViso_Adv_Factory::instance();
		add_action( 'aumviso_admin_menu_registered', [ $this, 'register_menu' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * No menu of its own.
	 *
	 * 🔴 This used to call `add_submenu_page()` with **the same slug as AumViso's main page**, a leftover
	 * from when the console shipped as a separate "Pro" product. Two entries were listed, both pointed at
	 * `admin.php?page=aumviso`, and because both render callbacks landed on that one page hook at priority
	 * 10, **both ran**: the screen carried two headers and two complete interfaces stacked on top of each
	 * other, and the menu offered a "Pro" upgrade to something the buyer already had.
	 *
	 * Free and Pro merged; the interface had not. These tabs now belong to the one AumViso page — see
	 * `AumViso_Admin::tabs()`, which merges `tabs()` below, and routes them back to `render_body()`.
	 *
	 * @param string $parent_slug Unused, kept for the action's signature.
	 * @param string $lite_hook   Unused.
	 * @return void
	 */
	public function register_menu( string $parent_slug = '', string $lite_hook = '' ): void {
		$this->hook = $lite_hook;   // share the main page's hook so asset loading still matches
	}


	public function enqueue_assets( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		$rel  = 'admin/assets/css/adv.css';
		$path = AUMVISO_DIR . $rel;
		wp_enqueue_style(
			'aumviso-adv-admin',
			AUMVISO_URL . $rel,
			[],
			file_exists( $path ) ? (string) filemtime( $path ) : AUMVISO_VERSION
		);

		$js     = 'admin/assets/js/batch.js';
		$jspath = AUMVISO_DIR . $js;
		wp_enqueue_script(
			'aumviso-adv-batch',
			AUMVISO_URL . $js,
			[ 'jquery' ],
			file_exists( $jspath ) ? (string) filemtime( $jspath ) : AUMVISO_VERSION,
			true
		);
		wp_localize_script(
			'aumviso-adv-batch',
			'AumVisoAdvBatch',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'chunk'   => 4,
				'i18n'    => [
					'loading' => __( 'Loading items…', 'aumviso' ),
					'none'    => __( 'Nothing to process.', 'aumviso' ),
					'done'    => __( 'Done.', 'aumviso' ),
					'error'   => __( 'Error.', 'aumviso' ),
					'reqfail' => __( 'Request failed', 'aumviso' ),
				],
			]
		);

		$ajs     = 'admin/assets/js/auto.js';
		$ajspath = AUMVISO_DIR . $ajs;
		wp_enqueue_script(
			'aumviso-adv-auto',
			AUMVISO_URL . $ajs,
			array(),
			file_exists( $ajspath ) ? (string) filemtime( $ajspath ) : AUMVISO_VERSION,
			true
		);
		wp_localize_script(
			'aumviso-adv-auto',
			'AumVisoAdvAuto',
			array(
				'confirmFullAuto' => __( 'Full-auto will publish AI content automatically without review. AI content may be inaccurate. Run semi-auto for a few days first. Enable full-auto anyway?', 'aumviso' ),
			)
		);

		$fjs     = 'admin/assets/js/factory.js';
		$fjspath = AUMVISO_DIR . $fjs;
		wp_enqueue_script(
			'aumviso-adv-factory',
			AUMVISO_URL . $fjs,
			[ 'jquery' ],
			file_exists( $fjspath ) ? (string) filemtime( $fjspath ) : AUMVISO_VERSION,
			true
		);
		wp_localize_script(
			'aumviso-adv-factory',
			'AumVisoAdvFactory',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => [
					'notopic'     => __( 'Please enter a topic.', 'aumviso' ),
					'writing'     => __( 'Building knowledge draft…', 'aumviso' ),
					'created'     => __( 'Draft created', 'aumviso' ),
					'words'       => __( 'words', 'aumviso' ),
					'edit'        => __( 'Edit draft', 'aumviso' ),
					'optimizing'  => __( 'Adding meta description…', 'aumviso' ),
					'faq'         => __( 'Generating FAQ…', 'aumviso' ),
					'summary'     => __( 'Adding key takeaways…', 'aumviso' ),
					'related'     => __( 'Adding related questions…', 'aumviso' ),
					'done'        => __( 'Done — review and publish this draft to make it trusted knowledge.', 'aumviso' ),
					'error'       => __( 'Error.', 'aumviso' ),
					'noknowledge' => __( 'No knowledge-base topics yet — add FAQs, glossary terms, or guides first.', 'aumviso' ),
				],
			]
		);
	}

	// ------------------------------------
	// Tabs
	// ------------------------------------

	/** key => [ label, icon, ready ]. */
	/**
	 * The console's tabs, for the main page to merge in.
	 *
	 * ⚠️ The first key is `geo`, not `overview`: the main page already owns `overview`, and two tabs with
	 * one key means one of them can never be reached. Every other key here is unique across both sets.
	 */
	public function tabs(): array {
		return [
			'geo'      => [ 'label' => __( 'GEO Console', 'aumviso' ), 'icon' => 'dashicons-chart-area',    'ready' => true ],
			'setup'    => [ 'label' => __( 'Setup', 'aumviso' ),        'icon' => 'dashicons-clipboard',     'ready' => true ],
			'factory'  => [ 'label' => __( 'Knowledge Builder', 'aumviso' ), 'icon' => 'dashicons-welcome-learn-more', 'ready' => true ],
			'auto'     => [ 'label' => __( 'Automation', 'aumviso' ),   'icon' => 'dashicons-clock',         'ready' => true ],
			'images'   => [ 'label' => __( 'Images', 'aumviso' ),       'icon' => 'dashicons-format-image',  'ready' => true ],
			'batch'    => [ 'label' => __( 'Optimize Existing', 'aumviso' ), 'icon' => 'dashicons-superhero-alt', 'ready' => true ],
			'brand'    => [ 'label' => __( 'Brand Entity', 'aumviso' ), 'icon' => 'dashicons-store',         'ready' => true ],
			'llms'     => [ 'label' => __( 'llms.txt', 'aumviso' ),     'icon' => 'dashicons-media-text',    'ready' => true ],
		];
	}

	/**
	 * Parent-agnostic URL to a tab on the console page. Static so other
	 * subsystem can build redirect URLs to its own tab.
	 *
	 * @param string $tab   Tab key.
	 * @param array  $extra Extra query args.
	 */
	public static function url( string $tab = '', array $extra = [] ): string {
		$base = menu_page_url( self::SLUG, false );
		if ( ! $base ) {
			$base = admin_url( 'admin.php?page=' . self::SLUG );
		}
		$args = [];
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		$args = array_merge( $args, $extra );
		return ! empty( $args ) ? add_query_arg( $args, $base ) : $base;
	}

	// ------------------------------------
	// Render
	// ------------------------------------

	/**
	 * One tab's contents, with no page chrome around it.
	 *
	 * The header, the tab strip and the `.wrap` are the main page's job now. Emitting them here as well is
	 * what produced the doubled screen described on `register_menu()`.
	 */
	public function render_body( string $current ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		switch ( $current ) {
			case 'setup':
				AumViso_Adv_Setup::instance()->render_tab();
				break;
			case 'batch':
				$this->batch->render_tab();
				break;
			case 'factory':
				$this->factory->render_tab();
				break;
			case 'images':
				AumViso_Adv_Images::instance()->render_tab();
				break;
			case 'auto':
				AumViso_Adv_Auto::instance()->render_tab();
				break;
			case 'brand':
				AumViso_Adv_Brand::instance()->render_tab();
				break;
			case 'llms':
				AumViso_Adv_LLMS::instance()->render_tab();
				break;
			default:
				$this->render_overview();
		}
	}


	private function render_overview(): void {
		$force = false;
		if ( isset( $_GET['avp_refresh'] ) ) {
			check_admin_referer( 'avp_refresh' );
			AumViso_Adv_Overview::flush();
			$force = true;
		}

		$data  = AumViso_Adv_Overview::get( $force );
		$grade = self::grade( (int) $data['avg'] );

		echo '<p class="aml-tab-intro">' . esc_html__( 'A live read of how AI-ready your content is across the whole site. Scores reuse AumViso\'s per-post analysis.', 'aumviso' ) . '</p>';

		// ---- Top row: score ring + stat cards ----
		echo '<div class="avp-overview-top">';

		echo '<div class="aml-card avp-score-card">';
		printf(
			'<div class="avp-score-ring is-%1$s"><span class="avp-score-num">%2$d</span><span class="avp-score-cap">%3$s</span></div>',
			esc_attr( $grade['class'] ),
			(int) $data['avg'],
			esc_html( $grade['label'] )
		);
		printf(
			'<p class="avp-score-sub">%s</p>',
			esc_html( sprintf( /* translators: %d: number of items */ __( 'Average across %d items', 'aumviso' ), (int) $data['count'] ) )
		);
		echo '</div>';

		echo '<div class="avp-stat-grid">';
		$this->stat( (int) $data['count'], __( 'Content scanned', 'aumviso' ) );
		$this->stat( (int) $data['missing_desc'], __( 'Missing meta description', 'aumviso' ), $data['missing_desc'] > 0 ? 'is-warn' : '' );
		$this->stat( (int) $data['missing_thumb'], __( 'Missing featured image', 'aumviso' ), $data['missing_thumb'] > 0 ? 'is-warn' : '' );
		$this->stat( (int) ( $data['products']['total'] ?? 0 ), __( 'Products in catalog', 'aumviso' ) );
		echo '</div>';

		echo '</div>'; // .avp-overview-top

		// ---- Grade distribution ----
		$this->render_bands( $data );

		// ---- Per content type ----
		$this->render_type_table( $data );

		// ---- Products ----
		$this->render_products( $data );

		// ---- Footer: stamp + refresh + cap notice ----
		echo '<div class="avp-meta-line">';
		printf(
			'<span class="avp-stamp">%s</span>',
			esc_html( sprintf( /* translators: %s: datetime */ __( 'Last scanned: %s', 'aumviso' ), (string) $data['generated'] ) )
		);
		printf(
			'<a class="aml-btn" href="%s"><span class="dashicons dashicons-update"></span> %s</a>',
			esc_url( wp_nonce_url( self::url( 'overview' ) . '&avp_refresh=1', 'avp_refresh' ) ),
			esc_html__( 'Refresh', 'aumviso' )
		);
		echo '</div>';

		if ( ! empty( $data['capped'] ) ) {
			printf(
				'<p class="aml-tab-intro">%s</p>',
				esc_html( sprintf( /* translators: %d: cap */ __( 'Note: scan limited to the %d most recently modified items per content type.', 'aumviso' ), (int) $data['cap'] ) )
			);
		}
	}

	private function stat( int $num, string $label, string $modifier = '' ): void {
		printf(
			'<div class="avp-stat"><div class="avp-stat-num %1$s">%2$d</div><div class="avp-stat-label">%3$s</div></div>',
			esc_attr( $modifier ),
			absint( $num ),
			esc_html( $label )
		);
	}

	private function render_bands( array $data ): void {
		$bands = $data['bands'];
		$count = max( 1, (int) $data['count'] );

		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-chart-bar"></span>' . esc_html__( 'Score distribution', 'aumviso' ) . '</h2>';
		echo '<div class="avp-bands">';
		foreach ( [ 'great', 'good', 'average', 'poor' ] as $band ) {
			$pct = round( ( (int) $bands[ $band ] / $count ) * 100, 2 );
			if ( $pct > 0 ) {
				printf( '<span class="avp-band %1$s" style="width:%2$s%%"></span>', esc_attr( $band ), esc_attr( (string) $pct ) );
			}
		}
		echo '</div>';
		echo '<div class="avp-band-legend">';
		$labels = [
			'great'   => __( 'Great (80+)', 'aumviso' ),
			'good'    => __( 'Good (60–79)', 'aumviso' ),
			'average' => __( 'Average (40–59)', 'aumviso' ),
			'poor'    => __( 'Poor (<40)', 'aumviso' ),
		];
		foreach ( $labels as $band => $label ) {
			printf( '<span><span class="dot %1$s"></span>%2$s — %3$d</span>', esc_attr( $band ), esc_html( $label ), (int) $bands[ $band ] );
		}
		echo '</div>';
		echo '</div>';
	}

	private function render_type_table( array $data ): void {
		if ( empty( $data['per_type'] ) ) {
			return;
		}
		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-admin-page"></span>' . esc_html__( 'By content type', 'aumviso' ) . '</h2>';
		echo '<table class="avp-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Type', 'aumviso' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Published', 'aumviso' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Scanned', 'aumviso' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Avg score', 'aumviso' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $data['per_type'] as $row ) {
			printf(
				'<tr><td>%1$s</td><td class="num">%2$d</td><td class="num">%3$d</td><td class="num">%4$d</td></tr>',
				esc_html( $row['label'] ),
				(int) $row['total'],
				(int) $row['scanned'],
				(int) $row['avg']
			);
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	private function render_products( array $data ): void {
		$products = $data['products'] ?? [];
		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-cart"></span>' . esc_html__( 'Product sources', 'aumviso' ) . '</h2>';

		if ( empty( $products['list'] ) ) {
			echo '<p class="aml-card-desc">' . esc_html__( 'No product sources detected. Install a supported catalog (e.g. AumNexCart or WooCommerce) to enable product GEO.', 'aumviso' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="avp-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Source', 'aumviso' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Products', 'aumviso' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $products['list'] as $row ) {
			printf( '<tr><td>%1$s</td><td class="num">%2$d</td></tr>', esc_html( $row['label'] ), (int) $row['count'] );
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	private static function grade( int $score ): array {
		if ( $score >= 80 ) {
			return [ 'class' => 'great', 'label' => __( 'Great', 'aumviso' ) ];
		}
		if ( $score >= 60 ) {
			return [ 'class' => 'good', 'label' => __( 'Good', 'aumviso' ) ];
		}
		if ( $score >= 40 ) {
			return [ 'class' => 'average', 'label' => __( 'Average', 'aumviso' ) ];
		}
		return [ 'class' => 'poor', 'label' => __( 'Poor', 'aumviso' ) ];
	}
}
