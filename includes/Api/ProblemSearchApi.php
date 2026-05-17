<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use ApiBase;
use MediaWiki\Extension\Pharmacopedia\ProblemStore;

/**
 * Lightweight autocomplete for the Problems repository.
 * Used by the diagnosis autocomplete on Special:MyProfile (renders Problems
 * in a secondary section) and any other UI that wants to surface matching
 * canonical Problems from a free-text input.
 *
 * Fuzzy substring match across name, slug, and aliases; case-insensitive.
 */
class ProblemSearchApi extends ApiBase {

    public function execute() {
        if ( $this->getUser()->pingLimiter( 'pharmacopedia-problemsearch' ) ) {
            $this->dieWithError( 'apierror-ratelimited', 'ratelimited' );
        }
        $params = $this->extractRequestParams();
        $q = trim( (string)$params['q'] );
        if ( $q === '' ) {
            $this->getResult()->addValue( null, 'pharmacopediaproblemsearch',
                [ 'matches' => [] ] );
            return;
        }
        $store = new ProblemStore();
        $rows = $store->search( $q, 10 );
        $out = [];
        foreach ( $rows as $r ) {
            $aliases = $store->getAliases( (int)$r->p_id );
            $out[] = [
                'id'       => (int)$r->p_id,
                'slug'     => (string)$r->p_slug,
                'name'     => (string)$r->p_name,
                'category' => (string)$r->p_category,
                'aliases'  => $aliases,
            ];
        }
        // Also report whether the typed query has an exact slug match
        $exactSlug = ProblemStore::normalizeSlug( $q );
        $exact = $store->getBySlug( $exactSlug );
        $this->getResult()->addValue( null, 'pharmacopediaproblemsearch', [
            'q' => $q,
            'matches' => $out,
            'exact_slug_match' => $exact ? (string)$exact->p_slug : null,
            'normalized_slug'  => $exactSlug,
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
