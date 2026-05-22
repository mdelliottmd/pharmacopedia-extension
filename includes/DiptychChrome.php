<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * DiptychChrome - the shared topbar + footer for the chromeless diptych
 * splash pages (the Main Page and the Category index). Those two pages
 * drop all Vector chrome, so the diptych module carries its own. Emitted
 * by FrontPageTag + CategoryIndexTag; styled by the pcp-frontpage /
 * pcp-catindex modules.
 */
class DiptychChrome {

    /** The shared topbar: brand mark + the site nav. */
    public static function topbar(): string {
        $pageUrl = static function ( $t ) {
            $title = Title::newFromText( $t );
            return $title ? htmlspecialchars( $title->getLocalURL() ) : '#';
        };
        $spUrl = static function ( $n ) {
            return htmlspecialchars( SpecialPage::getTitleFor( $n )->getLocalURL() );
        };
        $seal = '<svg viewBox="0 0 100 100" fill="none">'
            . '<path d="M50 6 L88 28 L88 72 L50 94 L12 72 L12 28 Z" stroke="currentColor" '
            . 'stroke-width="4" stroke-linejoin="round"/>'
            . '<path d="M50 14 L81 31 L81 69 L50 86 L19 69 L19 31 Z" stroke="currentColor" '
            . 'stroke-width="2" stroke-linejoin="round" opacity="0.5"/>'
            . '<circle cx="50" cy="50" r="14" stroke="currentColor" stroke-width="3.5"/>'
            . '<circle cx="50" cy="50" r="3" fill="currentColor"/></svg>';
        return '<div class="topbar">'
            . '<a class="brand" href="' . $pageUrl( 'Main Page' ) . '">'
            . '<span class="seal">' . $seal . '</span>'
            . '<span class="wm-top">Pharmacopedia</span></a>'
            . '<div class="topnav">'
            . '<a href="' . $pageUrl( 'List of CNS-active medicines' ) . '">Browse</a>'
            . '<a href="' . $pageUrl( 'Category index' ) . '">Categories</a>'
            . '<a href="' . $spUrl( 'TakeAssessment' ) . '">Assessments</a>'
            . '<a class="search" href="' . $spUrl( 'Search' ) . '">Search</a>'
            . '<a href="' . $spUrl( 'UserLogin' ) . '">Log in</a>'
            . '</div></div>';
    }

    /** The shared footer: license line + a small links row. */
    public static function footer(): string {
        $pageUrl = static function ( $t ) {
            $title = Title::newFromText( $t );
            return $title ? htmlspecialchars( $title->getLocalURL() ) : '#';
        };
        $spUrl = static function ( $n ) {
            return htmlspecialchars( SpecialPage::getTitleFor( $n )->getLocalURL() );
        };
        return '<footer class="footer"><div class="footer-row"><div class="footer-meta">'
            . '<p class="footer-line">Content available under CC BY-SA 4.0 unless '
            . 'otherwise noted.</p>'
            . '<div class="footer-links">'
            . '<a href="' . $pageUrl( 'Pharmacopedia:About' ) . '">About</a>'
            . '<a href="' . $pageUrl( 'Category index' ) . '">Category index</a>'
            . '<a href="' . $spUrl( 'RecentChanges' ) . '">Recent changes</a>'
            . '</div></div></div></footer>';
    }
}
