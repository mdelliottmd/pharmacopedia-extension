<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use ApiBase;
use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\MediaWikiServices;

/**
 * Find + upgrade free-text refs in observations/events to structured refs.
 *
 *   op=candidates  GET  — returns unmatched free-text refs that now match an
 *                        existing entity (user's meds, Category:Medicines wiki
 *                        pages, global effects/problems, user's diagnoses,
 *                        ICD abbreviations).
 *   op=apply       POST — set ler_ref_type + ler_ref_id on a specific ref row.
 *                        Required: ref_id, new_type, new_ref_id.
 *   op=dismiss     POST — mark a ref as "intentionally free-text" by appending
 *                        a marker to ler_role (e.g. role becomes 'cause:dismissed').
 *                        Cheap way to stop seeing it suggested.
 */
class RefUpgradeApi extends ApiBase {

    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( 'pharmacopedia-login-required', 'notloggedin' );
        }
        $userId = (int)$user->getId();
        $params = $this->extractRequestParams();
        $op = $params['op'];

        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        // Get caller's profile id (we only operate on their own refs).
        $prof = $dbr->newSelectQueryBuilder()
            ->select( 'prof_id' )
            ->from( 'pcp_user_profiles' )
            ->where( [ 'prof_user_id' => $userId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$prof ) {
            $this->dieWithError( 'pharmacopedia-no-profile', 'noprofile' );
        }
        $profileId = (int)$prof->prof_id;

        switch ( $op ) {
            case 'candidates':
                $candidates = $this->findCandidates( $profileId );
                $this->getResult()->addValue( null, 'candidates', $candidates );
                $this->getResult()->addValue( null, 'count', count( $candidates ) );
                break;

            case 'apply':
                $this->requirePost( $user );
                $refId = (int)$params['ref_id'];
                $newType = (string)$params['new_type'];
                $newRefId = (int)$params['new_ref_id'];
                if ( $refId <= 0 || $newType === '' || $newRefId <= 0 ) {
                    $this->dieWithError( 'pharmacopedia-bad-payload', 'badpayload' );
                }
                $this->applyUpgrade( $profileId, $refId, $newType, $newRefId );
                $this->getResult()->addValue( null, 'success', true );
                break;

            case 'dismiss':
                $this->requirePost( $user );
                $refId = (int)$params['ref_id'];
                if ( $refId <= 0 ) {
                    $this->dieWithError( 'pharmacopedia-bad-payload', 'badpayload' );
                }
                $this->dismissRef( $profileId, $refId );
                $this->getResult()->addValue( null, 'success', true );
                break;

            default:
                $this->dieWithError( 'pharmacopedia-bad-op', 'badop' );
        }
    }

    private function requirePost( $user ): void {
        if ( !$this->getRequest()->wasPosted() ) {
            $this->dieWithError( 'apierror-mustbeposted', 'mustbeposted' );
        }
        $token = (string)$this->getRequest()->getVal( 'token', '' );
        if ( $token === '' || !$user->matchEditToken( $token ) ) {
            $this->dieWithError( 'apierror-badtoken', 'badtoken' );
        }
    }

    /**
     * Walk all 'free'-typed refs for this profile's events, group by text,
     * and look up plausible matches in each catalog. Skip dismissed roles.
     */
    private function findCandidates( int $profileId ): array {
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        // All free-text refs on events owned by this profile, not dismissed.
        $rows = $dbr->newSelectQueryBuilder()
            ->select( [ 'r.ler_id', 'r.ler_event_id', 'r.ler_ref_text', 'r.ler_role' ] )
            ->from( 'pcp_life_event_refs', 'r' )
            ->join( 'pcp_life_events', 'e', 'e.le_id = r.ler_event_id' )
            ->where( [
                'e.le_profile_id' => $profileId,
                'r.ler_ref_type'  => 'free',
            ] )
            ->andWhere( 'r.ler_role NOT LIKE ' . $dbr->addQuotes( '%:dismissed' ) )
            ->caller( __METHOD__ )
            ->fetchResultSet();

        // Group by lowercased ref_text.
        $byText = [];
        foreach ( $rows as $r ) {
            $key = strtolower( trim( (string)$r->ler_ref_text ) );
            if ( $key === '' ) continue;
            $byText[$key]['text']      = (string)$r->ler_ref_text;
            $byText[$key]['ref_ids'][] = (int)$r->ler_id;
            $byText[$key]['event_ids'][] = (int)$r->ler_event_id;
        }

        $out = [];
        foreach ( $byText as $key => $info ) {
            $matches = $this->lookupMatches( $key, $profileId );
            if ( !$matches ) continue;
            $out[] = [
                'text'      => $info['text'],
                'ref_ids'   => array_values( array_unique( $info['ref_ids'] ) ),
                'event_ids' => array_values( array_unique( $info['event_ids'] ) ),
                'matches'   => $matches,
            ];
        }
        return $out;
    }

    /**
     * Mirror of ObservationParser::resolveSingleRef logic; returns up to 5
     * candidate matches across all catalogs.
     */
    private function lookupMatches( string $lower, int $profileId ): array {
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $out = [];

        // user's meds
        $rows = $dbr->newSelectQueryBuilder()
            ->select( [ 'um_id', 'um_med_name' ] )
            ->from( 'pcp_user_meds' )
            ->where( [ 'um_profile_id' => $profileId ] )
            ->andWhere( 'LOWER(CONVERT(um_med_name USING utf8mb4)) LIKE ' . $dbr->addQuotes( '%' . $lower . '%' ) )
            ->limit( 3 )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        foreach ( $rows as $r ) {
            $out[] = [ 'type' => 'med', 'id' => (int)$r->um_id, 'label' => (string)$r->um_med_name ];
        }

        // wiki Medicines pages
        $rows = $dbr->newSelectQueryBuilder()
            ->select( [ 'p.page_id', 'p.page_title' ] )
            ->from( 'page', 'p' )
            ->join( 'categorylinks', 'cl', 'cl.cl_from = p.page_id' )
            ->join( 'linktarget', 'lt', 'lt.lt_id = cl.cl_target_id' )
            ->where( [
                'lt.lt_namespace'    => 14,
                'lt.lt_title'        => 'Medicines',
                'p.page_namespace'   => 0,
                'p.page_is_redirect' => 0,
            ] )
            ->andWhere( "LOWER(CONVERT(p.page_title USING utf8mb4)) LIKE " . $dbr->addQuotes( '%' . str_replace( ' ', '_', $lower ) . '%' ) )
            ->limit( 3 )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        foreach ( $rows as $r ) {
            $out[] = [ 'type' => 'med_page', 'id' => (int)$r->page_id, 'label' => str_replace( '_', ' ', (string)$r->page_title ) ];
        }

        // effects
        $rows = $dbr->newSelectQueryBuilder()
            ->select( [ 'e_id', 'e_name' ] )
            ->from( 'pcp_effects' )
            ->where( "LOWER(CONVERT(e_name USING utf8mb4)) LIKE " . $dbr->addQuotes( '%' . $lower . '%' ) )
            ->limit( 3 )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        foreach ( $rows as $r ) {
            $out[] = [ 'type' => 'effect', 'id' => (int)$r->e_id, 'label' => (string)$r->e_name ];
        }

        // problems
        $rows = $dbr->newSelectQueryBuilder()
            ->select( [ 'p_id', 'p_name' ] )
            ->from( 'pcp_problem' )
            ->where( "LOWER(CONVERT(p_name USING utf8mb4)) LIKE " . $dbr->addQuotes( '%' . $lower . '%' ) )
            ->limit( 3 )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        foreach ( $rows as $r ) {
            $out[] = [ 'type' => 'problem', 'id' => (int)$r->p_id, 'label' => (string)$r->p_name ];
        }

        // user's diagnoses
        $rows = $dbr->newSelectQueryBuilder()
            ->select( [ 'pd_id', 'pd_description' ] )
            ->from( 'pcp_profile_diagnoses' )
            ->where( [ 'pd_profile_id' => $profileId ] )
            ->andWhere( "LOWER(CONVERT(pd_description USING utf8mb4)) LIKE " . $dbr->addQuotes( '%' . $lower . '%' ) )
            ->limit( 3 )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        foreach ( $rows as $r ) {
            $out[] = [ 'type' => 'diagnosis', 'id' => (int)$r->pd_id, 'label' => (string)$r->pd_description ];
        }

        return array_slice( $out, 0, 5 );
    }

    private function applyUpgrade( int $profileId, int $refId, string $newType, int $newRefId ): void {
        $dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
        // Verify ownership: the ref must belong to an event owned by $profileId.
        $row = $dbw->newSelectQueryBuilder()
            ->select( 'r.ler_id' )
            ->from( 'pcp_life_event_refs', 'r' )
            ->join( 'pcp_life_events', 'e', 'e.le_id = r.ler_event_id' )
            ->where( [ 'r.ler_id' => $refId, 'e.le_profile_id' => $profileId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) {
            $this->dieWithError( 'pharmacopedia-permission-denied', 'notowner' );
        }
        $dbw->newUpdateQueryBuilder()
            ->update( 'pcp_life_event_refs' )
            ->set( [
                'ler_ref_type' => $newType,
                'ler_ref_id'   => $newRefId,
            ] )
            ->where( [ 'ler_id' => $refId ] )
            ->caller( __METHOD__ )
            ->execute();
    }

    private function dismissRef( int $profileId, int $refId ): void {
        $dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
        $row = $dbw->newSelectQueryBuilder()
            ->select( [ 'r.ler_id', 'r.ler_role' ] )
            ->from( 'pcp_life_event_refs', 'r' )
            ->join( 'pcp_life_events', 'e', 'e.le_id = r.ler_event_id' )
            ->where( [ 'r.ler_id' => $refId, 'e.le_profile_id' => $profileId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) {
            $this->dieWithError( 'pharmacopedia-permission-denied', 'notowner' );
        }
        $newRole = rtrim( (string)$row->ler_role, ':dismissed' ) . ':dismissed';
        $dbw->newUpdateQueryBuilder()
            ->update( 'pcp_life_event_refs' )
            ->set( [ 'ler_role' => $newRole ] )
            ->where( [ 'ler_id' => $refId ] )
            ->caller( __METHOD__ )
            ->execute();
    }

    public function getAllowedParams() {
        return [
            'op'         => [ ParamValidator::PARAM_TYPE => [ 'candidates', 'apply', 'dismiss' ], ParamValidator::PARAM_REQUIRED => true ],
            'ref_id'     => [ ParamValidator::PARAM_TYPE => 'integer', ParamValidator::PARAM_DEFAULT => 0 ],
            'new_type'   => [ ParamValidator::PARAM_TYPE => 'string',  ParamValidator::PARAM_DEFAULT => '' ],
            'new_ref_id' => [ ParamValidator::PARAM_TYPE => 'integer', ParamValidator::PARAM_DEFAULT => 0 ],
        ];
    }

    public function isWriteMode() {
        $op = $this->getRequest()->getVal( 'op', 'candidates' );
        return in_array( $op, [ 'apply', 'dismiss' ], true );
    }

    public function mustBePosted() {
        $op = $this->getRequest()->getVal( 'op', 'candidates' );
        return in_array( $op, [ 'apply', 'dismiss' ], true );
    }
}
