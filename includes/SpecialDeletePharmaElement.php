<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;
use MediaWiki\Content\ContentHandler;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\MediaWikiServices;

class SpecialDeletePharmaElement extends SpecialPage {

    public function __construct() {
        parent::__construct( 'DeletePharmaElement' );
    }

    public function doesWrites() { return true; }

    private static $allowedTypes = [ 'anecdote', 'effect', 'titration', 'problem' ];

    public function execute( $par ) {
        try {
            $this->executeInner( $par );
        } catch ( \Throwable $e ) {
            $wantsJson = $this->getRequest()->getVal( 'format' ) === 'json';
            $msg = 'Exception: ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
            $this->finishError( $msg, $wantsJson, 500 );
        }
    }

    public function executeInner( $par ) {
        $this->setHeaders();
        $req = $this->getRequest();
        $user = $this->getUser();
        $wantsJson = $req->getVal( 'format' ) === 'json';

        if ( !$user->isRegistered() ) {
            return $this->finishError( 'Login required.', $wantsJson, 401 );
        }
        if ( !$req->wasPosted() ) {
            return $this->finishError( 'POST required.', $wantsJson, 405 );
        }
        if ( !$user->matchEditToken( $req->getVal( 'wpEditToken' ) ) ) {
            return $this->finishError( 'Invalid session token.', $wantsJson, 403 );
        }

        $targetText = trim( (string)$req->getVal( 'target', '' ) );
        $type = trim( strtolower( (string)$req->getVal( 'type', '' ) ) );
        $slug = trim( (string)$req->getVal( 'slug', '' ) );

        if ( !in_array( $type, self::$allowedTypes, true ) ) {
            return $this->finishError( 'Invalid element type.', $wantsJson, 400 );
        }
        if ( $slug === '' ) {
            return $this->finishError( 'Slug required.', $wantsJson, 400 );
        }
        $title = Title::newFromText( $targetText );
        if ( !$title || !$title->exists() || !$title->inNamespace( NS_MAIN ) ) {
            return $this->finishError( 'Target page not found.', $wantsJson, 404 );
        }

        $pageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
        $page = $pageFactory->newFromTitle( $title );
        $content = $page->getContent();
        if ( !$content ) {
            return $this->finishError( 'Could not load page content.', $wantsJson, 500 );
        }
        $wt = method_exists( $content, 'getText' ) ? $content->getText() : (string)$content;

        $slugQuoted = preg_quote( $slug, '/' );
        $typeQuoted = preg_quote( $type, '/' );
        // For effect tags using the global library, the on-page attribute is `ref="X"`
        // but the card emits data-slug="ref-X" — so we accept either attribute style.
        $attrAlternatives = [ 'slug\s*=\s*"' . $slugQuoted . '"' ];
        if ( ( $type === 'effect' || $type === 'problem' ) && substr( $slug, 0, 4 ) === 'ref-' ) {
            $refSlug = substr( $slug, 4 );
            $attrAlternatives[] = 'ref\s*=\s*"' . preg_quote( $refSlug, '/' ) . '"';
        }
        $attrPattern = '(?:' . implode( '|', $attrAlternatives ) . ')';
        $regex = '/<' . $typeQuoted . '\b[^>]*\b' . $attrPattern . '[^>]*?(?:\/\s*>|>[\s\S]*?<\/' . $typeQuoted . '\s*>)/';
        if ( !preg_match( $regex, $wt, $m, PREG_OFFSET_CAPTURE ) ) {
            return $this->finishError( 'Element not found on page.', $wantsJson, 404 );
        }
        $block = $m[0][0];
        $offset = $m[0][1];

        $author = '';
        if ( preg_match( '/\bauthor\s*=\s*"([^"]*)"/', $block, $am ) ) {
            $author = $am[1];
        }

        $groups = MediaWikiServices::getInstance()->getUserGroupManager()->getUserEffectiveGroups( $user );
        $isSysop = in_array( 'sysop', $groups, true ) || in_array( 'admin', $groups, true );
        if ( !$isSysop && $author !== $user->getName() ) {
            return $this->finishError( 'You can only delete your own elements.', $wantsJson, 403 );
        }

        // Remove block + one immediately-following newline (and one more blank line if present)
        $end = $offset + strlen( $block );
        if ( $end < strlen( $wt ) && $wt[$end] === "\n" ) { $end++; }
        if ( $end < strlen( $wt ) && $wt[$end] === "\n" ) { $end++; }
        $newWt = substr( $wt, 0, $offset ) . substr( $wt, $end );

        $newContent = ContentHandler::makeContent( $newWt, $title );
        $updater = $page->newPageUpdater( $user );
        $updater->setContent( SlotRecord::MAIN, $newContent );
        $adminTag = ( $isSysop && $author !== '' && $author !== $user->getName() )
            ? ' (admin removal)' : '';
        $summary = CommentStoreComment::newUnsavedComment(
            'Removed ' . $type . ' "' . $slug . '"' . $adminTag
        );
        $updater->saveRevision( $summary, EDIT_UPDATE );
        $status = $updater->getStatus();
        if ( !$status || !$status->isOK() ) {
            $msg = $status ? $status->getWikiText( false, false, 'en' ) : 'Save failed.';
            return $this->finishError( 'Save failed: ' . $msg, $wantsJson, 500 );
        }

        // Auto-publish for sysop/admin deletions (bypass FlaggedRevs pending queue).
        // Still leaves a full page-history record (reversible by rollback) and a
        // Recent Changes entry tagged with the edit summary.
        if ( $isSysop && class_exists( 'FlaggedRevs' ) ) {
            try {
                $newRev = $updater->getNewRevision();
                if ( $newRev ) {
                    \FlaggedRevs::autoReviewEdit( $page, $user, $newRev, null, true, true );
                }
            } catch ( \Throwable $e ) {
                // Non-fatal — deletion still committed as a pending edit
                wfDebugLog( 'pharmacopedia', 'Auto-review on delete failed: ' . $e->getMessage() );
            }
        }

        if ( $wantsJson ) {
            $this->getOutput()->disable();
            header( 'Content-Type: application/json' );
            echo json_encode( [ 'ok' => true ] );
            return;
        }
        $this->getOutput()->redirect( $title->getLocalURL() );
    }

    private function finishError( $msg, $wantsJson, $httpStatus = 400 ) {
        if ( $wantsJson ) {
            $this->getOutput()->disable();
            header( 'Content-Type: application/json', true, $httpStatus );
            echo json_encode( [ 'ok' => false, 'error' => $msg ] );
            return;
        }
        $this->getOutput()->addHTML(
            Html::element( 'div', [ 'class' => 'errorbox' ], $msg )
        );
    }

    /**
     * Renders the HTML for the delete button. Always emitted; client-side JS
     * decides whether to show it based on author / sysop status.
     */
    public static function buttonHtml( $type, $slug, $author ) {
        return '<button type="button" class="pcp-del-btn" ' .
            'data-type="' . htmlspecialchars( $type ) . '" ' .
            'data-slug="' . htmlspecialchars( $slug ) . '" ' .
            'data-author="' . htmlspecialchars( $author ) . '" ' .
            'title="Delete this ' . htmlspecialchars( $type ) . '" aria-label="Delete">×</button>';
    }
}
