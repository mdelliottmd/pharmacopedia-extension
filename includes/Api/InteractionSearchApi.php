<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;

/**
 * Type-ahead search for the Add-interaction modal.
 *
 * Returns medicines (NS_MAIN) and categories (NS_CATEGORY) matching the
 * query. Categories are filtered to those tagged with the marker category
 * configured via $wgPharmacopediaInteractionCategoryMarker (default
 * "Pharmacopedia_interaction_class") so only curated drug-class style
 * categories appear -- not every MW Category: page.
 */
class InteractionSearchApi extends ApiBase {
    public function execute() {
        $params = $this->extractRequestParams();
        $q = trim( (string)( $params['q'] ?? '' ) );
        $excludeType = (string)( $params['exclude_type'] ?? '' );
        $excludeSlug = (string)( $params['exclude_slug'] ?? '' );

        $matches = [];
        if ( $q === '' ) {
            $this->getResult()->addValue( null, 'pharmacopediainteractionsearch',
                [ 'q' => $q, 'matches' => $matches ] );
            return;
        }

        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        // Case-insensitive: lowercase both needle (PHP) and column (SQL).
        // page_title is varbinary so LIKE is normally case-sensitive; LOWER() folds ASCII.
        $needle = strtolower( str_replace( ' ', '_', $q ) );
        $like = $dbr->buildLike( $dbr->anyString(), $needle, $dbr->anyString() );

        // --- Medicines (NS_MAIN) ---
        $medRows = $dbr->select(
            'page',
            [ 'page_namespace', 'page_title' ],
            [
                'page_is_redirect' => 0,
                'page_namespace'   => NS_MAIN,
                "LOWER(CONVERT(page_title USING utf8mb4)) $like",
            ],
            __METHOD__,
            [ 'ORDER BY' => 'page_title', 'LIMIT' => 30 ]
        );
        foreach ( $medRows as $r ) {
            $slug = (string)$r->page_title;
            if ( $excludeType === 'medicine' && $excludeSlug === $slug ) { continue; }
            $matches[] = [
                'type' => 'medicine',
                'slug' => $slug,
                'name' => str_replace( '_', ' ', $slug ),
            ];
        }

        // --- Categories (NS_CATEGORY) filtered by marker category ---
        $marker = MediaWikiServices::getInstance()->getMainConfig()
            ->get( 'PharmacopediaInteractionCategoryMarker' );

        if ( $marker ) {
            // MW now stores the parent-category name in linktarget(lt_title); categorylinks.cl_target_id is the FK.
            $catRows = $dbr->select(
                [ 'page', 'categorylinks', 'linktarget' ],
                [ 'page_namespace', 'page_title' ],
                [
                    'page_is_redirect' => 0,
                    'page_namespace'   => NS_CATEGORY,
                    "LOWER(CONVERT(page_title USING utf8mb4)) $like",
                    'lt_namespace'     => NS_CATEGORY,
                    'lt_title'         => $marker,
                ],
                __METHOD__,
                [ 'ORDER BY' => 'page_title', 'LIMIT' => 30 ],
                [
                    'categorylinks' => [ 'INNER JOIN', 'cl_from = page_id' ],
                    'linktarget'    => [ 'INNER JOIN', 'lt_id = cl_target_id' ],
                ]
            );
            foreach ( $catRows as $r ) {
                $slug = (string)$r->page_title;
                if ( $excludeType === 'category' && $excludeSlug === $slug ) { continue; }
                $matches[] = [
                    'type' => 'category',
                    'slug' => $slug,
                    'name' => str_replace( '_', ' ', $slug ),
                ];
            }
        }

        $this->getResult()->addValue( null, 'pharmacopediainteractionsearch',
            [ 'q' => $q, 'matches' => $matches, 'marker' => $marker ] );
    }
    public function getAllowedParams() {
        return [
            'q'            => [ ApiBase::PARAM_TYPE => 'string' ],
            'exclude_type' => [ ApiBase::PARAM_TYPE => 'string' ],
            'exclude_slug' => [ ApiBase::PARAM_TYPE => 'string' ],
        ];
    }
    public function isReadMode()   { return true; }
    public function mustBePosted() { return false; }
}
