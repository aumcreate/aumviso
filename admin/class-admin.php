<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin screen orchestrator.
 *
 * All settings live on one page (slug 'aumviso') split into tabs via a
 * ?tab= query arg. Each settings tab keeps its own independent form, nonce, and
 * hidden section id (see AumViso_SettingsPage), and every save writes its own
 * option keys — so saving one tab never resets another. This mirrors the AumLang
 * and AumCreate theme settings pages.
 *
 * When the AumCreate theme is active the page is hooked under the theme's menu;
 * otherwise it is a standalone top-level menu.
 */
class AumViso_Admin {

	private static ?AumViso_Admin $instance = null;

	/** Menu page hook suffix, for targeted asset loading. */
	private string $hook = '';

	public static function instance(): AumViso_Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'plugin_action_links_' . AUMVISO_BASENAME, [ $this, 'plugin_action_links' ] );
	}


	// ------------------------------------
	// Plugins screen row link
	// ------------------------------------

	/**
	 * Adds a Settings link to this plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function plugin_action_links( array $links ): array {
		$url = menu_page_url( self::SLUG, false );

		if ( $url ) {
			array_unshift(
				$links,
				sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'aumviso' ) )
			);
		}

		return $links;
	}

	// ------------------------------------
	// Branding
	// ------------------------------------

	public const BRAND = 'AumViso';
	public const SLUG  = 'aumviso';

	/** Whether the AumCreate theme (parent or child) is active. */
	private function is_aumcreate_theme(): bool {
		$theme = wp_get_theme();
		return 'aumcreate' === $theme->get_template()
			|| false !== stripos( (string) $theme->get( 'Name' ), 'aumcreate' );
	}

	// ------------------------------------
	// Menu
	// ------------------------------------

	public function register_menu(): void {
		if ( $this->is_aumcreate_theme() ) {
			// Under the AumCreate theme menu the item is labelled "SEO/GEO"
			// (the page itself still carries the AumViso brand).
			$this->hook = (string) add_submenu_page(
				'aumcreate-settings',
				self::BRAND,
				__( 'SEO/GEO', 'aumviso' ),
				'manage_options',
				self::SLUG,
				[ $this, 'render_page' ]
			);
			$parent = 'aumcreate-settings';
		} else {
			$this->hook = (string) add_menu_page(
				self::BRAND,
				self::BRAND,
				'manage_options',
				self::SLUG,
				[ $this, 'render_page' ],
				'dashicons-chart-line',
				59
			);
			$parent = self::SLUG;
		}

		/**
		 * Fires right after AumViso registers its admin menu.
		 *
		 * Extension point (Module 0): add-ons attach their
		 * own submenus to $parent_slug so they appear inside the AumViso menu
		 * regardless of whether it is a standalone top-level menu or nested
		 * under the AumCreate theme menu.
		 *
		 * @param string $parent_slug Slug to use as add_submenu_page() parent.
		 * @param string $hook        AumViso's own page hook suffix.
		 */
		do_action( 'aumviso_admin_menu_registered', $parent, $this->hook );
	}

	// ------------------------------------
	// Tabs
	// ------------------------------------

	/** key => [ label, dashicon ]. */
	/**
	 * Every tab on the one AumViso page.
	 *
	 * The GEO console's tabs are merged in rather than living on a second screen. They used to be a
	 * separate "Pro" menu item pointing at this same URL — see `AumViso_Adv_Console::register_menu()` for
	 * what that actually produced.
	 */
	private function tabs(): array {
		/*
		 * Ordered by when a buyer needs them, not by what the code grew into.
		 *
		 * Four groups, left to right:
		 *
		 *   1. **Start** — Setup, then Overview. A template arrives configured, so the first job is telling
		 *      the plugin who the business is; the health report is what you read afterwards.
		 *   2. **Knowledge** — Brand Entity feeds the Knowledge Builder, which feeds Automation. That is a
		 *      chain, and it now reads left to right in the order the work happens.
		 *   3. **Bulk work** — things run against content that already exists.
		 *   4. **Configuration** — set once, then forgotten. Last on purpose: five of these used to be
		 *      top-level tabs and were the most technical-looking thing on the screen.
		 *
		 * ⚠️ Keys are unchanged. Only the order moved, so every existing link and bookmark still lands
		 * where it did.
		 */
		$start = [
			'setup'    => [ 'label' => __( 'Setup', 'aumviso' ),        'icon' => 'dashicons-clipboard' ],
			'overview' => [ 'label' => __( 'Overview', 'aumviso' ),     'icon' => 'dashicons-chart-line' ],
			'crawlers' => [ 'label' => __( 'Crawlers', 'aumviso' ),     'icon' => 'dashicons-visibility' ],
		];
		$knowledge = [
			'brand'   => [ 'label' => __( 'Brand Entity', 'aumviso' ),      'icon' => 'dashicons-store' ],
			'factory' => [ 'label' => __( 'Knowledge Builder', 'aumviso' ), 'icon' => 'dashicons-welcome-learn-more' ],
			'auto'    => [ 'label' => __( 'Automation', 'aumviso' ),        'icon' => 'dashicons-clock' ],
			'geo'     => [ 'label' => __( 'GEO Console', 'aumviso' ),       'icon' => 'dashicons-chart-area' ],
		];
		$bulk = [
			'batch'  => [ 'label' => __( 'Optimize Existing', 'aumviso' ), 'icon' => 'dashicons-superhero-alt' ],
			'images' => [ 'label' => __( 'Images', 'aumviso' ),            'icon' => 'dashicons-format-image' ],
			'llms'   => [ 'label' => __( 'llms.txt', 'aumviso' ),          'icon' => 'dashicons-media-text' ],
		];
		$config = [
			'technical' => [ 'label' => __( 'Technical SEO', 'aumviso' ),     'icon' => 'dashicons-editor-code' ],
			'settings'  => [ 'label' => __( 'Linking & Related', 'aumviso' ), 'icon' => 'dashicons-admin-links' ],
			'ai'        => [ 'label' => __( 'AI Tools', 'aumviso' ),          'icon' => 'dashicons-superhero-alt' ],
		];

		$tabs = $start + $knowledge + $bulk + $config;

		/*
		 * Drop the tabs whose module is not loaded.
		 *
		 * ⚠️ Built from what the modules **report**, never from a hand-kept list of "which keys belong to
		 * whom". The first version carried such a list and it silently swallowed `overview` — a tab this
		 * class renders itself, wrongly named as the console's. A list like that is only ever correct on
		 * the day it is written.
		 */
		$modular = [];
		$console  = self::console();
		if ( $console ) {
			$modular += $console->tabs();
		}

		/* Everything this class renders on its own, and therefore always has. */
		$mine = [ 'setup', 'overview', 'technical', 'settings', 'ai' ];

		foreach ( array_keys( $tabs ) as $key ) {
			if ( ! in_array( $key, $mine, true ) && ! isset( $modular[ $key ] ) ) {
				unset( $tabs[ $key ] );
			}
		}

		return $tabs;
	}

	/**
	 * The GEO console, when its module is loaded.
	 *
	 * Guarded rather than assumed: the console lives under `includes/adv/` and a build that omits it must
	 * still produce a working page, with its tabs simply absent.
	 */
	private static function console(): ?AumViso_Adv_Console {
		return class_exists( 'AumViso_Adv_Console' ) ? AumViso_Adv_Console::instance() : null;
	}

	private function current_tab(): string {
		$tabs = $this->tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : $this->landing_tab();

		/*
		 * The five technical tabs merged into one. A bookmark, a link in someone's notes, or a redirect
		 * from an older build still names the old key — send it to the section rather than silently
		 * dropping the visitor on Overview, which looks like the setting was removed.
		 */
		$merged = [ 'meta', 'schema', 'sitemap', 'robots', 'links' ];
		if ( in_array( $tab, $merged, true ) ) {
			return 'technical';
		}

		return isset( $tabs[ $tab ] ) ? $tab : 'overview';
	}

	/**
	 * Where someone lands when they open the plugin with no tab chosen.
	 *
	 * 🔴 Overview is a **health report**, and a health report is the wrong first screen for a site that has
	 * not been set up yet. It answers "how am I doing" to someone still asking "what do I do", and the
	 * honest answer at that point is a column of zeroes — which reads as "this plugin found a lot wrong"
	 * rather than "you have not told me anything yet".
	 *
	 * Setup is the checklist, with counters and a button per step. So: land there until the brand entity is
	 * filled in, then step aside and let Overview be the front door for good.
	 *
	 * Only applies when **no tab was asked for**. One click on any tab is an explicit choice and is never
	 * second-guessed — this must not become a screen the buyer has to escape from every visit.
	 */
	private function landing_tab(): string {
		if ( ! class_exists( 'AumViso_Adv_Setup' ) || ! class_exists( 'AumViso_Adv_Brand' ) ) {
			return 'overview';
		}

		$brand = AumViso_Adv_Brand::completion();

		return ( (int) ( $brand['filled'] ?? 0 ) >= (int) ( $brand['total'] ?? 1 ) ) ? 'overview' : 'setup';
	}

	/** URL to a tab on this page. */
	public static function tab_url( string $tab, array $extra = [] ): string {
		$args = [ 'page' => self::SLUG ];
		if ( '' !== $tab && 'overview' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	// ------------------------------------
	// Page shell
	// ------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs    = $this->tabs();
		$current = $this->current_tab();
		?>
		<div class="wrap aumviso-app aml-app">
			<div class="aml-header">
				<span class="aml-logo" aria-hidden="true"><span class="dashicons dashicons-chart-line"></span></span>
				<div class="aml-header-text">
					<h1 class="aml-title">
						<?php echo esc_html( self::BRAND ); ?>
						<span class="aml-badge">v<?php echo esc_html( AUMVISO_VERSION ); ?></span>
					</h1>
					<p class="aml-subtitle"><?php esc_html_e( 'SEO + GEO in one place — meta, schema, sitemap, internal links & AI tools.', 'aumviso' ); ?></p>
				</div>
			</div>
			<hr class="wp-header-end">

			<nav class="aml-tabs" aria-label="<?php esc_attr_e( 'AumViso sections', 'aumviso' ); ?>">
				<?php foreach ( $tabs as $key => $tab ) : ?>
					<a class="aml-tab<?php echo $key === $current ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::tab_url( $key ) ); ?>"<?php echo $key === $current ? ' aria-current="page"' : ''; ?>>
						<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="aml-tab-body">
				<?php
				$settings = AumViso_SettingsPage::instance();
				switch ( $current ) {
					case 'technical':
						$this->render_technical( $settings );
						break;
					case 'ai':
						$settings->render_ai();
						break;
					case 'settings':
						$settings->render_settings();
						break;
					default:
						/*
						 * Anything this page does not handle itself belongs to the GEO console — its tabs
						 * are merged into `tabs()`, so `$current` has already been validated against the
						 * combined set and cannot be a stray value from the query string.
						 */
						$console = self::console();
						if ( $console && isset( $console->tabs()[ $current ] ) ) {
							$console->render_body( $current );
						} else {
							$this->render_overview();
						}
				}
				?>
			</div>
			<?php $this->render_ecosystem_note(); ?>
		</div>
		<?php
	}

	/**
	 * One line at the foot of the settings screen pointing at the rest of the
	 * AumCreate ecosystem. Plain text with a link, no tracking beyond the UTM
	 * tags in the URL itself.
	 */
	private function render_ecosystem_note(): void {
		$url = 'https://aumcreate.com/plugins/aumviso/?utm_source=plugin&utm_medium=aumviso&utm_campaign=settings';
		echo '<p class="aum-ecosystem-note" style="margin:24px 0 0;color:#646970;font-size:12px">';
		printf(
			/* translators: %s: link to aumcreate.com */
			esc_html__( 'Part of the AumCreate ecosystem — themes and templates built around it. %s', 'aumviso' ),
			'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">aumcreate.com</a>'
		);
		echo '</p>';
	}

	/**
	 * The five technical sections, on one page, with a jump list.
	 *
	 * Each section keeps **its own form**: they were already scoped so a save writes only that section's
	 * option keys, so stacking them changes nothing about saving and a buyer editing one cannot disturb
	 * another.
	 */
	private function render_technical( $settings ): void {
		$sections = [
			'meta'    => [ __( 'Meta', 'aumviso' ),           'render_meta' ],
			'schema'  => [ __( 'Schema', 'aumviso' ),         'render_schema' ],
			'sitemap' => [ __( 'Sitemap', 'aumviso' ),        'render_sitemap' ],
			'robots'  => [ __( 'Robots.txt', 'aumviso' ),     'render_robots' ],
			'links'   => [ __( 'Internal Links', 'aumviso' ), 'render_internal_links' ],
		];
		?>
		<p class="aml-tab-intro">
			<?php esc_html_e( 'A template arrives with all of this configured. Change it only if you know why.', 'aumviso' ); ?>
		</p>
		<nav class="aml-jump" aria-label="<?php esc_attr_e( 'Sections', 'aumviso' ); ?>">
			<?php foreach ( $sections as $key => $sec ) : ?>
				<a href="#aumviso-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $sec[0] ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php
		/*
		 * One form, one Save button.
		 *
		 * Five separate forms with five Save buttons was what merging the tabs produced at first, and it
		 * read as five unrelated settings that happened to share a page — a buyer who changed something in
		 * two sections had to notice there were two buttons, and would reasonably assume one Save covered
		 * the screen. It does now.
		 *
		 * Internal Links stays outside it: that section saves over AJAX and has no fields to submit, so
		 * including it under the same button would offer to save something the button has no part in.
		 */
		echo '<form method="post">';
		wp_nonce_field( 'aumviso_settings_save', 'aumviso_settings_nonce' );
		echo '<input type="hidden" name="aumviso_settings_page" value="technical">';

		$settings->set_merged( true );
		foreach ( $sections as $key => $sec ) {
			if ( 'links' === $key ) {
				continue;
			}
			printf( '<section id="aumviso-%s" class="aml-section">', esc_attr( $key ) );
			printf( '<h2 class="aml-section-h">%s</h2>', esc_html( $sec[0] ) );
			$settings->{$sec[1]}();
			echo '</section>';
		}
		$settings->set_merged( false );

		printf(
			'<p class="aml-actions-bar aml-sticky-save"><button type="submit" class="aml-btn aml-btn-primary">%s</button></p>',
			esc_html__( 'Save Settings', 'aumviso' )
		);
		echo '</form>';

		/* Outside the form — it has its own AJAX controls and no fields the button above would carry. */
		echo '<section id="aumviso-links" class="aml-section">';
		printf( '<h2 class="aml-section-h">%s</h2>', esc_html( $sections['links'][0] ) );
		$settings->render_internal_links();
		echo '</section>';
	}

	// ------------------------------------
	// Overview tab
	// ------------------------------------

	public function render_overview(): void {
		$stats = $this->get_seo_stats();
		?>
		<p class="aml-tab-intro"><?php esc_html_e( 'A quick health check of your site’s SEO and GEO content.', 'aumviso' ); ?></p>

		<div class="aml-grid">

			<?php $this->render_crawler_card(); ?>

			<div class="aml-card aml-card-wide">
				<h2 class="aml-card-h"><span class="dashicons dashicons-heart" aria-hidden="true"></span> <?php esc_html_e( 'SEO Health', 'aumviso' ); ?></h2>
				<ul class="aml-health-list">
					<?php
					$this->render_health_row(
						'desc',
						'missing_desc',
						$stats['missing_desc'],
						/* translators: %d: number of posts. */
						__( '%d posts missing meta description', 'aumviso' ),
						__( 'All posts have meta descriptions', 'aumviso' ),
						'aumviso_page_desc',
						'warn',
						/*
						 * The only issue with a bulk fix today: `AumViso_Adv_Batch::operations()` offers
						 * `meta_desc` and nothing else. When it grows an operation for OG images, that row
						 * gets a link too — until then, offering one there would be a promise the tool
						 * cannot keep.
						 */
						$this->bulk_fix_url()
					);
					$this->render_health_row(
						'og',
						'missing_og',
						$stats['missing_og'],
						/* translators: %d: number of posts. */
						__( '%d posts missing OG image', 'aumviso' ),
						__( 'All posts have OG images', 'aumviso' ),
						'aumviso_page_og',
						'warn'
					);
					$this->render_health_row(
						'ni',
						'noindex',
						$stats['noindex_count'],
						/* translators: %d: number of pages. */
						__( '%d pages set to noindex', 'aumviso' ),
						__( 'No noindex pages found', 'aumviso' ),
						'aumviso_page_ni',
						'danger'
					);
					?>
				</ul>
			</div>

			<div class="aml-card">
				<h2 class="aml-card-h"><span class="dashicons dashicons-media-document" aria-hidden="true"></span> <?php esc_html_e( 'Content', 'aumviso' ); ?></h2>
				<ul class="aml-stat-list">
					<li><span class="aml-stat-label"><?php esc_html_e( 'Published Posts', 'aumviso' ); ?></span><span class="aml-stat-value"><?php echo esc_html( $stats['published_posts'] ); ?></span></li>
					<li><span class="aml-stat-label"><?php esc_html_e( 'FAQs', 'aumviso' ); ?></span><span class="aml-stat-value"><?php echo esc_html( $stats['faq_count'] ); ?></span></li>
					<li><span class="aml-stat-label"><?php esc_html_e( 'Glossary Terms', 'aumviso' ); ?></span><span class="aml-stat-value"><?php echo esc_html( $stats['glossary_count'] ); ?></span></li>
					<li><span class="aml-stat-label"><?php esc_html_e( 'Guides', 'aumviso' ); ?></span><span class="aml-stat-value"><?php echo esc_html( $stats['guide_count'] ); ?></span></li>
				</ul>
			</div>

			<div class="aml-card">
				<h2 class="aml-card-h"><span class="dashicons dashicons-networking" aria-hidden="true"></span> <?php esc_html_e( 'Sitemap', 'aumviso' ); ?></h2>
				<?php if ( AumViso_Options::get( 'aumviso_sitemap_enabled', true ) ) : ?>
					<p><span class="aml-pill aml-pill-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Active', 'aumviso' ); ?></span></p>
					<p style="margin-top:12px"><a class="aml-btn" href="<?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-external" aria-hidden="true"></span> <?php esc_html_e( 'View sitemap', 'aumviso' ); ?></a></p>
				<?php else : ?>
					<p><span class="aml-pill aml-pill-danger"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span> <?php esc_html_e( 'Disabled', 'aumviso' ); ?></span></p>
				<?php endif; ?>
			</div>

			<div class="aml-card">
				<h2 class="aml-card-h"><span class="dashicons dashicons-controls-play" aria-hidden="true"></span> <?php esc_html_e( 'Quick Actions', 'aumviso' ); ?></h2>
				<ul class="aml-link-list">
					<li><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=aumviso_faq' ) ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( 'New FAQ', 'aumviso' ); ?></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=aumviso_glossary' ) ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( 'New Glossary Term', 'aumviso' ); ?></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=aumviso_guide' ) ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( 'New Guide', 'aumviso' ); ?></a></li>
					<li><a href="<?php echo esc_url( self::tab_url( 'robots' ) ); ?>"><span class="dashicons dashicons-shield" aria-hidden="true"></span> <?php esc_html_e( 'Edit Robots.txt', 'aumviso' ); ?></a></li>
					<li><a href="<?php echo esc_url( self::tab_url( 'links' ) ); ?>"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> <?php esc_html_e( 'Manage Internal Links', 'aumviso' ); ?></a></li>
				</ul>
			</div>

		</div>
		<?php
	}

	/**
	 * Render one SEO-health row with an optional expandable detail table.
	 *
	 * @param string $key        Short key (desc/og/ni) for element ids.
	 * @param string $issue      Issue key for get_issue_posts().
	 * @param int    $count      Number of affected posts.
	 * @param string $issue_fmt  printf format for the issue message (has %d).
	 * @param string $ok_text    Message when there are no issues.
	 * @param string $page_arg   Query arg used for detail pagination.
	 * @param string $severity   'warn' or 'danger'.
	 */
	/**
	 * One line of the health report.
	 *
	 * `$fix_url`, when given, offers to fix the whole issue at once instead of one post at a time.
	 *
	 * 🔴 **Only pass it for issues a tool can actually fix.** The report used to end at "16 posts missing
	 * meta description" with a list and an Edit link per row, while a batch generator that fixes exactly
	 * that sat three tabs away — so the diligent buyer edited sixteen posts by hand and never learned the
	 * tool existed. A button that leads nowhere useful would be worse still, which is why the caller
	 * decides rather than this method guessing.
	 */
	/**
	 * Where "Fix all" should send someone.
	 *
	 * **AI Tools when there is no key yet, the batch tool when there is.**
	 *
	 * Sending everyone to the batch tool regardless produced a screen that contradicted itself: "pick a
	 * content type and run it" directly above "no AI API key is configured yet". The buyer pressed a button
	 * offering to fix something and was told, one line later, that it could not. The first step of fixing
	 * this issue on an unconfigured site **is** configuring the key, so that is where the button goes.
	 */
	/**
	 * Where the crawler card used to be.
	 *
	 * AumViso no longer records crawler visits -- that moved to AumCrawl, which
	 * does the whole job rather than half of it. This card stays so the space
	 * says where the feature went instead of the feature simply vanishing.
	 */
	private function render_crawler_card(): void {
		if ( defined( 'AUMCRAWL_VERSION' ) ) {
			return;
		}

		$install = self::aumcrawl_action_url();
		?>
		<div class="aml-card aml-card-wide">
			<h2 class="aml-card-h">
				<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
				<?php esc_html_e( 'See who reads this site', 'aumviso' ); ?>
			</h2>
			<p class="aml-card-desc">
				<?php esc_html_e( 'AumCrawl — also free — shows which AI crawlers and search engines read your site, checks that each one is who it claims to be, and lets you decide which ones to allow.', 'aumviso' ); ?>
			</p>
			<?php if ( $install ) : ?>
				<p class="aml-actions-bar">
					<a class="aml-btn" href="<?php echo esc_url( $install['url'] ); ?>"><?php echo esc_html( $install['label'] ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A link that installs or activates AumCrawl, or nothing when it is not
	 * this user's to install.
	 *
	 * The state and the wording come from core, so the button says the same
	 * thing it would say in Plugins > Add New, and stays correct when the
	 * plugin is present but inactive.
	 *
	 * @return array{url:string,label:string}|null
	 */
	private static function aumcrawl_action_url(): ?array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$file = 'aumcrawl/aumcrawl.php';

		if ( file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
			if ( is_plugin_active( $file ) || ! current_user_can( 'activate_plugins' ) ) {
				return null;
			}
			return [
				'url'   => wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ), 'activate-plugin_' . $file ),
				'label' => __( 'Activate AumCrawl', 'aumviso' ),
			];
		}

		if ( ! current_user_can( 'install_plugins' ) ) {
			return null;
		}

		return [
			'url'   => wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=aumcrawl' ), 'install-plugin_aumcrawl' ),
			'label' => __( 'Install AumCrawl', 'aumviso' ),
		];
	}

	private function bulk_fix_url(): string {
		if ( ! class_exists( 'AumViso_Adv_Batch' ) ) {
			return '';
		}

		$args = AumViso_Adv_Batch::ai_configured()
			? [ 'page' => 'aumviso', 'tab' => 'batch', 'from' => 'health' ]
			: [ 'page' => 'aumviso', 'tab' => 'ai', 'from' => 'health' ];

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private function render_health_row( string $key, string $issue, int $count, string $issue_fmt, string $ok_text, string $page_arg, string $severity, string $fix_url = '' ): void {
		$per       = 10;
		$detail_id = 'detail-' . $key;
		?>
		<li class="aml-health-row">
			<?php if ( $count > 0 ) : ?>
				<div class="aml-health-head">
					<span class="aml-health-status is-<?php echo esc_attr( $severity ); ?>">
						<span class="dashicons <?php echo 'danger' === $severity ? 'dashicons-dismiss' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
						<?php echo esc_html( sprintf( $issue_fmt, $count ) ); ?>
					</span>
					<span class="aml-health-actions">
						<?php if ( '' !== $fix_url ) : ?>
							<a class="aml-btn aml-btn-primary" href="<?php echo esc_url( $fix_url ); ?>">
								<?php esc_html_e( 'Fix all', 'aumviso' ); ?>
							</a>
						<?php endif; ?>
						<button type="button" class="aml-btn aumviso-toggle-detail" data-target="<?php echo esc_attr( $detail_id ); ?>">
							<?php esc_html_e( 'Show details', 'aumviso' ); ?>
						</button>
					</span>
				</div>
				<div id="<?php echo esc_attr( $detail_id ); ?>" class="aml-health-detail" style="display:none;">
					<?php
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$page = isset( $_GET[ $page_arg ] ) ? max( 1, (int) $_GET[ $page_arg ] ) : 1;
					?>
					<table class="aml-table">
						<thead><tr>
							<th><?php esc_html_e( 'Title', 'aumviso' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Type', 'aumviso' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Edit', 'aumviso' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $this->get_issue_posts( $issue, $page, $per ) as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->post_title ); ?></td>
								<td><code><?php echo esc_html( $row->post_type ); ?></code></td>
								<td><a class="aml-link" href="<?php echo esc_url( (string) get_edit_post_link( $row->ID ) ); ?>"><?php esc_html_e( 'Edit', 'aumviso' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php
					$total_pages = (int) ceil( $count / $per );
					if ( $total_pages > 1 ) :
						$base = self::tab_url( 'overview' );
						echo '<div class="aml-pagination">';
						for ( $p = 1; $p <= $total_pages; $p++ ) {
							$url = add_query_arg( $page_arg, $p, $base );
							$cls = $p === $page ? 'aml-btn aml-btn-primary' : 'aml-btn';
							echo '<a href="' . esc_url( $url ) . '#' . esc_attr( $detail_id ) . '" class="' . esc_attr( $cls ) . '">' . esc_html( (string) $p ) . '</a> ';
						}
						echo '</div>';
					endif;
					?>
				</div>
			<?php else : ?>
				<div class="aml-health-head">
					<span class="aml-health-status is-ok">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php echo esc_html( $ok_text ); ?>
					</span>
				</div>
			<?php endif; ?>
		</li>
		<?php
	}

	// ------------------------------------
	// Stats collector
	// ------------------------------------

	private function get_seo_stats(): array {
		global $wpdb;

		$published_posts = (int) ( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish'" ) );

		$missing_desc = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id AND pm.meta_key='_aumviso_seo_description'
			 WHERE p.post_type IN ('post','page') AND p.post_status='publish'
			 AND (pm.meta_value IS NULL OR pm.meta_value=%s)", ''
		) );

		$missing_og = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} th ON p.ID=th.post_id AND th.meta_key='_thumbnail_id'
			 LEFT JOIN {$wpdb->postmeta} og ON p.ID=og.post_id AND og.meta_key='_aumviso_og_image'
			 WHERE p.post_type='post' AND p.post_status='publish'
			 AND th.meta_value IS NULL AND og.meta_value IS NULL"
		);

		$noindex_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_aumviso_seo_index' AND meta_value=%s", 'noindex'
		) );

		return [
			'published_posts' => $published_posts,
			'missing_desc'    => $missing_desc,
			'missing_og'      => $missing_og,
			'noindex_count'   => $noindex_count,
			'faq_count'       => wp_count_posts( 'aumviso_faq' )->publish ?? 0,
			'glossary_count'  => wp_count_posts( 'aumviso_glossary' )->publish ?? 0,
			'guide_count'     => wp_count_posts( 'aumviso_guide' )->publish ?? 0,
		];
	}

	// ------------------------------------
	// Issue post lists (for dashboard detail tables)
	// ------------------------------------

	private function get_issue_posts( string $issue, int $page = 1, int $per_page = 10 ): array {
		global $wpdb;
		$offset = ( $page - 1 ) * $per_page;

		if ( $issue === 'missing_desc' ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_title, p.post_type FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id AND pm.meta_key='_aumviso_seo_description'
				 WHERE p.post_type IN ('post','page') AND p.post_status='publish'
				 AND (pm.meta_value IS NULL OR pm.meta_value=%s)
				 ORDER BY p.post_modified DESC LIMIT %d OFFSET %d",
				'', $per_page, $offset
			) );
		} elseif ( $issue === 'missing_og' ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_title, p.post_type FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} th ON p.ID=th.post_id AND th.meta_key='_thumbnail_id'
				 LEFT JOIN {$wpdb->postmeta} og ON p.ID=og.post_id AND og.meta_key='_aumviso_og_image'
				 WHERE p.post_type='post' AND p.post_status='publish'
				 AND th.meta_value IS NULL AND og.meta_value IS NULL
				 ORDER BY p.post_modified DESC LIMIT %d OFFSET %d",
				$per_page, $offset
			) );
		} elseif ( $issue === 'noindex' ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_title, p.post_type FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
				 WHERE pm.meta_key='_aumviso_seo_index' AND pm.meta_value=%s AND p.post_status='publish'
				 ORDER BY p.post_modified DESC LIMIT %d OFFSET %d",
				'noindex', $per_page, $offset
			) );
		} else {
			return [];
		}

		return $rows ?: [];
	}

	// ------------------------------------
	// Assets
	// ------------------------------------

	/** Cache-busting asset version: file mtime in dev, plugin version as fallback. */
	private static function asset_ver( string $rel ): string {
		$path = AUMVISO_DIR . $rel;
		return file_exists( $path ) ? (string) filemtime( $path ) : AUMVISO_VERSION;
	}

	public function enqueue_assets( string $hook ): void {
		$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_our    = ( self::SLUG === $page ) || ( '' !== $this->hook && $hook === $this->hook );
		$post_type = function_exists( 'get_current_screen' ) && get_current_screen() ? get_current_screen()->post_type : '';
		// Load editor assets wherever the meta box / SEO score appears: every
		// enabled post type (incl. ones opted in via `aumviso_enabled_post_types`,
		// e.g. AumNexCart) plus AumViso's own GEO content types.
		$editor_pts = array_merge(
			AumViso_Options::get_enabled_post_types(),
			[ 'aumviso_faq', 'aumviso_glossary', 'aumviso_guide' ]
		);
		$is_editor = in_array( $post_type, $editor_pts, true );

		if ( ! $is_our && ! $is_editor ) {
			return;
		}

		if ( $is_our ) {
			wp_enqueue_style(
				'aumviso-settings',
				AUMVISO_URL . 'admin/assets/css/settings.css',
				[],
				self::asset_ver( 'admin/assets/css/settings.css' )
			);
		}

		wp_enqueue_style(
			'aumviso-admin',
			AUMVISO_URL . 'admin/assets/css/admin.css',
			[],
			self::asset_ver( 'admin/assets/css/admin.css' )
		);

		wp_enqueue_script(
			'aumviso-admin',
			AUMVISO_URL . 'admin/assets/js/admin.js',
			[ 'jquery' ],
			self::asset_ver( 'admin/assets/js/admin.js' ),
			true
		);

		wp_localize_script( 'aumviso-admin', 'aumViso', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'aumviso_ajax' ),
			'strings'  => [
				'generating' => __( 'Generating...', 'aumviso' ),
				'error'      => __( 'Error. Please try again.', 'aumviso' ),
				'copied'     => __( 'Copied!', 'aumviso' ),
				'copy'       => __( 'Copy', 'aumviso' ),
				'copy_prompt' => __( 'Copy Prompt', 'aumviso' ),
				'remove'     => __( 'Remove', 'aumviso' ),
				'related_question' => __( 'Related question...', 'aumviso' ),
				'step'       => __( 'Step', 'aumviso' ),
				'step_name'  => __( 'Step name', 'aumviso' ),
				'step_description' => __( 'Step description', 'aumviso' ),
			],
		] );
	}
}
