<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;
use MediaWiki\MediaWikiServices;

/**
 * Special:Problem/<slug> — read-only page for a single Problem.
 *
 * Renders canonical name + category badge, description, aliases, and the list
 * of "Medicines that target this Problem" pulled from pcp_votable_elements
 * where ve_slug = 'problem-ref-<slug>' (the prefix the <problem> tag uses
 * since Phase 5a).
 */
class SpecialProblem extends SpecialPage {

    public function __construct() {
        parent::__construct( 'Problem' );
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        $slug = trim( (string)$par );
        if ( $slug === '' ) {
            $out->setPageTitle( 'Problem' );
            $out->addWikiTextAsInterface(
                "No slug specified. See [[Special:Problems]] for the full repository."
            );
            return;
        }

        $store = new ProblemStore();
        $raw = $store->getBySlug( $slug );
        if ( !$raw ) {
            $out->setPageTitle( 'Problem not found' );
            $out->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ],
                'No Problem with slug "' . $slug . '". ' ) );
            $out->addWikiTextAsInterface(
                "See [[Special:Problems]] for the full repository, or " .
                "[[Special:SuggestProblem|propose a new Problem]]."
            );
            return;
        }

        $resolved = $store->resolve( $slug );
        if ( $resolved && (string)$resolved->p_slug !== $slug ) {
            $canonical = SpecialPage::getTitleFor( 'Problem', (string)$resolved->p_slug );
            $out->redirect( $canonical->getLocalURL() );
            return;
        }

        $row = $resolved ?: $raw;

        $name = (string)$row->p_name;
        $slug = (string)$row->p_slug;
        $cat  = (string)$row->p_category;
        $desc = (string)$row->p_description;
        $aliases = $store->getAliases( (int)$row->p_id );

        $out->setPageTitle( $name );

        $html  = '';

        if ( (int)$row->p_retired === 1 ) {
            $html .= '<div class="warningbox"><strong>Retired.</strong> This Problem has been retired' .
                ( $row->p_merged_into ? ' and merged into another canonical entry' : '' ) . '.</div>';
        }

        if ( $cat !== '' ) {
            $catUrl = SpecialPage::getTitleFor( 'Problems' )->getLocalURL() . '#cat-' . htmlspecialchars( $cat );
            $html .= '<p><span class="pcp-pb-cat-badge"><a href="' . $catUrl . '">' .
                htmlspecialchars( $cat ) . '</a></span></p>';
        }

        if ( $desc !== '' ) {
            $html .= '<div class="pcp-pb-desc">' . htmlspecialchars( $desc ) . '</div>';
        } else {
            $html .= '<p style="opacity:0.6; font-style:italic;">No description yet.</p>';
        }

        if ( $aliases ) {
            $html .= '<p><strong>Also known as:</strong> ' .
                htmlspecialchars( implode( ', ', $aliases ) ) . '</p>';
        }

        $html .= '<p style="font-size:0.85em; opacity:0.7;">Slug: <code>' . htmlspecialchars( $slug ) . '</code></p>';

        $html .= '<h2>Medicines targeting this Problem</h2>';
        $meds = self::getLinkedMedicines( $slug );
        if ( !$meds ) {
            $html .= '<p style="opacity:0.6; font-style:italic;">No medicine pages yet tag this Problem.</p>';
        } else {
            $html .= '<ul class="pcp-problem-med-list">';
            foreach ( $meds as $title ) {
                $t = Title::newFromText( $title );
                if ( !$t ) { continue; }
                $html .= '<li><a href="' . htmlspecialchars( $t->getLocalURL() ) . '">' .
                    htmlspecialchars( $t->getPrefixedText() ) . '</a></li>';
            }
            $html .= '</ul>';
        }

        $html .= '<hr>';
        $html .= '<p><a href="' . htmlspecialchars(
            SpecialPage::getTitleFor( 'Problems' )->getLocalURL() ) . '">← All Problems</a></p>';

        $out->addHTML( $html );
    }

    /**
     * Find all medicine page titles where this Problem is referenced via a
     * <problem ref="..."> tag. The ProblemTag renders a votable element with
     * slug 'problem-ref-<problem-slug>' per page; this is the post-Phase-5a
     * prefix (legacy was 'indication-ref-<slug>', migrated in the same commit).
     */
    private static function getLinkedMedicines( $problemSlug ) {
        $db = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $needle = 'problem-ref-' . $problemSlug;
        $rows = $db->newSelectQueryBuilder()
            ->select( 'page_title' )
            ->from( 'pcp_votable_elements' )
            ->join( 'page', null, 'page_id = ve_page_id' )
            ->where( [
                've_slug' => $needle,
                'page_namespace' => 0,
            ] )
            ->orderBy( 'page_title' )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $titles = [];
        foreach ( $rows as $r ) {
            $titles[] = (string)$r->page_title;
        }
        return $titles;
    }

    protected function getGroupName() { return 'pharmacopedia'; }
}
