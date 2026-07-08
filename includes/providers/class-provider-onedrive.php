<?php
defined( 'ABSPATH' ) || exit;

/**
 * Microsoft OneDrive provider adapter (Microsoft Graph API).
 *
 * Uses the App Folder (Files.ReadWrite.AppFolder scope) — cleanest isolation.
 * Supports personal accounts by default; set RSD_RB_od_account_type option
 * to 'organizations' for work/school, or 'common' for both.
 *
 * Upload session: createUploadSession → PUT byte ranges with Content-Range.
 * Fragment size must be a multiple of 320 KiB.
 * Verify endpoint URLs against live Microsoft Graph docs at deploy time.
 */
class RSD_RB_Provider_OneDrive implements RB_Provider {

    // Authority — 'consumers' for personal, 'organizations' for work/school, 'common' for both.
    const AUTHORITY_BASE = 'https://login.microsoftonline.com/';
    const AUTH_PATH      = '/oauth2/v2.0/authorize';
    const TOKEN_PATH     = '/oauth2/v2.0/token';

    const SCOPES         = 'Files.ReadWrite.AppFolder offline_access User.Read';

    // Graph API base
    const GRAPH_BASE     = 'https://graph.microsoft.com/v1.0';

    // Chunk size: multiple of 320 KiB. ~10 MiB is well within Graph's guidance.
    const CHUNK_SIZE     = 10 * 320 * 1024; // ~3.2 MiB — exactly 10 × 320 KiB

    // -------------------------------------------------------------------------
    // RB_Provider — identity

    public function key(): string   { return 'onedrive'; }
    public function label(): string { return 'Microsoft OneDrive'; }

    // -------------------------------------------------------------------------
    // RB_Provider — OAuth

    public function get_authorize_url( string $state ): string {
        return $this->authority() . self::AUTH_PATH . '?' . http_build_query( array(
            'client_id'     => RSD_RB_Settings::get_od_client_id(),
            'response_type' => 'code',
            'redirect_uri'  => $this->redirect_uri(),
            'scope'         => self::SCOPES,
            'state'         => $state,
            'response_mode' => 'query',
        ), '', '&' );
    }

    public function exchange_code( string $code ): array {
        $raw = RSD_RB_OAuth::exchange_code_request( $this->token_url(), array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirect_uri(),
            'client_id'     => RSD_RB_Settings::get_od_client_id(),
            'client_secret' => RSD_RB_Settings::get_od_client_secret(),
            'scope'         => self::SCOPES,
        ) );

        $tokens = $this->normalise_tokens( $raw );
        RSD_RB_OAuth::save_tokens( $this->key(), $tokens );
        RSD_RB_Logger::info( 'OneDrive: authorization code exchanged successfully.' );

        return $tokens;
    }

    public function refresh_access_token(): string {
        $stored = RSD_RB_OAuth::get_tokens( $this->key() );
        if ( ! $stored || empty( $stored['refresh_token'] ) ) {
            throw new RuntimeException( 'OneDrive: no refresh token stored — re-authorisation required.' );
        }

        $raw = RSD_RB_OAuth::refresh_request( $this->token_url(), array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $stored['refresh_token'],
            'client_id'     => RSD_RB_Settings::get_od_client_id(),
            'client_secret' => RSD_RB_Settings::get_od_client_secret(),
            'scope'         => self::SCOPES,
        ) );

        $tokens = $this->normalise_tokens( $raw );
        // Microsoft may rotate the refresh token — always persist the latest one.
        if ( empty( $tokens['refresh_token'] ) ) {
            $tokens['refresh_token'] = $stored['refresh_token'];
        }

        RSD_RB_OAuth::save_tokens( $this->key(), $tokens );
        RSD_RB_Logger::info( 'OneDrive: access token refreshed.' );

        return $tokens['access_token'];
    }

    public function is_connected(): bool {
        return RSD_RB_OAuth::has_tokens( $this->key() );
    }

    public function disconnect(): void {
        RSD_RB_OAuth::delete_tokens( $this->key() );
        RSD_RB_Logger::info( 'OneDrive: disconnected.' );
    }

    // -------------------------------------------------------------------------
    // RB_Provider — Upload (resumable)

    public function begin_upload( string $filepath, string $remote_name ): array {
        $access_token = $this->get_valid_access_token();
        $folder_path  = $this->ensure_folder( RSD_RB_Settings::get_folder_name() );

        // createUploadSession endpoint for App Folder.
        // Encode folder and filename separately so the '/' path separator is preserved.
        // rawurlencode() on the whole concatenated string would encode '/' as '%2F',
        // which Graph's colon-path syntax does not recognise as a directory separator.
        $url = self::GRAPH_BASE . '/me/drive/special/approot:/'
             . rawurlencode( $folder_path ) . '/' . rawurlencode( $remote_name )
             . ':/createUploadSession';

        $response = wp_remote_post( $url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'item' => array(
                    '@microsoft.graph.conflictBehavior' => 'replace',
                    'name' => $remote_name,
                ),
            ) ),
        ) );

        $this->throw_on_wp_error( $response, 'begin_upload' );
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code || empty( $body['uploadUrl'] ) ) {
            throw new RuntimeException( 'OneDrive begin_upload: unexpected response (HTTP ' . $code . ').' );
        }

        RSD_RB_Logger::info( 'OneDrive: upload session created for ' . $remote_name . '.' );

        return array(
            'session_url' => $body['uploadUrl'],
            'chunk_size'  => self::CHUNK_SIZE,
        );
    }

    public function upload_chunk( array $session, int $offset, string $bytes ): array {
        $chunk_len = strlen( $bytes );
        $filesize  = isset( $session['filesize'] ) ? (int) $session['filesize'] : '*';
        $last_byte = $offset + $chunk_len - 1;
        $range     = "bytes {$offset}-{$last_byte}/{$filesize}";

        $response = wp_remote_request( $session['session_url'], array(
            'method'  => 'PUT',
            'timeout' => 60,
            'headers' => array(
                'Content-Range'  => $range,
                'Content-Length' => (string) $chunk_len,
                'Content-Type'   => 'application/octet-stream',
            ),
            'body' => $bytes,
        ) );

        $this->throw_on_wp_error( $response, 'upload_chunk' );
        $code = wp_remote_retrieve_response_code( $response );

        // 429 / 503 — throttled; throw immediately so the worker's backoff/reschedule logic handles the delay.
        if ( in_array( $code, array( 429, 503 ), true ) ) {
            $retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
            $wait        = max( $retry_after, 5 );
            RSD_RB_Logger::warning( 'OneDrive upload_chunk: throttled (HTTP ' . $code . '), Retry-After ' . $wait . 's.' );
            throw new RuntimeException( 'OneDrive throttled (HTTP ' . $code . ').' );
        }

        // 202 = accepted, more chunks needed.
        if ( 202 === $code ) {
            $body        = json_decode( wp_remote_retrieve_body( $response ), true );
            $next_ranges = $body['nextExpectedRanges'] ?? array();
            $next_offset = $offset + $chunk_len; // default
            if ( ! empty( $next_ranges[0] ) ) {
                $next_offset = (int) explode( '-', $next_ranges[0] )[0];
            }
            return array(
                'status'      => 'incomplete',
                'next_offset' => $next_offset,
                'remote_id'   => null,
            );
        }

        // 200 / 201 = complete.
        if ( in_array( $code, array( 200, 201 ), true ) ) {
            $body        = json_decode( wp_remote_retrieve_body( $response ), true );
            $remote_id   = $body['id'] ?? null;
            $remote_size = isset( $body['size'] ) ? (int) $body['size'] : null;
            RSD_RB_Logger::info( 'OneDrive: upload complete, item id ' . $remote_id . '.' );
            return array(
                'status'      => 'complete',
                'next_offset' => $offset + $chunk_len,
                'remote_id'   => $remote_id,
                'remote_size' => $remote_size,
            );
        }

        throw new RuntimeException( 'OneDrive upload_chunk: unexpected HTTP ' . $code . '.' );
    }

    // -------------------------------------------------------------------------
    // RB_Provider — Housekeeping

    public function ensure_folder( string $name ): string {
        // Graph API does not allow '/' in a folder name — it is treated as a path
        // separator in the colon-path syntax. Replace any slashes so the folder is
        // always a single, flat entry directly under the app root.
        $safe_name = str_replace( '/', '-', trim( $name ) );

        $cache_key = 'rsd_rb_od_folder_' . md5( $safe_name );
        $cached    = get_transient( $cache_key );
        if ( $cached ) {
            return (string) $cached;
        }

        $access_token = $this->get_valid_access_token();

        // Check if the folder already exists under approot.
        $url = self::GRAPH_BASE . '/me/drive/special/approot:/' . rawurlencode( $safe_name );

        $response = wp_remote_get( $url, array(
            'timeout' => 20,
            'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
        ) );

        $code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

        if ( 200 === $code ) {
            set_transient( $cache_key, $safe_name, HOUR_IN_SECONDS );
            return $safe_name;
        }

        // Create the folder under approot.
        $create_url = self::GRAPH_BASE . '/me/drive/special/approot/children';
        $create     = wp_remote_post( $create_url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'name'                              => $safe_name,
                'folder'                            => new stdClass(),
                '@microsoft.graph.conflictBehavior' => 'rename',
            ) ),
        ) );

        $this->throw_on_wp_error( $create, 'ensure_folder create' );
        $create_code = wp_remote_retrieve_response_code( $create );

        if ( ! in_array( $create_code, array( 200, 201 ), true ) ) {
            throw new RuntimeException( 'OneDrive: failed to create folder (HTTP ' . $create_code . ').' );
        }

        RSD_RB_Logger::info( 'OneDrive: created folder "' . $safe_name . '".' );
        set_transient( $cache_key, $safe_name, HOUR_IN_SECONDS );

        return $safe_name;
    }

    public function list_backups(): array {
        $access_token = $this->get_valid_access_token();
        $folder_name  = str_replace( '/', '-', trim( RSD_RB_Settings::get_folder_name() ) );

        // $orderby is not supported on personal OneDrive children endpoints — omit it.
        $url   = self::GRAPH_BASE . '/me/drive/special/approot:/' . rawurlencode( $folder_name ) . ':/children'
               . '?$select=id,name,size,createdDateTime&$top=1000';
        $files = array();

        do {
            $response = wp_remote_get( $url, array(
                'timeout' => 20,
                'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
            ) );

            $this->throw_on_wp_error( $response, 'list_backups' );
            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 200 !== $code ) {
                $msg = $body['error']['message'] ?? 'HTTP ' . $code;
                throw new RuntimeException( 'OneDrive list_backups: ' . $msg . ' (HTTP ' . $code . ').' );
            }

            foreach ( $body['value'] ?? array() as $item ) {
                $files[] = array(
                    'id'         => $item['id'],
                    'name'       => $item['name'],
                    'size'       => (int) ( $item['size'] ?? 0 ),
                    'created_at' => strtotime( $item['createdDateTime'] ),
                );
            }

            $url = $body['@odata.nextLink'] ?? '';
        } while ( ! empty( $url ) );

        return $files;
    }

    public function delete_remote( string $remote_id ): bool {
        $access_token = $this->get_valid_access_token();
        $url          = self::GRAPH_BASE . '/me/drive/items/' . rawurlencode( $remote_id );

        $response = wp_remote_request( $url, array(
            'method'  => 'DELETE',
            'timeout' => 20,
            'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
        ) );

        if ( is_wp_error( $response ) ) {
            RSD_RB_Logger::error( 'OneDrive delete_remote error: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 204 === $code ) {
            RSD_RB_Logger::info( 'OneDrive: deleted remote item ' . $remote_id . '.' );
            return true;
        }

        RSD_RB_Logger::error( 'OneDrive delete_remote: unexpected HTTP ' . $code . '.' );
        return false;
    }

    // -------------------------------------------------------------------------
    // RB_Provider — Download (ranged)

    /**
     * Graph's /content endpoint 302s to a separate pre-authed blob-storage URL.
     * WordPress's HTTP API forwards the same request headers (including
     * Authorization) on redirects by default — sending our bearer token to that
     * host would be an exposure risk and some blob endpoints reject an unexpected
     * Authorization header outright. redirection => 0 + a manual second GET
     * without Authorization avoids both problems.
     */
    public function download_chunk( string $remote_id, int $offset, int $length ): array {
        $access_token = $this->get_valid_access_token();
        $last_byte    = $offset + $length - 1;
        $url          = self::GRAPH_BASE . '/me/drive/items/' . rawurlencode( $remote_id ) . '/content';

        $response = wp_remote_get( $url, array(
            'timeout'     => 60,
            'redirection' => 0,
            'headers'     => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Range'         => "bytes={$offset}-{$last_byte}",
            ),
        ) );

        $this->throw_on_wp_error( $response, 'download_chunk' );
        $code = wp_remote_retrieve_response_code( $response );

        if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
            $location = wp_remote_retrieve_header( $response, 'location' );
            if ( empty( $location ) ) {
                throw new RuntimeException( 'OneDrive download_chunk: redirect with no Location header (HTTP ' . $code . ').' );
            }
            $response = wp_remote_get( $location, array(
                'timeout' => 60,
                'headers' => array( 'Range' => "bytes={$offset}-{$last_byte}" ),
            ) );
            $this->throw_on_wp_error( $response, 'download_chunk (redirect)' );
            $code = wp_remote_retrieve_response_code( $response );
        }

        if ( ! in_array( $code, array( 200, 206 ), true ) ) {
            throw new RuntimeException( 'OneDrive download_chunk: unexpected HTTP ' . $code . '.' );
        }

        return $this->parse_ranged_response( $response, $length );
    }

    /** @return array{bytes:string, total_size:?int, eof:bool} */
    private function parse_ranged_response( $response, int $requested_length ): array {
        $bytes        = wp_remote_retrieve_body( $response );
        $range_header = wp_remote_retrieve_header( $response, 'content-range' ); // "bytes start-end/total"
        $total_size   = null;
        $eof          = false;

        if ( $range_header && preg_match( '#bytes\s+(\d+)-(\d+)/(\d+)#', $range_header, $m ) ) {
            $end        = (int) $m[2];
            $total_size = (int) $m[3];
            $eof        = ( $end + 1 ) >= $total_size;
        } else {
            $eof = strlen( $bytes ) < $requested_length;
        }

        return array(
            'bytes'      => $bytes,
            'total_size' => $total_size,
            'eof'        => $eof,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers

    private function authority(): string {
        $type = get_option( 'rsd_rb_od_account_type', 'consumers' );
        return self::AUTHORITY_BASE . $type;
    }

    private function token_url(): string {
        return $this->authority() . self::TOKEN_PATH;
    }

    private function redirect_uri(): string {
        // Must be a path-based URL with no query string — Microsoft Entra rejects
        // query-string redirect URIs for apps that sign in personal accounts.
        // The rewrite rule ^rsd-rb-onedrive/?$ maps this to the plugin's callback handler.
        return home_url( '/rsd-rb-onedrive/' );
    }

    public function get_valid_access_token(): string {
        if ( RSD_RB_OAuth::access_token_is_valid( $this->key() ) ) {
            $tokens = RSD_RB_OAuth::get_tokens( $this->key() );
            return $tokens['access_token'];
        }
        return $this->refresh_access_token();
    }

    private function normalise_tokens( array $raw ): array {
        return array(
            'access_token'  => $raw['access_token'],
            'refresh_token' => $raw['refresh_token'] ?? '',
            'expires_at'    => time() + (int) ( $raw['expires_in'] ?? 3600 ),
        );
    }

    private function throw_on_wp_error( $response, string $context ): void {
        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( 'OneDrive ' . $context . ': ' . $response->get_error_message() );
        }
    }
}
