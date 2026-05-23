<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\Pharmacopedia\ElementStore;
use MediaWiki\Extension\Pharmacopedia\LikertStore;

class LikertApi extends ApiBase {
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
        $valRaw = $params['value'];
        $value = ( $valRaw === '' || $valRaw === null ) ? null : (float)$valRaw;
        if ( $value !== null && ( $value < -1 || $value > 5 ) ) {
            $this->dieWithError( 'pharmacopedia-invalid-likert', 'badvalue' );
        }

        $elementStore = new ElementStore();
        $element = $elementStore->getById( $elementId );
        if ( !$element ) {
            $this->dieWithError( 'pharmacopedia-element-not-found', 'notfound' );
        }

        $store = new LikertStore();
        $store->submitRating( $elementId, $user->getId(), $value );
        $agg = $store->getAggregates( $elementId );
        $user_value = $store->getUserRating( $elementId, $user->getId() );

        $this->getResult()->addValue( null, 'pharmacopedialikert', [
            'element_id' => $elementId,
            'n'          => $agg['n'],
            'mean'       => $agg['mean'],
            'user_value' => $user_value,
        ] );
    }

    public function getAllowedParams() {
        return [
            'element_id' => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'value'      => [ ApiBase::PARAM_TYPE => 'string' ],
        ];
    }
    public function needsToken()   { return 'csrf'; }
    public function isWriteMode()  { return true; }
    public function mustBePosted() { return true; }
}
