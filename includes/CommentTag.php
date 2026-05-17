<?php
namespace MediaWiki\Extension\Pharmacopedia;

class CommentTag {
    public static function render( $input, array $args, $parser, $frame ) {
        $slug = isset( $args['slug'] ) ? trim( (string)$args['slug'] ) : '';
        if ( $slug === '' ) {
            return '<span class="pcp-error">&lt;discuss&gt;: slug required</span>';
        }
        $title = $parser->getTitle();
        if ( !$title ) {
            return '<span class="pcp-error">&lt;discuss&gt;: no page context</span>';
        }
        $pageId = $title->getArticleID();
        if ( $pageId <= 0 ) { return ''; }

        $elementStore = new ElementStore();
        $element = $elementStore->getOrCreate( $pageId, $slug, 'discuss', $slug );

        $parser->getOutput()->addModules( ['ext.pharmacopedia'] );
        $parser->getOutput()->addModuleStyles( ['ext.pharmacopedia.styles'] );

        return self::renderThread( (int)$element->ve_id );
    }

    public static function renderThread( $elementId ) {
        $viewerVoterHash = '';
        if ( isset( $user ) && $user && $user->isRegistered() ) {
            $store = new CommentStore();
            $viewerVoterHash = $store->voterHash( $user->getId() );
        }
        $store = new CommentStore();
        $comments = $store->getThread( $elementId );

        $html  = '<div class="pcp-discuss" data-element-id="' . $elementId . '">';
        $html .= '<div class="pcp-discuss-header">';
        $html .= '<strong>Discussion</strong>';
        $html .= ' <span class="pcp-discuss-count">(' . count( $comments ) . ')</span>';
        $html .= '</div>';

        $html .= '<div class="pcp-discuss-thread">';
        $html .= self::renderComments( $comments, null, 0, $viewerVoterHash );
        $html .= '</div>';

        // Always render BOTH; JS shows whichever is relevant for the logged-in state.
        $html .= '<form class="pcp-discuss-newform" onsubmit="return false;">';
        $html .= '<textarea class="pcp-discuss-input" placeholder="Add a comment…" rows="3"></textarea>';
        $html .= '<label class="pcp-discuss-shownameLabel"><input type="checkbox" class="pcp-discuss-showname"/> Show my username publicly (default: post as Anonymous)</label>
                <button type="button" class="pcp-discuss-submit">Post comment</button>';
        $html .= '</form>';
        $html .= '<div class="pcp-discuss-loginnotice"><em>Log in to add a comment.</em></div>';

        $html .= '</div>';
        return $html;
    }

    private static function renderComments( $comments, $parentId, $depth, $viewerVoterHash = '' ) {
        $html = '';
        foreach ( $comments as $c ) {
            if ( (int)$c->c_parent_id !== (int)$parentId ) { continue; }
            $html .= self::renderComment( $c, $depth, $viewerVoterHash );
            $html .= self::renderComments( $comments, $c->c_id, $depth + 1, $viewerVoterHash );
        }
        return $html;
    }

    private static function renderComment( $c, $depth, $viewerVoterHash = '' ) {
        $indent = min( $depth, 5 );
        $isDeleted = (int)$c->c_deleted > 0;
        $hasName = !empty( $c->c_display_name );
        $username = $hasName ? (string)$c->c_display_name : 'Anonymous';
        $ts = wfTimestamp( TS_ISO_8601, $c->c_timestamp );
        $editedNote = $c->c_edited ? ' <span class="pcp-c-edited">(edited)</span>' : '';
        $isMine = $viewerVoterHash !== '' && (string)$c->c_voter_hash === $viewerVoterHash;

        $html = '<div class="pcp-c" data-comment-id="' . (int)$c->c_id
              . '" data-author-mine="' . ( $isMine ? '1' : '0' ) . '"'
              . ' style="margin-left:' . ( $indent * 18 ) . 'px;">';
        $html .= '<div class="pcp-c-meta">';
        if ( $hasName ) {
            $html .= '<a class="pcp-c-author" href="/wiki/User:' . htmlspecialchars( $username ) . '">' . htmlspecialchars( $username ) . '</a>';
        } else {
            $html .= '<span class="pcp-c-author pcp-c-anon">' . htmlspecialchars( $username ) . '</span>';
        }
        $html .= ' <time class="pcp-c-time" datetime="' . htmlspecialchars( $ts ) . '">' . htmlspecialchars( wfTimestamp( TS_RFC2822, $c->c_timestamp ) ) . '</time>';
        $html .= $editedNote;
        $html .= '</div>';

        if ( $isDeleted ) {
            $hidden_by = (int)$c->c_deleted === 2 ? 'sysop' : 'user';
            $html .= '<div class="pcp-c-body pcp-c-deleted"><em>[Comment removed by ' . $hidden_by . ']</em></div>';
        } else {
            $html .= '<div class="pcp-c-body">' . nl2br( htmlspecialchars( $c->c_text, ENT_QUOTES ) ) . '</div>';
        }

        if ( !$isDeleted ) {
            // Always render — JS hides per-user
            $html .= '<div class="pcp-c-actions">';
            $html .= '<button type="button" class="pcp-c-reply">Reply</button>';
            $html .= '<button type="button" class="pcp-c-edit">Edit</button>';
            $html .= '<button type="button" class="pcp-c-delete">Delete</button>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}
