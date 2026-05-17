<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\Pharmacopedia\InteractionStore;
use MediaWiki\Title\Title;

class InteractionAddApi extends ApiBase {
    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( [ 'apierror-mustbeloggedin', 'add an interaction' ], 'notloggedin' );
        }
        if ( !$user->isAllowed( 'pharmacopedia-vote' ) ) {
            $this->dieWithError( [ 'apierror-permissiondenied', 'add an interaction' ], 'permissiondenied' );
        }

        $params = $this->extractRequestParams();
        $lt = (string)$params['left_type'];
        $ls = (string)$params['left_slug'];
        $rt = (string)$params['right_type'];
        $rs = (string)$params['right_slug'];

        if ( !InteractionStore::isValidType( $lt ) || !InteractionStore::isValidType( $rt ) ) {
            $this->dieWithError( [ 'rawmessage', 'Invalid endpoint type.' ], 'badvalue' );
        }
        $pair = InteractionStore::normalizePair( $lt, $ls, $rt, $rs );
        if ( !$pair ) {
            $this->dieWithError( [ 'rawmessage', 'Cannot create an interaction with itself.' ], 'badvalue' );
        }
        [ $nlt, $nls, $nrt, $nrs ] = $pair;

        // Endpoint pages must exist.
        foreach ( [ [ $nlt, $nls ], [ $nrt, $nrs ] ] as $side ) {
            [ $t, $sl ] = $side;
            $ns = ( $t === InteractionStore::TYPE_CATEGORY ) ? NS_CATEGORY : NS_MAIN;
            $title = Title::makeTitleSafe( $ns, $sl );
            if ( !$title || !$title->exists() ) {
                $label = ( $t === InteractionStore::TYPE_CATEGORY ? 'Category:' : '' ) . str_replace( '_', ' ', $sl );
                $this->dieWithError( [ 'rawmessage', "Page does not exist: $label" ], 'notfound' );
            }
        }

        $store = new InteractionStore();
        $pre = $store->findPair( $nlt, $nls, $nrt, $nrs );
        $wasNew = !$pre;

        $row = $store->getOrCreate( $nlt, $nls, $nrt, $nrs, (int)$user->getId() );
        if ( !$row ) {
            $this->dieWithError( [ 'rawmessage', 'Interaction create failed.' ], 'internal' );
        }

        $this->getResult()->addValue( null, 'pharmacopediainteractionadd', [
            'ok'         => 1,
            'pi_id'      => (int)$row->pi_id,
            'element_id' => (int)$row->pi_element_id,
            'was_new'    => $wasNew ? 1 : 0,
            'left_type'  => $row->pi_left_type,
            'left_slug'  => $row->pi_left_slug,
            'right_type' => $row->pi_right_type,
            'right_slug' => $row->pi_right_slug,
        ] );
    }

    public function getAllowedParams() {
        return [
            'left_type'   => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => true ],
            'left_slug'   => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => true ],
            'right_type'  => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => true ],
            'right_slug'  => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => true ],
        ];
    }
    public function needsToken()   { return 'csrf'; }
    public function isWriteMode()  { return true; }
    public function mustBePosted() { return true; }
}
