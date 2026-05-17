<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\Pharmacopedia\LiteratureStore;

/**
 * Submitter-initiated delete of own pending literature entry.
 * Admins use SpecialLiteratureQueue for any-status deletion.
 */
class LiteratureDeleteApi extends ApiBase {
    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( [ 'apierror-mustbeloggedin', 'delete a literature entry' ], 'notloggedin' );
        }
        $params = $this->extractRequestParams();
        $id = (int)$params['id'];
        if ( $id <= 0 ) {
            $this->dieWithError( [ 'rawmessage', 'Invalid id.' ], 'badvalue' );
        }
        $store = new LiteratureStore();

        // Admin path: can delete anything by id.
        if ( $user->isAllowed( 'pharmacopedia-literature-review' ) ) {
            $ok = $store->adminDelete( $id );
        } else {
            $ok = $store->deletePendingByUser( $id, (int)$user->getId() );
        }
        if ( !$ok ) {
            $this->dieWithError(
                [ 'rawmessage', 'Could not delete entry (already reviewed, or not yours).' ],
                'notallowed'
            );
        }
        $this->getResult()->addValue( null, 'pharmacopedialiteraturedelete', [ 'ok' => 1, 'id' => $id ] );
    }
    public function getAllowedParams() {
        return [ 'id' => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ] ];
    }
    public function needsToken()   { return 'csrf'; }
    public function isWriteMode()  { return true; }
    public function mustBePosted() { return true; }
}
