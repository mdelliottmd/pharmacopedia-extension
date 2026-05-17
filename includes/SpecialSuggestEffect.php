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

class SpecialSuggestEffect extends SpecialPage {

    public function __construct() {
        parent::__construct( 'SuggestEffect' );
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

        $labelField = trim( (string)$req->getText( 'effect_label', '' ) );
        $descField  = trim( (string)$req->getText( 'effect_description', '' ) );
        $refField   = trim( (string)$req->getText( 'effect_ref', '' ) );
        $aliasField = trim( (string)$req->getText( 'effect_aliases', '' ) );
        $wantsJson  = $req->getVal( 'format' ) === 'json';

        if ( $req->wasPosted() ) {
            if ( !$user->matchEditToken( $req->getVal( 'wpEditToken' ) ) ) {
                $this->finishError( 'Invalid session token. Please reload and try again.', $wantsJson );
                return;
            }
            if ( $refField !== '' ) {
                $store = new \MediaWiki\Extension\Pharmacopedia\GlobalEffectStore();
                $row = $store->resolve( $refField );
                if ( !$row ) {
                    $this->finishError( 'That effect no longer exists in the library.', $wantsJson );
                    return;
                }
                $this->doSubmitRef( $title, $row->e_slug, $row->e_name, $descField, $wantsJson );
                return;
            }
            if ( $labelField === '' ) {
                $this->finishError( 'Effect name is required.', $wantsJson );
                return;
            }
            if ( mb_strlen( $labelField ) > 120 ) {
                $this->finishError( 'Effect name is too long (max 120 chars).', $wantsJson );
                return;
            }
            if ( mb_strlen( $descField ) > 2000 ) {
                $this->finishError( 'Description is too long (max 2000 chars).', $wantsJson );
                return;
            }
            $this->doSubmit( $title, $labelField, $descField, $wantsJson );
            return;
        }

        $this->renderForm( $title );
    }

    private function renderForm( Title $target ) {
        $out = $this->getOutput();
        $out->setPageTitle( 'Add an effect — ' . $target->getPrefixedText() );

        $token = $this->getUser()->getEditToken();
        $action = $this->getPageTitle()->getLocalURL();

        $html  = Html::openElement( 'p' );
        $html .= 'Add an effect to ' .
            Html::element( 'a', [ 'href' => $target->getLocalURL() ], $target->getPrefixedText() ) .
            '. Your suggestion will be reviewed before going live, then users and providers can vote on whether/how often they\'ve seen it.';
        $html .= Html::closeElement( 'p' );

        $html .= Html::openElement( 'form', [ 'method' => 'POST', 'action' => $action, 'class' => 'pcp-st-form' ] );
        $html .= Html::hidden( 'wpEditToken', $token );
        $html .= Html::hidden( 'target', $target->getPrefixedText() );

        $html .= Html::openElement( 'div', [ 'class' => 'pcp-st-field' ] );
        $html .= Html::label( 'Effect name (short, e.g. "Dry cough", "Hyperkalemia")', 'pcp-se-label' );
        $html .= Html::input( 'effect_label', '', 'text', [
            'id' => 'pcp-se-label', 'maxlength' => 120, 'required' => true,
            'style' => 'width:100%; padding:0.4em;'
        ] );
        $html .= Html::closeElement( 'div' );

        $html .= Html::openElement( 'div', [ 'class' => 'pcp-st-field' ] );
        $html .= Html::label( 'Description (optional — what is this effect, when does it occur)', 'pcp-se-desc' );
        $html .= Html::textarea( 'effect_description', '', [
            'id' => 'pcp-se-desc', 'rows' => 5,
            'placeholder' => "e.g. Persistent non-productive cough, typically appearing within weeks of starting therapy. Resolves on discontinuation.",
            'style' => 'width:100%; padding:0.4em; font-family:inherit;'
        ] );
        $html .= Html::closeElement( 'div' );

        $html .= Html::submitButton( 'Submit for review', [ 'class' => 'mw-ui-button mw-ui-progressive' ] );
        $html .= Html::closeElement( 'form' );

        $out->addHTML( $html );
    }

    private function doSubmit( Title $target, $label, $description, $wantsJson ) {
        $page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $target );
        $content = $page->getContent();
        if ( !$content ) {
            $this->finishError( 'Could not load page content.', $wantsJson );
            return;
        }
        $wt = $content instanceof \MediaWiki\Content\TextContent
            ? $content->getText()
            : ( method_exists( $content, 'getText' ) ? $content->getText() : (string)$content );

        $globalStore = new \MediaWiki\Extension\Pharmacopedia\GlobalEffectStore();
        $newSlug = \MediaWiki\Extension\Pharmacopedia\GlobalEffectStore::normalizeSlug( $label );
        $aliasField = trim( (string)$this->getRequest()->getText( 'effect_aliases', '' ) );
        $globalStore->create( $newSlug, $label, $description, $aliasField, $this->getUser()->getId() );
        $slug = $newSlug;

        $author = $this->getUser()->getName();
        $safeAuthor = htmlspecialchars( $author, ENT_QUOTES );
        $bodyInner = trim( $description );
        if ( $bodyInner === '' ) {
            $block = "<effect ref=\"$slug\" author=\"$safeAuthor\"/>";
        } else {
            $block = "<effect ref=\"$slug\" author=\"$safeAuthor\">\n" . $bodyInner . "\n</effect>";
        }

        $newWt = TemplateParamEditor::insertIntoMedTemplateParam( $wt, 'effects', $block );

        $newContent = ContentHandler::makeContent( $newWt, $target );

        $updater = $page->newPageUpdater( $this->getUser() );
        $updater->setContent( SlotRecord::MAIN, $newContent );
        $summary = CommentStoreComment::newUnsavedComment(
            'Proposed effect: ' . $label
        );
        $updater->saveRevision( $summary, EDIT_UPDATE );

        $status = $updater->getStatus();
        if ( !$status || !$status->isOK() ) {
            $msg = $status ? $status->getWikiText( false, false, 'en' ) : 'Save failed.';
            $this->finishError( 'Save failed: ' . $msg, $wantsJson );
            return;
        }

        $redirectUrl = $target->getLocalURL() . '#effect-' . $slug;

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

    private static function ensureEffectSlugUnique( $slug, $wt ) {
        $candidate = $slug;
        $i = 2;
        while ( preg_match( '/<effect[^>]*\bslug\s*=\s*"' . preg_quote( $candidate, '/' ) . '"/i', $wt ) ) {
            $candidate = $slug . '-' . $i;
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

    private function doSubmitRef( $target, $refSlug, $refName, $description, $wantsJson ) {
        $pageFactory = \MediaWiki\MediaWikiServices::getInstance()->getWikiPageFactory();
        $page = $pageFactory->newFromTitle( $target );
        $content = $page->getContent();
        if ( !$content ) { $this->finishError( 'Could not load page content.', $wantsJson ); return; }
        $wt = method_exists( $content, 'getText' ) ? $content->getText() : (string)$content;

        $author = $this->getUser()->getName();
        $safeAuthor = htmlspecialchars( $author, ENT_QUOTES );
        $bodyInner = trim( $description );
        if ( $bodyInner === '' ) {
            $block = "<effect ref=\"$refSlug\" author=\"$safeAuthor\"/>";
        } else {
            $block = "<effect ref=\"$refSlug\" author=\"$safeAuthor\">\n" . $bodyInner . "\n</effect>";
        }
        $newWt = \MediaWiki\Extension\Pharmacopedia\TemplateParamEditor::insertIntoMedTemplateParam( $wt, 'effects', $block );
        $newContent = \ContentHandler::makeContent( $newWt, $target );
        $updater = $page->newPageUpdater( $this->getUser() );
        $updater->setContent( \MediaWiki\Revision\SlotRecord::MAIN, $newContent );
        $summary = \CommentStoreComment::newUnsavedComment( 'Added effect: ' . $refName );
        $updater->saveRevision( $summary, EDIT_UPDATE );
        $status = $updater->getStatus();
        if ( !$status || !$status->isOK() ) {
            $msg = $status ? $status->getWikiText( false, false, 'en' ) : 'Save failed.';
            $this->finishError( 'Save failed: ' . $msg, $wantsJson ); return;
        }
        $redirectUrl = $target->getLocalURL() . '#effect-ref-' . $refSlug;
        if ( $wantsJson ) {
            $this->getOutput()->disable();
            header( 'Content-Type: application/json' );
            echo json_encode( [ 'ok' => true, 'slug' => $refSlug, 'redirect' => $redirectUrl ] );
            return;
        }
        $this->getOutput()->redirect( $redirectUrl );
    }
}
