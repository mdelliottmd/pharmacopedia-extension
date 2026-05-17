<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;

/**
 * Read/write API for the user profile system (demographics, OCEAN, diagnoses,
 * manually-added meds). All per-user data is keyed by voter_hash, with a
 * parallel user_id column for sysop-only reverse lookup.
 *
 * Permission model:
 *   - User can read/write their OWN profile fully (private + public)
 *   - Other logged-in users see ONLY fields with visibility > 0 (public)
 *   - Sysops (pharmacopedia-profile-view-others-full) see EVERYTHING on any profile,
 *     including private fields, real user_id, and the linkage to voter_hash
 */
class UserProfileStore {

    // Visibility codes for pf_visibility / pd_visibility / um_visibility
    public const VIS_PRIVATE          = 0;
    public const VIS_PUBLIC_DEFAULT   = 1;  // attribute per prof_show_default
    public const VIS_PUBLIC_USERNAME  = 2;  // override → force real username
    public const VIS_PUBLIC_ANONYMOUS = 3;  // override → force anonymous

    // Profile-level attribution defaults
    public const SHOW_ANONYMOUS = 0;
    public const SHOW_ALIAS     = 1;
    public const SHOW_USERNAME  = 2;
    public const SHOW_ALWAYS_ANONYMOUS = 3;

    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }
    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    /** Compute voter hash for a user id (same algorithm as ElementStore). */
    public function voterHash( $userId ): string {
        global $wgPharmacopediaVoteHashSecret;
        if ( !$wgPharmacopediaVoteHashSecret ) {
            throw new \RuntimeException( '$wgPharmacopediaVoteHashSecret must be set' );
        }
        return hash_hmac( 'sha256', (string)$userId, $wgPharmacopediaVoteHashSecret );
    }

    /** Get the profile row for a user; create it if missing. */
    public function getOrCreateForUser( int $userId ): \stdClass {
        $hash = $this->voterHash( $userId );
        $row = $this->dbr()->selectRow( 'pcp_user_profiles', '*',
            [ 'prof_user_id' => $userId ], __METHOD__ );
        if ( $row ) return $row;

        $dbw = $this->dbw();
        $now = $dbw->timestamp();
        $dbw->insert( 'pcp_user_profiles', [
            'prof_voter_hash'   => $hash,
            'prof_user_id'      => $userId,
            'prof_show_default' => self::SHOW_ANONYMOUS,
            'prof_created'      => $now,
            'prof_updated'      => $now,
        ], __METHOD__ );
        return $dbw->selectRow( 'pcp_user_profiles', '*',
            [ 'prof_user_id' => $userId ], __METHOD__ );
    }

    /** Look up a profile by username. Used for Special:UserProfile/<name> view. */
    public function getByUsername( string $username ): ?\stdClass {
        $userFactory = MediaWikiServices::getInstance()->getUserFactory();
        $user = $userFactory->newFromName( $username );
        if ( !$user || !$user->isRegistered() ) return null;
        $row = $this->dbr()->selectRow( 'pcp_user_profiles', '*',
            [ 'prof_user_id' => $user->getId() ], __METHOD__ );
        return $row ?: null;
    }

    /** Update profile-level fields (alias, show_default, show_xr_on_profile). */
    public function updateProfileMeta( int $profileId, ?string $alias, int $showDefault, int $showXrOnProfile = 0 ) {
        $dbw = $this->dbw();
        $dbw->update( 'pcp_user_profiles', [
            'prof_public_alias'       => $alias,
            'prof_show_default'       => $showDefault,
            'prof_show_xr_on_profile' => $showXrOnProfile ? 1 : 0,
            'prof_updated'            => $dbw->timestamp(),
        ], [ 'prof_id' => $profileId ], __METHOD__ );
    }

    // ===== Generic fields =====

    /** Set a single namespaced field (upsert). */
    public function setField( int $profileId, string $namespace, string $key,
                              ?string $valueText, $valueNum, int $visibility ) {
        $dbw = $this->dbw();
        $existing = $dbw->selectRow( 'pcp_profile_fields', 'pf_id',
            [ 'pf_profile_id' => $profileId, 'pf_namespace' => $namespace, 'pf_key' => $key ],
            __METHOD__ );
        $row = [
            'pf_value_text' => $valueText,
            'pf_value_num'  => $valueNum,
            'pf_visibility' => $visibility,
            'pf_updated'    => $dbw->timestamp(),
        ];
        if ( $existing ) {
            $dbw->update( 'pcp_profile_fields', $row,
                [ 'pf_id' => $existing->pf_id ], __METHOD__ );
        } else {
            $dbw->insert( 'pcp_profile_fields', $row + [
                'pf_profile_id' => $profileId,
                'pf_namespace'  => $namespace,
                'pf_key'        => $key,
            ], __METHOD__ );
        }
    }

    /** Delete a single field. */
    public function deleteField( int $profileId, string $namespace, string $key ) {
        $this->dbw()->delete( 'pcp_profile_fields',
            [ 'pf_profile_id' => $profileId, 'pf_namespace' => $namespace, 'pf_key' => $key ],
            __METHOD__ );
    }

    /** All fields for a profile, optionally filtered by namespace and visibility floor. */
    public function getFields( int $profileId, ?string $namespace = null,
                                int $minVisibility = 0 ): array {
        $where = [ 'pf_profile_id' => $profileId ];
        if ( $namespace !== null ) $where['pf_namespace'] = $namespace;
        if ( $minVisibility > 0 ) $where[] = 'pf_visibility >= ' . (int)$minVisibility;
        $res = $this->dbr()->select( 'pcp_profile_fields', '*', $where, __METHOD__ );
        $out = [];
        foreach ( $res as $r ) { $out[] = $r; }
        return $out;
    }

    // ===== Diagnoses =====

    public function addDiagnosis( int $profileId, array $fields ): int {
        $dbw = $this->dbw();
        $dbw->insert( 'pcp_profile_diagnoses', [
            'pd_profile_id'  => $profileId,
            'pd_system'      => $fields['system']      ?? 'unofficial',
            'pd_code'        => $fields['code']        ?? null,
            'pd_description' => $fields['description'],
            'pd_status'      => $fields['status']      ?? null,
            'pd_origin'      => $fields['origin']      ?? null,
            'pd_severity'    => $fields['severity']    ?? null,
            'pd_disability'  => $fields['disability']  ?? null,
            'pd_year_first'  => $fields['year_first']  ?? null,
            'pd_date_struct'     => $fields['date_struct']     ?? null,
            'pd_date_struct_pro' => $fields['date_struct_pro'] ?? null,
            'pd_notes'       => $fields['notes']       ?? null,
            'pd_visibility'  => $fields['visibility']  ?? self::VIS_PRIVATE,
            'pd_added'       => $dbw->timestamp(),
        ], __METHOD__ );
        return (int)$dbw->insertId();
    }

    public function updateDiagnosis( int $diagnosisId, int $profileId, array $fields ) {
        $set = [];
        foreach ( [ 'system','code','description','status','origin','severity','disability',
                    'year_first','date_struct','date_struct_pro','notes','visibility' ] as $f ) {
            if ( array_key_exists( $f, $fields ) ) {
                $set[ 'pd_' . $f ] = $fields[ $f ];
            }
        }
        if ( !$set ) return;
        $this->dbw()->update( 'pcp_profile_diagnoses', $set,
            [ 'pd_id' => $diagnosisId, 'pd_profile_id' => $profileId ], __METHOD__ );
    }

    public function deleteDiagnosis( int $diagnosisId, int $profileId ) {
        $this->dbw()->delete( 'pcp_profile_diagnoses',
            [ 'pd_id' => $diagnosisId, 'pd_profile_id' => $profileId ], __METHOD__ );
    }

    public function getDiagnoses( int $profileId, int $minVisibility = 0 ): array {
        $where = [ 'pd_profile_id' => $profileId ];
        if ( $minVisibility > 0 ) $where[] = 'pd_visibility >= ' . (int)$minVisibility;
        $res = $this->dbr()->select( 'pcp_profile_diagnoses', '*', $where, __METHOD__,
            [ 'ORDER BY' => 'pd_added DESC' ] );
        $out = [];
        foreach ( $res as $r ) { $out[] = $r; }
        return $out;
    }

    // ===== Manually-added medicines =====

    public function addMed( int $profileId, array $fields ): int {
        $dbw = $this->dbw();
        $now = $dbw->timestamp();
        $dbw->insert( 'pcp_user_meds', [
            'um_profile_id'    => $profileId,
            'um_page_id'       => $fields['page_id']       ?? null,
            'um_med_name'      => $fields['med_name'],
            'um_efficacy'      => $fields['efficacy']      ?? null,
            'um_route'         => $fields['route']         ?? null,
            'um_schedule'      => $fields['schedule']      ?? null,
            'um_burden'        => $fields['burden']        ?? null,
            'um_duration_days' => $fields['duration_days'] ?? null,
            'um_start_struct'  => $fields['start_struct']  ?? null,
            'um_stop_struct'   => $fields['stop_struct']   ?? null,
            'um_periods'       => $fields['periods']       ?? null,
            'um_dose_mg'       => $fields['dose_mg']       ?? null,
            'um_current'       => $fields['current']       ?? null,
            'um_notes'         => $fields['notes']         ?? null,
            'um_visibility'    => $fields['visibility']    ?? self::VIS_PRIVATE,
            'um_added'         => $now,
            'um_updated'       => $now,
        ], __METHOD__ );
        return (int)$dbw->insertId();
    }

    public function updateMed( int $umId, int $profileId, array $fields ) {
        $set = [];
        foreach ( [ 'page_id','med_name','efficacy','burden','duration_days','start_struct','stop_struct','periods',
                    'dose_mg','current','notes','visibility' ,'route','schedule'] as $f ) {
            if ( array_key_exists( $f, $fields ) ) {
                $set[ 'um_' . $f ] = $fields[ $f ];
            }
        }
        if ( !$set ) return;
        $set[ 'um_updated' ] = $this->dbw()->timestamp();
        $this->dbw()->update( 'pcp_user_meds', $set,
            [ 'um_id' => $umId, 'um_profile_id' => $profileId ], __METHOD__ );
    }

    public function deleteMed( int $umId, int $profileId ) {
        $this->dbw()->delete( 'pcp_user_meds',
            [ 'um_id' => $umId, 'um_profile_id' => $profileId ], __METHOD__ );
    }

    public function getMeds( int $profileId, int $minVisibility = 0 ): array {
        $where = [ 'um_profile_id' => $profileId ];
        if ( $minVisibility > 0 ) $where[] = 'um_visibility >= ' . (int)$minVisibility;
        $res = $this->dbr()->select( 'pcp_user_meds', '*', $where, __METHOD__,
            [ 'ORDER BY' => 'um_added DESC' ] );
        $out = [];
        foreach ( $res as $r ) { $out[] = $r; }
        return $out;
    }

    // ===== Existing experience reports auto-pull =====

    /** Pull this user's already-submitted experience reports (across all medicine pages). */
    public function getExperienceReports( string $voterHash ): array {
        $dbr = $this->dbr();
        $res = $dbr->select(
            [ 'xr' => 'pcp_experience_reports', 'p' => 'page' ],
            [ 'xr.*', 'p.page_title' ],
            [ 'xr.xr_voter_hash' => $voterHash ],
            __METHOD__,
            [ 'ORDER BY' => 'xr.xr_updated DESC' ],
            [ 'p' => [ 'LEFT JOIN', 'xr.xr_page_id = p.page_id' ] ]
        );
        $out = [];
        foreach ( $res as $r ) { $out[] = $r; }
        return $out;
    }

    // ===== Abbreviation search =====

    /**
     * Match abbreviations and canonical names. Case-insensitive substring,
     * with per-word AND semantics: "ADHD inattentive" matches any row whose
     * token-or-canonical contains both "ADHD" and "inattentive" in any order.
     */
    public function searchAbbreviations( string $query, int $limit = 20 ): array {
        $q = trim( $query );
        if ( $q === '' ) return [];
        $dbr = $this->dbr();
        $tokens = preg_split( '/\\s+/', $q, -1, PREG_SPLIT_NO_EMPTY );
        $tokens = array_slice( $tokens, 0, 8 );
        $conds = [];
        foreach ( $tokens as $tok ) {
            $like = $dbr->buildLike( $dbr->anyString(), strtolower( $tok ), $dbr->anyString() );
            $conds[] = "(LOWER(da_token) $like OR LOWER(da_canonical) $like)";
        }
        $where = implode( ' AND ', $conds );
        $res = $dbr->select( 'pcp_diagnosis_abbreviations',
            [ 'da_token','da_system','da_code','da_canonical' ],
            $where,
            __METHOD__,
            [
                'LIMIT' => $limit,
                'ORDER BY' => "FIELD(da_system, 'ICD-10-CM', 'ICD-10-CM-Index', 'ICD-11', 'DSM-5', 'somatic', 'unofficial', 'other'), LENGTH(da_canonical) ASC"
            ]
        );
        $out = [];
        foreach ( $res as $r ) { $out[] = $r; }
        return $out;
    }

    // ===== Permission helper =====

    /**
     * Can $viewer see private (visibility=0) data on profile $targetProfile?
     * True if: viewer is the profile owner, OR viewer has the sysop permission.
     */
    public function canViewPrivate( User $viewer, \stdClass $targetProfile ): bool {
        if ( !$viewer || !$viewer->isRegistered() ) return false;
        if ( (int)$targetProfile->prof_user_id === $viewer->getId() ) return true;
        return $viewer->isAllowed( 'pharmacopedia-profile-view-others-full' );
    }

    /** Resolve the "name to show publicly" for a profile, given a visibility code. */
    public function publicDisplayName( \stdClass $prof, int $fieldVisibility ): string {
        if ( $fieldVisibility === self::VIS_PUBLIC_ANONYMOUS ) return 'Anonymous';
        if ( $fieldVisibility === self::VIS_PUBLIC_USERNAME ) {
            $user = MediaWikiServices::getInstance()->getUserFactory()
                ->newFromId( (int)$prof->prof_user_id );
            return $user ? $user->getName() : 'Anonymous';
        }
        // Field uses profile default
        $default = (int)$prof->prof_show_default;
        if ( $default === self::SHOW_ANONYMOUS || $default === self::SHOW_ALWAYS_ANONYMOUS ) {
            return 'Anonymous';
        }
        if ( $default === self::SHOW_ALIAS ) {
            return !empty( $prof->prof_public_alias )
                ? (string)$prof->prof_public_alias
                : 'Anonymous';
        }
        if ( $default === self::SHOW_USERNAME ) {
            $user = MediaWikiServices::getInstance()->getUserFactory()
                ->newFromId( (int)$prof->prof_user_id );
            return $user ? $user->getName() : 'Anonymous';
        }
        return 'Anonymous';
    }
}
