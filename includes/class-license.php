<?php
defined( 'ABSPATH' ) || exit;

/**
 * Offline license-key verification (Ed25519 signature, no license server).
 *
 * The plugin's source is public (self-hosted update checks require that —
 * see RSD_RB_Plugin::register_update_checker()), so functionality is gated
 * behind a signed key instead. RSD_RB_Settings::get_license_key() holds a
 * raw string of the form base64(payload-json) . '.' . base64(signature),
 * issued offline via license-tools/generate-license.php (outside this
 * plugin's folder — never shipped).
 *
 * Every call here re-verifies the signature from the raw stored key rather
 * than trusting any cached "is licensed" flag — a cached boolean would just
 * be a WP option, and anyone with DB access could flip it to bypass the
 * gate entirely.
 */
class RSD_RB_License {

    // Ed25519 public key, base64-encoded (32 raw bytes). Generated once via
    // license-tools/generate-keypair.php — safe to hardcode, a public key
    // can only verify signatures, never create valid ones.
    const PUBLIC_KEY_B64 = 'IIEJtyjEFaIElfH7gH/BoHjCxU3EA0ru3HpnXQfGUSw=';

    // -------------------------------------------------------------------------

    public static function is_valid(): bool {
        return null !== self::verify();
    }

    /**
     * Decoded license payload (e.g. ['v' => 1, 'client' => ..., 'issued_at' => ...])
     * if the stored key's signature is valid, else null.
     */
    public static function get_payload(): ?array {
        $payload_json = self::verify();
        if ( null === $payload_json ) {
            return null;
        }

        $payload = json_decode( $payload_json, true );
        return is_array( $payload ) ? $payload : null;
    }

    // -------------------------------------------------------------------------

    /**
     * Verifies the stored license key's signature.
     *
     * @return string|null The raw JSON payload on success, null on any failure
     *                      (missing key, malformed key, bad signature).
     */
    private static function verify(): ?string {
        if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
            // Fail closed rather than fatal — an unlicensed state is safer
            // than crashing the site over a missing optional extension.
            return null;
        }

        $raw = trim( (string) RSD_RB_Settings::get_license_key() );
        if ( '' === $raw || false === strpos( $raw, '.' ) ) {
            return null;
        }

        list( $payload_b64, $signature_b64 ) = explode( '.', $raw, 2 );

        $payload_json = base64_decode( $payload_b64, true );
        $signature    = base64_decode( $signature_b64, true );
        $public_key   = base64_decode( self::PUBLIC_KEY_B64, true );

        if ( false === $payload_json || false === $signature || false === $public_key ) {
            return null;
        }

        if ( SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature )
            || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key ) ) {
            return null;
        }

        try {
            $valid = sodium_crypto_sign_verify_detached( $signature, $payload_json, $public_key );
        } catch ( \SodiumException $e ) {
            return null;
        }

        return $valid ? $payload_json : null;
    }
}
