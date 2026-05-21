<?php
/**
 * Phase 1 PGx Step E: FDA Table of Pharmacogenomic Biomarkers ingest.
 *
 * Source: https://www.fda.gov/drugs/science-and-research-drugs/table-pharmacogenomic-biomarkers-drug-labeling
 *
 * Strategy: evidence-upgrade-only pass.
 *   For each FDA row, find existing pcp_interactions rows that involve
 *   (drug, gene). Upgrade their evidence to fda_box (boxed-warning rows)
 *   or fda_label (everything else) if that's stronger than current. Append
 *   the FDA labeling-section detail to pi_mechanism.
 *
 * Why not create new edges:
 *   The FDA table doesn't carry an actionable relationship (no substrate/
 *   inhibitor direction for CYPs, no phenotype assignment for activity-based
 *   genes). Synthesizing one would pollute the data layer. CPIC ingest
 *   already creates the phenotype-level rows we'd want to tag.
 *
 * Out of scope (logged + skipped):
 *   Oncology companion-diagnostic biomarkers (ERBB2/EGFR/ALK/BRAF/RAS/ROS1/
 *   KRAS/ESR/PGR/PD-L1/BCR-ABL1/etc.); chromosomal markers (5q/17p);
 *   diseases-as-biomarkers (Congenital Methemoglobinemia, MSI).
 *
 * Usage:
 *   sudo -u www-data php maintenance/run.php extensions/Pharmacopedia/maintenance/IngestFdaPgxBiomarkers.php \
 *     --username=MDElliottMD [--dry-run] [--verbose]
 */
$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\Pharmacopedia\IngestionLog;

class IngestFdaPgxBiomarkers extends Maintenance {

    private const URL = 'https://www.fda.gov/drugs/science-and-research-drugs/table-pharmacogenomic-biomarkers-drug-labeling';

    /** PGx-relevant biomarker tokens -> [endpointType, gene_slug]. */
    private const GENE_MAP = [
        'CYP2D6'   => [ 'enzyme', 'CYP2D6' ],
        'CYP2C19'  => [ 'enzyme', 'CYP2C19' ],
        'CYP2C9'   => [ 'enzyme', 'CYP2C9' ],
        'CYP3A4'   => [ 'enzyme', 'CYP3A4' ],
        'CYP3A5'   => [ 'enzyme', 'CYP3A5' ],
        'CYP2B6'   => [ 'enzyme', 'CYP2B6' ],
        'CYP1A2'   => [ 'enzyme', 'CYP1A2' ],
        'UGT1A1'   => [ 'enzyme', 'UGT1A1' ],
        'UGT2B7'   => [ 'enzyme', 'UGT2B7' ],
        'TPMT'     => [ 'enzyme', 'TPMT' ],
        'DPYD'     => [ 'enzyme', 'DPYD' ],
        'NUDT15'   => [ 'enzyme', 'NUDT15' ],
        'G6PD'     => [ 'enzyme', 'G6PD' ],
        'MT-RNR1'  => [ 'enzyme', 'MT-RNR1' ],
        'SLCO1B1'  => [ 'transporter', 'SLCO1B1' ],
        'HLA-A'    => [ 'variant', 'hla-a' ],
        'HLA-B'    => [ 'variant', 'hla-b' ],
        'HLA-C'    => [ 'variant', 'hla-c' ],
        'HLA-DRB1' => [ 'variant', 'hla-drb1' ],
        'F5'       => [ 'variant', 'f5_factor_v_leiden' ],
        'VKORC1'   => [ 'enzyme',  'VKORC1' ],
        'APOE'     => [ 'variant', 'apoe' ],
        'IFNL3'    => [ 'variant', 'ifnl3' ],
        'POLG'     => [ 'enzyme',  'POLG' ],
        'RYR1'     => [ 'variant', 'ryr1' ],
        'CACNA1S'  => [ 'variant', 'cacna1s' ],
        'CFTR'     => [ 'variant', 'cftr' ],
    ];

    /** Relationships where an FDA evidence upgrade is semantically valid.
     *  Mild relationships (normal_dose, monitor, substrate_*, prodrug_*,
     *  inhibitor_*, inducer_*) describe metabolism direction or no-action
     *  guidance; promoting them to fda_box/fda_label is over-reach.
     */
    private const SEVERE_RELS = [
        'avoid', 'contraindication', 'contraindicated',
        'dose_reduce_25', 'dose_reduce_50',
        'prefer_alternative',
        'risk_SCAR', 'risk_hypersensitivity', 'risk_hepatotoxicity',
        'risk_hematologic', 'risk_ototoxicity', 'risk_qt',
        'toxicity_general', 'efficacy_loss', 'efficacy_gain',
    ];

    /** Evidence-rank table (matches CPIC ingest's). */
    private const EVIDENCE_RANK = [
        'fda_box' => 100, 'cpic_A' => 95, 'cpic_strong' => 90,
        'fda_label' => 85, 'cpic_B' => 75, 'cpic_moderate' => 70,
        'cpic_C' => 50, 'cpic_optional' => 45, 'cpic_D' => 30,
        'dpwg' => 60, 'primary' => 50, 'theoretical' => 20, 'derived' => 25,
    ];

    public function __construct() {
        parent::__construct();
        $this->addOption( 'username', 'User to attribute changes to', true, true );
        $this->addOption( 'dry-run',  'Preview without writing',     false, false );
        $this->addOption( 'verbose',  'Print every row touched',     false, false );
        $this->requireExtension( 'Pharmacopedia' );
    }

    public function execute() {
        $services = MediaWikiServices::getInstance();
        $user = $services->getUserFactory()->newFromName( $this->getOption( 'username' ) );
        if ( !$user || !$user->isRegistered() ) {
            $this->fatalError( "User not found" );
        }
        $dryRun  = $this->hasOption( 'dry-run' );
        $verbose = $this->hasOption( 'verbose' );

        $this->output( "Fetching " . self::URL . " ...\n" );
        $html = $services->getHttpRequestFactory()->get( self::URL,
            [ 'timeout' => 30,
              'userAgent' => 'Mozilla/5.0 (X11; Linux x86_64) Pharmacopedia/0.9' ],
            __METHOD__ );
        if ( !$html ) $this->fatalError( "FDA fetch failed" );
        $this->output( "Got " . strlen( $html ) . " bytes\n" );

        $rows = $this->parseFdaTable( $html );
        $this->output( "Parsed " . count( $rows ) . " biomarker entries\n\n" );

        $dbw = $services->getConnectionProvider()->getPrimaryDatabase();
        $dbr = $services->getConnectionProvider()->getReplicaDatabase();

        $stats = [
            'fda_rows'        => count( $rows ),
            'mapped'          => 0,
            'unmapped_gene'   => 0,
            'multi_gene'      => 0,
            'boxed'           => 0,
            'considered_edges' => 0,
            'upgraded_evidence' => 0,
            'updated_mechanism' => 0,
            'unchanged'       => 0,
            'no_matching_edge' => 0,
            'errors'          => 0,
        ];
        $unmappedTokens = [];

        foreach ( $rows as $r ) {
            [ $drug, $area, $biomRaw, $sections ] = $r;
            $drugSlug = self::medicineSlug( self::stripFootnote( $drug ) );
            $boxed = ( stripos( $sections, 'Boxed Warning' ) !== false );
            if ( $boxed ) $stats['boxed']++;
            $newEvidence = $boxed ? 'fda_box' : 'fda_label';

            $geneTokens = self::extractGenes( $biomRaw );
            if ( !$geneTokens ) {
                $stats['unmapped_gene']++;
                $unmappedTokens[ $biomRaw ] = ( $unmappedTokens[ $biomRaw ] ?? 0 ) + 1;
                continue;
            }
            if ( count( $geneTokens ) > 1 ) $stats['multi_gene']++;
            $stats['mapped']++;

            foreach ( $geneTokens as $token ) {
                [ $endpType, $geneSlug ] = self::GENE_MAP[$token];

                // Find all existing rows where this drug + this gene co-occur.
                // The pair could be in either order due to canonicalization.
                $matches = $dbr->select( 'pcp_interactions', '*', $dbr->makeList( [
                    $dbr->makeList( [
                        'pi_left_type'  => 'medicine', 'pi_left_slug'  => $drugSlug,
                        'pi_right_type' => $endpType, 'pi_right_slug' => $geneSlug,
                    ], LIST_AND ),
                    $dbr->makeList( [
                        'pi_left_type'  => $endpType, 'pi_left_slug'  => $geneSlug,
                        'pi_right_type' => 'medicine', 'pi_right_slug' => $drugSlug,
                    ], LIST_AND ),
                    // Also catch phenotype edges via gene prefix
                    $dbr->makeList( [
                        'pi_left_type'  => 'medicine', 'pi_left_slug'  => $drugSlug,
                        'pi_right_type' => 'phenotype',
                        'pi_right_slug' . $dbr->buildLike( strtolower( $geneSlug ) . '_', $dbr->anyString() ),
                    ], LIST_AND ),
                ], LIST_OR ), __METHOD__ );

                $matchCount = 0;
                foreach ( $matches as $m ) {
                    $matchCount++;
                    $stats['considered_edges']++;
                    $rel = (string)$m->pi_relationship;
                    // Skip mild-relationship rows entirely — FDA Boxed Warning
                    // doesn't justify upgrading "normal_dose" / "substrate_*"
                    // / "prodrug_activated_by" / "inhibitor_*" / "monitor" / etc.
                    if ( !in_array( $rel, self::SEVERE_RELS, true ) ) {
                        $stats['unchanged']++;
                        if ( !isset( $stats['skip_mild_rel'] ) ) $stats['skip_mild_rel'] = 0;
                        $stats['skip_mild_rel']++;
                        if ( $verbose ) $this->output( sprintf(
                            "  skip-mild-rel  pi_id=%d (%s)\n", (int)$m->pi_id, $rel ) );
                        continue;
                    }
                    $oldRank = self::EVIDENCE_RANK[ (string)$m->pi_evidence ] ?? 0;
                    $newRank = self::EVIDENCE_RANK[ $newEvidence ];
                    $updates = [];
                    if ( $newRank > $oldRank ) {
                        $updates['pi_evidence'] = $newEvidence;
                        // Boxed warning bumps intensity for true contraindication-grade edges.
                        if ( $boxed && (int)( $m->pi_intensity ?? 0 ) < 85 ) {
                            $updates['pi_intensity'] = 90;
                        }
                    }
                    // Always tag mechanism with FDA label-section detail (idempotent).
                    $oldMech = (string)( $m->pi_mechanism ?? '' );
                    $fdaTag = "FDA labeling (" . ( $boxed ? 'boxed: ' : '' )
                        . self::shortenSections( $sections ) . ")";
                    if ( strpos( $oldMech, 'FDA labeling' ) === false ) {
                        $newMech = mb_substr( trim( $oldMech ) . ' ' . $fdaTag, 0, 2048 );
                        if ( $newMech !== $oldMech ) $updates['pi_mechanism'] = $newMech;
                    }

                    if ( !$updates ) {
                        $stats['unchanged']++;
                        if ( $verbose ) $this->output( sprintf(
                            "  unchanged  %s <-> %s:%s (existing ev=%s)\n",
                            $drugSlug, $endpType, $geneSlug, $m->pi_evidence ) );
                        continue;
                    }
                    if ( $dryRun ) {
                        if ( $verbose ) {
                            $what = isset( $updates['pi_evidence'] )
                                ? "evidence " . $m->pi_evidence . " -> " . $updates['pi_evidence']
                                : "mechanism tagged";
                            $this->output( sprintf(
                                "  WOULD: %s [pi_id=%d] %s\n",
                                $drugSlug, (int)$m->pi_id, $what ) );
                        }
                        if ( isset( $updates['pi_evidence'] ) )  $stats['upgraded_evidence']++;
                        if ( isset( $updates['pi_mechanism'] ) ) $stats['updated_mechanism']++;
                        continue;
                    }
                    $dbw->update( 'pcp_interactions', $updates,
                        [ 'pi_id' => (int)$m->pi_id ], __METHOD__ );
                    if ( isset( $updates['pi_evidence'] ) ) {
                        $stats['upgraded_evidence']++;
                        if ( $verbose ) $this->output( sprintf(
                            "  UPGRADED  pi_id=%d  %s -> %s  (drug=%s gene=%s)\n",
                            (int)$m->pi_id, $m->pi_evidence, $updates['pi_evidence'],
                            $drugSlug, $geneSlug ) );
                    }
                    if ( isset( $updates['pi_mechanism'] ) ) {
                        $stats['updated_mechanism']++;
                    }
                }
                if ( $matchCount === 0 ) {
                    $stats['no_matching_edge']++;
                    if ( $verbose ) $this->output( sprintf(
                        "  no-match: %s <-> %s:%s (FDA-only, no CPIC counterpart)\n",
                        $drugSlug, $endpType, $geneSlug ) );
                }
            }
        }

        $this->output( "\nSummary:\n" );
        foreach ( $stats as $k => $v ) $this->output( sprintf( "  %-22s %d\n", $k, $v ) );
        if ( $unmappedTokens ) {
            arsort( $unmappedTokens );
            $this->output( "\nTop 15 unmapped biomarker tokens (logged as out-of-scope):\n" );
            $i = 0;
            foreach ( $unmappedTokens as $k => $v ) {
                $this->output( sprintf( "  %4d  %s\n", $v, $k ) );
                if ( ++$i >= 15 ) break;
            }
        }

        if ( !$dryRun ) {
            $note = sprintf(
                "FDA biomarker pass: %d rows, %d mapped (%d boxed); "
                . "%d edges considered, %d evidence upgrades, %d mechanism updates, "
                . "%d FDA-only (no CPIC counterpart), %d unmapped-gene.",
                $stats['fda_rows'], $stats['mapped'], $stats['boxed'],
                $stats['considered_edges'], $stats['upgraded_evidence'],
                $stats['updated_mechanism'], $stats['no_matching_edge'],
                $stats['unmapped_gene'] );
            $logId = IngestionLog::record(
                'fda_table',
                'fda-biomarkers-' . date( 'Ymd' ),
                0, $stats['upgraded_evidence'] + $stats['updated_mechanism'],
                $note );
            $this->output( "Logged as pcp_ingestion_log.il_id=$logId\n" );
        }
    }

    /** Parse the FDA HTML table using DOMDocument. */
    private function parseFdaTable( string $html ): array {
        $prev = libxml_use_internal_errors( true );
        $dom = new \DOMDocument();
        $dom->loadHTML( $html );
        libxml_use_internal_errors( $prev );

        $tables = $dom->getElementsByTagName( 'table' );
        if ( $tables->length === 0 ) return [];
        $table = $tables->item( 0 );
        $rows = [];
        foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
            $cells = [];
            foreach ( $tr->childNodes as $child ) {
                if ( $child->nodeType !== XML_ELEMENT_NODE ) continue;
                if ( $child->nodeName !== 'td' && $child->nodeName !== 'th' ) continue;
                $cells[] = trim( preg_replace( '/\s+/u', ' ',
                    html_entity_decode( $child->textContent, ENT_QUOTES, 'UTF-8' ) ) );
            }
            if ( count( $cells ) >= 4 && strcasecmp( $cells[0], 'Drug' ) !== 0 ) {
                $rows[] = $cells;
            }
        }
        return $rows;
    }

    /** Extract recognizable gene tokens from a biomarker column. */
    private static function extractGenes( string $biom ): array {
        $found = [];
        // Quick path: exact match against GENE_MAP keys.
        foreach ( self::GENE_MAP as $token => $_ ) {
            if ( preg_match( '/\b' . preg_quote( $token, '/' ) . '\b/i', $biom ) ) {
                $found[] = $token;
            }
        }
        return array_values( array_unique( $found ) );
    }

    private static function stripFootnote( string $drug ): string {
        // "Abemaciclib (1)" -> "Abemaciclib"
        return trim( preg_replace( '/\s*\(\d+\)\s*$/', '', $drug ) );
    }

    private static function medicineSlug( string $name ): string {
        $s = trim( $name );
        $s = preg_replace( '/\s+/u', '_', $s );
        if ( $s === '' ) return '';
        return mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 );
    }

    /** Compact the comma-separated section list into something <=80 chars. */
    private static function shortenSections( string $sections ): string {
        $tokens = array_map( 'trim', explode( ',', $sections ) );
        return mb_substr( implode( ', ', $tokens ), 0, 80 );
    }
}

$maintClass = IngestFdaPgxBiomarkers::class;
require_once RUN_MAINTENANCE_IF_MAIN;
