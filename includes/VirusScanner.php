<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * Hard project rule: ALL file uploads must pass through this scanner before
 * being moved to permanent storage. ClamAV is required; if clamdscan is not
 * available or returns a transport error, uploads fail closed.
 *
 * Usage:
 *   $r = VirusScanner::scanFile( $tmpPath );
 *   if ( !$r['ok'] ) {
 *       // reject upload with $r['reason']
 *   }
 *
 * Returns: [ 'ok' => bool, 'reason' => string, 'engine' => 'clamdscan'|'none' ]
 */
class VirusScanner {

    public const ENGINE = '/usr/bin/clamdscan';

    public static function scanFile( string $path ): array {
        if ( !is_file( $path ) ) {
            return [ 'ok' => false, 'reason' => 'file not found', 'engine' => 'none' ];
        }
        if ( !is_executable( self::ENGINE ) ) {
            // Fail closed: if antivirus isn't available, we don't accept uploads.
            wfDebugLog( 'pharmacopedia', 'VirusScanner: clamdscan not executable at ' . self::ENGINE );
            return [ 'ok' => false, 'reason' => 'antivirus scanner not configured on server', 'engine' => 'none' ];
        }
        // --fdpass: hand the file descriptor to clamd (avoids the daemon needing
        //           read permission on /tmp).
        // --no-summary: machine-friendly single-line output.
        $cmd = escapeshellcmd( self::ENGINE )
             . ' --fdpass --no-summary '
             . escapeshellarg( $path );
        $out = [];
        $rc  = 0;
        exec( $cmd . ' 2>&1', $out, $rc );
        $output = implode( "\n", $out );
        // clamdscan exit codes:
        //   0 = clean
        //   1 = virus found
        //   2 = error
        if ( $rc === 0 ) {
            return [ 'ok' => true, 'reason' => 'clean', 'engine' => 'clamdscan' ];
        }
        if ( $rc === 1 ) {
            wfDebugLog( 'pharmacopedia', 'VirusScanner: infected file rejected: ' . $output );
            return [ 'ok' => false, 'reason' => 'virus detected — upload rejected', 'engine' => 'clamdscan' ];
        }
        wfDebugLog( 'pharmacopedia', 'VirusScanner: scan error (rc=' . $rc . '): ' . $output );
        return [ 'ok' => false, 'reason' => 'antivirus scan failed; upload rejected', 'engine' => 'clamdscan' ];
    }
}
