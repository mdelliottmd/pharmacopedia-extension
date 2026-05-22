<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\Pharmacopedia\InteractionStore;

/**
 * POST pharmacopediainteractionreport
 *   element_id, perspective (1|2), experience ('' or 1..5),
 *   valence ('' or -100..100), note ('' or text)
 * Upserts the user's report and returns fresh aggregates.
 */
class InteractionReportApi extends ApiBase {
    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( [ 'apierror-mustbeloggedin', 'rate an interaction' ], 'notloggedin' );
        }
        if ( !$user->isAllowed( 'pharmacopedia-vote' ) ) {
            $this->dieWithError( [ 'apierror-permissiondenied', 'rate an interaction' ], 'permissiondenied' );
        }

        $params = $this->extractRequestParams();
        $elementId  = (int)$params['element_id'];
        $perspective = (int)$params['perspective'];
        if ( $perspective !== InteractionStore::PERSPECTIVE_USER
             && $perspective !== InteractionStore::PERSPECTIVE_PROVIDER ) {
            $this->dieWithError( [ 'rawmessage', 'Invalid perspective.' ], 'badvalue' );
        }
        if ( $perspective === InteractionStore::PERSPECTIVE_PROVIDER
             && !$user->isAllowed( 'pharmacopedia-effect-as-provider' ) ) {
            $this->dieWithError( [ 'apierror-permissiondenied', 'rate from provider perspective' ], 'permissiondenied' );
        }

        // Confirm the interaction exists.
        $store = new InteractionStore();
        $ix = $store->getByElementId( $elementId );
        if ( !$ix ) {
            $this->dieWithError( [ 'rawmessage', 'Interaction not found.' ], 'notfound' );
        }

        // Coerce + validate the three free fields.
        $expRaw = $params['experience'] ?? '';
        $valRaw = $params['valence']    ?? '';
        $noteRaw = $params['note']      ?? '';

        $experience = ( $expRaw === '' || $expRaw === null ) ? null : (int)$expRaw;
        $valence    = ( $valRaw === '' || $valRaw === null ) ? null : (int)$valRaw;

        if ( $experience !== null && ( $experience < 1 || $experience > 5 ) ) {
            $this->dieWithError( [ 'rawmessage', 'Experience must be 1-5.' ], 'badvalue' );
        }
        if ( $valence !== null && ( $valence < -100 || $valence > 100 ) ) {
            $this->dieWithError( [ 'rawmessage', 'Valence must be -100..+100.' ], 'badvalue' );
        }
        // Valence requires experience >= 1.
        if ( $valence !== null && ( $experience === null || $experience < 1 ) ) {
            $valence = null;
        }

        $note = ( $noteRaw === '' || $noteRaw === null ) ? null : mb_substr( (string)$noteRaw, 0, 8000 );

        $store->submitReport( $elementId, (int)$user->getId(), $perspective, $experience, $valence, $note );

        // Fresh aggregates for the JS to redraw with.
        $pooled = $store->getAggregates( $elementId );
        $userA  = $store->getAggregates( $elementId, InteractionStore::PERSPECTIVE_USER );
        $provA  = $store->getAggregates( $elementId, InteractionStore::PERSPECTIVE_PROVIDER );
        // The user's current report values (post-upsert).
        $mine = $store->getUserReport( $elementId, (int)$user->getId(), $perspective );

        $this->getResult()->addValue( null, 'pharmacopediainteractionreport', [
            'ok'         => 1,
            'element_id' => $elementId,
            'pooled'     => $pooled,
            'user_agg'   => $userA,
            'provider_agg' => $provA,
            'mine'       => [
                'perspective' => $perspective,
                'experience'  => $mine && $mine->pir_experience !== null ? (int)$mine->pir_experience : null,
                'valence'     => $mine && $mine->pir_valence    !== null ? (int)$mine->pir_valence    : null,
            ],
        ] );
    }

    public function getAllowedParams() {
        return [
            'element_id'  => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'perspective' => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'experience'  => [ ApiBase::PARAM_TYPE => 'string' ],
            'valence'     => [ ApiBase::PARAM_TYPE => 'string' ],
            'note'        => [ ApiBase::PARAM_TYPE => 'string' ],
        ];
    }
    public function needsToken()   { return 'csrf'; }
    public function isWriteMode()  { return true; }
    public function mustBePosted() { return true; }
}
