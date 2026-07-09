<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shared OAuth2 helpers used by provider adapters.
 *
 * Tokens are stored encrypted in a WP option keyed by provider:
 *   rsd_rb_tokens_{provider_key}
 *
 * Token shape stored (JSON, then encrypted):
 *   { access_token, refresh_token, expires_at (unix timestamp) }
 */
class RSD_RB_OAuth {

    const TOKEN_EXPIRY_BUFFER = 120; // refresh if token expires within 2 minutes

    // -------------------------------------------------------------------------
    // State parameter (CSRF protection)

    /**
     * Generate and store a signed state nonce for the given provider.
     * Returns the state string to embed in the authorize URL.
     *
     * Stored as a plain option with a manually-checked expiry, not a transient.
     * When an external object cache is active, WordPress stores transients
     * ONLY in that cache — never in the DB — so a misconfigured or unreachable
     * cache (confirmed on at least one client site, where wp_cache_get()/
     * wp_cache_set() themselves didn't round-trip either) makes the value
     * vanish before the browser ever returns from the provider's consent
     * screen. A plain option always writes through to the DB regardless of
     * object cache health, so it survives that round trip on every site.
     */
    public static function create_state( string $provider_key ): string {
        $state = wp_generate_password( 32, false );
        update_option( 'rsd_rb_oauth_state_' . $provider_key, array(
            'state'   => $state,
            'expires' => time() + 10 * MINUTE_IN_SECONDS,
        ), false );
        return $state;
    }

    /**
     * Validate the state param returned by the provider.
     * Deletes the stored option on read (one-time use), same as the transient
     * this used to be.
     *
     * @throws RuntimeException On mismatch (possible CSRF).
     */
    public static function validate_state( string $provider_key, string $returned_state ): void {
        $option_name = 'rsd_rb_oauth_state_' . $provider_key;
        $stored      = get_option( $option_name, null );
        delete_option( $option_name );

        $expected = null;
        if (
            is_array( $stored )
            && ! empty( $stored['state'] )
            && ! empty( $stored['expires'] )
            && time() <= (int) $stored['expires']
        ) {
            $expected = (string) $stored['state'];
        }

        if ( null === $expected || ! hash_equals( $expected, $returned_state ) ) {
            // These are one-time nonces, not long-lived secrets, so logging the raw
            // values is safe — kept permanently since it's the only way to tell
            // "never stored / expired" apart from "stored but genuinely mismatched"
            // from the log alone.
            RSD_RB_Logger::error( sprintf(
                'OAuth state validate failed for %s — stored state %s; expected=%s; returned=%s.',
                $provider_key,
                ( null === $expected ? 'MISSING/EXPIRED' : 'present' ),
                ( null === $expected ? '(none)' : $expected ),
                $returned_state
            ) );
            throw new RuntimeException( 'OAuth state mismatch — possible CSRF attempt.' );
        }
    }

    // -------------------------------------------------------------------------
    // Token storage

    /** Persist a token set (encrypted). */
    public static function save_tokens( string $provider_key, array $tokens ): void {
        $json      = wp_json_encode( $tokens );
        $encrypted = RSD_RB_Crypto::encrypt( $json );
        update_option( 'rsd_rb_tokens_' . $provider_key, $encrypted, false );
    }

    /**
     * Retrieve the decrypted token set, or null if none stored.
     *
     * @return array{access_token:string, refresh_token:string, expires_at:int}|null
     */
    public static function get_tokens( string $provider_key ): ?array {
        $encrypted = get_option( 'rsd_rb_tokens_' . $provider_key, '' );
        if ( '' === $encrypted ) {
            return null;
        }

        try {
            $json   = RSD_RB_Crypto::decrypt( $encrypted );
            $tokens = json_decode( $json, true );
            return is_array( $tokens ) ? $tokens : null;
        } catch ( RuntimeException $e ) {
            RSD_RB_Logger::error( 'Failed to decrypt tokens for ' . $provider_key . ': ' . $e->getMessage() );
            return null;
        }
    }

    /** Delete stored tokens (disconnect). */
    public static function delete_tokens( string $provider_key ): void {
        delete_option( 'rsd_rb_tokens_' . $provider_key );
    }

    /** Whether a token set exists (not necessarily valid/refreshable). */
    public static function has_tokens( string $provider_key ): bool {
        return null !== self::get_tokens( $provider_key );
    }

    // -------------------------------------------------------------------------
    // Token lifecycle helpers

    /**
     * Return true if the stored access token is still valid (with buffer).
     */
    public static function access_token_is_valid( string $provider_key ): bool {
        $tokens = self::get_tokens( $provider_key );
        if ( ! $tokens ) {
            return false;
        }
        return time() < ( (int) $tokens['expires_at'] - self::TOKEN_EXPIRY_BUFFER );
    }

    /**
     * Exchange an auth code for tokens via a POST to $token_endpoint.
     * Returns the raw decoded response array.
     *
     * @param string $token_endpoint Full token URL.
     * @param array  $body           POST body fields (grant_type, code, redirect_uri, client_id, client_secret, …).
     * @return array Raw decoded JSON response.
     * @throws RuntimeException On HTTP error or missing access_token.
     */
    public static function exchange_code_request( string $token_endpoint, array $body ): array {
        $response = wp_remote_post( $token_endpoint, array(
            'timeout' => 30,
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'body'    => $body,
        ) );

        return self::parse_token_response( $response, 'code exchange' );
    }

    /**
     * Refresh an access token via a POST to $token_endpoint.
     *
     * @param string $token_endpoint Full token URL.
     * @param array  $body           POST body fields (grant_type=refresh_token, refresh_token, client_id, client_secret, …).
     * @return array Raw decoded JSON response.
     * @throws RuntimeException On HTTP error or invalid_grant.
     */
    public static function refresh_request( string $token_endpoint, array $body ): array {
        $response = wp_remote_post( $token_endpoint, array(
            'timeout' => 30,
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'body'    => $body,
        ) );

        return self::parse_token_response( $response, 'token refresh' );
    }

    // -------------------------------------------------------------------------

    private static function parse_token_response( $response, string $context ): array {
        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( 'OAuth ' . $context . ' HTTP error: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            throw new RuntimeException( 'OAuth ' . $context . ': non-JSON response (HTTP ' . $code . ').' );
        }

        if ( isset( $body['error'] ) ) {
            $msg = $body['error'] . ( isset( $body['error_description'] ) ? ': ' . $body['error_description'] : '' );
            throw new RuntimeException( 'OAuth ' . $context . ' error — ' . $msg );
        }

        if ( empty( $body['access_token'] ) ) {
            throw new RuntimeException( 'OAuth ' . $context . ': response missing access_token (HTTP ' . $code . ').' );
        }

        return $body;
    }
}
