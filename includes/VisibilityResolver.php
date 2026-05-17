<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

/**
 * Centralised visibility-check service.
 *
 * Resolves "can viewer V see owner O's data at (namespace, key)?" against:
 *   1. owner-always-sees-own-stuff
 *   2. richer rules in pcp_visibility_rules (users / cohort / link_token /
 *      reciprocal / time-bounded / explicit public/private)
 *   3. legacy pf_visibility column fallback (preserves all current behavior
 *      when no rules exist for the record)
 *
 * Phase 1 ships this class but does NOT change any caller behavior — the
 * resolver's fallback path always defers to the legacy pf_visibility result,
 * so callers that switch to canView() get identical answers until owners
 * start creating rules in Phase 2+.
 *
 * Rule types (vr_rule_type):
 *   'private'      - explicit deny (only owner)
 *   'public'       - explicit allow (anyone, like legacy vis>=1)
 *   'users'        - payload {user_ids:[...]} or {usernames:[...]}, allow if viewer in list
 *   'cohort'       - payload {cohort_id:N}, allow if viewer in pcp_cohort_members for cohort
 *   'link_token'   - payload {token:'...', uses_remaining:null|N}, allow if linkToken matches
 *   'reciprocal'   - payload {ns:'cati', key:null}, allow if viewer has shared same shape back
 */
class VisibilityResolver {

    /**
     * Can $viewerUserId (0 = anonymous) view $ownerProfileId's data at ($namespace, $key)?
     *
     * @param int    $ownerProfileId  prof_id of the owning profile
     * @param int    $viewerUserId    user_id of the viewer (0 if anonymous)
     * @param string $namespace       e.g. 'cati', 'ocean', 'demographics'
     * @param string|null $key        specific field key, or null for namespace-wide
     * @param string|null $linkToken  if viewer is using a share link, the token (otherwise null)
     * @return bool
     */
    public static function canView(
        int $ownerProfileId,
        int $viewerUserId,
        string $namespace,
        ?string $key = null,
        ?string $linkToken = null
    ): bool {
        // Resolve owner user id from profile id
        $ownerUserId = self::ownerUserIdForProfile( $ownerProfileId );

        // Owner always sees own stuff
        if ( $viewerUserId > 0 && $ownerUserId === $viewerUserId ) {
            return true;
        }

        // Check rules table, most-specific first
        $rules = self::matchingRules( $ownerProfileId, $namespace, $key );
        foreach ( $rules as $rule ) {
            if ( self::rulePermits( $rule, $viewerUserId, $linkToken ) ) {
                return true;
            }
        }

        // Phase 6: if owner has privacy mode on (an active *-wide \'private\'
        // rule), skip the legacy pf_visibility fallback entirely.
        if ( self::hasPrivacyMode( $ownerProfileId ) ) {
            return false;
        }
        return self::legacyFallback( $ownerProfileId, $namespace, $key );
    }

    /**
     * Same as canView() but does NOT fall back to legacy pf_visibility.
     * Returns true only if owner-sees-own OR an explicit rule permits.
     * Use this to ADD rule-based access on top of unchanged legacy checks
     * (so callers can preserve existing behavior + grant rule-driven extras).
     */
    public static function canViewByRule(
        int $ownerProfileId,
        int $viewerUserId,
        string $namespace,
        ?string $key = null,
        ?string $linkToken = null
    ): bool {
        $ownerUserId = self::ownerUserIdForProfile( $ownerProfileId );
        if ( $viewerUserId > 0 && $ownerUserId === $viewerUserId ) {
            return true;
        }
        $rules = self::matchingRules( $ownerProfileId, $namespace, $key );
        foreach ( $rules as $rule ) {
            if ( self::rulePermits( $rule, $viewerUserId, $linkToken ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Log a successful access for the audit trail. Caller does this after a
     * positive canView() result before rendering the gated content.
     *
     * @param int|null $ruleId       which rule permitted (null = owner or legacy)
     * @param int      $ownerProfileId
     * @param int      $viewerUserId  (0 = anon)
     * @param string|null $viewerIp   for anon viewers; up to caller
     * @param string   $namespace
     * @param string|null $key
     */
    public static function logView(
        ?int $ruleId,
        int $ownerProfileId,
        int $viewerUserId,
        ?string $viewerIp,
        string $namespace,
        ?string $key
    ): void {
        try {
            $dbw = MediaWikiServices::getInstance()
                ->getConnectionProvider()->getPrimaryDatabase();
            $ownerUserId = self::ownerUserIdForProfile( $ownerProfileId );
            $dbw->newInsertQueryBuilder()
                ->insertInto( 'pcp_visibility_view_log' )
                ->row( [
                    'vl_rule_id'   => $ruleId,
                    'vl_owner_id'  => $ownerUserId,
                    'vl_viewer_id' => $viewerUserId > 0 ? $viewerUserId : null,
                    'vl_viewer_ip' => $viewerIp ? inet_pton( $viewerIp ) : null,
                    'vl_namespace' => $namespace,
                    'vl_key'       => $key,
                    'vl_viewed_at' => $dbw->timestamp(),
                ] )
                ->caller( __METHOD__ )
                ->execute();
        } catch ( \Throwable $e ) {
            // Audit logging failure should never block content rendering.
            wfDebugLog( 'pharmacopedia', 'visibility view log failed: ' . $e->getMessage() );
        }
    }


    /**
     * True if the profile has an active *-wide \'private\' rule — meaning the owner
     * opted out of the legacy pf_visibility fallback and ONLY explicit rules grant.
     */
    public static function hasPrivacyMode( int $profileId ): bool {
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $row = $dbr->newSelectQueryBuilder()
            ->select( 'vr_id' )
            ->from( 'pcp_visibility_rules' )
            ->where( [
                'vr_profile_id' => $profileId,
                'vr_namespace'  => '*',
                'vr_rule_type'  => 'private',
                'vr_revoked'    => 0,
            ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        return (bool)$row;
    }

    // ===== Public helpers for the rule-management API =====

    /**
     * Get prof_id for a given user_id (0 if no profile exists).
     */
    public static function profileIdForUser( int $userId ): int {
        if ( $userId <= 0 ) return 0;
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $row = $dbr->newSelectQueryBuilder()
            ->select( 'prof_id' )
            ->from( 'pcp_user_profiles' )
            ->where( [ 'prof_user_id' => $userId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        return $row ? (int)$row->prof_id : 0;
    }

    /**
     * List rules at a (profile, namespace, key) scope, including revoked + expired,
     * for the owner's "manage who can see this" UI.
     */
    public static function listRulesForOwner( int $profileId, ?string $namespace = null, ?string $key = null ): array {
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $conds = [ 'vr_profile_id' => $profileId ];
        if ( $namespace !== null ) $conds['vr_namespace'] = $namespace;
        if ( $key !== null )       $conds['vr_key']       = $key;
        $rows = $dbr->newSelectQueryBuilder()
            ->select( '*' )
            ->from( 'pcp_visibility_rules' )
            ->where( $conds )
            ->orderBy( 'vr_id', 'DESC' )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $rows as $r ) $out[] = $r;
        return $out;
    }

    /**
     * Create a new visibility rule. Returns the new vr_id.
     */
    public static function createRule(
        int $profileId,
        string $namespace,
        ?string $key,
        string $ruleType,
        array $payload = [],
        ?string $label = null,
        ?string $expires = null,
        int $attribution = 1
    ): int {
        $dbw = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getPrimaryDatabase();
        $now = $dbw->timestamp();
        $dbw->newInsertQueryBuilder()
            ->insertInto( 'pcp_visibility_rules' )
            ->row( [
                'vr_profile_id'  => $profileId,
                'vr_namespace'   => $namespace,
                'vr_key'         => $key,
                'vr_rule_type'   => $ruleType,
                'vr_payload'     => json_encode( $payload ),
                'vr_attribution' => $attribution,
                'vr_expires'     => $expires,
                'vr_revoked'     => 0,
                'vr_label'       => $label,
                'vr_created'     => $now,
                'vr_updated'     => $now,
            ] )
            ->caller( __METHOD__ )
            ->execute();
        return (int)$dbw->insertId();
    }

    /**
     * Update an existing rule's editable fields. Returns true on success.
     * Does not change the rule type, profile, namespace, or key.
     */
    public static function updateRule(
        int $ruleId,
        ?array $payload = null,
        ?string $label = null,
        ?string $expires = null,
        ?int $attribution = null
    ): bool {
        $dbw = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getPrimaryDatabase();
        $set = [ 'vr_updated' => $dbw->timestamp() ];
        if ( $payload !== null )     $set['vr_payload']     = json_encode( $payload );
        if ( $label !== null )       $set['vr_label']       = $label;
        if ( $expires !== null )     $set['vr_expires']     = ( $expires === '' ? null : $expires );
        if ( $attribution !== null ) $set['vr_attribution'] = (int)$attribution;
        $dbw->newUpdateQueryBuilder()
            ->update( 'pcp_visibility_rules' )
            ->set( $set )
            ->where( [ 'vr_id' => $ruleId ] )
            ->caller( __METHOD__ )
            ->execute();
        return true;
    }

    /**
     * Mark a rule revoked. Does not delete the row (audit trail).
     */
    public static function revokeRule( int $ruleId ): bool {
        $dbw = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getPrimaryDatabase();
        $dbw->newUpdateQueryBuilder()
            ->update( 'pcp_visibility_rules' )
            ->set( [ 'vr_revoked' => 1, 'vr_updated' => $dbw->timestamp() ] )
            ->where( [ 'vr_id' => $ruleId ] )
            ->caller( __METHOD__ )
            ->execute();
        return true;
    }

    /**
     * Verify the named rule belongs to the named profile (authorization check
     * before letting a user modify/revoke a rule).
     */
    public static function ruleBelongsToProfile( int $ruleId, int $profileId ): bool {
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $row = $dbr->newSelectQueryBuilder()
            ->select( 'vr_id' )
            ->from( 'pcp_visibility_rules' )
            ->where( [ 'vr_id' => $ruleId, 'vr_profile_id' => $profileId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        return (bool)$row;
    }

    /**
     * Generate a cryptographically secure URL-safe link token.
     */
    public static function generateLinkToken( int $bytes = 24 ): string {
        return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
    }

    /**
     * Look up a link-token rule. Returns the rule row, or null.
     */
    public static function findLinkTokenRule( string $token ): ?\stdClass {
        if ( $token === '' ) return null;
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $now = $dbr->timestamp();
        $rows = $dbr->newSelectQueryBuilder()
            ->select( '*' )
            ->from( 'pcp_visibility_rules' )
            ->where( [
                'vr_rule_type' => 'link_token',
                'vr_revoked'   => 0,
            ] )
            ->andWhere( $dbr->expr( 'vr_expires', '=', null )->or( 'vr_expires', '>', $now ) )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        foreach ( $rows as $r ) {
            $payload = $r->vr_payload ? json_decode( (string)$r->vr_payload, true ) : [];
            if ( is_array( $payload ) && hash_equals( (string)( $payload['token'] ?? '' ), $token ) ) {
                return $r;
            }
        }
        return null;
    }

    // ===== Private helpers =====

    private static function ownerUserIdForProfile( int $profileId ): int {
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $row = $dbr->newSelectQueryBuilder()
            ->select( 'prof_user_id' )
            ->from( 'pcp_user_profiles' )
            ->where( [ 'prof_id' => $profileId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        return $row ? (int)$row->prof_user_id : 0;
    }

    /**
     * Return rules matching (profile, namespace, key) in most-specific-first order.
     * Specific (ns, key) rules first, then (ns, NULL) namespace-wide rules,
     * then ('*', NULL) profile-wide rules.
     */
    private static function matchingRules( int $profileId, string $namespace, ?string $key ): array {
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $now = $dbr->timestamp();
        $conds = [
            'vr_profile_id' => $profileId,
            'vr_revoked'    => 0,
            $dbr->expr( 'vr_expires', '=', null )->or( 'vr_expires', '>', $now ),
        ];
        // Match (ns, key) OR (ns, NULL) OR (*, NULL) — collect both, order by specificity later in PHP.
        $rows = $dbr->newSelectQueryBuilder()
            ->select( '*' )
            ->from( 'pcp_visibility_rules' )
            ->where( $conds )
            ->andWhere( $dbr->orExpr( [
                $dbr->andExpr( [
                    'vr_namespace' => $namespace,
                    'vr_key'       => $key,
                ] ),
                $dbr->andExpr( [
                    'vr_namespace' => $namespace,
                    'vr_key'       => null,
                ] ),
                $dbr->andExpr( [
                    'vr_namespace' => '*',
                    'vr_key'       => null,
                ] ),
            ] ) )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $rows as $r ) { $out[] = $r; }
        // Sort: specific key first, then ns-wide, then *-wide
        usort( $out, function ( $a, $b ) use ( $key, $namespace ) {
            $aSpec = ( (string)$a->vr_namespace === $namespace && (string)$a->vr_key === (string)$key ) ? 0
                    : ( (string)$a->vr_namespace === $namespace ? 1 : 2 );
            $bSpec = ( (string)$b->vr_namespace === $namespace && (string)$b->vr_key === (string)$key ) ? 0
                    : ( (string)$b->vr_namespace === $namespace ? 1 : 2 );
            return $aSpec <=> $bSpec;
        } );
        return $out;
    }

    private static function rulePermits( $rule, int $viewerUserId, ?string $linkToken ): bool {
        $type = (string)$rule->vr_rule_type;
        $payload = $rule->vr_payload ? json_decode( (string)$rule->vr_payload, true ) : [];
        if ( !is_array( $payload ) ) $payload = [];

        switch ( $type ) {
            case 'private':
                return false;
            case 'public':
                return true;
            case 'users':
                if ( $viewerUserId <= 0 ) return false;
                $ids = $payload['user_ids'] ?? [];
                return in_array( $viewerUserId, array_map( 'intval', $ids ), true );
            case 'cohort':
                if ( $viewerUserId <= 0 ) return false;
                $cohortId = (int)( $payload['cohort_id'] ?? 0 );
                if ( $cohortId <= 0 ) return false;
                return self::isCohortMember( $cohortId, $viewerUserId );
            case 'link_token':
                if ( $linkToken === null || $linkToken === '' ) return false;
                $expected = (string)( $payload['token'] ?? '' );
                if ( $expected === '' ) return false;
                if ( !hash_equals( $expected, $linkToken ) ) return false;
                // uses_remaining decrement is the caller's responsibility post-permit
                if ( isset( $payload['uses_remaining'] ) && (int)$payload['uses_remaining'] <= 0 ) {
                    return false;
                }
                return true;
            case 'reciprocal':
                if ( $viewerUserId <= 0 ) return false;
                return self::hasReciprocal( $rule, $viewerUserId, $payload );
            default:
                return false;
        }
    }

    private static function isCohortMember( int $cohortId, int $userId ): bool {
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $row = $dbr->newSelectQueryBuilder()
            ->select( '1' )
            ->from( 'pcp_cohort_members' )
            ->where( [ 'cm_cohort_id' => $cohortId, 'cm_user_id' => $userId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        return (bool)$row;
    }

    /**
     * Reciprocal: viewer V has also shared the same shape with the rule's owner.
     * "Same shape" = same (namespace, key) scope.
     */
    private static function hasReciprocal( $rule, int $viewerUserId, array $payload ): bool {
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        // Find viewer's profile id
        $vp = $dbr->newSelectQueryBuilder()
            ->select( 'prof_id' )
            ->from( 'pcp_user_profiles' )
            ->where( [ 'prof_user_id' => $viewerUserId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$vp ) return false;
        $viewerProfileId = (int)$vp->prof_id;
        // Owner user id from rule's profile
        $ownerUserId = self::ownerUserIdForProfile( (int)$rule->vr_profile_id );
        // Does viewer have a rule sharing (vr_namespace, vr_key) with owner?
        $ns  = $payload['ns']  ?? (string)$rule->vr_namespace;
        $key = $payload['key'] ?? ( $rule->vr_key === null ? null : (string)$rule->vr_key );
        $reciprocal = $dbr->newSelectQueryBuilder()
            ->select( '1' )
            ->from( 'pcp_visibility_rules' )
            ->where( [
                'vr_profile_id' => $viewerProfileId,
                'vr_namespace'  => $ns,
                'vr_key'        => $key,
                'vr_revoked'    => 0,
                'vr_rule_type'  => 'users',
            ] )
            ->andWhere( "JSON_CONTAINS(vr_payload, '" . (int)$ownerUserId . "', '$.user_ids')" )
            ->caller( __METHOD__ )
            ->fetchRow();
        return (bool)$reciprocal;
    }

    /**
     * Legacy fallback: use pf_visibility column as in pre-Phase-1 behavior.
     */
    private static function legacyFallback( int $profileId, string $namespace, ?string $key ): bool {
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $conds = [ 'pf_profile_id' => $profileId, 'pf_namespace' => $namespace ];
        if ( $key !== null ) {
            $conds['pf_key'] = $key;
        }
        $row = $dbr->newSelectQueryBuilder()
            ->select( 'MAX(pf_visibility) AS maxvis' )
            ->from( 'pcp_profile_fields' )
            ->where( $conds )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) return false;
        return (int)( $row->maxvis ?? 0 ) >= 1;
    }
}
