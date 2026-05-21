<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

/**
 * PerspectiveStore: data access for the Perspective subsystem, the
 * pcp_perspective_invite and pcp_perspective tables.
 *
 * The two-gate model lives here:
 *   Gate 1, contribution: resolveToken() is the only way to confirm a
 *     contribution is permitted. No valid token, no perspective.
 *   Gate 2, publication: recordPerspective() always stores psp_consent
 *     = 0 (private to the owner). consent() is the only path to
 *     psp_consent = 1, and it re-checks owner identity rather than
 *     trusting the caller.
 *
 * See perspective_subsystem_spec.md.
 */
class PerspectiveStore {

    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }

    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    /**
     * Create an invite. Returns the opaque token to place in the link.
     * The token is the only thing the invitee URL carries.
     */
    public function mintInvite(
        int $ownerId, string $objectType, string $objectId,
        string $perspectiveType, string $displayName,
        ?int $maxUses, int $createdUserId
    ): string {
        $token = VisibilityResolver::generateLinkToken();
        $dbw = $this->dbw();
        $dbw->insert( 'pcp_perspective_invite', [
            'pvi_token'            => $token,
            'pvi_owner_id'         => $ownerId,
            'pvi_object_type'      => $objectType,
            'pvi_object_id'        => $objectId,
            'pvi_perspective_type' => $perspectiveType,
            'pvi_display_name'     => mb_substr( $displayName, 0, 128 ),
            'pvi_max_uses'         => $maxUses,
            'pvi_uses'             => 0,
            'pvi_status'           => 'active',
            'pvi_created'          => $dbw->timestamp(),
            'pvi_created_user_id'  => $createdUserId,
        ], __METHOD__ );
        return $token;
    }

    /**
     * Resolve an invite token. Gate 1. Returns the invite row only if
     * it is active and not used up; null in every other case (no match,
     * revoked, use limit reached).
     */
    public function resolveToken( string $token ): ?\stdClass {
        if ( $token === '' ) {
            return null;
        }
        $row = $this->dbr()->selectRow( 'pcp_perspective_invite', '*',
            [ 'pvi_token' => $token ], __METHOD__ );
        if ( !$row ) {
            return null;
        }
        if ( (string)$row->pvi_status !== 'active' ) {
            return null;
        }
        if ( $row->pvi_max_uses !== null
            && (int)$row->pvi_uses >= (int)$row->pvi_max_uses ) {
            return null;
        }
        return $row;
    }

    /**
     * Record a submitted perspective. Always born private
     * (psp_consent = 0). Increments the invite's use counter. Returns
     * the new perspective id.
     *
     * @param \stdClass $invite a resolved pcp_perspective_invite row
     */
    public function recordPerspective(
        \stdClass $invite, ?int $giverUserId, ?string $giverLabel,
        array $payload, ?string $validity, ?string $ip
    ): int {
        $dbw = $this->dbw();
        $dbw->insert( 'pcp_perspective', [
            'psp_invite_id'        => (int)$invite->pvi_id,
            'psp_owner_id'         => (int)$invite->pvi_owner_id,
            'psp_object_type'      => (string)$invite->pvi_object_type,
            'psp_object_id'        => (string)$invite->pvi_object_id,
            'psp_perspective_type' => (string)$invite->pvi_perspective_type,
            'psp_giver_user_id'    => $giverUserId,
            'psp_giver_label'      => $giverLabel !== null ? mb_substr( $giverLabel, 0, 128 ) : null,
            'psp_payload'          => json_encode( $payload ),
            'psp_validity'         => $validity,
            'psp_consent'          => 0,
            'psp_consent_at'       => null,
            'psp_submitted'        => $dbw->timestamp(),
            'psp_submitter_ip'     => $ip,
        ], __METHOD__ );
        $id = (int)$dbw->insertId();
        $dbw->update( 'pcp_perspective_invite',
            [ 'pvi_uses = pvi_uses + 1' ],
            [ 'pvi_id' => (int)$invite->pvi_id ], __METHOD__ );
        return $id;
    }

    /** A single perspective row by id, or null. */
    public function getPerspective( int $id ): ?\stdClass {
        $row = $this->dbr()->selectRow( 'pcp_perspective', '*',
            [ 'psp_id' => $id ], __METHOD__ );
        return $row ?: null;
    }

    /**
     * Perspectives on an owner's objects, newest first. The consent
     * inbox reads this. Optionally narrowed to one object.
     */
    public function listForOwner(
        int $ownerId, ?string $objectType = null, ?string $objectId = null
    ): array {
        $conds = [ 'psp_owner_id' => $ownerId ];
        if ( $objectType !== null ) {
            $conds['psp_object_type'] = $objectType;
        }
        if ( $objectId !== null ) {
            $conds['psp_object_id'] = $objectId;
        }
        $res = $this->dbr()->select( 'pcp_perspective', '*', $conds, __METHOD__,
            [ 'ORDER BY' => 'psp_submitted DESC' ] );
        $out = [];
        foreach ( $res as $row ) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Gate 2. The owner consents to publish a perspective: psp_consent
     * goes to 1. Ownership is re-checked here, never trusted from the
     * UI. Returns false if the perspective is not the caller's.
     */
    public function consent( int $perspectiveId, int $ownerId ): bool {
        $row = $this->getPerspective( $perspectiveId );
        if ( !$row || (int)$row->psp_owner_id !== $ownerId ) {
            return false;
        }
        $dbw = $this->dbw();
        $dbw->update( 'pcp_perspective',
            [ 'psp_consent' => 1, 'psp_consent_at' => $dbw->timestamp() ],
            [ 'psp_id' => $perspectiveId ], __METHOD__ );
        return true;
    }

    /** The owner withdraws consent: a published perspective goes back to private. */
    public function unconsent( int $perspectiveId, int $ownerId ): bool {
        $row = $this->getPerspective( $perspectiveId );
        if ( !$row || (int)$row->psp_owner_id !== $ownerId ) {
            return false;
        }
        $this->dbw()->update( 'pcp_perspective',
            [ 'psp_consent' => 0, 'psp_consent_at' => null ],
            [ 'psp_id' => $perspectiveId ], __METHOD__ );
        return true;
    }

    /** The owner deletes a perspective given on their object. */
    public function deletePerspective( int $perspectiveId, int $ownerId ): bool {
        $row = $this->getPerspective( $perspectiveId );
        if ( !$row || (int)$row->psp_owner_id !== $ownerId ) {
            return false;
        }
        $this->dbw()->delete( 'pcp_perspective',
            [ 'psp_id' => $perspectiveId ], __METHOD__ );
        return true;
    }

    /** Invites an owner holds, newest first. */
    public function listInvitesForOwner( int $ownerId ): array {
        $res = $this->dbr()->select( 'pcp_perspective_invite', '*',
            [ 'pvi_owner_id' => $ownerId ], __METHOD__,
            [ 'ORDER BY' => 'pvi_created DESC' ] );
        $out = [];
        foreach ( $res as $row ) {
            $out[] = $row;
        }
        return $out;
    }

    /** Revoke an invite: its token resolves to nothing thereafter. */
    public function revokeInvite( int $inviteId, int $ownerId ): bool {
        $row = $this->dbr()->selectRow( 'pcp_perspective_invite', '*',
            [ 'pvi_id' => $inviteId ], __METHOD__ );
        if ( !$row || (int)$row->pvi_owner_id !== $ownerId ) {
            return false;
        }
        $this->dbw()->update( 'pcp_perspective_invite',
            [ 'pvi_status' => 'revoked' ],
            [ 'pvi_id' => $inviteId ], __METHOD__ );
        return true;
    }

    /**
     * Count of perspectives on an owner's objects; with $pendingOnly,
     * only those still awaiting a consent decision (psp_consent = 0).
     */
    public function countForOwner( int $ownerId, bool $pendingOnly = false ): int {
        $conds = [ 'psp_owner_id' => $ownerId ];
        if ( $pendingOnly ) {
            $conds['psp_consent'] = 0;
        }
        return (int)$this->dbr()->selectRowCount( 'pcp_perspective', '*', $conds, __METHOD__ );
    }
}
