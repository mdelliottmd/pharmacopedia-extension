<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * Shared antivirus-scan helper. Prefers the clamav daemon (clamdscan, fast)
 * and falls back to the standalone clamscan binary if the daemon is absent.
 *
 * Throws RuntimeException if ClamAV reports a hit. Silently no-ops if no
 * scanner is installed (logged via wfDebugLog so the absence is auditable).
 *
 * Used by:
 *   - LiteratureStore::storeUploadedPdf()
 *   - SpecialMyLifeStory::maybeAcceptImageUpload()
 */
class AntivirusHelper {

    /**
     * Scan a local file. Returns void on clean / unavailable; throws on detection.
     */
    public static function scan( string $path ): void {
        $scanner = null;
        foreach ( [ '/usr/bin/clamdscan', '/usr/bin/clamscan' ] as $bin ) {
            if ( is_executable( $bin ) ) { $scanner = $bin; break; }
        }
        if ( $scanner === null ) {
            wfDebugLog( 'pharmacopedia', 'No clam scanner found; skipping AV scan for ' . $path );
            return;
        }
        $cmd = escapeshellcmd( $scanner ) . ' --no-summary --stdout ' . escapeshellarg( $path ) . ' 2>&1';
        $out = [];
        $rc = 0;
        exec( $cmd, $out, $rc );
        // clamscan/clamdscan exit codes: 0=clean, 1=virus found, 2=error
        if ( $rc === 1 ) {
            $detail = implode( ' / ', array_slice( $out, 0, 3 ) );
            throw new \RuntimeException( 'File rejected by antivirus: ' . $detail );
        }
        if ( $rc !== 0 ) {
            wfDebugLog( 'pharmacopedia', "AV scan returned rc=$rc on $path: " . implode( ' / ', $out ) );
        }
    }
}
