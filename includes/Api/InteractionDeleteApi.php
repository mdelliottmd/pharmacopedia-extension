<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\Pharmacopedia\InteractionStore;
use MediaWiki\MediaWikiServices;

/**
 * POST pharmacopediainteractiondelete
 *   op = 'interaction'              params: element_id
 *      = 'note'                     params: element_id, target_user_id, perspective
 *
 * 'interaction' requires the user be a sysop or admin.
 * 'note' allows sysop/admin OR the original note author.
 */
class InteractionDeleteApi extends ApiBase {
    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( [ 'apierror-mustbeloggedin', 'delete' ], 'notloggedin' );
        }
        $params = $this->extractRequestParams();
        $op = (string)$params['op'];
        $elementId = (int)$params['element_id'];

        $store = new InteractionStore();
        $ix = $store->getByElementId( $elementId );
        if ( !$ix ) {
            $this->dieWithError( [ 'rawmessage', 'Interaction not found.' ], 'notfound' );
        }

        $groups = MediaWikiServices::getInstance()->getUserGroupManager()->getUserGroups( $user );
        $isMod = in_array( 'sysop', $groups, true ) || in_array( 'admin', $groups, true );

        if ( $op === 'interaction' ) {
            if ( !$isMod ) {
                $this->dieWithError( [ 'apierror-permissiondenied', 'delete an interaction' ], 'permissiondenied' );
            }
            $store->deleteInteraction( $elementId );
            $this->getResult()->addValue( null, 'pharmacopediainteractiondelete', [
                'ok' => 1, 'op' => 'interaction', 'element_id' => $elementId,
            ] );
            return;
        }

        if ( $op === 'note' ) {
            $targetUserId = (int)( $params['target_user_id'] ?? 0 );
            $perspective  = (int)( $params['perspective']    ?? 0 );
            if ( !$targetUserId || ( $perspective !== 1 && $perspective !== 2 ) ) {
                $this->dieWithError( [ 'rawmessage', 'Missing or invalid note identity.' ], 'badvalue' );
            }
            $isAuthor = ( $targetUserId === (int)$user->getId() );
            if ( !$isMod && !$isAuthor ) {
                $this->dieWithError( [ 'apierror-permissiondenied', 'delete this note' ], 'permissiondenied' );
            }
            $store->clearNote( $elementId, $targetUserId, $perspective );
            $this->getResult()->addValue( null, 'pharmacopediainteractiondelete', [
                'ok' => 1, 'op' => 'note',
                'element_id' => $elementId,
                'target_user_id' => $targetUserId,
                'perspective' => $perspective,
            ] );
            return;
        }

        $this->dieWithError( [ 'rawmessage', 'Unknown op.' ], 'badvalue' );
    }

    public function getAllowedParams() {
        return [
            'op'             => [ ApiBase::PARAM_TYPE => 'string',  ApiBase::PARAM_REQUIRED => true ],
            'element_id'     => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'target_user_id' => [ ApiBase::PARAM_TYPE => 'integer' ],
            'perspective'    => [ ApiBase::PARAM_TYPE => 'integer' ],
        ];
    }
    public function needsToken()   { return 'csrf'; }
    public function isWriteMode()  { return true; }
    public function mustBePosted() { return true; }
}
