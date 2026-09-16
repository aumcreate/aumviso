<?php
/**
 * Brand Entity.
 *
 * Stores the brand's entity data and augments AumViso's Organization schema via
 * the `aumviso_organization_schema` hook with E-E-A-T signals — description,
 * slogan, foundingDate, founders, richer sameAs, contactPoint, postal address
 * and awards — so AI and search engines understand who the brand is.
 *
 * Singleton: the schema filter must run on the frontend (wp_head), while the
 * settings UI + save handler only load in admin.
 */

defined( 'ABSPATH' ) || exit;

final class AumViso_Adv_Brand {

	const OPTION_KEY   = 'aumviso_adv_brand';
	const NONCE_ACTION = 'aumviso_adv_brand_save';
	const NONCE_NAME   = 'aumviso_adv_brand_nonce';

	private static ?AumViso_Adv_Brand $instance = null;

	public static function instance(): AumViso_Adv_Brand {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'aumviso_organization_schema', [ $this, 'augment' ] );
		if ( is_admin() ) {
			add_action( 'admin_init', [ $this, 'handle_save' ] );
		}
	}

	public static function defaults(): array {
		return [
			'description'   => '',
			'slogan'        => '',
			'founding_date' => '',
			'founders'      => '',
			'same_as'       => '',
			'email'         => '',
			'phone'         => '',
			'addr_street'   => '',
			'addr_locality' => '',
			'addr_region'   => '',
			'addr_postal'   => '',
			'addr_country'  => '',
			'awards'        => '',
		];
	}

	public static function identity(): array {
		if ( class_exists( 'AumViso_Options' ) ) {
			$org = AumViso_Options::schema_org();
			return [
				'type' => (string) ( $org['type'] ?? 'Organization' ),
				'name' => (string) ( $org['name'] ?? get_bloginfo( 'name' ) ),
				'url'  => (string) ( $org['url'] ?? home_url() ),
				'logo' => (string) ( $org['logo'] ?? '' ),
			];
		}

		return [
			'type' => (string) get_option( 'aumviso_schema_org_type', 'Organization' ),
			'name' => (string) get_option( 'aumviso_schema_org_name', get_bloginfo( 'name' ) ),
			'url'  => (string) get_option( 'aumviso_schema_org_url', home_url() ),
			'logo' => (string) get_option( 'aumviso_schema_org_logo', '' ),
		];
	}

	public static function completion(): array {
		$identity = self::identity();
		$brand    = self::instance()->get_all();
		$checks   = [
			'name'         => trim( (string) $identity['name'] ),
			'url'          => trim( (string) $identity['url'] ),
			'description'  => trim( (string) $brand['description'] ),
			'founded'      => trim( (string) $brand['founding_date'] ),
			'contact'      => trim( (string) $brand['email'] . ' ' . (string) $brand['phone'] ),
			'location'     => trim( (string) $brand['addr_locality'] . ' ' . (string) $brand['addr_country'] ),
		];
		$filled = 0;
		foreach ( $checks as $value ) {
			if ( '' !== $value ) {
				++$filled;
			}
		}
		return [ 'filled' => $filled, 'total' => count( $checks ) ];
	}

	public static function material(): string {
		$identity = self::identity();
		$brand    = self::instance()->get_all();
		$labels   = [
			'type'          => [ 'Organization type', $identity['type'] ],
			'name'          => [ 'Organization name', $identity['name'] ],
			'url'           => [ 'Organization URL', $identity['url'] ],
			'logo'          => [ 'Logo URL', $identity['logo'] ],
			'description'   => [ 'Brand description', $brand['description'] ],
			'slogan'        => [ 'Slogan', $brand['slogan'] ],
			'founding_date' => [ 'Founded', $brand['founding_date'] ],
			'founders'      => [ 'Founders', $brand['founders'] ],
			'email'         => [ 'Contact email', $brand['email'] ],
			'phone'         => [ 'Contact phone', $brand['phone'] ],
			'address'       => [
				'Address',
				trim(
					implode(
						', ',
						array_filter(
							[
								$brand['addr_street'],
								$brand['addr_locality'],
								$brand['addr_region'],
								$brand['addr_postal'],
								$brand['addr_country'],
							]
						)
					)
				),
			],
			'awards'        => [ 'Awards/certifications', $brand['awards'] ],
		];

		$lines = [];
		foreach ( $labels as $row ) {
			$value = trim( (string) $row[1] );
			if ( '' !== $value ) {
				$lines[] = $row[0] . ': ' . $value;
			}
		}
		return $lines ? "Brand entity:\n" . implode( "\n", $lines ) : '';
	}

	public function get_all(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
	}

	private function csv( string $s ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $s ) ) ) );
	}

	private function lines( string $s ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $s ) ) ) );
	}

	// ------------------------------------
	// Schema augmentation (frontend)
	// ------------------------------------

	public function augment( array $schema ): array {
		$b = $this->get_all();

		if ( '' !== $b['description'] ) {
			$schema['description'] = $b['description'];
		}
		if ( '' !== $b['slogan'] ) {
			$schema['slogan'] = $b['slogan'];
		}
		if ( '' !== $b['founding_date'] ) {
			$schema['foundingDate'] = $b['founding_date'];
		}

		$founders = $this->csv( $b['founders'] );
		if ( $founders ) {
			$schema['founder'] = array_map(
				static function ( $name ) {
					return [ '@type' => 'Person', 'name' => $name ];
				},
				$founders
			);
		}

		$same = $this->lines( $b['same_as'] );
		if ( $same ) {
			$existing        = isset( $schema['sameAs'] ) ? (array) $schema['sameAs'] : [];
			$schema['sameAs'] = array_values( array_unique( array_merge( $existing, $same ) ) );
		}

		if ( '' !== $b['email'] || '' !== $b['phone'] ) {
			$cp = [ '@type' => 'ContactPoint', 'contactType' => 'customer service' ];
			if ( '' !== $b['email'] ) {
				$cp['email'] = $b['email'];
			}
			if ( '' !== $b['phone'] ) {
				$cp['telephone'] = $b['phone'];
			}
			$schema['contactPoint'] = $cp;
		}

		$address = array_filter(
			[
				'streetAddress'   => $b['addr_street'],
				'addressLocality' => $b['addr_locality'],
				'addressRegion'   => $b['addr_region'],
				'postalCode'      => $b['addr_postal'],
				'addressCountry'  => $b['addr_country'],
			],
			static function ( $v ) {
				return '' !== $v;
			}
		);
		if ( $address ) {
			$schema['address'] = array_merge( [ '@type' => 'PostalAddress' ], $address );
		}

		$awards = $this->lines( $b['awards'] );
		if ( $awards ) {
			$schema['award'] = $awards;
		}

		return $schema;
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

		$identity_raw = isset( $_POST['aumviso_adv_brand_identity'] ) && is_array( $_POST['aumviso_adv_brand_identity'] )
			? wp_unslash( $_POST['aumviso_adv_brand_identity'] )
			: [];
		$type         = sanitize_text_field( $identity_raw['type'] ?? 'Organization' );
		if ( ! in_array( $type, [ 'Organization', 'LocalBusiness', 'Person', 'Corporation' ], true ) ) {
			$type = 'Organization';
		}
		update_option( 'aumviso_schema_org_type', $type );
		update_option( 'aumviso_schema_org_name', sanitize_text_field( $identity_raw['name'] ?? '' ) );
		update_option( 'aumviso_schema_org_url', esc_url_raw( $identity_raw['url'] ?? '' ) );
		update_option( 'aumviso_schema_org_logo', esc_url_raw( $identity_raw['logo'] ?? '' ) );

		$raw = isset( $_POST['aumviso_adv_brand'] ) && is_array( $_POST['aumviso_adv_brand'] )
			? wp_unslash( $_POST['aumviso_adv_brand'] )
			: [];

		$clean = [
			'description'   => sanitize_textarea_field( $raw['description'] ?? '' ),
			'slogan'        => sanitize_text_field( $raw['slogan'] ?? '' ),
			'founding_date' => sanitize_text_field( $raw['founding_date'] ?? '' ),
			'founders'      => sanitize_text_field( $raw['founders'] ?? '' ),
			'same_as'       => $this->sanitize_url_lines( $raw['same_as'] ?? '' ),
			'email'         => sanitize_email( $raw['email'] ?? '' ),
			'phone'         => sanitize_text_field( $raw['phone'] ?? '' ),
			'addr_street'   => sanitize_text_field( $raw['addr_street'] ?? '' ),
			'addr_locality' => sanitize_text_field( $raw['addr_locality'] ?? '' ),
			'addr_region'   => sanitize_text_field( $raw['addr_region'] ?? '' ),
			'addr_postal'   => sanitize_text_field( $raw['addr_postal'] ?? '' ),
			'addr_country'  => sanitize_text_field( $raw['addr_country'] ?? '' ),
			'awards'        => sanitize_textarea_field( $raw['awards'] ?? '' ),
		];

		update_option( self::OPTION_KEY, $clean );

		wp_safe_redirect( AumViso_Adv_Console::url( 'brand', [ 'updated' => 1 ] ) );
		exit;
	}

	private function sanitize_url_lines( string $text ): string {
		$out = [];
		foreach ( $this->lines( $text ) as $line ) {
			$url = esc_url_raw( $line );
			if ( $url ) {
				$out[] = $url;
			}
		}
		return implode( "\n", $out );
	}

	// ------------------------------------
	// Render tab
	// ------------------------------------

	public function render_tab(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$b        = $this->get_all();
		$identity = self::identity();

		echo '<p class="aml-tab-intro">' . esc_html__( 'Define the canonical company identity for schema, GEO knowledge and AI content grounding. Setup will point users here instead of asking for duplicate company fields.', 'aumviso' ) . '</p>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Brand entity saved.', 'aumviso' ) . '</p></div>';
		}

		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-building"></span>' . esc_html__( 'Company identity', 'aumviso' ) . '</h2>';
		echo '<p class="aml-card-desc">' . esc_html__( 'These basic Organization fields are shared with the Lite schema settings and treated as trusted first-layer facts.', 'aumviso' ) . '</p>';
		echo '<div class="aml-field-grid">';
		$this->identity_select( 'type', __( 'Organization type', 'aumviso' ), $identity['type'] );
		$this->identity_text( 'name', __( 'Organization / company name', 'aumviso' ), $identity['name'] );
		echo '</div>';
		echo '<div class="aml-field-grid">';
		$this->identity_text( 'url', __( 'Organization URL', 'aumviso' ), $identity['url'] );
		$this->identity_text( 'logo', __( 'Logo URL', 'aumviso' ), $identity['logo'] );
		echo '</div>';
		echo '</div>';

		// --- Brand ---
		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-store"></span>' . esc_html__( 'Brand', 'aumviso' ) . '</h2>';
		$this->textarea( 'description', __( 'Brand description / story', 'aumviso' ), $b['description'], __( 'One or two sentences describing what the brand does.', 'aumviso' ) );
		echo '<div class="aml-field-grid">';
		$this->text( 'slogan', __( 'Slogan', 'aumviso' ), $b['slogan'] );
		$this->text( 'founding_date', __( 'Founded (YYYY or YYYY-MM-DD)', 'aumviso' ), $b['founding_date'] );
		echo '</div>';
		$this->text( 'founders', __( 'Founders (comma-separated)', 'aumviso' ), $b['founders'] );
		echo '</div>';

		// --- Profiles & contact ---
		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-share"></span>' . esc_html__( 'Profiles & contact', 'aumviso' ) . '</h2>';
		$this->textarea( 'same_as', __( 'Profile / social URLs (one per line)', 'aumviso' ), $b['same_as'], __( 'Official profiles: LinkedIn, YouTube, Wikipedia, Crunchbase, etc. Merged with AumViso\'s social links.', 'aumviso' ) );
		echo '<div class="aml-field-grid">';
		$this->text( 'email', __( 'Contact email', 'aumviso' ), $b['email'] );
		$this->text( 'phone', __( 'Contact phone', 'aumviso' ), $b['phone'] );
		echo '</div>';
		echo '</div>';

		// --- Address ---
		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-location"></span>' . esc_html__( 'Address', 'aumviso' ) . '</h2>';
		$this->text( 'addr_street', __( 'Street address', 'aumviso' ), $b['addr_street'] );
		echo '<div class="aml-field-grid">';
		$this->text( 'addr_locality', __( 'City / locality', 'aumviso' ), $b['addr_locality'] );
		$this->text( 'addr_region', __( 'Region / state', 'aumviso' ), $b['addr_region'] );
		$this->text( 'addr_postal', __( 'Postal code', 'aumviso' ), $b['addr_postal'] );
		$this->text( 'addr_country', __( 'Country (e.g. US, CN)', 'aumviso' ), $b['addr_country'] );
		echo '</div>';
		echo '</div>';

		// --- Recognition ---
		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-awards"></span>' . esc_html__( 'Recognition', 'aumviso' ) . '</h2>';
		$this->textarea( 'awards', __( 'Awards & certifications (one per line)', 'aumviso' ), $b['awards'] );
		echo '</div>';

		echo '<p class="aml-actions-bar"><button type="submit" class="aml-btn aml-btn-primary">' . esc_html__( 'Save brand entity', 'aumviso' ) . '</button></p>';
		echo '</form>';
	}

	private function identity_select( string $key, string $label, string $value ): void {
		echo '<div class="aml-field"><label class="aml-label" for="avp-brand-identity-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		echo '<select id="avp-brand-identity-' . esc_attr( $key ) . '" name="aumviso_adv_brand_identity[' . esc_attr( $key ) . ']">';
		foreach ( [ 'Organization', 'LocalBusiness', 'Person', 'Corporation' ] as $type ) {
			echo '<option value="' . esc_attr( $type ) . '"' . selected( $value, $type, false ) . '>' . esc_html( $type ) . '</option>';
		}
		echo '</select></div>';
	}

	private function identity_text( string $key, string $label, string $value ): void {
		printf(
			'<div class="aml-field"><label class="aml-label" for="avp-brand-identity-%1$s">%2$s</label><input type="text" id="avp-brand-identity-%1$s" name="aumviso_adv_brand_identity[%1$s]" value="%3$s" class="large-text" /></div>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $value )
		);
	}

	private function text( string $key, string $label, string $value ): void {
		printf(
			'<div class="aml-field"><label class="aml-label" for="avp-brand-%1$s">%2$s</label><input type="text" id="avp-brand-%1$s" name="aumviso_adv_brand[%1$s]" value="%3$s" class="large-text" /></div>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $value )
		);
	}

	private function textarea( string $key, string $label, string $value, string $hint = '' ): void {
		printf(
			'<div class="aml-field"><label class="aml-label" for="avp-brand-%1$s">%2$s</label><textarea id="avp-brand-%1$s" name="aumviso_adv_brand[%1$s]" rows="3" class="large-text">%3$s</textarea>%4$s</div>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_textarea( $value ),
			'' !== $hint ? '<p class="aml-field-hint">' . esc_html( $hint ) . '</p>' : ''
		);
	}
}
