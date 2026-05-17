<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;
use MediaWiki\Page\WikiPage;
use MediaWiki\Content\ContentHandler;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\MediaWikiServices;

class SpecialSuggestAnecdote extends SpecialPage {

    public function __construct() {
        parent::__construct( 'SuggestAnecdote' );
    }

    public function doesWrites() { return true; }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $req = $this->getRequest();
        $user = $this->getUser();

        if ( !$user->isRegistered() ) {
            $out->showErrorPage( 'pharmacopedia-login-required-title',
                'pharmacopedia-login-required' );
            return;
        }
        if ( !$user->isAllowed( 'edit' ) ) {
            throw new \PermissionsError( 'edit' );
        }

        $targetText = $req->getVal( 'target', $par ?? '' );
        $targetText = trim( (string)$targetText );
        if ( $targetText === '' ) {
            $this->renderError( 'No target medicine page specified.' );
            return;
        }
        $title = Title::newFromText( $targetText );
        if ( !$title || !$title->exists() || !$title->inNamespace( NS_MAIN ) ) {
            $this->renderError( 'Target page does not exist.' );
            return;
        }

        $bodyField = trim( (string)$req->getText( 'anecdote_body', '' ) );
        $perspectiveField = trim( strtolower( (string)$req->getText( 'anecdote_perspective', 'personal' ) ) );
        $wantsJson = $req->getVal( 'format' ) === 'json';

        if ( $req->wasPosted() ) {
            if ( !$user->matchEditToken( $req->getVal( 'wpEditToken' ) ) ) {
                $this->finishError( 'Invalid session token. Please reload and try again.', $wantsJson );
                return;
            }
            if ( $bodyField === '' ) {
                $this->finishError( 'Anecdote body is required.', $wantsJson );
                return;
            }
            if ( mb_strlen( $bodyField ) > 5000 ) {
                $this->finishError( 'Anecdote is too long (max 5000 chars).', $wantsJson );
                return;
            }
            if ( !in_array( $perspectiveField, [ 'personal', 'provider' ], true ) ) {
                $perspectiveField = 'personal';
            }
            // Server-side enforcement: only providers (or admin/sysop) can submit provider perspective
            if ( $perspectiveField === 'provider' &&
                 !$user->isAllowed( 'pharmacopedia-effect-as-provider' ) ) {
                $this->finishError(
                    'Only verified providers can submit provider-perspective anecdotes.',
                    $wantsJson );
                return;
            }
            $this->doSubmit( $title, $bodyField, $perspectiveField, $wantsJson );
            return;
        }

        $this->renderForm( $title );
    }

    private function renderForm( Title $target ) {
        $out = $this->getOutput();
        $out->setPageTitle( 'Add an anecdote — ' . $target->getPrefixedText() );

        $token = $this->getUser()->getEditToken();
        $action = $this->getPageTitle()->getLocalURL();
        $isProvider = $this->getUser()->isAllowed( 'pharmacopedia-effect-as-provider' );

        $html  = Html::openElement( 'p' );
        $html .= 'Add an anecdote to ' .
            Html::element( 'a', [ 'href' => $target->getLocalURL() ], $target->getPrefixedText() ) .
            '. Your suggestion will be reviewed before going live.';
        $html .= Html::closeElement( 'p' );

        $html .= Html::openElement( 'form', [ 'method' => 'POST', 'action' => $action, 'class' => 'pcp-st-form' ] );
        $html .= Html::hidden( 'wpEditToken', $token );
        $html .= Html::hidden( 'target', $target->getPrefixedText() );

        if ( $isProvider ) {
            $html .= Html::openElement( 'div', [ 'class' => 'pcp-st-field' ] );
            $html .= '<strong>Perspective:</strong> ';
            $html .= '<label style="margin-right:1em;"><input type="radio" name="anecdote_perspective" value="personal" checked> 👤 Personal</label>';
            $html .= '<label><input type="radio" name="anecdote_perspective" value="provider"> ⚕️ Provider</label>';
            $html .= Html::closeElement( 'div' );
        } else {
            $html .= Html::hidden( 'anecdote_perspective', 'personal' );
        }

        $html .= Html::openElement( 'div', [ 'class' => 'pcp-st-field' ] );
        $html .= Html::label( 'Anecdote (wikitext allowed)', 'pcp-sa-body' );
        $html .= Html::textarea( 'anecdote_body', '', [
            'id' => 'pcp-sa-body', 'rows' => 8, 'required' => true,
            'placeholder' => "Share the relevant story or clinical observation.",
            'style' => 'width:100%; padding:0.4em; font-family:inherit;'
        ] );
        $html .= Html::closeElement( 'div' );

        $html .= Html::submitButton( 'Submit for review', [ 'class' => 'mw-ui-button mw-ui-progressive' ] );
        $html .= Html::closeElement( 'form' );

        $out->addHTML( $html );
    }

    private function doSubmit( Title $target, $body, $perspective, $wantsJson ) {
        $page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $target );
        $content = $page->getContent();
        if ( !$content ) {
            $this->finishError( 'Could not load page content.', $wantsJson );
            return;
        }
        $wt = $content instanceof \MediaWiki\Content\TextContent
            ? $content->getText()
            : ( method_exists( $content, 'getText' ) ? $content->getText() : (string)$content );

        $base = gmdate( 'Y-m-d' );
        $slug = $base;
        $i = 2;
        while ( preg_match( '/<anecdote[^>]*\bslug\s*=\s*"' . preg_quote( $slug, '/' ) . '"/i', $wt ) ) {
            $slug = $base . '-' . $i;
            $i++;
            if ( $i > 1000 ) { break; }
        }

        $author = $this->getUser()->getName();
        $safeAuthor = str_replace( '"', '"', $author );
        $block = "<anecdote slug=\"$slug\" perspective=\"$perspective\" author=\"$safeAuthor\">\n" .
            rtrim( $body ) . "\n</anecdote>";

        $newWt = TemplateParamEditor::insertIntoMedTemplateParam( $wt, 'anecdotes', $block );

        $newContent = ContentHandler::makeContent( $newWt, $target );

        $updater = $page->newPageUpdater( $this->getUser() );
        $updater->setContent( SlotRecord::MAIN, $newContent );
        $summary = CommentStoreComment::newUnsavedComment(
            'Proposed anecdote (' . $perspective . ')'
        );
        $updater->saveRevision( $summary, EDIT_UPDATE );

        $status = $updater->getStatus();
        if ( !$status || !$status->isOK() ) {
            $msg = $status ? $status->getWikiText( false, false, 'en' ) : 'Save failed.';
            $this->finishError( 'Save failed: ' . $msg, $wantsJson );
            return;
        }

        // Auto-upvote own submission (+1 from the author)
        try {
            $elementStore = new ElementStore();
            $pageId = $target->getArticleID();
            if ( $pageId > 0 ) {
                $element = $elementStore->getOrCreate( $pageId, 'anecdote-' . $slug, 'binary', $perspective . ' anecdote' );
                if ( $element ) {
                    $elementStore->castVote( (int)$element->ve_id, $this->getUser()->getId(), 1 );
                }
            }
        } catch ( \Throwable $e ) {
            wfDebugLog( 'pharmacopedia', 'Auto-upvote failed (anecdote): ' . $e->getMessage() );
        }

        $redirectUrl = $target->getLocalURL() . '#anecdote-' . $slug;

        if ( $wantsJson ) {
            $this->getOutput()->disable();
            header( 'Content-Type: application/json' );
            echo json_encode( [
                'ok' => true,
                'slug' => $slug,
                'redirect' => $redirectUrl
            ] );
            return;
        }

        $this->getOutput()->redirect( $redirectUrl );
    }

    private function renderError( $msg ) {
        $this->getOutput()->addHTML(
            Html::element( 'div', [ 'class' => 'errorbox' ], $msg )
        );
    }

    private function finishError( $msg, $wantsJson ) {
        if ( $wantsJson ) {
            $this->getOutput()->disable();
            header( 'Content-Type: application/json', true, 400 );
            echo json_encode( [ 'ok' => false, 'error' => $msg ] );
            return;
        }
        $this->renderError( $msg );
        $req = $this->getRequest();
        $target = Title::newFromText( $req->getVal( 'target', '' ) );
        if ( $target ) {
            $this->renderForm( $target );
        }
    }
}
