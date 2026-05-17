<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;

class SpecialVerificationDoc extends SpecialPage {
    public function __construct() {
        parent::__construct( 'VerificationDoc', 'pharmacopedia-verify-review' );
    }

    public function execute( $subPage ) {
        $this->checkPermissions();

        // Expect subpage like: "42/20260512_123456_abc.pdf"
        if ( !$subPage || strpos( $subPage, '/' ) === false ) {
            $this->getOutput()->setStatusCode( 400 );
            $this->getOutput()->addWikiTextAsContent( "Invalid request." );
            return;
        }
        [ $appId, $filename ] = explode( '/', $subPage, 2 );
        $appId = (int)$appId;
        // Sanitize filename — only allow our own naming pattern
        if ( !preg_match( '/^[A-Za-z0-9_.-]+$/', $filename ) ) {
            $this->getOutput()->setStatusCode( 400 );
            $this->getOutput()->addWikiTextAsContent( "Invalid filename." );
            return;
        }

        $store = new ProviderAppStore();
        $app = $store->getById( $appId );
        if ( !$app ) {
            $this->getOutput()->setStatusCode( 404 );
            $this->getOutput()->addWikiTextAsContent( "Application not found." );
            return;
        }
        $paths = json_decode( $app->pa_doc_paths ?? '[]', true ) ?: [];
        $match = null;
        foreach ( $paths as $p ) {
            if ( basename( $p ) === $filename ) { $match = $p; break; }
        }
        if ( !$match || !file_exists( $match ) ) {
            $this->getOutput()->setStatusCode( 404 );
            $this->getOutput()->addWikiTextAsContent( "Document not found (may have been deleted after review)." );
            return;
        }

        $mime = mime_content_type( $match ) ?: 'application/octet-stream';
        $size = filesize( $match );

        $this->getOutput()->disable(); // we'll write directly to the response

        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . $size );
        header( 'Content-Disposition: inline; filename="' . $filename . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Cache-Control: private, no-cache, no-store, must-revalidate' );
        readfile( $match );
    }

    protected function getGroupName() { return 'users'; }
}
