<?php
defined( 'ABSPATH' ) || exit;

class AumViso_AI_Generator {

    // ------------------------------------
    // FAQ generation
    // ------------------------------------

    public static function generate_faqs( string $title, string $content, int $count = 5 ): array|WP_Error {
        $excerpt = wp_trim_words( wp_strip_all_tags( $content ), 100 );
        $prompt  = "Generate {$count} FAQ questions and answers based on the following content. Each Q&A pair should be clear, concise, and useful for website visitors and AI search engines. Respond in the same language as the title and content. Do not add new specifications, performance claims, suitability claims, certifications, pricing, stock, lead time, warranty, or company facts beyond the supplied content.\n\nTitle: {$title}\nContent: {$excerpt}\n\nRespond ONLY with valid JSON in this exact format (no markdown):\n[\n  {\"question\": \"...\", \"answer\": \"...\"}\n]";

        $result = AumViso_AI_Connector::complete( $prompt, [ 'max_tokens' => 1500 ] );
        if ( is_wp_error( $result ) ) return $result;

        $decoded = json_decode( $result, true );
        if ( ! is_array( $decoded ) ) {
            // Try to extract JSON from response
            preg_match( '/\[.*\]/s', $result, $matches );
            $decoded = $matches ? json_decode( $matches[0], true ) : null;
        }

        return $decoded ?: new WP_Error( 'parse_error', __( 'Could not parse AI response as FAQ list.', 'aumviso' ) );
    }

    // ------------------------------------
    // Meta description generation
    // ------------------------------------

    public static function generate_meta_description( string $title, string $content ): string|WP_Error {
        $excerpt = wp_trim_words( wp_strip_all_tags( $content ), 80 );
        $prompt  = "Write a compelling, keyword-rich SEO meta description (max 155 characters) for the following page as one or two complete sentences. Respond in the same language as the title and content. Do not add new specifications, performance claims, suitability claims, certifications, pricing, stock, lead time, warranty, or company facts beyond the supplied content. Return ONLY the description text, no quotes or labels.\n\nTitle: {$title}\nContent: {$excerpt}";

        $result = AumViso_AI_Connector::complete( $prompt, [ 'max_tokens' => 100, 'temperature' => 0.6 ] );
        return $result;
    }

    // ------------------------------------
    // Related questions generation
    // ------------------------------------

    public static function generate_related_questions( string $title, string $content, int $count = 5 ): array|WP_Error {
        $excerpt = wp_trim_words( wp_strip_all_tags( $content ), 60 );
        $prompt  = "Generate {$count} related questions that users might also search for after reading the following content. Make them natural, conversational, and representative of real search queries. Respond in the same language as the title and content. Do not add new specifications, performance claims, suitability claims, certifications, pricing, stock, lead time, warranty, or company facts beyond the supplied content.\n\nTitle: {$title}\nContent: {$excerpt}\n\nRespond ONLY with valid JSON array of strings (no markdown):\n[\"question 1\", \"question 2\"]";

        $result = AumViso_AI_Connector::complete( $prompt, [ 'max_tokens' => 500 ] );
        if ( is_wp_error( $result ) ) return $result;

        $decoded = json_decode( $result, true );
        if ( ! is_array( $decoded ) ) {
            preg_match( '/\[.*\]/s', $result, $matches );
            $decoded = $matches ? json_decode( $matches[0], true ) : null;
        }

        return $decoded ?: new WP_Error( 'parse_error', __( 'Could not parse AI response.', 'aumviso' ) );
    }

    // ------------------------------------
    // Key takeaways / summary
    // ------------------------------------

    public static function generate_summary( string $title, string $content, int $points = 5 ): string|WP_Error {
        $excerpt = wp_trim_words( wp_strip_all_tags( $content ), 150 );
        $prompt  = "Generate {$points} key takeaways from the following article. Each takeaway should be a single sentence and should summarize only verified facts from the content. For product or specification pages, keep takeaways factual rather than advisory; do not tell readers to select, verify, ensure, guarantee, or use the product unless the supplied content explicitly makes that recommendation. Respond in the same language as the title and content. Do not add new specifications, performance claims, suitability claims, maintenance claims, certifications, pricing, stock, lead time, warranty, or company facts beyond the supplied content.\n\nTitle: {$title}\nContent: {$excerpt}\n\nRespond ONLY with valid JSON array of strings:\n[\"takeaway 1\", \"takeaway 2\"]";

        $result = AumViso_AI_Connector::complete( $prompt, [ 'max_tokens' => 600 ] );
        if ( is_wp_error( $result ) ) return $result;

        $decoded = json_decode( $result, true );
        if ( is_array( $decoded ) ) {
            $items = array_map( 'esc_html', $decoded );
            $html  = '<div class="aum-summary"><h3>' . esc_html__( 'Key Takeaways', 'aumviso' ) . '</h3><ul>';
            foreach ( $items as $item ) {
                $html .= '<li>' . $item . '</li>';
            }
            $html .= '</ul></div>';
            return $html;
        }

        return $result; // Return raw if not parseable
    }

    // ------------------------------------
    // Glossary definition generation
    // ------------------------------------

    public static function generate_definition( string $term ): array|WP_Error {
        $prompt = "Generate a clear, accurate definition for the following term as it would appear in a professional glossary. \n\nTerm: {$term}\n\nRespond ONLY with valid JSON (no markdown):\n{\"short_definition\": \"1-2 sentence definition\", \"full_explanation\": \"2-3 paragraph detailed explanation\"}";

        $result = AumViso_AI_Connector::complete( $prompt, [ 'max_tokens' => 800 ] );
        if ( is_wp_error( $result ) ) return $result;

        $decoded = json_decode( $result, true );
        return $decoded ?: new WP_Error( 'parse_error', __( 'Could not parse AI response.', 'aumviso' ) );
    }
}
