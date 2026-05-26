<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\SiteStats\SiteStats;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * <frontpage/> -- the two-origin diptych Main Page.
 *
 * A shared split-gradient masthead + status strip, then a 50/50 diptych:
 * the pharmaceutical wiki on the left, the plant wiki on the right, each a
 * stack of four modules (featured / browse / portals / recently updated).
 * Built per designer-claude's mainpage_diptych.html; styling is the
 * ext.pharmacopedia.frontpage module. Browse teasers link real curated
 * categories; counts come from the live graph.
 */
class FrontPageTag {

    /** Pharma browse teaser: category DB-key => display label. */
    private const PHARMA_TEASER = [
        'Antidepressants'  => 'Antidepressants',
        'Anxiolytics'      => 'Anxiolytics',
        'Mood_Stabilizers' => 'Mood stabilizers',
        'Neuroleptics'     => 'Neuroleptics',
        'Opioids'          => 'Opioids',
        'Psychedelics'     => 'Psychedelics',
        'Psychostimulants' => 'Psychostimulants',
    ];

    /** Plant browse teaser: the 3 Pharmako volumes (publication order). */
    private const PLANT_TEASER = [
        'Poeia'   => 'Poeia',
        'Dynamis' => 'Dynamis',
        'Gnosis'  => 'Gnosis',
    ];

    /** Recently-updated seed pools; rendered only if the page exists. */
    private const RECENT_PHARMA = [
        'Fluoxetine', 'Bupropion', 'Lithium', 'Quetiapine', 'Alprazolam', 'Sertraline',
    ];
    private const RECENT_PLANT = [
        'Cannabis', 'Psilocybin', 'Datura', 'Ayahuasca', 'Iboga', 'Kratom',
    ];

    public static function render( $input, array $args, $parser, $frame ) {
        $parser->getOutput()->updateCacheExpiry( 300 );
        $parser->getOutput()->addModuleStyles( [ 'ext.pharmacopedia.frontpage' ] );
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

        $h  = '<div class="pcp-frontpage">';
        $h .= self::svgDefs();
        $h .= DiptychChrome::topbar();
        $h .= self::titleBand();
        $h .= self::strip( $dbr );
        $h .= '<div class="diptych">';
        $h .= self::pharmaColumn( $dbr );
        $h .= self::seam();
        $h .= self::plantColumn( $dbr );
        $h .= '</div>';
        $h .= DiptychChrome::footer();
        $h .= '</div>';
        return $h;
    }

    private static function titleBand() {
        return '<div class="titleband">'
            . '<p class="tb-eyebrow"><span class="pcp-mb">the encyclopedia of medicines for the mind</span></p>'
            . '<h1 class="tb-title"><span class="pcp-mb">Pharmacopedia</span></h1>'
            . '<p class="tb-sub"><span class="pcp-mb">The definitive reference for psychotropic medicines.</span></p></div>';
    }

    private static function strip( $dbr ) {
        $meds = (int)SiteStats::articles();
        $cats = (int)$dbr->selectField( 'page', 'COUNT(*)',
            [ 'page_namespace' => NS_CATEGORY ], __METHOD__ );
        $users = self::contributorCount( $dbr );
        $fmt = static function ( $n ) { return number_format( $n ); };
        return '<div class="strip">'
            . '<span class="mature">Mature audiences only</span>'
            . '<span class="build">Under construction. Nothing is reliable (yet)</span>'
            . '<span class="stats">' . $fmt( $meds ) . ' pages &middot; '
            . $fmt( $cats ) . ' categories &middot; ' . $fmt( $users )
            . ' contributors</span></div>';
    }

    /**
     * Distinct registered users who have contributed to the site:
     * a page edit, or an authored problem / effect / interaction.
     * Votes, comments and literature are stored against a one-way
     * voter hash (the privacy design) and carry no user id, so a
     * vote-only / comment-only contributor cannot be counted.
     */
    private static function contributorCount( $dbr ) {
        $ids = [];
        $collect = static function ( $res, $col ) use ( &$ids ) {
            foreach ( $res as $row ) {
                $uid = (int)$row->$col;
                if ( $uid > 0 ) {
                    $ids[ $uid ] = true;
                }
            }
        };
        // page editors
        $collect( $dbr->select(
            [ 'revision', 'actor' ],
            [ 'uid' => 'actor_user' ],
            [ 'actor_user IS NOT NULL' ],
            __METHOD__,
            [ 'DISTINCT' ],
            [ 'actor' => [ 'JOIN', 'actor_id = rev_actor' ] ]
        ), 'uid' );
        // problem / effect / interaction authors
        $collect( $dbr->select( 'pcp_problem',
            [ 'uid' => 'p_created_by' ], [], __METHOD__, [ 'DISTINCT' ] ), 'uid' );
        $collect( $dbr->select( 'pcp_effects',
            [ 'uid' => 'e_created_by' ], [], __METHOD__, [ 'DISTINCT' ] ), 'uid' );
        $collect( $dbr->select( 'pcp_interactions',
            [ 'uid' => 'pi_created_user_id' ], [], __METHOD__, [ 'DISTINCT' ] ), 'uid' );
        return count( $ids );
    }

    private static function seam() {
        return '<div class="diptych-seam">'
            . '<svg class="seam-sprig" viewBox="0 0 46 46" aria-hidden="true"><use href="#pl-sprig"/></svg>'
            . '<div class="seam-eyebrow">Crossing into</div>'
            . '<div class="seam-label">The plant origin</div></div>';
    }

    /** Per-category content-page counts for a set of DB-keys. */
    private static function catCounts( $dbr, array $keys ) {
        $out = [];
        if ( !$keys ) { return $out; }
        $res = $dbr->select( 'category', [ 'cat_title', 'cat_pages' ],
            [ 'cat_title' => $keys ], __METHOD__ );
        foreach ( $res as $r ) {
            $out[ (string)$r->cat_title ] = (int)$r->cat_pages;
        }
        return $out;
    }

    /** Recently-updated rows: seed pool filtered to existing pages, by edit time. */
    private static function recent( $dbr, array $seed ) {
        $res = $dbr->select( 'page', [ 'page_title', 'page_touched' ],
            [ 'page_namespace' => NS_MAIN, 'page_title' => $seed, 'page_is_redirect' => 0 ],
            __METHOD__, [ 'ORDER BY' => 'page_touched DESC', 'LIMIT' => 5 ]
        );
        $rows = [];
        foreach ( $res as $r ) {
            $rows[] = [ 'title' => (string)$r->page_title, 'touched' => (string)$r->page_touched ];
        }
        return $rows;
    }

    private static function ago( $mwTs ) {
        $d = time() - (int)wfTimestamp( TS_UNIX, $mwTs );
        if ( $d < 3600 )       { return max( 1, intdiv( $d, 60 ) ) . ' min'; }
        if ( $d < 86400 )      { return intdiv( $d, 3600 ) . ' h'; }
        if ( $d < 7 * 86400 )  { return intdiv( $d, 86400 ) . ' d'; }
        return intdiv( $d, 7 * 86400 ) . ' wk';
    }

    private static function catUrl( $key ) {
        return htmlspecialchars( Title::makeTitle( NS_CATEGORY, $key )->getLocalURL() );
    }

    private static function pageUrl( $name ) {
        $t = Title::newFromText( $name );
        return $t ? htmlspecialchars( $t->getLocalURL() ) : '#';
    }

    private static function pharmaColumn( $dbr ) {
        $counts = self::catCounts( $dbr, array_keys( self::PHARMA_TEASER ) );
        $catIndex = htmlspecialchars(
            Title::newFromText( 'Category index' )->getLocalURL() );
        $assess = htmlspecialchars(
            SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() )
            . '#pcp-assessments';
        // CSS-only chevron injected into every module summary.
        $chev = '<span class="p-mod-chev" aria-hidden="true"></span>';

        $h  = '<div class="col col-pharma"><div class="col-inner">';
        $h .= '<div class="p-head"><div class="rk">Origin</div>'
            . '<div class="nm serif"><a href="' . self::catUrl( 'Pharmaceutical' ) . '">Pharmaceutical</a></div>'
            . '<div class="fr">The prescriber\'s reference, clinical-first.</div>'
            . '<div class="mt">23 classes &middot; indexed by therapeutic use</div></div>';

        // 01 featured medicine
        $h .= '<details class="p-mod" open>'
            . '<summary class="p-mod-h"><span class="p-mod-no">01</span>'
            . '<span class="p-mod-label">Featured medicine</span>' . $chev . '</summary>'
            . '<div class="p-mod-body">'
            . '<h2 class="p-feat-name"><a href="' . self::pageUrl( 'Fluoxetine' )
            . '">Fluoxetine</a></h2>'
            . '<p class="p-feat-meta">SSRI &middot; Antidepressant &middot; introduced 1987</p>'
            . '<p class="p-feat-prose">The first of the SSRIs, released in the United '
            . 'States in December 1987 and still a mainstay for conditions from anxiety '
            . 'to OCD. Both of its founding promises, that it was effective and that it '
            . 'was well tolerated, have drawn hard scrutiny since.</p>'
            . '</div></details>';

        // 02 browse classes
        $h .= '<details class="p-mod" open>'
            . '<summary class="p-mod-h"><span class="p-mod-no">02</span>'
            . '<span class="p-mod-label">Browse pharmaceutical classes</span>'
            . $chev
            . '<a class="p-mod-more" href="' . $catIndex . '">all 23</a></summary>'
            . '<div class="p-mod-body">';
        foreach ( self::PHARMA_TEASER as $key => $label ) {
            $n = $counts[ $key ] ?? 0;
            $h .= '<div class="p-brow"><a href="' . self::catUrl( $key ) . '">'
                . htmlspecialchars( $label ) . '</a><span class="dots"></span>'
                . '<span class="ct">' . $n . '</span></div>';
        }
        $h .= '</div></details>';

        // 03 clinical tools
        $h .= '<details class="p-mod" open>'
            . '<summary class="p-mod-h"><span class="p-mod-no">03</span>'
            . '<span class="p-mod-label">Clinical tools</span>' . $chev . '</summary>'
            . '<div class="p-mod-body">'
            . '<a class="p-portal" href="' . $catIndex . '"><h3>The category index</h3>'
            . '<p>Every pharmacological class and plant lineage on the wiki, the two '
            . 'origins side by side.</p><span class="p-go">Open the category index</span></a>'
            . '<a class="p-portal" href="' . $assess . '"><h3>Self-assessments</h3>'
            . '<p>Enneagram, MBTI, and many more, stored with top-tier '
            . 'encryption only you can access.</p><span class="p-go">Take an assessment</span></a>'
            . '</div></details>';

        // 04 recently updated
        $h .= '<details class="p-mod" open>'
            . '<summary class="p-mod-h"><span class="p-mod-no">04</span>'
            . '<span class="p-mod-label">Recently updated</span>' . $chev . '</summary>'
            . '<div class="p-mod-body">';
        foreach ( self::recent( $dbr, self::RECENT_PHARMA ) as $r ) {
            $h .= '<div class="p-li"><a href="' . self::pageUrl( $r['title'] ) . '">'
                . htmlspecialchars( str_replace( '_', ' ', $r['title'] ) ) . '</a>'
                . '<span class="when">' . self::ago( $r['touched'] ) . '</span></div>';
        }
        $h .= '</div></details>';

        $h .= '</div></div>';
        return $h;
    }

    private static function plantColumn( $dbr ) {
        $counts = self::catCounts( $dbr, array_keys( self::PLANT_TEASER ) );
        $catIndex = htmlspecialchars(
            Title::newFromText( 'Category index' )->getLocalURL() );
        // CSS-only chevron injected into every module summary.
        $chev = '<span class="l-mod-chev" aria-hidden="true"></span>';

        $h  = '<div class="col col-plant"><div class="col-inner">';
        $h .= '<div class="l-head">'
            . '<svg class="lr-sprig" viewBox="0 0 46 46" aria-hidden="true"><use href="#pl-sprig"/></svg>'
            . '<div><div class="rk">Origin</div>'
            . '<div class="nm"><a href="' . self::catUrl( 'Plants' ) . '">Plant</a> <span class="nm-aux">(and fungi and animals)</span></div>'
            . '<div class="fr">The poison path, the field guide.</div>'
            . '<div class="mt">3 Pharmako volumes &middot; 11 classes</div></div></div>';

        // featured plant
        $h .= '<details class="l-mod" open>'
            . '<summary class="l-mod-h"><span class="l-mod-bar"></span>'
            . '<span class="l-mod-label">Featured plant medicine</span>' . $chev . '</summary>'
            . '<div class="l-mod-body">'
            . '<h2 class="l-feat-name"><a href="' . self::pageUrl( 'Cannabis' )
            . '">Cannabis</a></h2>'
            . '<p class="l-feat-meta">Cannabaceae &middot; in use for millennia</p>'
            . '<p class="l-feat-prose">The most used of the plant medicines and among '
            . 'the oldest companions of any, cannabis carries a record of human use that '
            . 'runs from the Neolithic steppe to the modern clinic, gathered, named, and '
            . 'argued over the whole way.</p>'
            . '</div></details>';

        // browse volumes
        $h .= '<details class="l-mod" open>'
            . '<summary class="l-mod-h"><span class="l-mod-bar"></span>'
            . '<span class="l-mod-label">Browse the Pharmako volumes</span>'
            . $chev
            . '<a class="l-mod-more" href="' . $catIndex . '">all</a></summary>'
            . '<div class="l-mod-body">';
        foreach ( self::PLANT_TEASER as $key => $label ) {
            $n = $counts[ $key ] ?? 0;
            $h .= '<div class="l-brow"><svg class="leaf-ic" viewBox="0 0 22 26">'
                . '<use href="#pl-leaf"/></svg><a href="' . self::catUrl( $key ) . '">'
                . htmlspecialchars( $label ) . '</a><span class="ct">' . $n . '</span></div>';
        }
        $h .= '</div></details>';

        // explore portals
        $h .= '<details class="l-mod" open>'
            . '<summary class="l-mod-h"><span class="l-mod-bar"></span>'
            . '<span class="l-mod-label">Explore the plant world</span>' . $chev . '</summary>'
            . '<div class="l-mod-body">'
            . '<a class="l-portal" href="' . $catIndex . '#pendell-axis"><h3>The Pendell axis</h3>'
            . '<p>Dale Pendell\'s ordering of the plant medicines across the three '
            . 'Pharmako volumes, the long human acquaintance with these plants.</p>'
            . '<span class="l-go">Enter by the Pendell axis</span></a>'
            . '<a class="l-portal" href="' . $catIndex . '"><h3>Browse both origins</h3>'
            . '<p>The plant medicines set alongside the pharmacological classes, the '
            . 'whole taxonomy in one place.</p>'
            . '<span class="l-go">Open the category index</span></a>'
            . '</div></details>';

        // recently updated
        $h .= '<details class="l-mod" open>'
            . '<summary class="l-mod-h"><span class="l-mod-bar"></span>'
            . '<span class="l-mod-label">Recently updated</span>' . $chev . '</summary>'
            . '<div class="l-mod-body">';
        foreach ( self::recent( $dbr, self::RECENT_PLANT ) as $r ) {
            $h .= '<div class="l-li"><a href="' . self::pageUrl( $r['title'] ) . '">'
                . htmlspecialchars( str_replace( '_', ' ', $r['title'] ) ) . '</a>'
                . '<span class="when">' . self::ago( $r['touched'] ) . '</span></div>';
        }
        $h .= '</div></details>';

        $h .= '</div></div>';
        return $h;
    }

    private static function svgDefs() {
        return '<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>'
            . '<symbol id="pl-leaf" viewBox="0 0 22 26">'
            . '<path d="M11 1 C4 7 3 15 11 25 C19 15 18 7 11 1 Z" fill="currentColor"/>'
            . '<g stroke="rgba(20,11,5,0.38)" stroke-width="1" fill="none" stroke-linecap="round">'
            . '<path d="M11 4 L11 22"/><path d="M11 10 L6.4 8"/><path d="M11 10 L15.6 8"/>'
            . '</g></symbol>'
            . '<symbol id="pl-sprig" viewBox="0 0 46 46"><g fill="currentColor">'
            . '<path d="M23 44 C18 31 19 18 23 6 C27 18 28 31 23 44 Z"/>'
            . '<path d="M23 43 C14 35 9 25 8 14 C18 17 25 30 23 43 Z"/>'
            . '<path d="M23 43 C32 35 37 25 38 14 C28 17 21 30 23 43 Z"/></g>'
            . '<path d="M23 45 L23 30" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>'
            . '</symbol></defs></svg>';
    }
}
