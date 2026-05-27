<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\Context\RequestContext;

/**
 * <problem ref="slug" author="..."> body </problem>
 *
 * Renders a single Problem (what a medicine is used FOR) on a medicine page,
 * with a per-page efficacy likert and an optional body description.
 *
 * Internal element-slug prefix is 'problem-<slug>' (since Phase 5a of the
 * indications-to-problems rebuild). Pre-rebuild ratings stored under the
 * legacy 'indication-<slug>' prefix were migrated in a single DB UPDATE at
 * the same commit, so no data is orphaned.
 */
class ProblemTag {
    public static function render( $input, array $args, $parser, $frame ) {
        $slug   = isset( $args['slug'] )   ? trim( (string)$args['slug'] )   : '';
        $title  = isset( $args['title'] )  ? trim( (string)$args['title'] )  : '';
        $author = isset( $args['author'] ) ? trim( (string)$args['author'] ) : '';
        $ref = isset( $args['ref'] ) ? trim( (string)$args['ref'] ) : '';
        if ( $ref !== '' ) {
            $problemStore = new ProblemStore();
            $resolved = $problemStore->resolve( $ref );
            if ( $resolved ) {
                $slug = 'ref-' . $resolved->p_slug;
                $title = $resolved->p_name;
            } else {
                return '<span class="pcp-error">&lt;problem&gt;: unknown ref "' . htmlspecialchars( $ref ) . '"</span>';
            }
        }
        if ( $slug === '' )  { return '<div class="pcp-error">&lt;problem&gt;: slug required</div>'; }
        if ( $title === '' ) { return '<div class="pcp-error">&lt;problem&gt;: title required</div>'; }

        $normSlug = strtolower( preg_replace( '/[^a-zA-Z0-9-]+/', '-', $slug ) );
        $normSlug = trim( $normSlug, '-' );
        if ( $normSlug === '' ) {
            return '<div class="pcp-error">&lt;problem&gt;: invalid slug</div>';
        }

        $page = $parser->getTitle();
        if ( !$page ) { return '<div class="pcp-error">&lt;problem&gt;: no page context</div>'; }
        $pageId = $page->getArticleID();
        $body = trim( $parser->recursiveTagParse( (string)$input, $frame ) );

        $user = RequestContext::getMain()->getUser();
        $parser->getOutput()->updateCacheExpiry( 0 );
        $parser->getOutput()->addModules( ['ext.pharmacopedia'] );
        $parser->getOutput()->addModuleStyles( ['ext.pharmacopedia.styles'] );

        // Preview-mode render before the page exists
        if ( $pageId <= 0 ) {
            $h  = '<div class="pcp-row pcp-row-problem pcp-problem pcp-problem-preview" id="problem-' . htmlspecialchars( $normSlug ) . '">';
            $h .= '<div class="pcp-row-head">';
            $h .= '<span class="pcp-row-title">';
        $pcpProbTitle = defined( 'NS_PROBLEM' ) ? \MediaWiki\Title\Title::makeTitleSafe( NS_PROBLEM, $title ) : null;
        if ( $pcpProbTitle ) {
            $h .= '<a href="' . htmlspecialchars( $pcpProbTitle->getLocalURL() ) . '">' . htmlspecialchars( $title ) . '</a>';
        } else {
            $h .= htmlspecialchars( $title );
        }
        $h .= '</span>';
            $h .= '<span class="pcp-row-aggs"><span class="pcp-row-agg pcp-problem-vote-placeholder">(efficacy ratings appear once page is saved)</span></span>';
            $h .= '<span class="pcp-row-actions">';
            $h .= SpecialDeletePharmaElement::buttonHtml( 'problem', $normSlug, $author );
            $h .= '</span>';
            $h .= '</div>';
            if ( $body !== '' ) { $h .= '<div class="pcp-row-body">' . $body . '</div>'; }
            $h .= '</div>';
            return $h;
        }

        // 'problem-' prefix (post-Phase-5a). The DB UPDATE that runs alongside
        // this code change renames every pre-existing 'indication-<slug>' row
        // in pcp_votable_elements to 'problem-<slug>', preserving all ratings.
        $store = new ElementStore();
        $element = $store->getOrCreate( $pageId, 'problem-' . $normSlug, 'likert', $title );
        $elementId = (int)$element->ve_id;

        $likert = new LikertStore();
        $agg = $likert->getAggregates( $elementId );
        $userRating = $user->isRegistered() ? $likert->getUserRating( $elementId, $user->getId() ) : null;

        // Aggregate one-liner
        $dk = (int)( $agg['n_dontknow'] ?? 0 );
        // Always render the rate widget (display + mouseover input);
        // it shows empty stars at n=0 and remains rateable.
        $rMean   = max( 0.0, min( 5.0, (float)( $agg['mean'] ?? 0 ) ) );
        $rN      = (int)$agg['n'];
        $aggText = RateWidget::render( (int)$elementId, $rMean, $rN, $title );
        // pcp-rate-n is now rendered inside RateWidget (two-line voted layout).
        if ( $dk > 0 ) {
            $aggText .= ' <span class="pcp-likert-dk-count">' . $dk . ' don\'t know</span>';
        }



        $h  = '<div class="pcp-row pcp-row-problem pcp-problem" id="problem-' . htmlspecialchars( $normSlug ) .
              '" data-slug="' . htmlspecialchars( $normSlug ) .
              '" data-element-id="' . $elementId .
              '" data-user-rating="' . ( $userRating === null ? '' : ( $userRating >= 0 ? sprintf( '%.2f', (float)$userRating ) : '' ) ) . '"' .
              ' data-likert-n="' . (int)$agg['n'] . '"' .
              ' data-likert-mean="' . ( $agg['n'] > 0 ? (float)$agg['mean'] : '' ) . '">';

        // HEAD line
        $h .= '<div class="pcp-row-head">';
        $h .= '<span class="pcp-row-title">';
        $pcpProbTitle = defined( 'NS_PROBLEM' ) ? \MediaWiki\Title\Title::makeTitleSafe( NS_PROBLEM, $title ) : null;
        if ( $pcpProbTitle ) {
            $h .= '<a href="' . htmlspecialchars( $pcpProbTitle->getLocalURL() ) . '">' . htmlspecialchars( $title ) . '</a>';
        } else {
            $h .= htmlspecialchars( $title );
        }
        $h .= '</span>';
        $h .= '<span class="pcp-row-aggs"><span class="pcp-row-agg pcp-problem-agg">' . $aggText . '</span></span>';
        $h .= '<span class="pcp-row-actions">';
        $h .= SpecialDeletePharmaElement::buttonHtml( 'problem', $normSlug, $author );
        $h .= '</span>';
        $h .= '</div>';


        // BODY (always visible)
        if ( $body !== '' ) {
            $h .= '<div class="pcp-row-body pcp-problem-body">' . $body . '</div>';
        }
        $h .= '</div>';
        return $h;
    }
}
