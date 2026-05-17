<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class ClassGridTag {
    public static function render( $input, array $args, $parser, $frame ) {
        $count = max( 1, min( 50, (int)( $args['count'] ?? 9 ) ) );
        $excludeRaw = (string)( $args['exclude'] ?? '' );

        $exclude = [];
        foreach ( explode( ',', $excludeRaw ) as $e ) {
            $e = trim( $e );
            if ( $e === '' ) { continue; }
            // Case-insensitive match: store lowercased DB form (underscores).
            $exclude[ strtolower( str_replace( ' ', '_', $e ) ) ] = true;
        }

        $marker = MediaWikiServices::getInstance()->getMainConfig()
            ->get( 'PharmacopediaInteractionCategoryMarker' );
        if ( !$marker ) {
            return '<div class="pcp-error">&lt;classGrid&gt;: marker category not configured.</div>';
        }

        // Auto-refresh: re-render every 5 min so new categories/changing member
        // counts surface promptly without manual cache purges.
        $parser->getOutput()->updateCacheExpiry( 300 );

        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

        // Categories whose own page is tagged with [[Category:$marker]].
        // Filter to nonempty categories. Pull a few extra rows so we have headroom
        // for the post-query exclude filter.
        $rows = $dbr->select(
            [ 'page', 'category', 'categorylinks', 'linktarget' ],
            [ 'cat_title' => 'category.cat_title',
              'cat_pages' => 'category.cat_pages' ],
            [
                'page.page_namespace' => NS_CATEGORY,
                'lt_namespace'        => NS_CATEGORY,
                'lt_title'            => $marker,
                'category.cat_pages > 0',
            ],
            __METHOD__,
            [ 'ORDER BY' => 'category.cat_pages DESC',
              'LIMIT'    => max( 100, $count + count( $exclude ) + 20 ) ],
            [
                'category'      => [ 'INNER JOIN', 'category.cat_title = page.page_title' ],
                'categorylinks' => [ 'INNER JOIN', 'cl_from = page_id' ],
                'linktarget'    => [ 'INNER JOIN', 'lt_id = cl_target_id' ],
            ]
        );

        $classes = [];
        foreach ( $rows as $r ) {
            $title = (string)$r->cat_title;
            if ( isset( $exclude[ strtolower( $title ) ] ) ) { continue; }
            $classes[] = [
                'title'   => $title,
                'display' => str_replace( '_', ' ', $title ),
                'count'   => (int)$r->cat_pages,
            ];
            if ( count( $classes ) >= $count ) { break; }
        }

        if ( !$classes ) {
            return '<div class="pcp-empty">No classes tagged with <code>[[Category:' .
                htmlspecialchars( $marker ) . ']]</code> yet.</div>';
        }

        // Alphabetical, case-insensitive
        usort( $classes, fn( $a, $b ) => strcasecmp( $a['display'], $b['display'] ) );

        $h = '<ul class="pcp-classgrid">';
        foreach ( $classes as $c ) {
            $href = htmlspecialchars(
                Title::makeTitle( NS_CATEGORY, $c['title'] )->getLocalURL()
            );
            $h .= '<li><a href="' . $href . '">' . htmlspecialchars( $c['display'] ) . '</a>'
                . ' <span class="pcp-classgrid-n">(' . (int)$c['count'] . ')</span></li>';
        }
        $h .= '</ul>';

        return $h;
    }
}
