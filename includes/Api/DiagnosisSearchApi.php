<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use ApiBase;
use MediaWiki\Extension\Pharmacopedia\UserProfileStore;

/**
 * Lightweight autocomplete for the diagnosis editor on Special:MyProfile.
 * Fuzzy substring match against the seeded abbreviation table; case-insensitive.
 */
class DiagnosisSearchApi extends ApiBase {

    public function execute() {
        if ( $this->getUser()->pingLimiter( 'pharmacopedia-dxsearch' ) ) {
            $this->dieWithError( 'apierror-ratelimited', 'ratelimited' );
        }
        $params = $this->extractRequestParams();
        $q = trim( (string)$params['q'] );
        if ( $q === '' || mb_strlen( $q ) < 1 ) {
            $this->getResult()->addValue( null, 'pharmacopediadxsearch', [ 'matches' => [] ] );
            return;
        }
        $store = new UserProfileStore();
        $matches = $store->searchAbbreviations( $q, 15 );
        $out = [];
        foreach ( $matches as $m ) {
            $out[] = [
                'token'     => (string)$m->da_token,
                'system'    => (string)$m->da_system,
                'code'      => $m->da_code === null ? null : (string)$m->da_code,
                'canonical' => (string)$m->da_canonical,
            ];
        }
        $this->getResult()->addValue( null, 'pharmacopediadxsearch', [
            'q' => $q,
            'matches' => $out,
        ] );
    }

    public function getAllowedParams() {
        return [
            'q' => [ ApiBase::PARAM_TYPE => 'string', ApiBase::PARAM_REQUIRED => true ],
        ];
    }
    public function needsToken()   { return false; }
    public function isWriteMode()  { return false; }
    public function mustBePosted() { return false; }
}
