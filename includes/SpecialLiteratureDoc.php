<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;

/**
 * Public download proxy for approved literature PDFs.
 * URL form: Special:LiteratureDoc/<id>
 * Only streams files whose row is in APPROVED state.
 */
class SpecialLiteratureDoc extends SpecialPage {
    public function __construct() {
        parent::__construct( 'LiteratureDoc' );
    }

    public function execute( $subPage ) {
        $out = $this->getOutput();

        $id = (int)$subPage;
        if ( $id <= 0 ) {
            $out->setStatusCode( 400 );
            $out->addWikiTextAsContent( 'Invalid request.' );
            return;
        }

        $store = new LiteratureStore();
        $row = $store->getById( $id );
        if ( !$row ) {
            $out->setStatusCode( 404 );
            $out->addWikiTextAsContent( 'Not found.' );
            return;
        }

        $status = (int)$row->l_status;
        $isReviewer = $this->getUser()->isAllowed( 'pharmacopedia-literature-review' );

        // Public: only approved entries.
        // Reviewer: can also view pending (for moderation preview).
        if ( $status !== LiteratureStore::STATUS_APPROVED &&
             !( $isReviewer && $status === LiteratureStore::STATUS_PENDING ) ) {
            $out->setStatusCode( 404 );
            $out->addWikiTextAsContent( 'Not found.' );
            return;
        }

        $path = (string)( $row->l_file_path ?? '' );
        if ( $path === '' || !is_file( $path ) ) {
            $out->setStatusCode( 404 );
            $out->addWikiTextAsContent( 'File missing.' );
            return;
        }

        // Sanitize a filename for the Content-Disposition header.
        $orig = (string)( $row->l_file_origname ?? 'literature.pdf' );
        $orig = preg_replace( '/[^A-Za-z0-9._-]/', '_', $orig );
        if ( $orig === '' ) { $orig = 'literature.pdf'; }
        if ( !preg_match( '/\.pdf$/i', $orig ) ) { $orig .= '.pdf'; }

        $size = filesize( $path );

        $out->disable();
        header( 'Content-Type: application/pdf' );
        if ( $size !== false ) { header( 'Content-Length: ' . $size ); }
        header( 'Content-Disposition: inline; filename="' . $orig . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Cache-Control: private, max-age=300' );
        readfile( $path );
    }

    protected function getGroupName() { return 'users'; }
}
