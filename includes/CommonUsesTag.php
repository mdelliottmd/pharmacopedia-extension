<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * <pharmaCommonUses>...</pharmaCommonUses> -- the medicine-page
 * datasheet "Common uses" field.
 *
 * A read-only digest of the medicine's linked problems (the
 * <problem ref="..."> entries in its Problems section), ranked by
 * rater count, top 5. Replaces the hand-entered MedTemplate
 * `uses` param. Data: ProblemStore::medicineUses().
 *
 * Each use links to its Special:Problem/<slug> page (Problems are
 * SpecialPages, so the link always resolves - no redlinks). Past
 * the top 5, a "+N more uses" link jumps to the page's Problems
 * section.
 *
 * The tag content is the legacy hand-entered `uses` wikitext,
 * passed as <pharmaCommonUses>{{{uses|}}}</pharmaCommonUses>.
 * When the medicine has no linked problems yet, that content is
 * rendered verbatim as the field value (house "keep both"); if it
 * is also empty, the field is omitted entirely.
 */
class CommonUsesTag {
    public static function render( $input, array $args, $parser, $frame ) {
        $title = $parser->getTitle();
        if ( !$title ) {
            return '';
        }

        $parser->getOutput()->updateCacheExpiry( 0 );
        $parser->getOutput()->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        $data = ( new ProblemStore() )->medicineUses( $title, 5 );
        $top = is_array( $data['top'] ?? null ) ? $data['top'] : [];
        $total = (int)( $data['total'] ?? 0 );

        // No linked problems: fall back to the hand-entered uses.
        if ( !$top ) {
            $fallback = trim( (string)$input );
            if ( $fallback === '' ) {
                return '';
            }
            $fv = $parser->recursiveTagParse( $fallback, $frame );
            return '<div class="med-field"><div class="fl">Common uses</div>'
                . '<div class="fv">' . $fv . '</div></div>';
        }

        // Sort uses by aggregate rating descending, then name.
        usort( $top, static function ( $a, $b ) {
            $am = (float)( $a['mean'] ?? 0 );
            $bm = (float)( $b['mean'] ?? 0 );
            if ( $am === $bm ) {
                return strcmp(
                    (string)( $a['name'] ?? '' ),
                    (string)( $b['name'] ?? '' )
                );
            }
            return $bm <=> $am;
        } );
        // Resolve slug -> votable-element-id, one query for the lot.
        $pageId    = $title->getArticleID();
        $slugToEid = [];
        if ( $pageId > 0 ) {
            $prefix    = 'problem-ref-';
            $ePrefixed = [];
            foreach ( $top as $use ) {
                $s = (string)( $use['slug'] ?? '' );
                if ( $s !== '' ) {
                    $ePrefixed[] = $prefix . $s;
                }
            }
            if ( $ePrefixed ) {
                $dbr = MediaWikiServices::getInstance()
                    ->getConnectionProvider()->getReplicaDatabase();
                $res = $dbr->select( 'pcp_votable_elements',
                    [ 've_id', 've_slug' ],
                    [ 've_page_id' => $pageId, 've_slug' => $ePrefixed ],
                    __METHOD__ );
                foreach ( $res as $r ) {
                    $slugToEid[ substr( (string)$r->ve_slug, strlen( $prefix ) ) ] = (int)$r->ve_id;
                }
            }
        }
        $h = '<div class="med-field-uses"><div class="fl">Common uses</div>'
            . '<ul class="uses">';
        foreach ( $top as $use ) {
            $name = htmlspecialchars( (string)( $use['name'] ?? '' ) );
            $slug = (string)( $use['slug'] ?? '' );
            $url  = SpecialPage::getTitleFor( 'Problem', $slug )->getLocalURL();
            $eid  = (int)( $slugToEid[ $slug ] ?? 0 );
            $rate = '';
            if ( $eid > 0 ) {
                $mean = max( 0.0, min( 5.0, (float)( $use['mean'] ?? 0 ) ) );
                $rn   = (int)( $use['raters'] ?? 0 );
                $rate = RateWidget::render( $eid, $mean, $rn, (string)( $use['name'] ?? '' ) );
            }
            $h .= '<li class="use"><a class="use-n" href="'
                . htmlspecialchars( $url ) . '">' . $name . '</a>'
                . $rate . '</li>';
        }
        $h .= '</ul>';

        $shown = count( $top );
        if ( $total > $shown ) {
            $more = $total - $shown;
            $h .= '<a class="use-more" href="'
                . htmlspecialchars( $title->getLocalURL() ) . '#Problems">'
                . '+ ' . $more . ' more use' . ( $more === 1 ? '' : 's' )
                . ' &rarr;</a>';
        }
        $h .= '</div>';
        return $h;
    }
}
