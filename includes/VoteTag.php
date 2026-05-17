<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\Context\RequestContext;

class VoteTag {
    public static function render( $input, array $args, $parser, $frame ) {
        $slug = isset( $args['slug'] ) ? trim( (string)$args['slug'] ) : '';
        if ( $slug === '' ) {
            return '<span class="pcp-error">&lt;vote&gt;: slug required</span>';
        }
        $title = $parser->getTitle();
        if ( !$title ) { return '<span class="pcp-error">&lt;vote&gt;: no page context</span>'; }
        $pageId = $title->getArticleID();
        if ( $pageId <= 0 ) { return $parser->recursiveTagParse( (string)$input, $frame ); }

        $rendered = $parser->recursiveTagParse( (string)$input, $frame );

        $store = new ElementStore();
        $element = $store->getOrCreate( $pageId, $slug, 'binary', trim( strip_tags( $rendered ) ) );

        $user = RequestContext::getMain()->getUser();
        $userVote = $user->isRegistered()
            ? $store->getUserVote( (int)$element->ve_id, $user->getId() )
            : 0;

        // Disable parser cache for logged-in users so each viewer's own state
        // (vote / report / etc.) renders against their session — without this,
        // MediaWiki's shared parser cache leaks one user's state into others' views.
        $parser->getOutput()->updateCacheExpiry( 0 );
        $parser->getOutput()->addModules( ['ext.pharmacopedia'] );
        $parser->getOutput()->addModuleStyles( ['ext.pharmacopedia.styles'] );

        $up = (int)$element->ve_upvotes;
        $down = (int)$element->ve_downvotes;
        $score = $up - $down;
        $upActive   = $userVote ===  1 ? ' pcp-vote-active' : '';
        $downActive = $userVote === -1 ? ' pcp-vote-active' : '';
        $signClass = $score > 0 ? ' pcp-vote-score-pos'
                    : ( $score < 0 ? ' pcp-vote-score-neg' : ' pcp-vote-score-zero' );
        $scoreDisplay = $score > 0 ? ( '+' . $score ) : (string)$score;

        $html  = '<span class="pcp-vote" data-element-id="' . (int)$element->ve_id . '">';
        $html .= '<span class="pcp-vote-content">' . $rendered . '</span>';
        $html .= '<span class="pcp-vote-controls" role="group" aria-label="Vote">';
        $html .= '<button type="button" class="pcp-vote-btn pcp-vote-up' . $upActive . '" data-value="1" aria-label="Upvote">▲</button>';
        $html .= '<span class="pcp-vote-score' . $signClass . '" data-up="' . $up . '" data-down="' . $down . '">' . $scoreDisplay . '</span>';
        $html .= '<button type="button" class="pcp-vote-btn pcp-vote-down' . $downActive . '" data-value="-1" aria-label="Downvote">▼</button>';
        $html .= '</span>';
        $html .= '</span>';
        return $html;
    }
}
