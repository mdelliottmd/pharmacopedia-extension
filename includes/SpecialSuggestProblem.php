<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;
use MediaWiki\Content\ContentHandler;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\MediaWikiServices;

/**
 * Special:SuggestProblem — two modes:
 *
 *   1. WITH target (Special:SuggestProblem/<MedPage>): proposes a Problem for
 *      a specific medicine page. Creates the Problem in the repo AND inserts a
 *      <problem ref="..."> tag into the target page's `indications` template
 *      parameter.
 *
 *   2. NO TARGET (Special:SuggestProblem with no subpage / blank target):
 *      standalone "add a Problem to the repo" form. Creates the Problem row
 *      only; no wikitext edit. After save, redirects to Special:Problem/<slug>.
 *
 * Auto-approved on submit; sysop can merge/retire later via
 * Special:ManageProblems.
 */
class SpecialSuggestProblem extends SpecialPage {

    public function __construct() {
        parent::__construct( 'SuggestProblem' );
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

        $targetText = trim( (string)$req->getVal( 'target', $par ?? '' ) );
        $standalone = ( $targetText === '' );

        $title = null;
        if ( !$standalone ) {
            $title = Title::newFromText( $targetText );
            if ( !$title || !$title->exists() || !$title->inNamespace( NS_MAIN ) ) {
                $this->renderError( 'Target page does not exist.' );
                return;
            }
        }

        $titleField = trim( (string)$req->getText( 'problem_title', $req->getText( 'prefill', '' ) ) );
        $bodyField  = trim( (string)$req->getText( 'problem_body', '' ) );
        $refField   = trim( (string)$req->getText( 'problem_ref', '' ) );
        $aliasField = trim( (string)$req->getText( 'problem_aliases', '' ) );
        $catField   = trim( (string)$req->getText( 'problem_category', '' ) );
        $descField  = trim( (string)$req->getText( 'problem_description', '' ) );
        $wantsJson  = $req->getVal( 'format' ) === 'json';

        if ( $req->wasPosted() ) {
            if ( !$user->matchEditToken( $req->getVal( 'wpEditToken' ) ) ) {
                $this->finishError( 'Invalid session token. Please reload and try again.', $wantsJson );
                return;
            }
            $store = new ProblemStore();
            if ( !$standalone && $refField !== '' ) {
                $row = $store->resolve( $refField );
                if ( !$row ) {
                    $this->finishError( 'That Problem no longer exists in the repository.', $wantsJson );
                    return;
                }
                $this->doSubmitRef( $title, (string)$row->p_slug, (string)$row->p_name, $bodyField, $wantsJson );
                return;
            }
            if ( $titleField === '' ) {
                $this->finishError( 'Title is required.', $wantsJson );
                return;
            }
            if ( mb_strlen( $titleField ) > 200 ) {
                $this->finishError( 'Title is too long (max 200 chars).', $wantsJson );
                return;
            }
            if ( $standalone ) {
                if ( mb_strlen( $descField ) > 5000 ) {
                    $this->finishError( 'Description is too long (max 5000 chars).', $wantsJson );
                    return;
                }
                $this->doSubmitStandalone( $titleField, $descField, $aliasField, $catField, $wantsJson );
                return;
            }
            if ( mb_strlen( $bodyField ) > 5000 ) {
                $this->finishError( 'Body is too long (max 5000 chars).', $wantsJson );
                return;
            }
            $this->doSubmit( $title, $titleField, $bodyField, $aliasField, $catField, $wantsJson );
            return;
        }

        if ( $standalone ) {
            $this->renderStandaloneForm( $titleField );
        } else {
            $this->renderForm( $title );
        }
    }

    // --- Standalone (no-target) form ---

    private function renderStandaloneForm( $prefill = '' ) {
        $out = $this->getOutput();
        $out->setPageTitle( 'Add a Problem to the repository' );

        $token = $this->getUser()->getEditToken();
        $action = $this->getPageTitle()->getLocalURL();
        $store = new ProblemStore();
        $cats  = array_keys( $store->listCategories() );

        $html  = '<p>Add a Problem to the [[Special:Problems|repository]] without anchoring it to a specific medicine. ' .
            'A Problem is anything a medicine is used FOR — a diagnosis, symptom, functional state, or lab target. ' .
            'Auto-approved on submission; a sysop may later merge duplicates into canonical entries via [[Special:ManageProblems]].</p>';
        $html = $out->parseAsContent( $html );

        $html .= Html::openElement( 'form', [ 'method' => 'POST', 'action' => $action, 'class' => 'pcp-st-form' ] );
        $html .= Html::hidden( 'wpEditToken', $token );

        $html .= Html::openElement( 'div', [ 'class' => 'pcp-st-field' ] );
        $html .= Html::label( 'Problem name (e.g. "Restless legs syndrome", "Heartburn")', 'pcp-sp-title' );
        $html .= Html::input( 'problem_title', $prefill, 'text', [
            'id' => 'pcp-sp-title', 'maxlength' => 200, 'required' => true,
            'style' => 'width:100%; padding:0.4em;'
        ] );
        $html .= Html::closeElement( 'div' );

        $html .= Html::openElement( 'div', [ 'class' => 'pcp-st-field' ] );
        $html .= Html::label( 'Category (optional)', 'pcp-sp-cat' );
        $html .= Html::openElement( 'select', [
            'name' => 'problem_category', 'id' => 'pcp-sp-cat',
            'style' => 'width:100%; padding:0.4em;'
        ] );
        $html .= Html::element( 'option', [ 'value' => '' ], '— uncategorized —' );
        foreach ( $cats as $c ) {
            $html .= Html::element( 'option', [ 'value' => $c ], $c );
        }
        $html .= Html::closeElement( 'select' );
        $html .= Html::closeElement( 'div' );

        $html .= Html::openElement( 'div', [ 'class' => 'pcp-st-field' ] );
        $html .= Html::label( 'Aliases (comma-separated; e.g. "RLS, Willis-Ekbom disease")', 'pcp-sp-alias' );
        $html .= Html::input( 'problem_aliases', '', 'text', [
            'id' => 'pcp-sp-alias',
            'style' => 'width:100%; padding:0.4em;'
        ] );
        $html .= Html::closeElement( 'div' );

        $html .= Html::openElement( 'div', [ 'class' => 'pcp-st-field' ] );
        $html .= Html::label( 'Description (optional — one or two sentences describing this Problem)', 'pcp-sp-desc' );
        $html .= Html::textarea( 'problem_description', '', [
            'id' => 'pcp-sp-desc', 'rows' => 4,
            'placeholder' => "What this Problem is. Plain prose; cite if helpful.",
            'style' => 'width:100%; padding:0.4em; font-family:inherit;'
        ] );
        $html .= Html::closeElement( 'div' );

        $html .= Html::submitButton( 'Add to repository', [ 'class' => 'mw-ui-button mw-ui-progressive' ] );
        $html .= Html::closeElement( 'form' );

        $out->addHTML( $html );
    }

    private function doSubmitStandalone( $name, $desc, $aliases, $category, $wantsJson ) {
        $store = new ProblemStore();
        $slug = ProblemStore::normalizeSlug( $name );
        $existing = $store->getBySlug( $slug );
        if ( $existing ) {
            // Already exists — redirect to its Problem page
            $redirectUrl = SpecialPage::getTitleFor( 'Problem', $slug )->getLocalURL();
            if ( $wantsJson ) {
                $this->getOutput()->disable();
                header( 'Content-Type: application/json' );
                echo json_encode( [ 'ok' => true, 'slug' => $slug, 'redirect' => $redirectUrl, 'preexisting' => true ] );
                return;
            }
            $this->getOutput()->redirect( $redirectUrl );
            return;
        }
        $store->create( $slug, $name, $desc, $aliases, $this->getUser()->getId(),
            $category !== '' ? $category : null );
        $redirectUrl = SpecialPage::getTitleFor( 'Problem', $slug )->getLocalURL();
        if ( $wantsJson ) {
            $this->getOutput()->disable();
            header( 'Content-Type: application/json' );
            echo json_encode( [ 'ok' => true, 'slug' => $slug, 'redirect' => $redirectUrl, 'preexisting' => false ] );
            return;
        }
        $this->getOutput()->redirect( $redirectUrl );
    }

    // --- Target-mode form (unchanged from v1) ---

    private function renderForm( Title $target ) {
        $out = $this->getOutput();
        $out->setPageTitle( 'Add a Problem — ' . $target->getPrefixedText() );

        $token = $this->getUser()->getEditToken();
        $action = $this->getPageTitle()->getLocalURL();
        $store = new ProblemStore();
        $cats  = array_keys( $store->listCategories() );

        $html  = Html::openElement( 'p' );
        $html .= 'Add a Problem (something this medicine is used to address) to ' .
            Html::element( 'a', [ 'href' => $target->getLocalURL() ], $target->getPrefixedText() ) .
            '. Anyone can then rate efficacy 0–5. Problems are auto-approved on submission; ' .
            'a sysop may later merge duplicates into canonical entries.';
        $html .= Html::closeElement( 'p' );

        $html .= Html::openElement( 'form', [ 'method' => 'POST', 'action' => $action, 'class' => 'pcp-st-form' ] );
        $html .= Html::hidden( 'wpEditToken', $token );
        $html .= Html::hidden( 'target', $target->getPrefixedText() );

        $html .= Html::openElement( 'div', [ 'class' => 'pcp-st-field' ] );
        $html .= Html::label( 'Problem name (e.g. "Treatment-resistant depression", "Cluster headache")', 'pcp-sp-title' );
        $html .= Html::input( 'problem_title', '', 'text', [
            'id' => 'pcp-sp-title', 'maxlength' => 200, 'required' => true,
            'style' => 'width:100%; padding:0.4em;'
        ] );
        $html .= Html::closeElement( 'div' );

        $html .= Html::openElement( 'div', [ 'class' => 'pcp-st-field' ] );
        $html .= Html::label( 'Category (optional)', 'pcp-sp-cat' );
        $html .= Html::openElement( 'select', [
            'name' => 'problem_category', 'id' => 'pcp-sp-cat',
            'style' => 'width:100%; padding:0.4em;'
        ] );
        $html .= Html::element( 'option', [ 'value' => '' ], '— uncategorized —' );
        foreach ( $cats as $c ) {
            $html .= Html::element( 'option', [ 'value' => $c ], $c );
        }
        $html .= Html::closeElement( 'select' );
        $html .= Html::closeElement( 'div' );

        $html .= Html::openElement( 'div', [ 'class' => 'pcp-st-field' ] );
        $html .= Html::label( 'Aliases (comma-separated; e.g. "TRD, treatment-resistant MDD")', 'pcp-sp-alias' );
        $html .= Html::input( 'problem_aliases', '', 'text', [
            'id' => 'pcp-sp-alias',
            'style' => 'width:100%; padding:0.4em;'
        ] );
        $html .= Html::closeElement( 'div' );

        $html .= Html::openElement( 'div', [ 'class' => 'pcp-st-field' ] );
        $html .= Html::label( 'Body — context for this med specifically (clinical context, evidence — wikitext allowed)', 'pcp-sp-body' );
        $html .= Html::textarea( 'problem_body', '', [
            'id' => 'pcp-sp-body', 'rows' => 6,
            'placeholder' => "Population, severity, evidence — cite with <ref>...</ref>",
            'style' => 'width:100%; padding:0.4em; font-family:inherit;'
        ] );
        $html .= Html::closeElement( 'div' );

        $html .= Html::submitButton( 'Add Problem', [ 'class' => 'mw-ui-button mw-ui-progressive' ] );
        $html .= Html::closeElement( 'form' );

        $out->addHTML( $html );
    }

    private function doSubmit( Title $target, $problemTitle, $body, $aliases, $category, $wantsJson ) {
        $pageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
        $page = $pageFactory->newFromTitle( $target );
        $content = $page->getContent();
        if ( !$content ) {
            $this->finishError( 'Could not load page content.', $wantsJson );
            return;
        }
        $wt = method_exists( $content, 'getText' ) ? $content->getText() : (string)$content;

        $store = new ProblemStore();
        $newSlug = ProblemStore::normalizeSlug( $problemTitle );
        $store->create( $newSlug, $problemTitle, $body, $aliases, $this->getUser()->getId(),
            $category !== '' ? $category : null );

        $author = $this->getUser()->getName();
        $safeAuthor = htmlspecialchars( $author, ENT_QUOTES );
        if ( trim( $body ) === '' ) {
            $block = "<problem ref=\"$newSlug\" author=\"$safeAuthor\"/>";
        } else {
            $block = "<problem ref=\"$newSlug\" author=\"$safeAuthor\">\n" .
                rtrim( $body ) . "\n</problem>";
        }

        $newWt = TemplateParamEditor::insertIntoMedTemplateParam( $wt, 'indications', $block );

        $newContent = ContentHandler::makeContent( $newWt, $target );
        $updater = $page->newPageUpdater( $this->getUser() );
        $updater->setContent( SlotRecord::MAIN, $newContent );
        $summary = CommentStoreComment::newUnsavedComment( 'Added Problem: ' . $problemTitle );
        $updater->saveRevision( $summary, EDIT_UPDATE );
        $status = $updater->getStatus();
        if ( !$status || !$status->isOK() ) {
            $msg = $status ? $status->getWikiText( false, false, 'en' ) : 'Save failed.';
            $this->finishError( 'Save failed: ' . $msg, $wantsJson );
            return;
        }

        $redirectUrl = $target->getLocalURL() . '#problem-' . $newSlug;
        if ( $wantsJson ) {
            $this->getOutput()->disable();
            header( 'Content-Type: application/json' );
            echo json_encode( [ 'ok' => true, 'slug' => $newSlug, 'redirect' => $redirectUrl ] );
            return;
        }
        $this->getOutput()->redirect( $redirectUrl );
    }

    private function doSubmitRef( Title $target, $refSlug, $refName, $body, $wantsJson ) {
        $pageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
        $page = $pageFactory->newFromTitle( $target );
        $content = $page->getContent();
        if ( !$content ) { $this->finishError( 'Could not load page content.', $wantsJson ); return; }
        $wt = method_exists( $content, 'getText' ) ? $content->getText() : (string)$content;
        $author = $this->getUser()->getName();
        $safeAuthor = htmlspecialchars( $author, ENT_QUOTES );
        $bodyInner = trim( $body );
        if ( $bodyInner === '' ) {
            $block = "<problem ref=\"$refSlug\" author=\"$safeAuthor\"/>";
        } else {
            $block = "<problem ref=\"$refSlug\" author=\"$safeAuthor\">\n" . $bodyInner . "\n</problem>";
        }
        $newWt = TemplateParamEditor::insertIntoMedTemplateParam( $wt, 'indications', $block );
        $newContent = ContentHandler::makeContent( $newWt, $target );
        $updater = $page->newPageUpdater( $this->getUser() );
        $updater->setContent( SlotRecord::MAIN, $newContent );
        $summary = CommentStoreComment::newUnsavedComment( 'Added Problem: ' . $refName );
        $updater->saveRevision( $summary, EDIT_UPDATE );
        $status = $updater->getStatus();
        if ( !$status || !$status->isOK() ) {
            $msg = $status ? $status->getWikiText( false, false, 'en' ) : 'Save failed.';
            $this->finishError( 'Save failed: ' . $msg, $wantsJson ); return;
        }
        $redirectUrl = $target->getLocalURL() . '#problem-ref-' . $refSlug;
        if ( $wantsJson ) {
            $this->getOutput()->disable();
            header( 'Content-Type: application/json' );
            echo json_encode( [ 'ok' => true, 'slug' => $refSlug, 'redirect' => $redirectUrl ] );
            return;
        }
        $this->getOutput()->redirect( $redirectUrl );
    }

    private function renderError( $msg ) {
        $this->getOutput()->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ], $msg ) );
    }

    private function finishError( $msg, $wantsJson, $http = 400 ) {
        if ( $wantsJson ) {
            $this->getOutput()->disable();
            header( 'Content-Type: application/json', true, $http );
            echo json_encode( [ 'ok' => false, 'error' => $msg ] );
            return;
        }
        $this->renderError( $msg );
        $req = $this->getRequest();
        $target = Title::newFromText( $req->getVal( 'target', '' ) );
        if ( $target ) {
            $this->renderForm( $target );
        } else {
            $this->renderStandaloneForm( trim( (string)$req->getText( 'problem_title', '' ) ) );
        }
    }
}
