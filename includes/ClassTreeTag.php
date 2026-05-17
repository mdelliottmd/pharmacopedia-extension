<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class ClassTreeTag {

    public static function render( $input, array $args, $parser, $frame ) {
        $excludeRaw = (string)( $args['exclude'] ?? '' );
        $exclude = [];
        foreach ( explode( ',', $excludeRaw ) as $e ) {
            $e = trim( $e );
            if ( $e === '' ) { continue; }
            $exclude[ strtolower( str_replace( ' ', '_', $e ) ) ] = true;
        }

        $marker = MediaWikiServices::getInstance()->getMainConfig()
            ->get( 'PharmacopediaInteractionCategoryMarker' );
        if ( !$marker ) {
            return '<div class="pcp-error">&lt;classTree&gt;: marker category not configured.</div>';
        }

        $parser->getOutput()->updateCacheExpiry( 300 );
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

        $rows = $dbr->select(
            [ 'page', 'category', 'categorylinks', 'linktarget' ],
            [ 'cat_title' => 'category.cat_title',
              'cat_pages' => 'category.cat_pages' ],
            [
                'page.page_namespace' => NS_CATEGORY,
                'lt_namespace'        => NS_CATEGORY,
                'lt_title'            => $marker,
            ],
            __METHOD__,
            [],
            [
                'category'      => [ 'INNER JOIN', 'category.cat_title = page.page_title' ],
                'categorylinks' => [ 'INNER JOIN', 'cl_from = page_id' ],
                'linktarget'    => [ 'INNER JOIN', 'lt_id = cl_target_id' ],
            ]
        );

        $classes = [];
        foreach ( $rows as $r ) {
            $key = (string)$r->cat_title;
            if ( isset( $exclude[ strtolower( $key ) ] ) ) { continue; }
            $classes[ $key ] = [
                'display'  => str_replace( '_', ' ', $key ),
                'count'    => (int)$r->cat_pages,
                'parents'  => [],
                'children' => [],
            ];
        }

        if ( !$classes ) {
            return '<div class="pcp-empty">No classes tagged with <code>[[Category:' .
                htmlspecialchars( $marker ) . ']]</code>.</div>';
        }

        $keys = array_keys( $classes );
        $edges = $dbr->select(
            [ 'page', 'categorylinks', 'linktarget' ],
            [ 'child' => 'page.page_title', 'parent' => 'linktarget.lt_title' ],
            [
                'page.page_namespace'     => NS_CATEGORY,
                'page.page_title'         => $keys,
                'linktarget.lt_namespace' => NS_CATEGORY,
                'linktarget.lt_title'     => $keys,
            ],
            __METHOD__,
            [],
            [
                'categorylinks' => [ 'INNER JOIN', 'cl_from = page_id' ],
                'linktarget'    => [ 'INNER JOIN', 'lt_id = cl_target_id' ],
            ]
        );
        foreach ( $edges as $e ) {
            $child  = (string)$e->child;
            $parent = (string)$e->parent;
            if ( $child === $parent ) { continue; }
            if ( !isset( $classes[ $child ] ) || !isset( $classes[ $parent ] ) ) { continue; }
            $classes[ $child ][ 'parents' ][]  = $parent;
            $classes[ $parent ][ 'children' ][] = $child;
        }

        $roots = [];
        foreach ( $classes as $k => $c ) {
            if ( empty( $c[ 'parents' ] ) ) { $roots[] = $k; }
        }
        usort( $roots, fn( $a, $b ) =>
            strcasecmp( $classes[ $a ][ 'display' ], $classes[ $b ][ 'display' ] )
        );

        $renderNode = function( string $key, array $stack ) use ( &$renderNode, &$classes ) {
            if ( in_array( $key, $stack, true ) ) { return ''; }
            $stack[] = $key;
            $c = $classes[ $key ];
            $url = htmlspecialchars(
                Title::makeTitle( NS_CATEGORY, $key )->getLocalURL()
            );
            $h = '<li><a href="' . $url . '">' . htmlspecialchars( $c[ 'display' ] ) . '</a>'
               . ' <span class="pcp-classtree-n">(' . (int)$c[ 'count' ] . ')</span>';
            if ( !empty( $c[ 'children' ] ) ) {
                $kids = array_unique( $c[ 'children' ] );
                usort( $kids, fn( $a, $b ) =>
                    strcasecmp( $classes[ $a ][ 'display' ], $classes[ $b ][ 'display' ] )
                );
                $h .= '<ul>';
                foreach ( $kids as $kid ) {
                    $h .= $renderNode( $kid, $stack );
                }
                $h .= '</ul>';
            }
            $h .= '</li>';
            return $h;
        };

        $h = '<ul class="pcp-classtree">';
        foreach ( $roots as $r ) {
            $h .= $renderNode( $r, [] );
        }
        $h .= '</ul>';
        return $h;
    }
}
