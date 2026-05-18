<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

class ProviderAppStore {
    const STATUS_PENDING  = 0;
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;

    const ALLOWED_MIME = [
        'application/pdf', 'image/jpeg', 'image/png',
    ];
    const MAX_BYTES = 10 * 1024 * 1024; // 10 MB

    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }
    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    public function docsDir() {
        global $wgPharmacopediaVerifyDocsDir;
        return rtrim( $wgPharmacopediaVerifyDocsDir, '/' );
    }

    /** Latest application (any status) for this user, or null. */
    public function getLatestForUser( $userId ) {
        return $this->dbr()->selectRow(
            'pcp_provider_apps', '*',
            [ 'pa_user_id' => $userId ],
            __METHOD__,
            [ 'ORDER BY' => 'pa_id DESC', 'LIMIT' => 1 ]
        );
    }

    public function getById( $id ) {
        return $this->dbr()->selectRow(
            'pcp_provider_apps', '*',
            [ 'pa_id' => $id ],
            __METHOD__
        );
    }

    public function listPending() {
        $res = $this->dbr()->select(
            [ 'pa' => 'pcp_provider_apps', 'u' => 'user' ],
            [ 'pa.*', 'u.user_name' ],
            [ 'pa.pa_status' => self::STATUS_PENDING ],
            __METHOD__,
            [ 'ORDER BY' => 'pa.pa_submitted ASC' ],
            [ 'u' => [ 'LEFT JOIN', 'pa.pa_user_id = u.user_id' ] ]
        );
        $rows = [];
        foreach ( $res as $r ) { $rows[] = $r; }
        return $rows;
    }

    public function countPending(): int {
        return (int)$this->dbr()->selectField( 'pcp_provider_apps', 'COUNT(*)',
            [ 'pa_status' => self::STATUS_PENDING ], __METHOD__ );
    }

    /** Create a new application. Returns the new pa_id. */
    public function create( $userId, $fields, $docPaths ) {
        $dbw = $this->dbw();
        $dbw->insert( 'pcp_provider_apps', [
            'pa_user_id'        => $userId,
            'pa_status'         => self::STATUS_PENDING,
            'pa_profession'     => $fields['profession'] ?? null,
            'pa_specialty'      => $fields['specialty'] ?? null,
            'pa_jurisdiction'   => $fields['jurisdiction'] ?? null,
            'pa_license_number' => $fields['license_number'] ?? null,
            'pa_real_name'      => $fields['real_name'] ?? null,
            'pa_notes'          => $fields['notes'] ?? null,
            'pa_doc_paths'      => json_encode( $docPaths ),
            'pa_submitted'      => $dbw->timestamp(),
        ], __METHOD__ );
        return $dbw->insertId();
    }

    /** Save a single uploaded file into the private dir. Returns the absolute path or throws. */
    public function saveUploadedFile( $tmpName, $originalName, $mimeType ) {
        if ( !file_exists( $tmpName ) ) {
            throw new \RuntimeException( 'Uploaded file missing' );
        }
        if ( !in_array( $mimeType, self::ALLOWED_MIME, true ) ) {
            throw new \RuntimeException( 'File type not allowed: ' . $mimeType );
        }
        if ( filesize( $tmpName ) > self::MAX_BYTES ) {
            throw new \RuntimeException( 'File too large (>10MB)' );
        }

        $dir = $this->docsDir();
        if ( !is_dir( $dir ) ) {
            throw new \RuntimeException( 'Verification storage directory missing' );
        }

        $ext = strtolower( pathinfo( $originalName, PATHINFO_EXTENSION ) );
        if ( !in_array( $ext, [ 'pdf', 'jpg', 'jpeg', 'png' ], true ) ) {
            $ext = 'bin';
        }
        $randomId = bin2hex( random_bytes( 12 ) );
        $newName = sprintf( '%s_%s.%s', date( 'Ymd_His' ), $randomId, $ext );
        $newPath = $dir . '/' . $newName;

        // PROJECT RULE: virus-scan every upload before storing (see feedback_clamav_upload_rule.md).
        $scan = \MediaWiki\Extension\Pharmacopedia\VirusScanner::scanFile( $tmpName );
        if ( !$scan['ok'] ) {
            throw new \RuntimeException( 'Upload rejected by antivirus: ' . $scan['reason'] );
        }
        if ( !@move_uploaded_file( $tmpName, $newPath ) ) {
            throw new \RuntimeException( 'Failed to store uploaded file' );
        }
        chmod( $newPath, 0600 );
        return $newPath;
    }

    /** Write in-memory bytes (e.g. a captured webcam JPEG) to the private dir. */
    public function saveBytes( $bytes, $ext, $mimeType ) {
        if ( !in_array( $mimeType, self::ALLOWED_MIME, true ) ) {
            throw new \RuntimeException( 'File type not allowed: ' . $mimeType );
        }
        if ( strlen( $bytes ) > self::MAX_BYTES ) {
            throw new \RuntimeException( 'Photo too large (>10MB)' );
        }
        $dir = $this->docsDir();
        if ( !is_dir( $dir ) ) {
            throw new \RuntimeException( 'Verification storage directory missing' );
        }
        $ext = strtolower( $ext );
        if ( !in_array( $ext, [ 'jpg', 'jpeg', 'png' ], true ) ) { $ext = 'jpg'; }
        $randomId = bin2hex( random_bytes( 12 ) );
        $newName = sprintf( '%s_%s_webcam.%s', date( 'Ymd_His' ), $randomId, $ext );
        $newPath = $dir . '/' . $newName;
        if ( file_put_contents( $newPath, $bytes ) === false ) {
            throw new \RuntimeException( 'Failed to store captured photo' );
        }
        chmod( $newPath, 0600 );
        return $newPath;
    }

    public function decide( $appId, $reviewerId, $status, $adminNotes, $autoGrantGroup = true ) {
        $dbw = $this->dbw();
        $row = $dbw->selectRow( 'pcp_provider_apps', '*', [ 'pa_id' => $appId ], __METHOD__ );
        if ( !$row || (int)$row->pa_status !== self::STATUS_PENDING ) {
            return false;
        }
        // Delete docs immediately
        $paths = json_decode( $row->pa_doc_paths ?? '[]', true ) ?: [];
        foreach ( $paths as $p ) {
            if ( is_string( $p ) && file_exists( $p ) ) {
                @unlink( $p );
            }
        }
        $dbw->update( 'pcp_provider_apps', [
            'pa_status'      => $status,
            'pa_admin_notes' => $adminNotes,
            'pa_reviewed_by' => $reviewerId,
            'pa_reviewed'    => $dbw->timestamp(),
            'pa_doc_paths'   => json_encode( [] ), // wiped
        ], [ 'pa_id' => $appId ], __METHOD__ );

        if ( $status === self::STATUS_APPROVED && $autoGrantGroup ) {
            $services = MediaWikiServices::getInstance();
            $userFactory = $services->getUserFactory();
            $userGroupManager = $services->getUserGroupManager();
            $user = $userFactory->newFromId( (int)$row->pa_user_id );
            if ( $user && $user->isRegistered() ) {
                $userGroupManager->addUserToGroup( $user, 'provider' );
            }
        }
        return true;
    }
}
