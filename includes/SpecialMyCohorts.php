<?php
namespace MediaWiki\Extension\Pharmacopedia;

use SpecialPage;
use Html;

/**
 * Owner-facing cohort management: create cohorts, add/remove members.
 * All CRUD goes through pharmacopediacohorts API; this page is a thin shell.
 */
class SpecialMyCohorts extends SpecialPage {

    public function __construct() {
        parent::__construct( 'MyCohorts' );
    }

    public function execute( $sub ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $user = $this->getUser();

        if ( !$user->isRegistered() ) {
            $out->addWikiTextAsInterface( $this->msg( 'pharmacopedia-login-required' )->plain() );
            return;
        }

        $out->setPageTitle( 'My cohorts' );
        $out->addModules( [ 'ext.pharmacopedia.share' ] );
        $out->addModuleStyles( [ 'ext.pharmacopedia.share' ] );

        $out->addHTML(
            Html::rawElement( 'div', [ 'class' => 'pcp-mycohorts' ],
                Html::element( 'p', [], 'Cohorts are reusable groups you can share visibility with. Create a cohort once, then share any record with that whole cohort in one click.' ) .
                Html::rawElement( 'div', [ 'class' => 'pcp-cohort-create' ],
                    Html::element( 'input', [
                        'type'        => 'text',
                        'class'       => 'pcp-cohort-new-name',
                        'placeholder' => 'New cohort name (e.g. "Therapy team", "Family", "Clinical trial Drs")',
                    ] ) .
                    Html::element( 'input', [
                        'type'        => 'text',
                        'class'       => 'pcp-cohort-new-desc',
                        'placeholder' => 'Optional description',
                    ] ) .
                    Html::element( 'button', [
                        'type'  => 'button',
                        'class' => 'pcp-btn pcp-cohort-create-btn',
                    ], 'Create cohort' )
                ) .
                Html::rawElement( 'div', [ 'class' => 'pcp-cohort-list' ],
                    Html::element( 'p', [ 'class' => 'pcp-loading' ], 'Loading your cohorts...' )
                )
            )
        );
    }

    protected function getGroupName() { return 'users'; }
}
