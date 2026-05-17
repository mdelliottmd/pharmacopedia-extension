<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * ClamAV wrapper. Prefers clamdscan (uses the persistent daemon, fast);
 * falls back to clamscan (slow, loads signatures per invocation).
 *
 * Returns one of:
 *   [ 'status' => 1, 'message' => 'OK' ]                 // clean
 *   [ 'status' => 2, 'message' => 'Eicar-Test-Signature' ] // infected
 *   [ 'status' => 3, 'message' => 'scanner error: ...' ]   // error
 *
 * Status codes map to fra_scan_status in pcp_feature_request_attachment.
 */
class AttachmentScanner {

    public const STATUS_PENDING  = 0;
    public const STATUS_CLEAN    = 1;
    public const STATUS_INFECTED = 2;
    public const STATUS_ERROR    = 3;

    public static function scanFile( string $path ): array {
        if ( !is_file( $path ) || !is_readable( $path ) ) {
            return [ 'status' => self::STATUS_ERROR, 'message' => 'file unreadable' ];
        }
        // Prefer clamdscan if available
        $clamdscan = self::which( 'clamdscan' );
        $clamscan  = self::which( 'clamscan' );
        $bin = $clamdscan ?: $clamscan;
        if ( !$bin ) {
            return [ 'status' => self::STATUS_ERROR, 'message' => 'clamav not installed' ];
        }
        // --fdpass on clamdscan: pass file descriptor (avoids needing the
        // daemon to read the file directly when it runs as a different user).
        $cmd = escapeshellcmd( $bin );
        if ( basename( $bin ) === 'clamdscan' ) {
            $cmd .= ' --no-summary --stream';
        } else {
            $cmd .= ' --no-summary --infected --stdout';
        }
        $cmd .= ' ' . escapeshellarg( $path ) . ' 2>&1';
        $output = [];
        $exit = 0;
        exec( $cmd, $output, $exit );
        $msg = trim( implode( "\n", $output ) );
        if ( $exit === 0 ) {
            return [ 'status' => self::STATUS_CLEAN, 'message' => 'OK' ];
        }
        if ( $exit === 1 ) {
            // ClamAV exit 1 = infected. Try to extract the signature name.
            $sig = 'infected';
            if ( preg_match( '/:\s*(\S+)\s+FOUND/i', $msg, $m ) ) $sig = $m[1];
            return [ 'status' => self::STATUS_INFECTED, 'message' => mb_substr( $sig, 0, 255 ) ];
        }
        return [ 'status' => self::STATUS_ERROR, 'message' => mb_substr( 'exit=' . $exit . ' ' . $msg, 0, 255 ) ];
    }

    private static function which( string $bin ): ?string {
        $paths = [ '/usr/bin/' . $bin, '/usr/local/bin/' . $bin ];
        foreach ( $paths as $p ) {
            if ( is_executable( $p ) ) return $p;
        }
        $out = @shell_exec( 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null' );
        $out = trim( (string)$out );
        return ( $out !== '' && is_executable( $out ) ) ? $out : null;
    }
}
