<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

class LiteratureStore {
    const STATUS_PENDING  = 0;
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;

    const MAX_BYTES = 23289856; // 22.22 MB
    const ALLOWED_EXT = 'pdf';
    const ALLOWED_MIME = 'application/pdf';

    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }
    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    public function filesDir(): string {
        global $wgPharmacopediaLiteratureDir;
        return rtrim( $wgPharmacopediaLiteratureDir, '/' );
    }

    public function getById( int $id ) {
        return $this->dbr()->selectRow( 'pcp_literature', '*', [ 'l_id' => $id ], __METHOD__ );
    }

    /** Approved entries for a medicine page, newest first. */
    public function listApproved( int $pageId, int $limit = 200 ): array {
        $res = $this->dbr()->select(
            [ 'l' => 'pcp_literature', 'r' => 'user' ],
            [ 'l.*', 'l.l_display_name AS submitter_name', 'r.user_name AS reviewer_name' ],
            [ 'l.l_page_id' => $pageId, 'l.l_status' => self::STATUS_APPROVED ],
            __METHOD__,
            [ 'ORDER BY' => 'l.l_year DESC, l.l_id DESC', 'LIMIT' => $limit ],
            [
                'r' => [ 'LEFT JOIN', 'l.l_reviewed_by = r.user_id' ],
            ]
        );
        $rows = [];
        foreach ( $res as $r ) { $rows[] = $r; }
        return $rows;
    }

    /** Pending entries this user submitted on this page (for their own "Pending" view). */
    public function listPendingForUser( int $pageId, int $userId ): array {
        $res = $this->dbr()->select(
            'pcp_literature', '*',
            [ 'l_page_id' => $pageId, 'l_voter_hash' => $this->voterHash( $userId ), 'l_status' => self::STATUS_PENDING ],
            __METHOD__,
            [ 'ORDER BY' => 'l_submitted DESC' ]
        );
        $rows = [];
        foreach ( $res as $r ) { $rows[] = $r; }
        return $rows;
    }

    /** All pending entries (admin queue). */
    public function listPending( int $limit = 200 ): array {
        $res = $this->dbr()->select(
            [ 'l' => 'pcp_literature', 'p' => 'page' ],
            [ 'l.*', 'l.l_display_name AS submitter_name', 'p.page_title' ],
            [ 'l.l_status' => self::STATUS_PENDING ],
            __METHOD__,
            [ 'ORDER BY' => 'l.l_submitted ASC', 'LIMIT' => $limit ],
            [
                'p' => [ 'LEFT JOIN', 'l.l_page_id = p.page_id' ],
            ]
        );
        $rows = [];
        foreach ( $res as $r ) { $rows[] = $r; }
        return $rows;
    }

    /** Count of pending entries (admin queue badge). */
    public function countPending(): int {
        return (int)$this->dbr()->selectField( 'pcp_literature', 'COUNT(*)',
            [ 'l_status' => self::STATUS_PENDING ], __METHOD__ );
    }

    /** Recent submissions (any status) for the activity feed. */
    public function listRecent( int $limit = 30 ): array {
        $res = $this->dbr()->select(
            [ 'l' => 'pcp_literature', 'p' => 'page' ],
            [ 'l.*', 'l.l_display_name AS submitter_name', 'p.page_title' ],
            [],
            __METHOD__,
            [ 'ORDER BY' => 'l.l_submitted DESC', 'LIMIT' => $limit ],
            [
                'p' => [ 'LEFT JOIN', 'l.l_page_id = p.page_id' ],
            ]
        );
        $rows = [];
        foreach ( $res as $r ) { $rows[] = $r; }
        return $rows;
    }

    /** True if a non-rejected entry already exists with the same DOI or PMID on this page. */
    public function findDuplicate( int $pageId, ?string $doi, ?int $pmid ) {
        $dbr = $this->dbr();
        $base = [
            'l_page_id' => $pageId,
            'l_status'  => [ self::STATUS_PENDING, self::STATUS_APPROVED ],
        ];
        if ( $doi !== null && $doi !== '' ) {
            $row = $dbr->selectRow( 'pcp_literature', 'l_id',
                $base + [ 'l_doi' => $doi ], __METHOD__ );
            if ( $row ) { return $row; }
        }
        if ( $pmid !== null && $pmid > 0 ) {
            $row = $dbr->selectRow( 'pcp_literature', 'l_id',
                $base + [ 'l_pmid' => $pmid ], __METHOD__ );
            if ( $row ) { return $row; }
        }
        return null;
    }

    /** Count submissions made by this user in the last 24h. */
    public function countRecentForUser( int $userId, int $windowSec = 86400 ): int {
        $dbr = $this->dbr();
        $cutoff = $dbr->timestamp( time() - $windowSec );
        return (int)$dbr->selectField(
            'pcp_literature', 'COUNT(*)',
            [ 'l_voter_hash' => $this->voterHash( $userId ), 'l_submitted >= ' . $dbr->addQuotes( $cutoff ) ],
            __METHOD__
        );
    }

    /**
     * Move an uploaded PDF into the private dir.
     * Validates magic bytes, size, and runs ClamAV if available.
     * Returns absolute path on success; throws on failure.
     */
    public function storeUploadedPdf( string $tmpName, string $originalName ): string {
        if ( !is_file( $tmpName ) ) {
            throw new \RuntimeException( 'Uploaded file missing' );
        }
        $size = filesize( $tmpName );
        if ( $size === false || $size <= 0 ) {
            throw new \RuntimeException( 'Uploaded file is empty' );
        }
        if ( $size > self::MAX_BYTES ) {
            throw new \RuntimeException( 'File too large (max 22.22 MB)' );
        }

        // Magic-byte check: first 4 bytes must be "%PDF".
        $fh = fopen( $tmpName, 'rb' );
        if ( !$fh ) { throw new \RuntimeException( 'Cannot read uploaded file' ); }
        $head = fread( $fh, 5 );
        fclose( $fh );
        if ( substr( $head, 0, 4 ) !== '%PDF' ) {
            throw new \RuntimeException( 'File does not look like a PDF' );
        }

        // ClamAV scan (FAIL-CLOSED: rejects if scanner missing or errors).
        $avScan = \MediaWiki\Extension\Pharmacopedia\VirusScanner::scanFile( $tmpName );
        if ( !$avScan['ok'] ) {
            throw new \RuntimeException( 'Upload rejected by antivirus: ' . $avScan['reason'] );
        }

        $dir = $this->filesDir();
        if ( !is_dir( $dir ) ) {
            throw new \RuntimeException( 'Literature storage directory missing' );
        }
        $hash = hash_file( 'sha256', $tmpName );
        if ( !$hash ) { throw new \RuntimeException( 'Hash failed' ); }
        $newPath = $dir . '/' . $hash . '.pdf';

        if ( !file_exists( $newPath ) ) {
            if ( !@move_uploaded_file( $tmpName, $newPath ) ) {
                // Fall back to copy (helpful in tests / non-upload contexts)
                if ( !@copy( $tmpName, $newPath ) ) {
                    throw new \RuntimeException( 'Failed to store uploaded file' );
                }
            }
            @chmod( $newPath, 0600 );
        }
        return $newPath;
    }

    /** Insert a new pending literature entry. Returns the new l_id. */
    public function createPending(
        int $pageId, int $userId, array $fields, ?array $file = null, ?string $displayName = null
    ): int {
        $dbw = $this->dbw();
        $row = [
            'l_page_id'       => $pageId,
            'l_voter_hash'    => $this->voterHash( $userId ),
            'l_display_name'  => $displayName,
            'l_status'        => self::STATUS_PENDING,
            'l_authors'       => $fields['authors']  ?? null,
            'l_et_al'         => !empty( $fields['et_al'] ) ? 1 : 0,
            'l_title'         => $fields['title'],
            'l_year'          => $fields['year']     ?? null,
            'l_url'           => $fields['url']      ?? null,
            'l_doi'           => $fields['doi']      ?? null,
            'l_pmid'          => $fields['pmid']     ?? null,
            'l_file_path'     => $file['path']       ?? null,
            'l_file_origname' => $file['origname']   ?? null,
            'l_file_mime'     => $file['mime']       ?? null,
            'l_file_size'     => $file['size']       ?? null,
            'l_submitted'     => $dbw->timestamp(),
        ];
        $dbw->insert( 'pcp_literature', $row, __METHOD__ );
        return (int)$dbw->insertId();
    }

    /** Approve a pending entry. */
    public function approve( int $id, int $reviewerId, ?string $notes = null ): bool {
        return $this->decide( $id, $reviewerId, self::STATUS_APPROVED, $notes );
    }

    /** Reject a pending entry. Also deletes the stored file. */
    public function reject( int $id, int $reviewerId, ?string $notes = null ): bool {
        $row = $this->dbw()->selectRow( 'pcp_literature', 'l_file_path',
            [ 'l_id' => $id, 'l_status' => self::STATUS_PENDING ], __METHOD__ );
        if ( $row && $row->l_file_path && is_file( $row->l_file_path ) ) {
            @unlink( $row->l_file_path );
        }
        return $this->decide( $id, $reviewerId, self::STATUS_REJECTED, $notes, true );
    }

    private function decide( int $id, int $reviewerId, int $status, ?string $notes,
                              bool $wipeFile = false ): bool {
        $dbw = $this->dbw();
        $row = $dbw->selectRow( 'pcp_literature', '*',
            [ 'l_id' => $id, 'l_status' => self::STATUS_PENDING ], __METHOD__ );
        if ( !$row ) { return false; }

        $update = [
            'l_status'      => $status,
            'l_reviewed_by' => $reviewerId,
            'l_reviewed'    => $dbw->timestamp(),
            'l_admin_notes' => $notes,
        ];
        if ( $wipeFile ) { $update['l_file_path'] = null; }
        $dbw->update( 'pcp_literature', $update, [ 'l_id' => $id ], __METHOD__ );
        return true;
    }

    /** Submitter-initiated delete of own pending entry. */
    public function deletePendingByUser( int $id, int $userId ): bool {
        $dbw = $this->dbw();
        $row = $dbw->selectRow( 'pcp_literature', '*',
            [ 'l_id' => $id, 'l_voter_hash' => $this->voterHash( $userId ), 'l_status' => self::STATUS_PENDING ],
            __METHOD__ );
        if ( !$row ) { return false; }
        if ( $row->l_file_path && is_file( $row->l_file_path ) ) {
            @unlink( $row->l_file_path );
        }
        $dbw->delete( 'pcp_literature', [ 'l_id' => $id ], __METHOD__ );
        return true;
    }

    /** Admin hard-delete (any status). */
    public function adminDelete( int $id ): bool {
        $dbw = $this->dbw();
        $row = $dbw->selectRow( 'pcp_literature', 'l_file_path',
            [ 'l_id' => $id ], __METHOD__ );
        if ( !$row ) { return false; }
        if ( $row->l_file_path && is_file( $row->l_file_path ) ) {
            @unlink( $row->l_file_path );
        }
        $dbw->delete( 'pcp_literature', [ 'l_id' => $id ], __METHOD__ );
        return true;
    }

    public static function statusLabel( int $s ): string {
        return [
            self::STATUS_PENDING  => 'pending',
            self::STATUS_APPROVED => 'approved',
            self::STATUS_REJECTED => 'rejected',
        ][ $s ] ?? 'unknown';
    }

    /** Validate a DOI string. Returns canonical lowercase form or null. */
    public static function normalizeDoi( string $raw ): ?string {
        $raw = trim( $raw );
        if ( $raw === '' ) { return null; }
        // Strip common prefixes
        $raw = preg_replace( '#^https?://(dx\.)?doi\.org/#i', '', $raw );
        $raw = preg_replace( '#^doi:\s*#i', '', $raw );
        // DOIs start with 10. and contain a slash.
        if ( !preg_match( '#^10\.\d{3,9}/[\S]+$#', $raw ) ) { return null; }
        return strtolower( $raw );
    }

    public function voterHash( $userId ): string {
        global $wgPharmacopediaVoteHashSecret;
        if ( !$wgPharmacopediaVoteHashSecret ) { throw new \RuntimeException( '$wgPharmacopediaVoteHashSecret must be set' ); }
        return hash_hmac( 'sha256', (string)$userId, $wgPharmacopediaVoteHashSecret );
    }

}
