<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use ApiBase;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Lightweight username autocomplete for the share-with-people picker.
 * Returns up to N matching registered users (case-insensitive prefix or substring).
 */
class UserSearchApi extends ApiBase {

    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( 'pharmacopedia-login-required', 'notloggedin' );
        }
        $params = $this->extractRequestParams();
        $term  = trim( (string)$params['term'] );
        $limit = max( 1, min( 25, (int)$params['limit'] ) );

        $out = [];
        if ( $term !== '' ) {
            $dbr = \MediaWiki\MediaWikiServices::getInstance()
                ->getConnectionProvider()->getReplicaDatabase();
            // user_name in MW is varbinary; substring-match case-insensitively
            // by folding both sides to lowercase utf8mb4 via CONVERT.
            $folded = 'LOWER(CONVERT(user_name USING utf8mb4))';
            $likeTerm = $dbr->buildLike( $dbr->anyString(), strtolower( $term ), $dbr->anyString() );
            $rows = $dbr->newSelectQueryBuilder()
                ->select( [ 'user_id', 'user_name', 'user_real_name' ] )
                ->from( 'user' )
                ->where( $folded . ' ' . $likeTerm )
                ->limit( $limit )
                ->orderBy( 'user_name' )
                ->caller( __METHOD__ )
                ->fetchResultSet();
            foreach ( $rows as $r ) {
                if ( (int)$r->user_id === (int)$user->getId() ) continue; // skip self
                $out[] = [
                    'id'        => (int)$r->user_id,
                    'name'      => (string)$r->user_name,
                    'real_name' => (string)( $r->user_real_name ?? '' ),
                ];
            }
        }
        $this->getResult()->addValue( null, 'users', $out );
    }

    public function getAllowedParams() {
        return [
            'term'  => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_REQUIRED => true ],
            'limit' => [ ParamValidator::PARAM_TYPE => 'integer', ParamValidator::PARAM_DEFAULT => 10 ],
        ];
    }
}
