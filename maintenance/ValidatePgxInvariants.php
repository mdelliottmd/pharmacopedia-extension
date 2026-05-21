<?php
/**
 * Invariant checker for pcp_interactions data layer.
 *
 * Scans every row + the votable_elements join, reports semantic
 * inconsistencies as a structured violations list. Designed to run after
 * any ingest (CPIC, FDA, sandbox, inference) and surface regressions
 * before they ship.
 *
 * Catches the FDA-over-promotion bug pattern that bit us in Step E, plus
 * generic vocab / type / range checks.
 *
 * Exit code: 0 if clean, 1 if violations found.
 *
 * Usage:
 *   sudo -u www-data php maintenance/run.php extensions/Pharmacopedia/maintenance/ValidatePgxInvariants.php \
 *     [--verbose] [--summary]
 */
$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;

class ValidatePgxInvariants extends Maintenance {

    private const EVIDENCE_VOCAB = [
        'cpic_A', 'cpic_B', 'cpic_C', 'cpic_D',
        'cpic_strong', 'cpic_moderate', 'cpic_optional',
        'fda_box', 'fda_label', 'dpwg',
        'primary', 'theoretical', 'derived',
        // Phase 4 herbal-medicine evidence tiers.
        'ema_hmpc', 'who_monograph', 'usp_hmc', 'msk_about',
    ];

    /** Relationships with fixed names (pk_via_* is handled separately). */
    private const RELATIONSHIP_VOCAB = [
        // medicine x enzyme
        'substrate_major', 'substrate_minor',
        'inhibitor_strong', 'inhibitor_moderate', 'inhibitor_weak',
        'inducer_strong', 'inducer_moderate', 'inducer_weak',
        'prodrug_activated_by',
        // medicine x transporter
        'substrate', 'inhibitor', 'inducer',
        // medicine x phenotype
        'avoid', 'prefer_alternative',
        'dose_reduce_25', 'dose_reduce_50', 'dose_increase',
        'monitor', 'normal_dose',
        'toxicity_risk',
        // medicine x variant
        'risk_SCAR', 'risk_hypersensitivity', 'risk_hepatotoxicity',
        'risk_hematologic', 'risk_ototoxicity', 'risk_qt',
        'contraindication', 'toxicity_general',
        'efficacy_loss', 'efficacy_gain',
        // medicine x medicine (PD; pk_via_* handled separately)
        'pd_additive', 'pd_opposing', 'qt_combined',
        'serotonin_syndrome_risk', 'bleeding_risk', 'contraindicated',
        // legacy
        'unspecified',
    ];

    /** Relationships that are NOT clinical-action — FDA evidence upgrades
     *  are semantically invalid for these. */
    private const MILD_RELS = [
        'normal_dose', 'monitor', 'dose_increase',
        'substrate_major', 'substrate_minor', 'prodrug_activated_by',
        'inhibitor_strong', 'inhibitor_moderate', 'inhibitor_weak',
        'inducer_strong', 'inducer_moderate', 'inducer_weak',
        'substrate', 'inhibitor', 'inducer',
    ];

    /** Relationships that imply a particular endpoint type on the
     *  non-medicine side. Used to flag wrong-namespace rows. */
    private const REL_EXPECTS = [
        // [ relationship => expected endpoint type for the non-medicine side ]
        'substrate_major'      => 'enzyme',
        'substrate_minor'      => 'enzyme',
        'inhibitor_strong'     => 'enzyme',
        'inhibitor_moderate'   => 'enzyme',
        'inhibitor_weak'       => 'enzyme',
        'inducer_strong'       => 'enzyme',
        'inducer_moderate'     => 'enzyme',
        'inducer_weak'         => 'enzyme',
        'prodrug_activated_by' => 'enzyme',
        'substrate'            => 'transporter',
        'inhibitor'            => 'transporter',
        'inducer'              => 'transporter',
        'avoid'                => 'phenotype',
        'prefer_alternative'   => 'phenotype',
        'dose_reduce_25'       => 'phenotype',
        'dose_reduce_50'       => 'phenotype',
        'dose_increase'        => 'phenotype',
        'monitor'              => 'phenotype',
        'normal_dose'          => 'phenotype',
        'toxicity_risk'        => 'phenotype',
        'risk_SCAR'            => 'variant',
        'risk_hypersensitivity' => 'variant',
        'risk_hepatotoxicity'  => 'variant',
        'risk_hematologic'     => 'variant',
        'risk_ototoxicity'     => 'variant',
        'risk_qt'              => 'variant',
        'contraindication'     => 'variant',
        'toxicity_general'     => 'variant',
    ];

    private const TYPE_VOCAB = [
        'medicine', 'category', 'enzyme', 'transporter', 'phenotype', 'variant',
    ];

    private const KINETICS_VOCAB = [
        'reversible_competitive', 'mechanism_based',
        'irreversible_covalent', 'allosteric',
        'time_dependent', 'unknown',
    ];

    public function __construct() {
        parent::__construct();
        $this->addOption( 'verbose', 'List every violating pi_id', false, false );
        $this->addOption( 'summary', 'Just print the counts table', false, false );
        $this->requireExtension( 'Pharmacopedia' );
    }

    public function execute() {
        $verbose = $this->hasOption( 'verbose' );
        $summaryOnly = $this->hasOption( 'summary' );
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $violations = [];   // name => list of [pi_id, detail]

        $add = function ( string $name, $piId, string $detail ) use ( &$violations ) {
            if ( !isset( $violations[$name] ) ) $violations[$name] = [];
            $violations[$name][] = [ $piId, $detail ];
        };

        // Load every row once (646 rows fits comfortably).
        $rows = $dbr->select( 'pcp_interactions', '*', [], __METHOD__ );
        $rowList = [];
        foreach ( $rows as $r ) $rowList[] = $r;
        $this->output( "Scanning " . count( $rowList ) . " rows of pcp_interactions ...\n" );

        foreach ( $rowList as $r ) {
            $id  = (int)$r->pi_id;
            $lt  = (string)$r->pi_left_type;
            $rt  = (string)$r->pi_right_type;
            $rel = (string)$r->pi_relationship;
            $ev  = $r->pi_evidence === null ? null : (string)$r->pi_evidence;
            $iN  = $r->pi_intensity === null ? null : (int)$r->pi_intensity;
            $kin = $r->pi_kinetics === null ? null : (string)$r->pi_kinetics;

            // 1) Endpoint type vocab
            if ( !in_array( $lt, self::TYPE_VOCAB, true ) )
                $add( 'unknown_endpoint_type', $id, "left='$lt'" );
            if ( !in_array( $rt, self::TYPE_VOCAB, true ) )
                $add( 'unknown_endpoint_type', $id, "right='$rt'" );

            // 2) Evidence vocab
            if ( $ev !== null && !in_array( $ev, self::EVIDENCE_VOCAB, true ) )
                $add( 'unknown_evidence', $id, "evidence='$ev'" );

            // 3) Relationship vocab (allow the dynamic pk_*_via_<E> family).
            //    Current: pk_raises_via_, pk_lowers_via_ (outcome-named).
            //    Legacy accepted for back-compat: pk_via_, pk_inhibit_via_,
            //    pk_induce_via_.
            $isPkVia = ( strncmp( $rel, 'pk_raises_via_', 14 ) === 0 )
                || ( strncmp( $rel, 'pk_lowers_via_', 14 ) === 0 )
                || ( strncmp( $rel, 'pk_via_', 7 ) === 0 )
                || ( strncmp( $rel, 'pk_inhibit_via_', 15 ) === 0 )
                || ( strncmp( $rel, 'pk_induce_via_', 14 ) === 0 );
            if ( !$isPkVia && !in_array( $rel, self::RELATIONSHIP_VOCAB, true ) )
                $add( 'unknown_relationship', $id, "rel='$rel'" );

            // 4) Intensity range
            if ( $iN !== null && ( $iN < 0 || $iN > 100 ) )
                $add( 'intensity_out_of_range', $id, "intensity=$iN" );

            // 5) FDA evidence on mild relationship (the Step-E bug pattern)
            if ( in_array( $ev, [ 'fda_box', 'fda_label' ], true )
                 && in_array( $rel, self::MILD_RELS, true ) )
                $add( 'mild_rel_with_fda_evidence', $id,
                    "rel='$rel' ev='$ev' — promotion is semantic mismatch" );

            // 6) Relationship expects specific endpoint type
            if ( isset( self::REL_EXPECTS[$rel] ) ) {
                $expected = self::REL_EXPECTS[$rel];
                // The medicine side could be either left or right (canonical sort).
                // Identify the non-medicine side and verify its type.
                $nonMedType = null;
                if ( $lt === 'medicine' )       $nonMedType = $rt;
                elseif ( $rt === 'medicine' )   $nonMedType = $lt;
                if ( $nonMedType !== null && $nonMedType !== $expected ) {
                    $add( 'wrong_endpoint_type_for_relationship', $id,
                        "rel='$rel' expects non-medicine side to be '$expected' but it's '$nonMedType'" );
                }
                // Categories ↔ enzyme pairs without a medicine side are
                // grandfathered (legacy pre-PGx); skip the check then.
            }

            // 7) Derived edges must be medicine x medicine
            if ( $ev === 'derived' && !( $lt === 'medicine' && $rt === 'medicine' ) )
                $add( 'derived_not_med_med', $id, "left='$lt' right='$rt'" );

            // 8) pk_via_* relationships must be medicine x medicine
            if ( $isPkVia && !( $lt === 'medicine' && $rt === 'medicine' ) )
                $add( 'pk_via_not_med_med', $id, "rel='$rel' left='$lt' right='$rt'" );

            // 9) Kinetics vocab
            if ( $kin !== null && !in_array( $kin, self::KINETICS_VOCAB, true ) )
                $add( 'unknown_kinetics', $id, "kinetics='$kin'" );

            // 10) Evidence attached to a relationship='unspecified' row is
            //     ghost data — the renderer has no chip to attach evidence to,
            //     so the evidence is invisible. Either the row needs a real
            //     vocab relationship or the evidence should be NULL.
            if ( $rel === 'unspecified' && $ev !== null )
                $add( 'evidence_without_actionable_relationship', $id,
                    "rel='unspecified' but ev='$ev' — evidence is invisible to renderer" );

            // 11) Empty/zero element_id (every interaction needs a votable_element)
            if ( (int)( $r->pi_element_id ?? 0 ) <= 0 )
                $add( 'missing_element_id', $id, "pi_element_id={$r->pi_element_id}" );
        }

        // 11) Orphan votable_elements (interaction-typed but no backing row)
        $orphans = $dbr->select(
            [ 've' => 'pcp_votable_elements', 'pi' => 'pcp_interactions' ],
            [ 've.ve_id', 've.ve_slug' ],
            [ 've.ve_type' => 'interaction', 'pi.pi_id IS NULL' ],
            __METHOD__,
            [],
            [ 'pi' => [ 'LEFT JOIN', 'pi.pi_element_id = ve.ve_id' ] ]
        );
        foreach ( $orphans as $o ) {
            $add( 'orphan_votable_element', '(ve_id=' . (int)$o->ve_id . ')', $o->ve_slug );
        }

        // 12) pcp_interactions pointing at non-existent votable_element
        $danglingPi = $dbr->select(
            [ 'pi' => 'pcp_interactions', 've' => 'pcp_votable_elements' ],
            [ 'pi.pi_id', 'pi.pi_element_id' ],
            [ 've.ve_id IS NULL' ],
            __METHOD__,
            [],
            [ 've' => [ 'LEFT JOIN', 've.ve_id = pi.pi_element_id' ] ]
        );
        foreach ( $danglingPi as $d ) {
            $add( 'dangling_element_ref', (int)$d->pi_id, "pi_element_id=" . (int)$d->pi_element_id );
        }

        // Report
        $this->output( "\n=== INVARIANT VIOLATIONS ===\n" );
        if ( !$violations ) {
            $this->output( "  none. data layer clean.\n" );
            return;
        }
        ksort( $violations );
        $total = 0;
        foreach ( $violations as $name => $list ) {
            $this->output( sprintf( "  %-40s %d\n", $name, count( $list ) ) );
            $total += count( $list );
        }
        $this->output( sprintf( "  %-40s %d\n\n", '(TOTAL)', $total ) );

        if ( !$summaryOnly ) {
            foreach ( $violations as $name => $list ) {
                $this->output( "--- $name (" . count( $list ) . ") ---\n" );
                $shown = $verbose ? $list : array_slice( $list, 0, 10 );
                foreach ( $shown as [ $id, $detail ] ) {
                    $this->output( "  pi_id=$id  $detail\n" );
                }
                if ( !$verbose && count( $list ) > 10 ) {
                    $this->output( "  ... " . ( count( $list ) - 10 ) . " more (use --verbose)\n" );
                }
                $this->output( "\n" );
            }
        }
        $this->fatalError( "Found $total invariant violations.", 1 );
    }
}
$maintClass = ValidatePgxInvariants::class;
require_once RUN_MAINTENANCE_IF_MAIN;
