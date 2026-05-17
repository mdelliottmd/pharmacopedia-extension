<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;

/**
 * Permission-checked image streamer for Life Story images.
 *   Special:LifeImage/<event_id>/<image_id>
 * Visibility-gated: owner + sysop always; others only if event visibility >= 1.
 */
class SpecialLifeImage extends SpecialPage {

    public function __construct() {
        parent::__construct( 'LifeImage' );
    }

    public function execute( $par ) {
        $par = trim( (string)$par );
        if ( !preg_match( '#^(\d+)/(\d+)$#', $par, $m ) ) {
            $this->setHeaders();
            $this->getOutput()->setStatusCode( 400 );
            $this->getOutput()->addHTML( '<p>Bad image path.</p>' );
            return;
        }
        $eventId = (int)$m[1];
        $imageId = (int)$m[2];

        $store = new LifeStoryStore();
        $event = $store->getEvent( $eventId );
        $image = $store->getImage( $imageId );
        if ( !$event || !$image || (int)$image->li_event_id !== $eventId ) {
            $this->setHeaders();
            $this->getOutput()->setStatusCode( 404 );
            $this->getOutput()->addHTML( '<p>Image not found.</p>' );
            return;
        }

        // Permission check
        $viewer = $this->getUser();
        $isSysop = $viewer->isAllowed( 'pharmacopedia-profile-view-others-full' );
        $viewerProfileId = null;
        if ( $viewer->isRegistered() ) {
            $pStore = new UserProfileStore();
            $vp = $pStore->getOrCreateForUser( $viewer->getId() );
            $viewerProfileId = (int)$vp->prof_id;
        }
        if ( !$store->canViewEvent( $event, $viewerProfileId, $isSysop ) ) {
            $this->setHeaders();
            $this->getOutput()->setStatusCode( 403 );
            $this->getOutput()->addHTML( '<p>Permission denied.</p>' );
            return;
        }

        $path = (string)$image->li_file_path;
        if ( $path === '' || !is_file( $path ) ) {
            $this->setHeaders();
            $this->getOutput()->setStatusCode( 404 );
            $this->getOutput()->addHTML( '<p>File missing.</p>' );
            return;
        }

        // Whitelist the MIME at stream-time: even if the DB row were tampered
        // with, only known-good image MIMEs are emitted to the client.
        $allowedMimes = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
        $mime = (string)$image->li_mime;
        if ( !in_array( $mime, $allowedMimes, true ) ) {
            $mime = 'application/octet-stream';
        }

        // Stream the file
        $this->getOutput()->disable();
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . (int)$image->li_size_bytes );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Cache-Control: private, max-age=0, no-store' );
        header( 'Content-Disposition: inline; filename="' . preg_replace( '/[^A-Za-z0-9._-]/', '_', (string)$image->li_orig_name ) . '"' );
        readfile( $path );
    }

    public function doesWrites() { return false; }
    protected function getGroupName() { return 'users'; }
}
