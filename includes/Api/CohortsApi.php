<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use ApiBase;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Cohort CRUD + membership management for share-with-cohort flows.
 *
 * All ops act on the CURRENT USER's owned cohorts (you can only manage your own).
 *
 * op=list         -> list current user's cohorts
 * op=create       -> create cohort (name, description)
 * op=update       -> update cohort (cohort_id, name, description)
 * op=delete       -> delete cohort + members
 * op=members      -> list members of one cohort
 * op=addmember    -> add user to cohort (cohort_id, username)
 * op=removemember -> remove user from cohort (cohort_id, user_id)
 */
class CohortsApi extends ApiBase {

    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( 'pharmacopedia-login-required', 'notloggedin' );
        }
        $ownerId = (int)$user->getId();
        $params = $this->extractRequestParams();
        $op = $params['op'];
        $dbProvider = \MediaWiki\MediaWikiServices::getInstance()->getConnectionProvider();
        $dbr = $dbProvider->getReplicaDatabase();
        $dbw = $dbProvider->getPrimaryDatabase();

        switch ( $op ) {
            case 'list':
                $rows = $dbr->newSelectQueryBuilder()
                    ->select( [ 'co_id', 'co_name', 'co_description', 'co_created', 'co_updated' ] )
                    ->from( 'pcp_cohorts' )
                    ->where( [ 'co_owner_id' => $ownerId ] )
                    ->orderBy( 'co_name' )
                    ->caller( __METHOD__ )
                    ->fetchResultSet();
                $out = [];
                foreach ( $rows as $r ) {
                    $cid = (int)$r->co_id;
                    $cnt = $dbr->newSelectQueryBuilder()
                        ->select( 'COUNT(*) AS n' )
                        ->from( 'pcp_cohort_members' )
                        ->where( [ 'cm_cohort_id' => $cid ] )
                        ->caller( __METHOD__ )
                        ->fetchRow();
                    $out[] = [
                        'id'           => $cid,
                        'name'         => (string)$r->co_name,
                        'description'  => $r->co_description !== null ? (string)$r->co_description : '',
                        'member_count' => (int)( $cnt->n ?? 0 ),
                        'created'      => (string)$r->co_created,
                        'updated'      => (string)$r->co_updated,
                    ];
                }
                $this->getResult()->addValue( null, 'cohorts', $out );
                break;

            case 'create':
                $this->requirePosted();
                $name = trim( (string)$params['name'] );
                if ( $name === '' ) $this->dieWithError( 'pharmacopedia-cohort-name-required', 'noname' );
                $now = $dbw->timestamp();
                $dbw->newInsertQueryBuilder()
                    ->insertInto( 'pcp_cohorts' )
                    ->row( [
                        'co_owner_id'    => $ownerId,
                        'co_name'        => $name,
                        'co_description' => $params['description'] ?: null,
                        'co_created'     => $now,
                        'co_updated'     => $now,
                    ] )
                    ->caller( __METHOD__ )
                    ->execute();
                $this->getResult()->addValue( null, 'cohort_id', (int)$dbw->insertId() );
                $this->getResult()->addValue( null, 'success', true );
                break;

            case 'update':
                $this->requirePosted();
                $cid = (int)$params['cohort_id'];
                $this->assertOwnsCohort( $dbr, $cid, $ownerId );
                $set = [ 'co_updated' => $dbw->timestamp() ];
                if ( $params['name'] !== '' )        $set['co_name']        = $params['name'];
                if ( $params['description'] !== '' ) $set['co_description'] = $params['description'];
                $dbw->newUpdateQueryBuilder()
                    ->update( 'pcp_cohorts' )
                    ->set( $set )
                    ->where( [ 'co_id' => $cid, 'co_owner_id' => $ownerId ] )
                    ->caller( __METHOD__ )
                    ->execute();
                $this->getResult()->addValue( null, 'success', true );
                break;

            case 'delete':
                $this->requirePosted();
                $cid = (int)$params['cohort_id'];
                $this->assertOwnsCohort( $dbr, $cid, $ownerId );
                $dbw->newDeleteQueryBuilder()
                    ->deleteFrom( 'pcp_cohort_members' )
                    ->where( [ 'cm_cohort_id' => $cid ] )
                    ->caller( __METHOD__ )
                    ->execute();
                $dbw->newDeleteQueryBuilder()
                    ->deleteFrom( 'pcp_cohorts' )
                    ->where( [ 'co_id' => $cid, 'co_owner_id' => $ownerId ] )
                    ->caller( __METHOD__ )
                    ->execute();
                $this->getResult()->addValue( null, 'success', true );
                break;

            case 'members':
                $cid = (int)$params['cohort_id'];
                $this->assertOwnsCohort( $dbr, $cid, $ownerId );
                $rows = $dbr->newSelectQueryBuilder()
                    ->select( [ 'cm.cm_user_id', 'cm.cm_joined', 'u.user_name' ] )
                    ->from( 'pcp_cohort_members', 'cm' )
                    ->leftJoin( 'user', 'u', 'u.user_id = cm.cm_user_id' )
                    ->where( [ 'cm.cm_cohort_id' => $cid ] )
                    ->orderBy( 'u.user_name' )
                    ->caller( __METHOD__ )
                    ->fetchResultSet();
                $out = [];
                foreach ( $rows as $r ) {
                    $out[] = [
                        'user_id' => (int)$r->cm_user_id,
                        'name'    => (string)( $r->user_name ?? '(deleted)' ),
                        'joined'  => (string)$r->cm_joined,
                    ];
                }
                $this->getResult()->addValue( null, 'members', $out );
                break;

            case 'addmember':
                $this->requirePosted();
                $cid = (int)$params['cohort_id'];
                $this->assertOwnsCohort( $dbr, $cid, $ownerId );
                $uid = $this->resolveUserId( $dbr, (string)$params['username'] );
                if ( $uid <= 0 ) $this->dieWithError( 'pharmacopedia-user-not-found', 'nouser' );
                $dbw->newInsertQueryBuilder()
                    ->insertInto( 'pcp_cohort_members' )
                    ->ignore()
                    ->row( [
                        'cm_cohort_id' => $cid,
                        'cm_user_id'   => $uid,
                        'cm_joined'    => $dbw->timestamp(),
                    ] )
                    ->caller( __METHOD__ )
                    ->execute();
                $this->getResult()->addValue( null, 'user_id', $uid );
                $this->getResult()->addValue( null, 'success', true );
                break;

            case 'removemember':
                $this->requirePosted();
                $cid = (int)$params['cohort_id'];
                $this->assertOwnsCohort( $dbr, $cid, $ownerId );
                $uid = (int)$params['user_id'];
                $dbw->newDeleteQueryBuilder()
                    ->deleteFrom( 'pcp_cohort_members' )
                    ->where( [ 'cm_cohort_id' => $cid, 'cm_user_id' => $uid ] )
                    ->caller( __METHOD__ )
                    ->execute();
                $this->getResult()->addValue( null, 'success', true );
                break;

            default:
                $this->dieWithError( 'pharmacopedia-bad-op', 'badop' );
        }
    }

    private function requirePosted(): void {
        if ( !$this->getRequest()->wasPosted() ) {
            $this->dieWithError( 'apierror-mustbeposted', 'mustbeposted' );
        }
        $token = (string)$this->getRequest()->getVal( 'token', '' );
        if ( $token === '' || !$this->getUser()->matchEditToken( $token ) ) {
            $this->dieWithError( 'apierror-badtoken', 'badtoken' );
        }
    }

    private function assertOwnsCohort( $dbr, int $cohortId, int $ownerId ): void {
        $row = $dbr->newSelectQueryBuilder()
            ->select( 'co_id' )
            ->from( 'pcp_cohorts' )
            ->where( [ 'co_id' => $cohortId, 'co_owner_id' => $ownerId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) $this->dieWithError( 'pharmacopedia-permission-denied', 'notowner' );
    }

    private function resolveUserId( $dbr, string $username ): int {
        $row = $dbr->newSelectQueryBuilder()
            ->select( 'user_id' )
            ->from( 'user' )
            ->where( [ 'user_name' => $username ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        return $row ? (int)$row->user_id : 0;
    }

    public function getAllowedParams() {
        return [
            'op'          => [ ParamValidator::PARAM_TYPE => [ 'list', 'create', 'update', 'delete', 'members', 'addmember', 'removemember' ], ParamValidator::PARAM_REQUIRED => true ],
            'cohort_id'   => [ ParamValidator::PARAM_TYPE => 'integer', ParamValidator::PARAM_DEFAULT => 0 ],
            'name'        => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
            'description' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
            'username'    => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
            'user_id'     => [ ParamValidator::PARAM_TYPE => 'integer', ParamValidator::PARAM_DEFAULT => 0 ],
        ];
    }

    public function isWriteMode() {
        $op = $this->getRequest()->getVal( 'op', 'list' );
        return in_array( $op, [ 'create', 'update', 'delete', 'addmember', 'removemember' ], true );
    }
    public function mustBePosted() {
        $op = $this->getRequest()->getVal( 'op', 'list' );
        return in_array( $op, [ 'create', 'update', 'delete', 'addmember', 'removemember' ], true );
    }
}
