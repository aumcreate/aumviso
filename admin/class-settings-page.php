<?php
defined( 'ABSPATH' ) || exit;

class AumViso_SettingsPage {

	private static ?AumViso_SettingsPage $instance = null;

	public static function instance(): AumViso_SettingsPage {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'handle_save' ] );
	}

	// ------------------------------------
	// Save handler — each section writes only its own option keys, so saving
	// one tab never resets another.
	// ------------------------------------

	public function handle_save(): void {
		if ( ! isset( $_POST['aumviso_settings_nonce'] ) ) return;
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aumviso_settings_nonce'] ) ), 'aumviso_settings_save' ) ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;

		$page = sanitize_key( wp_unslash( $_POST['aumviso_settings_page'] ?? '' ) );

		match ( $page ) {
			'meta'           => $this->save_meta_settings(),
			'schema'         => $this->save_schema_settings(),
			'sitemap'        => $this->save_sitemap_settings(),
			'robots'         => $this->save_robots_settings(),
			/*
			 * The five technical sections share one form and one Save button, so one submit carries all of
			 * their fields at once and every section's saver runs.
			 *
			 * ⚠️ Order does not matter and overlap does not exist — each saver writes only its own option
			 * keys, which is the property that made merging the forms safe in the first place. Internal
			 * Links is absent on purpose: it saves over AJAX and has no fields in this form.
			 */
			'technical'      => array_map(
				fn( $m ) => $this->$m(),
				[ 'save_meta_settings', 'save_schema_settings', 'save_sitemap_settings', 'save_robots_settings' ]
			),
			'internal_links' => $this->save_internal_links_settings(),
			'ai'             => $this->save_ai_settings(),
			'settings'       => $this->save_general_settings(),
			default          => null,
		};

		wp_redirect( add_query_arg( 'saved', '1', wp_get_referer() ) );
		exit;
	}

	// ------------------------------------
	// Render helpers
	// ------------------------------------

	/**
	 * ⚠️ Prints at most once per request.
	 *
	 * Each section renders its own notice, which was right while each had a page to itself. Now that the
	 * five technical sections share one tab, an unguarded version would stack five identical "Settings
	 * saved." banners after a single save.
	 */
	private function saved_notice(): void {
		static $shown = false;
		if ( $shown ) {
			return;
		}
		$shown = true;

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'aumviso' ) . '</p></div>';
		}
	}

	/**
	 * True while the five technical sections are being rendered inside one shared form.
	 *
	 * Each section still knows how to stand alone — `render_meta()` called by itself opens and closes its
	 * own form exactly as before. This only silences that when an outer form is already open, which is what
	 * lets one Save button cover the whole page without rewriting five render methods.
	 */
	private $merged = false;

	public function set_merged( bool $on ): void {
		$this->merged = $on;
	}

	private function form_open( string $page ): void {
		if ( $this->merged ) {
			return;
		}
		echo '<form method="post">';
		wp_nonce_field( 'aumviso_settings_save', 'aumviso_settings_nonce' );
		echo '<input type="hidden" name="aumviso_settings_page" value="' . esc_attr( $page ) . '">';
	}

	private function form_close(): void {
		if ( $this->merged ) {
			return;
		}
		echo '<p class="aml-actions-bar"><button type="submit" class="aml-btn aml-btn-primary">' . esc_html__( 'Save Settings', 'aumviso' ) . '</button></p>';
		echo '</form>';
	}

	/** A single on/off toggle switch row. */
	private function switch_row( string $name, string $value, bool $checked, string $label, string $code = '' ): void {
		?>
		<label class="aml-switch-row">
			<span class="aml-switch">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $checked ); ?> />
				<span class="aml-track" aria-hidden="true"></span>
			</span>
			<span class="aml-switch-label"><?php echo esc_html( $label ); ?><?php echo '' !== $code ? ' <code>' . esc_html( $code ) . '</code>' : ''; ?></span>
		</label>
		<?php
	}

	// ------------------------------------
	// Meta Settings
	// ------------------------------------

	public function render_meta(): void {
		$enabled_pts = AumViso_Options::get_enabled_post_types();
		$all_pts     = get_post_types( [ 'public' => true ], 'objects' );

		$this->saved_notice();
		?>
		<p class="aml-tab-intro"><?php esc_html_e( 'Title and description templates, the OG fallback image, and which content gets SEO fields.', 'aumviso' ); ?></p>
		<?php
		$this->form_open( 'meta' );
		?>
		<div class="aml-card">
			<h2 class="aml-card-h"><span class="dashicons dashicons-editor-textcolor" aria-hidden="true"></span> <?php esc_html_e( 'Templates', 'aumviso' ); ?></h2>

			<div class="aml-field">
				<label class="aml-label" for="aumviso_title_template"><?php esc_html_e( 'SEO title template', 'aumviso' ); ?></label>
				<input type="text" id="aumviso_title_template" name="aumviso_title_template" value="<?php echo esc_attr( AumViso_Options::title_template() ); ?>">
				<p class="aml-field-hint"><?php esc_html_e( 'Variables:', 'aumviso' ); ?> <code>{post_title}</code> <code>{site_name}</code> <code>{category}</code> <code>{author}</code> <code>{year}</code> <code>{sep}</code></p>
			</div>

			<div class="aml-field">
				<label class="aml-label" for="aumviso_desc_template"><?php esc_html_e( 'Meta description template', 'aumviso' ); ?></label>
				<input type="text" id="aumviso_desc_template" name="aumviso_desc_template" value="<?php echo esc_attr( AumViso_Options::desc_template() ); ?>">
				<p class="aml-field-hint"><?php esc_html_e( 'Variables:', 'aumviso' ); ?> <code>{post_excerpt}</code></p>
			</div>

			<div class="aml-field">
				<label class="aml-label" for="aumviso_og_image_fallback"><?php esc_html_e( 'OG fallback image', 'aumviso' ); ?></label>
				<input type="url" id="aumviso_og_image_fallback" name="aumviso_og_image_fallback" value="<?php echo esc_attr( AumViso_Options::og_fallback_image() ); ?>" placeholder="https://example.com/social-image.jpg">
				<p class="aml-field-hint"><?php esc_html_e( 'Used when no featured image or OG image is set.', 'aumviso' ); ?></p>
			</div>
		</div>

		<div class="aml-card">
			<h2 class="aml-card-h"><span class="dashicons dashicons-screenoptions" aria-hidden="true"></span> <?php esc_html_e( 'Enable SEO fields for', 'aumviso' ); ?></h2>
			<p class="aml-card-desc"><?php esc_html_e( 'Check a post type to enable, then choose which levels get SEO fields.', 'aumviso' ); ?></p>

			<table class="aml-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post type', 'aumviso' ); ?></th>
						<th class="aml-col-center"><?php esc_html_e( 'Single', 'aumviso' ); ?></th>
						<th class="aml-col-center"><?php esc_html_e( 'Archive', 'aumviso' ); ?></th>
						<th class="aml-col-center"><?php esc_html_e( 'Tax archive', 'aumviso' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $all_pts as $pt ) :
					$pt_enabled = in_array( $pt->name, $enabled_pts, true );
					$levels     = AumViso_Options::get_pt_levels( $pt->name );
				?>
					<tr>
						<td>
							<label>
								<input type="checkbox" name="aumviso_enabled_post_types[]"
									value="<?php echo esc_attr( $pt->name ); ?>"
									class="aumviso-pt-toggle"
									data-pt="<?php echo esc_attr( $pt->name ); ?>"
									<?php checked( $pt_enabled ); ?>>
								<strong><?php echo esc_html( $pt->labels->name ); ?></strong>
								<code><?php echo esc_html( $pt->name ); ?></code>
							</label>
						</td>
						<?php foreach ( [ 'singular', 'archive', 'tax_archive' ] as $level ) : ?>
						<td class="aml-col-center">
							<input type="checkbox"
								name="aumviso_pt_levels[<?php echo esc_attr( $pt->name ); ?>][]"
								value="<?php echo esc_attr( $level ); ?>"
								class="aumviso-level-check aumviso-level-<?php echo esc_attr( $pt->name ); ?>"
								<?php checked( in_array( $level, $levels, true ) ); ?>
								<?php echo $pt_enabled ? '' : 'disabled'; ?>>
						</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

		</div>
		<?php
		$this->form_close();
	}

	private function save_meta_settings(): void {
		update_option( 'aumviso_title_template', sanitize_text_field( wp_unslash( $_POST['aumviso_title_template'] ?? '' ) ) );
		update_option( 'aumviso_desc_template',  sanitize_text_field( wp_unslash( $_POST['aumviso_desc_template'] ?? '' ) ) );
		update_option( 'aumviso_og_image_fallback', esc_url_raw( wp_unslash( $_POST['aumviso_og_image_fallback'] ?? '' ) ) );
		update_option( 'aumviso_enabled_post_types', array_map( 'sanitize_key', (array) wp_unslash( $_POST['aumviso_enabled_post_types'] ?? [] ) ) );
		update_option( 'aumviso_enabled_taxonomies', array_map( 'sanitize_key', (array) wp_unslash( $_POST['aumviso_enabled_taxonomies'] ?? [] ) ) );
		// Save per-CPT level settings
		// All registered CPTs must be traversed, and those that do not appear in POST (uncheck all) must be explicitly saved as an empty array
		$pt_levels_post = isset( $_POST['aumviso_pt_levels'] ) ? (array) wp_unslash( $_POST['aumviso_pt_levels'] ) : [];
		$all_post_types = get_post_types( [ 'public' => true ], 'names' );
		foreach ( $all_post_types as $pt_name ) {
			$pt_name = sanitize_key( $pt_name );
			if ( isset( $pt_levels_post[ $pt_name ] ) ) {
				$levels = array_map( 'sanitize_key', (array) $pt_levels_post[ $pt_name ] );
			} else {
				$levels = []; // Explicitly save empty array when unchecking all options
			}
			AumViso_Options::set_pt_levels( $pt_name, $levels );
		}
	}

	// ------------------------------------
	// Schema
	// ------------------------------------

	public function render_schema(): void {
		$org = AumViso_Options::schema_org();
		$this->saved_notice();
		?>
		<p class="aml-tab-intro"><?php esc_html_e( 'Organization / business structured data (JSON-LD) and social profiles.', 'aumviso' ); ?></p>
		<?php
		$this->form_open( 'schema' );
		?>
		<div class="aml-card">
			<h2 class="aml-card-h"><span class="dashicons dashicons-building" aria-hidden="true"></span> <?php esc_html_e( 'Organization', 'aumviso' ); ?></h2>

			<div class="aml-field aml-field-narrow">
				<label class="aml-label" for="aumviso_schema_org_type"><?php esc_html_e( 'Organization type', 'aumviso' ); ?></label>
				<select id="aumviso_schema_org_type" name="aumviso_schema_org_type">
					<?php foreach ( [ 'Organization', 'LocalBusiness', 'Person', 'Corporation' ] as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $org['type'], $t ); ?>><?php echo esc_html( $t ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="aml-field">
				<label class="aml-label" for="aumviso_schema_org_name"><?php esc_html_e( 'Organization name', 'aumviso' ); ?></label>
				<input type="text" id="aumviso_schema_org_name" name="aumviso_schema_org_name" value="<?php echo esc_attr( $org['name'] ); ?>">
			</div>

			<div class="aml-field aml-field-narrow">
				<label class="aml-label" for="aumviso_schema_org_url"><?php esc_html_e( 'Organization URL', 'aumviso' ); ?></label>
				<input type="url" id="aumviso_schema_org_url" name="aumviso_schema_org_url" value="<?php echo esc_attr( $org['url'] ); ?>">
			</div>

			<div class="aml-field">
				<label class="aml-label" for="aumviso_schema_org_logo"><?php esc_html_e( 'Logo URL', 'aumviso' ); ?></label>
				<input type="url" id="aumviso_schema_org_logo" name="aumviso_schema_org_logo" value="<?php echo esc_attr( $org['logo'] ); ?>">
			</div>

			<div class="aml-field aml-field-narrow">
				<label class="aml-label" for="aumviso_social_twitter"><?php esc_html_e( 'Twitter / X username', 'aumviso' ); ?></label>
				<input type="text" id="aumviso_social_twitter" name="aumviso_social_twitter" value="<?php echo esc_attr( AumViso_Options::get( 'aumviso_social_twitter', '' ) ); ?>" placeholder="@handle">
			</div>

			<div class="aml-field aml-field-narrow">
				<label class="aml-label" for="aumviso_social_facebook"><?php esc_html_e( 'Facebook page', 'aumviso' ); ?></label>
				<input type="text" id="aumviso_social_facebook" name="aumviso_social_facebook" value="<?php echo esc_attr( AumViso_Options::get( 'aumviso_social_facebook', '' ) ); ?>" placeholder="pagename">
			</div>
		</div>
		<?php
		$this->render_local_business_section();
		$this->form_close();
	}

	private function save_schema_settings(): void {
		update_option( 'aumviso_schema_org_type',  sanitize_text_field( wp_unslash( $_POST['aumviso_schema_org_type'] ?? '' ) ) );
		update_option( 'aumviso_schema_org_name',  sanitize_text_field( wp_unslash( $_POST['aumviso_schema_org_name'] ?? '' ) ) );
		update_option( 'aumviso_schema_org_url',   esc_url_raw( wp_unslash( $_POST['aumviso_schema_org_url'] ?? '' ) ) );
		update_option( 'aumviso_schema_org_logo',  esc_url_raw( wp_unslash( $_POST['aumviso_schema_org_logo'] ?? '' ) ) );
		update_option( 'aumviso_social_twitter',   sanitize_text_field( wp_unslash( $_POST['aumviso_social_twitter'] ?? '' ) ) );
		update_option( 'aumviso_social_facebook',  sanitize_text_field( wp_unslash( $_POST['aumviso_social_facebook'] ?? '' ) ) );
		/*
		 * LocalBusiness — only when the card was rendered. See the marker in
		 * `render_local_business_section()` for why a blank field and an absent field must not mean the
		 * same thing here.
		 */
		if ( empty( $_POST['aumviso_lb_present'] ) ) {
			return;
		}

		if ( isset( $_POST['aumviso_lb_address'] ) ) {
			$addr = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['aumviso_lb_address'] ) );
			update_option( 'aumviso_lb_address', $addr );
		}
		update_option( 'aumviso_lb_phone',       sanitize_text_field( wp_unslash( $_POST['aumviso_lb_phone'] ?? '' ) ) );
		update_option( 'aumviso_lb_email',       sanitize_email( wp_unslash( $_POST['aumviso_lb_email'] ?? '' ) ) );
		update_option( 'aumviso_lb_price_range', sanitize_text_field( wp_unslash( $_POST['aumviso_lb_price_range'] ?? '' ) ) );
		update_option( 'aumviso_lb_geo_lat',     sanitize_text_field( wp_unslash( $_POST['aumviso_lb_geo_lat'] ?? '' ) ) );
		update_option( 'aumviso_lb_geo_lng',     sanitize_text_field( wp_unslash( $_POST['aumviso_lb_geo_lng'] ?? '' ) ) );
	}

	// ------------------------------------
	// LocalBusiness Settings (additional card within the schema tab)
	// ------------------------------------

	private function render_local_business_section(): void {
		$lb_types = [ 'LocalBusiness', 'Restaurant', 'Store', 'Hotel', 'MedicalBusiness', 'LegalService', 'FinancialService', 'AutomotiveBusiness' ];
		$org_type = AumViso_Options::get( 'aumviso_schema_org_type', 'Organization' );
		if ( ! in_array( $org_type, $lb_types, true ) ) return;

		/*
		 * Marks that these fields were actually on screen.
		 *
		 * 🔴 Without it the saver cannot tell "the shop has no phone number" from "the phone field was never
		 * rendered", and treats both as an instruction to clear. On a site whose organisation type is plain
		 * `Organization` this whole card is skipped, so saving Schema settings **wiped the stored phone,
		 * email, price range and coordinates** — silently, and with no way to notice until the LocalBusiness
		 * schema stopped carrying them.
		 *
		 * It became much easier to hit once the five technical tabs merged: one Save now runs every
		 * section's saver, so changing a title template was enough to trigger it.
		 */
		echo '<input type="hidden" name="aumviso_lb_present" value="1">';

		$address = (array) AumViso_Options::get( 'aumviso_lb_address', [] );
		$phone   = AumViso_Options::get( 'aumviso_lb_phone', '' );
		$email   = AumViso_Options::get( 'aumviso_lb_email', '' );
		$price   = AumViso_Options::get( 'aumviso_lb_price_range', '' );
		$geo_lat = AumViso_Options::get( 'aumviso_lb_geo_lat', '' );
		$geo_lng = AumViso_Options::get( 'aumviso_lb_geo_lng', '' );
		?>
		<div class="aml-card">
			<h2 class="aml-card-h"><span class="dashicons dashicons-store" aria-hidden="true"></span> <?php esc_html_e( 'Local business details', 'aumviso' ); ?></h2>

			<div class="aml-field">
				<label class="aml-label" for="aumviso_lb_street"><?php esc_html_e( 'Street address', 'aumviso' ); ?></label>
				<input type="text" id="aumviso_lb_street" name="aumviso_lb_address[street]" value="<?php echo esc_attr( $address['street'] ?? '' ); ?>">
			</div>
			<div class="aml-field aml-field-narrow">
				<label class="aml-label" for="aumviso_lb_city"><?php esc_html_e( 'City', 'aumviso' ); ?></label>
				<input type="text" id="aumviso_lb_city" name="aumviso_lb_address[city]" value="<?php echo esc_attr( $address['city'] ?? '' ); ?>">
			</div>
			<div class="aml-field aml-field-narrow">
				<label class="aml-label" for="aumviso_lb_region"><?php esc_html_e( 'State / region', 'aumviso' ); ?></label>
				<input type="text" id="aumviso_lb_region" name="aumviso_lb_address[region]" value="<?php echo esc_attr( $address['region'] ?? '' ); ?>">
			</div>
			<div class="aml-field aml-field-narrow">
				<label class="aml-label" for="aumviso_lb_postcode"><?php esc_html_e( 'Postal code', 'aumviso' ); ?></label>
				<input type="text" id="aumviso_lb_postcode" name="aumviso_lb_address[postcode]" value="<?php echo esc_attr( $address['postcode'] ?? '' ); ?>">
			</div>
			<div class="aml-field aml-field-narrow">
				<label class="aml-label" for="aumviso_lb_country"><?php esc_html_e( 'Country code', 'aumviso' ); ?></label>
				<input type="text" id="aumviso_lb_country" name="aumviso_lb_address[country]" value="<?php echo esc_attr( $address['country'] ?? '' ); ?>" placeholder="US">
			</div>
			<div class="aml-field aml-field-narrow">
				<label class="aml-label" for="aumviso_lb_phone"><?php esc_html_e( 'Phone', 'aumviso' ); ?></label>
				<input type="text" id="aumviso_lb_phone" name="aumviso_lb_phone" value="<?php echo esc_attr( $phone ); ?>" placeholder="+1-555-000-0000">
			</div>
			<div class="aml-field aml-field-narrow">
				<label class="aml-label" for="aumviso_lb_email"><?php esc_html_e( 'Email', 'aumviso' ); ?></label>
				<input type="email" id="aumviso_lb_email" name="aumviso_lb_email" value="<?php echo esc_attr( $email ); ?>">
			</div>
			<div class="aml-field aml-field-narrow">
				<label class="aml-label" for="aumviso_lb_price_range"><?php esc_html_e( 'Price range', 'aumviso' ); ?></label>
				<input type="text" id="aumviso_lb_price_range" name="aumviso_lb_price_range" value="<?php echo esc_attr( $price ); ?>" placeholder="$$ or €€€">
				<p class="aml-field-hint"><?php esc_html_e( 'Use $ signs ($ = budget, $$$$ = luxury).', 'aumviso' ); ?></p>
			</div>
			<div class="aml-field">
				<label class="aml-label"><?php esc_html_e( 'Geo coordinates', 'aumviso' ); ?></label>
				<div class="aml-field-inline">
					<input type="text" name="aumviso_lb_geo_lat" value="<?php echo esc_attr( $geo_lat ); ?>" placeholder="<?php esc_attr_e( 'Latitude e.g. 37.7749', 'aumviso' ); ?>">
					<input type="text" name="aumviso_lb_geo_lng" value="<?php echo esc_attr( $geo_lng ); ?>" placeholder="<?php esc_attr_e( 'Longitude e.g. -122.4194', 'aumviso' ); ?>">
				</div>
			</div>
		</div>
		<?php
	}

	// ------------------------------------
	// Sitemap
	// ------------------------------------

	public function render_sitemap(): void {
		$enabled_pts  = (array) AumViso_Options::get( 'aumviso_sitemap_post_types', [] );
		$enabled_taxs = (array) AumViso_Options::get( 'aumviso_sitemap_taxonomies', [] );
		$all_pts      = get_post_types( [ 'public' => true ], 'objects' );
		$all_taxs     = get_taxonomies( [ 'public' => true ], 'objects' );
		$enabled      = (bool) AumViso_Options::get( 'aumviso_sitemap_enabled', true );

		$this->saved_notice();
		?>
		<p class="aml-tab-intro"><?php esc_html_e( 'Generate an XML sitemap and choose what it includes.', 'aumviso' ); ?></p>
		<?php
		$this->form_open( 'sitemap' );
		?>
		<div class="aml-card">
			<h2 class="aml-card-h"><span class="dashicons dashicons-networking" aria-hidden="true"></span> <?php esc_html_e( 'XML sitemap', 'aumviso' ); ?></h2>
			<?php $this->switch_row( 'aumviso_sitemap_enabled', '1', $enabled, __( 'Generate XML sitemap', 'aumviso' ) ); ?>
			<?php if ( $enabled ) : ?>
				<p style="margin-top:12px"><a class="aml-readonly-link" href="<?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( home_url( '/sitemap.xml' ) ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a></p>
			<?php endif; ?>
		</div>

		<div class="aml-card">
			<h2 class="aml-card-h"><span class="dashicons dashicons-screenoptions" aria-hidden="true"></span> <?php esc_html_e( 'Included content', 'aumviso' ); ?></h2>

			<h3 class="aml-group-label"><?php esc_html_e( 'Post types', 'aumviso' ); ?></h3>
			<div class="aml-toggle-grid">
				<?php foreach ( $all_pts as $pt ) : ?>
					<?php $this->switch_row( 'aumviso_sitemap_post_types[]', $pt->name, in_array( $pt->name, $enabled_pts, true ), $pt->labels->name ); ?>
				<?php endforeach; ?>
			</div>

			<h3 class="aml-group-label"><?php esc_html_e( 'Taxonomies', 'aumviso' ); ?></h3>
			<div class="aml-toggle-grid">
				<?php foreach ( $all_taxs as $tax ) : ?>
					<?php $this->switch_row( 'aumviso_sitemap_taxonomies[]', $tax->name, in_array( $tax->name, $enabled_taxs, true ), $tax->labels->name ); ?>
				<?php endforeach; ?>
			</div>

			<h3 class="aml-group-label"><?php esc_html_e( 'Images', 'aumviso' ); ?></h3>
			<?php $this->switch_row( 'aumviso_sitemap_images', '1', (bool) AumViso_Options::get( 'aumviso_sitemap_images', true ), __( 'Include images in sitemap', 'aumviso' ) ); ?>
		</div>
		<?php
		$this->form_close();
	}

	private function save_sitemap_settings(): void {
		update_option( 'aumviso_sitemap_enabled',    ! empty( $_POST['aumviso_sitemap_enabled'] ) );
		update_option( 'aumviso_sitemap_post_types', array_map( 'sanitize_key', (array) wp_unslash( $_POST['aumviso_sitemap_post_types'] ?? [] ) ) );
		update_option( 'aumviso_sitemap_taxonomies', array_map( 'sanitize_key', (array) wp_unslash( $_POST['aumviso_sitemap_taxonomies'] ?? [] ) ) );
		update_option( 'aumviso_sitemap_images',     ! empty( $_POST['aumviso_sitemap_images'] ) );
		flush_rewrite_rules();
	}

	// ------------------------------------
	// Robots.txt
	// ------------------------------------

	public function render_robots(): void {
		$this->saved_notice();
		?>
		<p class="aml-tab-intro">
			<?php /* translators: %s: link to the robots.txt file. */ printf( esc_html__( 'This content is served at %s', 'aumviso' ), '<a class="aml-link" href="' . esc_url( home_url( '/robots.txt' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( home_url( '/robots.txt' ) ) . '</a>' ); ?>
		</p>
		<?php
		$this->form_open( 'robots' );
		?>
		<div class="aml-card">
			<h2 class="aml-card-h"><span class="dashicons dashicons-shield" aria-hidden="true"></span> <?php esc_html_e( 'robots.txt', 'aumviso' ); ?></h2>
			<div class="aml-field">
				<textarea class="aml-code" name="aumviso_robots_txt" rows="18"><?php echo esc_textarea( AumViso_Robots::get_content() ); ?></textarea>
			</div>
		</div>
		<?php
		$this->form_close();
	}

	private function save_robots_settings(): void {
		AumViso_Robots::save( wp_unslash( $_POST['aumviso_robots_txt'] ?? '' ) );
	}

	// ------------------------------------
	// Internal Links
	// ------------------------------------

	public function render_internal_links(): void {
		$keywords = AumViso_InternalLinks::get_all_keywords();
		$this->saved_notice();
		?>
		<p class="aml-tab-intro"><?php esc_html_e( 'Keywords entered here are automatically linked in post content.', 'aumviso' ); ?></p>

		<div class="aml-card">
			<h2 class="aml-card-h"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> <?php esc_html_e( 'Keywords', 'aumviso' ); ?></h2>
			<table class="aml-table" id="aumviso-ilinks-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Keyword', 'aumviso' ); ?></th>
						<th><?php esc_html_e( 'Target URL', 'aumviso' ); ?></th>
						<th class="aml-col-center"><?php esc_html_e( 'Max', 'aumviso' ); ?></th>
						<th class="aml-col-center"><?php esc_html_e( 'Case', 'aumviso' ); ?></th>
						<th class="aml-col-center"><?php esc_html_e( 'Actions', 'aumviso' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $keywords as $kw ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $kw['keyword'] ); ?></strong></td>
						<td><a class="aml-link" href="<?php echo esc_url( $kw['target_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $kw['target_url'] ); ?></a></td>
						<td class="aml-col-center"><?php echo esc_html( $kw['max_links'] ); ?></td>
						<td class="aml-col-center"><?php echo $kw['case_sensitive'] ? '<span class="dashicons dashicons-yes" aria-hidden="true"></span>' : '—'; ?></td>
						<td class="aml-col-center">
							<button type="button" class="aml-btn aml-btn-danger aumviso-ilink-delete"
								data-id="<?php echo esc_attr( $kw['id'] ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'aumviso_ajax' ) ); ?>">
								<?php esc_html_e( 'Delete', 'aumviso' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
					<?php if ( empty( $keywords ) ) : ?>
					<tr id="aumviso-no-keywords"><td colspan="5"><?php esc_html_e( 'No keywords yet.', 'aumviso' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div class="aml-card">
			<h2 class="aml-card-h"><span class="dashicons dashicons-plus-alt" aria-hidden="true"></span> <?php esc_html_e( 'Add keyword', 'aumviso' ); ?></h2>
			<div class="aml-field">
				<label class="aml-label" for="ilink_keyword"><?php esc_html_e( 'Keyword', 'aumviso' ); ?></label>
				<input type="text" id="ilink_keyword" placeholder="CNC machining">
			</div>
			<div class="aml-field">
				<label class="aml-label" for="ilink_url"><?php esc_html_e( 'Target URL', 'aumviso' ); ?></label>
				<input type="url" id="ilink_url" placeholder="https://example.com/target-page/">
			</div>
			<div class="aml-field aml-field-narrow">
				<label class="aml-label" for="ilink_max"><?php esc_html_e( 'Max links per page', 'aumviso' ); ?></label>
				<input type="number" id="ilink_max" value="1" min="1" max="10">
			</div>
			<label class="aml-switch-row">
				<span class="aml-switch">
					<input type="checkbox" id="ilink_case">
					<span class="aml-track" aria-hidden="true"></span>
				</span>
				<span class="aml-switch-label"><?php esc_html_e( 'Case sensitive', 'aumviso' ); ?></span>
			</label>
			<p class="aml-actions-bar" style="margin-top:14px">
				<button type="button" class="aml-btn aml-btn-primary" id="aumviso-ilink-add" data-nonce="<?php echo esc_attr( wp_create_nonce( 'aumviso_ajax' ) ); ?>">
					<?php esc_html_e( 'Add keyword', 'aumviso' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	private function save_internal_links_settings(): void {} // Handled via AJAX

	// ------------------------------------
	// AI Tools
	// ------------------------------------

	public function render_ai(): void {
		$config = AumViso_Options::ai_config();
		$this->saved_notice();
		?>
		<p class="aml-tab-intro"><?php esc_html_e( 'Connect an AI provider for content generation tools.', 'aumviso' ); ?></p>
		<?php
		$this->form_open( 'ai' );
		?>
		<div class="aml-card">
			<h2 class="aml-card-h"><span class="dashicons dashicons-superhero-alt" aria-hidden="true"></span> <?php esc_html_e( 'AI provider', 'aumviso' ); ?></h2>

			<div class="aml-field aml-field-narrow">
				<label class="aml-label" for="aumviso_ai_provider"><?php esc_html_e( 'Provider', 'aumviso' ); ?></label>
				<select id="aumviso_ai_provider" name="aumviso_ai_provider">
					<option value="core"     <?php selected( $config['provider'], 'core' ); ?>><?php esc_html_e( 'WordPress AI Client (recommended)', 'aumviso' ); ?></option>
					<option value="openai"   <?php selected( $config['provider'], 'openai' ); ?>>OpenAI</option>
					<option value="deepseek" <?php selected( $config['provider'], 'deepseek' ); ?>>DeepSeek</option>
				</select>
				<p class="aml-field-hint">
					<?php
					if ( AumViso_AI_Connector::core_client_ready() ) {
						esc_html_e( 'The WordPress AI Client is connected on this site. With it, the provider and its credentials are managed once under Settings → Connectors and shared by every plugin, so no key is needed here.', 'aumviso' );
					} else {
						esc_html_e( 'The WordPress AI Client needs WordPress 7.0 or newer with a provider connected under Settings → Connectors. Until then, pick a direct provider below and supply your own key.', 'aumviso' );
					}
					?>
				</p>
			</div>

			<?php
			/*
			 * The key and model belong to a direct provider only.
			 *
			 * 🔴 They used to render unconditionally, directly underneath a hint that says — when the
			 * WordPress AI Client is chosen — "no key is needed here". The screen asked for a key one line
			 * after telling the reader it did not want one, and a buyer who dutifully fills it in has stored
			 * a credential that nothing reads.
			 *
			 * Initial state is decided here rather than by script alone, so the fields are already in the
			 * right state before any JavaScript runs; `admin.js` only keeps them in step when the select
			 * changes.
			 */
			$aumviso_direct = 'core' !== $config['provider'];
			?>
			<div class="aumviso-ai-direct" <?php echo $aumviso_direct ? '' : 'hidden'; ?>>
			<div class="aml-field">
				<label class="aml-label" for="aumviso_ai_key"><?php esc_html_e( 'API key', 'aumviso' ); ?></label>
				<input type="password" id="aumviso_ai_key" name="aumviso_ai_key" value="<?php echo esc_attr( $config['key'] ); ?>" autocomplete="new-password">
				<p class="aml-field-hint"><?php esc_html_e( 'Your API key is stored in the database. Use a restricted key when possible.', 'aumviso' ); ?></p>
			</div>

			<div class="aml-field aml-field-narrow">
				<label class="aml-label" for="aumviso_ai_model"><?php esc_html_e( 'Model', 'aumviso' ); ?></label>
				<input type="text" id="aumviso_ai_model" name="aumviso_ai_model" value="<?php echo esc_attr( $config['model'] ); ?>" placeholder="gpt-4o-mini">
				<p class="aml-field-hint"><?php esc_html_e( 'e.g. gpt-4o-mini, gpt-4o, deepseek-chat', 'aumviso' ); ?></p>
			</div>
			</div>
		</div>
		<?php
		$this->form_close();
	}

	private function save_ai_settings(): void {
		update_option( 'aumviso_ai_provider', sanitize_key( wp_unslash( $_POST['aumviso_ai_provider'] ?? 'openai' ) ) );
		if ( ! empty( $_POST['aumviso_ai_key'] ) ) {
			update_option( 'aumviso_ai_key', sanitize_text_field( wp_unslash( $_POST['aumviso_ai_key'] ) ) );
		}
		update_option( 'aumviso_ai_model', sanitize_text_field( wp_unslash( $_POST['aumviso_ai_model'] ?? '' ) ) );
	}

	// ------------------------------------
	// General Settings
	// ------------------------------------

	public function render_settings(): void {
		$ilink_pts = (array) AumViso_Options::get( 'aumviso_ilinks_post_types', [] );
		$this->saved_notice();
		?>
		<p class="aml-tab-intro"><?php esc_html_e( 'Automatically link keywords inside post content, and append related items at the end of a post. Unrelated to the Automation tab, which drafts articles with AI.', 'aumviso' ); ?></p>
		<?php
		$this->form_open( 'settings' );
		?>
		<div class="aml-card">
			<h2 class="aml-card-h"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> <?php esc_html_e( 'Internal linking', 'aumviso' ); ?></h2>
			<?php $this->switch_row( 'aumviso_ilinks_enabled', '1', (bool) AumViso_Options::get( 'aumviso_ilinks_enabled', true ), __( 'Enable automatic internal linking', 'aumviso' ) ); ?>

			<h3 class="aml-group-label"><?php esc_html_e( 'Apply to post types', 'aumviso' ); ?></h3>
			<div class="aml-toggle-grid">
				<?php foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) : ?>
					<?php $this->switch_row( 'aumviso_ilinks_post_types[]', $pt->name, in_array( $pt->name, $ilink_pts, true ), $pt->labels->name ); ?>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="aml-card">
			<h2 class="aml-card-h"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span> <?php esc_html_e( 'Related content', 'aumviso' ); ?></h2>
			<?php $this->switch_row( 'aumviso_related_auto_append', '1', (bool) AumViso_Options::get( 'aumviso_related_auto_append', false ), __( 'Automatically append related content after post content', 'aumviso' ) ); ?>
		</div>
		<?php
		$this->form_close();
	}

	private function save_general_settings(): void {
		update_option( 'aumviso_ilinks_enabled',       ! empty( $_POST['aumviso_ilinks_enabled'] ) );
		update_option( 'aumviso_ilinks_post_types',    array_map( 'sanitize_key', (array) wp_unslash( $_POST['aumviso_ilinks_post_types'] ?? [] ) ) );
		update_option( 'aumviso_related_auto_append',  ! empty( $_POST['aumviso_related_auto_append'] ) );
	}
}
