<?php
/**
 * AI SEO assistant: meta title/description generation, keyword suggestions, content improvement.
 * All requests go through PHP proxy; API key never sent to frontend. Rate limited per user.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_AI {

	const USER_META_DATE  = 'meyvora_seo_ai_calls_date';
	const USER_META_COUNT = 'meyvora_seo_ai_calls_count';
	const RATE_LIMIT      = 100;
	const NONCE_ACTION    = 'meyvora_seo_ai';

	/**
	 * @var Meyvora_SEO_Loader
	 */
	protected Meyvora_SEO_Loader $loader;

	/**
	 * @var Meyvora_SEO_Options
	 */
	protected Meyvora_SEO_Options $options;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	public function register_hooks(): void {
		if ( ! is_admin() ) {
			return;
		}
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_ai_script', 11, 1 );
		add_action( 'wp_ajax_meyvora_seo_ai_request', array( $this, 'ajax_proxy' ) );
	}

	/**
	 * Add AI script and localize. Run after meta box enqueue so we can depend on meta-box.
	 *
	 * @param string $hook_suffix
	 */
	public function enqueue_ai_script( string $hook_suffix ): void {
		if ( $hook_suffix !== 'post.php' && $hook_suffix !== 'post-new.php' ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type ?? '', $this->get_supported_post_types(), true ) ) {
			return;
		}
		$path = MEYVORA_SEO_PATH . 'admin/assets/js/meyvora-ai.js';
		if ( ! file_exists( $path ) ) {
			return;
		}
		wp_enqueue_script(
			'meyvora-seo-ai',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-ai.js',
			array( 'jquery', 'meyvora-seo-meta-box', 'meyvora-toast' ),
			MEYVORA_SEO_VERSION,
			true
		);
		$daily_limit = $this->get_effective_ai_daily_limit();
		wp_localize_script( 'meyvora-seo-ai', 'meyvoraSeoAi', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
			'rateLimit' => $daily_limit,
			'remaining' => $this->get_remaining_calls(),
			'i18n'      => array(
				'generateWithAi'    => __( 'Generate with AI', 'meyvora-seo' ),
				'suggestKeywords'   => __( 'Suggest keywords', 'meyvora-seo' ),
				'improveForSeo'     => __( 'Improve for SEO', 'meyvora-seo' ),
				'useThis'           => __( 'Use this', 'meyvora-seo' ),
				'loading'           => __( 'Generating…', 'meyvora-seo' ),
				'error'             => __( 'Something went wrong.', 'meyvora-seo' ),
				'rateLimitReached'  => __( 'Daily AI limit reached. Try again tomorrow.', 'meyvora-seo' ),
				'noApiKey'          => __( 'Add an API key in Settings → AI.', 'meyvora-seo' ),
				'titles'            => __( 'Choose a title', 'meyvora-seo' ),
				'descriptions'      => __( 'Choose a description', 'meyvora-seo' ),
				'readabilityScore'  => __( 'Readability score', 'meyvora-seo' ),
				'improvementTips'   => __( 'Improvement tips', 'meyvora-seo' ),
				'suggestedHeadings' => __( 'Suggested headings', 'meyvora-seo' ),
				'close'             => __( 'Close', 'meyvora-seo' ),
				'high'              => __( 'High', 'meyvora-seo' ),
				'medium'            => __( 'Medium', 'meyvora-seo' ),
				'low'               => __( 'Low', 'meyvora-seo' ),
				'aiAssistant'       => __( 'AI Assistant', 'meyvora-seo' ),
				'modeOutline'       => __( 'Outline', 'meyvora-seo' ),
				'modeExpand'        => __( 'Expand', 'meyvora-seo' ),
				'modeRewriteIntro'  => __( 'Rewrite Intro', 'meyvora-seo' ),
				'modeReadability'   => __( 'Readability', 'meyvora-seo' ),
				'modeToneCheck'     => __( 'Tone Check', 'meyvora-seo' ),
				'generate'         => __( 'Generate', 'meyvora-seo' ),
				'insertIntoPost'    => __( 'Insert into post', 'meyvora-seo' ),
				'copy'              => __( 'Copy', 'meyvora-seo' ),
				'copied'            => __( 'Copied!', 'meyvora-seo' ),
				'inputPlaceholder'  => __( 'Paste or type content…', 'meyvora-seo' ),
			),
		) );
	}

	private function get_supported_post_types(): array {
		return apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
	}

	/**
	 * Remaining AI calls for current user today.
	 *
	 * @return int
	 */
	/**
	 * Effective daily AI call cap (settings + filter). Not a license gate — abuse/API protection only.
	 *
	 * @return int
	 */
	private function get_effective_ai_daily_limit(): int {
		$from_opts = (int) $this->options->get( 'ai_rate_limit', self::RATE_LIMIT );
		$filtered  = (int) apply_filters( 'meyvora_seo_ai_daily_call_limit', $from_opts );
		return max( 1, $filtered );
	}

	public function get_remaining_calls(): int {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}
		// wp_date() respects WordPress timezone setting. Never use PHP date() here.
		$today = wp_date( 'Y-m-d' );
		$date  = get_user_meta( $user_id, self::USER_META_DATE, true );
		$count = (int) get_user_meta( $user_id, self::USER_META_COUNT, true );
		$limit = $this->get_effective_ai_daily_limit();
		if ( $date !== $today ) {
			return $limit;
		}
		return max( 0, $limit - $count );
	}

	/**
	 * Consume one rate-limit slot for current user.
	 *
	 * @return bool True if allowed and consumed.
	 */
	private function consume_rate_limit(): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		// wp_date() respects WordPress timezone setting. Never use PHP date() here.
		$today = wp_date( 'Y-m-d' );
		$date  = get_user_meta( $user_id, self::USER_META_DATE, true );
		$count = (int) get_user_meta( $user_id, self::USER_META_COUNT, true );
		$limit = $this->get_effective_ai_daily_limit();
		if ( $date !== $today ) {
			$count = 0;
		}
		if ( $count >= $limit ) {
			return false;
		}
		update_user_meta( $user_id, self::USER_META_DATE, $today );
		update_user_meta( $user_id, self::USER_META_COUNT, $count + 1 );
		return true;
	}

	/**
	 * AJAX proxy: validate, rate limit, call AI, return JSON.
	 */
	public function ajax_proxy(): void {
		header( 'Content-Type: application/json; charset=utf-8' );
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'meyvora-seo' ) ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'meyvora-seo' ) ) );
		}
		$action = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';
		$allowed = array( 'generate_title', 'generate_description', 'generate_desc_variants', 'suggest_keywords', 'improve_content', 'outline', 'expand_paragraph', 'rewrite_intro', 'improve_readability', 'check_tone', 'generate_faq', 'extract_schema_fields', 'classify_intent', 'competitor_gap', 'chat' );
		if ( ! in_array( $action, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid action.', 'meyvora-seo' ) ) );
		}
		if ( ! $this->consume_rate_limit() ) {
			wp_send_json_error( array( 'message' => __( 'Daily AI limit reached. Try again tomorrow.', 'meyvora-seo' ), 'code' => 'rate_limit' ) );
		}
		$api_key = $this->get_decrypted_api_key();
		if ( $api_key === '' ) {
			wp_send_json_error( array( 'message' => __( 'Add an API key in Settings → AI.', 'meyvora-seo' ), 'code' => 'no_api_key' ) );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post = $post_id ? get_post( $post_id ) : null;
		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$content = is_string( $content ) ? wp_kses_post( $content ) : '';
		$focus_keyword = isset( $_POST['focus_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['focus_keyword'] ) ) : '';
		$title = $post ? $post->post_title : ( isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '' );
		$excerpt = $post && $post->post_excerpt ? $post->post_excerpt : ( isset( $_POST['excerpt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excerpt'] ) ) : '' );
		if ( $post && $content === '' ) {
			$content = $post->post_content;
		}
		$content = wp_strip_all_tags( $content );
		$excerpt_500 = mb_strlen( $content ) > 500 ? mb_substr( $content, 0, 500 ) . '…' : $content;
		if ( $excerpt === '' && $content !== '' ) {
			$excerpt = mb_strlen( $content ) > 500 ? mb_substr( $content, 0, 500 ) : $content;
		}
		$site_name = get_bloginfo( 'name', 'display' );

		switch ( $action ) {
			case 'generate_title':
				$prompt = $this->build_title_prompt( $title, $excerpt_500, $focus_keyword, $site_name );
				$raw = $this->call_ai( $api_key, $prompt );
				$options = $this->parse_lines( $raw, 3 );
				wp_send_json_success( array( 'options' => $options ) );
				break;
			case 'generate_description':
				$prompt = $this->build_description_prompt( $excerpt_500, $focus_keyword );
				$raw = $this->call_ai( $api_key, $prompt );
				$options = $this->parse_lines( $raw, 3 );
				wp_send_json_success( array( 'options' => $options ) );
				break;
			case 'generate_desc_variants':
				$prompt = $this->build_description_prompt( $excerpt_500, $focus_keyword );
				$raw_a = $this->call_ai( $api_key, $prompt, array( 'temperature' => 0.9 ) );
				$raw_b = $this->call_ai( $api_key, $prompt, array( 'temperature' => 0.9 ) );
				$lines_a = $this->parse_lines( $raw_a, 1 );
				$lines_b = $this->parse_lines( $raw_b, 1 );
				$variant_a = isset( $lines_a[0] ) ? trim( $lines_a[0] ) : trim( $raw_a );
				$variant_b = isset( $lines_b[0] ) ? trim( $lines_b[0] ) : trim( $raw_b );
				wp_send_json_success( array( 'variant_a' => $variant_a, 'variant_b' => $variant_b ) );
				break;
			case 'suggest_keywords':
				$prompt = $this->build_keywords_prompt( $content ?: $excerpt_500 );
				$raw = $this->call_ai( $api_key, $prompt );
				$keywords = $this->parse_lines( $raw, 5 );
				$with_tier = $this->attach_volume_tier( $keywords );
				wp_send_json_success( array( 'keywords' => $with_tier ) );
				break;
			case 'improve_content':
				$prompt = $this->build_improve_prompt( $content ?: $excerpt_500, $focus_keyword );
				$raw = $this->call_ai( $api_key, $prompt );
				$improve = $this->parse_improve_response( $raw );
				wp_send_json_success( $improve );
				break;
			case 'outline':
				$prompt = $this->build_outline_prompt( $title, $focus_keyword );
				$raw = $this->call_ai( $api_key, $prompt );
				wp_send_json_success( array( 'text' => $raw ) );
				break;
			case 'expand_paragraph':
				$input = isset( $_POST['assistant_input'] ) ? wp_unslash( $_POST['assistant_input'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$input = is_string( $input ) ? wp_kses_post( $input ) : '';
				$input = wp_strip_all_tags( $input );
				$prompt = $this->build_expand_paragraph_prompt( $input );
				$raw = $this->call_ai( $api_key, $prompt );
				wp_send_json_success( array( 'text' => $raw ) );
				break;
			case 'rewrite_intro':
				$intro = $content !== '' ? ( mb_strlen( $content ) > 800 ? mb_substr( $content, 0, 800 ) . '…' : $content ) : ( isset( $_POST['assistant_input'] ) ? wp_kses_post( wp_unslash( $_POST['assistant_input'] ) ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$intro = is_string( $intro ) ? wp_strip_all_tags( $intro ) : '';
				$prompt = $this->build_rewrite_intro_prompt( $intro, $title, $focus_keyword );
				$raw = $this->call_ai( $api_key, $prompt );
				wp_send_json_success( array( 'text' => $raw ) );
				break;
			case 'improve_readability':
				$input = isset( $_POST['assistant_input'] ) ? wp_unslash( $_POST['assistant_input'] ) : ( $content ?: '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$input = is_string( $input ) ? wp_strip_all_tags( wp_kses_post( $input ) ) : '';
				$prompt = $this->build_improve_readability_prompt( $input );
				$raw = $this->call_ai( $api_key, $prompt );
				wp_send_json_success( array( 'text' => $raw ) );
				break;
			case 'check_tone':
				$input = isset( $_POST['assistant_input'] ) ? wp_unslash( $_POST['assistant_input'] ) : ( $content ?: '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$input = is_string( $input ) ? wp_strip_all_tags( wp_kses_post( $input ) ) : '';
				$prompt = $this->build_check_tone_prompt( $input );
				$raw = $this->call_ai( $api_key, $prompt );
				wp_send_json_success( array( 'text' => $raw ) );
				break;
			case 'generate_faq':
				$content_input = $content ?: $excerpt_500;
				$prompt = $this->build_faq_prompt( $content_input, $focus_keyword );
				$raw = $this->call_ai( $api_key, $prompt );
				$faq_json = $this->parse_faq_response( $raw );
				wp_send_json_success( array( 'faq' => $faq_json, 'raw' => $faq_json ) );
				break;
			case 'extract_schema_fields':
				$schema_type = sanitize_text_field( wp_unslash( $_POST['schema_type'] ?? '' ) );
				$allowed_types = array( 'HowTo', 'Recipe', 'Event', 'JobPosting' );
				if ( ! in_array( $schema_type, $allowed_types, true ) ) {
					wp_send_json_error( array( 'message' => __( 'Invalid schema type', 'meyvora-seo' ) ) );
					return;
				}
				$prompt = $this->build_schema_extract_prompt( $schema_type, $content ?: $excerpt_500 );
				$raw    = $this->call_ai( $api_key, $prompt );
				$parsed = $this->parse_schema_json_response( $raw );
				wp_send_json_success( array( 'fields' => $parsed ) );
				break;
			case 'classify_intent':
				$prompt = $this->build_intent_prompt( $title, $focus_keyword, $excerpt_500 );
				$raw    = $this->call_ai( $api_key, $prompt );
				$intent = strtolower( trim( $raw ) );
				$valid  = array( 'informational', 'navigational', 'commercial', 'transactional' );
				if ( ! in_array( $intent, $valid, true ) ) {
					$intent = 'informational';
				}
				$pid = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
				if ( $pid > 0 && current_user_can( 'edit_post', $pid ) ) {
					update_post_meta( $pid, MEYVORA_SEO_META_SEARCH_INTENT, $intent );
				}
				wp_send_json_success( array( 'intent' => $intent ) );
				break;
			case 'competitor_gap':
				$our_headings  = sanitize_textarea_field( wp_unslash( $_POST['our_headings'] ?? '' ) );
				$comp_headings = sanitize_textarea_field( wp_unslash( $_POST['comp_headings'] ?? '' ) );
				$our_wc        = isset( $_POST['our_word_count'] ) ? absint( wp_unslash( $_POST['our_word_count'] ) ) : 0;
				$comp_wc       = isset( $_POST['comp_word_count'] ) ? absint( wp_unslash( $_POST['comp_word_count'] ) ) : 0;
				$prompt        = $this->build_competitor_gap_prompt( $title, $focus_keyword, $our_headings, $comp_headings, $our_wc, $comp_wc );
				$raw           = $this->call_ai( $api_key, $prompt );
				$parsed        = $this->parse_schema_json_response( $raw );
				wp_send_json_success( array( 'analysis' => $parsed ) );
				break;
			case 'chat':
				$user_message = isset( $_POST['message'] )
					? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
				if ( $user_message === '' ) {
					wp_send_json_error( array( 'message' => 'Empty message.' ) );
					return;
				}
				$context_parts = array();
				if ( $title !== '' ) {
					$context_parts[] = "Post title: {$title}";
				}
				if ( $focus_keyword !== '' ) {
					$context_parts[] = "Focus keyword: {$focus_keyword}";
				}
				$score = (int) get_post_meta( $post_id, MEYVORA_SEO_META_SCORE, true );
				if ( $score > 0 ) {
					$context_parts[] = "Current SEO score: {$score}/100";
				}
				if ( $excerpt_500 !== '' ) {
					$context_parts[] = "Content excerpt:\n{$excerpt_500}";
				}
				$system = "You are an SEO expert assistant for the Meyvora SEO plugin. "
					. "Help the user improve their page SEO. Be concise and specific. "
					. "Context:\n" . implode( "\n", $context_parts );
				$prompt = $system . "\n\nUser question: " . $user_message;
				$raw    = $this->call_ai( $api_key, $prompt );
				wp_send_json_success( array( 'reply' => $raw ) );
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Invalid action.', 'meyvora-seo' ) ) );
		}
	}

	private function build_title_prompt( string $title, string $excerpt, string $focus_keyword, string $site_name ): string {
		return sprintf(
			"Generate exactly 3 SEO meta titles for this page. Each title must be under 60 characters. Do not number them. Return only the 3 titles, one per line.\n\nPost title: %s\nContent excerpt: %s\nFocus keyword: %s\nSite name: %s",
			$title,
			$excerpt,
			$focus_keyword,
			$site_name
		);
	}

	private function build_description_prompt( string $excerpt, string $focus_keyword ): string {
		return sprintf(
			"Generate exactly 3 meta descriptions for this page. Each 150-160 characters, compelling for search results. Do not number them. Return only the 3 descriptions, one per line.\n\nContent excerpt: %s\nFocus keyword: %s",
			$excerpt,
			$focus_keyword
		);
	}

	private function build_keywords_prompt( string $content ): string {
		return sprintf(
			"Analyze this content and suggest exactly 5 relevant SEO focus keywords or key phrases the content could rank for. Return only the 5 keywords, one per line, no numbering or bullets.\n\nContent:\n%s",
			mb_substr( $content, 0, 3000 )
		);
	}

	private function build_improve_prompt( string $content, string $focus_keyword ): string {
		return sprintf(
			"Analyze this content for SEO and readability. Respond with valid JSON only, no markdown or code block. Use this exact structure:\n{\"readability_score\": number 1-100, \"tips\": [\"tip1\", \"tip2\", \"tip3\"], \"suggested_headings\": [\"H2 or H3 suggestion 1\", \"suggestion 2\"]}\n\nContent:\n%s\nFocus keyword: %s",
			mb_substr( $content, 0, 6000 ),
			$focus_keyword
		);
	}

	private function build_outline_prompt( string $title, string $focus_keyword ): string {
		return sprintf(
			"Generate a structured article outline for a post. Return only the outline: use clear headings (H2, H3 style) one per line, with optional short bullet points under each. Do not include the article body. Do not number sections.\n\nPost title: %s\nFocus keyword: %s",
			$title,
			$focus_keyword
		);
	}

	private function build_expand_paragraph_prompt( string $paragraph ): string {
		return sprintf(
			"Rewrite and expand this short paragraph to be more detailed and informative. Keep the same tone and message. Return only the expanded paragraph, no explanations.\n\nParagraph:\n%s",
			mb_substr( $paragraph, 0, 2000 )
		);
	}

	private function build_rewrite_intro_prompt( string $intro, string $title, string $focus_keyword ): string {
		return sprintf(
			"Rewrite this post introduction to be more engaging and hook the reader. Keep it concise (1-3 paragraphs). Use the focus keyword naturally. Return only the rewritten introduction, no explanations.\n\nCurrent introduction:\n%s\n\nPost title: %s\nFocus keyword: %s",
			mb_substr( $intro, 0, 2000 ),
			$title,
			$focus_keyword
		);
	}

	private function build_improve_readability_prompt( string $text ): string {
		return sprintf(
			"Identify complex or hard-to-read sentences in this text. For each such sentence, provide a simplified rewrite on the next line. Format: one block per sentence as \"ORIGINAL: [sentence]\\nSIMPLIFIED: [rewrite]\" with a blank line between blocks. If the text is already clear, say \"Text is already clear and readable.\"\n\nText:\n%s",
			mb_substr( $text, 0, 4000 )
		);
	}

	private function build_check_tone_prompt( string $text ): string {
		return sprintf(
			"Identify the tone of this text (e.g. formal, casual, persuasive, neutral, friendly). In 1-2 short sentences suggest specific adjustments to better match a professional but approachable blog tone. Return only: first line \"Tone: [identified tone]\", then a blank line, then \"Suggestions: [your suggestions]\".\n\nText:\n%s",
			mb_substr( $text, 0, 3000 )
		);
	}

	private function build_faq_prompt( string $content, string $keyword ): string {
		return 'From the following content, generate exactly 5 frequently asked question and answer pairs that would be most useful to someone searching for: "' . $keyword . "\".\n\n"
			. "Return ONLY a valid JSON array in this exact format, no other text:\n"
			. '[{"question":"...","answer":"..."},{"question":"...","answer":"..."}]' . "\n\n"
			. "Content:\n" . mb_substr( $content, 0, 3000 );
	}

	private function parse_faq_response( string $raw ): array {
		$raw = trim( $raw );
		$raw = preg_replace( '/^```(?:json)?\s*/', '', $raw );
		$raw = preg_replace( '/```\s*$/', '', $raw );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$q = sanitize_text_field( (string) ( $item['question'] ?? '' ) );
			$a = sanitize_textarea_field( (string) ( $item['answer'] ?? '' ) );
			if ( $q !== '' && $a !== '' ) {
				$out[] = array( 'question' => $q, 'answer' => $a );
			}
		}
		return array_slice( $out, 0, 5 );
	}

	private function build_schema_extract_prompt( string $type, string $content ): string {
		$instructions = array(
			'HowTo'      => 'Extract: name, description, totalTime (ISO 8601), estimatedCost, and steps (array of {name, text}) from this how-to content. Return JSON.',
			'Recipe'     => 'Extract: recipeName, recipeYield, prepTime (ISO 8601), cookTime, ingredients (array of strings), recipeInstructions (array of strings). Return JSON.',
			'Event'      => 'Extract: name, startDate (ISO 8601), endDate, location (name), description, eventStatus, eventAttendanceMode. Return JSON.',
			'JobPosting' => 'Extract: title, description, datePosted (ISO 8601), validThrough, hiringOrganization.name, jobLocation.addressLocality, baseSalary.value. Return JSON.',
		);
		$instruction = isset( $instructions[ $type ] ) ? $instructions[ $type ] : '';
		return $instruction
			. "\nReturn ONLY a valid JSON object matching the field names above, no other text."
			. "\nContent:\n" . mb_substr( $content, 0, 3000 );
	}

	private function build_intent_prompt( string $title, string $keyword, string $excerpt ): string {
		return "Classify the search intent of this content into exactly one of these four categories:\n"
			. "informational, navigational, commercial, transactional\n"
			. "Title: {$title}\n"
			. "Focus keyword: {$keyword}\n"
			. "Excerpt: {$excerpt}\n"
			. "Return ONLY the single lowercase category word, nothing else.";
	}

	/**
	 * Build prompt for competitor content gap analysis.
	 * Expected JSON keys: missing_topics (string[]), suggested_headings (string[]), depth_gap (string), word_count_note (string).
	 *
	 * @param string $title         Our article title.
	 * @param string $keyword       Focus keyword.
	 * @param string $our_headings  Our headings (one per line).
	 * @param string $comp_headings Competitor headings (one per line).
	 * @param int    $our_wc       Our word count.
	 * @param int    $comp_wc      Competitor word count.
	 * @return string
	 */
	private function build_competitor_gap_prompt(
		string $title,
		string $keyword,
		string $our_headings,
		string $comp_headings,
		int $our_wc,
		int $comp_wc
	): string {
		return "You are an SEO expert. Compare these two articles and identify content gaps.\n"
			. "Our article title: {$title}\n"
			. "Focus keyword: {$keyword}\n"
			. "Our word count: {$our_wc}. Competitor word count: {$comp_wc}.\n"
			. "Our headings:\n{$our_headings}\n"
			. "Competitor headings:\n{$comp_headings}\n"
			. "Return ONLY valid JSON (no other text) with these keys:\n"
			. '{"missing_topics":["..."],"suggested_headings":["..."],"depth_gap":"...","word_count_note":"..."}';
	}

	private function parse_schema_json_response( string $raw ): array {
		$raw = trim( preg_replace( '/^```(?:json)?\s*/', '', preg_replace( '/```\s*$/', '', $raw ) ) );
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param string $raw Raw response text.
	 * @param int    $max Max lines to return.
	 * @return array<int, string>
	 */
	private function parse_lines( string $raw, int $max ): array {
		$lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) );
		$out = array();
		foreach ( $lines as $line ) {
			$line = preg_replace( '/^[\d\.\-\*]+\s*/', '', $line );
			if ( $line !== '' ) {
				$out[] = $line;
				if ( count( $out ) >= $max ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * @param array<int, string> $keywords
	 * @return array<int, array{keyword: string, tier: string}>
	 */
	private function attach_volume_tier( array $keywords ): array {
		$dataforseo = $this->options->get( 'dataforseo_api_key', '' );
		if ( is_string( $dataforseo ) && $dataforseo !== '' && strpos( $dataforseo, ':' ) === false ) {
			wp_send_json_error( array(
				'message' => __( 'DataForSEO API key must be in "login:password" format. Check Settings → AI.', 'meyvora-seo' ),
			) );
		}
		$result = array();
		foreach ( $keywords as $kw ) {
			$tier = '—';
			if ( is_string( $dataforseo ) && $dataforseo !== '' ) {
				$tier = $this->fetch_dataforseo_volume( $kw, $dataforseo );
			}
			$result[] = array( 'keyword' => $kw, 'tier' => $tier );
		}
		return $result;
	}

	/**
	 * Optional: call DataForSEO for search volume tier. Placeholder returns High/Medium/Low by length heuristic if API not implemented.
	 *
	 * @param string $keyword
	 * @param string $api_key
	 * @return string
	 */
	private function fetch_dataforseo_volume( string $keyword, string $api_key ): string {
		if ( strpos( $api_key, ':' ) === false ) {
			return '—';
		}
		// DataForSEO uses Basic auth: typically "login:password" in one string.
		$auth = base64_encode( $api_key );
		$body = wp_json_encode( array(
			'keywords' => array( $keyword ),
			'location_code' => 2840,
			'language_code' => 'en',
		) );
		$resp = wp_remote_post(
			'https://api.dataforseo.com/v3/keywords_data/google_ads/search_volume/live',
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Basic ' . $auth,
					'Content-Type'  => 'application/json',
				),
				'body' => $body,
			)
		);
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			$len = mb_strlen( $keyword );
			return $len <= 2 ? __( 'High', 'meyvora-seo' ) : ( $len <= 4 ? __( 'Medium', 'meyvora-seo' ) : __( 'Low', 'meyvora-seo' ) );
		}
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		$volume = isset( $json['tasks'][0]['result'][0]['search_volume'] ) ? (int) $json['tasks'][0]['result'][0]['search_volume'] : 0;
		if ( $volume >= 10000 ) {
			return __( 'High', 'meyvora-seo' );
		}
		if ( $volume >= 1000 ) {
			return __( 'Medium', 'meyvora-seo' );
		}
		return __( 'Low', 'meyvora-seo' );
	}

	/**
	 * @param string $raw
	 * @return array{readability_score: int, tips: array, suggested_headings: array}
	 */
	private function parse_improve_response( string $raw ): array {
		$raw = trim( preg_replace( '/^```\w*\s*|\s*```$/', '', $raw ) );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return array(
				'readability_score' => 0,
				'tips'              => array(),
				'suggested_headings' => array(),
			);
		}
		return array(
			'readability_score'   => isset( $data['readability_score'] ) ? max( 0, min( 100, (int) $data['readability_score'] ) ) : 0,
			'tips'                => isset( $data['tips'] ) && is_array( $data['tips'] ) ? array_slice( $data['tips'], 0, 3 ) : array(),
			'suggested_headings'  => isset( $data['suggested_headings'] ) && is_array( $data['suggested_headings'] ) ? $data['suggested_headings'] : array(),
		);
	}

	/**
	 * Call OpenAI or custom endpoint. Returns assistant message content or empty string.
	 *
	 * @param string $api_key Decrypted key.
	 * @param string $user_prompt
	 * @return string
	 */
	/**
	 * @param string $api_key   Decrypted key.
	 * @param string $user_prompt User prompt.
	 * @param array  $options   Optional. 'temperature' => float.
	 * @return string
	 */
	private function call_ai( string $api_key, string $user_prompt, array $options = array() ): string {
		$temperature = isset( $options['temperature'] ) ? (float) $options['temperature'] : 0.7;
		$provider = $this->options->get( 'ai_api_provider', 'openai' );
		$model = $this->options->get( 'ai_model', 'gpt-4o-mini' );
		$custom_url = $this->options->get( 'ai_custom_endpoint', '' );
		$system_prompt = trim( (string) $this->options->get( 'ai_custom_system_prompt', '' ) );
		$url = ( $provider === 'custom' && is_string( $custom_url ) && $custom_url !== '' )
			? $custom_url
			: 'https://api.openai.com/v1/chat/completions';
		$is_anthropic = ( strpos( $custom_url, 'anthropic.com' ) !== false );

		$messages = array();
		if ( $is_anthropic && $system_prompt !== '' ) {
			// Anthropic: system is top-level, not inside messages.
			$body = array(
				'model'        => $model,
				'max_tokens'   => 500,
				'temperature'  => $temperature,
				'system'       => $system_prompt,
				'messages'     => array(
					array( 'role' => 'user', 'content' => $user_prompt ),
				),
			);
		} else {
			// OpenAI or custom OpenAI-compatible: system as first message when set.
			if ( $system_prompt !== '' ) {
				$messages[] = array( 'role' => 'system', 'content' => $system_prompt );
			}
			$messages[] = array( 'role' => 'user', 'content' => $user_prompt );
			$body = array(
				'model'       => $model,
				'messages'    => $messages,
				'max_tokens'  => 500,
				'temperature' => $temperature,
			);
		}

		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $api_key,
		);
		$resp = wp_remote_post( $url, array(
			'timeout' => 30,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $resp ) ) {
			return '';
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$body_resp = wp_remote_retrieve_body( $resp );
		if ( $code !== 200 ) {
			return '';
		}
		$json = json_decode( $body_resp, true );
		// OpenAI/custom: choices[0].message.content; Anthropic: content[0].text
		$content = isset( $json['choices'][0]['message']['content'] ) ? $json['choices'][0]['message']['content'] : '';
		if ( $content === '' && isset( $json['content'][0]['text'] ) ) {
			$content = $json['content'][0]['text'];
		}
		return is_string( $content ) ? trim( $content ) : '';
	}

	/**
	 * Get decrypted API key. Empty if not set or invalid.
	 *
	 * @return string
	 */
	private function get_decrypted_api_key(): string {
		$enc = $this->options->get( 'ai_api_key_encrypted', '' );
		if ( ! is_string( $enc ) || $enc === '' ) {
			return '';
		}
		return $this->decrypt( $enc );
	}

	/**
	 * Encrypt with AUTH_KEY for storage.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function encrypt( string $value ): string {
		if ( $value === '' ) {
			return '';
		}
		$key = hash( 'sha256', defined( 'AUTH_KEY' ) ? AUTH_KEY : 'meyvora_seo_fallback_key', true );
		$iv = substr( hash( 'sha256', 'meyvora_seo_ai_iv_' . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ), true ), 0, 16 );
		$cipher = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return $cipher !== false ? base64_encode( $cipher ) : '';
	}

	/**
	 * Decrypt stored value.
	 *
	 * @param string $encrypted
	 * @return string
	 */
	public static function decrypt( string $encrypted ): string {
		if ( $encrypted === '' ) {
			return '';
		}
		$raw = base64_decode( $encrypted, true );
		if ( $raw === false ) {
			return '';
		}
		$key = hash( 'sha256', defined( 'AUTH_KEY' ) ? AUTH_KEY : 'meyvora_seo_fallback_key', true );
		$iv = substr( hash( 'sha256', 'meyvora_seo_ai_iv_' . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ), true ), 0, 16 );
		$dec = openssl_decrypt( $raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return $dec !== false ? $dec : '';
	}

	/**
	 * Public wrapper for automation (and other callers) to invoke AI with a decrypted key and prompt.
	 *
	 * @param string $api_key Decrypted API key.
	 * @param string $prompt  User prompt.
	 * @return string AI response or empty string on failure.
	 */
	public function call_ai_public( string $api_key, string $prompt ): string {
		return $this->call_ai( $api_key, $prompt );
	}
}
