<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

/**
 * Filesystem operations for feature-request attachments.
 *
 * Storage layout:
 *   $wgPharmacopediaFeatureRequestDir / <request_id> / <storage_name>
 *
 * storage_name is the SHA-256 of (random 32 bytes + uploaded_at + filename),
 * preserving the original extension. Display name is preserved in DB only.
 */
class AttachmentStorage {

    /**
     * Broad whitelist as agreed with the user. Lower-cased extensions.
     * ClamAV scans every upload regardless.
     */
    public const ALLOWED_EXTENSIONS = [
        // Documents
        'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'md',
        // Spreadsheets
        'xls', 'xlsx', 'csv', 'ods',
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
        // Audio/Video
        'mp4', 'mov', 'webm', 'mp3', 'm4a', 'wav',
        // Archives (scanned by ClamAV recursively)
        'zip',
    ];

    public const ALLOWED_MIMES = [
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.oasis.opendocument.text', 'application/rtf', 'text/plain', 'text/markdown',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv', 'application/vnd.oasis.opendocument.spreadsheet',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        'video/mp4', 'video/quicktime', 'video/webm',
        'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav',
        'application/zip',
    ];

    public static function baseDir(): string {
        $cfg = MediaWikiServices::getInstance()->getMainConfig();
        return rtrim( (string)$cfg->get( 'PharmacopediaFeatureRequestDir' ), '/' );
    }

    /** Directory for a given request_id. Creates if missing. */
    public static function dirFor( int $requestId ): string {
        $dir = self::baseDir() . '/' . $requestId;
        if ( !is_dir( $dir ) ) {
            @mkdir( $dir, 0700, true );
        }
        return $dir;
    }

    /** Full path for a stored attachment. */
    public static function pathFor( int $requestId, string $storageName ): ?string {
        // Defensive: storage name should never contain a slash or '..'.
        if ( $storageName === '' || strpbrk( $storageName, "/\\" ) !== false || strpos( $storageName, '..' ) !== false ) {
            return null;
        }
        return self::baseDir() . '/' . $requestId . '/' . $storageName;
    }

    public static function generateStorageName( string $displayName ): string {
        $ext = strtolower( pathinfo( $displayName, PATHINFO_EXTENSION ) );
        $ext = preg_replace( '/[^a-z0-9]/', '', $ext );
        if ( strlen( $ext ) > 8 ) $ext = substr( $ext, 0, 8 );
        $hash = hash( 'sha256', random_bytes( 32 ) . microtime( true ) . $displayName );
        return substr( $hash, 0, 48 ) . ( $ext ? ( '.' . $ext ) : '' );
    }

    public static function isAllowedExtension( string $displayName ): bool {
        $ext = strtolower( pathinfo( $displayName, PATHINFO_EXTENSION ) );
        return in_array( $ext, self::ALLOWED_EXTENSIONS, true );
    }

    public static function isAllowedMime( string $mime ): bool {
        return in_array( strtolower( $mime ), self::ALLOWED_MIMES, true );
    }

    /**
     * Move an uploaded tempfile into final storage. Returns array with
     * 'ok', 'storage_name', 'mime', 'size'  (or 'error' on failure).
     */
    public static function moveUploaded( string $tmpPath, int $requestId, string $displayName ): array {
        if ( !is_file( $tmpPath ) || !is_readable( $tmpPath ) ) {
            return [ 'ok' => false, 'error' => 'Temp file unreadable.' ];
        }
        $size = (int)filesize( $tmpPath );
        if ( $size <= 0 || $size > FeatureRequestStore::ATTACHMENT_MAX_BYTES ) {
            return [ 'ok' => false, 'error' => 'File size out of range.' ];
        }
        if ( !self::isAllowedExtension( $displayName ) ) {
            return [ 'ok' => false, 'error' => 'File extension not allowed.' ];
        }
        $mime = self::detectMime( $tmpPath );
        if ( !self::isAllowedMime( $mime ) ) {
            return [ 'ok' => false, 'error' => 'MIME type not allowed: ' . $mime ];
        }
        $dir = self::dirFor( $requestId );
        if ( !is_dir( $dir ) || !is_writable( $dir ) ) {
            return [ 'ok' => false, 'error' => 'Attachment directory not writable.' ];
        }
        $storageName = self::generateStorageName( $displayName );
        $finalPath = $dir . '/' . $storageName;
        if ( !rename( $tmpPath, $finalPath ) ) {
            // Fallback: copy + unlink (e.g. cross-device)
            if ( !copy( $tmpPath, $finalPath ) ) {
                return [ 'ok' => false, 'error' => 'Could not move file into final location.' ];
            }
            @unlink( $tmpPath );
        }
        @chmod( $finalPath, 0600 );
        return [
            'ok'           => true,
            'storage_name' => $storageName,
            'mime'         => $mime,
            'size'         => $size,
        ];
    }

    private static function detectMime( string $path ): string {
        if ( function_exists( 'finfo_open' ) ) {
            $f = finfo_open( FILEINFO_MIME_TYPE );
            if ( $f ) {
                $m = (string)finfo_file( $f, $path );
                finfo_close( $f );
                if ( $m !== '' ) return $m;
            }
        }
        return 'application/octet-stream';
    }
}
