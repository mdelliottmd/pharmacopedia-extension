<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use ApiBase;
use MediaWiki\Extension\Pharmacopedia\VisibilityResolver;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Visibility rule CRUD for the rule-management UI.
 *
 * All operations act on the CURRENT USER's profile (you can only manage
 * your own share rules). Rule types: users, public, private, link_token,
 * cohort, reciprocal.
 *
 * op=list      -> list rules at (namespace, key) scope (omit key for ns-wide)
 * op=create    -> create a new rule
 * op=update    -> update an existing rule's payload/label/expires
 * op=revoke    -> revoke a rule
 * op=newtoken  -> create a link_token rule + return its token
 */
class VisibilityRulesApi extends ApiBase {

    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( 'pharmacopedia-login-required', 'notloggedin' );
        }
        $profileId = VisibilityResolver::profileIdForUser( $user->getId() );
        if ( $profileId <= 0 ) {
            $this->dieWithError( 'pharmacopedia-no-profile', 'noprofile' );
        }

        $params = $this->extractRequestParams();
        $op = $params['op'];

        switch ( $op ) {
            case 'list':
                $rules = VisibilityResolver::listRulesForOwner(
                    $profileId,
                    $params['namespace'] ?: null,
                    $params['key'] ?: null
                );
                $out = [];
                foreach ( $rules as $r ) {
                    $out[] = $this->serializeRule( $r );
                }
                $this->getResult()->addValue( null, 'rules', $out );
                break;

            case 'create':
                $this->requirePostedAndToken();
                $payload = $this->decodePayload( $params['payload'] );
                $ruleId = VisibilityResolver::createRule(
                    $profileId,
                    (string)$params['namespace'],
                    $params['key'] ?: null,
                    (string)$params['type'],
                    $payload,
                    $params['label'] ?: null,
                    $params['expires'] ?: null,
                    (int)$params['attribution']
                );
                $this->getResult()->addValue( null, 'rule_id', $ruleId );
                $this->getResult()->addValue( null, 'success', true );
                break;

            case 'update':
                $this->requirePostedAndToken();
                $ruleId = (int)$params['rule_id'];
                if ( !VisibilityResolver::ruleBelongsToProfile( $ruleId, $profileId ) ) {
                    $this->dieWithError( 'pharmacopedia-permission-denied', 'notowner' );
                }
                $payload = $params['payload'] !== '' ? $this->decodePayload( $params['payload'] ) : null;
                VisibilityResolver::updateRule(
                    $ruleId,
                    $payload,
                    $params['label'] !== '' ? $params['label'] : null,
                    $params['expires'] !== '' ? $params['expires'] : null,
                    $params['attribution'] !== '' ? (int)$params['attribution'] : null
                );
                $this->getResult()->addValue( null, 'success', true );
                break;

            case 'revoke':
                $this->requirePostedAndToken();
                $ruleId = (int)$params['rule_id'];
                if ( !VisibilityResolver::ruleBelongsToProfile( $ruleId, $profileId ) ) {
                    $this->dieWithError( 'pharmacopedia-permission-denied', 'notowner' );
                }
                VisibilityResolver::revokeRule( $ruleId );
                $this->getResult()->addValue( null, 'success', true );
                break;

            case 'newtoken':
                $this->requirePostedAndToken();
                $token = VisibilityResolver::generateLinkToken( 24 );
                $payload = [ 'token' => $token ];
                if ( $params['max_uses'] !== '' && (int)$params['max_uses'] > 0 ) {
                    $payload['uses_remaining'] = (int)$params['max_uses'];
                    $payload['max_uses']       = (int)$params['max_uses'];
                }
                $ruleId = VisibilityResolver::createRule(
                    $profileId,
                    (string)$params['namespace'],
                    $params['key'] ?: null,
                    'link_token',
                    $payload,
                    $params['label'] ?: null,
                    $params['expires'] ?: null,
                    (int)$params['attribution']
                );
                $this->getResult()->addValue( null, 'rule_id', $ruleId );
                $this->getResult()->addValue( null, 'token', $token );
                $this->getResult()->addValue( null, 'success', true );
                break;

            default:
                $this->dieWithError( 'pharmacopedia-bad-op', 'badop' );
        }
    }

    private function requirePostedAndToken(): void {
        if ( !$this->getRequest()->wasPosted() ) {
            $this->dieWithError( 'apierror-mustbeposted', 'mustbeposted' );
        }
        $token = (string)$this->getRequest()->getVal( 'token', '' );
        if ( $token === '' || !$this->getUser()->matchEditToken( $token ) ) {
            $this->dieWithError( 'apierror-badtoken', 'badtoken' );
        }
    }

    private function decodePayload( string $raw ): array {
        if ( $raw === '' ) return [];
        $d = json_decode( $raw, true );
        if ( !is_array( $d ) ) {
            $this->dieWithError( 'pharmacopedia-bad-payload', 'badpayload' );
        }
        return $d;
    }

    private function serializeRule( $r ): array {
        $payload = $r->vr_payload ? json_decode( (string)$r->vr_payload, true ) : [];
        if ( !is_array( $payload ) ) $payload = [];
        // For users-type rules, hydrate usernames for display
        if ( ( (string)$r->vr_rule_type ) === 'users' && !empty( $payload['user_ids'] ) ) {
            $payload['usernames'] = $this->lookupUsernames( $payload['user_ids'] );
        }
        // For cohort, hydrate cohort name
        if ( ( (string)$r->vr_rule_type ) === 'cohort' && !empty( $payload['cohort_id'] ) ) {
            $payload['cohort_name'] = $this->lookupCohortName( (int)$payload['cohort_id'] );
        }
        return [
            'id'          => (int)$r->vr_id,
            'namespace'   => (string)$r->vr_namespace,
            'key'         => $r->vr_key !== null ? (string)$r->vr_key : null,
            'type'        => (string)$r->vr_rule_type,
            'payload'     => $payload,
            'attribution' => (int)$r->vr_attribution,
            'expires'     => $r->vr_expires !== null ? (string)$r->vr_expires : null,
            'revoked'     => (int)$r->vr_revoked,
            'label'       => $r->vr_label !== null ? (string)$r->vr_label : null,
            'created'     => (string)$r->vr_created,
            'updated'     => (string)$r->vr_updated,
        ];
    }

    private function lookupUsernames( array $userIds ): array {
        if ( !$userIds ) return [];
        $ids = array_map( 'intval', $userIds );
        $dbr = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $rows = $dbr->newSelectQueryBuilder()
            ->select( [ 'user_id', 'user_name' ] )
            ->from( 'user' )
            ->where( [ 'user_id' => $ids ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [ "id" => (int)$r->user_id, "name" => (string)$r->user_name ];
        }
        return $out;
    }

    private function lookupCohortName( int $cohortId ): ?string {
        $dbr = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $row = $dbr->newSelectQueryBuilder()
            ->select( 'co_name' )
            ->from( 'pcp_cohorts' )
            ->where( [ 'co_id' => $cohortId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        return $row ? (string)$row->co_name : null;
    }

    public function getAllowedParams() {
        return [
            'op'          => [ ParamValidator::PARAM_TYPE => [ 'list', 'create', 'update', 'revoke', 'newtoken' ], ParamValidator::PARAM_REQUIRED => true ],
            'namespace'   => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
            'key'         => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
            'type'        => [ ParamValidator::PARAM_TYPE => [ 'users', 'public', 'private', 'link_token', 'cohort', 'reciprocal' ], ParamValidator::PARAM_DEFAULT => 'users' ],
            'payload'     => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
            'label'       => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
            'expires'     => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
            'attribution' => [ ParamValidator::PARAM_TYPE => 'integer', ParamValidator::PARAM_DEFAULT => 1 ],
            'rule_id'     => [ ParamValidator::PARAM_TYPE => 'integer', ParamValidator::PARAM_DEFAULT => 0 ],
            'max_uses'    => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
        ];
    }

    public function isWriteMode() {
        $op = $this->getRequest()->getVal( 'op', 'list' );
        return in_array( $op, [ 'create', 'update', 'revoke', 'newtoken' ], true );
    }
    public function mustBePosted() {
        $op = $this->getRequest()->getVal( 'op', 'list' );
        return in_array( $op, [ 'create', 'update', 'revoke', 'newtoken' ], true );
    }
}
