<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * <effectMedicines slug="X" /> -- auto-generated list of medicines that
 * carry an <effect ref="X"> on their page. Drops onto every Effect:<Name>
 * stub so the article carries the canonical "medicines that may cause
 * this" index without the editor maintaining it by hand.
 *
 * Looks up the votable-element rows whose ve_slug = 'ref-<slug>' AND
 * ve_type = 'effect' (the prefix EffectTag uses on getOrCreate, with the
 * type column carrying the disambiguation since effects do not carry an
 * 'effect-' prefix in ve_slug -- unlike Problems which do).
 */
class EffectMedicinesTag {
    public static function render( $input, array $args, $parser, $frame ) {
        $slug = isset( $args['slug'] ) ? trim( (string)$args['slug'] ) : '';
        if ( $slug === '' ) {
            return '<div class="pcp-error">&lt;effectMedicines&gt;: slug required</div>';
        }
        $normSlug = strtolower( preg_replace( '/[^a-zA-Z0-9-]+/', '-', $slug ) );
        $normSlug = trim( $normSlug, '-' );
        if ( $normSlug === '' ) {
            return '<div class="pcp-error">&lt;effectMedicines&gt;: invalid slug</div>';
        }

        $parser->getOutput()->updateCacheExpiry( 300 );
        $parser->getOutput()->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $veSlug = 'ref-' . $normSlug;
        $res = $dbr->newSelectQueryBuilder()
            ->select( [ 'page_id', 'page_namespace', 'page_title' ] )
            ->distinct()
            ->from( 'pcp_votable_elements' )
            ->join( 'page', null, 've_page_id = page_id' )
            ->where( [ 've_slug' => $veSlug, 've_type' => 'effect' ] )
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
            return '<p class="pcp-pe-empty"><em>No medicines reference this effect yet.</em></p>';
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
