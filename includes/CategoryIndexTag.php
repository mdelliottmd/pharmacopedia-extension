<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * <categoryindex/> -- the two-origin Category index diptych.
 *
 * Pharma panel: 23 curated classes (category-claude's hand-specified top
 * tier, alphabetical), each rendering its direct subcategories from the
 * live category graph as leaves. A curated class with no live
 * subcategories renders as a flat, non-collapsing node. Plant panel:
 * Category:Plants -> 3 Pharmako volumes -> 11 classes (curated, fixed).
 * Static, server-rendered nested <details>; styling is the
 * ext.pharmacopedia.categoryindex module. Built per designer-claude's
 * static-diptych spec; markup follows /design/category_index.html.
 */
class CategoryIndexTag {

    /** Curated pharma top tier: DB-key => display label, alphabetical by label. */
    private const PHARMA_CLASSES = [
        'Analgesics'                     => 'Analgesics (non-opioid)',
        'Anesthetics'                    => 'Anesthetics',
        'Anti-addiction_agents'          => 'Anti-addiction agents',
        'Antidepressants'                => 'Antidepressants',
        'Antiepileptics'                 => 'Antiepileptics',
        'Antihistamines'                 => 'Antihistamines',
        'Antimigraine_agents'            => 'Antimigraine agents',
        'Antiparkinsonism_agents'        => 'Antiparkinsonism agents',
        'Anxiolytics'                    => 'Anxiolytics',
        'Cannabinoids'                   => 'Cannabinoids',
        'Deliriants'                     => 'Deliriants',
        'Dissociatives'                  => 'Dissociatives',
        'Empathogens'                    => 'Empathogens',
        'Mood_Stabilizers'               => 'Mood stabilizers',
        'Muscle_relaxants'               => 'Muscle relaxants',
        'Neuroleptics'                   => 'Neuroleptics',
        'Non-psychotropic_medicines'     => 'Non-psychotropic medicines',
        'Nootropics/Cognitive_enhancers' => 'Nootropics/Cognitive enhancers',
        'Opioids'                        => 'Opioids',
        'Psychedelics'                   => 'Psychedelics',
        'Psychostimulants'               => 'Psychostimulants',
        'Research_chemicals'             => 'Research chemicals',
        'Sedative-hypnotics'             => 'Sedative-hypnotics',
    ];

    /** Curated plant tier: 3 Pharmako volumes (publication order); classes alphabetical. */
    private const PLANT_VOLUMES = [
        [
            'key'     => 'Poeia',
            'desc'    => 'The inebriants. Pharmako/Poeia, 1995.',
            'classes' => [ 'Euphorica', 'Evaesthetica', 'Existentia', 'Inebriantia',
                'Metaphysica', 'Pacifica', 'Rhapsodica' ],
        ],
        [
            'key'     => 'Dynamis',
            'desc'    => 'The psychostimulants and empathogens. Pharmako/Dynamis, 2002.',
            'classes' => [ 'Empathogenica', 'Excitantia' ],
        ],
        [
            'key'     => 'Gnosis',
            'desc'    => 'The visionaries. Pharmako/Gnosis, 2005.',
            'classes' => [ 'Daimonica', 'Phantastica' ],
        ],
    ];

    public static function render( $input, array $args, $parser, $frame ) {
        $parser->getOutput()->updateCacheExpiry( 300 );
        $parser->getOutput()->addModuleStyles( [ 'ext.pharmacopedia.categoryindex' ] );

        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

        $h  = '<div class="pcp-catindex">';
        $h .= self::svgDefs();
        $h .= DiptychChrome::topbar();
        $h .= self::titleBand();
        $h .= '<div class="diptych">';
        $h .= self::pharmaColumn( $dbr );
        $h .= self::seam();
        $h .= self::plantColumn();
        $h .= '</div>';
        $h .= DiptychChrome::footer();
        $h .= '</div>';
        return $h;
    }

    private static function titleBand() {
        return '<div class="titleband">'
            . '<p class="tb-eyebrow">Index</p>'
            . '<div class="tb-title">The category index</div>'
            . '<p class="tb-sub">Every medicine on the wiki has one of two origins. '
            . 'The pharmaceutical classes branch to the left, indexed by therapeutic '
            . 'use; the plant categories to the right, indexed by the Pendell axis.</p>'
            . '</div>';
    }

    private static function seam() {
        return '<div class="diptych-seam">'
            . '<svg class="seam-sprig" viewBox="0 0 46 46" aria-hidden="true"><use href="#pl-sprig"/></svg>'
            . '<div class="seam-eyebrow">Crossing into</div>'
            . '<div class="seam-label">The plant origin</div>'
            . '</div>';
    }

    private static function countBadge( $n ) {
        return $n >= 1 ? ' <span class="ct">' . (int)$n . '</span>' : '';
    }

    private static function pharmaColumn( $dbr ) {
        $keys = array_keys( self::PHARMA_CLASSES );

        // Direct content-page count per curated class (cat_pages excludes
        // subcats + files).
        $count = [];
        $res = $dbr->select(
            'category',
            [ 'cat_title', 'cat_pages', 'cat_subcats', 'cat_files' ],
            [ 'cat_title' => $keys ],
            __METHOD__
        );
        foreach ( $res as $r ) {
            $count[ (string)$r->cat_title ] =
                max( 0, (int)$r->cat_pages - (int)$r->cat_subcats - (int)$r->cat_files );
        }

        // Direct subcategories of every curated class, one query.
        $subcats = array_fill_keys( $keys, [] );
        $res = $dbr->select(
            [ 'page', 'categorylinks', 'linktarget' ],
            [ 'child' => 'page.page_title', 'parent' => 'linktarget.lt_title' ],
            [
                'page.page_namespace'     => NS_CATEGORY,
                'linktarget.lt_namespace' => NS_CATEGORY,
                'linktarget.lt_title'     => $keys,
            ],
            __METHOD__,
            [],
            [
                'categorylinks' => [ 'INNER JOIN', 'cl_from = page_id' ],
                'linktarget'    => [ 'INNER JOIN', 'lt_id = cl_target_id' ],
            ]
        );
        foreach ( $res as $r ) {
            $p = (string)$r->parent;
            if ( isset( $subcats[ $p ] ) ) {
                $subcats[ $p ][] = (string)$r->child;
            }
        }

        $h  = '<div class="col col-pharma"><div class="col-inner">';
        $h .= '<div class="troot"><div class="rk">Origin</div>'
            . '<div class="nm">Pharmaceutical</div>'
            . '<div class="mt">23 classes &middot; indexed by therapeutic use, alphabetical</div>'
            . '</div>';
        $h .= '<div class="tbody">';
        foreach ( self::PHARMA_CLASSES as $key => $label ) {
            $n    = $count[ $key ] ?? 0;
            $kids = $subcats[ $key ] ?? [];
            $h   .= $kids
                ? self::pharmaBranch( $label, $n, $kids )
                : self::pharmaFlat( $key, $label, $n );
        }
        $h .= '</div></div></div>';
        return $h;
    }

    private static function pharmaBranch( $label, $n, array $kids ) {
        $leaves = [];
        foreach ( $kids as $k ) {
            $leaves[ $k ] = str_replace( '_', ' ', $k );
        }
        natcasesort( $leaves );

        $h  = '<details class="tbranch"><summary class="tnode">'
            . '<span class="tw-chev"></span><div class="tnode-body">'
            . '<div class="rk">Class</div><div class="nm">'
            . '<span class="nm-t">' . htmlspecialchars( $label ) . '</span>'
            . self::countBadge( $n )
            . '</div></div></summary><div class="tfoliage">';
        foreach ( $leaves as $dbkey => $disp ) {
            $url = Title::makeTitle( NS_CATEGORY, $dbkey )->getLocalURL();
            $h .= '<div class="tleaf"><span class="lg"></span>'
                . '<a href="' . htmlspecialchars( $url ) . '">'
                . htmlspecialchars( $disp ) . '</a></div>';
        }
        $h .= '</div></details>';
        return $h;
    }

    private static function pharmaFlat( $key, $label, $n ) {
        $url = Title::makeTitle( NS_CATEGORY, $key )->getLocalURL();
        return '<div class="tbranch tbranch-flat"><div class="tnode">'
            . '<div class="tnode-body"><div class="rk">Class</div><div class="nm">'
            . '<a class="nm-t" href="' . htmlspecialchars( $url ) . '">'
            . htmlspecialchars( $label ) . '</a>'
            . self::countBadge( $n )
            . '</div></div></div></div>';
    }

    private static function plantColumn() {
        $h  = '<div class="col col-plant"><div class="col-inner">';
        $h .= '<div class="lroot">'
            . '<svg class="lr-sprig" viewBox="0 0 46 46" aria-hidden="true"><use href="#pl-sprig"/></svg>'
            . '<div><div class="lr-rank">Origin</div>'
            . '<div class="lr-name">Plants</div>'
            . '<div class="lr-count">3 volumes &middot; 11 classes &middot; the Pendell axis</div>'
            . '</div></div>';
        $h .= '<div class="lbody"><div class="trunk" aria-hidden="true"></div>';
        foreach ( self::PLANT_VOLUMES as $vol ) {
            $classes = $vol['classes'];
            $h .= '<details class="lbranch" open><summary class="lnode">'
                . '<svg class="bough" viewBox="0 0 100 92" aria-hidden="true"><use href="#pl-bough"/></svg>'
                . '<div class="lnode-top"><span class="lw-chev"></span>'
                . '<svg class="ln-glyph" viewBox="0 0 22 26"><use href="#pl-leaf"/></svg>'
                . '<span class="ln-name">' . htmlspecialchars( $vol['key'] ) . '</span>'
                . '<span class="ln-count">' . count( $classes ) . ' classes</span></div>'
                . '<div class="ln-desc">' . htmlspecialchars( $vol['desc'] ) . '</div>'
                . '</summary><div class="lfoliage">';
            foreach ( $classes as $cls ) {
                $url = Title::makeTitle( NS_CATEGORY, $cls )->getLocalURL();
                $h .= '<a class="leaf" href="' . htmlspecialchars( $url ) . '">'
                    . '<svg class="leaf-ic" viewBox="0 0 22 26"><use href="#pl-leaf"/></svg>'
                    . '<span class="lf-name">' . htmlspecialchars( $cls ) . '</span></a>';
            }
            $h .= '</div></details>';
        }
        $h .= '</div></div></div>';
        return $h;
    }

    private static function svgDefs() {
        return '<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>'
            . '<symbol id="pl-leaf" viewBox="0 0 22 26">'
            . '<path d="M11 1 C4 7 3 15 11 25 C19 15 18 7 11 1 Z" fill="currentColor"/>'
            . '<g stroke="rgba(20,11,5,0.38)" stroke-width="1" fill="none" stroke-linecap="round">'
            . '<path d="M11 4 L11 22"/><path d="M11 10 L6.4 8"/><path d="M11 10 L15.6 8"/>'
            . '<path d="M11 15 L7 14"/><path d="M11 15 L15 14"/></g></symbol>'
            . '<symbol id="pl-bough" viewBox="0 0 100 92">'
            . '<path d="M0 14 C26 12 36 26 52 34 C68 42 84 43 100 44 C86 50 66 50 49 41 C30 31 22 20 2 26 Z" fill="currentColor"/>'
            . '</symbol>'
            . '<symbol id="pl-sprig" viewBox="0 0 46 46"><g fill="currentColor">'
            . '<path d="M23 44 C18 31 19 18 23 6 C27 18 28 31 23 44 Z"/>'
            . '<path d="M23 43 C14 35 9 25 8 14 C18 17 25 30 23 43 Z"/>'
            . '<path d="M23 43 C32 35 37 25 38 14 C28 17 21 30 23 43 Z"/></g>'
            . '<path d="M23 45 L23 30" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>'
            . '</symbol></defs></svg>';
    }
}
