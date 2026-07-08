<?php
defined( 'ABSPATH' ) || exit;

/**
 * AES-256-CBC encryption/decryption for storing OAuth tokens at rest.
 *
 * Key precedence:
 *   1. RSD_RB_ENCRYPTION_KEY constant (define in wp-config.php — best option).
 *   2. Derived from WordPress auth salts (good on dedicated hosting; weaker on shared).
 *
 * Format stored: base64( iv [16 bytes] . ciphertext )
 */
class RSD_RB_Crypto {

    const CIPHER = 'aes-256-cbc';
    const IV_LEN = 16;

    // -------------------------------------------------------------------------

    public static function encrypt( string $plaintext ): string {
        $key = self::get_key();
        $iv  = random_bytes( self::IV_LEN );

        $ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

        if ( false === $ciphertext ) {
            throw new RuntimeException( 'AI1WM RB: encryption failed.' );
        }

        return base64_encode( $iv . $ciphertext );
    }

    public static function decrypt( string $encoded ): string {
        $key  = self::get_key();
        $data = base64_decode( $encoded, true );

        if ( false === $data || strlen( $data ) <= self::IV_LEN ) {
            throw new RuntimeException( 'AI1WM RB: decryption failed — malformed data.' );
        }

        $iv         = substr( $data, 0, self::IV_LEN );
        $ciphertext = substr( $data, self::IV_LEN );

        $plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

        if ( false === $plaintext ) {
            throw new RuntimeException( 'AI1WM RB: decryption failed — bad key or corrupt data.' );
        }

        return $plaintext;
    }

    // -------------------------------------------------------------------------

    private static function get_key(): string {
        if ( defined( 'RSD_RB_ENCRYPTION_KEY' ) && '' !== RSD_RB_ENCRYPTION_KEY ) {
            // Stretch to exactly 32 bytes regardless of what the user supplies.
            return hash( 'sha256', RSD_RB_ENCRYPTION_KEY, true );
        }

        // Derive from WP salts — unique per install, not per-request.
        $salts = '';
        foreach ( array( AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY ) as $salt ) {
            $salts .= $salt;
        }

        return hash( 'sha256', $salts . home_url(), true );
    }
}
