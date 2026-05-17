<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\Pharmacopedia\ElementStore;

class VoteApi extends ApiBase {
    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( 'pharmacopedia-login-required', 'notloggedin' );
        }
        if ( !$user->isAllowed( 'pharmacopedia-vote' ) ) {
            $this->dieWithError( 'pharmacopedia-permission-denied', 'permissiondenied' );
        }

        $params = $this->extractRequestParams();
        $elementId = (int)$params['element_id'];
        $value = (int)$params['value'];
        if ( !in_array( $value, [ -1, 0, 1 ], true ) ) {
            $this->dieWithError( 'pharmacopedia-invalid-vote-value', 'badvalue' );
        }

        $store = new ElementStore();
        $element = $store->getById( $elementId );
        if ( !$element ) {
            $this->dieWithError( 'pharmacopedia-element-not-found', 'notfound' );
        }

        $updated = $store->castVote( $elementId, $user->getId(), $value );

        $this->getResult()->addValue( null, 'pharmacopediavote', [
            'element_id' => (int)$updated->ve_id,
            'upvotes'    => (int)$updated->ve_upvotes,
            'downvotes'  => (int)$updated->ve_downvotes,
            'score'      => (int)$updated->ve_upvotes - (int)$updated->ve_downvotes,
            'user_vote'  => $value,
        ] );
    }

    public function getAllowedParams() {
        return [
            'element_id' => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'value'      => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
        ];
    }
    public function needsToken()    { return 'csrf'; }
    public function isWriteMode()   { return true; }
    public function mustBePosted()  { return true; }
}
