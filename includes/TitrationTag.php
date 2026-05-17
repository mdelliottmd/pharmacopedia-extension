<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\Context\RequestContext;

class TitrationTag {
    public static function render( $input, array $args, $parser, $frame ) {
        $slug  = isset( $args['slug'] )  ? trim( (string)$args['slug'] )  : '';
        $title = isset( $args['title'] ) ? trim( (string)$args['title'] ) : '';
        $author = isset( $args['author'] ) ? trim( (string)$args['author'] ) : '';
        if ( $slug === '' )  { return '<div class="pcp-error">&lt;titration&gt;: slug required</div>'; }
        if ( $title === '' ) { return '<div class="pcp-error">&lt;titration&gt;: title required</div>'; }

        // Normalize slug: lowercase, hyphenated, alnum + hyphen only
        $normSlug = strtolower( preg_replace( '/[^a-zA-Z0-9-]+/', '-', $slug ) );
        $normSlug = trim( $normSlug, '-' );
        if ( $normSlug === '' ) {
            return '<div class="pcp-error">&lt;titration&gt;: invalid slug</div>';
        }

        $page = $parser->getTitle();
        if ( !$page ) { return '<div class="pcp-error">&lt;titration&gt;: no page context</div>'; }
        $pageId = $page->getArticleID();

        $body = trim( $parser->recursiveTagParse( (string)$input, $frame ) );

        // Disable parser cache for logged-in users so each viewer's own state
        // (vote / report / etc.) renders against their session — without this,
        // MediaWiki's shared parser cache leaks one user's state into others' views.
        $parser->getOutput()->updateCacheExpiry( 0 );
        $parser->getOutput()->addModules( ['ext.pharmacopedia'] );
        $parser->getOutput()->addModuleStyles( ['ext.pharmacopedia.styles'] );

        // If page not yet saved, render preview without persistence.
        if ( $pageId <= 0 ) {
            $html  = '<div class="pcp-titration pcp-titration-preview" id="titration-' . htmlspecialchars( $normSlug ) . '">';
            $html .= '<div class="pcp-titration-head">';
            $html .= '<span class="pcp-titration-title">' . htmlspecialchars( $title ) . '</span>';
            $html .= '<span class="pcp-titration-vote-placeholder">(votes appear once page is saved)</span>';
            $html .= SpecialDeletePharmaElement::buttonHtml( 'titration', $normSlug, $author );
            $html .= '</div>';
            $html .= '<div class="pcp-titration-body">' . $body . '</div>';
            $html .= '</div>';
            return $html;
        }

        // Persist a binary votable element keyed on titration-{slug}
        $voteSlug = 'titration-' . $normSlug;
        $store = new ElementStore();
        $element = $store->getOrCreate( $pageId, $voteSlug, 'binary', $title );

        $user = RequestContext::getMain()->getUser();
        $userVote = $user->isRegistered()
            ? $store->getUserVote( (int)$element->ve_id, $user->getId() )
            : 0;

        $up = (int)$element->ve_upvotes;
        $down = (int)$element->ve_downvotes;
        $score = $up - $down;
        $upActive   = $userVote ===  1 ? ' pcp-vote-active' : '';
        $downActive = $userVote === -1 ? ' pcp-vote-active' : '';
        $signClass = $score > 0 ? ' pcp-vote-score-pos'
                    : ( $score < 0 ? ' pcp-vote-score-neg' : ' pcp-vote-score-zero' );
        $scoreDisplay = $score > 0 ? ( '+' . $score ) : (string)$score;

        $voteHtml  = '<span class="pcp-vote" data-element-id="' . (int)$element->ve_id . '">';
        $voteHtml .= '<span class="pcp-vote-controls" role="group" aria-label="Vote on titration strategy">';
        $voteHtml .= '<button type="button" class="pcp-vote-btn pcp-vote-up' . $upActive . '" data-value="1" aria-label="Upvote">▲</button>';
        $voteHtml .= '<span class="pcp-vote-score' . $signClass . '" data-up="' . $up . '" data-down="' . $down . '">' . $scoreDisplay . '</span>';
        $voteHtml .= '<button type="button" class="pcp-vote-btn pcp-vote-down' . $downActive . '" data-value="-1" aria-label="Downvote">▼</button>';
        $voteHtml .= '</span>';
        $voteHtml .= '</span>';

        $html  = '<div class="pcp-titration" id="titration-' . htmlspecialchars( $normSlug ) .
            '" data-slug="' . htmlspecialchars( $normSlug ) .
            '" data-vote-score="' . (int)$score . '">';
        $html .= '<div class="pcp-titration-head">';
        $html .= '<span class="pcp-titration-title">' . htmlspecialchars( $title ) . '</span>';
        $html .= $voteHtml;
        $html .= SpecialDeletePharmaElement::buttonHtml( 'titration', $normSlug, $author );
        $html .= '</div>';
        $html .= '<div class="pcp-titration-body">' . $body . '</div>';
        $html .= '</div>';
        return $html;
    }
}
