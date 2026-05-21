<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\Pharmacopedia\InteractionStore;

/**
 * POST pcp-interaction-flag
 *   elementid  int    required
 *   type       string required, one of the 5 dimension codes
 *   value      int    required unless clear=1
 *   note       string optional, capped at 2000 chars
 *   clear      bool   optional; if set, clears the flag, value/note ignored
 *
 * Upserts or clears the user's flag on a PGx interaction edge and
 * returns fresh aggregates for the element so the UI can redraw the
 * inline tally without a second round trip.
 *
 * Spec: interface-claude, 2026-05-20. Login + edit token required, the
 * same gate as the interaction-report submit path.
 *
 * WIRING (at install): register in extension.json APIModules as
 * "pcp-interaction-flag" => InteractionFlagApi::class.
 */
class InteractionFlagApi extends ApiBase {

    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( [ 'apierror-mustbeloggedin', 'flag an interaction' ], 'notloggedin' );
        }
        if ( !$user->isAllowed( 'pharmacopedia-vote' ) ) {
            $this->dieWithError( [ 'apierror-permissiondenied', 'flag an interaction' ], 'permissiondenied' );
        }

        $params    = $this->extractRequestParams();
        $elementId = (int)$params['elementid'];
        $type      = (string)$params['type'];
        $clear     = !empty( $params['clear'] );

        if ( !isset( InteractionStore::FLAG_DIMENSIONS[ $type ] ) ) {
            $this->dieWithError( [ 'rawmessage', 'Invalid flag type.' ], 'badvalue' );
        }

        $store = new InteractionStore();
        if ( !$store->getByElementId( $elementId ) ) {
            $this->dieWithError( [ 'rawmessage', 'Interaction not found.' ], 'notfound' );
        }

        if ( $clear ) {
            $store->clearFlag( $elementId, (int)$user->getId(), $type );
        } else {
            $valRaw = $params['value'] ?? null;
            if ( $valRaw === null || $valRaw === '' ) {
                $this->dieWithError( [ 'rawmessage', 'A value is required unless clear=1.' ], 'badvalue' );
            }
            [ $min, $max ] = InteractionStore::FLAG_DIMENSIONS[ $type ];
            $value = (int)$valRaw;
            if ( $value < $min || $value > $max ) {
                $this->dieWithError(
                    [ 'rawmessage', 'Value out of range for this flag type.' ], 'badvalue' );
            }
            $noteRaw = $params['note'] ?? '';
            $note = ( $noteRaw === '' || $noteRaw === null )
                ? null : mb_substr( (string)$noteRaw, 0, 2000 );
            if ( !$store->submitFlag( $elementId, (int)$user->getId(), $type, $value, $note ) ) {
                $this->dieWithError( [ 'rawmessage', 'Could not record the flag.' ], 'badvalue' );
            }
        }

        $this->getResult()->addValue( null, 'pcp-interaction-flag', [
            'ok'         => true,
            'elementid'  => $elementId,
            'aggregates' => $store->getFlagAggregates( $elementId ),
        ] );
    }

    public function getAllowedParams() {
        return [
            'elementid' => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'type'      => [ ApiBase::PARAM_TYPE => 'string',  ApiBase::PARAM_REQUIRED => true ],
            'value'     => [ ApiBase::PARAM_TYPE => 'integer' ],
            'note'      => [ ApiBase::PARAM_TYPE => 'string' ],
            'clear'     => [ ApiBase::PARAM_TYPE => 'boolean' ],
        ];
    }

    public function needsToken()   { return 'csrf'; }
    public function isWriteMode()  { return true; }
    public function mustBePosted() { return true; }
}
