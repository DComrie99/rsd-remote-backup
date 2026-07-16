<?php
defined( 'ABSPATH' ) || exit;

/**
 * Contract every cloud-storage provider adapter must implement.
 */
interface RB_Provider {

    /** Unique machine key, e.g. 'google-drive' or 'onedrive'. */
    public function key(): string;

    /** Human-readable label. */
    public function label(): string;

    // -------------------------------------------------------------------------
    // OAuth

    /**
     * Build the authorization URL to redirect the user to.
     *
     * @param string $state Signed nonce to prevent CSRF.
     */
    public function get_authorize_url( string $state ): string;

    /**
     * Exchange an authorization code for tokens.
     * Persists tokens via class-crypto; returns the raw token array.
     *
     * @param string $code The code from the OAuth callback.
     * @return array{access_token:string, refresh_token:string, expires_at:int}
     */
    public function exchange_code( string $code ): array;

    /**
     * Refresh the access token using the stored refresh token.
     * Persists the updated token set and returns the fresh access token.
     *
     * @throws RuntimeException If refresh fails (e.g. invalid_grant).
     */
    public function refresh_access_token(): string;

    /** Whether a valid (potentially refreshable) token is stored. */
    public function is_connected(): bool;

    /**
     * Make one real, uncached API call to confirm the stored credentials
     * actually work right now — not just "a token is stored" (is_connected())
     * or "our local expiry clock says it should still be valid"
     * (get_valid_access_token()), which can both be true while the secret
     * has actually gone bad (e.g. revoked, rotated, or the app registration's
     * client secret expired). Must not use ensure_folder()'s cache — that
     * would let a connection that broke mid-cache-window still report healthy.
     *
     * @throws RuntimeException If the connection is not currently working.
     */
    public function verify_connection(): void;

    /** Remove stored tokens (disconnect). */
    public function disconnect(): void;

    // -------------------------------------------------------------------------
    // Upload (resumable)

    /**
     * Start a new resumable upload session for the given local file.
     *
     * @param string $filepath    Absolute path to the .wpress file.
     * @param string $remote_name Filename to use in the cloud.
     * @return array{session_url:string, chunk_size:int}
     */
    public function begin_upload( string $filepath, string $remote_name ): array;

    /**
     * Upload one chunk of bytes.
     *
     * @param array  $session    Descriptor returned by begin_upload (or persisted state).
     * @param int    $offset     Byte offset this chunk starts at.
     * @param string $bytes      Raw bytes to send.
     * @return array{status:'incomplete'|'complete', next_offset:int, remote_id:string|null}
     */
    public function upload_chunk( array $session, int $offset, string $bytes ): array;

    // -------------------------------------------------------------------------
    // Housekeeping

    /**
     * Ensure the destination folder exists; return its remote id/path.
     *
     * @param string $name Folder name.
     */
    public function ensure_folder( string $name ): string;

    /**
     * List all backup files in the plugin's remote folder.
     *
     * @return array<array{id:string, name:string, created_at:int}>
     */
    public function list_backups(): array;

    /**
     * Delete a remote file by its provider id.
     *
     * @param string $remote_id Provider-specific file identifier.
     */
    public function delete_remote( string $remote_id ): bool;

    // -------------------------------------------------------------------------
    // Download (ranged)

    /**
     * Fetch one ranged chunk of a remote file's bytes.
     *
     * @param string $remote_id Provider-specific file identifier.
     * @param int    $offset    Byte offset to start reading from.
     * @param int    $length    Number of bytes requested (implementations should
     *                          keep this in the same order of magnitude as their
     *                          upload CHUNK_SIZE — each chunk is buffered fully in
     *                          memory by wp_remote_get()).
     * @return array{bytes:string, total_size:?int, eof:bool}
     */
    public function download_chunk( string $remote_id, int $offset, int $length ): array;
}
