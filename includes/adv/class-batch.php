<?php
/**
 * Batch AI — generate SEO/GEO content across many items at once.
 *
 * Reuses AumViso's existing AI generator (AumViso_AI_Generator) and persists to
 * the same meta AumViso reads, so batch output is identical to the per-post
 * tools — only at scale. Work is driven from the browser in small chunks
 * (see assets/js/batch.js) to avoid PHP timeouts; this class exposes two AJAX
 * endpoints: one to resolve the target list, one to process a chunk.
 *
 * v1 operation: Meta description → `_aumviso_seo_description` (closes the "missing meta
 * description" gap the Overview surfaces). The run() switch is structured so
 * FAQ / summary operations can be added later.
 */

defined( 'ABSPATH' ) || exit;

final class AumViso_Adv_Batch {

	const NONCE = 'aumviso_adv_batch';

	/** Hard cap on items resolved per run, to bound memory on large sites. */
	const TARGET_CAP = 500;

	public function __construct() {
		add_action( 'wp_ajax_aumviso_adv_batch_targets', [ $this, 'ajax_targets' ] );
		add_action( 'wp_ajax_aumviso_adv_batch_run', [ $this, 'ajax_run' ] );
	}

	/** Whether the AI provider has an API key configured in AumViso. */
	public static function ai_configured(): bool {
		if ( ! method_exists( 'AumViso_Options', 'ai_config' ) ) {
			return false;
		}
		$cfg = AumViso_Options::ai_config();
		return ! empty( $cfg['key'] );
	}

	/** Operations offered by the batch tool. */
	public static function operations(): array {
		return [
			'meta_desc' => __( 'Generate meta description', 'aumviso' ),
		];
	}

	private function guard(): void {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'aumviso' ) ], 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'aumviso' ) ], 403 );
		}
	}

	/**
	 * Resolve the list of post IDs to process for the chosen content type and
	 * filter. Returned to the browser, which then sends them back in chunks.
	 */
	public function ajax_targets(): void {
		$this->guard();

		$post_type    = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$missing_only = ! empty( $_POST['missing_only'] );

		if ( ! post_type_exists( $post_type ) || ! in_array( $post_type, AumViso_Options::get_enabled_post_types(), true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid content type.', 'aumviso' ) ] );
		}

		$args = [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => self::TARGET_CAP,
			'fields'         => 'ids',
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];

		if ( $missing_only ) {
			$args['meta_query'] = [
				'relation' => 'OR',
				[ 'key' => '_aumviso_seo_description', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_aumviso_seo_description', 'value' => '', 'compare' => '=' ],
			];
		}

		$ids = array_map( 'intval', get_posts( $args ) );

		wp_send_json_success( [ 'ids' => $ids, 'count' => count( $ids ) ] );
	}

	/**
	 * Process one chunk of IDs and return per-item results.
	 */
	public function ajax_run(): void {
		$this->guard();

		$op        = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'meta_desc';
		$overwrite = ! empty( $_POST['overwrite'] );
		$ids       = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : [];

		$results = [];
		foreach ( $ids as $id ) {
			$results[] = $this->run_one( $id, $op, $overwrite );
		}

		wp_send_json_success( [ 'results' => $results ] );
	}

	/**
	 * @return array{id:int,title:string,status:string,message:string}
	 */
	private function run_one( int $id, string $op, bool $overwrite ): array {
		$post = get_post( $id );
		if ( ! $post ) {
			return [ 'id' => $id, 'title' => '#' . $id, 'status' => 'error', 'message' => __( 'Not found', 'aumviso' ) ];
		}
		$title = $post->post_title;

		switch ( $op ) {
			case 'meta_desc':
				$existing = (string) get_post_meta( $id, '_aumviso_seo_description', true );
				if ( '' !== $existing && ! $overwrite ) {
					return [ 'id' => $id, 'title' => $title, 'status' => 'skip', 'message' => __( 'Already has one', 'aumviso' ) ];
				}
				$result = AumViso_AI_Generator::generate_meta_description( $title, $post->post_content );
				if ( is_wp_error( $result ) ) {
					return [ 'id' => $id, 'title' => $title, 'status' => 'error', 'message' => $result->get_error_message() ];
				}
				$desc = sanitize_textarea_field( (string) $result );
				if ( '' === $desc ) {
					return [ 'id' => $id, 'title' => $title, 'status' => 'error', 'message' => __( 'Empty result', 'aumviso' ) ];
				}
				update_post_meta( $id, '_aumviso_seo_description', $desc );
				return [ 'id' => $id, 'title' => $title, 'status' => 'ok', 'message' => $desc ];

			default:
				return [ 'id' => $id, 'title' => $title, 'status' => 'error', 'message' => __( 'Unknown operation', 'aumviso' ) ];
		}
	}

	/** Render the Batch AI tab body. */
	public function render_tab(): void {
		$configured = self::ai_configured();
		$types      = AumViso_Options::get_enabled_post_types();
		$ops        = self::operations();

		echo '<p class="aml-tab-intro">' . esc_html__( 'Generate SEO/GEO content across many items at once, reusing AumViso\'s AI tools. Runs in small batches with a live progress log.', 'aumviso' ) . '</p>';

		/*
		 * Arriving from the health report's "Fix all". Saying so out loud matters: the buyer clicked a
		 * button about missing descriptions and landed on a general-purpose tool, and without a word
		 * connecting the two it looks like the click went to the wrong place.
		 *
		 * ⚠️ Only when the tool can actually run. With no AI key the page already says so, and adding
		 * "pick a type and run it" above that produces two sentences that contradict each other in the
		 * space of one screen — the first promising, the second refusing.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $configured && isset( $_GET['from'] ) && 'health' === $_GET['from'] ) {
			printf(
				'<div class="aml-card aml-note"><p>%s</p></div>',
				esc_html__( 'Pick the content type below and run it — only items missing a description are touched.', 'aumviso' )
			);
		}

		if ( ! $configured ) {
			printf(
				'<div class="aml-card"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'No AI API key is configured yet. Add one in AumViso to enable batch generation:', 'aumviso' ),
				esc_url( add_query_arg( [ 'page' => 'aumviso', 'tab' => 'ai' ], admin_url( 'admin.php' ) ) ),
				esc_html__( 'AumViso → AI Tools', 'aumviso' )
			);
			echo '</div>';
			return;
		}
		?>
		<div class="aml-card" id="avp-batch" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
			<h2 class="aml-card-h"><span class="dashicons dashicons-superhero-alt"></span><?php esc_html_e( 'Batch generate', 'aumviso' ); ?></h2>

			<div class="aml-field-grid">
				<div class="aml-field">
					<label class="aml-label" for="avp-batch-pt"><?php esc_html_e( 'Content type', 'aumviso' ); ?></label>
					<select id="avp-batch-pt">
						<?php foreach ( $types as $type ) :
							$obj = get_post_type_object( $type );
							?>
							<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $obj ? $obj->labels->name : $type ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="aml-field">
					<?php
					/*
					 * With one operation there is no choice to offer.
					 *
					 * A select holding a single option is a control that pretends to be a decision: it draws
					 * the eye, invites a click, and does nothing. Say what will happen instead, and keep the
					 * value in a hidden field so the script reads it exactly as before. The select comes back
					 * on its own the day a second operation exists.
					 */
					if ( count( $ops ) > 1 ) :
						?>
						<label class="aml-label" for="avp-batch-op"><?php esc_html_e( 'Operation', 'aumviso' ); ?></label>
						<select id="avp-batch-op">
							<?php foreach ( $ops as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<label class="aml-label"><?php esc_html_e( 'Operation', 'aumviso' ); ?></label>
						<p class="aml-readonly-value"><?php echo esc_html( (string) reset( $ops ) ); ?></p>
						<input type="hidden" id="avp-batch-op" value="<?php echo esc_attr( (string) key( $ops ) ); ?>">
					<?php endif; ?>
				</div>
			</div>

			<p style="margin:14px 0 0">
				<label class="aml-switch-row" style="display:inline-flex">
					<span class="aml-switch"><input type="checkbox" id="avp-batch-missing" value="1" checked /><span class="aml-track" aria-hidden="true"></span></span>
					<span class="aml-switch-label"><?php esc_html_e( 'Only items missing a meta description', 'aumviso' ); ?></span>
				</label>
			</p>
			<p style="margin:6px 0 0">
				<label class="aml-switch-row" style="display:inline-flex">
					<span class="aml-switch"><input type="checkbox" id="avp-batch-overwrite" value="1" /><span class="aml-track" aria-hidden="true"></span></span>
					<span class="aml-switch-label"><?php esc_html_e( 'Overwrite items that already have one', 'aumviso' ); ?></span>
				</label>
			</p>

			<p class="aml-actions-bar" style="margin-top:16px">
				<button type="button" class="aml-btn aml-btn-primary" id="avp-batch-run"><span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Run batch', 'aumviso' ); ?></button>
				<button type="button" class="aml-btn" id="avp-batch-stop" style="display:none"><?php esc_html_e( 'Stop', 'aumviso' ); ?></button>
			</p>

			<div id="avp-batch-progress" style="display:none">
				<div class="avp-bands" style="height:16px"><span class="avp-band great" id="avp-batch-bar" style="width:0"></span></div>
				<p class="avp-stamp" id="avp-batch-status"></p>
				<ul class="avp-batch-log" id="avp-batch-log"></ul>
			</div>
		</div>
		<?php
	}
}
