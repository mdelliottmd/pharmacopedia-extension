<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\Parser\Parser;
use MediaWiki\Extension\Pharmacopedia\KineticsHelper;
use PPFrame;
use MediaWiki\Title\Title;

/**
 * Read-only render for the new Interactions section.
 *
 * Drop <pharmaInteractions/> anywhere in a medicine article or Category page.
 *  - On medicine pages: lists direct medicine-to-medicine / medicine-to-category
 *    interactions plus transitive category-to-anything edges via the page's
 *    own categories. Direct rows win over transitive ones for the same counterparty.
 *  - On Category pages: lists direct edges only.
 *
 * PGx rows also carry a granular vote panel (see renderPgxVotePanel).
 */
class InteractionTag {
    /* ============================================================
       Pharmacogenomic rendering surface (2026-05-18).
       Tiered render emitted by renderPgxSection() when any
       pi_relationship != 'unspecified' rows exist for the page.
       ============================================================ */

    private const PGX_EVIDENCE_TIER = [
        'fda_box'       => 1,
        'fda_label'     => 2, 'cpic_strong' => 2, 'cpic_A'      => 2,
        'cpic_moderate' => 2, 'cpic_B'      => 2, 'dpwg'        => 2,
        'cpic_optional' => 3, 'cpic_C'      => 3, 'cpic_D'      => 3,
        'primary'       => 3, 'theoretical' => 3,
        'derived'       => 4,
    ];

    private const PGX_TIER_HEADERS = [
        1 => [ 'FDA Boxed Warning',
               'Highest-evidence regulatory action' ],
        2 => [ 'Pharmacogenomic guideline recommendations',
               'CPIC and Dutch Pharmacogenetics Working Group clinical guidelines' ],
        3 => [ 'Pharmacokinetic mechanism',
               'Substrate / metabolism relationships from primary literature' ],
        4 => [ 'Inferred from pharmacokinetic data',
               'Materialised by the inference engine; provenance shown per row' ],
    ];

    private const PGX_EVIDENCE_LABELS = [
        'fda_box'       => 'FDA Boxed',
        'fda_label'     => 'FDA Label',
        'cpic_strong'   => 'CPIC Strong',
        'cpic_A'        => 'CPIC A',
        'cpic_moderate' => 'CPIC Moderate',
        'cpic_B'        => 'CPIC B',
        'cpic_optional' => 'CPIC Optional',
        'cpic_C'        => 'CPIC C',
        'cpic_D'        => 'CPIC D',
        'dpwg'          => 'DPWG',
        'primary'       => 'Primary',
        'theoretical'   => 'Theoretical',
        'derived'       => 'Inferred',
    ];

    private const PGX_EVIDENCE_CSS = [
        'fda_box'       => 'is-fda-box',
        'fda_label'     => 'is-fda-label',
        'cpic_strong'   => 'is-cpic-strong',
        'cpic_A'        => 'is-cpic-strong',
        'cpic_moderate' => 'is-cpic-mod',
        'cpic_B'        => 'is-cpic-mod',
        'cpic_optional' => 'is-cpic-opt',
        'cpic_C'        => 'is-cpic-opt',
        'cpic_D'        => 'is-cpic-opt',
        'dpwg'          => 'is-cpic-mod',
        'primary'       => 'is-primary',
        'theoretical'   => 'is-primary',
        'derived'       => 'is-derived',
    ];

    private const PGX_PHENOTYPE_LABELS = [
        'cyp2d6_pm'  => 'CYP2D6 poor metabolizer',
        'cyp2d6_im'  => 'CYP2D6 intermediate metabolizer',
        'cyp2d6_nm'  => 'CYP2D6 normal metabolizer',
        'cyp2d6_rm'  => 'CYP2D6 rapid metabolizer',
        'cyp2d6_um'  => 'CYP2D6 ultrarapid metabolizer',
        'cyp2c19_pm' => 'CYP2C19 poor metabolizer',
        'cyp2c19_im' => 'CYP2C19 intermediate metabolizer',
        'cyp2c19_nm' => 'CYP2C19 normal metabolizer',
        'cyp2c19_rm' => 'CYP2C19 rapid metabolizer',
        'cyp2c19_um' => 'CYP2C19 ultrarapid metabolizer',
        'cyp2c9_pm'  => 'CYP2C9 poor metabolizer',
        'cyp2c9_im'  => 'CYP2C9 intermediate metabolizer',
        'cyp2c9_nm'  => 'CYP2C9 normal metabolizer',
        'cyp3a4_pm'  => 'CYP3A4 poor metabolizer',
        'cyp3a4_im'  => 'CYP3A4 intermediate metabolizer',
        'cyp3a4_nm'  => 'CYP3A4 normal metabolizer',
        'tpmt_pm'    => 'TPMT poor metabolizer',
        'tpmt_im'    => 'TPMT intermediate metabolizer',
        'tpmt_nm'    => 'TPMT normal metabolizer',
        'dpyd_pm'    => 'DPYD poor metabolizer',
        'dpyd_im'    => 'DPYD intermediate metabolizer',
        'dpyd_nm'    => 'DPYD normal metabolizer',
        'ugt1a1_pm'  => 'UGT1A1 poor metabolizer',
        'ugt1a1_im'  => 'UGT1A1 intermediate metabolizer',
        'ugt1a1_nm'  => 'UGT1A1 normal metabolizer',
        'slco1b1_pf' => 'SLCO1B1 poor function',
        'slco1b1_im' => 'SLCO1B1 intermediate function',
        'slco1b1_nm' => 'SLCO1B1 normal function',
    ];

    private const PGX_RISK_RELS = [
        'avoid', 'contraindication', 'contraindicated', 'toxicity_risk',
        'qt_combined', 'serotonin_syndrome_risk', 'bleeding_risk',
        'risk_SCAR', 'risk_hypersensitivity', 'risk_hepatotoxicity',
        'risk_hematologic', 'risk_ototoxicity', 'risk_qt',
        'efficacy_loss', 'toxicity_general',
    ];
    private const PGX_SAFE_RELS = [ 'normal_dose', 'efficacy_gain' ];
    private const PGX_INFO_RELS = [
        'monitor', 'prefer_alternative',
        'dose_reduce_25', 'dose_reduce_50', 'dose_increase',
        'pd_additive', 'pd_opposing',
    ];

    /** Per-row classifier: which CSS family does this relationship belong to? */
    private static function pgxRelClass( string $rel ): string {
        if ( in_array( $rel, self::PGX_RISK_RELS, true ) ) return 'is-risk';
        if ( in_array( $rel, self::PGX_SAFE_RELS, true ) ) return 'is-safe';
        if ( strpos( $rel, 'pk_via_' ) === 0 || strpos( $rel, 'pk_' ) === 0 ) return 'is-pk';
        if ( in_array( $rel, self::PGX_INFO_RELS, true ) ) return 'is-info';
        return ''; // default purple
    }

    /** Format the relationship for display: lower-case with spaces, e.g. "prodrug activated by". */
    private static function pgxRelLabel( string $rel ): string {
        // pk_via_CYP2D6 -> "pk via CYP2D6" (keep enzyme symbol upper)
        if ( strpos( $rel, 'pk_via_' ) === 0 ) {
            return 'pk via ' . substr( $rel, 7 );
        }
        return str_replace( '_', ' ', $rel );
    }

    /** Phenotype slug -> human label, falling back to slug-with-spaces. */
    private static function pgxPhenotypeLabel( string $slug ): string {
        return self::PGX_PHENOTYPE_LABELS[ $slug ] ?? str_replace( '_', ' ', $slug );
    }

    /** Display label for the counterparty cell, with namespace prefix and CSS class. */
    private static function pgxCounterparty( string $type, string $slug ): array {
        switch ( $type ) {
            case InteractionStore::TYPE_ENZYME:
                return [ 'prefix' => 'Enzyme', 'name' => $slug,
                         'title' => 'Enzyme:' . $slug, 'cssClass' => 'is-enzyme' ];
            case InteractionStore::TYPE_TRANSPORTER:
                return [ 'prefix' => 'Transporter', 'name' => $slug,
                         'title' => 'Transporter:' . $slug, 'cssClass' => 'is-transporter' ];
            case InteractionStore::TYPE_PHENOTYPE:
                return [ 'prefix' => 'Phenotype', 'name' => self::pgxPhenotypeLabel( $slug ),
                         'title' => 'Phenotype:' . $slug, 'cssClass' => 'is-phenotype' ];
            case InteractionStore::TYPE_VARIANT:
                return [ 'prefix' => 'Variant', 'name' => str_replace( '_', ' ', $slug ),
                         'title' => 'Variant:' . $slug, 'cssClass' => 'is-variant' ];
            case InteractionStore::TYPE_CATEGORY:
                return [ 'prefix' => 'Category', 'name' => str_replace( '_', ' ', $slug ),
                         'title' => 'Category:' . $slug, 'cssClass' => 'is-category' ];
            default: // medicine
                return [ 'prefix' => '', 'name' => str_replace( '_', ' ', $slug ),
                         'title' => str_replace( '_', ' ', $slug ), 'cssClass' => '' ];
        }
    }

    /** Render the full PGx section for the medicine $slug, or '' if no PGx rows. */
    private static function renderPgxSection( InteractionStore $store, string $mySlug ): string {
        $rows = $store->listForEndpoint( InteractionStore::TYPE_MEDICINE, $mySlug );
        // Filter to PGx-typed rows.
        $pgx = [];
        foreach ( $rows as $r ) {
            $rel = (string)( $r->pi_relationship ?? '' );
            if ( $rel === '' || $rel === InteractionStore::REL_UNSPECIFIED ) continue;
            $pgx[] = $r;
        }
        if ( !$pgx ) return '';

        // Orient + classify by evidence tier.
        $byTier = [ 1 => [], 2 => [], 3 => [], 4 => [] ];
        foreach ( $pgx as $r ) {
            $oriented = self::pgxOrient( $r, InteractionStore::TYPE_MEDICINE, $mySlug );
            if ( $oriented === null ) continue;
            $evid = (string)( $r->pi_evidence ?? '' );
            $tier = self::PGX_EVIDENCE_TIER[ $evid ] ?? 3;
            $byTier[ $tier ][] = [ 'row' => $r, 'other' => $oriented ];
        }

        // Sort each tier by intensity desc.
        foreach ( $byTier as $t => &$entries ) {
            usort( $entries, function ( $a, $b ) {
                $ai = (int)( $a['row']->pi_intensity ?? 0 );
                $bi = (int)( $b['row']->pi_intensity ?? 0 );
                return $bi <=> $ai;
            } );
        }
        unset( $entries );

        $totalEdges = count( $pgx );
        $h  = '<div class="pcp-pgx-header-band">';
        $h .= '<span class="pcp-pgx-header-title">Pharmacogenomic + mechanism interactions</span>';
        $h .= '<span class="pcp-pgx-header-meta">' . (int)$totalEdges . ' edge' . ( $totalEdges === 1 ? '' : 's' ) . '</span>';
        $h .= '</div>';

        foreach ( [ 1, 2, 3, 4 ] as $t ) {
            if ( !$byTier[ $t ] ) continue;
            $h .= self::renderPgxTier( $t, $byTier[ $t ] );
        }
        return $h;
    }

    private static function renderPgxTier( int $tier, array $entries ): string {
        [ $title, $sub ] = self::PGX_TIER_HEADERS[ $tier ];
        $h  = '<div class="pcp-pgx-section pcp-pgx-tier-' . $tier . '">';
        $h .= '<div class="pcp-pgx-tier-head">';
        $h .= '<span class="pcp-pgx-tier-title">' . htmlspecialchars( $title ) . '</span>';
        $h .= '<span class="pcp-pgx-tier-sub">' . htmlspecialchars( $sub ) . '</span>';
        $h .= '</div>';
        foreach ( $entries as $e ) {
            $h .= self::renderPgxRow( $e['row'], $e['other'] );
        }
        $h .= '</div>';
        return $h;
    }

    private static function renderPgxRow( $row, array $other ): string {
        $rel       = (string)( $row->pi_relationship ?? '' );
        $intensity = $row->pi_intensity !== null ? (int)$row->pi_intensity : null;
        $evid      = (string)( $row->pi_evidence ?? '' );
        $mech      = (string)( $row->pi_mechanism ?? '' );
        $kinetics  = (string)( $row->pi_kinetics ?? '' );

        $cp = self::pgxCounterparty( $other['type'], $other['slug'] );
        $titleObj = \MediaWiki\Title\Title::newFromText( $cp['title'] );
        $url = $titleObj ? $titleObj->getLocalURL() : '#';

        $relCls   = self::pgxRelClass( $rel );
        $relText  = self::pgxRelLabel( $rel );
        $evidCss  = self::PGX_EVIDENCE_CSS[ $evid ] ?? '';
        $evidLab  = self::PGX_EVIDENCE_LABELS[ $evid ] ?? ucfirst( str_replace( '_', ' ', $evid ) );

        $eid = (int)( $row->pi_element_id ?? 0 );
        $h  = '<div class="pcp-pgx-row pcp-row" data-element-id="' . $eid . '">';
        $h .= '<div class="pcp-pgx-row-head">';
        $h .= '<a class="pcp-pgx-counterparty ' . htmlspecialchars( $cp['cssClass'] ) . '" href="' . htmlspecialchars( $url ) . '">';
        if ( $cp['prefix'] !== '' ) {
            $h .= '<span class="ns-prefix">' . htmlspecialchars( $cp['prefix'] ) . ':</span>';
        }
        $h .= htmlspecialchars( $cp['name'] ) . '</a>';
        if ( $rel !== '' ) {
            $h .= ' <span class="pcp-pgx-rel ' . htmlspecialchars( $relCls ) . '">' . htmlspecialchars( $relText ) . '</span>';
        }
        if ( $evid !== '' ) {
            $h .= ' <span class="pcp-pgx-evid ' . htmlspecialchars( $evidCss ) . '">' . htmlspecialchars( $evidLab ) . '</span>';
        }
        if ( $intensity !== null ) {
            $h .= ' <span class="pcp-pgx-intensity-wrap">';
            $h .= '<span class="pcp-pgx-intensity"><span class="pcp-pgx-intensity-fill" style="width:' . max( 0, min( 100, $intensity ) ) . '%"></span></span>';
            $h .= '<span class="pcp-pgx-intensity-val">' . (int)$intensity . ' / 100</span>';
            $h .= '</span>';
        }
        $h .= '<span class="pcp-row-actions pcp-pgx-row-actions">';
        $h .= '<button type="button" class="pcp-row-action pcp-row-action-toggle pcp-pgx-vote-toggle" data-target="pgxvote" aria-expanded="false">Rate</button>';
        $h .= '</span>';
        $h .= '</div>'; // /row-head

        if ( $mech !== '' ) {
            $h .= '<div class="pcp-pgx-mech">' . htmlspecialchars( $mech ) . '</div>';
        }
        $kphrase = KineticsHelper::getHint( $kinetics );
        if ( $kphrase !== null ) {
            $kcls = KineticsHelper::isPersistent( $kinetics ) ? 'is-persistent' : '';
            $h .= '<span class="pcp-pgx-kinetics ' . $kcls . '"><span class="clock">⏱</span> ' . htmlspecialchars( $kphrase ) . '</span>';
        }

        // Provenance for derived edges: "Inferred via <Enzyme:X>" parsed from pk_via_X.
        // Derived-edge provenance. Relationship is one of:
        //   pk_inhibit_via_<E>  enzyme inhibition raises substrate exposure
        //   pk_induce_via_<E>   enzyme induction lowers substrate exposure
        //   pk_via_<E>          legacy direction-agnostic form
        if ( $evid === 'derived' ) {
            $enzymeSlug = null;
            $direction  = '';
            // Current outcome-named codes:
            if ( strpos( $rel, 'pk_raises_via_' ) === 0 ) {
                $enzymeSlug = substr( $rel, 14 );
                $direction  = ' (exposure raised)';
            } elseif ( strpos( $rel, 'pk_lowers_via_' ) === 0 ) {
                $enzymeSlug = substr( $rel, 14 );
                $direction  = ' (exposure lowered)';
            // Legacy mechanism-named codes (back-compat):
            } elseif ( strpos( $rel, 'pk_inhibit_via_' ) === 0 ) {
                $enzymeSlug = substr( $rel, 15 );
                $direction  = ' (exposure raised)';
            } elseif ( strpos( $rel, 'pk_induce_via_' ) === 0 ) {
                $enzymeSlug = substr( $rel, 14 );
                $direction  = ' (exposure lowered)';
            } elseif ( strpos( $rel, 'pk_via_' ) === 0 ) {
                $enzymeSlug = substr( $rel, 7 );
            }
            if ( $enzymeSlug !== null && $enzymeSlug !== '' ) {
                $eTitle = \MediaWiki\Title\Title::newFromText( 'Enzyme:' . $enzymeSlug );
                $eUrl = $eTitle ? $eTitle->getLocalURL() : '#';
                $h .= '<div class="pcp-pgx-provenance">Inferred via <a href="'
                    . htmlspecialchars( $eUrl ) . '"><span class="ns-prefix">Enzyme:</span>'
                    . htmlspecialchars( $enzymeSlug ) . '</a>'
                    . htmlspecialchars( $direction ) . '</div>';
            }
        }

        $h .= self::renderPgxVotePanel( $eid, $evid === 'derived', $mech !== '', $kphrase !== null );
        $h .= '</div>'; // /row
        return $h;
    }

    /**
     * Granular vote panel for one PGx interaction row. Queued 2026-05-19
     * ("rather granular voting on these interaction elements"). Collapsed
     * by default; the row's "Rate" toggle reveals it.
     *
     * Five curation dimensions write to pcp_interaction_flags via
     * action=pcp-interaction-flag: clinical_relevance and derived_confidence
     * are 1..5 scales, mechanism_flag is 1..3, kinetics_flag and noise are
     * single flags. The personal-experience block reuses
     * pcp_interaction_reports. Submit + aggregate wiring lives in
     * ext.pharmacopedia.js (.pcp-pgx-vote).
     *
     * derived_confidence renders only on tier-4 (derived) edges; the
     * mechanism and kinetics dimensions render only when the row carries
     * that annotation.
     */
    private static function renderPgxVotePanel(
        int $elementId, bool $isDerived, bool $hasMech, bool $hasKinetics
    ): string {
        $h  = '<div class="pcp-row-panel pcp-row-pgxvote-panel pcp-pgx-vote"';
        $h .= ' data-element-id="' . $elementId . '" hidden>';
        $h .= '<p class="pcp-pgx-vote-intro">Rate this interaction. Reports are anonymous and help curate the page.</p>';

        // clinical_relevance: 1..5 scale, on every edge.
        $h .= '<div class="pcp-pgx-vote-dim" data-flag-type="clinical_relevance">';
        $h .= '<span class="pcp-pgx-vote-q">Clinical relevance: does this interaction matter in practice?</span>';
        $h .= '<span class="pcp-pgx-vote-scale">';
        $h .= '<span class="pcp-pgx-vote-scale-end">trivial</span>';
        for ( $i = 1; $i <= 5; $i++ ) {
            $h .= '<button type="button" class="pcp-pgx-vote-btn" data-flag-value="' . $i . '">' . $i . '</button>';
        }
        $h .= '<span class="pcp-pgx-vote-scale-end">critical</span>';
        $h .= '</span>';
        $h .= '<span class="pcp-pgx-vote-agg" hidden></span>';
        $h .= '</div>';

        // derived_confidence: 1..5 scale, tier-4 (derived) edges only.
        if ( $isDerived ) {
            $h .= '<div class="pcp-pgx-vote-dim" data-flag-type="derived_confidence">';
            $h .= '<span class="pcp-pgx-vote-q">Confidence in this inference: is the inferred magnitude sound?</span>';
            $h .= '<span class="pcp-pgx-vote-scale">';
            $h .= '<span class="pcp-pgx-vote-scale-end">overstated</span>';
            for ( $i = 1; $i <= 5; $i++ ) {
                $h .= '<button type="button" class="pcp-pgx-vote-btn" data-flag-value="' . $i . '">' . $i . '</button>';
            }
            $h .= '<span class="pcp-pgx-vote-scale-end">sound</span>';
            $h .= '</span>';
            $h .= '<span class="pcp-pgx-vote-agg" hidden></span>';
            $h .= '</div>';
        }

        // mechanism_flag: 1..3 options, edges that carry mechanism prose.
        if ( $hasMech ) {
            $h .= '<div class="pcp-pgx-vote-dim" data-flag-type="mechanism_flag">';
            $h .= '<span class="pcp-pgx-vote-q">Mechanism description, if it needs work:</span>';
            $h .= '<span class="pcp-pgx-vote-opts">';
            foreach ( [ 1 => 'Outdated', 2 => 'Inaccurate', 3 => 'Misleading' ] as $v => $lbl ) {
                $h .= '<button type="button" class="pcp-pgx-vote-opt" data-flag-value="' . $v . '">' . $lbl . '</button>';
            }
            $h .= '</span>';
            $h .= '<span class="pcp-pgx-vote-agg" hidden></span>';
            $h .= '</div>';
        }

        // kinetics_flag: single flag, edges that carry a kinetics annotation.
        if ( $hasKinetics ) {
            $h .= '<div class="pcp-pgx-vote-dim pcp-pgx-vote-dim-flag" data-flag-type="kinetics_flag">';
            $h .= '<span class="pcp-pgx-vote-q">Kinetics annotation:</span>';
            $h .= '<button type="button" class="pcp-pgx-vote-flag" data-flag-value="1">Dispute the half-life claim</button>';
            $h .= '<span class="pcp-pgx-vote-agg" hidden></span>';
            $h .= '</div>';
        }

        // noise: single flag, on every edge.
        $h .= '<div class="pcp-pgx-vote-dim pcp-pgx-vote-dim-flag" data-flag-type="noise">';
        $h .= '<span class="pcp-pgx-vote-q">Is this row worth surfacing?</span>';
        $h .= '<button type="button" class="pcp-pgx-vote-flag" data-flag-value="1">Flag as low-value noise</button>';
        $h .= '<span class="pcp-pgx-vote-agg" hidden></span>';
        $h .= '</div>';

        // personal experience: reuses pcp_interaction_reports.
        $h .= '<div class="pcp-pgx-vote-dim pcp-pgx-vote-experience">';
        $h .= '<span class="pcp-pgx-vote-q">Your own experience with this combination:</span>';
        $h .= '<div class="pcp-pgx-vote-exp-grid">';
        $h .= '<div class="pcp-pgx-vote-exp-sub">';
        $h .= '<span class="pcp-pgx-vote-exp-label">Experience (1 a little, 5 a lot)</span>';
        $h .= '<span class="pcp-pgx-vote-exprow">';
        for ( $i = 1; $i <= 5; $i++ ) {
            $h .= '<button type="button" class="pcp-pgx-vote-expbtn" data-experience="' . $i . '">' . $i . '</button>';
        }
        $h .= '</span>';
        $h .= '</div>';
        $h .= '<div class="pcp-pgx-vote-exp-sub pcp-pgx-vote-valrow pcp-disabled">';
        $h .= '<span class="pcp-pgx-vote-exp-label">Outcome (-100 worst, +100 best)</span>';
        $h .= '<span class="pcp-pgx-vote-vslider-wrap">';
        $h .= '<span class="pcp-pgx-vote-vanchor">-100</span>';
        $h .= '<input type="range" class="pcp-pgx-vote-vslider" min="-100" max="100" step="1" value="0" oninput="this.nextElementSibling.value=(this.value>=0?\'+\':\'\')+this.value">';
        $h .= '<output class="pcp-pgx-vote-vout">0</output>';
        $h .= '<span class="pcp-pgx-vote-vanchor">+100</span>';
        $h .= '</span>';
        $h .= '</div>';
        $h .= '</div>';
        $h .= '</div>';

        $h .= '<div class="pcp-pgx-vote-status" hidden></div>';
        $h .= '</div>';
        return $h;
    }

    /** Orient a row so we always have the OTHER side relative to ($myType, $mySlug). */
    private static function pgxOrient( $row, string $myType, string $mySlug ): ?array {
        $lt = (string)$row->pi_left_type;
        $ls = (string)$row->pi_left_slug;
        $rt = (string)$row->pi_right_type;
        $rs = (string)$row->pi_right_slug;
        if ( $lt === $myType && $ls === $mySlug ) {
            return [ 'type' => $rt, 'slug' => $rs ];
        }
        if ( $rt === $myType && $rs === $mySlug ) {
            return [ 'type' => $lt, 'slug' => $ls ];
        }
        return null;
    }


    public static function render( $input, array $args, Parser $parser, PPFrame $frame ) {
        $title = $parser->getTitle();
        if ( !$title ) { return ''; }
        $ns = $title->getNamespace();
        if ( $ns !== NS_MAIN && $ns !== NS_CATEGORY ) {
            return '<div class="pcp-interactions pcp-interactions-skipped"><em>' .
                   'The Interactions section only renders on medicine articles and Category pages.' .
                   '</em></div>';
        }

        $store    = new InteractionStore();
        $pageSlug = $title->getDBkey();
        $entries  = [];

        if ( $ns === NS_CATEGORY ) {
            $entries = $store->listForCategory( $pageSlug );
        } else {
            // NS_MAIN -- collect this page's categories (DB-key form, no prefix).
            $categories = [];
            foreach ( array_keys( $title->getParentCategories() ) as $catTitleText ) {
                $t = Title::newFromText( $catTitleText );
                if ( $t && $t->getNamespace() === NS_CATEGORY ) {
                    $categories[] = $t->getDBkey();
                }
            }
            $entries = $store->listForMedicineWithCategories( $pageSlug, $categories );
        }

        // Filter PGx-typed rows out of the experience entries so they
        // don't double-render. The PGx section below picks them up.
        $entries = array_values( array_filter( $entries, function ( $e ) {
            $rel = (string)( $e['row']->pi_relationship ?? '' );
            return $rel === '' || $rel === InteractionStore::REL_UNSPECIFIED;
        } ) );

        // PGx section (medicine pages only).
        $pgxHtml = '';
        if ( $ns === NS_MAIN ) {
            $pgxHtml = self::renderPgxSection( $store, $pageSlug );
        }

        $parser->getOutput()->updateCacheExpiry( 0 );
        $parser->getOutput()->addModules( [ 'ext.pharmacopedia' ] );
        $parser->getOutput()->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        if ( empty( $entries ) && $pgxHtml === '' ) {
            return '<div class="pcp-interactions">' .
                '<div class="pcp-interactions-empty"><em>No interactions reported yet.</em></div>' .
                self::renderAddButton( $title ) .
                '</div>';
        }

        // Pre-compute pooled / per-perspective aggregates once per row.
        $rendered = [];
        foreach ( $entries as $e ) {
            $eid = (int)$e['row']->pi_element_id;
            $rendered[] = [
                'entry'    => $e,
                'pooled'   => $store->getAggregates( $eid ),
                'user'     => $store->getAggregates( $eid, InteractionStore::PERSPECTIVE_USER ),
                'provider' => $store->getAggregates( $eid, InteractionStore::PERSPECTIVE_PROVIDER ),
            ];
        }

        // Sort by pooled valence_mean ASCENDING: most-negative outcome on top.
        // Nulls (no reports) sink to the bottom. Tiebreaker: n desc, then alphabetic.
        usort( $rendered, function ( $a, $b ) {
            $av = $a['pooled']['valence_mean'];
            $bv = $b['pooled']['valence_mean'];
            if ( $av === null && $bv !== null ) { return 1; }
            if ( $av !== null && $bv === null ) { return -1; }
            if ( $av !== null && $bv !== null && $av !== $bv ) {
                return $av <=> $bv;
            }
            $an = (int)$a['pooled']['n']; $bn = (int)$b['pooled']['n'];
            if ( $an !== $bn ) { return $bn - $an; }
            return strcmp( $a['entry']['other_slug'], $b['entry']['other_slug'] );
        } );

        $html = '<div class="pcp-interactions">';
        $html .= $pgxHtml;
        if ( !empty( $rendered ) ) {
            if ( $pgxHtml !== '' ) {
                $html .= '<h3 class="pcp-experience-section-title">Patient experience</h3>';
            }
            foreach ( $rendered as $r ) {
                $html .= self::renderRow( $r );
            }
        } elseif ( $pgxHtml !== '' ) {
            $html .= '<h3 class="pcp-experience-section-title">Patient experience</h3>';
            $html .= '<div class="pcp-interactions-empty"><em>No patient-experience reports yet.</em></div>';
        }
        $html .= self::renderAddButton( $title );
        $html .= '</div>';
        return $html;
    }

    /**
     * Inline rating widget rendered under each interaction row. The actual
     * button event handlers live in ext.pharmacopedia.js (.pcp-interaction-rate-* selectors).
     * Server-side we only emit markup + the current user's existing report values
     * as data-* attrs so the buttons can pre-highlight without an extra API call.
     */
    /**
     * Public reader-facing notes block.
     * Renders a <details>Notes (N)</details> listing every report's note for this
     * interaction, attributed to the reporter and tagged by perspective.
     * Returns '' when no notes exist.
     */
    /** Returns [ 'count' => int, 'html' => string ] for the notes panel under a row. */
    private static function renderNotesPanel( $elementId, $store ) {
        $reports = $store->listReports( $elementId );
        $withNotes = [];
        foreach ( $reports as $r ) {
            if ( $r->pir_note !== null && trim( (string)$r->pir_note ) !== '' ) {
                $withNotes[] = $r;
            }
        }
        if ( !$withNotes ) { return [ 'count' => 0, 'html' => '' ]; }
        $h  = '<div class="pcp-row-panel pcp-row-notes-panel pcp-interaction-notes" hidden>';
        $h .= '<ul class="pcp-interaction-notes-list">';
        foreach ( $withNotes as $r ) {
            $isProvider = (int)$r->pir_perspective === InteractionStore::PERSPECTIVE_PROVIDER;
            $icon = $isProvider ? '⚕️' : '👤';
            // Anonymized: row no longer carries user_name.
            $name = 'Anonymous';
            $ts = $r->pir_updated ?: $r->pir_created;
            $tsFmt = '';
            if ( $ts ) {
                $tsFmt = substr( $ts, 0, 4 ) . '-' . substr( $ts, 4, 2 ) . '-' . substr( $ts, 6, 2 );
            }
            $body = htmlspecialchars( (string)$r->pir_note, ENT_QUOTES, 'UTF-8' );
            $body = nl2br( $body );
            $h .= '<li class="pcp-interaction-note' . ( $isProvider ? ' pcp-interaction-note-provider' : '' ) . '"' .
                  // anonymized: no author id
                  ' data-author-name="' . htmlspecialchars( $name ) . '"' .
                  ' data-perspective="' . (int)$r->pir_perspective . '"' .
                  ' data-element-id="' . (int)$elementId . '">';
            $h .= '<div class="pcp-interaction-note-meta">';
            $h .= '<span class="pcp-interaction-note-icon">' . $icon . '</span> ';
            $h .= '<span class="pcp-interaction-note-user">' . htmlspecialchars( $name ) . '</span> ';
            if ( $tsFmt ) {
                $h .= '<span class="pcp-interaction-note-ts">' . htmlspecialchars( $tsFmt ) . '</span>';
            }
            $h .= '<button type="button" class="pcp-ix-del-note" title="Delete this note" aria-label="Delete note">×</button>';
            $h .= '</div>';
            $h .= '<div class="pcp-interaction-note-body">' . $body . '</div>';
            $h .= '</li>';
        }
        $h .= '</ul></div>';
        return [ 'count' => count( $withNotes ), 'html' => $h ];
    }

    private static function renderRateWidget( $elementId, $user, $store ) {
        $loggedIn = $user && $user->isRegistered();
        $canProvider = $loggedIn && $user->isAllowed( 'pharmacopedia-effect-as-provider' );

        // Pre-fetch the user's current votes (both perspectives) for state seeding.
        $userVotes = [ 1 => [ 'exp' => null, 'val' => null ],
                       2 => [ 'exp' => null, 'val' => null ] ];
        if ( $loggedIn ) {
            foreach ( [ 1, 2 ] as $p ) {
                $r = $store->getUserReport( $elementId, $user->getId(), $p );
                if ( $r ) {
                    $userVotes[$p]['exp'] = $r->pir_experience !== null ? (int)$r->pir_experience : null;
                    $userVotes[$p]['val'] = $r->pir_valence    !== null ? (int)$r->pir_valence    : null;
                }
            }
        }
        // Also fetch the user's existing notes for both perspectives
        $userNotes = [ 1 => '', 2 => '' ];
        if ( $loggedIn ) {
            foreach ( [ 1, 2 ] as $p ) {
                $r = $store->getUserReport( $elementId, $user->getId(), $p );
                if ( $r && $r->pir_note !== null ) {
                    $userNotes[$p] = (string)$r->pir_note;
                }
            }
        }
        $dataAttrs =
            ' data-user-1-exp="' . ( $userVotes[1]['exp'] ?? '' ) . '"' .
            ' data-user-1-val="' . ( $userVotes[1]['val'] ?? '' ) . '"' .
            ' data-user-1-note="' . htmlspecialchars( $userNotes[1], ENT_QUOTES, 'UTF-8' ) . '"' .
            ' data-user-2-exp="' . ( $userVotes[2]['exp'] ?? '' ) . '"' .
            ' data-user-2-val="' . ( $userVotes[2]['val'] ?? '' ) . '"' .
            ' data-user-2-note="' . htmlspecialchars( $userNotes[2], ENT_QUOTES, 'UTF-8' ) . '"' .
            ' data-can-provider="' . ( $canProvider ? '1' : '0' ) . '"' .
            ' data-logged-in="'   . ( $loggedIn    ? '1' : '0' ) . '"';

        $h  = '<div class="pcp-row-panel pcp-row-rate-panel pcp-interaction-rate"' . $dataAttrs . ' hidden>';

        // Perspective selector (rendered for everyone; UI hides it if not eligible)
        $h .= '<div class="pcp-interaction-persp-row">';
        $h .= '<label><input type="radio" name="pcp-ix-persp-' . $elementId . '" value="1" checked> Personal experience</label> ';
        $h .= '<label class="pcp-ix-persp-provider"><input type="radio" name="pcp-ix-persp-' . $elementId . '" value="2"> As a clinician (provider)</label>';
        $h .= '</div>';

        // Experience row (1-5)
        $h .= '<div class="pcp-interaction-q">How much experience do you have with this combination (1 a little, 5 a lot)?</div>';
        $h .= '<div class="pcp-interaction-btnrow pcp-interaction-exprow">';
        for ( $i = 1; $i <= 5; $i++ ) {
            $h .= '<button type="button" class="pcp-ix-expbtn" data-experience="' . $i . '">' . $i . '</button>';
        }
        $h .= '</div>';

        // Valence row (-100..+100)
        $h .= '<div class="pcp-interaction-q">How did it go? (-100 worst, +100 best)</div>';
        $h .= '<div class="pcp-interaction-btnrow pcp-interaction-valrow">';
        $h .= '<span class="pcp-effect-vslider-wrap pcp-ix-vslider-wrap">';
        $h .= '<span class="pcp-effect-vslider-anchor pcp-effect-vslider-anchor-neg">−100</span>';
        $h .= '<input type="range" class="pcp-ix-vslider" aria-label="How did this interaction go, worst to best" min="-100" max="100" step="1" value="0" oninput="this.nextElementSibling.value=(this.value>=0?\'+\':\'\')+this.value">';
        $h .= '<output class="pcp-effect-vslider-out">0</output>';
        $h .= '<span class="pcp-effect-vslider-anchor pcp-effect-vslider-anchor-pos">+100</span>';
        $h .= '</span>';
        $h .= '</div>';

        // Optional note (hidden behind a toggle)
        $h .= '<div class="pcp-interaction-note-wrap">';
        $h .= '<a class="pcp-ix-note-toggle" href="#">+ Add a note</a>';
        $h .= '<div class="pcp-ix-note" hidden>';
        $h .= '<textarea class="pcp-ix-note-input" aria-label="Note, what happened" rows="2" maxlength="8000" placeholder="What happened? (optional)"></textarea>';
        $h .= '<button type="button" class="pcp-ix-note-save mw-ui-button mw-ui-progressive">Save note</button>';
        $h .= '<div class="pcp-ix-note-status"></div>';
        $h .= '</div>';
        $h .= '</div>';

        $h .= '</div>'; // /panel
        return $h;
    }

    private static function renderAddButton( $pageTitle ) {
        $ns = $pageTitle->getNamespace();
        $type = ( $ns === NS_CATEGORY ) ? InteractionStore::TYPE_CATEGORY : InteractionStore::TYPE_MEDICINE;
        $slug = $pageTitle->getDBkey();
        $h  = '<div class="pcp-interaction-addwrap">';
        $h .= '<button type="button" class="pcp-interaction-add"';
        $h .= ' data-page-type="' . htmlspecialchars( $type ) . '"';
        $h .= ' data-page-slug="' . htmlspecialchars( $slug ) . '"';
        $h .= ' data-page-name="' . htmlspecialchars( str_replace( '_', ' ', $slug ) ) . '"';
        $h .= '>+ Add an interaction</button>';
        $h .= '</div>';
        return $h;
    }

    private static function renderRow( $r ) {
        $entry    = $r['entry'];
        $row      = $entry['row'];
        $otherT   = $entry['other_type'];
        $otherS   = $entry['other_slug'];
        $via      = $entry['via'];
        $pooled   = $r['pooled'];
        $userAgg  = $r['user'];
        $provAgg  = $r['provider'];
        $eid      = (int)$row->pi_element_id;

        // Counterparty link
        $otherName = str_replace( '_', ' ', $otherS );
        $isOtherCategory = ( $otherT === InteractionStore::TYPE_CATEGORY );
        if ( $isOtherCategory ) {
            $otherTitle = Title::makeTitle( NS_CATEGORY, $otherS );
        } else {
            $otherTitle = Title::newFromText( $otherName );
        }
        $otherLabel = $otherName;
        $otherUrl = $otherTitle ? $otherTitle->getLocalURL() : '#';

        $severe = $pooled['severe'] || $userAgg['severe'] || $provAgg['severe'];
        $severeCls = $severe ? ' pcp-interaction-severe' : '';

        $store = new InteractionStore();
        $notesPanel = self::renderNotesPanel( $eid, $store );
        $user = \RequestContext::getMain()->getUser();

        $h  = '<div class="pcp-row pcp-row-interaction pcp-interaction-row' . $severeCls . '"';
        $h .= ' data-element-id="' . $eid . '"';
        $h .= ' data-n="' . (int)$pooled['n'] . '"';
        $h .= ' data-vmean="' . ( $pooled['valence_mean'] === null ? '' : $pooled['valence_mean'] ) . '"';
        $h .= '>';

        // HEAD line
        $h .= '<div class="pcp-row-head">';
        $h .= '<span class="pcp-row-title">';
        $otherCls = 'pcp-interaction-other' . ( $isOtherCategory ? ' pcp-interaction-other-category' : '' );
        $h .= '<a class="' . $otherCls . '" href="' . htmlspecialchars( $otherUrl ) . '">' .
              htmlspecialchars( $otherLabel ) . '</a>';
        if ( $via !== null ) {
            $viaName = str_replace( '_', ' ', $via );
            $viaTitle = Title::makeTitle( NS_CATEGORY, $via );
            $viaUrl = $viaTitle ? $viaTitle->getLocalURL() : '#';
            $h .= ' <a class="pcp-interaction-via" href="' . htmlspecialchars( $viaUrl ) . '">' .
                  'via Category:' . htmlspecialchars( $viaName ) . '</a>';
        }
        if ( $severe ) {
            $h .= ' <span class="pcp-interaction-severe-tag">severe</span>';
        }
        $h .= '</span>';

        $h .= '<span class="pcp-row-aggs">';
        $h .= self::renderAggLine( '👤', 'user',     $userAgg );
        $h .= self::renderAggLine( '⚕️', 'provider', $provAgg );
        $h .= '</span>';

        $h .= '<span class="pcp-row-actions">';
        $h .= '<button type="button" class="pcp-row-action pcp-row-action-toggle" data-target="rate" aria-expanded="false">Rate</button>';
        if ( $notesPanel['count'] > 0 ) {
            $h .= '<button type="button" class="pcp-row-action pcp-row-action-toggle" data-target="notes" aria-expanded="false">Notes (' . (int)$notesPanel['count'] . ')</button>';
        }
        $h .= '<button type="button" class="pcp-ix-del-row" data-element-id="' . $eid . '" title="Delete this interaction (admin)" aria-label="Delete interaction">×</button>';
        $h .= '</span>';
        $h .= '</div>';

        // Panels below the head
        $h .= self::renderRateWidget( $eid, $user, $store );
        $h .= $notesPanel['html'];

        $h .= '</div>';
        return $h;
    }

    private static function renderAggLine( $icon, $persp, $agg ) {
        $h  = '<span class="pcp-interaction-agg pcp-interaction-agg-' . htmlspecialchars( $persp ) . '"';
        $h .= ' data-n="' . (int)$agg['n'] . '"';
        if ( $agg['valence_mean'] !== null ) {
            $h .= ' data-vmean="' . $agg['valence_mean'] . '"';
        }
        if ( $agg['experience_mean'] !== null ) {
            $h .= ' data-emean="' . $agg['experience_mean'] . '"';
        }
        $h .= '>';
        $h .= '<span class="pcp-interaction-agg-icon">' . $icon . '</span> ';
        if ( (int)$agg['n'] === 0 ) {
            $h .= '<span class="pcp-interaction-agg-empty">no reports yet</span>';
        } else {
            $expFmt = $agg['experience_mean'] !== null
                ? number_format( (float)$agg['experience_mean'], 1 ) : 'n/a';
            $vmean  = $agg['valence_mean'];
            $vFmt = $vmean !== null ? sprintf( '%+.1f', (float)$vmean ) : 'n/a';
            $h .= '<span class="pcp-interaction-agg-exp" title="experience: 1=a little, 5=extensive">' .
                  'exp ' . htmlspecialchars( $expFmt ) . '/5</span> ';
            $h .= '<span class="pcp-interaction-agg-val" title="outcome: -3 worst, +3 best">' .
                  'outcome ' . htmlspecialchars( $vFmt ) . '</span> ';
            $h .= '<span class="pcp-interaction-agg-n">(n=' . (int)$agg['n'] . ')</span>';
        }
        $h .= '</span>';
        return $h;
    }
}
