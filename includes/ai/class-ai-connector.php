<?php
defined( 'ABSPATH' ) || exit;

class AumViso_AI_Connector {

    /**
     * Send a prompt to the configured AI provider.
     * Returns the text response or WP_Error.
     */
    public static function complete( string $prompt, array $options = [] ): string|WP_Error {
        $config   = AumViso_Options::ai_config();
        $provider = $config['provider'];
        $api_key  = $config['key'];

        // The core AI Client keeps credentials at site level, so it needs no
        // key of ours.
        if ( 'core' !== $provider && empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'No AI API key configured. Go to SEO → Settings → AI Tools.', 'aumviso' ) );
        }

        return match ( $provider ) {
            'core'      => self::core_client( $prompt, $options ),
            'openai'    => self::openai( $prompt, $api_key, $config['model'], $options ),
            'deepseek'  => self::deepseek( $prompt, $api_key, $options ),
            default     => new WP_Error( 'unknown_provider', __( 'Unknown AI provider.', 'aumviso' ) ),
        };
    }

    /**
     * Is the WordPress core AI Client present and able to generate text?
     *
     * The client shipped in WordPress 7.0 but is explicitly optional: it
     * carries no provider of its own, so it only works once the site owner has
     * connected one. Never assume it is usable just because the function
     * exists.
     */
    public static function core_client_ready(): bool {
        if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
            return false;
        }

        $builder = wp_ai_client_prompt( 'ping' );

        return is_object( $builder )
            && method_exists( $builder, 'is_supported_for_text_generation' )
            && $builder->is_supported_for_text_generation();
    }

    // ------------------------------------
    // WordPress core AI Client (preferred)
    // ------------------------------------

    /**
     * Route the prompt through the core AI Client.
     *
     * The site owner picks and authenticates a provider once, under Settings →
     * Connectors, and every plugin on the site reuses it. We send only the
     * prompt; WordPress owns the credentials and the transport.
     */
    private static function core_client( string $prompt, array $options ): string|WP_Error {
        if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
            return new WP_Error(
                'no_ai_client',
                __( 'This site does not provide the WordPress AI Client. Update to WordPress 7.0 or newer, or choose a direct provider under SEO → Settings → AI Tools.', 'aumviso' )
            );
        }

        $builder = wp_ai_client_prompt( $prompt );

        if ( isset( $options['temperature'] ) && method_exists( $builder, 'using_temperature' ) ) {
            $builder = $builder->using_temperature( (float) $options['temperature'] );
        }

        if ( method_exists( $builder, 'is_supported_for_text_generation' ) && ! $builder->is_supported_for_text_generation() ) {
            return new WP_Error(
                'ai_client_unconfigured',
                __( 'No AI provider is connected to this site yet. Connect one under Settings → Connectors, or choose a direct provider under SEO → Settings → AI Tools.', 'aumviso' )
            );
        }

        $text = $builder->generate_text();

        if ( is_wp_error( $text ) ) {
            return $text;
        }

        return (string) $text;
    }

    // ------------------------------------
    // OpenAI
    // ------------------------------------

    private static function openai( string $prompt, string $api_key, string $model, array $options ): string|WP_Error {
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'model'       => $model ?: 'gpt-4o-mini',
                'messages'    => [
                    [ 'role' => 'system', 'content' => 'You are an expert SEO and GEO content writer. Always respond in the same language as the user prompt.' ],
                    [ 'role' => 'user',   'content' => $prompt ],
                ],
                'max_tokens'  => $options['max_tokens'] ?? 1000,
                'temperature' => $options['temperature'] ?? 0.7,
            ] ),
            'timeout' => 30,
        ] );

        return self::parse_response( $response, 'choices.0.message.content' );
    }

    // ------------------------------------
    // DeepSeek
    // ------------------------------------

    private static function deepseek( string $prompt, string $api_key, array $options ): string|WP_Error {
        $response = wp_remote_post( 'https://api.deepseek.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'model'       => 'deepseek-chat',
                'messages'    => [
                    [ 'role' => 'system', 'content' => 'You are an expert SEO and GEO content writer.' ],
                    [ 'role' => 'user',   'content' => $prompt ],
                ],
                'max_tokens'  => $options['max_tokens'] ?? 1000,
            ] ),
            'timeout' => 30,
        ] );

        return self::parse_response( $response, 'choices.0.message.content' );
    }

    // ------------------------------------
    // Response parser
    // ------------------------------------

    private static function parse_response( $response, string $path ): string|WP_Error {
        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            /* translators: %d: the HTTP status code returned by the API. */
            $msg = $body['error']['message'] ?? sprintf( __( 'API error (HTTP %d)', 'aumviso' ), $code );
            return new WP_Error( 'ai_api_error', $msg );
        }

        // Navigate dot-path
        $parts = explode( '.', $path );
        $val   = $body;
        foreach ( $parts as $part ) {
            $val = $val[ $part ] ?? null;
            if ( $val === null ) break;
        }

        return trim( (string) $val );
    }
}
