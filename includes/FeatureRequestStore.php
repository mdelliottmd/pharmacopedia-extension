<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;

/**
 * Data layer for pcp_feature_request and its child tables (attachments,
 * comments). Privacy resolution lives here so the special pages stay thin.
 */
class FeatureRequestStore {

    public const STATUSES = [
        'new'           => 'New',
        'acknowledged'  => 'Acknowledged',
        'in_progress'   => 'In progress',
        'done'          => 'Done',
        'wontfix'       => "Won't fix",
        'duplicate'     => 'Duplicate',
    ];
    public const TERMINAL_STATUSES = [ 'done', 'wontfix', 'duplicate' ];
    public const PRIORITIES = [
        0 => '(none)',
        1 => 'Low',
        2 => 'Medium',
        3 => 'High',
        4 => 'Critical',
    ];

    public const ATTACHMENT_MAX_BYTES   = 20 * 1024 * 1024;
    public const ATTACHMENT_MAX_PER_REQ = 10;

    private function dbw() {
        return MediaWikiServices::getInstance()
            ->getDBLoadBalancer()->getConnection( DB_PRIMARY );
    }
    private function dbr() {
        return MediaWikiServices::getInstance()
            ->getDBLoadBalancer()->getConnection( DB_REPLICA );
    }

    // ===== Requests =====

    public function create( int $userId, string $title, string $body, int $usernameVis, bool $contentPrivate ): int {
        $now = wfTimestampNow();
        $this->dbw()->insert( 'pcp_feature_request', [
            'fr_user_id'      => $userId,
            'fr_created'      => $now,
            'fr_updated'      => $now,
            'fr_title'        => mb_substr( $title, 0, 200 ),
            'fr_body'         => $body,
            'fr_username_vis' => max( 0, min( 3, $usernameVis ) ),
            'fr_content_vis'  => $contentPrivate ? 1 : 0,
            'fr_status'       => 'new',
            'fr_priority'     => 0,
        ], __METHOD__ );
        return (int)$this->dbw()->insertId();
    }

    public function getById( int $id ): ?\stdClass {
        $row = $this->dbr()->selectRow( 'pcp_feature_request', '*',
            [ 'fr_id' => $id ], __METHOD__ );
        return $row ?: null;
    }

    public function listRequests( array $filters = [], int $limit = 200, int $offset = 0 ): array {
        $conds = [];
        if ( !empty( $filters['status'] ) ) {
            $conds['fr_status'] = (array)$filters['status'];
        }
        if ( !empty( $filters['priority'] ) ) {
            $conds['fr_priority'] = (array)$filters['priority'];
        }
        if ( !empty( $filters['userId'] ) ) {
            $conds['fr_user_id'] = (int)$filters['userId'];
        }
        if ( isset( $filters['includeResolved'] ) && !$filters['includeResolved'] ) {
            $conds[] = 'fr_status NOT IN (' . $this->dbr()->makeList( self::TERMINAL_STATUSES ) . ')';
        }
        if ( isset( $filters['onlyResolved'] ) && $filters['onlyResolved'] ) {
            $conds['fr_status'] = self::TERMINAL_STATUSES;
        }
        $orderBy = $filters['orderBy'] ?? 'fr_created ASC';
        $res = $this->dbr()->select( 'pcp_feature_request', '*', $conds, __METHOD__,
            [ 'ORDER BY' => $orderBy, 'LIMIT' => $limit, 'OFFSET' => $offset ]
        );
        $out = [];
        foreach ( $res as $r ) $out[] = $r;
        return $out;
    }

    public function countByStatus(): array {
        $res = $this->dbr()->select( 'pcp_feature_request',
            [ 'fr_status', 'cnt' => 'COUNT(*)' ], [], __METHOD__,
            [ 'GROUP BY' => 'fr_status' ]
        );
        $out = [];
        foreach ( $res as $r ) $out[ $r->fr_status ] = (int)$r->cnt;
        return $out;
    }

    public function canEdit( \stdClass $row, User $viewer ): bool {
        if ( in_array( $row->fr_status, self::TERMINAL_STATUSES, true ) ) {
            return $viewer->isAllowed( 'pharmacopedia-fr-review' );
        }
        if ( (int)$row->fr_user_id === $viewer->getId() ) return true;
        return $viewer->isAllowed( 'pharmacopedia-fr-review' );
    }

    public function canResolve( \stdClass $row, User $viewer ): bool {
        if ( in_array( $row->fr_status, self::TERMINAL_STATUSES, true ) ) return false;
        if ( (int)$row->fr_user_id === $viewer->getId() ) return true;
        return $viewer->isAllowed( 'pharmacopedia-fr-review' );
    }

    public function canViewBody( \stdClass $row, User $viewer ): bool {
        if ( (int)$row->fr_content_vis === 0 ) return true;
        if ( !$viewer->isRegistered() ) return false;
        if ( (int)$row->fr_user_id === $viewer->getId() ) return true;
        return $viewer->isAllowed( 'pharmacopedia-fr-view-private' );
    }

    public function updateContent( int $id, string $title, string $body, int $usernameVis, bool $contentPrivate ): void {
        $this->dbw()->update( 'pcp_feature_request',
            [
                'fr_title'        => mb_substr( $title, 0, 200 ),
                'fr_body'         => $body,
                'fr_username_vis' => max( 0, min( 3, $usernameVis ) ),
                'fr_content_vis'  => $contentPrivate ? 1 : 0,
                'fr_updated'      => wfTimestampNow(),
            ],
            [ 'fr_id' => $id ], __METHOD__
        );
    }

    public function updateStatus( int $id, string $newStatus, ?int $actorUserId = null ): void {
        $row = $this->getById( $id );
        if ( !$row ) return;
        $oldStatus = (string)$row->fr_status;
        if ( $oldStatus === $newStatus ) return;
        $set = [
            'fr_status'  => $newStatus,
            'fr_updated' => wfTimestampNow(),
        ];
        $isTerminalNow = in_array( $newStatus, self::TERMINAL_STATUSES, true );
        $wasTerminal   = in_array( $oldStatus, self::TERMINAL_STATUSES, true );
        if ( $isTerminalNow && !$wasTerminal ) {
            $set['fr_resolved_at'] = wfTimestampNow();
            $set['fr_resolved_by'] = $actorUserId;
        } elseif ( !$isTerminalNow && $wasTerminal ) {
            $set['fr_resolved_at'] = null;
            $set['fr_resolved_by'] = null;
        }
        $this->dbw()->update( 'pcp_feature_request', $set, [ 'fr_id' => $id ], __METHOD__ );
        FeatureRequestNotifier::onStatusChange( $row, $oldStatus, $newStatus, $actorUserId );
    }

    public function updatePriority( int $id, int $priority ): void {
        $priority = max( 0, min( 4, $priority ) );
        $this->dbw()->update( 'pcp_feature_request',
            [ 'fr_priority' => $priority, 'fr_updated' => wfTimestampNow() ],
            [ 'fr_id' => $id ], __METHOD__
        );
    }

    public function updateSysopNotes( int $id, string $notes ): void {
        $this->dbw()->update( 'pcp_feature_request',
            [ 'fr_sysop_notes' => $notes, 'fr_updated' => wfTimestampNow() ],
            [ 'fr_id' => $id ], __METHOD__
        );
    }

    public function delete( int $id ): void {
        // Cascade: delete attachments + comments first
        $atts = $this->listAttachments( $id, true );
        foreach ( $atts as $a ) {
            $this->deleteAttachment( (int)$a->fra_id, /*hard=*/true );
        }
        $this->dbw()->delete( 'pcp_feature_request_comment', [ 'frc_request_id' => $id ], __METHOD__ );
        $this->dbw()->delete( 'pcp_feature_request', [ 'fr_id' => $id ], __METHOD__ );
    }

    /**
     * Resolve the submitter display string per privacy + viewer.
     * Sysops always see the real username (with parenthetical note).
     */
    public function submitterDisplay( \stdClass $row, User $viewer ): string {
        $userFactory = MediaWikiServices::getInstance()->getUserFactory();
        $submitter = $userFactory->newFromId( (int)$row->fr_user_id );
        $realName = $submitter ? $submitter->getName() : 'Unknown';
        $sysopView = $viewer->isAllowed( 'pharmacopedia-fr-review' );

        $vis = (int)$row->fr_username_vis;

        // VIS_PUBLIC_ANONYMOUS = 3 (force anonymous)
        if ( $vis === 3 ) {
            return $sysopView ? ( $realName . ' (submitted anonymously)' ) : 'Anonymous';
        }
        // VIS_PUBLIC_USERNAME = 2 (force username)
        if ( $vis === 2 ) {
            return $realName;
        }
        // VIS_PUBLIC_DEFAULT = 1 (use profile default), or PRIVATE = 0 (treat as default for this surface)
        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( (int)$row->fr_user_id );
        if ( $profile ) {
            $name = $store->publicDisplayName( $profile, UserProfileStore::VIS_PUBLIC_DEFAULT );
            if ( $name === 'Anonymous' && $sysopView ) {
                return $realName . ' (profile-default attribution: Anonymous)';
            }
            return $name;
        }
        return $realName;
    }

    // ===== Attachments =====

    public function addAttachment( int $requestId, int $userId, string $displayName, string $storageName, string $mime, int $size, int $scanStatus, ?string $scanResult ): int {
        $this->dbw()->insert( 'pcp_feature_request_attachment', [
            'fra_request_id'   => $requestId,
            'fra_uploaded_by'  => $userId,
            'fra_uploaded_at'  => wfTimestampNow(),
            'fra_filename'     => mb_substr( $displayName, 0, 255 ),
            'fra_storage_name' => $storageName,
            'fra_mime'         => $mime,
            'fra_size'         => $size,
            'fra_scan_status'  => $scanStatus,
            'fra_scan_result'  => $scanResult,
        ], __METHOD__ );
        return (int)$this->dbw()->insertId();
    }

    public function getAttachment( int $attId ): ?\stdClass {
        $row = $this->dbr()->selectRow( 'pcp_feature_request_attachment', '*',
            [ 'fra_id' => $attId ], __METHOD__ );
        return $row ?: null;
    }

    public function listAttachments( int $requestId, bool $includeDeleted = false ): array {
        $conds = [ 'fra_request_id' => $requestId ];
        if ( !$includeDeleted ) $conds['fra_deleted'] = 0;
        $res = $this->dbr()->select( 'pcp_feature_request_attachment', '*', $conds, __METHOD__,
            [ 'ORDER BY' => 'fra_uploaded_at ASC' ] );
        $out = [];
        foreach ( $res as $r ) $out[] = $r;
        return $out;
    }

    public function countAttachments( int $requestId ): int {
        return (int)$this->dbr()->selectField( 'pcp_feature_request_attachment',
            'COUNT(*)', [ 'fra_request_id' => $requestId, 'fra_deleted' => 0 ], __METHOD__ );
    }

    public function deleteAttachment( int $attId, bool $hard = false ): void {
        $att = $this->getAttachment( $attId );
        if ( !$att ) return;
        if ( $hard ) {
            // Remove file from disk
            $path = AttachmentStorage::pathFor( (int)$att->fra_request_id, (string)$att->fra_storage_name );
            if ( $path && is_file( $path ) ) @unlink( $path );
            $this->dbw()->delete( 'pcp_feature_request_attachment', [ 'fra_id' => $attId ], __METHOD__ );
        } else {
            $this->dbw()->update( 'pcp_feature_request_attachment',
                [ 'fra_deleted' => 1 ], [ 'fra_id' => $attId ], __METHOD__ );
        }
    }

    // ===== Comments =====

    public function addComment( int $requestId, int $userId, string $body, bool $isSysop ): int {
        $now = wfTimestampNow();
        $this->dbw()->insert( 'pcp_feature_request_comment', [
            'frc_request_id' => $requestId,
            'frc_user_id'    => $userId,
            'frc_created'    => $now,
            'frc_updated'    => $now,
            'frc_body'       => $body,
            'frc_is_sysop'   => $isSysop ? 1 : 0,
        ], __METHOD__ );
        $this->dbw()->update( 'pcp_feature_request',
            [ 'fr_updated' => $now ], [ 'fr_id' => $requestId ], __METHOD__ );
        return (int)$this->dbw()->insertId();
    }

    public function listComments( int $requestId, bool $includeDeleted = false ): array {
        $conds = [ 'frc_request_id' => $requestId ];
        if ( !$includeDeleted ) $conds['frc_deleted'] = 0;
        $res = $this->dbr()->select( 'pcp_feature_request_comment', '*', $conds, __METHOD__,
            [ 'ORDER BY' => 'frc_created ASC' ] );
        $out = [];
        foreach ( $res as $r ) $out[] = $r;
        return $out;
    }

    public function deleteComment( int $commentId ): void {
        $this->dbw()->update( 'pcp_feature_request_comment',
            [ 'frc_deleted' => 1 ], [ 'frc_id' => $commentId ], __METHOD__ );
    }
}
