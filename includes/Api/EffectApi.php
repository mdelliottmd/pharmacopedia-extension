<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\Pharmacopedia\ElementStore;
use MediaWiki\Extension\Pharmacopedia\EffectStore;

class EffectApi extends ApiBase {
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

        $perspective = (int)$params['perspective'];
        if ( $perspective !== EffectStore::PERSPECTIVE_PATIENT
             && $perspective !== EffectStore::PERSPECTIVE_PROVIDER ) {
            $this->dieWithError( 'pharmacopedia-invalid-perspective', 'badvalue' );
        }
        if ( $perspective === EffectStore::PERSPECTIVE_PROVIDER
             && !$user->isAllowed( 'pharmacopedia-effect-as-provider' ) ) {
            $this->dieWithError( 'pharmacopedia-permission-denied', 'permissiondenied' );
        }

        $valRaw = $params['valence'];
        $valence = ( $valRaw === '' || $valRaw === null ) ? null : (int)$valRaw;
        if ( $valence !== null && ( $valence < -100 || $valence > 100 ) ) {
            $this->dieWithError( 'pharmacopedia-invalid-valence', 'badvalue' );
        }

        $experienced = null;
        $frequency = null;

        if ( $perspective === EffectStore::PERSPECTIVE_PATIENT ) {
            $expRaw = $params['experienced'];
            $experienced = ( $expRaw === '' || $expRaw === null ) ? null : (int)$expRaw;
            if ( $experienced !== null && !in_array( $experienced, [ 0, 1, 2 ], true ) ) {
                $this->dieWithError( 'pharmacopedia-invalid-experienced', 'badvalue' );
            }
            // Valence only valid when patient experienced=1 (Yes)
            if ( $experienced !== 1 ) { $valence = null; }
        } else {
            $freqRaw = $params['frequency'];
            $frequency = ( $freqRaw === '' || $freqRaw === null ) ? null : (int)$freqRaw;
            if ( $frequency !== null && !in_array( $frequency, EffectStore::FREQUENCY_VALUES, true ) ) {
                $this->dieWithError( 'pharmacopedia-invalid-frequency', 'badvalue' );
            }
            // Valence only valid when provider has seen it (frequency > 0 and not null)
            if ( $frequency === null || $frequency === -1 ) { $valence = null; }
        }

        $elementStore = new ElementStore();
        $element = $elementStore->getById( $elementId );
        if ( !$element ) {
            $this->dieWithError( 'pharmacopedia-element-not-found', 'notfound' );
        }

        $effectStore = new EffectStore();
        $effectStore->submitReport( $elementId, $user->getId(), $perspective, $experienced, $frequency, $valence );
        $patient  = $effectStore->getAggregates( $elementId, EffectStore::PERSPECTIVE_PATIENT );
        $provider = $effectStore->getAggregates( $elementId, EffectStore::PERSPECTIVE_PROVIDER );

        $userPatient  = $effectStore->getUserReport( $elementId, $user->getId(), EffectStore::PERSPECTIVE_PATIENT );
        $userProvider = $effectStore->getUserReport( $elementId, $user->getId(), EffectStore::PERSPECTIVE_PROVIDER );

        $this->getResult()->addValue( null, 'pharmacopediaeffect', [
            'element_id'                => $elementId,
            'patient'                   => $patient,
            'provider'                  => $provider,
            'user_patient_experienced'  => $userPatient && $userPatient->er_experienced !== null ? (int)$userPatient->er_experienced : null,
            'user_patient_valence'      => $userPatient && $userPatient->er_valence !== null ? (int)$userPatient->er_valence : null,
            'user_provider_frequency'   => $userProvider && $userProvider->er_frequency_pct !== null ? (int)$userProvider->er_frequency_pct : null,
            'user_provider_valence'     => $userProvider && $userProvider->er_valence !== null ? (int)$userProvider->er_valence : null,
        ] );
    }

    public function getAllowedParams() {
        return [
            'element_id'  => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'perspective' => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'experienced' => [ ApiBase::PARAM_TYPE => 'string' ],
            'frequency'   => [ ApiBase::PARAM_TYPE => 'string' ],
            'valence'     => [ ApiBase::PARAM_TYPE => 'string' ],
        ];
    }
    public function needsToken()   { return 'csrf'; }
    public function isWriteMode()  { return true; }
    public function mustBePosted() { return true; }
}
