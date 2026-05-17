<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use ApiBase;
use MediaWiki\MediaWikiServices;

/**
 * Autocomplete for med-name fields on Special:MyProfile.
 * Searches pages in Category:Medicines by case-insensitive substring on page title.
 */
class MedSearchApi extends ApiBase {

    public function execute() {
        if ( $this->getUser()->pingLimiter( 'pharmacopedia-medsearch' ) ) {
            $this->dieWithError( 'apierror-ratelimited', 'ratelimited' );
        }
        $params = $this->extractRequestParams();
        $q = trim( (string)$params['q'] );
        if ( $q === '' ) {
            $this->getResult()->addValue( null, 'pharmacopediamedsearch', [ 'matches' => [] ] );
            return;
        }

        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        // Query for pages in Category:Medicines whose title contains the query.
        // Compare lowercased on both sides so search is case-insensitive.
        $qLower = mb_strtolower( $q );
        $like = $dbr->buildLike( $dbr->anyString(), $qLower, $dbr->anyString() );
        $res = $dbr->newSelectQueryBuilder()
            ->select( [ 'p.page_id', 'p.page_title' ] )
            ->from( 'page', 'p' )
            ->join( 'categorylinks', 'cl', 'cl.cl_from = p.page_id' )
            ->join( 'linktarget', 'lt', 'lt.lt_id = cl.cl_target_id' )
            ->where( [
                'lt.lt_namespace' => 14,
                'lt.lt_title'     => 'Medicines',
                'p.page_namespace' => 0,
                'p.page_is_redirect' => 0,
                // page_title is VARBINARY; LOWER() is a no-op on binary columns,
                // so CONVERT to utf8mb4 first before lowering.
                "REPLACE(LOWER(CONVERT(p.page_title USING utf8mb4)), '_', ' ') $like",
            ] )
            ->limit( 15 )
            ->orderBy( 'p.page_title' )
            ->caller( __METHOD__ )
            ->fetchResultSet();

        $out = [];
        foreach ( $res as $r ) {
            $out[] = [
                'page_id' => (int)$r->page_id,
                'title'   => str_replace( '_', ' ', (string)$r->page_title ),
            ];
        }
        $this->getResult()->addValue( null, 'pharmacopediamedsearch', [
            'q'       => $q,
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
