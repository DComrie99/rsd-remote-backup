<?php
defined( 'ABSPATH' ) || exit;

class RSD_RB_Provider_Google_Drive implements RB_Provider {

    // Verify these endpoints against live Google docs at implementation time.
    const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    const SCOPE       = 'https://www.googleapis.com/auth/drive.file';

    // Resumable upload: chunk size must be a multiple of 256 KiB.
    const CHUNK_SIZE  = 8 * 1024 * 1024; // 8 MiB

    // -------------------------------------------------------------------------
    // RB_Provider — identity

    public function key(): string   { return 'google-drive'; }
    public function label(): string { return 'Google Drive'; }

    // -------------------------------------------------------------------------
    // RB_Provider — OAuth

    public function get_authorize_url( string $state ): string {
        return self::AUTH_URL . '?' . http_build_query( array(
            'client_id'     => RSD_RB_Settings::get_google_client_id(),
            'redirect_uri'  => $this->redirect_uri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ), '', '&' );
    }

    public function exchange_code( string $code ): array {
        $raw = RSD_RB_OAuth::exchange_code_request( self::TOKEN_URL, array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirect_uri(),
            'client_id'     => RSD_RB_Settings::get_google_client_id(),
            'client_secret' => RSD_RB_Settings::get_google_client_secret(),
        ) );

        $tokens = $this->normalise_tokens( $raw );
        RSD_RB_OAuth::save_tokens( $this->key(), $tokens );
        RSD_RB_Logger::info( 'Google Drive: authorization code exchanged successfully.' );

        return $tokens;
    }

    public function refresh_access_token(): string {
        $stored = RSD_RB_OAuth::get_tokens( $this->key() );
        if ( ! $stored || empty( $stored['refresh_token'] ) ) {
            throw new RuntimeException( 'Google Drive: no refresh token stored — re-authorisation required.' );
        }

        try {
            $raw = RSD_RB_OAuth::refresh_request( self::TOKEN_URL, array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $stored['refresh_token'],
                'client_id'     => RSD_RB_Settings::get_google_client_id(),
                'client_secret' => RSD_RB_Settings::get_google_client_secret(),
            ) );
        } catch ( RuntimeException $e ) {
            // invalid_grant = refresh token revoked or consent screen in Testing mode
            if ( false !== strpos( $e->getMessage(), 'invalid_grant' ) ) {
                RSD_RB_OAuth::delete_tokens( $this->key() );
                RSD_RB_Logger::error(
                    'Google Drive: refresh token invalid (invalid_grant). ' .
                    'This often means the OAuth consent screen is in "Testing" mode — publish it to "In production" in Google Cloud Console. ' .
                    'The plugin has been disconnected; please re-authorise.'
                );
            }
            throw $e;
        }

        $tokens = $this->normalise_tokens( $raw );
        // Google may not return a new refresh_token on refresh — keep the old one.
        if ( empty( $tokens['refresh_token'] ) ) {
            $tokens['refresh_token'] = $stored['refresh_token'];
        }

        RSD_RB_OAuth::save_tokens( $this->key(), $tokens );
        RSD_RB_Logger::info( 'Google Drive: access token refreshed.' );

        return $tokens['access_token'];
    }

    public function is_connected(): bool {
        return RSD_RB_OAuth::has_tokens( $this->key() );
    }

    public function disconnect(): void {
        RSD_RB_OAuth::delete_tokens( $this->key() );
        RSD_RB_Logger::info( 'Google Drive: disconnected.' );
    }

    // -------------------------------------------------------------------------
    // RB_Provider — Upload (resumable)

    public function begin_upload( string $filepath, string $remote_name ): array {
        $access_token = $this->get_valid_access_token();
        $folder_id    = $this->ensure_folder( RSD_RB_Settings::get_folder_name() );
        $filesize     = filesize( $filepath );

        $metadata = wp_json_encode( array(
            'name'    => $remote_name,
            'parents' => array( $folder_id ),
        ) );

        $response = wp_remote_request(
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,size',
            array(
                'method'      => 'POST',
                'timeout'     => 30,
                'redirection' => 0, // must not follow Location — we need to capture it as the session URL
                'headers'     => array(
                    'Authorization'           => 'Bearer ' . $access_token,
                    'Content-Type'            => 'application/json; charset=UTF-8',
                    'X-Upload-Content-Type'   => 'application/octet-stream',
                    'X-Upload-Content-Length' => (string) $filesize,
                ),
                'body' => $metadata,
            )
        );

        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( 'Google Drive begin_upload error: ' . $response->get_error_message() );
        }

        $code        = wp_remote_retrieve_response_code( $response );
        $session_url = wp_remote_retrieve_header( $response, 'location' );

        if ( 200 !== $code || empty( $session_url ) ) {
            throw new RuntimeException( 'Google Drive begin_upload: unexpected response (HTTP ' . $code . ').' );
        }

        RSD_RB_Logger::info( 'Google Drive: upload session created for ' . $remote_name . '.' );

        return array(
            'session_url' => $session_url,
            'chunk_size'  => self::CHUNK_SIZE,
        );
    }

    public function upload_chunk( array $session, int $offset, string $bytes ): array {
        $chunk_len = strlen( $bytes );
        $total     = isset( $session['filesize'] ) ? (int) $session['filesize'] : '*';
        $last_byte = $offset + $chunk_len - 1;
        $range     = "bytes {$offset}-{$last_byte}/{$total}";

        $response = wp_remote_request( $session['session_url'], array(
            'method'  => 'PUT',
            'timeout' => 60,
            'headers' => array(
                'Content-Range' => $range,
                'Content-Type'  => 'application/octet-stream',
            ),
            'body' => $bytes,
        ) );

        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( 'Google Drive upload_chunk error: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );

        // 308 = Resume Incomplete — more chunks needed.
        if ( 308 === $code ) {
            $range_header = wp_remote_retrieve_header( $response, 'range' );
            $next_offset  = $range_header ? ( (int) explode( '-', $range_header )[1] + 1 ) : ( $offset + $chunk_len );
            return array(
                'status'      => 'incomplete',
                'next_offset' => $next_offset,
                'remote_id'   => null,
            );
        }

        // 200/201 = complete.
        if ( in_array( $code, array( 200, 201 ), true ) ) {
            $body        = json_decode( wp_remote_retrieve_body( $response ), true );
            $remote_id   = $body['id'] ?? null;
            $remote_size = isset( $body['size'] ) ? (int) $body['size'] : null;
            RSD_RB_Logger::info( 'Google Drive: upload complete, remote id ' . $remote_id . '.' );
            return array(
                'status'      => 'complete',
                'next_offset' => $offset + $chunk_len,
                'remote_id'   => $remote_id,
                'remote_size' => $remote_size,
            );
        }

        throw new RuntimeException( 'Google Drive upload_chunk: unexpected HTTP ' . $code . '.' );
    }

    // -------------------------------------------------------------------------
    // RB_Provider — Housekeeping

    public function ensure_folder( string $name ): string {
        // Cache folder id in a transient to avoid a Drive query on every upload.
        $cache_key = 'rsd_rb_gd_folder_' . md5( $name );
        $cached    = get_transient( $cache_key );
        if ( $cached ) {
            return $cached;
        }

        $access_token = $this->get_valid_access_token();

        // Search for existing folder.
        $query = sprintf(
            "mimeType='application/vnd.google-apps.folder' and name='%s' and trashed=false",
            addslashes( $name )
        );

        $response = wp_remote_get(
            add_query_arg( array( 'q' => $query, 'fields' => 'files(id,name)' ), 'https://www.googleapis.com/drive/v3/files' ),
            array(
                'timeout' => 20,
                'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
            )
        );

        $this->throw_on_wp_error( $response, 'ensure_folder search' );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['files'][0]['id'] ) ) {
            $folder_id = $body['files'][0]['id'];
            set_transient( $cache_key, $folder_id, HOUR_IN_SECONDS );
            return $folder_id;
        }

        // Create folder.
        $create_response = wp_remote_post( 'https://www.googleapis.com/drive/v3/files', array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'name'     => $name,
                'mimeType' => 'application/vnd.google-apps.folder',
            ) ),
        ) );

        $this->throw_on_wp_error( $create_response, 'ensure_folder create' );
        $created   = json_decode( wp_remote_retrieve_body( $create_response ), true );
        $folder_id = $created['id'] ?? '';

        if ( empty( $folder_id ) ) {
            throw new RuntimeException( 'Google Drive: failed to create folder "' . esc_html( $name ) . '".' );
        }

        set_transient( $cache_key, $folder_id, HOUR_IN_SECONDS );
        RSD_RB_Logger::info( 'Google Drive: created folder "' . $name . '".' );

        return $folder_id;
    }

    public function list_backups(): array {
        $access_token = $this->get_valid_access_token();
        $folder_id    = $this->ensure_folder( RSD_RB_Settings::get_folder_name() );

        $query  = "'{$folder_id}' in parents and trashed=false";
        $files  = array();
        $params = array(
            'q'        => $query,
            'fields'   => 'nextPageToken,files(id,name,size,createdTime)',
            'orderBy'  => 'createdTime',
            'pageSize' => 1000,
        );

        do {
            $response = wp_remote_get(
                add_query_arg( $params, 'https://www.googleapis.com/drive/v3/files' ),
                array(
                    'timeout' => 20,
                    'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
                )
            );

            $this->throw_on_wp_error( $response, 'list_backups' );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            foreach ( $body['files'] ?? array() as $file ) {
                $files[] = array(
                    'id'         => $file['id'],
                    'name'       => $file['name'],
                    'size'       => (int) ( $file['size'] ?? 0 ),
                    'created_at' => strtotime( $file['createdTime'] ),
                );
            }

            $params['pageToken'] = $body['nextPageToken'] ?? '';
        } while ( ! empty( $params['pageToken'] ) );

        return $files;
    }

    public function delete_remote( string $remote_id ): bool {
        $access_token = $this->get_valid_access_token();

        $response = wp_remote_request(
            'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $remote_id ),
            array(
                'method'  => 'DELETE',
                'timeout' => 20,
                'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
            )
        );

        if ( is_wp_error( $response ) ) {
            RSD_RB_Logger::error( 'Google Drive delete_remote error: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 204 === $code ) {
            RSD_RB_Logger::info( 'Google Drive: deleted remote file ' . $remote_id . '.' );
            return true;
        }

        RSD_RB_Logger::error( 'Google Drive delete_remote: unexpected HTTP ' . $code . '.' );
        return false;
    }

    // -------------------------------------------------------------------------
    // RB_Provider — Download (ranged)

    /**
     * Drive's alt=media normally serves 206 Partial Content directly from
     * www.googleapis.com with no redirect. We still handle a redirect defensively
     * (redirection => 0, manual follow) without forwarding Authorization to
     * whatever host the Location header points at — same precaution OneDrive's
     * implementation needs for real, since Graph does redirect to a separate
     * pre-authed blob host.
     */
    public function download_chunk( string $remote_id, int $offset, int $length ): array {
        $access_token = $this->get_valid_access_token();
        $last_byte    = $offset + $length - 1;

        $response = wp_remote_get(
            'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $remote_id ) . '?alt=media',
            array(
                'timeout'     => 60,
                'redirection' => 0,
                'headers'     => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Range'         => "bytes={$offset}-{$last_byte}",
                ),
            )
        );

        $this->throw_on_wp_error( $response, 'download_chunk' );
        $code = wp_remote_retrieve_response_code( $response );

        if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
            $location = wp_remote_retrieve_header( $response, 'location' );
            if ( empty( $location ) ) {
                throw new RuntimeException( 'Google Drive download_chunk: redirect with no Location header (HTTP ' . $code . ').' );
            }
            // Second hop deliberately omits Authorization — the redirect target is
            // typically a separate, pre-authed host that must not see our bearer token.
            $response = wp_remote_get( $location, array(
                'timeout' => 60,
                'headers' => array( 'Range' => "bytes={$offset}-{$last_byte}" ),
            ) );
            $this->throw_on_wp_error( $response, 'download_chunk (redirect)' );
            $code = wp_remote_retrieve_response_code( $response );
        }

        if ( ! in_array( $code, array( 200, 206 ), true ) ) {
            throw new RuntimeException( 'Google Drive download_chunk: unexpected HTTP ' . $code . '.' );
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
            // No Content-Range — infer EOF from a short read (server returned less than asked for).
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

    private function redirect_uri(): string {
        return admin_url( 'admin.php?page=rsd-remote-backup&rb_oauth=callback&provider=google-drive' );
    }

    /**
     * Return a valid access token, refreshing automatically if near expiry.
     */
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
            throw new RuntimeException( 'Google Drive ' . $context . ': ' . $response->get_error_message() );
        }
    }
}
