<?php
/**
 * Knowledge Builder + article generation engine.
 *
 * Generates a full article from a topic (optionally grounded in pasted facts),
 * creates it as a draft, then enriches it with a meta description — "generate =
 * GEO-optimized" in two safe AJAX steps so neither request hits a PHP timeout.
 *
 * v1 is manual + draft-only by design: every piece is a draft a human reviews
 * before publishing. Scheduling, auto-publish and images are later slices.
 *
 * Reuses Lite's AumViso_AI_Connector (transport) and AumViso_AI_Generator (meta).
 */

defined( 'ABSPATH' ) || exit;

final class AumViso_Adv_Factory {

	const NONCE      = 'aumviso_adv_factory';
	const FLAG_META  = '_aumviso_adv_factory';

	private static ?AumViso_Adv_Factory $instance = null;

	/** Shared instance so the scheduler reuses the same generation path. */
	public static function instance(): AumViso_Adv_Factory {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'wp_ajax_aumviso_adv_factory_generate', [ $this, 'ajax_generate' ] );
		add_action( 'wp_ajax_aumviso_adv_factory_enrich', [ $this, 'ajax_enrich' ] );
		add_action( 'wp_ajax_aumviso_adv_factory_topics', [ $this, 'ajax_topics' ] );
	}

	public static function ai_configured(): bool {
		if ( ! method_exists( 'AumViso_Options', 'ai_config' ) ) {
			return false;
		}
		$cfg = AumViso_Options::ai_config();
		return ! empty( $cfg['key'] );
	}

	private function guard(): void {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'aumviso' ) ], 403 );
		}
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'aumviso' ) ], 403 );
		}
	}

	// ------------------------------------
	// Step 1: write article -> draft
	// ------------------------------------

	public function ajax_generate(): void {
		$this->guard();

		$topic       = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
		$kind        = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : 'faq';
		$source_type = isset( $_POST['source_type'] ) ? sanitize_key( wp_unslash( $_POST['source_type'] ) ) : 'entity';
		$source_id   = isset( $_POST['source_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_id'] ) ) : '';
		$manual      = isset( $_POST['material'] ) ? sanitize_textarea_field( wp_unslash( $_POST['material'] ) ) : '';
		$material    = $this->combine_source_material(
			$this->knowledge_builder_source_material( $source_type, $source_id ),
			$manual
		);

		$id = $this->create_knowledge_draft( $topic, $material, $kind );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( [ 'message' => $id->get_error_message() ] );
		}

		$post = get_post( $id );
		wp_send_json_success(
			[
				'id'       => $id,
				'title'    => $topic,
				'edit_url' => get_edit_post_link( $id, 'raw' ),
				'words'    => $this->estimated_word_count( wp_strip_all_tags( $post ? $post->post_content : '' ) ),
			]
		);
	}

	/**
	 * Creates a factory draft from a topic (+ optional material). Shared by the
	 * manual AJAX flow and the scheduler; returns the new post ID or WP_Error so
	 * either caller can decide how to report it.
	 */
	public function create_knowledge_draft( string $topic, string $material, string $kind ): int|WP_Error {
		$topic = trim( $topic );
		$kind  = sanitize_key( $kind );
		if ( '' === $topic ) {
			return new WP_Error( 'no_topic', __( 'Please enter a topic.', 'aumviso' ) );
		}
		if ( ! in_array( $kind, [ 'faq', 'guide' ], true ) ) {
			return new WP_Error( 'bad_kind', __( 'Choose FAQ or Guide.', 'aumviso' ) );
		}

		$grounding_material = $this->combine_source_material(
			$this->profile_material(),
			$this->knowledge_material_for_topic( $topic ),
			$material
		);
		if ( '' === trim( $grounding_material ) ) {
			return new WP_Error( 'no_facts', __( 'Add first-layer facts in Setup or paste source notes before building knowledge drafts.', 'aumviso' ) );
		}

		return match ( $kind ) {
			'guide'    => $this->create_guide_draft( $topic, $grounding_material ),
			default    => $this->create_faq_draft( $topic, $grounding_material ),
		};
	}

	private function create_faq_draft( string $topic, string $material ): int|WP_Error {
		if ( ! post_type_exists( 'aumviso_faq' ) ) {
			return new WP_Error( 'missing_cpt', __( 'FAQ content type is not available.', 'aumviso' ) );
		}

		$prompt = $this->knowledge_prompt(
			'FAQ',
			$topic,
			$material,
			'Return ONLY valid JSON: {"question":"...","answer":"..."}'
		);
		$result = AumViso_AI_Connector::complete( $prompt, [ 'max_tokens' => 1200, 'temperature' => 0.4 ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = $this->json_from_model( (string) $result );
		$q    = sanitize_text_field( (string) ( $data['question'] ?? $topic ) );
		$a    = wp_kses_post( (string) ( $data['answer'] ?? $result ) );

		$id = wp_insert_post(
			[
				'post_title'   => $q,
				'post_content' => $a,
				'post_status'  => 'draft',
				'post_type'    => 'aumviso_faq',
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_aumviso_faq_answer', $a );
		$this->mark_knowledge_draft( (int) $id, $topic, $material, 'faq' );
		return (int) $id;
	}

	private function create_glossary_draft( string $topic, string $material ): int|WP_Error {
		if ( ! post_type_exists( 'aumviso_glossary' ) ) {
			return new WP_Error( 'missing_cpt', __( 'Glossary content type is not available.', 'aumviso' ) );
		}

		$prompt = $this->knowledge_prompt(
			'Glossary',
			$topic,
			$material,
			'Return ONLY valid JSON: {"term":"...","short_definition":"...","full_explanation":"..."}'
		);
		$result = AumViso_AI_Connector::complete( $prompt, [ 'max_tokens' => 1400, 'temperature' => 0.35 ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data  = $this->json_from_model( (string) $result );
		$term  = sanitize_text_field( (string) ( $data['term'] ?? $topic ) );
		$short = sanitize_text_field( (string) ( $data['short_definition'] ?? '' ) );
		$full  = wp_kses_post( (string) ( $data['full_explanation'] ?? $result ) );

		$id = wp_insert_post(
			[
				'post_title'   => $term,
				'post_content' => $full,
				'post_status'  => 'draft',
				'post_type'    => 'aumviso_glossary',
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_aumviso_glossary_short_def', $short );
		update_post_meta( $id, '_aumviso_glossary_full_explanation', wp_strip_all_tags( $full ) );
		$this->mark_knowledge_draft( (int) $id, $topic, $material, 'glossary' );
		return (int) $id;
	}

	private function create_guide_draft( string $topic, string $material ): int|WP_Error {
		if ( ! post_type_exists( 'aumviso_guide' ) ) {
			return new WP_Error( 'missing_cpt', __( 'Guide content type is not available.', 'aumviso' ) );
		}

		$prompt = $this->knowledge_prompt(
			'Guide',
			$topic,
			$material,
			'Return ONLY valid JSON: {"title":"...","intro":"...","time_minutes":30,"difficulty":"beginner","tools":["..."],"materials":["..."],"steps":[{"name":"...","text":"..."}]}'
		);
		$result = AumViso_AI_Connector::complete( $prompt, [ 'max_tokens' => 2200, 'temperature' => 0.45 ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data   = $this->json_from_model( (string) $result );
		$title  = sanitize_text_field( (string) ( $data['title'] ?? $topic ) );
		$intro  = wp_kses_post( (string) ( $data['intro'] ?? '' ) );
		$steps  = [];
		foreach ( (array) ( $data['steps'] ?? [] ) as $step ) {
			$name = sanitize_text_field( (string) ( $step['name'] ?? '' ) );
			$text = sanitize_textarea_field( (string) ( $step['text'] ?? '' ) );
			if ( '' !== $name || '' !== $text ) {
				$steps[] = [ 'name' => $name, 'text' => $text ];
			}
		}

		$content = '' !== $intro ? '<p>' . esc_html( wp_strip_all_tags( $intro ) ) . '</p>' : '';
		if ( $steps ) {
			$content .= '<h2>' . esc_html__( 'Steps', 'aumviso' ) . '</h2><ol>';
			foreach ( $steps as $step ) {
				$content .= '<li><strong>' . esc_html( $step['name'] ) . '</strong><br>' . esc_html( $step['text'] ) . '</li>';
			}
			$content .= '</ol>';
		}

		$id = wp_insert_post(
			[
				'post_title'   => $title,
				'post_content' => wp_kses_post( $content ),
				'post_status'  => 'draft',
				'post_type'    => 'aumviso_guide',
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_aumviso_guide_time', max( 1, (int) ( $data['time_minutes'] ?? 30 ) ) );
		update_post_meta( $id, '_aumviso_guide_difficulty', in_array( $data['difficulty'] ?? '', [ 'beginner', 'intermediate', 'advanced' ], true ) ? $data['difficulty'] : 'beginner' );
		update_post_meta( $id, '_aumviso_guide_tools', array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $data['tools'] ?? [] ) ) ) ) );
		update_post_meta( $id, '_aumviso_guide_materials', array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $data['materials'] ?? [] ) ) ) ) );
		update_post_meta( $id, '_aumviso_guide_steps', $steps );
		$this->mark_knowledge_draft( (int) $id, $topic, $material, 'guide' );
		return (int) $id;
	}

	private function mark_knowledge_draft( int $id, string $topic, string $material, string $kind ): void {
		update_post_meta( $id, self::FLAG_META, 1 );
		update_post_meta( $id, '_aumviso_adv_knowledge_builder', 1 );
		update_post_meta( $id, '_aumviso_adv_knowledge_kind', $kind );
		update_post_meta( $id, '_aumviso_adv_factory_topic', $topic );
		update_post_meta( $id, '_aumviso_adv_factory_material', $material );
	}

	private function knowledge_prompt( string $type, string $topic, string $material, string $format ): string {
		return "You help build a verified website knowledge base. Create a {$type} draft about:\n\"{$topic}\"\n\n"
			. "Trusted source facts:\n{$material}\n\n"
			. "Rules:\n"
			. "- Use only the trusted source facts for company facts, product specifications, certifications, warranty, MOQ, lead time, target markets and claims.\n"
			. "- If a useful detail is missing, say it should be confirmed instead of inventing it.\n"
			. "- Write in the same language as the topic.\n"
			. "- This is a draft for human review, not a published final answer.\n"
			. $format;
	}

	private function json_from_model( string $raw ): array {
		$raw = trim( $raw );
		$data = json_decode( $raw, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		if ( preg_match( '/\{.*\}/s', $raw, $matches ) ) {
			$data = json_decode( $matches[0], true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return [];
	}

	public function create_draft( string $topic, string $material, string $post_type, int $words ): int|WP_Error {
		$topic = trim( $topic );
		$words = max( 200, min( 3000, $words ) );
		if ( '' === $topic ) {
			return new WP_Error( 'no_topic', __( 'Please enter a topic.', 'aumviso' ) );
		}
		$post_type = $this->factory_post_type( $post_type );
		if ( '' === $post_type ) {
			return new WP_Error( 'bad_post_type', __( 'Automation creates articles and pages only. Products and knowledge-base entries must be written or reviewed by the site owner.', 'aumviso' ) );
		}

		$grounding_material = $this->combine_source_material(
			$this->profile_material(),
			$material,
			$this->knowledge_material_for_topic( $topic )
		);

		$prompt = $this->article_prompt( $topic, $grounding_material, $post_type, $words );
		$result = AumViso_AI_Connector::complete( $prompt, [ 'max_tokens' => 3000, 'temperature' => 0.7 ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$html = $this->clean_html( (string) $result );
		if ( '' === wp_strip_all_tags( $html ) ) {
			return new WP_Error( 'empty', __( 'The model returned no usable content.', 'aumviso' ) );
		}

		$id = wp_insert_post(
			[
				'post_title'   => $topic,
				'post_content' => $html,
				'post_status'  => 'draft',
				'post_type'    => $post_type,
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, self::FLAG_META, 1 );

		$focus_keyword = $this->focus_keyword_from_topic( $topic );
		$seo_title     = $this->seo_title_from_topic( $topic, $focus_keyword );
		update_post_meta( $id, '_aumviso_seo_title', $seo_title );
		update_post_meta( $id, '_aumviso_og_title', $seo_title );
		update_post_meta( $id, '_aumviso_adv_factory_topic', $topic );
		update_post_meta( $id, '_aumviso_adv_factory_material', $grounding_material );
		if ( '' !== trim( $material ) ) {
			update_post_meta( $id, '_aumviso_adv_factory_manual_material', $material );
		}
		if ( '' !== $focus_keyword ) {
			update_post_meta( $id, '_aumviso_focus_kw', $focus_keyword );
		}

		$this->apply_featured_image( $id, $topic, $post_type, $grounding_material );

		return (int) $id;
	}

	// ------------------------------------
	// Step 2: enrich draft with meta description
	// ------------------------------------

	public function ajax_enrich(): void {
		$this->guard();

		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'meta';
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || (int) get_post_meta( $id, self::FLAG_META, true ) !== 1 ) {
			wp_send_json_error( [ 'message' => __( 'Draft not found.', 'aumviso' ) ] );
		}

		$result = $this->enrich_op( $id, $op );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success( array_merge( [ 'id' => $id, 'op' => $op ], (array) $result ) );
	}

	/**
	 * Runs one enrichment op on a factory draft and returns a small info array
	 * (or WP_Error). Shared by the manual AJAX flow and the scheduler.
	 */
	public function enrich_op( int $id, string $op ): array|WP_Error {
		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Draft not found.', 'aumviso' ) );
		}

		$title   = $post->post_title;
		$content = $post->post_content;

		// Products are fully source-only: deterministic body + deterministic meta
		// + no AI GEO sections. Skip every AI enrich op (also blocks direct AJAX).
		if ( 'aum_nexcart_product' === $post->post_type && in_array( $op, [ 'meta', 'faq', 'summary', 'related' ], true ) ) {
			return [ 'skipped' => true ];
		}

		switch ( $op ) {
			case 'meta':
				$result = AumViso_AI_Generator::generate_meta_description( $title, $content );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$desc = $this->limit_meta_description( sanitize_textarea_field( (string) $result ) );
				if ( '' !== $desc ) {
					update_post_meta( $id, '_aumviso_seo_description', $desc );
					update_post_meta( $id, '_aumviso_og_description', $desc );
					wp_update_post( [ 'ID' => $id, 'post_excerpt' => $desc ] );
				}
				return [];

			case 'faq':
				$faqs = AumViso_AI_Generator::generate_faqs( $title, $content );
				if ( is_wp_error( $faqs ) ) {
					return $faqs;
				}
				$clean = [];
				foreach ( (array) $faqs as $qa ) {
					$q = isset( $qa['question'] ) ? sanitize_text_field( $qa['question'] ) : '';
					$a = isset( $qa['answer'] ) ? wp_kses_post( $qa['answer'] ) : '';
					if ( '' !== $q && '' !== $a ) {
						$clean[] = [ 'question' => $q, 'answer' => $a ];
					}
				}
				if ( $clean ) {
					// _aumviso_inline_faqs feeds FAQPage schema; visible block is added to content.
					update_post_meta( $id, '_aumviso_inline_faqs', $clean );
					$this->append_content( $id, $this->faq_html( $clean, $title ) );
				}
				return [ 'count' => count( $clean ) ];

			case 'summary':
				$html = AumViso_AI_Generator::generate_summary( $title, $content );
				if ( is_wp_error( $html ) ) {
					return $html;
				}
				$this->append_content( $id, $this->normalize_summary_html( (string) $html, $title ) );
				return [];

			case 'related':
				$qs = AumViso_AI_Generator::generate_related_questions( $title, $content );
				if ( is_wp_error( $qs ) ) {
					return $qs;
				}
				$qs = array_values( array_filter( array_map( 'sanitize_text_field', (array) $qs ) ) );
				if ( $qs ) {
					$this->append_content( $id, $this->related_html( $qs, $title ) );
				}
				return [ 'count' => count( $qs ) ];

			default:
				return new WP_Error( 'unknown_op', __( 'Unknown step.', 'aumviso' ) );
		}
	}

	/**
	 * Topic + grounding-material items for the scheduler. Each item carries the
	 * real product data behind the topic as "material", so an auto-generated guide
	 * is grounded in facts — this is what lets full-auto enforce strict grounding
	 * (no material -> not generated). 'gaps' filters to topics with no article yet.
	 *
	 * @return array[] Each: [ 'topic' => string, 'material' => string ].
	 */
	public function auto_topic_items( string $mode ): array {
		if ( 'knowledge' === $mode ) {
			$items = [];
			foreach ( $this->suggest_knowledge_topics( 24 ) as $topic ) {
				$material = $this->combine_source_material(
					$this->profile_material(),
					$this->knowledge_material_for_topic( $topic )
				);
				if ( '' !== trim( $material ) ) {
					$items[] = [ 'topic' => $topic, 'material' => $material ];
				}
			}
			return $items;
		}

		$products = $this->collect_products();
		$titles   = 'gaps' === $mode ? $this->existing_title_index() : [];

		$items = [];
		$seen  = [];
		$push  = static function ( string $topic, string $material ) use ( &$items, &$seen ): void {
			$topic = trim( $topic );
			$k     = mb_strtolower( $topic );
			if ( '' === $topic || isset( $seen[ $k ] ) ) {
				return;
			}
			$seen[ $k ] = true;
			$items[]    = [ 'topic' => $topic, 'material' => trim( $material ) ];
		};

		// Group by category for category-level guides.
		$groups = [];
		foreach ( $products as $p ) {
			$cat = isset( $p['categories'][0]['name'] ) ? (string) $p['categories'][0]['name'] : '';
			$key = '' !== $cat ? mb_strtolower( $cat ) : '__none__';
			$groups[ $key ]['name']    = $cat;
			$groups[ $key ]['items'][] = $p;
		}

		foreach ( $groups as $grp ) {
			$cat = (string) ( $grp['name'] ?? '' );
			if ( '' === $cat ) {
				continue;
			}
			if ( 'gaps' === $mode && $this->title_covered( $titles, $cat ) ) {
				continue;
			}
			$mat = $this->product_material_bundle( $grp['items'] ?? [], 6 );
			$push( $this->has_cjk( $cat ) ? "{$cat}怎么选?选型要点指南" : "How to choose {$cat}", $mat );
		}

		foreach ( $products as $p ) {
			$title = (string) ( $p['title'] ?? '' );
			if ( '' === $title ) {
				continue;
			}
			if ( 'gaps' === $mode && $this->title_covered( $titles, $this->product_key_term( $title ) ) ) {
				continue;
			}
			$mat = $this->product_material_bundle( [ $p ], 1 );
			$push( $this->has_cjk( $title ) ? "{$title} 规格参数与选型说明" : "{$title} specifications and selection guide", $mat );
		}

		return $items;
	}

	/** Serializes normalized product records into Key: Value grounding lines. */
	private function product_material_bundle( array $products, int $limit ): string {
		$lines = [];
		$count = 0;
		foreach ( $products as $p ) {
			if ( $count >= $limit ) {
				break;
			}
			++$count;

			$title = trim( (string) ( $p['title'] ?? '' ) );
			if ( '' !== $title ) {
				$lines[] = $title . ':';
			}
			foreach ( (array) ( $p['attributes'] ?? [] ) as $a ) {
				$k = trim( (string) ( $a['key'] ?? '' ) );
				$v = trim( (string) ( $a['value'] ?? '' ) );
				if ( '' !== $k && '' !== $v ) {
					$lines[] = "- {$k}: {$v}";
				}
			}

			$price = (array) ( $p['price'] ?? [] );
			$type  = $price['type'] ?? '';
			if ( 'range' === $type && ( '' !== ( $price['min'] ?? '' ) || '' !== ( $price['max'] ?? '' ) ) ) {
				$lines[] = '- Price: ' . trim( (string) ( $price['min'] ?? '' ) . ' - ' . (string) ( $price['max'] ?? '' ) . ' ' . (string) ( $price['currency'] ?? '' ) );
			} elseif ( 'text' === $type && '' !== ( $price['text'] ?? '' ) ) {
				$lines[] = '- Price: ' . (string) $price['text'];
			}
		}
		return implode( "\n", $lines );
	}

	// ------------------------------------
	// Topic suggestions (derived from products, no AI)
	// ------------------------------------

	/**
	 * Returns buying-guide / spec / FAQ / comparison topics derived from the
	 * site's products (via the unified registry). This turns the factory from
	 * "type your own title" into "pick a suggested topic" without any AI call —
	 * the suggestions are templated from real product titles and categories.
	 */
	public function ajax_topics(): void {
		$this->guard();

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'all';
		if ( 'builder' === $mode ) {
			$source_type = isset( $_POST['source_type'] ) ? sanitize_key( wp_unslash( $_POST['source_type'] ) ) : 'entity';
			$source_id   = isset( $_POST['source_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_id'] ) ) : '';
			$kind        = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : 'faq';
			wp_send_json_success(
				[
					'topics'   => $this->suggest_builder_items( $source_type, $source_id, $kind ),
					'mode'     => $mode,
					'material' => $this->knowledge_builder_source_material( $source_type, $source_id ),
				]
			);
		}
		if ( ! class_exists( 'AumViso_Product_Registry' ) && 'knowledge' !== $mode ) {
			wp_send_json_success( [ 'topics' => [] ] );
		}

		$topics = match ( $mode ) {
			'gaps'      => $this->suggest_topic_gaps(),
			'knowledge' => $this->suggest_knowledge_topics(),
			default     => $this->suggest_topics(),
		};

		wp_send_json_success( [ 'topics' => $topics, 'mode' => $mode ] );
	}

	/**
	 * Gathers normalized products from every registered source, capped so a
	 * large catalog never stalls the request. Shared by both topic modes.
	 *
	 * @return array[] Normalized product records.
	 */
	private function collect_products( int $cap = 60 ): array {
		if ( ! class_exists( 'AumViso_Product_Registry' ) ) {
			return [];
		}

		$products = [];
		$registry = AumViso_Product_Registry::instance();

		foreach ( $registry->get_sources() as $source ) {
			foreach ( $source->query( [ 'posts_per_page' => 30 ] ) as $pid ) {
				$data = $source->get( (int) $pid );
				if ( $data && ! empty( $data['title'] ) ) {
					$products[] = $data;
				}
				if ( count( $products ) >= $cap ) {
					break 2;
				}
			}
		}

		return $products;
	}

	private function knowledge_builder_source_material( string $source_type, string $source_id ): string {
		if ( 'product' === $source_type ) {
			foreach ( $this->collect_products( 120 ) as $product ) {
				if ( (string) ( $product['id'] ?? '' ) === (string) $source_id ) {
					return "Selected product:\n" . $this->product_material_bundle( [ $product ], 1 );
				}
			}
			return '';
		}

		if ( 'category' === $source_type ) {
			$products = $this->products_for_category( $source_id );
			return $products ? "Selected product category:\n" . $this->product_material_bundle( $products, 8 ) : '';
		}

		return $this->combine_source_material(
			$this->profile_material(),
			$this->first_layer_glossary_material()
		);
	}

	private function first_layer_glossary_material(): string {
		$lines = [];
		foreach ( $this->published_knowledge_posts( 'aumviso_glossary', 20 ) as $post ) {
			$text = trim( $this->glossary_material( $post ) );
			if ( '' !== $text ) {
				$lines[] = $text;
			}
		}
		return $lines ? "Confirmed glossary terms:\n" . implode( "\n", $lines ) : '';
	}

	private function products_for_category( string $category_key, int $limit = 30 ): array {
		$category_key = mb_strtolower( trim( $category_key ) );
		if ( '' === $category_key ) {
			return [];
		}

		$matches = [];
		foreach ( $this->collect_products( 120 ) as $product ) {
			foreach ( (array) ( $product['categories'] ?? [] ) as $category ) {
				$name = mb_strtolower( trim( (string) ( $category['name'] ?? '' ) ) );
				$slug = mb_strtolower( trim( (string) ( $category['slug'] ?? '' ) ) );
				if ( $category_key === $name || $category_key === $slug ) {
					$matches[] = $product;
					break;
				}
			}
			if ( count( $matches ) >= $limit ) {
				break;
			}
		}
		return $matches;
	}

	private function product_by_id( string $product_id ): array {
		foreach ( $this->collect_products( 120 ) as $product ) {
			if ( (string) ( $product['id'] ?? '' ) === (string) $product_id ) {
				return $product;
			}
		}
		return [];
	}

	private function product_categories(): array {
		$categories = [];
		foreach ( $this->collect_products( 120 ) as $product ) {
			foreach ( (array) ( $product['categories'] ?? [] ) as $category ) {
				$name = trim( (string) ( $category['name'] ?? '' ) );
				if ( '' === $name ) {
					continue;
				}
				$key = trim( (string) ( $category['slug'] ?? '' ) );
				if ( '' === $key ) {
					$key = $name;
				}
				$categories[ mb_strtolower( $key ) ] = [
					'key'   => $key,
					'name'  => $name,
					'count' => ( $categories[ mb_strtolower( $key ) ]['count'] ?? 0 ) + 1,
				];
			}
		}
		return array_values( $categories );
	}

	private function suggest_builder_topics( string $source_type, string $source_id, string $kind, int $limit = 8 ): array {
		$kind   = in_array( $kind, [ 'faq', 'guide' ], true ) ? $kind : 'faq';
		$topics = [];
		$add    = static function ( string $topic ) use ( &$topics, $limit ): void {
			$topic = trim( $topic );
			if ( '' !== $topic && count( $topics ) < $limit ) {
				$topics[] = $topic;
			}
		};

		if ( 'product' === $source_type ) {
			$product = $this->product_by_id( $source_id );
			$title   = trim( (string) ( $product['title'] ?? '' ) );
			if ( '' === $title ) {
				return [];
			}
			if ( 'guide' === $kind ) {
				$add( "How to confirm {$title} specifications before inquiry" );
				$add( "{$title} selection and quotation guide" );
				$add( "How to compare {$title} with similar options" );
			} else {
				$add( "What information is needed to quote {$title}?" );
				$add( "What should buyers confirm before ordering {$title}?" );
				$add( "Can {$title} be customized for industrial projects?" );
			}
			return $topics;
		}

		if ( 'category' === $source_type ) {
			$products = $this->products_for_category( $source_id );
			$cat      = '';
			foreach ( $products as $product ) {
				foreach ( (array) ( $product['categories'] ?? [] ) as $category ) {
					$name = trim( (string) ( $category['name'] ?? '' ) );
					$slug = trim( (string) ( $category['slug'] ?? '' ) );
					if ( mb_strtolower( $source_id ) === mb_strtolower( $name ) || mb_strtolower( $source_id ) === mb_strtolower( $slug ) ) {
						$cat = $name;
						break 2;
					}
				}
			}
			if ( '' === $cat ) {
				return [];
			}
			if ( 'guide' === $kind ) {
				$add( "How to choose {$cat} for industrial projects" );
				$add( "{$cat} quotation and specification checklist" );
				$add( "How to compare options in the {$cat} category" );
			} else {
				$add( "What information is needed to quote {$cat}?" );
				$add( "What should buyers confirm before sourcing {$cat}?" );
				$add( "Can {$cat} be customized for project requirements?" );
			}
			return $topics;
		}

		if ( 'entity' === $source_type && class_exists( 'AumViso_Adv_Setup' ) ) {
			$profile = AumViso_Adv_Setup::profile();
			foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $profile['core_terms'] ?? '' ) ) as $term ) {
				$term = trim( $term );
				if ( '' === $term ) {
					continue;
				}
				if ( 'guide' === $kind ) {
					$add( "How {$term} affects industrial project requirements" );
				} else {
					$add( "What should buyers know about {$term}?" );
				}
			}
		}

		if ( 'guide' === $kind ) {
			$add( 'How to prepare project requirements before requesting a quotation' );
			$add( 'How to evaluate a factory partner for custom industrial projects' );
			$add( 'How to confirm lead time, MOQ, warranty and documentation before order' );
		} else {
			$add( 'What information is needed to request a quotation?' );
			$add( 'How are lead time, MOQ and warranty confirmed?' );
			$add( 'What quality and documentation details should buyers confirm?' );
		}
		return $topics;
	}

	private function suggest_builder_items( string $source_type, string $source_id, string $kind, int $limit = 8 ): array {
		$material = $this->knowledge_builder_source_material( $source_type, $source_id );
		$items    = [];
		foreach ( $this->suggest_builder_topics( $source_type, $source_id, $kind, $limit ) as $topic ) {
			$items[] = [
				'topic'    => $topic,
				'material' => $material,
			];
		}
		return $items;
	}

	/**
	 * Builds a de-duplicated, capped list of topic strings from product data.
	 * Language is decided per item from the product's own text, so mixed-language
	 * catalogs still get sensible suggestions.
	 *
	 * @return string[]
	 */
	private function suggest_topics( int $limit = 24 ): array {
		$products = $this->collect_products();

		// Group by first category for category-level guides + comparisons.
		$groups = [];
		foreach ( $products as $p ) {
			$cat = isset( $p['categories'][0]['name'] ) ? (string) $p['categories'][0]['name'] : '';
			$key = '' !== $cat ? mb_strtolower( $cat ) : '__none__';
			$groups[ $key ]['name']    = $cat;
			$groups[ $key ]['items'][] = $p;
		}

		$topics = [];
		$seen   = [];
		$add    = static function ( string $t ) use ( &$topics, &$seen, $limit ): void {
			$t = trim( $t );
			$k = mb_strtolower( $t );
			if ( '' === $t || isset( $seen[ $k ] ) || count( $topics ) >= $limit ) {
				return;
			}
			$seen[ $k ] = true;
			$topics[]   = $t;
		};

		// Category buying guides + one comparison per category (highest value).
		foreach ( $groups as $grp ) {
			$cat = (string) ( $grp['name'] ?? '' );
			if ( '' !== $cat ) {
				$add( $this->has_cjk( $cat ) ? "{$cat}怎么选?选型要点指南" : "How to choose {$cat}" );
			}
			$items = $grp['items'] ?? [];
			if ( count( $items ) >= 2 ) {
				$a = (string) $items[0]['title'];
				$b = (string) $items[1]['title'];
				$add( $this->has_cjk( $a . $b ) ? "{$a} 与 {$b} 的区别" : "{$a} vs {$b}: which to choose" );
			}
		}

		// Per-product spec sheet + FAQ.
		foreach ( $products as $p ) {
			$title = (string) $p['title'];
			$add( $this->has_cjk( $title ) ? "{$title} 规格参数与选型说明" : "{$title} specifications and selection guide" );
			$add( $this->has_cjk( $title ) ? "{$title} 常见问题" : "{$title} FAQ" );
		}

		return array_slice( $topics, 0, $limit );
	}

	/**
	 * Content-gap topics: only the guides/FAQs for product categories and models
	 * that have NO matching article yet (post/page, any status incl. drafts). This
	 * points at what is missing rather than everything possible — the difference
	 * between "you could write" and "you should write". No AI.
	 *
	 * @return string[]
	 */
	private function suggest_topic_gaps( int $limit = 24 ): array {
		$products = $this->collect_products();
		$titles   = $this->existing_title_index();

		$topics = [];
		$seen   = [];
		$add    = static function ( string $t ) use ( &$topics, &$seen, $limit ): void {
			$t = trim( $t );
			$k = mb_strtolower( $t );
			if ( '' === $t || isset( $seen[ $k ] ) || count( $topics ) >= $limit ) {
				return;
			}
			$seen[ $k ] = true;
			$topics[]   = $t;
		};

		// Uncovered categories -> buying guide.
		$cat_done = [];
		foreach ( $products as $p ) {
			$cat = isset( $p['categories'][0]['name'] ) ? (string) $p['categories'][0]['name'] : '';
			if ( '' === $cat ) {
				continue;
			}
			$ck = mb_strtolower( $cat );
			if ( isset( $cat_done[ $ck ] ) ) {
				continue;
			}
			$cat_done[ $ck ] = true;
			if ( ! $this->title_covered( $titles, $cat ) ) {
				$add( $this->has_cjk( $cat ) ? "{$cat}怎么选?选型要点指南" : "How to choose {$cat}" );
			}
		}

		// Uncovered product models -> spec sheet + FAQ.
		foreach ( $products as $p ) {
			$title = (string) $p['title'];
			$term  = $this->product_key_term( $title );
			if ( '' === $title || $this->title_covered( $titles, $term ) ) {
				continue;
			}
			$add( $this->has_cjk( $title ) ? "{$title} 规格参数与选型说明" : "{$title} specifications and selection guide" );
			$add( $this->has_cjk( $title ) ? "{$title} 常见问题" : "{$title} FAQ" );
		}

		return array_slice( $topics, 0, $limit );
	}

	private function suggest_knowledge_topics( int $limit = 24 ): array {
		$topics = [];
		$seen   = [];
		$add    = static function ( string $topic ) use ( &$topics, &$seen, $limit ): void {
			$topic = trim( $topic );
			$key   = mb_strtolower( $topic );
			if ( '' === $topic || isset( $seen[ $key ] ) || count( $topics ) >= $limit ) {
				return;
			}
			$seen[ $key ] = true;
			$topics[]     = $topic;
		};

		foreach ( $this->published_knowledge_posts( 'aumviso_faq', 8 ) as $post ) {
			$title = get_the_title( $post );
			$add( (string) $title );
		}

		foreach ( $this->published_knowledge_posts( 'aumviso_glossary', 8 ) as $post ) {
			$term = (string) get_the_title( $post );
			$add( $this->has_cjk( $term ) ? "{$term}是什么？核心概念与常见问题" : "What is {$term}? Definition and practical examples" );
		}

		foreach ( $this->published_knowledge_posts( 'aumviso_guide', 8 ) as $post ) {
			$title = (string) get_the_title( $post );
			$add( $this->has_cjk( $title ) ? "{$title}：步骤、注意事项与常见问题" : "{$title}: steps, checklist and common questions" );
		}

		return array_slice( $topics, 0, $limit );
	}

	/** @return WP_Post[] */
	private function published_knowledge_posts( string $post_type, int $limit ): array {
		if ( ! post_type_exists( $post_type ) ) {
			return [];
		}
		return get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			]
		);
	}

	/**
	 * Lowercased titles of existing posts/pages (any status incl. drafts) used to
	 * decide whether a topic is already covered. Capped for large sites.
	 *
	 * @return string[]
	 */
	private function existing_title_index( int $cap = 500 ): array {
		$titles = [];
		$query  = new WP_Query(
			[
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => [ 'publish', 'draft', 'pending', 'future' ],
				'posts_per_page' => $cap,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		foreach ( $query->posts as $pid ) {
			$titles[] = mb_strtolower( (string) get_the_title( (int) $pid ) );
		}
		wp_reset_postdata();

		return $titles;
	}

	/** True when any existing title contains $needle (case-insensitive). */
	private function title_covered( array $titles, string $needle ): bool {
		$needle = mb_strtolower( trim( $needle ) );
		if ( '' === $needle ) {
			return true; // Nothing to look for -> treat as covered (skip).
		}
		foreach ( $titles as $t ) {
			if ( '' !== $t && false !== mb_strpos( $t, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A distinctive term for coverage matching: prefers a model-like token that
	 * contains a digit (e.g. "X200"), else falls back to the full title. Keeps
	 * "no article mentions X200" from being fooled by a longer exact title.
	 */
	private function product_key_term( string $title ): string {
		if ( preg_match( '/[\p{L}]*\d+[\p{L}\d-]*/u', $title, $m ) ) {
			return $m[0];
		}
		return $title;
	}

	/** Appends an HTML block to the draft body. */
	private function append_content( int $id, string $html ): void {
		if ( '' === trim( $html ) ) {
			return;
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return;
		}
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => $post->post_content . "\n\n" . $html,
			]
		);
	}

	private function faq_html( array $faqs, string $title = '' ): string {
		$heading = $this->has_cjk( $title ) ? '常见问题' : __( 'Frequently asked questions', 'aumviso' );
		$out = '<h2>' . esc_html( $heading ) . "</h2>\n";
		foreach ( $faqs as $qa ) {
			$out .= '<h3>' . esc_html( $qa['question'] ) . "</h3>\n";
			$out .= '<p>' . wp_kses_post( $qa['answer'] ) . "</p>\n";
		}
		return $out;
	}

	private function related_html( array $questions, string $title = '' ): string {
		$heading = $this->has_cjk( $title ) ? '相关问题' : __( 'People also ask', 'aumviso' );
		$out = '<h2>' . esc_html( $heading ) . "</h2>\n<ul>\n";
		foreach ( $questions as $q ) {
			$out .= '<li>' . esc_html( $q ) . "</li>\n";
		}
		$out .= "</ul>\n";
		return $out;
	}

	/** Converts raw/fenced JSON takeaway arrays into clean visible HTML. */
	private function normalize_summary_html( string $html, string $title = '' ): string {
		$decoded = json_decode( trim( $html ), true );
		if ( ! is_array( $decoded ) && preg_match( '/\[.*\]/s', $html, $matches ) ) {
			$decoded = json_decode( $matches[0], true );
		}

		if ( is_array( $decoded ) ) {
			$items = array_values( array_filter( array_map( 'sanitize_text_field', $decoded ) ) );
			if ( $items ) {
				$heading = $this->has_cjk( $title ) ? '要点总结' : __( 'Key takeaways', 'aumviso' );
				$out = '<div class="aum-summary"><h2>' . esc_html( $heading ) . '</h2><ul>';
				foreach ( $items as $item ) {
					$out .= '<li>' . esc_html( $item ) . '</li>';
				}
				$out .= '</ul></div>';
				return $out;
			}
		}

		if ( $this->has_cjk( $title ) ) {
			$html = preg_replace( '/<h[2-3][^>]*>\s*Key Takeaways\s*<\/h[2-3]>/i', '<h2>要点总结</h2>', $html );
		}

		return wp_kses_post( $html );
	}

	// ------------------------------------
	// Helpers
	// ------------------------------------

	private function factory_post_type( string $post_type ): string {
		$post_type = sanitize_key( $post_type );
		if ( ! in_array( $post_type, [ 'post', 'page' ], true ) ) {
			return '';
		}
		if ( ! post_type_exists( $post_type ) ) {
			return '';
		}
		$enabled = AumViso_Options::get_enabled_post_types();
		if ( ! in_array( $post_type, $enabled, true ) ) {
			return 'post' === $post_type && post_type_exists( 'post' ) ? 'post' : '';
		}
		return $post_type;
	}

	private function available_factory_post_types(): array {
		$enabled = AumViso_Options::get_enabled_post_types();
		$types   = [];
		foreach ( [ 'post', 'page' ] as $type ) {
			if ( post_type_exists( $type ) && in_array( $type, $enabled, true ) ) {
				$types[] = $type;
			}
		}
		return $types ?: [ 'post' ];
	}

	private function combine_source_material( string ...$blocks ): string {
		$lines = [];
		$seen  = [];
		foreach ( $blocks as $block ) {
			foreach ( preg_split( '/\R+/', trim( $block ) ) ?: [] as $line ) {
				$line = trim( (string) $line );
				if ( '' === $line ) {
					continue;
				}
				$key = preg_replace( '/\s+/', ' ', $line );
				$key = mb_strtolower( is_string( $key ) ? $key : $line );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$lines[]      = $line;
			}
		}
		return implode( "\n", $lines );
	}

	private function knowledge_material_for_topic( string $topic ): string {
		$sections = [];

		$product_material = $this->knowledge_products_for_topic( $topic );
		if ( '' !== $product_material ) {
			$sections[] = "Product data:\n" . $product_material;
		}

		foreach ( $this->knowledge_cpt_material_for_topic( $topic ) as $label => $text ) {
			if ( '' !== trim( $text ) ) {
				$sections[] = $label . ":\n" . trim( $text );
			}
		}

		$custom = $this->custom_cpt_material_for_topic( $topic );
		if ( '' !== trim( $custom ) ) {
			$sections[] = "Additional site sources:\n" . trim( $custom );
		}

		return implode( "\n\n", $sections );
	}

	private function profile_material(): string {
		$sections = [];
		if ( class_exists( 'AumViso_Adv_Brand' ) ) {
			$brand = AumViso_Adv_Brand::material();
			if ( '' !== trim( $brand ) ) {
				$sections[] = $brand;
			}
		}

		if ( ! class_exists( 'AumViso_Adv_Setup' ) ) {
			return implode( "\n\n", $sections );
		}
		$profile = AumViso_Adv_Setup::profile();
		$labels  = [
			'main_business'        => 'Main business',
			'service_scope'        => 'Products/services scope',
			'certifications'       => 'Certifications',
			'warranty'             => 'Warranty',
			'lead_time'            => 'Lead time',
			'moq'                  => 'MOQ',
			'factory_capabilities' => 'Factory capabilities',
			'case_notes'           => 'Case studies/proof points',
			'target_markets'       => 'Target markets',
			'customer_types'       => 'Customer types',
			'tone'                 => 'Language and tone',
			'core_terms'           => 'Core glossary terms',
		];
		$lines = [];
		foreach ( $labels as $key => $label ) {
			$value = trim( (string) ( $profile[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$lines[] = "{$label}: {$value}";
			}
		}
		if ( $lines ) {
			$sections[] = "Business facts:\n" . implode( "\n", $lines );
		}
		return implode( "\n\n", $sections );
	}

	private function knowledge_products_for_topic( string $topic ): string {
		if ( ! class_exists( 'AumViso_Product_Registry' ) ) {
			return '';
		}

		$keywords = $this->topic_keywords( $topic );
		$matches  = [];
		foreach ( $this->collect_products( 80 ) as $product ) {
			$haystack = mb_strtolower(
				wp_strip_all_tags(
					(string) ( $product['title'] ?? '' ) . ' '
					. (string) ( $product['description'] ?? '' ) . ' '
					. wp_json_encode( $product['attributes'] ?? [], JSON_UNESCAPED_UNICODE ) . ' '
					. wp_json_encode( $product['categories'] ?? [], JSON_UNESCAPED_UNICODE )
				)
			);
			foreach ( $keywords as $kw ) {
				if ( '' !== $kw && false !== mb_strpos( $haystack, mb_strtolower( $kw ) ) ) {
					$matches[] = $product;
					break;
				}
			}
			if ( count( $matches ) >= 6 ) {
				break;
			}
		}

		return $matches ? $this->product_material_bundle( $matches, 6 ) : '';
	}

	private function knowledge_cpt_material_for_topic( string $topic ): array {
		return [
			'FAQs'     => $this->knowledge_faqs_for_topic( $topic ),
			'Glossary' => $this->knowledge_posts_for_topic( 'aumviso_glossary', $topic, [ $this, 'glossary_material' ], 5 ),
			'Guides'   => $this->knowledge_posts_for_topic( 'aumviso_guide', $topic, [ $this, 'guide_material' ], 4 ),
		];
	}

	private function knowledge_faqs_for_topic( string $topic ): string {
		return $this->knowledge_posts_for_topic(
			'aumviso_faq',
			$topic,
			function ( WP_Post $post ): string {
				$answer = get_post_meta( $post->ID, '_aumviso_faq_answer', true );
				if ( '' === trim( (string) $answer ) ) {
					$answer = $post->post_content;
				}
				$q = trim( wp_strip_all_tags( $post->post_title ) );
				$a = trim( wp_strip_all_tags( (string) $answer ) );
				return '' !== $q && '' !== $a ? "- Q: {$q}\n  A: {$a}" : '';
			},
			6
		);
	}

	private function knowledge_posts_for_topic( string $post_type, string $topic, callable $formatter, int $limit ): string {
		if ( ! post_type_exists( $post_type ) ) {
			return '';
		}

		$posts = [];
		$seen  = [];
		foreach ( $this->topic_keywords( $topic ) as $keyword ) {
			$query = new WP_Query(
				[
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => $limit,
					's'              => $keyword,
					'no_found_rows'  => true,
				]
			);
			foreach ( $query->posts as $post ) {
				if ( isset( $seen[ $post->ID ] ) ) {
					continue;
				}
				$seen[ $post->ID ] = true;
				$posts[]           = $post;
				if ( count( $posts ) >= $limit ) {
					break 2;
				}
			}
			wp_reset_postdata();
		}

		$lines = [];
		foreach ( $posts as $post ) {
			$text = trim( (string) call_user_func( $formatter, $post ) );
			if ( '' !== $text ) {
				$lines[] = $text;
			}
		}
		return implode( "\n", $lines );
	}

	private function custom_cpt_material_for_topic( string $topic ): string {
		if ( ! class_exists( 'AumViso_Adv_Setup' ) ) {
			return '';
		}
		$sources = AumViso_Adv_Setup::custom_post_types();
		if ( ! $sources ) {
			return '';
		}

		$blocks = [];
		foreach ( $sources as $type => $cfg ) {
			if ( empty( $cfg['enabled'] ) || ! post_type_exists( $type ) ) {
				continue;
			}
			$limit = max( 1, min( 100, (int) ( $cfg['limit'] ?? 20 ) ) );
			$role  = sanitize_key( (string) ( $cfg['role'] ?? 'other' ) );
			$label = sanitize_text_field( (string) ( $cfg['label'] ?? $type ) );
			$text  = $this->knowledge_posts_for_topic(
				$type,
				$topic,
				function ( WP_Post $post ) use ( $role, $label ): string {
					$title   = trim( wp_strip_all_tags( $post->post_title ) );
					$excerpt = trim( wp_strip_all_tags( $post->post_excerpt ) );
					if ( '' === $excerpt ) {
						$excerpt = trim( wp_strip_all_tags( $post->post_content ) );
					}
					$excerpt = preg_replace( '/\s+/', ' ', $excerpt );
					$excerpt = mb_substr( is_string( $excerpt ) ? $excerpt : '', 0, 900 );
					if ( '' === $title && '' === $excerpt ) {
						return '';
					}
					return sprintf(
						"- Source type: %s\n  Role: %s\n  Title: %s\n  URL: %s\n  Excerpt: %s",
						$label,
						$role,
						$title,
						get_permalink( $post ),
						$excerpt
					);
				},
				$limit
			);
			if ( '' !== trim( $text ) ) {
				$blocks[] = $text;
			}
		}

		return implode( "\n", $blocks );
	}

	private function glossary_material( WP_Post $post ): string {
		$title = trim( wp_strip_all_tags( $post->post_title ) );
		$short = trim( wp_strip_all_tags( (string) get_post_meta( $post->ID, '_aumviso_glossary_short_def', true ) ) );
		$full  = trim( wp_strip_all_tags( (string) get_post_meta( $post->ID, '_aumviso_glossary_full_explanation', true ) ) );
		if ( '' === $full ) {
			$full = trim( wp_strip_all_tags( $post->post_content ) );
		}
		$parts = array_filter( [ "Term: {$title}", '' !== $short ? "Short definition: {$short}" : '', '' !== $full ? "Explanation: {$full}" : '' ] );
		return $title ? '- ' . implode( "\n  ", $parts ) : '';
	}

	private function guide_material( WP_Post $post ): string {
		$title = trim( wp_strip_all_tags( $post->post_title ) );
		$body  = trim( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ) );
		$steps = get_post_meta( $post->ID, '_aumviso_guide_steps', true );
		$lines = [ "Guide: {$title}" ];
		if ( '' !== $body ) {
			$lines[] = 'Summary: ' . $body;
		}
		foreach ( (array) $steps as $step ) {
			$name = trim( (string) ( $step['name'] ?? '' ) );
			$text = trim( wp_strip_all_tags( (string) ( $step['text'] ?? '' ) ) );
			if ( '' !== $name || '' !== $text ) {
				$lines[] = 'Step: ' . trim( $name . ' - ' . $text, " \t\n\r\0\x0B-" );
			}
		}
		return $title ? '- ' . implode( "\n  ", $lines ) : '';
	}

	private function product_fact_sheet_html( string $topic, array $attrs ): string {
		$is_cjk = $this->has_cjk( $topic . ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE ) );
		$heading_specs = $is_cjk ? '已知产品参数' : 'Known Product Specifications';
		$heading_notes = $is_cjk ? '参数说明' : 'Specification Notes';
		$heading_missing = $is_cjk ? '未提供的信息' : 'Information Not Provided';
		$intro = $is_cjk
			? sprintf( '%s 的以下内容仅根据结构化 Source material 生成。未在 Source material 中出现的信息不会作为产品事实写入。', $topic )
			: sprintf( 'The following %s information is generated only from the structured source material. Details not present in the source material are not stated as product facts.', $topic );
		$missing = $is_cjk
			? '除上表字段外，Source material 未提供其他规格、适用场景、性能承诺、认证、库存、交期、质保、安装尺寸或工作条件。如需这些信息，应向供应商确认。'
			: 'Beyond the fields above, the source material does not provide other specifications, application claims, performance promises, certifications, stock, lead time, warranty, installation dimensions, or operating conditions. Confirm those details with the supplier when needed.';

		$html = '<p>' . esc_html( $intro ) . '</p>';
		$html .= '<h2>' . esc_html( $heading_specs ) . '</h2>';
		$html .= '<table><tbody>';
		foreach ( $attrs as $attr ) {
			$key = isset( $attr['key'] ) ? (string) $attr['key'] : '';
			$value = isset( $attr['value'] ) ? (string) $attr['value'] : '';
			if ( '' === $key || '' === $value ) {
				continue;
			}
			$html .= '<tr><th scope="row">' . esc_html( $key ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		$html .= '</tbody></table>';

		$html .= '<h2>' . esc_html( $heading_notes ) . '</h2><ul>';
		foreach ( $attrs as $attr ) {
			$key = isset( $attr['key'] ) ? (string) $attr['key'] : '';
			$value = isset( $attr['value'] ) ? (string) $attr['value'] : '';
			if ( '' === $key || '' === $value ) {
				continue;
			}
			$sentence = $is_cjk ? sprintf( '%s：%s。', $key, $value ) : sprintf( '%s: %s.', $key, $value );
			$html .= '<li>' . esc_html( $sentence ) . '</li>';
		}
		$html .= '</ul>';
		$html .= '<h2>' . esc_html( $heading_missing ) . '</h2><p>' . esc_html( $missing ) . '</p>';

		return wp_kses_post( $html );
	}

	private function article_prompt( string $topic, string $material, string $post_type, int $words ): string {
		$grounding = '' !== $material
			? "Source facts from the site knowledge base and user notes:\n{$material}\n\n"
			: '';
		$focus = $this->focus_keyword_from_topic( $topic );
		$focus_instruction = '' !== $focus
			? "- Use this exact focus phrase naturally in the first sentence, and create one <h2> or <h3> heading that contains the exact focus phrase. Do not rely on the post title for this: {$focus}\n- Use the exact focus phrase 2 to 5 times total; use natural references such as the model name, the unit, or this gearbox elsewhere.\n"
			: '';

		return "You are an expert SEO/GEO writer. Write a complete, original, well-structured {$post_type} about:\n\"{$topic}\"\n\n"
			. $grounding
			. "Requirements:\n"
			. $focus_instruction
			. "- About {$words} words.\n"
			. "- Use <h2> and <h3> subheadings, <p> paragraphs, and <ul>/<li> where useful.\n"
			. "- Informative, accurate, natural; write in the same language as the topic.\n"
			. "- The automation engine creates informational articles and pages only. Do not write a product listing, a product SKU page, a catalog entry, or a product attribute table as if it were the product record.\n"
			. "- If Source facts are provided, use ONLY facts explicitly present there for product specs, brand claims, locations, founding dates, certifications, warranties, materials, product families, and availability.\n"
			. "- Do not add company history, geography, certifications, warranty terms, product type, model capabilities, or purchase details unless the Source facts explicitly say them.\n"
			. "- Do not infer target industries, use cases, installation environments, lead times, stock status, or corrosion/cleaning claims from materials or IP ratings unless Source facts explicitly name them.\n"
			. "- You may explain a rating or specification literally, but do not turn it into suitability, ruggedness, durability, long-life, continuous-operation, or harsh-environment claims unless Source facts explicitly say so.\n"
			. "- When a useful detail is missing from Source facts, say that it should be confirmed with the supplier instead of inventing it.\n"
			. "- Output ONLY the article body HTML. No <html>, <head>, <body>, no <h1> title, no markdown code fences.";
	}

	/**
	 * Best-effort keyword seed for the SEO score panel. It intentionally stays
	 * simple; users can still edit it in the post sidebar.
	 */
	private function focus_keyword_from_topic( string $topic ): string {
		$keyword = strtolower( trim( wp_strip_all_tags( $topic ) ) );
		$keyword = preg_replace( '/[^\p{L}\p{N}\s-]+/u', ' ', $keyword );
		$keyword = preg_replace( '/\s+/', ' ', (string) $keyword );
		$keyword = preg_replace( '/^(how to choose|how to select|how to|what is|guide to|choosing|selecting)\s+(an|a|the)?\s*/i', '', (string) $keyword );
		$keyword = preg_replace( '/\b(selection guide|buyer guide|buying guide|guide|checklist|overview)\b/i', '', (string) $keyword );
		$words   = array_values( array_filter( explode( ' ', trim( (string) $keyword ) ) ) );

		if ( count( $words ) > 3 ) {
			$words = array_slice( $words, 0, 3 );
		}

		return sanitize_text_field( implode( ' ', $words ) );
	}

	/** Prefills NexCart product fields from structured source material. */
	private function apply_nexcart_product_defaults( int $id, string $material, string $html ): void {
		if ( ! class_exists( 'AUM_NexCart_Meta' ) ) {
			return;
		}

		$attrs = $this->extract_product_attributes( $material );
		if ( $attrs ) {
			AUM_NexCart_Meta::save_attributes( $id, $attrs );
		}

		$price = $this->derive_product_price( $attrs, $material );
		if ( $price ) {
			AUM_NexCart_Meta::save_price( $id, $price );
		}
	}

	/**
	 * Derives a NexCart price block from source material only (no AI, no
	 * guessing beyond what the material states):
	 *  - an explicit price/价格 attribute with numbers -> a numeric "range" price;
	 *  - an inquiry / contact-for-price / no-online-checkout signal -> a "text"
	 *    price showing an inquiry label (the B2B default for this catalog).
	 * Returns null to leave the product at NexCart's default (hidden) price.
	 */
	private function derive_product_price( array $attrs, string $material ): ?array {
		// 1. Explicit numeric price attribute -> range.
		foreach ( $attrs as $attr ) {
			$key = strtolower( (string) ( $attr['key'] ?? '' ) );
			$val = (string) ( $attr['value'] ?? '' );
			if ( preg_match( '/price|价格|售价|单价|价位/u', $key ) && preg_match( '/\d/', $val ) ) {
				$range = $this->parse_price_range( $val );
				if ( $range ) {
					return $range;
				}
			}
		}

		// 2. Inquiry-only / contact-for-price signal -> text price.
		$haystack = strtolower( $material . ' ' . (string) wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE ) );
		$signals  = [
			'inquiry', 'inquire', 'enquiry', 'request a quote', 'request quote', 'get a quote',
			'contact for price', 'contact for pricing', 'price on request', 'no online checkout',
			'not sold online', 'quote only', 'call for price',
			'询盘', '询价', '面议', '电议', '价格面议', '不在线下单', '不支持在线',
		];
		foreach ( $signals as $sig ) {
			if ( false !== strpos( $haystack, strtolower( $sig ) ) ) {
				return [
					'type' => 'text',
					'text' => $this->has_cjk( $material ) ? '询盘' : 'Inquiry only',
				];
			}
		}

		return null;
	}

	/**
	 * Parses a free-text price value like "500-800 USD" or "$1,200" into a
	 * NexCart range block. Returns null when no positive number is present.
	 */
	private function parse_price_range( string $val ): ?array {
		$currency = '';
		if ( preg_match( '/\b([A-Z]{3})\b/', strtoupper( $val ), $m ) ) {
			$currency = $m[1];
		} elseif ( preg_match( '/[$€£¥]/u', $val, $m ) ) {
			$symbols  = [ '$' => 'USD', '€' => 'EUR', '£' => 'GBP', '¥' => 'CNY' ];
			$currency = $symbols[ $m[0] ] ?? '';
		}

		preg_match_all( '/\d[\d,]*(?:\.\d+)?/', $val, $nums );
		$values = array_values(
			array_filter(
				array_map(
					static function ( $n ) {
						return (float) str_replace( ',', '', $n );
					},
					$nums[0]
				),
				static function ( $n ) {
					return $n > 0;
				}
			)
		);

		if ( ! $values ) {
			return null;
		}

		return [
			'type'     => 'range',
			'min'      => min( $values ),
			'max'      => max( $values ),
			'currency' => $currency,
		];
	}

	/**
	 * Best-effort featured image from FREE, no-dependency sources (blueprint C.7
	 * priority chain, first two links):
	 *   1. the main image of the product the topic is about (via the registry);
	 *   2. a site media-library image whose title/caption matches the topic.
	 * Sets both the featured image (_thumbnail_id) and _aumviso_og_image, since the SEO
	 * score checks each separately. AI-generated and stock-library sources slot in
	 * later behind the `aumviso_adv_factory_image` filter — no core change needed.
	 */
	private function apply_featured_image( int $id, string $topic, string $post_type, string $material ): void {
		$attachment_id = $this->image_from_product( $topic, $post_type );
		if ( ! $attachment_id ) {
			$attachment_id = $this->image_from_media_library( $topic );
		}

		/**
		 * Extension point for premium image providers (AI generation, stock
		 * libraries). A provider returns an attachment ID when the free sources
		 * found nothing. Kept as a filter so add-ons never touch factory core.
		 */
		if ( ! $attachment_id ) {
			$attachment_id = (int) apply_filters(
				'aumviso_adv_factory_image',
				0,
				[ 'post_id' => $id, 'topic' => $topic, 'post_type' => $post_type, 'material' => $material ]
			);
		}

		if ( $attachment_id <= 0 ) {
			return;
		}

		set_post_thumbnail( $id, $attachment_id );

		$url = wp_get_attachment_image_url( $attachment_id, 'large' );
		if ( $url && '' === (string) get_post_meta( $id, '_aumviso_og_image', true ) ) {
			update_post_meta( $id, '_aumviso_og_image', esc_url_raw( $url ) );
		}
	}

	/**
	 * Featured-image source 1: reuse the main image of the product a guide is
	 * about. A generated product has no source image of its own, so this only
	 * applies to Post/Page topics that name a product (matched by model token).
	 */
	private function image_from_product( string $topic, string $post_type ): int {
		if ( 'aum_nexcart_product' === $post_type || ! class_exists( 'AumViso_Product_Registry' ) ) {
			return 0;
		}
		$term = mb_strtolower( $this->product_key_term( $topic ) );
		if ( '' === $term ) {
			return 0;
		}
		foreach ( $this->collect_products() as $p ) {
			$title = mb_strtolower( (string) ( $p['title'] ?? '' ) );
			if ( '' === $title || false === mb_strpos( $title, $term ) ) {
				continue;
			}
			$pid   = (int) ( $p['id'] ?? 0 );
			$thumb = $pid ? (int) get_post_thumbnail_id( $pid ) : 0;
			if ( $thumb ) {
				return $thumb;
			}
		}
		return 0;
	}

	/**
	 * Featured-image source 2: an existing media-library image whose title or
	 * caption matches the topic keywords. Returns the first match, or 0.
	 */
	private function image_from_media_library( string $topic ): int {
		foreach ( $this->topic_keywords( $topic ) as $kw ) {
			$query = new WP_Query(
				[
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'image',
					'posts_per_page' => 1,
					's'              => $kw,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			);
			if ( ! empty( $query->posts ) ) {
				return (int) $query->posts[0];
			}
		}
		return 0;
	}

	/** Search keywords for media matching: the focus phrase + any model token. */
	private function topic_keywords( string $topic ): array {
		$keywords = [];
		$focus    = $this->focus_keyword_from_topic( $topic );
		if ( '' !== $focus ) {
			$keywords[] = $focus;
		}
		$term = $this->product_key_term( $topic );
		if ( '' !== $term && $term !== $topic ) {
			$keywords[] = $term;
		}
		return array_values( array_unique( array_filter( $keywords ) ) );
	}

	/**
	 * Deterministic Product meta description: title + the first few structured
	 * attributes, no AI call. Kept within the scorer/search-preview length by
	 * limit_meta_description(). This is what closes the last hallucination vector
	 * for products — nothing here is model-generated.
	 */
	private function product_meta_description( string $topic, array $attrs ): string {
		$is_cjk = $this->has_cjk( $topic );
		$pairs  = [];
		foreach ( $attrs as $attr ) {
			$key   = isset( $attr['key'] ) ? trim( (string) $attr['key'] ) : '';
			$value = isset( $attr['value'] ) ? trim( (string) $attr['value'] ) : '';
			if ( '' === $key || '' === $value ) {
				continue;
			}
			$pairs[] = $is_cjk ? "{$key}：{$value}" : "{$key}: {$value}";
			if ( count( $pairs ) >= 4 ) {
				break;
			}
		}

		if ( ! $pairs ) {
			return $this->limit_meta_description( $topic );
		}

		$joined = implode( $is_cjk ? '，' : ', ', $pairs );
		$desc   = $is_cjk
			? sprintf( '%s。主要参数：%s。', $topic, $joined )
			: sprintf( '%s. Key specifications: %s.', $topic, $joined );

		return $this->limit_meta_description( $desc );
	}

	private function extract_product_attributes( string $source ): array {
		$source = $this->decode_unicode_escapes( wp_strip_all_tags( $source ) );
		$attrs  = [];
		$seen   = [];
		$lines  = preg_split( '/\R+/', $source ) ?: [];

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			$line = preg_replace( '/^\s*(?:[-*]|\d+[.)])\s*/u', '', $line );
			if ( ! is_string( $line ) || '' === $line ) {
				continue;
			}

			if ( ! preg_match( '/^([^:：]{1,80})[:：]\s*(.{1,500})$/u', $line, $matches ) ) {
				continue;
			}

			$this->add_product_attribute( $attrs, $seen, $matches[1], $matches[2] );
		}

		return $attrs;
	}

	private function add_product_attribute( array &$attrs, array &$seen, string $key, string $value ): void {
		$key = trim( sanitize_text_field( $key ) );
		$value = $this->normalize_product_attribute_value( $key, $value );
		if ( '' === $key || '' === $value ) {
			return;
		}

		$slug = strtolower( $key );
		if ( isset( $seen[ $slug ] ) ) {
			return;
		}

		$attrs[] = [ 'key' => $key, 'value' => $value ];
		$seen[ $slug ] = true;
	}

	private function normalize_product_attribute_value( string $key, string $value ): string {
		$value = $this->decode_unicode_escapes( $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );
		$value = preg_replace( '/\s*\([^)]*\)\s*$/', '', (string) $value );
		return trim( (string) $value, " \t\n\r\0\x0B.-" );
	}
	private function decode_unicode_escapes( string $value ): string {
		$value = str_ireplace( [ '\\u00b7', '\u00b7', 'u00b7' ], '·', $value );
		$decoded = preg_replace_callback(
			'/\\\\u([0-9a-fA-F]{4})/',
			static function ( array $matches ): string {
				return html_entity_decode( '&#x' . $matches[1] . ';', ENT_QUOTES, 'UTF-8' );
			},
			$value
		);

		return is_string( $decoded ) ? $decoded : $value;
	}
	/** Rough content size for the draft-created status pill, including CJK text. */
	private function estimated_word_count( string $text ): int {
		$text = trim( $text );
		if ( '' === $text ) {
			return 0;
		}

		$cjk_count = preg_match_all( '/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text );
		$latin     = preg_replace( '/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', ' ', $text );

		return (int) $cjk_count + str_word_count( (string) $latin );
	}

	private function has_cjk( string $text ): bool {
		return (bool) preg_match( '/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text );
	}

	/** Builds a scorer-friendly SEO title while preserving the user's topic. */
	private function seo_title_from_topic( string $topic, string $focus_keyword ): string {
		$title = trim( wp_strip_all_tags( $topic ) );
		if ( mb_strlen( $title ) >= 30 ) {
			return sanitize_text_field( $title );
		}

		$suffix = $this->has_cjk( $title ) ? '：核心要点、操作步骤、注意事项与常见问题指南' : ( '' !== $focus_keyword ? ' for industrial buyers' : ' selection guide' );
		$title .= $suffix;
		if ( mb_strlen( $title ) > 60 ) {
			$title = trim( wp_strip_all_tags( $topic ) );
		}

		return sanitize_text_field( $title );
	}

	/** Keeps generated meta descriptions inside the scorer/search-preview limit. */
	private function limit_meta_description( string $desc ): string {
		$desc = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $desc ) ) );
		$desc = $this->remove_short_meta_tail( $desc );
		if ( mb_strlen( $desc ) <= 155 ) {
			return $desc;
		}

		$trimmed = mb_substr( $desc, 0, 155 );
		$min_len = $this->has_cjk( $trimmed ) ? 50 : 80;
		if ( preg_match( '/^(.+?[.!?。！？])\s+[^.!?。！？]*$/u', $trimmed, $matches ) && mb_strlen( $matches[1] ) >= $min_len ) {
			return rtrim( $matches[1] );
		}

		$last_space = mb_strrpos( $trimmed, ' ' );
		if ( false !== $last_space && $last_space >= $min_len ) {
			$trimmed = mb_substr( $trimmed, 0, $last_space );
		}

		return rtrim( $trimmed, " \t\n\r\0\x0B.,;:-" );
	}

	private function remove_short_meta_tail( string $desc ): string {
		$min_len = $this->has_cjk( $desc ) ? 50 : 80;
		if ( preg_match( '/^(.+[.!?。！？])\s+([^.!?。！？]{1,35})$/u', $desc, $matches ) && mb_strlen( $matches[1] ) >= $min_len ) {
			return rtrim( $matches[1] );
		}

		return $desc;
	}

	/** Strips markdown code fences and disallowed HTML from the model output. */
	private function clean_html( string $html ): string {
		$html = trim( $html );
		$html = preg_replace( '/^```[a-z]*\s*/i', '', $html );
		$html = preg_replace( '/\s*```$/', '', $html );

		return wp_kses_post( $html );
	}

	// ------------------------------------
	// Render tab
	// ------------------------------------

	public function render_tab(): void {
		if ( ! current_user_can( 'publish_posts' ) ) {
			return;
		}
		$configured = self::ai_configured();

		echo '<p class="aml-tab-intro">' . esc_html__( 'Build second-layer FAQ and Guide drafts from first-layer facts. You can type the topic and notes yourself, or have them filled in from company facts, products, categories and confirmed glossary terms.', 'aumviso' ) . '</p>';

		if ( ! $configured ) {
			printf(
				'<div class="aml-card"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'No AI API key is configured yet. Add one in AumViso to enable Knowledge Builder:', 'aumviso' ),
				esc_url( add_query_arg( [ 'page' => 'aumviso', 'tab' => 'ai' ], admin_url( 'admin.php' ) ) ),
				esc_html__( 'AumViso → AI Tools', 'aumviso' )
			);
			echo '</div>';
			return;
		}

		$products   = $this->collect_products( 120 );
		$categories = $this->product_categories();
		$this->render_knowledge_sources();
		?>
		<div class="aml-card" id="avp-factory" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
			<h2 class="aml-card-h"><span class="dashicons dashicons-welcome-learn-more"></span><?php esc_html_e( 'New knowledge draft', 'aumviso' ); ?></h2>

			<div class="aml-field">
				<label class="aml-label" for="avp-factory-source"><?php esc_html_e( 'Source to help fill fields', 'aumviso' ); ?></label>
				<select id="avp-factory-source" class="large-text">
					<option value="entity|"><?php esc_html_e( 'Company + business + glossary facts', 'aumviso' ); ?></option>
					<?php if ( $products ) : ?>
						<optgroup label="<?php esc_attr_e( 'Products', 'aumviso' ); ?>">
							<?php foreach ( $products as $product ) : ?>
								<option value="<?php echo esc_attr( 'product|' . (string) ( $product['id'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $product['title'] ?? '' ) ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endif; ?>
					<?php if ( $categories ) : ?>
						<optgroup label="<?php esc_attr_e( 'Product categories', 'aumviso' ); ?>">
							<?php foreach ( $categories as $category ) : ?>
								<option value="<?php echo esc_attr( 'category|' . (string) $category['key'] ); ?>"><?php echo esc_html( sprintf( '%1$s (%2$d)', (string) $category['name'], (int) $category['count'] ) ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endif; ?>
				</select>
				<p class="aml-field-hint"><?php esc_html_e( 'This only helps fill the topic and source notes. You can edit both fields before generating a draft.', 'aumviso' ); ?></p>
			</div>

			<div class="aml-field">
				<label class="aml-label" for="avp-factory-topic"><?php esc_html_e( 'Topic / question / term', 'aumviso' ); ?></label>
				<input type="text" id="avp-factory-topic" class="large-text" placeholder="<?php esc_attr_e( 'e.g. What is the MOQ for custom gearbox orders?', 'aumviso' ); ?>" />
				<p style="margin:8px 0 0">
					<button type="button" class="button" id="avp-factory-fill"><span class="dashicons dashicons-lightbulb" style="vertical-align:text-top"></span> <?php esc_html_e( 'Suggest topic + source notes from selected source', 'aumviso' ); ?></button>
				</p>
				<div id="avp-factory-topics" style="display:none;margin-top:8px"></div>
			</div>

			<div class="aml-field" style="margin-top:12px">
				<label class="aml-label" for="avp-factory-material"><?php esc_html_e( 'Extra source notes (optional)', 'aumviso' ); ?></label>
				<textarea id="avp-factory-material" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'Paste verified facts, customer notes, or source details for this knowledge draft.', 'aumviso' ); ?>"></textarea>
				<p class="aml-field-hint"><?php esc_html_e( 'Manual notes are allowed. The helper button can fill this from first-layer facts, products, categories and confirmed glossary terms.', 'aumviso' ); ?></p>
			</div>

			<div class="aml-field-grid" style="margin-top:12px">
				<div class="aml-field">
					<label class="aml-label" for="avp-factory-kind"><?php esc_html_e( 'Draft type', 'aumviso' ); ?></label>
					<select id="avp-factory-kind">
						<option value="faq"><?php esc_html_e( 'FAQ', 'aumviso' ); ?></option>
						<option value="guide"><?php esc_html_e( 'Guide', 'aumviso' ); ?></option>
					</select>
				</div>
			</div>

			<p class="aml-actions-bar" style="margin-top:16px">
				<button type="button" class="aml-btn aml-btn-primary" id="avp-factory-run"><span class="dashicons dashicons-superhero-alt"></span> <?php esc_html_e( 'Generate knowledge draft', 'aumviso' ); ?></button>
			</p>

			<div id="avp-factory-status" class="avp-stamp" style="display:none"></div>
			<div id="avp-factory-result" style="display:none"></div>
		</div>

		<?php $this->render_recent(); ?>
		<?php
	}

	private function render_knowledge_sources(): void {
		$products = 0;
		if ( class_exists( 'AumViso_Product_Registry' ) ) {
			$products = (int) AumViso_Product_Registry::instance()->total_count();
		}

		$counts = [
			__( 'Products', 'aumviso' ) => $products,
			__( 'FAQs', 'aumviso' )     => post_type_exists( 'aumviso_faq' ) ? (int) ( wp_count_posts( 'aumviso_faq' )->publish ?? 0 ) : 0,
			__( 'Glossary', 'aumviso' ) => post_type_exists( 'aumviso_glossary' ) ? (int) ( wp_count_posts( 'aumviso_glossary' )->publish ?? 0 ) : 0,
			__( 'Guides', 'aumviso' )   => post_type_exists( 'aumviso_guide' ) ? (int) ( wp_count_posts( 'aumviso_guide' )->publish ?? 0 ) : 0,
		];

		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-database"></span>' . esc_html__( 'Knowledge sources', 'aumviso' ) . '</h2>';
		echo '<p class="aml-card-desc">' . esc_html__( 'First-layer sources include Brand Entity, business facts, products and Glossary. FAQ and Guide drafts become trusted sources only after you review and publish them.', 'aumviso' ) . '</p>';
		echo '<div class="avp-stat-grid">';
		foreach ( $counts as $label => $count ) {
			$this->stat( (int) $count, (string) $label );
		}
		echo '</div>';

		echo '<p class="aml-actions-bar" style="margin-top:14px">';
		if ( post_type_exists( 'aum_nexcart_product' ) ) {
			printf( '<a class="aml-btn" href="%s"><span class="dashicons dashicons-products"></span> %s</a> ', esc_url( admin_url( 'edit.php?post_type=aum_nexcart_product' ) ), esc_html__( 'Manage NexCart products', 'aumviso' ) );
		}
		if ( post_type_exists( 'product' ) ) {
			printf( '<a class="aml-btn" href="%s"><span class="dashicons dashicons-cart"></span> %s</a> ', esc_url( admin_url( 'edit.php?post_type=product' ) ), esc_html__( 'Manage Woo products', 'aumviso' ) );
		}
		if ( post_type_exists( 'aumviso_faq' ) ) {
			printf( '<a class="aml-btn" href="%s">%s</a> ', esc_url( admin_url( 'edit.php?post_type=aumviso_faq' ) ), esc_html__( 'FAQs', 'aumviso' ) );
		}
		if ( post_type_exists( 'aumviso_glossary' ) ) {
			printf( '<a class="aml-btn" href="%s">%s</a> ', esc_url( admin_url( 'edit.php?post_type=aumviso_glossary' ) ), esc_html__( 'Glossary', 'aumviso' ) );
		}
		if ( post_type_exists( 'aumviso_guide' ) ) {
			printf( '<a class="aml-btn" href="%s">%s</a>', esc_url( admin_url( 'edit.php?post_type=aumviso_guide' ) ), esc_html__( 'Guides', 'aumviso' ) );
		}
		echo '</p>';
		echo '</div>';
	}

	private function stat( int $value, string $label ): void {
		printf(
			'<div class="avp-stat"><div class="avp-stat-num %1$s">%2$s</div><div class="avp-stat-label">%3$s</div></div>',
			$value > 0 ? 'is-ok' : 'is-warn',
			esc_html( (string) $value ),
			esc_html( $label )
		);
	}

	private function render_recent(): void {
		$recent = get_posts(
			[
				'post_type'      => 'any',
				'post_status'    => [ 'draft', 'pending', 'publish' ],
				'posts_per_page' => 10,
				'meta_key'       => self::FLAG_META,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		if ( empty( $recent ) ) {
			return;
		}

		echo '<div class="aml-card">';
		echo '<h2 class="aml-card-h"><span class="dashicons dashicons-backup"></span>' . esc_html__( 'Recent factory drafts', 'aumviso' ) . '</h2>';
		echo '<table class="avp-table"><tbody>';
		foreach ( $recent as $post ) {
			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td class="num">%3$s</td></tr>',
				esc_url( (string) get_edit_post_link( $post->ID ) ),
				esc_html( get_the_title( $post ) ?: __( '(no title)', 'aumviso' ) ),
				esc_html( get_post_status( $post ) )
			);
		}
		echo '</tbody></table>';
		echo '</div>';
	}
}
