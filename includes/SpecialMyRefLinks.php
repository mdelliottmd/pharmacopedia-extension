<?php
namespace MediaWiki\Extension\Pharmacopedia;

use SpecialPage;
use Html;

/**
 * Owner-facing bulk linker: list every free-text observation/episode ref with
 * a now-existing structured match, offer one-click link or dismiss.
 *
 * The heavy lifting (matching + applying) is in pharmacopediarefupgrade API.
 * This page is a thin shell that loads ext.pharmacopedia.refupgrade JS.
 */
class SpecialMyRefLinks extends SpecialPage {

    public function __construct() {
        parent::__construct( 'MyRefLinks' );
    }

    public function execute( $sub ) {
        $this->setHeaders();
        $out = $this->getOutput();
        if ( !$this->getUser()->isRegistered() ) {
            $out->addWikiTextAsInterface( $this->msg( 'pharmacopedia-login-required' )->plain() );
            return;
        }
        $out->setPageTitle( 'Link my free-text references' );
        $out->addModules( [ 'ext.pharmacopedia.refupgrade' ] );
        $out->addModuleStyles( [ 'ext.pharmacopedia.refupgrade' ] );
        $out->addHTML( Html::rawElement( 'div', [ 'class' => 'pcp-reflinks' ],
            Html::element( 'p', [],
                'When you write observations like "anxiety from bupropion" before you have added "bupropion" to your medicines, the reference is stored as free text. ' .
                'This page lists every unmatched free-text reference that now matches an entity in your data or in the global catalog. ' .
                'Click a match to link it (updates all observations containing that text), or dismiss to stop seeing it suggested.'
            ) .
            Html::rawElement( 'div', [ 'class' => 'pcp-reflinks-list' ],
                Html::element( 'p', [ 'class' => 'pcp-loading' ], 'Scanning your free-text references...' )
            )
        ) );
    }

    protected function getGroupName() { return 'users'; }
}
