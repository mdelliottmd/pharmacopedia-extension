<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use ApiBase;
use MediaWiki\Extension\Pharmacopedia\FeatureRequestStore;

/**
 * Sysop-only inline updates from Special:RequestReview.
 * Patches one field (status | priority | sysop_notes) per call.
 */
class FeatureRequestUpdateApi extends ApiBase {

    public function execute() {
        if ( !$this->getUser()->isAllowed( 'pharmacopedia-fr-review' ) ) {
            $this->dieWithError( 'apierror-permissiondenied-generic', 'permissiondenied' );
        }
        $params = $this->extractRequestParams();
        $id = (int)$params['id'];
        $field = (string)$params['field'];
        $value = (string)$params['value'];

        $store = new FeatureRequestStore();
        $row = $store->getById( $id );
        if ( !$row ) {
            $this->dieWithError( [ 'apierror-invalidparameter', 'id' ], 'notfound' );
        }

        if ( $field === 'status' ) {
            if ( !isset( FeatureRequestStore::STATUSES[ $value ] ) ) {
                $this->dieWithError( [ 'apierror-invalidparameter', 'value' ], 'badstatus' );
            }
            $store->updateStatus( $id, $value, $this->getUser()->getId() );
        } elseif ( $field === 'priority' ) {
            $store->updatePriority( $id, (int)$value );
        } elseif ( $field === 'sysop_notes' ) {
            $store->updateSysopNotes( $id, $value );
        } else {
            $this->dieWithError( [ 'apierror-invalidparameter', 'field' ], 'badfield' );
        }

        $this->getResult()->addValue( null, 'pharmacopediafrupdate', [
            'ok'    => true,
            'id'    => $id,
            'field' => $field,
        ] );
    }

    public function getAllowedParams() {
        return [
            'id'    => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'field' => [ ApiBase::PARAM_TYPE => 'string',  ApiBase::PARAM_REQUIRED => true ],
            'value' => [ ApiBase::PARAM_TYPE => 'string',  ApiBase::PARAM_REQUIRED => true ],
        ];
    }
    public function needsToken()   { return 'csrf'; }
    public function isWriteMode()  { return true; }
    public function mustBePosted() { return true; }
}
