<?php
/**
 * Content Factory image provider.
 *
 * Supplies a featured image when the factory's free sources (product main image,
 * media library) find nothing, via the `aumviso_adv_factory_image` filter. Two
 * kinds of provider are supported and both are pluggable by API key:
 *
 *   - AI image generation: 通义万相 (DashScope) · 智谱 CogView · 火山方舟 Ark ·
 *     OpenAI gpt-image / DALL·E · a generic OpenAI-compatible "custom" endpoint.
 *   - Stock libraries: Unsplash · Pexels.
 *
 * Design note (global market incl. Mainland China): no provider is hard-wired —
 * the admin picks one AI provider + one stock provider, supplies the key, and may
 * point AI at a custom endpoint, so customers whose network can't reach a given
 * vendor can still use the feature. Whatever image is obtained is sideloaded into
 * the media library and given AI alt text; it is then set as the draft's featured
 * image by the caller.
 */

defined( 'ABSPATH' ) || exit;

final class AumViso_Adv_Images {

	const OPTION = 'aumviso_adv_images';
	const NONCE  = 'aumviso_adv_images_save';

	private static ?AumViso_Adv_Images $instance = null;

	public static function instance(): AumViso_Adv_Images {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Answer the factory's image request when free sources found nothing.
		add_filter( 'aumviso_adv_factory_image', [ $this, 'provide' ], 10, 2 );
		// Emit an ImageObject for factory posts' featured image (GEO), via Lite's
		// schema engine — no Lite edit, just its documented collect hook.
		add_action( 'aumviso_collect_schemas', [ $this, 'add_image_object' ] );
	}

	/**
	 * Registers an ImageObject for the featured image of a Content Factory post,
	 * using the AI-written alt text as caption/description. Scoped to factory
	 * posts so the rest of the site's schema is untouched.
	 *
	 * @param object $engine AumViso_SchemaEngine (register()-able).
	 */
	public function add_image_object( $engine ): void {
		if ( ! is_singular() || ! is_object( $engine ) || ! method_exists( $engine, 'register' ) ) {
			return;
		}
		$id = get_queried_object_id();
		if ( ! $id || 1 !== (int) get_post_meta( $id, '_aumviso_adv_factory', true ) ) {
			return;
		}
		$thumb = get_post_thumbnail_id( $id );
		if ( ! $thumb ) {
			return;
		}
		$url = wp_get_attachment_image_url( $thumb, 'full' );
		if ( ! $url ) {
			return;
		}

		$schema = [
			'@type'                => 'ImageObject',
			'contentUrl'           => $url,
			'url'                  => $url,
			'representativeOfPage' => true,
		];
		$alt = trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) );
		if ( '' !== $alt ) {
			$schema['caption']     = $alt;
			$schema['description'] = $alt;
		}
		$engine->register( $schema );
	}

	// ------------------------------------
	// Settings
	// ------------------------------------

	public function settings(): array {
		$saved = get_option( self::OPTION, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		return wp_parse_args(
			$saved,
			[
				'auto'            => 0,
				'prefer'          => 'ai_first', // ai_first | stock_first
				'ai_provider'     => '',         // '' | dashscope | cogview | ark | openai | custom
				'dashscope_key'   => '',
				'dashscope_model' => 'wanx2.1-t2i-turbo',
				'cogview_key'     => '',
				'cogview_model'   => 'cogview-3',
				'ark_key'         => '',
				'ark_model'       => '',          // Volcengine endpoint/model id
				'openai_key'      => '',
				'openai_model'    => 'gpt-image-1',
				'custom_endpoint' => '',
				'custom_key'      => '',
				'custom_model'    => '',
				'stock_provider'  => '',          // '' | unsplash | pexels
				'unsplash_key'    => '',
				'pexels_key'      => '',
			]
		);
	}

	private function save( array $post ): void {
		$providers = [ '', 'core', 'dashscope', 'cogview', 'ark', 'openai', 'custom' ];
		$stock     = [ '', 'unsplash', 'pexels' ];

		$data = [
			'auto'            => empty( $post['auto'] ) ? 0 : 1,
			'prefer'          => 'stock_first' === ( $post['prefer'] ?? '' ) ? 'stock_first' : 'ai_first',
			'ai_provider'     => in_array( $post['ai_provider'] ?? '', $providers, true ) ? (string) $post['ai_provider'] : '',
			'dashscope_key'   => sanitize_text_field( $post['dashscope_key'] ?? '' ),
			'dashscope_model' => sanitize_text_field( $post['dashscope_model'] ?? 'wanx2.1-t2i-turbo' ),
			'cogview_key'     => sanitize_text_field( $post['cogview_key'] ?? '' ),
			'cogview_model'   => sanitize_text_field( $post['cogview_model'] ?? 'cogview-3' ),
			'ark_key'         => sanitize_text_field( $post['ark_key'] ?? '' ),
			'ark_model'       => sanitize_text_field( $post['ark_model'] ?? '' ),
			'openai_key'      => sanitize_text_field( $post['openai_key'] ?? '' ),
			'openai_model'    => sanitize_text_field( $post['openai_model'] ?? 'gpt-image-1' ),
			'custom_endpoint' => esc_url_raw( $post['custom_endpoint'] ?? '' ),
			'custom_key'      => sanitize_text_field( $post['custom_key'] ?? '' ),
			'custom_model'    => sanitize_text_field( $post['custom_model'] ?? '' ),
			'stock_provider'  => in_array( $post['stock_provider'] ?? '', $stock, true ) ? (string) $post['stock_provider'] : '',
			'unsplash_key'    => sanitize_text_field( $post['unsplash_key'] ?? '' ),
			'pexels_key'      => sanitize_text_field( $post['pexels_key'] ?? '' ),
		];

		update_option( self::OPTION, $data );
	}

	// ------------------------------------
	// Resolver (filter callback)
	// ------------------------------------

	/**
	 * Returns an attachment ID for the draft, or the incoming value untouched.
	 * Walks AI + stock providers per the configured preference; the first hit is
	 * sideloaded, given alt text and returned.
	 *
	 * @param mixed $current Incoming filter value (0 or an already-resolved id).
	 * @param array $ctx     { post_id, topic, post_type, material }.
	 */
	public function provide( $current, array $ctx ): int {
		$current = (int) $current;
		if ( $current > 0 ) {
			return $current;
		}

		$s = $this->settings();
		if ( empty( $s['auto'] ) ) {
			return 0;
		}

		$topic = trim( (string) ( $ctx['topic'] ?? '' ) );
		if ( '' === $topic ) {
			return 0;
		}

		$order = 'stock_first' === $s['prefer'] ? [ 'stock', 'ai' ] : [ 'ai', 'stock' ];
		foreach ( $order as $kind ) {
			$id = 'ai' === $kind ? $this->try_ai( $topic, $s ) : $this->try_stock( $topic, $s );
			if ( $id > 0 ) {
				$this->set_alt( $id, $topic );
				return $id;
			}
		}
		return 0;
	}

	// ------------------------------------
	// AI generation
	// ------------------------------------

	private function try_ai( string $topic, array $s ): int {
		$provider = (string) $s['ai_provider'];
		if ( '' === $provider || $this->on_cooldown( 'ai_' . $provider ) ) {
			return 0;
		}

		$prompt = $this->ai_prompt( $topic );
		$result = match ( $provider ) {
			'core'      => $this->gen_core( $prompt ),
			'dashscope' => $this->gen_dashscope( $prompt, $s ),
			'cogview'   => $this->gen_cogview( $prompt, $s ),
			'ark'       => $this->gen_ark( $prompt, $s ),
			'openai'    => $this->gen_openai( $prompt, $s ),
			'custom'    => $this->gen_custom( $prompt, $s ),
			default     => new WP_Error( 'unknown', 'Unknown provider.' ),
		};

		if ( is_wp_error( $result ) || empty( $result ) ) {
			$this->set_cooldown( 'ai_' . $provider );
			return 0;
		}
		return $this->store_result( $result, $topic );
	}

	/** Alibaba DashScope 通义万相 — async: submit a task, then poll for the URL. */
	private function gen_dashscope( string $prompt, array $s ): array|WP_Error {
		if ( '' === $s['dashscope_key'] ) {
			return new WP_Error( 'no_key', 'No DashScope key.' );
		}
		$submit = wp_remote_post(
			'https://dashscope.aliyuncs.com/api/v1/services/aigc/text2image/image-synthesis',
			[
				'headers' => [
					'Authorization'    => 'Bearer ' . $s['dashscope_key'],
					'Content-Type'     => 'application/json',
					'X-DashScope-Async' => 'enable',
				],
				'body'    => wp_json_encode(
					[
						'model'      => $s['dashscope_model'] ?: 'wanx2.1-t2i-turbo',
						'input'      => [ 'prompt' => $prompt ],
						'parameters' => [ 'size' => '1024*1024', 'n' => 1 ],
					]
				),
				'timeout' => 30,
			]
		);
		if ( is_wp_error( $submit ) ) {
			return $submit;
		}
		$body    = json_decode( wp_remote_retrieve_body( $submit ), true );
		$task_id = $body['output']['task_id'] ?? '';
		if ( '' === $task_id ) {
			return new WP_Error( 'no_task', 'DashScope did not return a task id.' );
		}

		// Poll (bounded) until the task succeeds.
		for ( $i = 0; $i < 10; $i++ ) {
			sleep( 2 );
			$poll = wp_remote_get(
				'https://dashscope.aliyuncs.com/api/v1/tasks/' . rawurlencode( $task_id ),
				[ 'headers' => [ 'Authorization' => 'Bearer ' . $s['dashscope_key'] ], 'timeout' => 20 ]
			);
			if ( is_wp_error( $poll ) ) {
				continue;
			}
			$pb     = json_decode( wp_remote_retrieve_body( $poll ), true );
			$status = $pb['output']['task_status'] ?? '';
			if ( 'SUCCEEDED' === $status ) {
				$url = $pb['output']['results'][0]['url'] ?? '';
				return $url ? [ 'url' => $url ] : new WP_Error( 'no_url', 'DashScope returned no image URL.' );
			}
			if ( 'FAILED' === $status ) {
				return new WP_Error( 'failed', 'DashScope task failed.' );
			}
		}
		return new WP_Error( 'timeout', 'DashScope task did not finish in time.' );
	}

	/** Zhipu CogView — synchronous, returns a URL. */
	private function gen_cogview( string $prompt, array $s ): array|WP_Error {
		if ( '' === $s['cogview_key'] ) {
			return new WP_Error( 'no_key', 'No CogView key.' );
		}
		$res = wp_remote_post(
			'https://open.bigmodel.cn/api/paas/v4/images/generations',
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $s['cogview_key'], 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'model' => $s['cogview_model'] ?: 'cogview-3', 'prompt' => $prompt ] ),
				'timeout' => 60,
			]
		);
		return $this->url_from( $res, [ 'data', 0, 'url' ] );
	}

	/** Volcengine Ark (Doubao/Jimeng) — OpenAI-style images endpoint, returns URL. */
	private function gen_ark( string $prompt, array $s ): array|WP_Error {
		if ( '' === $s['ark_key'] || '' === $s['ark_model'] ) {
			return new WP_Error( 'no_key', 'Ark needs both an API key and a model/endpoint id.' );
		}
		$res = wp_remote_post(
			'https://ark.cn-beijing.volces.com/api/v3/images/generations',
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $s['ark_key'], 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode(
					[ 'model' => $s['ark_model'], 'prompt' => $prompt, 'size' => '1024x1024', 'response_format' => 'url' ]
				),
				'timeout' => 60,
			]
		);
		return $this->url_from( $res, [ 'data', 0, 'url' ] );
	}

	/** OpenAI Images — gpt-image-1 returns b64_json; dall-e-3 returns a URL. */
	/**
	 * Generate through the WordPress core AI Client.
	 *
	 * Preferred when the site has a provider connected: the credentials live at
	 * site level under Settings → Connectors instead of in this plugin's
	 * options, and every plugin on the site shares the same connection.
	 *
	 * The direct providers below stay available because the client's official
	 * connectors cover Anthropic, Google and OpenAI only -- sites that cannot
	 * reach those networks still need DashScope, CogView or Ark.
	 */
	private function gen_core( string $prompt ): array|WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'no_ai_client', __( 'This site does not provide the WordPress AI Client.', 'aumviso' ) );
		}

		$builder = wp_ai_client_prompt( $prompt );

		if ( method_exists( $builder, 'is_supported_for_image_generation' ) && ! $builder->is_supported_for_image_generation() ) {
			return new WP_Error( 'ai_client_unconfigured', __( 'No AI provider capable of generating images is connected under Settings → Connectors.', 'aumviso' ) );
		}

		$file = $builder->generate_image();

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( ! is_object( $file ) || ! method_exists( $file, 'getDataUri' ) ) {
			return new WP_Error( 'unexpected_result', __( 'The AI Client returned something this plugin does not understand.', 'aumviso' ) );
		}

		if ( ! preg_match( '#^data:image/([a-z0-9.+-]+);base64,(.+)$#i', (string) $file->getDataUri(), $m ) ) {
			return new WP_Error( 'bad_data_uri', __( 'The generated image could not be read.', 'aumviso' ) );
		}

		$binary = base64_decode( $m[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the data: URI the AI Client returned, not obfuscated code.

		if ( false === $binary || '' === $binary ) {
			return new WP_Error( 'bad_data_uri', __( 'The generated image could not be read.', 'aumviso' ) );
		}

		$ext = strtolower( $m[1] );
		$ext = 'jpeg' === $ext ? 'jpg' : preg_replace( '/[^a-z0-9]/', '', $ext );

		// store_result() hands 'data' straight to sideload_data(), which expects
		// raw bytes rather than base64.
		return [ 'data' => $binary, 'ext' => $ext ?: 'png' ];
	}

	private function gen_openai( string $prompt, array $s ): array|WP_Error {
		if ( '' === $s['openai_key'] ) {
			return new WP_Error( 'no_key', 'No OpenAI key.' );
		}
		$res = wp_remote_post(
			'https://api.openai.com/v1/images/generations',
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $s['openai_key'], 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'model' => $s['openai_model'] ?: 'gpt-image-1', 'prompt' => $prompt, 'size' => '1024x1024', 'n' => 1 ] ),
				'timeout' => 60,
			]
		);
		return $this->image_from_openai_like( $res );
	}

	/** Generic OpenAI-compatible images endpoint (escape hatch for any region). */
	private function gen_custom( string $prompt, array $s ): array|WP_Error {
		if ( '' === $s['custom_endpoint'] || '' === $s['custom_key'] ) {
			return new WP_Error( 'no_key', 'Custom provider needs an endpoint and a key.' );
		}
		$res = wp_remote_post(
			$s['custom_endpoint'],
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $s['custom_key'], 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( array_filter( [ 'model' => $s['custom_model'], 'prompt' => $prompt, 'size' => '1024x1024', 'n' => 1 ] ) ),
				'timeout' => 60,
			]
		);
		return $this->image_from_openai_like( $res );
	}

	/** Parses an OpenAI-style images response into url or raw data. */
	private function image_from_openai_like( $res ): array|WP_Error {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			return new WP_Error( 'api', $body['error']['message'] ?? 'Image API error.' );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$item = $body['data'][0] ?? [];
		if ( ! empty( $item['b64_json'] ) ) {
			$bin = base64_decode( (string) $item['b64_json'], true );
			return $bin ? [ 'data' => $bin, 'ext' => 'png' ] : new WP_Error( 'decode', 'Bad base64 image.' );
		}
		if ( ! empty( $item['url'] ) ) {
			return [ 'url' => (string) $item['url'] ];
		}
		return new WP_Error( 'empty', 'No image in response.' );
	}

	// ------------------------------------
	// Stock libraries
	// ------------------------------------

	private function try_stock( string $topic, array $s ): int {
		$provider = (string) $s['stock_provider'];
		if ( '' === $provider || $this->on_cooldown( 'stock_' . $provider ) ) {
			return 0;
		}
		$query  = $this->stock_query( $topic );
		$result = match ( $provider ) {
			'unsplash' => $this->stock_unsplash( $query, $s ),
			'pexels'   => $this->stock_pexels( $query, $s ),
			default    => new WP_Error( 'unknown', 'Unknown stock provider.' ),
		};
		if ( is_wp_error( $result ) || empty( $result ) ) {
			$this->set_cooldown( 'stock_' . $provider );
			return 0;
		}
		return $this->store_result( $result, $topic );
	}

	private function stock_unsplash( string $query, array $s ): array|WP_Error {
		if ( '' === $s['unsplash_key'] ) {
			return new WP_Error( 'no_key', 'No Unsplash key.' );
		}
		$res = wp_remote_get(
			add_query_arg(
				[ 'query' => $query, 'per_page' => 1, 'orientation' => 'landscape' ],
				'https://api.unsplash.com/search/photos'
			),
			[ 'headers' => [ 'Authorization' => 'Client-ID ' . $s['unsplash_key'] ], 'timeout' => 20 ]
		);
		return $this->url_from( $res, [ 'results', 0, 'urls', 'regular' ] );
	}

	private function stock_pexels( string $query, array $s ): array|WP_Error {
		if ( '' === $s['pexels_key'] ) {
			return new WP_Error( 'no_key', 'No Pexels key.' );
		}
		$res = wp_remote_get(
			add_query_arg(
				[ 'query' => $query, 'per_page' => 1, 'orientation' => 'landscape' ],
				'https://api.pexels.com/v1/search'
			),
			[ 'headers' => [ 'Authorization' => $s['pexels_key'] ], 'timeout' => 20 ]
		);
		return $this->url_from( $res, [ 'photos', 0, 'src', 'large' ] );
	}

	// ------------------------------------
	// Helpers
	// ------------------------------------

	/** Extracts a URL at $path from a JSON response; WP_Error on any miss. */
	private function url_from( $res, array $path ): array|WP_Error {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new WP_Error( 'api', 'Image API HTTP ' . wp_remote_retrieve_response_code( $res ) );
		}
		$val = json_decode( wp_remote_retrieve_body( $res ), true );
		foreach ( $path as $key ) {
			if ( ! is_array( $val ) || ! isset( $val[ $key ] ) ) {
				return new WP_Error( 'empty', 'No image URL in response.' );
			}
			$val = $val[ $key ];
		}
		return is_string( $val ) && '' !== $val ? [ 'url' => $val ] : new WP_Error( 'empty', 'No image URL.' );
	}

	/** Sideloads a url/data result into the media library; returns attachment id. */
	private function store_result( array $result, string $topic ): int {
		if ( ! empty( $result['url'] ) ) {
			return $this->sideload_url( (string) $result['url'], $topic );
		}
		if ( ! empty( $result['data'] ) ) {
			return $this->sideload_data( (string) $result['data'], (string) ( $result['ext'] ?? 'png' ), $topic );
		}
		return 0;
	}

	private function sideload_url( string $url, string $desc ): int {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_sideload_image( $url, 0, $desc, 'id' );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	private function sideload_data( string $binary, string $ext, string $desc ): int {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$ext      = preg_match( '/^[a-z0-9]{2,4}$/i', $ext ) ? strtolower( $ext ) : 'png';
		$filename = 'avp-' . wp_generate_password( 8, false ) . '.' . $ext;
		$upload   = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return 0;
		}
		$filetype   = wp_check_filetype( $upload['file'] );
		$attachment = [
			'post_mime_type' => $filetype['type'] ?: 'image/png',
			'post_title'     => sanitize_text_field( $desc ),
			'post_status'    => 'inherit',
		];
		$id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		return (int) $id;
	}

	/** Generates + stores AI alt text on the attachment (falls back to topic). */
	private function set_alt( int $attachment_id, string $topic ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}
		$alt = $topic;
		if ( class_exists( 'AumViso_AI_Connector' ) ) {
			$prompt = "Write concise, descriptive alt text (max 120 characters) for an image illustrating the topic below. Respond in the same language as the topic. Return ONLY the alt text, no quotes.\n\nTopic: {$topic}";
			$res    = AumViso_AI_Connector::complete( $prompt, [ 'max_tokens' => 60, 'temperature' => 0.4 ] );
			if ( ! is_wp_error( $res ) ) {
				$clean = trim( wp_strip_all_tags( (string) $res ) );
				if ( '' !== $clean ) {
					$alt = mb_substr( $clean, 0, 150 );
				}
			}
		}
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
	}

	private function ai_prompt( string $topic ): string {
		return 'A clean, professional, photorealistic image suitable as the header image for an article about: ' . $topic
			. '. No text, no watermark, no logo.';
	}

	/** A short English-ish query for stock search (first few significant words). */
	private function stock_query( string $topic ): string {
		$t     = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $topic );
		$words = array_values( array_filter( explode( ' ', trim( (string) preg_replace( '/\s+/', ' ', (string) $t ) ) ) ) );
		return implode( ' ', array_slice( $words, 0, 4 ) ) ?: $topic;
	}

	private function on_cooldown( string $key ): bool {
		return (bool) get_transient( 'avp_img_cd_' . $key );
	}

	private function set_cooldown( string $key ): void {
		set_transient( 'avp_img_cd_' . $key, 1, 10 * MINUTE_IN_SECONDS );
	}

	// ------------------------------------
	// Config tab
	// ------------------------------------

	public function render_tab(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['avp_images_save'] ) ) {
			check_admin_referer( self::NONCE );
			$this->save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized field-by-field in save().
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Image settings saved.', 'aumviso' ) . '</p></div>';
		}

		$s        = $this->settings();
		$ai_ready = self::ai_configured();

		echo '<p class="aml-tab-intro">' . esc_html__( 'Auto-add a featured image to generated drafts. Free sources (product main image, media library) are always tried first; if none matches, the provider you configure here is used. Pick what your region can reach.', 'aumviso' ) . '</p>';

		if ( ! $ai_ready ) {
			echo '<div class="aml-card"><p class="aml-card-desc">' . esc_html__( 'Tip: AI alt text reuses the AumViso AI key. Configure it in AumViso → AI Tools for best results (images still work without it).', 'aumviso' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( AumViso_Adv_Console::url( 'images' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>

			<div class="aml-card">
				<h2 class="aml-card-h"><span class="dashicons dashicons-format-image"></span><?php esc_html_e( 'Auto featured image', 'aumviso' ); ?></h2>
				<label class="aml-switch-row">
					<span class="aml-switch"><input type="checkbox" name="auto" value="1" <?php checked( $s['auto'], 1 ); ?> /><span class="aml-track" aria-hidden="true"></span></span>
					<span class="aml-switch-label"><?php esc_html_e( 'Add a featured image automatically when generating drafts', 'aumviso' ); ?></span>
				</label>
				<p class="aml-field" style="margin-top:12px">
					<label class="aml-label" for="avp-img-prefer"><?php esc_html_e( 'Preferred paid source', 'aumviso' ); ?></label>
					<select id="avp-img-prefer" name="prefer">
						<option value="ai_first" <?php selected( $s['prefer'], 'ai_first' ); ?>><?php esc_html_e( 'AI generation first, then stock', 'aumviso' ); ?></option>
						<option value="stock_first" <?php selected( $s['prefer'], 'stock_first' ); ?>><?php esc_html_e( 'Stock library first, then AI', 'aumviso' ); ?></option>
					</select>
				</p>
			</div>

			<div class="aml-card">
				<h2 class="aml-card-h"><span class="dashicons dashicons-superhero-alt"></span><?php esc_html_e( 'AI image generation', 'aumviso' ); ?></h2>
				<p class="aml-field">
					<label class="aml-label" for="avp-img-ai"><?php esc_html_e( 'Provider', 'aumviso' ); ?></label>
					<select id="avp-img-ai" name="ai_provider">
						<option value="" <?php selected( $s['ai_provider'], '' ); ?>><?php esc_html_e( 'Off', 'aumviso' ); ?></option>
						<option value="core" <?php selected( $s['ai_provider'], 'core' ); ?>><?php esc_html_e( 'WordPress AI Client (recommended)', 'aumviso' ); ?></option>
						<option value="dashscope" <?php selected( $s['ai_provider'], 'dashscope' ); ?>><?php esc_html_e( '通义万相 / Tongyi Wanxiang (DashScope)', 'aumviso' ); ?></option>
						<option value="cogview" <?php selected( $s['ai_provider'], 'cogview' ); ?>><?php esc_html_e( '智谱 CogView (GLM)', 'aumviso' ); ?></option>
						<option value="ark" <?php selected( $s['ai_provider'], 'ark' ); ?>><?php esc_html_e( '火山方舟 Ark (Doubao/Jimeng)', 'aumviso' ); ?></option>
						<option value="openai" <?php selected( $s['ai_provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI (gpt-image-1 / DALL·E 3)', 'aumviso' ); ?></option>
						<option value="custom" <?php selected( $s['ai_provider'], 'custom' ); ?>><?php esc_html_e( 'Custom (OpenAI-compatible endpoint)', 'aumviso' ); ?></option>
					</select>
				</p>
				<div class="aml-field-grid">
					<?php
					$this->text_field( 'dashscope_key', __( 'DashScope API key', 'aumviso' ), $s['dashscope_key'], true );
					$this->text_field( 'dashscope_model', __( 'DashScope model', 'aumviso' ), $s['dashscope_model'] );
					$this->text_field( 'cogview_key', __( 'CogView API key', 'aumviso' ), $s['cogview_key'], true );
					$this->text_field( 'cogview_model', __( 'CogView model', 'aumviso' ), $s['cogview_model'] );
					$this->text_field( 'ark_key', __( 'Ark API key', 'aumviso' ), $s['ark_key'], true );
					$this->text_field( 'ark_model', __( 'Ark model / endpoint id', 'aumviso' ), $s['ark_model'] );
					$this->text_field( 'openai_key', __( 'OpenAI API key', 'aumviso' ), $s['openai_key'], true );
					$this->text_field( 'openai_model', __( 'OpenAI image model', 'aumviso' ), $s['openai_model'] );
					$this->text_field( 'custom_endpoint', __( 'Custom endpoint URL', 'aumviso' ), $s['custom_endpoint'] );
					$this->text_field( 'custom_key', __( 'Custom API key', 'aumviso' ), $s['custom_key'], true );
					$this->text_field( 'custom_model', __( 'Custom model', 'aumviso' ), $s['custom_model'] );
					?>
				</div>
			</div>

			<div class="aml-card">
				<h2 class="aml-card-h"><span class="dashicons dashicons-images-alt2"></span><?php esc_html_e( 'Stock library', 'aumviso' ); ?></h2>
				<p class="aml-field">
					<label class="aml-label" for="avp-img-stock"><?php esc_html_e( 'Provider', 'aumviso' ); ?></label>
					<select id="avp-img-stock" name="stock_provider">
						<option value="" <?php selected( $s['stock_provider'], '' ); ?>><?php esc_html_e( 'Off', 'aumviso' ); ?></option>
						<option value="unsplash" <?php selected( $s['stock_provider'], 'unsplash' ); ?>>Unsplash</option>
						<option value="pexels" <?php selected( $s['stock_provider'], 'pexels' ); ?>>Pexels</option>
					</select>
				</p>
				<div class="aml-field-grid">
					<?php
					$this->text_field( 'unsplash_key', __( 'Unsplash Access Key', 'aumviso' ), $s['unsplash_key'], true );
					$this->text_field( 'pexels_key', __( 'Pexels API key', 'aumviso' ), $s['pexels_key'], true );
					?>
				</div>
			</div>

			<p class="aml-actions-bar">
				<button type="submit" name="avp_images_save" value="1" class="aml-btn aml-btn-primary"><?php esc_html_e( 'Save image settings', 'aumviso' ); ?></button>
			</p>
		</form>
		<?php
	}

	private function text_field( string $name, string $label, string $value, bool $secret = false ): void {
		printf(
			'<div class="aml-field"><label class="aml-label" for="avp-img-%1$s">%2$s</label><input type="%3$s" id="avp-img-%1$s" name="%1$s" class="regular-text" value="%4$s" autocomplete="off" /></div>',
			esc_attr( $name ),
			esc_html( $label ),
			$secret ? 'password' : 'text',
			esc_attr( $value )
		);
	}

	private static function ai_configured(): bool {
		return AumViso_Adv_Factory::ai_configured();
	}
}
