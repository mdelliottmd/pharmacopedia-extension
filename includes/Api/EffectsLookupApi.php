<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\Pharmacopedia\GlobalEffectStore;

class EffectsLookupApi extends ApiBase {
    public function execute() {
        $params = $this->extractRequestParams();
        $q = trim( (string)( $params['q'] ?? '' ) );
        $store = new GlobalEffectStore();
        $rows = $store->search( $q, 8 );
        $matches = [];
        foreach ( $rows as $r ) {
            $matches[] = [
                'id'          => (int)$r->e_id,
                'slug'        => $r->e_slug,
                'name'        => $r->e_name,
                'description' => $r->e_description,
                'aliases'     => $r->e_aliases,
            ];
        }
        $this->getResult()->addValue( null, 'pharmacopediaeffectslookup', [
            'q' => $q, 'matches' => $matches,
        ] );
    }
    public function getAllowedParams() {
        return [ 'q' => [ ApiBase::PARAM_TYPE => 'string' ] ];
    }
    public function isReadMode() { return true; }
    public function mustBePosted() { return false; }
}
