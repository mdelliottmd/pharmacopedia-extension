<?php
/**
 * Phase 1 follow-up: enrich existing CPIC-derived rows with the more
 * granular CPIC Level (A/B/C/D) from api.cpicpgx.org/v1/pair_view.
 *
 * Conservative scope:
 *   - ONLY upgrades within the CPIC family. Doesn't touch fda_box,
 *     fda_label, primary, derived, theoretical, or dpwg rows.
 *   - cpic_strong   -> cpic_A (only if pair-level is A; rank 90 -> 95)
 *   - cpic_moderate -> cpic_B (if pair-level is B; rank 70 -> 75)
 *   - cpic_optional -> cpic_C (if pair-level is C; rank 45 -> 50)
 *   - cpic_D stays unless pair-level is stronger, then becomes the matching tier
 *   - Never downgrades.
 *
 * Mechanism enrichment: appends "CPIC pair-level <X>: <guideline_name>"
 * with PMIDs if not already present. Adds traceability back to the
 * CPIC guideline.
 *
 * Idempotent. Audit-logged under source='cpic_api' version='cpic-pair-...'.
 *
 * Usage:
 *   sudo -u www-data php maintenance/run.php extensions/Pharmacopedia/maintenance/IngestCpicPairLevels.php \
 *     [--dry-run] [--verbose]
 */
$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\Pharmacopedia\IngestionLog;

class IngestCpicPairLevels extends Maintenance {

    /** Map CPIC level letter -> [target evidence code, rank]. */
    private const LEVEL_TO_EVIDENCE = [
        'A'  => [ 'cpic_A', 95 ],
        'A/B' => [ 'cpic_A', 95 ],   // some guidelines use A/B
        'B'  => [ 'cpic_B', 75 ],
        'B1' => [ 'cpic_B', 75 ],
        'B2' => [ 'cpic_B', 75 ],
        'C'  => [ 'cpic_C', 50 ],
        'C1' => [ 'cpic_C', 50 ],
        'C2' => [ 'cpic_C', 50 ],
        'D'  => [ 'cpic_D', 30 ],
    ];

    /** Existing CPIC tiers we're willing to upgrade. */
    private const ELIGIBLE_PRIOR = [
        'cpic_strong', 'cpic_moderate', 'cpic_optional', 'cpic_D',
        'cpic_A', 'cpic_B', 'cpic_C',  // re-upgrades allowed if rank improves
    ];

    public function __construct() {
        parent::__construct();
        $this->addOption( 'dry-run', 'Preview without writing', false, false );
        $this->addOption( 'verbose', 'Print every row touched', false, false );
        $this->requireExtension( 'Pharmacopedia' );
    }

    public function execute() {
        $services = MediaWikiServices::getInstance();
        $http = $services->getHttpRequestFactory();
        $dryRun = $this->hasOption( 'dry-run' );
        $verbose = $this->hasOption( 'verbose' );
        $dbr = $services->getConnectionProvider()->getReplicaDatabase();
        $dbw = $services->getConnectionProvider()->getPrimaryDatabase();

        // 1) Fetch /pair_view.
        $this->output( "Fetching /v1/pair_view ...\n" );
        $body = $http->get( 'https://api.cpicpgx.org/v1/pair_view?limit=1000',
            [ 'timeout' => 30 ], __METHOD__ );
        $pairs = json_decode( (string)$body, true );
        if ( !is_array( $pairs ) ) $this->fatalError( "Bad JSON from /pair_view" );
        $this->output( "Got " . count( $pairs ) . " (drug, gene) pairs\n" );

        // Build map: (medicine_slug, gene_symbol_upper) -> [level, guidelineName, pmids]
        $pairMap = [];
        foreach ( $pairs as $p ) {
            $drug = trim( (string)( $p['drugname'] ?? '' ) );
            $gene = trim( (string)( $p['genesymbol'] ?? '' ) );
            $lvl  = trim( (string)( $p['cpiclevel'] ?? '' ) );
            if ( $drug === '' || $gene === '' || $lvl === '' ) continue;
            $medSlug = self::medicineSlug( $drug );
            $key = $medSlug . '|' . strtoupper( $gene );
            $pairMap[$key] = [
                'level' => $lvl,
                'guideline' => (string)( $p['guidelinename'] ?? '' ),
                'pmids' => is_array( $p['pmids'] ?? null ) ? $p['pmids'] : [],
            ];
        }
        $this->output( "Mapped " . count( $pairMap ) . " unique (drug, gene) keys\n\n" );

        // 2) Walk pcp_interactions; for each row where one side is medicine
        //    and other side is enzyme/transporter/phenotype, figure out the
        //    gene + drug and apply enrichment.
        $rows = $dbr->select( 'pcp_interactions', '*', [
            'pi_evidence' => self::ELIGIBLE_PRIOR,
        ], __METHOD__ );
        $rowsArr = [];
        foreach ( $rows as $r ) $rowsArr[] = $r;
        $this->output( "Scanning " . count( $rowsArr ) . " CPIC-tier rows\n\n" );

        $stats = [
            'considered' => 0,
            'no_pair_match' => 0,
            'no_upgrade' => 0,
            'evidence_upgraded' => 0,
            'mechanism_enriched' => 0,
        ];

        foreach ( $rowsArr as $r ) {
            $stats['considered']++;
            // Identify medicine side + gene side.
            $medSlug = null; $geneSymbol = null;
            if ( $r->pi_left_type === 'medicine' )       { $medSlug = (string)$r->pi_left_slug; }
            elseif ( $r->pi_right_type === 'medicine' )  { $medSlug = (string)$r->pi_right_slug; }
            if ( !$medSlug ) { $stats['no_pair_match']++; continue; }
            $otherType = ( $r->pi_left_type === 'medicine' ) ? $r->pi_right_type : $r->pi_left_type;
            $otherSlug = ( $r->pi_left_type === 'medicine' ) ? $r->pi_right_slug : $r->pi_left_slug;

            if ( $otherType === 'enzyme' || $otherType === 'transporter' ) {
                $geneSymbol = strtoupper( (string)$otherSlug );
            } elseif ( $otherType === 'phenotype' ) {
                // Phenotype slug like cyp2d6_pm; the gene is the prefix before the last underscore segment.
                $parts = explode( '_', (string)$otherSlug );
                // Most phenotype slugs are "gene_suffix" (2 parts) but a few
                // (cftr_ivacaftor_nr) are 3+. Heuristic: take everything
                // before the LAST underscore as the gene token. For 3-part
                // slugs like "cftr_ivacaftor_nr" this gives "cftr_ivacaftor"
                // which won't match CPIC's gene field — so fall back to
                // first segment if the multi-part lookup misses.
                if ( count( $parts ) >= 2 ) {
                    $geneSymbol = strtoupper( implode( '_', array_slice( $parts, 0, count( $parts ) - 1 ) ) );
                }
            } elseif ( $otherType === 'variant' ) {
                // Variant slugs like hla-b_5701_pos: gene is everything before
                // the first underscore.
                $parts = explode( '_', (string)$otherSlug );
                $geneSymbol = strtoupper( (string)$parts[0] );
            }
            if ( !$geneSymbol ) { $stats['no_pair_match']++; continue; }

            $key = $medSlug . '|' . $geneSymbol;
            $pair = $pairMap[$key] ?? null;
            // Try first-segment fallback for multi-part phenotype slugs.
            if ( !$pair && $otherType === 'phenotype' ) {
                $first = strtoupper( explode( '_', (string)$otherSlug )[0] );
                $key2 = $medSlug . '|' . $first;
                $pair = $pairMap[$key2] ?? null;
            }
            if ( !$pair ) { $stats['no_pair_match']++; continue; }

            [ $newEv, $newRank ] = self::LEVEL_TO_EVIDENCE[ $pair['level'] ] ?? [ null, 0 ];
            if ( !$newEv ) { $stats['no_upgrade']++; continue; }
            $oldEv = (string)$r->pi_evidence;
            $oldRank = self::evidenceRank( $oldEv );

            $updates = [];
            if ( $newRank > $oldRank ) {
                $updates['pi_evidence'] = $newEv;
            }

            // Mechanism enrichment: tag with pair-level + guideline if not already.
            $oldMech = (string)( $r->pi_mechanism ?? '' );
            $tag = "CPIC pair-level " . $pair['level'];
            if ( $pair['guideline'] !== '' ) $tag .= " (" . $pair['guideline'] . ")";
            if ( !empty( $pair['pmids'] ) ) {
                $tag .= " [PMID " . implode( ', ', array_slice( $pair['pmids'], 0, 3 ) ) . "]";
            }
            if ( strpos( $oldMech, 'CPIC pair-level' ) === false ) {
                $newMech = mb_substr( trim( $oldMech ) . ' ' . $tag, 0, 2048 );
                if ( $newMech !== $oldMech ) $updates['pi_mechanism'] = $newMech;
            }

            if ( !$updates ) { $stats['no_upgrade']++; continue; }

            if ( $verbose || $dryRun ) {
                $what = isset( $updates['pi_evidence'] )
                    ? "$oldEv -> " . $updates['pi_evidence']
                    : "(mech only)";
                $this->output( sprintf( "  pi_id=%-5d  %s <-> %s:%s  %s  level=%s\n",
                    (int)$r->pi_id, $medSlug, $otherType, $otherSlug, $what, $pair['level'] ) );
            }
            if ( !$dryRun ) {
                $dbw->update( 'pcp_interactions', $updates,
                    [ 'pi_id' => (int)$r->pi_id ], __METHOD__ );
            }
            if ( isset( $updates['pi_evidence'] ) )  $stats['evidence_upgraded']++;
            if ( isset( $updates['pi_mechanism'] ) ) $stats['mechanism_enriched']++;
        }

        $this->output( "\nSummary:\n" );
        foreach ( $stats as $k => $v ) $this->output( sprintf( "  %-22s %d\n", $k, $v ) );

        if ( !$dryRun ) {
            $note = sprintf( "CPIC pair-level enrichment: %d considered, "
                . "%d evidence upgrades, %d mechanism enrichments, %d no-match.",
                $stats['considered'], $stats['evidence_upgraded'],
                $stats['mechanism_enriched'], $stats['no_pair_match'] );
            $logId = IngestionLog::record(
                'cpic_api',
                'cpic-pair-' . date( 'Ymd' ),
                0, $stats['evidence_upgraded'] + $stats['mechanism_enriched'],
                $note );
            $this->output( "Logged as pcp_ingestion_log.il_id=$logId\n" );
        }
    }

    private static function evidenceRank( string $ev ): int {
        static $rank = null;
        if ( $rank === null ) $rank = [
            'fda_box' => 100, 'cpic_A' => 95, 'cpic_strong' => 90,
            'fda_label' => 85, 'cpic_B' => 75, 'cpic_moderate' => 70,
            'cpic_C' => 50, 'cpic_optional' => 45, 'cpic_D' => 30,
            'dpwg' => 60, 'primary' => 50, 'theoretical' => 20, 'derived' => 25,
        ];
        return $rank[$ev] ?? 0;
    }

    private static function medicineSlug( string $name ): string {
        $s = trim( $name );
        $s = preg_replace( '/\s+/u', '_', $s );
        if ( $s === '' ) return '';
        return mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 );
    }
}
$maintClass = IngestCpicPairLevels::class;
require_once RUN_MAINTENANCE_IF_MAIN;
