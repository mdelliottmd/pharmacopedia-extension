<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class SpecialLiteratureQueue extends SpecialPage {
    public function __construct() {
        parent::__construct( 'LiteratureQueue', 'pharmacopedia-literature-review' );
    }

    public function execute( $subPage ) {
        $this->setHeaders();
        $this->checkPermissions();
        $out = $this->getOutput();
        $out->setPageTitle( 'Literature review queue' );

        $store = new LiteratureStore();
        $request = $this->getRequest();

        // Handle decision POST
        if ( $request->wasPosted() && $request->getCheck( 'lit_id' ) ) {
            if ( !$this->getUser()->matchEditToken( $request->getVal( 'token' ) ) ) {
                $out->addWikiTextAsContent( "''Invalid CSRF token.''" );
                return;
            }
            $litId = (int)$request->getVal( 'lit_id' );
            $action = $request->getVal( 'action_decision' );
            $notes = trim( $request->getText( 'admin_notes' ) ) ?: null;
            $reviewer = $this->getUser();
            if ( $action === 'approve' ) {
                $ok = $store->approve( $litId, (int)$reviewer->getId(), $notes );
                $out->addWikiTextAsContent( $ok ? "''Approved.''" : "''Could not approve (already reviewed?).''" );
            } elseif ( $action === 'reject' ) {
                $ok = $store->reject( $litId, (int)$reviewer->getId(), $notes );
                $out->addWikiTextAsContent( $ok ? "''Rejected.''" : "''Could not reject.''" );
            } elseif ( $action === 'delete' ) {
                $ok = $store->adminDelete( $litId );
                $out->addWikiTextAsContent( $ok ? "''Deleted.''" : "''Could not delete.''" );
            }
        }

        if ( $subPage && is_numeric( $subPage ) ) {
            $this->renderDetail( (int)$subPage );
            return;
        }

        $pending = $store->listPending();
        if ( !$pending ) {
            $out->addWikiTextAsContent( "''No pending literature submissions.''" );
            return;
        }
        $out->addWikiTextAsContent( '== Pending literature submissions ==' );
        $rows = '';
        foreach ( $pending as $r ) {
            $pageTitle = $r->page_title ? str_replace( '_', ' ', (string)$r->page_title ) : '(unknown)';
            $submitter = $r->submitter_name ?: 'Anonymous (' . substr( (string)$r->l_voter_hash, 0, 8 ) . '…)';
            $submitted = wfTimestamp( TS_RFC2822, $r->l_submitted );
            $detail = '[[Special:LiteratureQueue/' . (int)$r->l_id . '|Review]]';
            $rows .= '|-' . "\n" .
                '| ' . (int)$r->l_id .
                ' || [[' . $pageTitle . ']]' .
                ' || ' . htmlspecialchars( (string)$r->l_title ) .
                ' || [[User:' . $submitter . '|' . $submitter . ']]' .
                ' || ' . $submitted .
                ' || ' . $detail . "\n";
        }
        $out->addWikiTextAsContent(
            "{| class=\"wikitable\" style=\"width:100%;\"\n" .
            "! # !! Page !! Title !! Submitter !! Submitted !! Action\n" .
            $rows .
            "|}"
        );
    }

    private function renderDetail( int $id ) {
        $out = $this->getOutput();
        $store = new LiteratureStore();
        $row = $store->getById( $id );
        if ( !$row ) {
            $out->addWikiTextAsContent( "''Entry not found.''" );
            return;
        }
        $pageTitle = Title::newFromID( (int)$row->l_page_id );
        $pageName = $pageTitle ? $pageTitle->getPrefixedText() : '(unknown)';
        $status = LiteratureStore::statusLabel( (int)$row->l_status );

        $userFactory = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory();
        // anonymized: no submitter user lookup
        $submitter = null;
        $submitterName = $submitter ? $submitter->getName() : '(unknown)';

        $out->addWikiTextAsContent( "== Literature entry #$id ==" );
        $out->addWikiTextAsContent(
            "; Page\n: [[$pageName]]\n" .
            "; Status\n: " . htmlspecialchars( $status ) . "\n" .
            "; Submitter\n: [[User:$submitterName|$submitterName]]\n" .
            "; Submitted\n: " . htmlspecialchars( wfTimestamp( TS_RFC2822, $row->l_submitted ) ) . "\n" .
            "; Title\n: " . htmlspecialchars( (string)$row->l_title ) . "\n" .
            "; Authors\n: " . ( $row->l_authors ? htmlspecialchars( (string)$row->l_authors ) : '(none)' ) .
                ( !empty( $row->l_et_al ) ? " ''et al.''" : '' ) . "\n" .
            "; Year\n: " . ( $row->l_year ? (int)$row->l_year : '(none)' ) . "\n" .
            "; URL\n: " . ( $row->l_url ? htmlspecialchars( (string)$row->l_url ) : '(none)' ) . "\n" .
            "; DOI\n: " . ( $row->l_doi ? htmlspecialchars( (string)$row->l_doi ) : '(none)' ) . "\n" .
            "; PubMed ID\n: " . ( $row->l_pmid ? (int)$row->l_pmid : '(none)' ) . "\n"
        );

        if ( $row->l_file_path ) {
            $docUrl = htmlspecialchars(
                Title::makeTitle( NS_SPECIAL, 'LiteratureDoc/' . $id )->getLocalURL()
            );
            $orig = htmlspecialchars( (string)( $row->l_file_origname ?: 'file.pdf' ) );
            $sizeKb = (int)( (int)$row->l_file_size / 1024 );
            $out->addHTML(
                '<p><strong>Uploaded PDF:</strong> ' .
                '<a href="' . $docUrl . '" target="_blank" rel="noopener">View</a> ' .
                '<small>(' . $orig . ', ' . $sizeKb . ' KB)</small></p>'
            );
        }

        if ( (int)$row->l_status === LiteratureStore::STATUS_PENDING ) {
            $token = htmlspecialchars( $this->getUser()->getEditToken() );
            $formAction = htmlspecialchars( $this->getPageTitle()->getLocalURL() );
            $html  = '<form method="post" action="' . $formAction .
                     '" style="margin-top:1em; padding:1em; border:1px solid #ccc; border-radius:6px;">';
            $html .= '<input type="hidden" name="lit_id" value="' . $id . '">';
            $html .= '<input type="hidden" name="token" value="' . $token . '">';
            $html .= '<label>Notes (shown to submitter on rejection):<br>' .
                     '<textarea name="admin_notes" rows="3" cols="60"></textarea></label><br><br>';
            $html .= '<button type="submit" name="action_decision" value="approve" ' .
                     'style="background:#16a34a; color:#fff; padding:6px 14px; border:none; border-radius:4px; margin-right:0.5em;">Approve</button>';
            $html .= '<button type="submit" name="action_decision" value="reject" ' .
                     'style="background:#dc2626; color:#fff; padding:6px 14px; border:none; border-radius:4px; margin-right:0.5em;">Reject</button>';
            $html .= '<button type="submit" name="action_decision" value="delete" ' .
                     'style="background:#6b7280; color:#fff; padding:6px 14px; border:none; border-radius:4px;" ' .
                     'class="js-pcp-confirm-delete" data-pcp-confirm="Hard-delete this entry?">Delete</button>';
            $html .= '</form>';
            $out->addHTML( $html );
        } else {
            $reviewer = $row->l_reviewed_by ? $userFactory->newFromId( (int)$row->l_reviewed_by ) : null;
            $out->addWikiTextAsContent(
                "== Decision ==\n" .
                "Reviewed: " . htmlspecialchars( wfTimestamp( TS_RFC2822, $row->l_reviewed ) ) . "\n\n" .
                ( $reviewer ? "By: [[User:" . $reviewer->getName() . "|" . $reviewer->getName() . "]]\n\n" : '' ) .
                "Admin notes: " . ( $row->l_admin_notes ? htmlspecialchars( (string)$row->l_admin_notes ) : '(none)' )
            );
        }
    }

    protected function getGroupName() { return 'users'; }
}
