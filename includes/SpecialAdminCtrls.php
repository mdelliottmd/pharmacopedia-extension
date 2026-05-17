<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;

class SpecialAdminCtrls extends SpecialPage {

    public function __construct() {
        // Right left empty; Lockdown gates access via $wgSpecialPageLockdown.
        parent::__construct( 'AdminCtrls' );
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->setPageTitle( 'Admin controls' );

        // Pull content from MediaWiki:Adminctrls-body (sysop-editable by default).
        $msg = wfMessage( 'adminctrls-body' );
        if ( !$msg->exists() ) {
            $out->addHTML(
                '<div class="errorbox">No content yet — create or edit ' .
                '<a href="' . htmlspecialchars(
                    \Title::makeTitle( NS_MEDIAWIKI, 'Adminctrls-body' )->getLocalURL( [ 'action' => 'edit' ] )
                ) . '">MediaWiki:Adminctrls-body</a> ' .
                'to populate this page.</div>'
            );
            return;
        }

        $out->addWikiTextAsContent( $msg->plain() );

        // Small footer linking to the source for quick editing
        $editUrl = \Title::makeTitle( NS_MEDIAWIKI, 'Adminctrls-body' )->getLocalURL( [ 'action' => 'edit' ] );
        $out->addHTML(
            '<hr><p style="font-size:0.85em; opacity:0.7;">Source: ' .
            '<a href="' . htmlspecialchars( $editUrl ) . '">MediaWiki:Adminctrls-body</a></p>'
        );
    }

    public function doesWrites() { return false; }
}
