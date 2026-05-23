<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * MedicineDatasheetHandler. GET /rest.php/pharmacopedia/v0/medicine/{title}.
 *
 * Read-only structured datasheet feed for the mobile app's native medicine
 * screen. Returns one JSON record per medicine, assembled from three sources:
 *   - The Cargo `Medicines` table (populated by Template:MedTemplate's
 *     #cargo_store): the template-driven fields.
 *   - ProblemStore::medicineUses() (pcp_votable_elements + pcp_likert_reports):
 *     per-(medicine, problem) efficacy ratings, on the 0-5 scale.
 *   - The file repo: the structure-image filename resolved to a full URL.
 *
 * Public; every field served is public wiki content.
 *
 * @license GPL-3.0-or-later
 */
class MedicineDatasheetHandler extends SimpleHandler {

    /** @inheritDoc */
    public function run( $title ) {
        $t = Title::newFromText( (string)$title );
        if ( !$t || $t->getNamespace() !== NS_MAIN ) {
            throw new HttpException( 'Invalid medicine title.', 400 );
        }
        $pageId = $t->getArticleID();
        if ( $pageId <= 0 ) {
            throw new HttpException( 'Page not found.', 404 );
        }

        $row = $this->fetchCargoRow( $pageId );
        if ( !$row ) {
            throw new HttpException( 'Not a medicine.', 404 );
        }

        $datasheet = [
            'pageId'            => $pageId,
            'title'             => $t->getPrefixedText(),
            'classes'           => $this->parseLinkedList( (string)( $row->classes__full ?? '' ) ),
            'alsoKnownAs'       => $this->buildAlsoKnownAs( $t, (string)( $row->brand ?? '' ) ),
            'structureImageUrl' => $this->resolveStructureUrl( (string)( $row->structure ?? '' ) ),
            'commonUses'        => $this->buildCommonUses( $t ),
            'pharmacy' => [
                'startingDose' => $this->nullIfEmpty( (string)( $row->starting_dose ?? '' ) ),
                'preparations' => $this->nullIfEmpty( (string)( $row->preparations ?? '' ) ),
                'usFdaMax'     => $this->nullIfEmpty( (string)( $row->fda_max ?? '' ) ),
            ],
            'pharmacology' => [
                'halfLife' => $this->nullIfEmpty( (string)( $row->halflife ?? '' ) ),
                'routes'   => $this->nullIfEmpty( (string)( $row->routes__full ?? '' ) ),
            ],
        ];

        return $this->getResponseFactory()->createJson( $datasheet );
    }

    /** This page's row in the Cargo Medicines table, or null. */
    private function fetchCargoRow( int $pageId ) {
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $row = $dbr->selectRow(
            'cargo__Medicines',
            '*',
            [ '_pageID' => $pageId ],
            __METHOD__
        );
        return $row ?: null;
    }

    /** Resolve a structure-image filename to its full URL, or null. */
    private function resolveStructureUrl( string $filename ): ?string {
        $filename = trim( $filename );
        if ( $filename === '' ) {
            return null;
        }
        $file = MediaWikiServices::getInstance()
            ->getRepoGroup()->findFile( $filename );
        return $file ? $file->getFullUrl() : null;
    }

    /**
     * Parse a Cargo list field stored as comma-separated wikilinks, e.g.
     * "[[:Category:SSRIs|SSRI]], [[:Category:Anxiolytics|Anxiolytic]]", and
     * return the pipe-labels as plain strings. Falls back to a plain
     * comma-split if no wikilinks are present.
     */
    private function parseLinkedList( string $text ): array {
        $text = trim( $text );
        if ( $text === '' ) {
            return [];
        }
        $out = [];
        if ( preg_match_all( '/\[\[[^\]|]+\|([^\]]+)\]\]/', $text, $m ) ) {
            foreach ( $m[1] as $label ) {
                $label = trim( $label );
                if ( $label !== '' ) {
                    $out[] = $label;
                }
            }
        }
        if ( !$out ) {
            foreach ( explode( ',', $text ) as $part ) {
                $part = trim( $part );
                if ( $part !== '' ) {
                    $out[] = $part;
                }
            }
        }
        return $out;
    }

    /** Split a comma-separated string into a trimmed, non-empty array. */
    private function splitCsv( string $text ): array {
        $out = [];
        foreach ( explode( ',', $text ) as $part ) {
            $part = trim( $part );
            if ( $part !== '' ) {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * Aliases for this medicine: the comma-separated `brand` field from the
     * Cargo Medicines table merged with every wiki redirect pointing at this
     * page. Redirects are the wiki's native alias mechanism (Prozac, Sarafem,
     * etc. all redirect to Fluoxetine), so they're the right substrate for
     * the app's alias matching. Deduped case-insensitively, the page title
     * itself is dropped, and the result is alphabetized.
     */
    private function buildAlsoKnownAs( Title $title, string $brandCsv ): array {
        $aliases = $this->splitCsv( $brandCsv );

        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $res = $dbr->select(
            [ 'redirect', 'page' ],
            [ 'page_title' ],
            [
                'rd_namespace' => $title->getNamespace(),
                'rd_title'     => $title->getDBkey(),
                'rd_interwiki' => '',
            ],
            __METHOD__,
            [],
            [ 'page' => [ 'INNER JOIN', 'page_id = rd_from' ] ]
        );
        foreach ( $res as $row ) {
            // page_title is DB-form (underscored); restore display spaces.
            $aliases[] = str_replace( '_', ' ', (string)$row->page_title );
        }

        // Drop the page title itself, dedupe case-insensitively (preserving
        // the first-seen casing), alphabetize.
        $self = $title->getText();
        $seen = [];
        $out = [];
        foreach ( $aliases as $a ) {
            $a = trim( $a );
            if ( $a === '' || strcasecmp( $a, $self ) === 0 ) {
                continue;
            }
            $k = mb_strtolower( $a );
            if ( isset( $seen[ $k ] ) ) {
                continue;
            }
            $seen[ $k ] = true;
            $out[] = $a;
        }
        usort( $out, 'strcasecmp' );
        return $out;
    }

    /** Return null for empty strings, the trimmed string otherwise. */
    private function nullIfEmpty( string $s ): ?string {
        $s = trim( $s );
        return $s === '' ? null : $s;
    }

    /**
     * commonUses: the full ranked list from ProblemStore::medicineUses(),
     * mapped to the contract shape. The rating is already 0-5.
     */
    private function buildCommonUses( Title $title ): array {
        $r = ( new ProblemStore() )->medicineUses( $title, 0 );
        $out = [];
        foreach ( $r['top'] as $u ) {
            $out[] = [
                'problem'          => $u['name'],
                'problemPageTitle' => 'Special:Problem/' . $u['slug'],
                'rating'           => $u['mean'] !== null
                    ? (float)$u['mean']
                    : 0.0,
                'raterCount'       => (int)$u['raters'],
            ];
        }
        return $out;
    }

    /** @inheritDoc */
    public function getParamSettings() {
        return [
            'title' => [
                self::PARAM_SOURCE => 'path',
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => true,
            ],
        ];
    }

    /** @inheritDoc */
    public function needsWriteAccess() {
        return false;
    }
}
