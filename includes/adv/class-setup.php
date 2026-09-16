<?php
/**
 * Long-running setup checklist and first-layer business facts.
 *
 * This screen is not a one-time wizard. It keeps showing what the site still
 * needs before the automation layer has enough trusted knowledge to work well.
 */

defined( 'ABSPATH' ) || exit;

final class AumViso_Adv_Setup {

	const PROFILE_OPTION = 'aumviso_adv_business_profile';
	const SOURCES_OPTION = 'aumviso_adv_knowledge_sources';
	const NONCE_ACTION   = 'aumviso_adv_setup_save';
	const NONCE_NAME     = 'aumviso_adv_setup_nonce';

	private static ?AumViso_Adv_Setup $instance = null;

	public static function instance(): AumViso_Adv_Setup {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_init', [ $this, 'handle_save' ] );
		}
	}

	public static function profile_defaults(): array {
		return [
			'main_business'        => '',
			'service_scope'        => '',
			'certifications'       => '',
			'warranty'             => '',
			'lead_time'            => '',
			'moq'                  => '',
			'factory_capabilities' => '',
			'case_notes'           => '',
			'target_markets'       => '',
			'customer_types'       => '',
			'tone'                 => '',
			'core_terms'           => '',
		];
	}

	public static function profile(): array {
		$stored = get_option( self::PROFILE_OPTION, [] );
		return array_merge( self::profile_defaults(), is_array( $stored ) ? $stored : [] );
	}

	public static function knowledge_sources(): array {
		$stored = get_option( self::SOURCES_OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		$stored['custom_post_types'] = isset( $stored['custom_post_types'] ) && is_array( $stored['custom_post_types'] )
			? $stored['custom_post_types']
			: [];
		return $stored;
	}

	public static function custom_post_types(): array {
		$available = self::available_custom_post_types();
		$stored    = self::knowledge_sources()['custom_post_types'] ?? [];
		$out       = [];
		foreach ( $stored as $type => $cfg ) {
			if ( ! isset( $available[ $type ] ) || empty( $cfg['enabled'] ) ) {
				continue;
			}
			$out[ $type ] = $cfg;
		}
		return $out;
	}

	public static function available_custom_post_types(): array {
		$exclude = [
			'post',
			'page',
			'attachment',
			'revision',
			'nav_menu_item',
			'wp_template',
			'wp_template_part',
			'wp_block',
			'wp_font_family',
			'wp_font_face',
			'wp_global_styles',
			'wp_navigation',
			'elementor_library',
			'e-landing-page',
			'e-floating-buttons',
			'e-floating-button',
			'product',
			'aum_nexcart_product',
			'aumviso_faq',
			'aumviso_glossary',
			'aumviso_guide',
		];

		$out = [];
		foreach ( get_post_types( [ 'public' => true, 'show_ui' => true ], 'objects' ) as $name => $obj ) {
			if ( in_array( $name, $exclude, true ) ) {
				continue;
			}
			$out[ $name ] = $obj;
		}
		return $out;
	}

	public function handle_save(): void {
		if ( empty( $_POST[ self::NONCE_NAME ] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		$raw = isset( $_POST['aumviso_adv_profile'] ) && is_array( $_POST['aumviso_adv_profile'] )
			? wp_unslash( $_POST['aumviso_adv_profile'] )
			: [];

		$profile = [];
		foreach ( self::profile_defaults() as $key => $default ) {
			$profile[ $key ] = sanitize_textarea_field( $raw[ $key ] ?? $default );
		}
		update_option( self::PROFILE_OPTION, $profile );

		wp_safe_redirect( AumViso_Adv_Console::url( 'setup', [ 'updated' => 1 ] ) );
		exit;
	}

	public function render_tab(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$profile = self::profile();

		echo '<p class="aml-tab-intro">' . esc_html__( 'Follow this checklist over time. Fill verified business facts first, then let AI draft supporting knowledge, then turn on content automation.', 'aumviso' ) . '</p>';

		$this->render_map();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Setup saved.', 'aumviso' ) . '</p></div>';
		}

		$this->render_next_steps( $profile );

		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$this->render_brand_guidance();
		$this->render_product_guidance();
		$this->render_profile_fields( $profile );
		$this->render_glossary_guidance( $profile );

		echo '<p class="aml-actions-bar"><button type="submit" class="aml-btn aml-btn-primary">' . esc_html__( 'Save setup', 'aumviso' ) . '</button></p>';
		echo '</form>';
	}

	private function render_next_steps( array $profile ): void {
		$brand_status = $this->brand_status();
		$required     = [ 'main_business', 'service_scope', 'target_markets', 'customer_types' ];
		$filled   = 0;
		foreach ( $required as $key ) {
			if ( '' !== trim( (string) ( $profile[ $key ] ?? '' ) ) ) {
				++$filled;
			}
		}
		$profile_done = count( $required ) === $filled;
		$ai_done      = class_exists( 'AumViso_Adv_Factory' ) && AumViso_Adv_Factory::ai_configured();
		$products     = $this->product_count();
		$faq_count    = post_type_exists( 'aumviso_faq' ) ? (int) ( wp_count_posts( 'aumviso_faq' )->publish ?? 0 ) : 0;
		$guide_count  = post_type_exists( 'aumviso_guide' ) ? (int) ( wp_count_posts( 'aumviso_guide' )->publish ?? 0 ) : 0;
		$gloss_count  = post_type_exists( 'aumviso_glossary' ) ? (int) ( wp_count_posts( 'aumviso_glossary' )->publish ?? 0 ) : 0;

		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-yes-alt"></span>' . esc_html__( 'Recommended next steps', 'aumviso' ) . '</h2>';
		echo '<div class="avp-stat-grid">';
		/* translators: 1: number of fields already filled in, 2: total number of fields. */
		$this->step_card( __( 'Brand entity', 'aumviso' ), sprintf( __( '%1$d/%2$d filled', 'aumviso' ), $brand_status['filled'], $brand_status['total'] ), $brand_status['filled'] >= $brand_status['total'] );
		/* translators: 1: number of fields already filled in, 2: total number of fields. */
		$this->step_card( __( 'Business facts', 'aumviso' ), $profile_done ? __( 'Ready', 'aumviso' ) : sprintf( __( '%1$d/%2$d filled', 'aumviso' ), $filled, count( $required ) ), $profile_done );
		$this->step_card( __( 'AI connection', 'aumviso' ), $ai_done ? __( 'Ready', 'aumviso' ) : __( 'Missing key', 'aumviso' ), $ai_done );
		$this->step_card( __( 'Products (optional)', 'aumviso' ), (string) $products, true );
		$this->step_card( __( 'Glossary terms', 'aumviso' ), (string) $gloss_count, $gloss_count > 0 || '' !== trim( (string) ( $profile['core_terms'] ?? '' ) ) );
		$this->step_card( __( 'FAQ / Guides', 'aumviso' ), sprintf( '%d / %d', $faq_count, $guide_count ), ( $faq_count + $guide_count ) > 0 );
		echo '</div>';

		echo '<p class="aml-actions-bar" style="margin-top:14px">';
		printf( '<a class="aml-btn" href="%s">%s</a> ', esc_url( AumViso_Adv_Console::url( 'brand' ) ), esc_html__( 'Open Brand Entity', 'aumviso' ) );
		printf( '<a class="aml-btn" href="%s">%s</a> ', esc_url( add_query_arg( [ 'page' => 'aumviso', 'tab' => 'ai' ], admin_url( 'admin.php' ) ) ), esc_html__( 'Configure AI', 'aumviso' ) );
		printf( '<a class="aml-btn" href="%s">%s</a> ', esc_url( AumViso_Adv_Console::url( 'factory' ) ), esc_html__( 'Build knowledge drafts', 'aumviso' ) );
		printf( '<a class="aml-btn" href="%s">%s</a>', esc_url( AumViso_Adv_Console::url( 'auto' ) ), esc_html__( 'Open automation', 'aumviso' ) );
		echo '</p>';
		echo '</div>';
	}

	private function brand_status(): array {
		if ( class_exists( 'AumViso_Adv_Brand' ) ) {
			return AumViso_Adv_Brand::completion();
		}
		$checks = [
			get_option( 'aumviso_schema_org_name', get_bloginfo( 'name' ) ),
			get_option( 'aumviso_schema_org_url', home_url() ),
		];
		$filled = 0;
		foreach ( $checks as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				++$filled;
			}
		}
		return [ 'filled' => $filled, 'total' => count( $checks ) ];
	}

	/**
	 * How the tabs fit together.
	 *
	 * 🔴 The gap this fills is **not** "what does this tab do" — every tab already says that in a line of
	 * its own. It is that the tabs look like thirteen independent tools when four of them are a **chain**:
	 * Brand Entity holds the facts, the Knowledge Builder drafts from those facts, Automation publishes on
	 * a schedule, and the GEO Console reports on the result. Someone who opens the Knowledge Builder first
	 * gets thin drafts and concludes the AI is poor, when the real answer is that nothing had been told to
	 * it yet.
	 *
	 * Deliberately not a guided tour: those are built once, skipped by most people, and go stale the moment
	 * a tab is renamed. A short map earns its place every time the screen is opened.
	 */
	private function render_map(): void {
		$steps = [
			[
				'tab'   => 'brand',
				'title' => __( 'Brand Entity', 'aumviso' ),
				'text'  => __( 'Who the business is: name, address, contact, profiles. Everything below reads from here, and schema publishes it.', 'aumviso' ),
			],
			[
				'tab'   => 'factory',
				'title' => __( 'Knowledge Builder', 'aumviso' ),
				'text'  => __( 'Turns those facts into FAQ and Guide entries — the material AI assistants quote when they answer about you.', 'aumviso' ),
			],
			[
				'tab'   => 'auto',
				'title' => __( 'Automation', 'aumviso' ),
				'text'  => __( 'Keeps writing on a schedule once the knowledge base is worth drawing from. Leave it off until then.', 'aumviso' ),
			],
			[
				'tab'   => 'geo',
				'title' => __( 'GEO Console', 'aumviso' ),
				'text'  => __( 'What it all adds up to, scored across the whole site.', 'aumviso' ),
			],
		];
		?>
		<div class="aml-card aumviso-map">
			<h2 class="aml-card-h">
				<span class="dashicons dashicons-networking" aria-hidden="true"></span>
				<?php esc_html_e( 'How these fit together', 'aumviso' ); ?>
			</h2>
			<p class="aml-card-desc">
				<?php esc_html_e( 'These four are one chain, in this order. The rest of the tabs are settings a template has already filled in for you.', 'aumviso' ); ?>
			</p>
			<ol class="aumviso-map-list">
				<?php foreach ( $steps as $i => $step ) : ?>
					<li>
						<span class="aumviso-map-num"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
						<span class="aumviso-map-body">
							<a href="<?php echo esc_url( AumViso_Adv_Console::url( $step['tab'] ) ); ?>"><?php echo esc_html( $step['title'] ); ?></a>
							<span><?php echo esc_html( $step['text'] ); ?></span>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	private function step_card( string $label, string $value, bool $ok ): void {
		printf(
			'<div class="avp-stat"><div class="avp-stat-num %1$s">%2$s</div><div class="avp-stat-label">%3$s</div></div>',
			$ok ? 'is-ok' : 'is-warn',
			esc_html( $value ),
			esc_html( $label )
		);
	}

	private function product_count(): int {
		if ( class_exists( 'AumViso_Product_Registry' ) ) {
			return (int) AumViso_Product_Registry::instance()->total_count();
		}
		return 0;
	}

	private function render_profile_fields( array $profile ): void {
		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-clipboard"></span>' . esc_html__( 'Business facts', 'aumviso' ) . '</h2>';
		echo '<p class="aml-card-desc">' . esc_html__( 'Add operational facts that are not company identity fields. AI may use them as trusted source material, but it should not replace or invent them.', 'aumviso' ) . '</p>';

		$this->textarea( 'main_business', __( 'Main business', 'aumviso' ), $profile['main_business'] );
		$this->textarea( 'service_scope', __( 'Products / services scope', 'aumviso' ), $profile['service_scope'] );

		echo '<div class="aml-field-grid">';
		$this->textarea( 'certifications', __( 'Certifications', 'aumviso' ), $profile['certifications'] );
		$this->textarea( 'warranty', __( 'Warranty', 'aumviso' ), $profile['warranty'] );
		$this->textarea( 'lead_time', __( 'Lead time', 'aumviso' ), $profile['lead_time'] );
		$this->textarea( 'moq', __( 'MOQ', 'aumviso' ), $profile['moq'] );
		echo '</div>';

		$this->textarea( 'factory_capabilities', __( 'Factory capabilities', 'aumviso' ), $profile['factory_capabilities'] );
		$this->textarea( 'case_notes', __( 'Case studies / proof points', 'aumviso' ), $profile['case_notes'] );

		echo '<div class="aml-field-grid">';
		$this->textarea( 'target_markets', __( 'Target markets', 'aumviso' ), $profile['target_markets'] );
		$this->textarea( 'customer_types', __( 'Customer types', 'aumviso' ), $profile['customer_types'] );
		$this->textarea( 'tone', __( 'Language and tone', 'aumviso' ), $profile['tone'] );
		$this->textarea( 'core_terms', __( 'Core glossary terms', 'aumviso' ), $profile['core_terms'], __( 'One term per line. Use this as the confirmed term checklist, then add important terms to Glossary manually.', 'aumviso' ) );
		echo '</div>';
		echo '</div>';
	}

	private function render_product_guidance(): void {
		if ( ! post_type_exists( 'aum_nexcart_product' ) && ! post_type_exists( 'product' ) ) {
			return;
		}
		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-products"></span>' . esc_html__( 'Products are first-layer knowledge', 'aumviso' ) . '</h2>';
		echo '<p class="aml-card-desc">' . esc_html__( 'If this site sells or presents products, publish product records manually in NexCart or WooCommerce. AI can read published product facts later, but it should not create or invent products.', 'aumviso' ) . '</p>';
		echo '<div class="avp-stat-grid">';
		$this->step_card( __( 'Published products', 'aumviso' ), (string) $this->product_count(), $this->product_count() > 0 );
		echo '</div>';
		echo '<p class="aml-actions-bar" style="margin-top:14px">';
		if ( post_type_exists( 'aum_nexcart_product' ) ) {
			printf( '<a class="aml-btn" href="%s">%s</a> ', esc_url( admin_url( 'edit.php?post_type=aum_nexcart_product' ) ), esc_html__( 'Manage NexCart products', 'aumviso' ) );
		}
		if ( post_type_exists( 'product' ) ) {
			printf( '<a class="aml-btn" href="%s">%s</a>', esc_url( admin_url( 'edit.php?post_type=product' ) ), esc_html__( 'Manage Woo products', 'aumviso' ) );
		}
		echo '</p>';
		echo '</div>';
	}

	private function render_glossary_guidance( array $profile ): void {
		$count = post_type_exists( 'aumviso_glossary' ) ? (int) ( wp_count_posts( 'aumviso_glossary' )->publish ?? 0 ) : 0;
		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-book"></span>' . esc_html__( 'Glossary is first-layer knowledge', 'aumviso' ) . '</h2>';
		echo '<p class="aml-card-desc">' . esc_html__( 'Confirm the important terms yourself first. Glossary entries are trusted source facts for FAQ, Guides and Automation, so they should be manually reviewed and published.', 'aumviso' ) . '</p>';
		echo '<div class="avp-stat-grid">';
		$this->step_card( __( 'Published glossary terms', 'aumviso' ), (string) $count, $count > 0 );
		$this->step_card( __( 'Core term checklist', 'aumviso' ), '' !== trim( (string) ( $profile['core_terms'] ?? '' ) ) ? __( 'Filled', 'aumviso' ) : __( 'Missing', 'aumviso' ), '' !== trim( (string) ( $profile['core_terms'] ?? '' ) ) );
		echo '</div>';
		if ( post_type_exists( 'aumviso_glossary' ) ) {
			echo '<p class="aml-actions-bar" style="margin-top:14px">';
			printf( '<a class="aml-btn aml-btn-primary" href="%s">%s</a> ', esc_url( admin_url( 'post-new.php?post_type=aumviso_glossary' ) ), esc_html__( 'Add glossary term', 'aumviso' ) );
			printf( '<a class="aml-btn" href="%s">%s</a>', esc_url( admin_url( 'edit.php?post_type=aumviso_glossary' ) ), esc_html__( 'Manage Glossary', 'aumviso' ) );
			echo '</p>';
		}
		echo '</div>';
	}

	private function render_brand_guidance(): void {
		$status = $this->brand_status();
		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-store"></span>' . esc_html__( 'Company identity lives in Brand Entity', 'aumviso' ) . '</h2>';
		echo '<p class="aml-card-desc">' . esc_html__( 'Company name, brand/entity description, founding date, location and contact details should be maintained in Brand Entity so schema, GEO and AI grounding use the same source of truth.', 'aumviso' ) . '</p>';
		echo '<div class="avp-stat-grid">';
		/* translators: 1: number of fields already filled in, 2: total number of fields. */
		$this->step_card( __( 'Brand entity', 'aumviso' ), sprintf( __( '%1$d/%2$d filled', 'aumviso' ), $status['filled'], $status['total'] ), $status['filled'] >= $status['total'] );
		echo '</div>';
		echo '<p class="aml-actions-bar" style="margin-top:14px">';
		printf( '<a class="aml-btn aml-btn-primary" href="%s"><span class="dashicons dashicons-store"></span> %s</a>', esc_url( AumViso_Adv_Console::url( 'brand' ) ), esc_html__( 'Open Brand Entity', 'aumviso' ) );
		echo '</p>';
		echo '</div>';
	}

	private function text( string $key, string $label, string $value ): void {
		printf(
			'<div class="aml-field"><label class="aml-label" for="avp-setup-%1$s">%2$s</label><input type="text" id="avp-setup-%1$s" name="aumviso_adv_profile[%1$s]" value="%3$s" class="large-text"></div>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $value )
		);
	}

	private function textarea( string $key, string $label, string $value, string $hint = '' ): void {
		printf(
			'<div class="aml-field"><label class="aml-label" for="avp-setup-%1$s">%2$s</label><textarea id="avp-setup-%1$s" name="aumviso_adv_profile[%1$s]" rows="3" class="large-text">%3$s</textarea>%4$s</div>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_textarea( $value ),
			'' !== $hint ? '<p class="aml-field-hint">' . esc_html( $hint ) . '</p>' : ''
		);
	}
}
