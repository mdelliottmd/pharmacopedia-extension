<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\Context\RequestContext;

class AnecdoteTag {
    public static function render( $input, array $args, $parser, $frame ) {
        $slug = isset( $args['slug'] ) ? trim( (string)$args['slug'] ) : '';
        $perspective = isset( $args['perspective'] )
            ? trim( strtolower( (string)$args['perspective'] ) ) : 'personal';
        $author = isset( $args['author'] ) ? trim( (string)$args['author'] ) : '';

        if ( $slug === '' ) {
            return '<div class="pcp-error">&lt;anecdote&gt;: slug required</div>';
        }
        if ( !in_array( $perspective, [ 'provider', 'personal' ], true ) ) {
            $perspective = 'personal';
        }

        $normSlug = strtolower( preg_replace( '/[^a-zA-Z0-9-]+/', '-', $slug ) );
        $normSlug = trim( $normSlug, '-' );
        if ( $normSlug === '' ) {
            return '<div class="pcp-error">&lt;anecdote&gt;: invalid slug</div>';
        }

        $page = $parser->getTitle();
        if ( !$page ) { return '<div class="pcp-error">&lt;anecdote&gt;: no page context</div>'; }
        $pageId = $page->getArticleID();

        $body = trim( $parser->recursiveTagParse( (string)$input, $frame ) );

        $parser->getOutput()->updateCacheExpiry( 0 );
        $parser->getOutput()->addModules( ['ext.pharmacopedia'] );
        $parser->getOutput()->addModuleStyles( ['ext.pharmacopedia.styles'] );

        $isProvider = $perspective === 'provider';
        $badge = $isProvider
            ? '<span class="pcp-anecdote-badge pcp-anecdote-badge-provider">⚕️ Provider</span>'
            : '<span class="pcp-anecdote-badge pcp-anecdote-badge-personal">👤 Personal</span>';

        $authorHtml = '';
        if ( $author !== '' ) {
            $safeAuthor = htmlspecialchars( $author );
            $authorHtml = ' <span class="pcp-anecdote-author">by <a href="/wiki/User:' .
                $safeAuthor . '">' . $safeAuthor . '</a></span>';
        }

        // Preview mode (page not yet saved)
        if ( $pageId <= 0 ) {
            $h  = '<div class="pcp-row pcp-row-anecdote pcp-anecdote pcp-anecdote-' . htmlspecialchars( $perspective ) .
                  ' pcp-anecdote-preview" id="anecdote-' . htmlspecialchars( $normSlug ) . '">';
            $h .= '<div class="pcp-row-head">';
            $h .= '<span class="pcp-row-title">' . $badge . $authorHtml . '</span>';
            $h .= '<span class="pcp-row-aggs"><span class="pcp-anecdote-vote-placeholder">(votes appear once page is saved)</span></span>';
            $h .= '<span class="pcp-row-actions">';
            $h .= SpecialDeletePharmaElement::buttonHtml( 'anecdote', $normSlug, $author );
            $h .= '</span>';
            $h .= '</div>';
            if ( $body !== '' ) { $h .= '<div class="pcp-row-body pcp-anecdote-body">' . $body . '</div>'; }
            $h .= '</div>';
            return $h;
        }

        $voteSlug = 'anecdote-' . $normSlug;
        $store = new ElementStore();
        $element = $store->getOrCreate( $pageId, $voteSlug, 'binary', $perspective . ' anecdote' );

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
        $voteHtml .= '<span class="pcp-vote-controls" role="group" aria-label="Was this useful?">';
        $voteHtml .= '<button type="button" class="pcp-vote-btn pcp-vote-up' . $upActive . '" data-value="1" aria-label="Upvote">▲</button>';
        $voteHtml .= '<span class="pcp-vote-score' . $signClass . '" data-up="' . $up . '" data-down="' . $down . '">' . $scoreDisplay . '</span>';
        $voteHtml .= '<button type="button" class="pcp-vote-btn pcp-vote-down' . $downActive . '" data-value="-1" aria-label="Downvote">▼</button>';
        $voteHtml .= '</span>';
        $voteHtml .= '</span>';

        $h  = '<div class="pcp-row pcp-row-anecdote pcp-anecdote pcp-anecdote-' . htmlspecialchars( $perspective ) .
              '" id="anecdote-' . htmlspecialchars( $normSlug ) .
              '" data-slug="' . htmlspecialchars( $normSlug ) .
              '" data-vote-score="' . (int)$score . '">';
        $h .= '<div class="pcp-row-head">';
        $h .= '<span class="pcp-row-title">' . $badge . $authorHtml . '</span>';
        $h .= '<span class="pcp-row-aggs">' . $voteHtml . '</span>';
        $h .= '<span class="pcp-row-actions">';
        $h .= SpecialDeletePharmaElement::buttonHtml( 'anecdote', $normSlug, $author );
        $h .= '</span>';
        $h .= '</div>';
        if ( $body !== '' ) {
            $h .= '<div class="pcp-row-body pcp-anecdote-body">' . $body . '</div>';
        }
        $h .= '</div>';
        return $h;
    }
}
