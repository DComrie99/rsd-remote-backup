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

    /**
     * @throws RSD_RB_Crypto_Malformed_Exception If $encoded isn't shaped like
     *         our ciphertext format at all (not valid base64, or too short to
     *         contain an IV) — safe for callers to treat this specific case
     *         as legacy plaintext stored before encryption existed in this
     *         plugin (a real client secret value structurally can't collide
     *         with this — see decrypt_stored_secret() in class-settings.php).
     * @throws RuntimeException If $encoded IS shaped like our ciphertext but
     *         neither the current nor legacy key can decrypt it. NOT safe to
     *         treat as plaintext — the raw ciphertext blob is not a usable
     *         secret, and returning it as one is exactly what silently broke
     *         OAuth reconnection on a live site (see get_legacy_key() below).
     *
     * @param bool|null $used_legacy_key Set by reference to true if recovery
     *        only succeeded via the legacy key formula — callers should
     *        re-save via encrypt() in that case to migrate off it, so
     *        subsequent reads (some of which, like token validity checks,
     *        run frequently) don't need the fallback again. Always set to
     *        false before either key is attempted, so it's meaningful even
     *        if a caller inspects it after a caught exception.
     */
    public static function decrypt( string $encoded, ?bool &$used_legacy_key = null ): string {
        $used_legacy_key = false;
        $data            = base64_decode( $encoded, true );

        if ( false === $data || strlen( $data ) <= self::IV_LEN ) {
            throw new RSD_RB_Crypto_Malformed_Exception( 'AI1WM RB: decryption failed — malformed data.' );
        }

        $iv         = substr( $data, 0, self::IV_LEN );
        $ciphertext = substr( $data, self::IV_LEN );

        $plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::get_key(), OPENSSL_RAW_DATA, $iv );
        if ( false !== $plaintext ) {
            return $plaintext;
        }

        // Fall back to the legacy key formula — see get_legacy_key() for why
        // this exists. Only meaningful when RSD_RB_ENCRYPTION_KEY isn't
        // defined, since that path never included home_url() at all.
        if ( ! defined( 'RSD_RB_ENCRYPTION_KEY' ) || '' === RSD_RB_ENCRYPTION_KEY ) {
            $legacy_plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::get_legacy_key(), OPENSSL_RAW_DATA, $iv );
            if ( false !== $legacy_plaintext ) {
                $used_legacy_key = true;
                return $legacy_plaintext;
            }
        }

        throw new RuntimeException( 'AI1WM RB: decryption failed — bad key or corrupt data.' );
    }

    // -------------------------------------------------------------------------

    private static function get_key(): string {
        if ( defined( 'RSD_RB_ENCRYPTION_KEY' ) && '' !== RSD_RB_ENCRYPTION_KEY ) {
            // Stretch to exactly 32 bytes regardless of what the user supplies.
            return hash( 'sha256', RSD_RB_ENCRYPTION_KEY, true );
        }

        // Derive from WP salts only — NOT home_url() (see get_legacy_key()
        // for why that was removed). Uses wp_salt() rather than referencing
        // the AUTH_KEY/SECURE_AUTH_KEY/LOGGED_IN_KEY/NONCE_KEY constants
        // directly — those are normally defined in wp-config.php, but
        // nothing guarantees it (found via a live site whose wp-config.php
        // was missing AUTH_KEY; an undefined constant is a fatal Error on
        // PHP 8+). wp_salt() returns the exact constant value when one is
        // defined, and a stable, DB-persisted generated fallback otherwise.
        return hash( 'sha256', self::wp_salts(), true );
    }

    /**
     * The key formula used before this fix — additionally hashed in
     * home_url(). Kept ONLY so decrypt() can still read values encrypted
     * under it and self-heal them onto the new, stable formula (see
     * decrypt_stored_secret() in class-settings.php and get_tokens() in
     * class-oauth.php) — never used for new encryption.
     *
     * Removed because home_url() is not actually stable over a site's
     * lifetime (an HTTP→HTTPS migration, a domain change, a www/non-www
     * change) — any such change silently changed the derived key, making
     * every previously-stored OAuth token AND client secret undecryptable
     * from that point on. This is the confirmed root cause of a live-site
     * report: a OneDrive connection "went stale" over time, and reconnecting
     * failed claiming there was no valid secret — decrypt() was failing
     * (home_url() had changed since the secret was first saved), and the
     * settings accessor's old fallback logic wrongly assumed any decrypt
     * failure meant "legacy plaintext," returning the raw ciphertext blob
     * as if it were the real secret. home_url() didn't add meaningful
     * security value here either — it's a public value read straight out of
     * wp_options, not a secret an attacker with DB access wouldn't already
     * have.
     */
    private static function get_legacy_key(): string {
        return hash( 'sha256', self::wp_salts() . home_url(), true );
    }

    private static function wp_salts(): string {
        return wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . wp_salt( 'logged_in' ) . wp_salt( 'nonce' );
    }
}

/**
 * Thrown by RSD_RB_Crypto::decrypt() specifically when the input isn't
 * shaped like this plugin's ciphertext format at all (not valid base64, or
 * too short) — as opposed to a plain RuntimeException, which means the input
 * WAS shaped correctly but no known key could decrypt it. Callers use this
 * distinction to tell "genuinely never-encrypted legacy value" (safe to use
 * as-is) apart from "looks encrypted but the key doesn't match" (NOT safe to
 * use as-is — see class-settings.php's decrypt_stored_secret()).
 */
class RSD_RB_Crypto_Malformed_Exception extends RuntimeException {}
