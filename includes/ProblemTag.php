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
            $h .= '<span class="pcp-row-title">' . htmlspecialchars( $title ) . '</span>';
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
        if ( $agg['n'] > 0 ) {
            $aggText = '' .
                '<svg class="pcp-star-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 L14.6 9 L22 9.3 L16.2 14 L18.3 21.6 L12 17.3 L5.7 21.6 L7.8 14 L2 9.3 L9.4 9 Z" fill="#000" stroke="#fff" stroke-width="1.4" stroke-linejoin="round"/></svg> ' . number_format( (float)$agg['mean'], 1 ) .
                ' <span class="pcp-likert-n">(n=' . (int)$agg['n'] . ')</span>';
            if ( $dk > 0 ) {
                $aggText .= ' <span class="pcp-likert-dk-count">' . $dk . ' don\'t know</span>';
            }
        } elseif ( $dk > 0 ) {
            $aggText = '<span class="pcp-likert-dk-count">' . $dk . ' don\'t know</span>';
        } else {
            $aggText = '<span class="pcp-likert-noreports">no ratings yet</span>';
        }

        // Likert slider 0–100 (replaces the 6-button row). -1 "Don't know"
        // stays as a separate toggle that dims the slider when active.
        $initRating = ( $userRating !== null && $userRating >= 0 ) ? (int)$userRating : 50;
        $isDk       = ( $userRating === -1 );
        $dkActiveCls = $isDk ? ' pcp-likert-active' : '';
        $likertBtns  = '<span class="pcp-likert-slider-wrap' . ( $isDk ? ' pcp-likert-dk-on' : '' ) . '">';
        $likertBtns .= '<input type="range" class="pcp-likert-slider" min="0" max="100" step="1" value="' . $initRating . '" oninput="this.nextElementSibling.value=this.value">';
        $likertBtns .= '<output class="pcp-likert-slider-out">' . $initRating . '</output>';
        $likertBtns .= '</span>';
        $likertBtns .= '<button type="button" class="pcp-likert-btn pcp-likert-btn-dk' . $dkActiveCls . '" data-value="-1">Don\'t know</button>';

        $h  = '<div class="pcp-row pcp-row-problem pcp-problem" id="problem-' . htmlspecialchars( $normSlug ) .
              '" data-slug="' . htmlspecialchars( $normSlug ) .
              '" data-element-id="' . $elementId .
              '" data-user-rating="' . ( $userRating === null ? '' : (int)$userRating ) . '"' .
              ' data-likert-n="' . (int)$agg['n'] . '"' .
              ' data-likert-mean="' . ( $agg['n'] > 0 ? (float)$agg['mean'] : '' ) . '">';

        // HEAD line
        $h .= '<div class="pcp-row-head">';
        $h .= '<span class="pcp-row-title">' . htmlspecialchars( $title ) . '</span>';
        $h .= '<span class="pcp-row-aggs"><span class="pcp-row-agg pcp-problem-agg">' . $aggText . '</span></span>';
        $h .= '<span class="pcp-row-actions">';
        $h .= '<button type="button" class="pcp-row-action pcp-row-action-toggle" data-target="rate" aria-expanded="false">Rate</button>';
        $h .= SpecialDeletePharmaElement::buttonHtml( 'problem', $normSlug, $author );
        $h .= '</span>';
        $h .= '</div>';

        // RATE panel (folded)
        $h .= '<div class="pcp-row-panel pcp-row-rate-panel pcp-problem-likert" hidden>';
        $h .= '<span class="pcp-likert-q">Efficacy:</span>';
        $h .= $likertBtns;
        $h .= '</div>';

        // BODY (always visible)
        if ( $body !== '' ) {
            $h .= '<div class="pcp-row-body pcp-problem-body">' . $body . '</div>';
        }
        $h .= '</div>';
        return $h;
    }
}
