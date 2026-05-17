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

class SpecialSuggestTitration extends SpecialPage {

    public function __construct() {
        parent::__construct( 'SuggestTitration' );
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

        $titleField = trim( (string)$req->getText( 'titration_title', '' ) );
        $bodyField  = trim( (string)$req->getText( 'titration_body', '' ) );
        $wantsJson  = $req->getVal( 'format' ) === 'json';

        if ( $req->wasPosted() ) {
            if ( !$user->matchEditToken( $req->getVal( 'wpEditToken' ) ) ) {
                $this->finishError( 'Invalid session token. Please reload and try again.', $wantsJson );
                return;
            }
            if ( $titleField === '' ) {
                $this->finishError( 'Title is required.', $wantsJson );
                return;
            }
            if ( $bodyField === '' ) {
                $this->finishError( 'Body is required.', $wantsJson );
                return;
            }
            if ( mb_strlen( $titleField ) > 200 ) {
                $this->finishError( 'Title is too long (max 200 chars).', $wantsJson );
                return;
            }
            if ( mb_strlen( $bodyField ) > 5000 ) {
                $this->finishError( 'Body is too long (max 5000 chars).', $wantsJson );
                return;
            }
            $this->doSubmit( $title, $titleField, $bodyField, $wantsJson );
            return;
        }

        $this->renderForm( $title );
    }

    private function renderForm( Title $target ) {
        $out = $this->getOutput();
        $out->setPageTitle( 'Add a titration strategy — ' . $target->getPrefixedText() );

        $token = $this->getUser()->getEditToken();
        $action = $this->getPageTitle()->getLocalURL();

        $html  = Html::openElement( 'p' );
        $html .= 'Add a titration strategy to ' .
            Html::element( 'a', [ 'href' => $target->getLocalURL() ], $target->getPrefixedText() ) .
            '. Your suggestion will be reviewed before going live.';
        $html .= Html::closeElement( 'p' );

        $html .= Html::openElement( 'form', [ 'method' => 'POST', 'action' => $action, 'class' => 'pcp-st-form' ] );
        $html .= Html::hidden( 'wpEditToken', $token );
        $html .= Html::hidden( 'target', $target->getPrefixedText() );

        $html .= Html::openElement( 'div', [ 'class' => 'pcp-st-field' ] );
        $html .= Html::label( 'Title (short, e.g. "Slow start (elderly)")', 'pcp-st-title' );
        $html .= Html::input( 'titration_title', '', 'text', [
            'id' => 'pcp-st-title', 'maxlength' => 200, 'required' => true,
            'style' => 'width:100%; padding:0.4em;'
        ] );
        $html .= Html::closeElement( 'div' );

        $html .= Html::openElement( 'div', [ 'class' => 'pcp-st-field' ] );
        $html .= Html::label( 'Body (regimen, monitoring, who it\'s for — wikitext allowed)', 'pcp-st-body' );
        $html .= Html::textarea( 'titration_body', '', [
            'id' => 'pcp-st-body', 'rows' => 8, 'required' => true,
            'placeholder' => "Starting dose: ...\nEscalation: ...\nMonitoring: ...\nBest suited for: ...\n\nCite sources with <ref>...</ref>",
            'style' => 'width:100%; padding:0.4em; font-family:inherit;'
        ] );
        $html .= Html::closeElement( 'div' );

        $html .= Html::submitButton( 'Submit for review', [ 'class' => 'mw-ui-button mw-ui-progressive' ] );
        $html .= Html::closeElement( 'form' );

        $out->addHTML( $html );
    }

    private function doSubmit( Title $target, $titrationTitle, $body, $wantsJson ) {
        $page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $target );
        $content = $page->getContent();
        if ( !$content ) {
            $this->finishError( 'Could not load page content.', $wantsJson );
            return;
        }
        $wt = $content instanceof \MediaWiki\Content\TextContent
            ? $content->getText()
            : ( method_exists( $content, 'getText' ) ? $content->getText() : (string)$content );

        $slug = self::generateUniqueSlug( $titrationTitle, $wt );

        $author = $this->getUser()->getName();
        $safeAuthor = htmlspecialchars( $author, ENT_QUOTES );
        $block = "<titration slug=\"$slug\" title=\"" .
            htmlspecialchars( $titrationTitle, ENT_QUOTES ) . "\" author=\"$safeAuthor\">\n" .
            rtrim( $body ) . "\n</titration>";

        $newWt = TemplateParamEditor::insertIntoMedTemplateParam( $wt, 'dosing', $block );

        $newContent = ContentHandler::makeContent( $newWt, $target );

        $updater = $page->newPageUpdater( $this->getUser() );
        $updater->setContent( SlotRecord::MAIN, $newContent );
        $summary = CommentStoreComment::newUnsavedComment(
            'Proposed titration strategy: ' . $titrationTitle
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
                $element = $elementStore->getOrCreate( $pageId, 'titration-' . $slug, 'binary', $titrationTitle );
                if ( $element ) {
                    $elementStore->castVote( (int)$element->ve_id, $this->getUser()->getId(), 1 );
                }
            }
        } catch ( \Throwable $e ) {
            wfDebugLog( 'pharmacopedia', 'Auto-upvote failed (titration): ' . $e->getMessage() );
        }

        $redirectUrl = $target->getLocalURL() . '#titration-' . $slug;

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

    public static function generateUniqueSlug( $title, $pageWikitext ) {
        $base = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', $title ) );
        $base = trim( $base, '-' );
        if ( $base === '' ) { $base = 'strategy'; }
        $candidate = $base;
        $i = 2;
        while ( preg_match( '/<titration[^>]*\bslug\s*=\s*"' . preg_quote( $candidate, '/' ) . '"/i', $pageWikitext ) ) {
            $candidate = $base . '-' . $i;
            $i++;
            if ( $i > 1000 ) { break; }
        }
        return $candidate;
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
