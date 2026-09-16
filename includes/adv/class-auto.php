<?php
/**
 * Automation content engine (Module 4, blueprint C.4–C.9).
 *
 * Runs the factory unattended in one of three modes:
 *   - off   : nothing scheduled.
 *   - semi  : generate drafts on a schedule into the review queue (default).
 *   - full  : generate AND publish, behind quality gates + safety fuses.
 *
 * Reliability: a bounded WP-Cron tick recomputes "how many should exist by now
 * today" vs "how many were made", so a missed tick self-compensates on the next
 * run (WP-Cron only fires on traffic — the admin is told to use a real cron).
 *
 * Safety fuses (full mode): forced strict grounding, a per-draft quality gate
 * (min words + no duplicate title) that falls back to draft instead of
 * publishing, a monthly cap, a one-click stop-all, and auto-stop when topics run
 * out. Every auto item is flagged (_aumviso_adv_auto) for traceability/rollback.
 */

defined( 'ABSPATH' ) || exit;

final class AumViso_Adv_Auto {

	const OPTION    = 'aumviso_adv_auto';
	const NONCE     = 'aumviso_adv_auto_save';
	const CRON_HOOK = 'aumviso_adv_auto_tick';
	const AUTO_META = '_aumviso_adv_auto';

	/** Max drafts generated per single tick, to bound load on catch-up. */
	const PER_TICK = 2;

	private static ?AumViso_Adv_Auto $instance = null;

	public static function instance(): AumViso_Adv_Auto {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, [ $this, 'run_tick' ] );
		add_action( 'init', [ $this, 'maybe_schedule' ] );
	}

	// ------------------------------------
	// Settings + state
	// ------------------------------------

	public function settings(): array {
		$saved = get_option( self::OPTION, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		return wp_parse_args(
			$saved,
			[
				'mode'         => 'off',       // off | semi | full
				'times'        => [ '09:00' ], // daily time points -> one draft each
				'topic_source' => 'gaps',      // gaps | products | knowledge
				'post_type'    => 'post',
				'words'        => 800,
				'geo_faq'      => 1,
				'geo_summary'  => 1,
				'geo_related'  => 1,
				'monthly_cap'  => 30,
				'min_words'    => 300,         // full-mode quality gate
				// runtime state
				'paused'       => 0,
				'last_run'     => 0,
				'day_key'      => '',
				'day_count'    => 0,
				'month_key'    => '',
				'month_count'  => 0,
				'exhausted'    => 0,
			]
		);
	}

	private function update( array $patch ): void {
		update_option( self::OPTION, array_merge( $this->settings(), $patch ) );
	}

	// ------------------------------------
	// Cron scheduling
	// ------------------------------------

	/** Keeps the hourly tick scheduled only while automation is active. */
	public function maybe_schedule(): void {
		$s      = $this->settings();
		$active = 'off' !== $s['mode'] && empty( $s['paused'] );

		if ( $active && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		} elseif ( ! $active && wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	public function next_run(): int {
		return (int) wp_next_scheduled( self::CRON_HOOK );
	}

	// ------------------------------------
	// The tick
	// ------------------------------------

	public function run_tick(): void {
		$s = $this->settings();

		if ( 'off' === $s['mode'] || ! empty( $s['paused'] ) ) {
			return;
		}
		$s = $this->roll_counters( $s );

		$deficit = $this->due_now( $s );
		if ( $deficit <= 0 ) {
			$this->update( [ 'last_run' => time() ] );
			return;
		}

		$factory = AumViso_Adv_Factory::instance();
		$made    = 0;

		for ( $i = 0; $i < min( $deficit, self::PER_TICK ); $i++ ) {
			if ( $s['month_count'] >= (int) $s['monthly_cap'] ) {
				break; // Monthly cap reached — stop quietly until next month.
			}

			$item = $this->pick_item( $s, $factory );
			if ( null === $item ) {
				$this->update( [ 'exhausted' => 1 ] ); // Material/topics exhausted -> auto-stop signal.
				break;
			}

			// Auto guides are grounded in the product data behind the topic; in
			// full mode pick_item already refused any topic without material
			// (strict grounding). Products themselves are never auto-generated.
			$id = $factory->create_draft( $item['topic'], $item['material'], (string) $s['post_type'], (int) $s['words'] );
			if ( is_wp_error( $id ) ) {
				break; // Likely an API/config problem — stop this tick, retry next.
			}

			update_post_meta( $id, self::AUTO_META, 1 );
			update_post_meta( $id, '_aumviso_adv_auto_ts', time() );

			foreach ( $this->geo_ops( $s ) as $op ) {
				$factory->enrich_op( $id, $op );
			}

			if ( 'full' === $s['mode'] && $this->quality_ok( $id, (int) $s['min_words'] ) ) {
				wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
			}

			$s['day_count']   = (int) $s['day_count'] + 1;
			$s['month_count'] = (int) $s['month_count'] + 1;
			++$made;
		}

		$this->update(
			[
				'last_run'    => time(),
				'day_count'   => (int) $s['day_count'],
				'month_count' => (int) $s['month_count'],
			]
		);
		unset( $made );
	}

	/** Resets the daily/monthly counters when the day/month rolls over. */
	private function roll_counters( array $s ): array {
		$today = wp_date( 'Y-m-d' );
		$month = wp_date( 'Y-m' );
		if ( $s['day_key'] !== $today ) {
			$s['day_key']   = $today;
			$s['day_count'] = 0;
		}
		if ( $s['month_key'] !== $month ) {
			$s['month_key']   = $month;
			$s['month_count'] = 0;
			$s['exhausted']   = 0;
		}
		$this->update( $s );
		return $s;
	}

	/** How many drafts are owed today = time points already passed − made today. */
	private function due_now( array $s ): int {
		$now      = wp_date( 'H:i' );
		$expected = 0;
		foreach ( (array) $s['times'] as $t ) {
			if ( preg_match( '/^\d{2}:\d{2}$/', (string) $t ) && $t <= $now ) {
				++$expected;
			}
		}
		return max( 0, $expected - (int) $s['day_count'] );
	}

	private function geo_ops( array $s ): array {
		$ops = [ 'meta' ];
		if ( ! empty( $s['geo_faq'] ) ) {
			$ops[] = 'faq';
		}
		if ( ! empty( $s['geo_summary'] ) ) {
			$ops[] = 'summary';
		}
		if ( ! empty( $s['geo_related'] ) ) {
			$ops[] = 'related';
		}
		return $ops;
	}

	/**
	 * Picks the first grounded topic item not already used by a factory draft.
	 * In full mode, items without grounding material are skipped (strict
	 * grounding — no real facts, no unattended publish). Returns null if none.
	 */
	private function pick_item( array $s, AumViso_Adv_Factory $factory ): ?array {
		$used   = $this->used_topics();
		$strict = 'full' === $s['mode'];
		foreach ( array_unique( [ (string) $s['topic_source'], 'knowledge', 'products', 'gaps' ] ) as $mode ) {
			foreach ( $factory->auto_topic_items( $mode ) as $item ) {
				$topic = mb_strtolower( trim( (string) $item['topic'] ) );
				if ( isset( $used[ $topic ] ) ) {
					continue;
				}
				if ( $strict && '' === trim( (string) $item['material'] ) ) {
					continue;
				}
				return $item;
			}
		}
		return null;
	}

	/** Lowercased set of topics already used, to avoid regenerating duplicates. */
	private function used_topics(): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1000",
				'_aumviso_adv_factory_topic'
			)
		);
		$set = [];
		foreach ( (array) $rows as $r ) {
			$set[ mb_strtolower( trim( (string) $r ) ) ] = true;
		}
		return $set;
	}

	/** Quality gate: enough words and no existing published post with same title. */
	private function quality_ok( int $id, int $min_words ): bool {
		$post = get_post( $id );
		if ( ! $post ) {
			return false;
		}
		$text  = wp_strip_all_tags( (string) $post->post_content );
		$words = str_word_count( $text ) + (int) preg_match_all( '/[\x{4E00}-\x{9FFF}]/u', $text );
		if ( $words < max( 1, $min_words ) ) {
			return false;
		}

		$dupe = get_posts(
			[
				'post_type'      => $post->post_type,
				'post_status'    => 'publish',
				'title'          => $post->post_title,
				'exclude'        => [ $id ],
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		return empty( $dupe );
	}

	// ------------------------------------
	// Config tab
	// ------------------------------------

	private function render_knowledge_status(): void {
		$profile = class_exists( 'AumViso_Adv_Setup' ) ? AumViso_Adv_Setup::profile() : [];
		$brand_status = class_exists( 'AumViso_Adv_Brand' ) ? AumViso_Adv_Brand::completion() : [ 'filled' => 0, 'total' => 0 ];
		$required = [ 'main_business', 'service_scope', 'target_markets', 'customer_types' ];
		$filled = 0;
		foreach ( $required as $key ) {
			if ( '' !== trim( (string) ( $profile[ $key ] ?? '' ) ) ) {
				++$filled;
			}
		}
		$products = class_exists( 'AumViso_Product_Registry' ) ? (int) AumViso_Product_Registry::instance()->total_count() : 0;
		$faqs     = post_type_exists( 'aumviso_faq' ) ? (int) ( wp_count_posts( 'aumviso_faq' )->publish ?? 0 ) : 0;
		$guides   = post_type_exists( 'aumviso_guide' ) ? (int) ( wp_count_posts( 'aumviso_guide' )->publish ?? 0 ) : 0;
		$glossary = post_type_exists( 'aumviso_glossary' ) ? (int) ( wp_count_posts( 'aumviso_glossary' )->publish ?? 0 ) : 0;
		$custom   = class_exists( 'AumViso_Adv_Setup' ) ? count( AumViso_Adv_Setup::custom_post_types() ) : 0;

		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-database"></span>' . esc_html__( 'Knowledge base status', 'aumviso' ) . '</h2>';
		echo '<p class="aml-card-desc">' . esc_html__( 'Automation combines Brand Entity, business facts, product data, published FAQs, Guides, Glossary terms and enabled custom sources. Draft knowledge is not used until published.', 'aumviso' ) . '</p>';
		echo '<div class="avp-stat-grid">';
		$this->status_card( __( 'Brand entity', 'aumviso' ), sprintf( '%1$d/%2$d', $brand_status['filled'], $brand_status['total'] ), $brand_status['total'] > 0 && $brand_status['filled'] >= $brand_status['total'] );
		$this->status_card( __( 'Business facts', 'aumviso' ), sprintf( '%1$d/%2$d', $filled, count( $required ) ), count( $required ) === $filled );
		$this->status_card( __( 'Products', 'aumviso' ), (string) $products, $products > 0 );
		$this->status_card( __( 'FAQs', 'aumviso' ), (string) $faqs, $faqs > 0 );
		$this->status_card( __( 'Guides', 'aumviso' ), (string) $guides, $guides > 0 );
		$this->status_card( __( 'Glossary', 'aumviso' ), (string) $glossary, $glossary > 0 );
		$this->status_card( __( 'Custom sources', 'aumviso' ), (string) $custom, $custom > 0 );
		echo '</div>';
		echo '<p class="aml-actions-bar" style="margin-top:14px">';
		printf( '<a class="aml-btn" href="%s">%s</a> ', esc_url( AumViso_Adv_Console::url( 'setup' ) ), esc_html__( 'Open Setup', 'aumviso' ) );
		printf( '<a class="aml-btn" href="%s">%s</a>', esc_url( AumViso_Adv_Console::url( 'factory' ) ), esc_html__( 'Build knowledge drafts', 'aumviso' ) );
		echo '</p>';
		echo '</div>';
	}

	private function status_card( string $label, string $value, bool $ok ): void {
		printf(
			'<div class="avp-stat"><div class="avp-stat-num %1$s">%2$s</div><div class="avp-stat-label">%3$s</div></div>',
			$ok ? 'is-ok' : 'is-warn',
			esc_html( $value ),
			esc_html( $label )
		);
	}

	private function render_custom_sources(): void {
		if ( ! class_exists( 'AumViso_Adv_Setup' ) ) {
			return;
		}
		$available = AumViso_Adv_Setup::available_custom_post_types();
		$sources   = AumViso_Adv_Setup::knowledge_sources();
		$custom    = $sources['custom_post_types'] ?? [];

		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-database-add"></span>' . esc_html__( 'Additional retrieval sources', 'aumviso' ) . '</h2>';
		echo '<p class="aml-card-desc">' . esc_html__( 'Third-layer automation can optionally search extra public content types when drafting articles. Built-in sources are always used: Brand Entity, business facts, products, FAQs, Guides and Glossary.', 'aumviso' ) . '</p>';

		if ( empty( $available ) ) {
			echo '<p>' . esc_html__( 'No additional public content types are available.', 'aumviso' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="avp-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Use', 'aumviso' ) . '</th>';
		echo '<th>' . esc_html__( 'Content type', 'aumviso' ) . '</th>';
		echo '<th>' . esc_html__( 'Role', 'aumviso' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Max', 'aumviso' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Published', 'aumviso' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $available as $type => $obj ) {
			$row     = (array) ( $custom[ $type ] ?? [] );
			$checked = ! empty( $row['enabled'] );
			$role    = (string) ( $row['role'] ?? 'other' );
			$limit   = (int) ( $row['limit'] ?? 20 );
			$count   = (int) ( wp_count_posts( $type )->publish ?? 0 );
			$label   = (string) $obj->labels->name;
			printf(
				'<tr><td><input type="checkbox" name="aumviso_adv_sources[custom_post_types][%1$s][enabled]" value="1" %2$s></td><td><strong>%3$s</strong><br><code>%1$s</code><input type="hidden" name="aumviso_adv_sources[custom_post_types][%1$s][label]" value="%4$s"></td><td>%5$s</td><td class="num"><input type="number" name="aumviso_adv_sources[custom_post_types][%1$s][limit]" value="%6$d" min="1" max="100" style="width:72px"></td><td class="num">%7$d</td></tr>',
				esc_attr( $type ),
				checked( $checked, true, false ),
				esc_html( $label ),
				esc_attr( $label ),
				$this->custom_source_role_select( $type, $role ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns a <select> whose every value and label is escaped inside that method.
				esc_attr( max( 1, min( 100, $limit ) ) ),
				esc_html( $count )
			);
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	private function custom_source_role_select( string $type, string $current ): string {
		$roles = [
			'case_studies'   => __( 'Case studies', 'aumviso' ),
			'services'       => __( 'Services', 'aumviso' ),
			'documentation'  => __( 'Documentation', 'aumviso' ),
			'testimonials'   => __( 'Testimonials', 'aumviso' ),
			'certifications' => __( 'Certifications', 'aumviso' ),
			'downloads'      => __( 'Downloads', 'aumviso' ),
			'other'          => __( 'Other', 'aumviso' ),
		];
		$html  = '<select name="aumviso_adv_sources[custom_post_types][' . esc_attr( $type ) . '][role]">';
		foreach ( $roles as $key => $label ) {
			$html .= '<option value="' . esc_attr( $key ) . '"' . selected( $current, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select>';
		return $html;
	}

	public function render_tab(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['avp_auto_save'] ) ) {
			check_admin_referer( self::NONCE );
			$this->save_form( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in save_form().
			$this->maybe_schedule();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Automation settings saved.', 'aumviso' ) . '</p></div>';
		}
		if ( isset( $_POST['avp_auto_stop'] ) ) {
			check_admin_referer( self::NONCE );
			$this->update( [ 'paused' => 1 ] );
			$this->maybe_schedule();
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Automation paused. No further drafts or posts will be generated until you resume.', 'aumviso' ) . '</p></div>';
		}

		$s = $this->settings();

		echo '<p class="aml-tab-intro">' . esc_html__( 'Generate SEO/GEO articles from the trusted knowledge base. Semi-auto queues drafts for review (recommended); full-auto publishes automatically behind quality gates.', 'aumviso' ) . '</p>';

		if ( ! empty( $s['exhausted'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Automation ran out of new topics and stopped adding items. Add products/content or new topic sources, then it resumes next cycle.', 'aumviso' ) . '</p></div>';
		}
		if ( ! empty( $s['paused'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Automation is currently PAUSED.', 'aumviso' ) . '</p></div>';
		}

		$times = implode( ', ', array_map( 'sanitize_text_field', (array) $s['times'] ) );
		$this->render_knowledge_status();
		?>
		<form method="post" action="<?php echo esc_url( AumViso_Adv_Console::url( 'auto' ) ); ?>" id="avp-auto-form">
			<?php wp_nonce_field( self::NONCE ); ?>

			<div class="aml-card">
				<h2 class="aml-card-h"><span class="dashicons dashicons-clock"></span><?php esc_html_e( 'Run mode', 'aumviso' ); ?></h2>
				<p class="aml-field">
					<label class="aml-label" for="avp-auto-mode"><?php esc_html_e( 'Mode', 'aumviso' ); ?></label>
					<select id="avp-auto-mode" name="mode">
						<option value="off" <?php selected( $s['mode'], 'off' ); ?>><?php esc_html_e( 'Off', 'aumviso' ); ?></option>
						<option value="semi" <?php selected( $s['mode'], 'semi' ); ?>><?php esc_html_e( 'Semi-auto — generate drafts for review (recommended)', 'aumviso' ); ?></option>
						<option value="full" <?php selected( $s['mode'], 'full' ); ?>><?php esc_html_e( 'Full-auto — generate AND publish (higher risk)', 'aumviso' ); ?></option>
					</select>
				</p>
				<div class="aml-field-grid">
					<div class="aml-field">
						<label class="aml-label" for="avp-auto-times"><?php esc_html_e( 'Daily time points (HH:MM, comma-separated)', 'aumviso' ); ?></label>
						<input type="text" id="avp-auto-times" name="times" class="regular-text" value="<?php echo esc_attr( $times ); ?>" placeholder="09:00, 15:00" />
						<p class="aml-field-hint"><?php esc_html_e( 'One draft per time point per day. WP-Cron only fires on site traffic — for reliable timing, set a real server cron.', 'aumviso' ); ?></p>
					</div>
					<div class="aml-field">
						<label class="aml-label" for="avp-auto-source"><?php esc_html_e( 'Topic source', 'aumviso' ); ?></label>
						<select id="avp-auto-source" name="topic_source">
							<option value="gaps" <?php selected( $s['topic_source'], 'gaps' ); ?>><?php esc_html_e( 'Content gaps (missing guides/FAQs)', 'aumviso' ); ?></option>
							<option value="products" <?php selected( $s['topic_source'], 'products' ); ?>><?php esc_html_e( 'Product-derived topics', 'aumviso' ); ?></option>
							<option value="knowledge" <?php selected( $s['topic_source'], 'knowledge' ); ?>><?php esc_html_e( 'Knowledge-base topics', 'aumviso' ); ?></option>
						</select>
					</div>
					<div class="aml-field">
						<label class="aml-label" for="avp-auto-words"><?php esc_html_e( 'Target length (words)', 'aumviso' ); ?></label>
						<input type="number" id="avp-auto-words" name="words" value="<?php echo esc_attr( (string) (int) $s['words'] ); ?>" min="200" max="3000" step="100" />
					</div>
				</div>
				<div style="margin-top:12px">
					<p class="aml-label" style="margin-bottom:8px"><?php esc_html_e( 'Also generate (GEO)', 'aumviso' ); ?></p>
					<label class="aml-switch-row" style="display:inline-flex;margin-right:22px"><span class="aml-switch"><input type="checkbox" name="geo_faq" value="1" <?php checked( $s['geo_faq'], 1 ); ?> /><span class="aml-track" aria-hidden="true"></span></span><span class="aml-switch-label"><?php esc_html_e( 'FAQ', 'aumviso' ); ?></span></label>
					<label class="aml-switch-row" style="display:inline-flex;margin-right:22px"><span class="aml-switch"><input type="checkbox" name="geo_summary" value="1" <?php checked( $s['geo_summary'], 1 ); ?> /><span class="aml-track" aria-hidden="true"></span></span><span class="aml-switch-label"><?php esc_html_e( 'Key takeaways', 'aumviso' ); ?></span></label>
					<label class="aml-switch-row" style="display:inline-flex"><span class="aml-switch"><input type="checkbox" name="geo_related" value="1" <?php checked( $s['geo_related'], 1 ); ?> /><span class="aml-track" aria-hidden="true"></span></span><span class="aml-switch-label"><?php esc_html_e( 'Related questions', 'aumviso' ); ?></span></label>
				</div>
			</div>

			<?php $this->render_custom_sources(); ?>

			<div class="aml-card">
				<h2 class="aml-card-h"><span class="dashicons dashicons-shield"></span><?php esc_html_e( 'Safety limits', 'aumviso' ); ?></h2>
				<div class="aml-field-grid">
					<div class="aml-field">
						<label class="aml-label" for="avp-auto-cap"><?php esc_html_e( 'Monthly cap (max items/month)', 'aumviso' ); ?></label>
						<input type="number" id="avp-auto-cap" name="monthly_cap" value="<?php echo esc_attr( (string) (int) $s['monthly_cap'] ); ?>" min="1" max="1000" />
					</div>
					<div class="aml-field">
						<label class="aml-label" for="avp-auto-min"><?php esc_html_e( 'Full-auto min words to publish', 'aumviso' ); ?></label>
						<input type="number" id="avp-auto-min" name="min_words" value="<?php echo esc_attr( (string) (int) $s['min_words'] ); ?>" min="1" max="3000" step="50" />
						<p class="aml-field-hint"><?php esc_html_e( 'Below this, or if a same-title post already exists, the item stays a draft instead of publishing.', 'aumviso' ); ?></p>
					</div>
				</div>
			</div>

			<div class="aml-card">
				<h2 class="aml-card-h"><span class="dashicons dashicons-info"></span><?php esc_html_e( 'Status', 'aumviso' ); ?></h2>
				<p class="aml-card-desc">
					<?php
					printf(
						/* translators: 1: last run, 2: next run, 3: this month count, 4: cap */
						esc_html__( 'Last run: %1$s · Next scheduled: %2$s · This month: %3$d / %4$d', 'aumviso' ),
						esc_html( $s['last_run'] ? wp_date( 'Y-m-d H:i', (int) $s['last_run'] ) : '—' ),
						esc_html( $this->next_run() ? wp_date( 'Y-m-d H:i', $this->next_run() ) : '—' ),
						(int) $s['month_count'],
						(int) $s['monthly_cap']
					);
					?>
				</p>
			</div>

			<p class="aml-actions-bar">
				<button type="submit" name="avp_auto_save" value="1" class="aml-btn aml-btn-primary"><?php esc_html_e( 'Save automation', 'aumviso' ); ?></button>
				<button type="submit" name="avp_auto_stop" value="1" class="aml-btn"><?php esc_html_e( 'Stop all now', 'aumviso' ); ?></button>
			</p>
		</form>
		<?php
	}

	private function save_form( array $post ): void {
		$mode = in_array( $post['mode'] ?? '', [ 'off', 'semi', 'full' ], true ) ? (string) $post['mode'] : 'off';

		// Parse + validate time points.
		$times = [];
		foreach ( explode( ',', (string) ( $post['times'] ?? '' ) ) as $t ) {
			$t = trim( $t );
			if ( preg_match( '/^\d{1,2}:\d{2}$/', $t ) ) {
				$times[] = substr( '0' . $t, -5 ); // zero-pad to HH:MM
			}
		}
		if ( ! $times ) {
			$times = [ '09:00' ];
		}

		$pt      = sanitize_key( $post['post_type'] ?? 'post' );
		$enabled = AumViso_Options::get_enabled_post_types();
		if ( ! in_array( $pt, $enabled, true ) || 'aum_nexcart_product' === $pt ) {
			$pt = 'post'; // Never auto-generate products.
		}

		// Resuming (choosing a live mode) clears the paused flag.
		$paused = ( 'off' === $mode ) ? (int) $this->settings()['paused'] : 0;

		$this->update(
			[
				'mode'         => $mode,
				'times'        => array_values( array_unique( $times ) ),
				'topic_source' => in_array( $post['topic_source'] ?? '', [ 'gaps', 'products', 'knowledge' ], true ) ? (string) $post['topic_source'] : 'gaps',
				'post_type'    => $pt,
				'words'        => max( 200, min( 3000, (int) ( $post['words'] ?? 800 ) ) ),
				'geo_faq'      => empty( $post['geo_faq'] ) ? 0 : 1,
				'geo_summary'  => empty( $post['geo_summary'] ) ? 0 : 1,
				'geo_related'  => empty( $post['geo_related'] ) ? 0 : 1,
				'monthly_cap'  => max( 1, min( 1000, (int) ( $post['monthly_cap'] ?? 30 ) ) ),
				'min_words'    => max( 1, min( 3000, (int) ( $post['min_words'] ?? 300 ) ) ),
				'paused'       => $paused,
			]
		);
		$this->save_custom_sources( $post );
	}

	private function save_custom_sources( array $post ): void {
		if ( ! class_exists( 'AumViso_Adv_Setup' ) ) {
			return;
		}
		$source_raw = isset( $post['aumviso_adv_sources'] ) && is_array( $post['aumviso_adv_sources'] )
			? $post['aumviso_adv_sources']
			: [];
		$available  = AumViso_Adv_Setup::available_custom_post_types();
		$custom     = [];
		foreach ( $available as $type => $obj ) {
			if ( empty( $source_raw['custom_post_types'][ $type ]['enabled'] ) ) {
				continue;
			}
			$row = (array) $source_raw['custom_post_types'][ $type ];
			$custom[ $type ] = [
				'enabled' => true,
				'label'   => sanitize_text_field( $row['label'] ?? $obj->labels->name ),
				'role'    => sanitize_key( $row['role'] ?? 'other' ),
				'limit'   => max( 1, min( 100, (int) ( $row['limit'] ?? 20 ) ) ),
			];
		}
		update_option( AumViso_Adv_Setup::SOURCES_OPTION, [ 'custom_post_types' => $custom ] );
	}
}
