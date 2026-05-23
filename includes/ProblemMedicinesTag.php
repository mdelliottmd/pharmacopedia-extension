<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * <problemMedicines slug="X" /> -- auto-generated list of medicines that
 * carry a <problem ref="X"> on their page. Drops onto every Problem:<Name>
 * stub so the article carries the canonical "medicines used for this"
 * index without the editor maintaining it by hand.
 *
 * Looks up the votable-element rows whose ve_slug = 'problem-ref-<slug>'
 * (the prefix ProblemTag uses on getOrCreate), joins to page for the
 * medicine page titles, distincts and sorts.
 */
class ProblemMedicinesTag {
    public static function render( $input, array $args, $parser, $frame ) {
        $slug = isset( $args['slug'] ) ? trim( (string)$args['slug'] ) : '';
        if ( $slug === '' ) {
            return '<div class="pcp-error">&lt;problemMedicines&gt;: slug required</div>';
        }
        $normSlug = strtolower( preg_replace( '/[^a-zA-Z0-9-]+/', '-', $slug ) );
        $normSlug = trim( $normSlug, '-' );
        if ( $normSlug === '' ) {
            return '<div class="pcp-error">&lt;problemMedicines&gt;: invalid slug</div>';
        }

        $parser->getOutput()->updateCacheExpiry( 300 );
        $parser->getOutput()->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        // ve_slug pattern from ProblemTag::render(): 'problem-' . $normSlug,
        // where the inbound ref normalizes to 'ref-<canonical>'. So the
        // stored slug for a <problem ref="headache"> is 'problem-ref-headache'.
        $veSlug = 'problem-ref-' . $normSlug;
        $res = $dbr->newSelectQueryBuilder()
            ->select( [ 'page_id', 'page_namespace', 'page_title' ] )
            ->distinct()
            ->from( 'pcp_votable_elements' )
            ->join( 'page', null, 've_page_id = page_id' )
            ->where( [ 've_slug' => $veSlug ] )
            ->orderBy( 'page_title' )
            ->caller( __METHOD__ )
            ->fetchResultSet();

        $items = [];
        foreach ( $res as $r ) {
            $t = Title::makeTitle( (int)$r->page_namespace, (string)$r->page_title );
            if ( $t ) {
                $items[] = $t;
            }
        }
        if ( !$items ) {
            return '<p class="pcp-pe-empty"><em>No medicines reference this problem yet.</em></p>';
        }
        $h = '<ul class="pcp-pe-medicines">';
        foreach ( $items as $t ) {
            $h .= '<li><a href="' . htmlspecialchars( $t->getLocalURL() ) . '">'
                . htmlspecialchars( $t->getPrefixedText() ) . '</a></li>';
        }
        $h .= '</ul>';
        return $h;
    }
}
