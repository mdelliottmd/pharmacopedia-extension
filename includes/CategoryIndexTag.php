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

    /** Curated herbal-medicines tier: 5 source-tradition sub-categories,
     *  alphabetical. The clinical-traditional axis, distinct from the
     *  Pendell entheogenic-and-psychoactive axis above. Added 2026-05-23
     *  per Mark's "lead with axis B in the visible taxonomy" directive. */
    private const HERBAL_TRADITIONS = [
        'Ayurvedic_herbs'        => 'Ayurvedic herbs',
        'Native_American_herbs'  => 'Native American herbs',
        'TCM_herbs'              => 'TCM herbs',
        'Unani_herbs'            => 'Unani herbs',
        'Western_clinical_herbs' => 'Western clinical herbs',
    ];

    /**
     * One-word / short-phrase English gloss per Latin Pendell class, shown
     * inline in the Category index as "Latin (gloss)". Canonical translation
     * by category-claude (2026-05-22), Pendell-true rather than Latin
     * dictionary lookup. Each gloss is the noun form of Pendell's own
     * TOC subtitle for the chapter (Daimonica from Gnosis chapter subtitle
     * "Toloache, Flying Herbs, and the Witch's Garden"; Excitantia takes
     * the house word "psychostimulants" per the standing terminology rule).
     */
    private const PLANT_CLASS_GLOSS = [
        'Euphorica'     => 'euphorics',
        'Evaesthetica'  => 'sensually pleasing',
        'Existentia'    => 'existential plants',
        'Inebriantia'   => 'inebriants',
        'Metaphysica'   => 'intimations',
        'Pacifica'      => 'peacemakers',
        'Rhapsodica'    => 'muses',
        'Empathogenica' => 'empathogens',
        'Excitantia'    => 'psychostimulants',
        'Daimonica'     => 'witch plants',
        'Phantastica'   => 'visionaries',
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
        $h .= self::plantColumn( $dbr );
        $h .= '</div>';
        $h .= DiptychChrome::footer();
        $h .= '</div>';
        return $h;
    }

    private static function titleBand() {
        return '<div class="titleband">'
            . '<p class="tb-eyebrow">Index</p>'
            . '<h1 class="tb-title">The category index</h1>'
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
            . '<h2 class="nm">Pharmaceutical</h2>'
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

    private static function plantColumn( $dbr ) {
        // Flat list of every Latin class across the three volumes, used as
        // the IN() set for the one-query members fetch below.
        $allClasses = [];
        foreach ( self::PLANT_VOLUMES as $vol ) {
            foreach ( $vol['classes'] as $cls ) {
                $allClasses[] = $cls;
            }
        }

        // Mainspace medicines categorized in each Latin class. One query
        // covers all 11 classes; results are grouped by parent class in PHP.
        // Multi-membership is preserved naturally - a medicine tagged with
        // two Latin classes appears under both tree positions.
        $members = array_fill_keys( $allClasses, [] );
        $res = $dbr->select(
            [ 'page', 'categorylinks', 'linktarget' ],
            [ 'medicine' => 'page.page_title', 'parent' => 'linktarget.lt_title' ],
            [
                'page.page_namespace'     => NS_MAIN,
                'linktarget.lt_namespace' => NS_CATEGORY,
                'linktarget.lt_title'     => $allClasses,
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
            if ( isset( $members[ $p ] ) ) {
                $members[ $p ][] = (string)$r->medicine;
            }
        }
        // Alphabetize each class's medicines (case-insensitive).
        foreach ( $members as $cls => $list ) {
            natcasesort( $list );
            $members[ $cls ] = array_values( $list );
        }

        $h  = '<div class="col col-plant"><div class="col-inner">';
        $h .= '<div class="lroot">'
            . '<svg class="lr-sprig" viewBox="0 0 46 46" aria-hidden="true"><use href="#pl-sprig"/></svg>'
            . '<div><div class="lr-rank">Origin</div>'
            . '<h2 class="lr-name">Plants</h2>'
            . '<div class="lr-count">3 Pendell volumes (11 classes) &middot; 1 herbal-medicines axis (5 traditions)</div>'
            . '</div></div>';
        $h .= '<div class="lbody"><div class="trunk" aria-hidden="true"></div>';
        foreach ( self::PLANT_VOLUMES as $vol ) {
            $classes = $vol['classes'];
            $h .= '<details class="lbranch" open><summary class="lnode">'
                . '<svg class="bough" viewBox="0 0 100 92" aria-hidden="true"><use href="#pl-bough"/></svg>'
                . '<div class="lnode-top"><span class="lw-chev"></span>'
                . '<svg class="ln-glyph" viewBox="0 0 22 26"><use href="#pl-leaf"/></svg>'
                . '<h3 class="ln-name">' . htmlspecialchars( $vol['key'] ) . '</h3>'
                . '<span class="ln-count">' . count( $classes ) . ' classes</span></div>'
                . '<div class="ln-desc">' . htmlspecialchars( $vol['desc'] ) . '</div>'
                . '</summary><div class="lfoliage">';
            foreach ( $classes as $cls ) {
                $meds  = $members[ $cls ] ?? [];
                $n     = count( $meds );
                $gloss = self::PLANT_CLASS_GLOSS[ $cls ] ?? '';
                // Class node: a collapsed <details>; the summary carries the
                // Latin name, the parenthetical English gloss, the direct-
                // member count, and a chevron. Members render only on expand.
                $h .= '<details class="lsub"><summary>'
                    . '<svg class="leaf-ic" viewBox="0 0 22 26"><use href="#pl-leaf"/></svg>'
                    . '<span class="lf-name">' . htmlspecialchars( $cls ) . '</span>';
                if ( $gloss !== '' ) {
                    $h .= '<span class="lf-gloss">(' . htmlspecialchars( $gloss ) . ')</span>';
                }
                $h .= '<span class="ls-count">' . $n . '</span>'
                    . '<span class="ls-chev"></span>'
                    . '</summary>'
                    . '<div class="lsfoliage">';
                foreach ( $meds as $med ) {
                    $url  = Title::makeTitle( NS_MAIN, $med )->getLocalURL();
                    $disp = str_replace( '_', ' ', $med );
                    $h   .= '<a class="lsleaf" href="' . htmlspecialchars( $url ) . '">'
                        . '<span class="lsdot"></span>'
                        . '<span class="lsname">' . htmlspecialchars( $disp ) . '</span>'
                        . '</a>';
                }
                $h .= '</div></details>';
            }
            $h .= '</div></details>';
        }

        // Herbal_medicines block -- fourth section in the plant column,
        // organised by source tradition (the clinical-traditional axis,
        // distinct from the Pendell volumes above). Added 2026-05-23.
        $herbalCounts = [];
        $herbKeys = array_keys( self::HERBAL_TRADITIONS );
        $res = $dbr->select(
            'category',
            [ 'cat_title', 'cat_pages', 'cat_subcats', 'cat_files' ],
            [ 'cat_title' => $herbKeys ],
            __METHOD__
        );
        foreach ( $res as $r ) {
            $herbalCounts[ (string)$r->cat_title ] =
                max( 0, (int)$r->cat_pages - (int)$r->cat_subcats - (int)$r->cat_files );
        }

        $h .= '<details class="lbranch lbranch-herbal" open><summary class="lnode">'
            . '<svg class="bough" viewBox="0 0 100 92" aria-hidden="true"><use href="#pl-bough"/></svg>'
            . '<div class="lnode-top"><span class="lw-chev"></span>'
            . '<svg class="ln-glyph" viewBox="0 0 22 26"><use href="#pl-leaf"/></svg>'
            . '<h3 class="ln-name">Herbal medicines</h3>'
            . '<span class="ln-count">' . count( self::HERBAL_TRADITIONS ) . ' traditions</span></div>'
            . '<div class="ln-desc">The clinical-traditional plants: medicinal use across the world\'s medicine systems, organised by source tradition.</div>'
            . '</summary><div class="lfoliage">';
        foreach ( self::HERBAL_TRADITIONS as $dbkey => $label ) {
            $n   = $herbalCounts[ $dbkey ] ?? 0;
            $url = Title::makeTitle( NS_CATEGORY, $dbkey )->getLocalURL();
            $h  .= '<details class="lsub"><summary>'
                . '<svg class="leaf-ic" viewBox="0 0 22 26"><use href="#pl-leaf"/></svg>'
                . '<a class="lf-name" href="' . htmlspecialchars( $url ) . '">'
                . htmlspecialchars( $label ) . '</a>'
                . '<span class="ls-count">' . $n . '</span>'
                . '<span class="ls-chev"></span>'
                . '</summary>'
                . '<div class="lsfoliage"></div></details>';
        }
        $h .= '</div></details>';

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
