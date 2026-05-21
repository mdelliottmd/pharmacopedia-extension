<?php
/**
 * Ingest DPWG (Dutch Pharmacogenetics Working Group) guideline annotations
 * into pcp_interactions as medicine <-> phenotype edges with evidence='dpwg'.
 *
 * Source: the PharmGKB / ClinPGx bulk guideline-annotations file
 *   https://s3.pgkb.org/data/guidelineAnnotations.json.zip
 * a static snapshot (re-downloaded each run). Of ~220 guidelines it
 * contains, 110 are DPWG-sourced; ~65 are actionable. 85 of the DPWG
 * drug-gene pairs are NOT covered by CPIC, which is the value here:
 * genuine new coverage (cardiovascular drugs, beta-blockers,
 * anticoagulants CPIC has no guideline for).
 *
 * Edge model: one medicine <-> phenotype edge per standard phenotype the
 * guideline summary addresses (PM / IM / NM / UM / RM). The relationship
 * is the summary's HEADLINE action (avoid / prefer_alternative /
 * dose_reduce_* / dose_increase / monitor / normal_dose) applied across
 * the guideline's phenotypes. This is deliberately guideline-level-coarse:
 * the per-phenotype nuance (PM needs an alternative, IM just a dose cut)
 * lives in pi_mechanism as the full DPWG summary prose, which the renderer
 * surfaces. A per-clause parser was rejected as too fragile.
 *
 * Phenotype detection:
 *   "poor metaboli[sz]er"          -> _pm
 *   "intermediate metaboli[sz]er"  -> _im
 *   "normal/extensive metaboli..."  -> _nm
 *   "ultra(-)rapid metaboli..."     -> _um
 *   "rapid metaboli..." (non-ultra) -> _rm
 *   DPYD "activity score of 0"      -> _pm ; "of 1" / "1.5" -> _im
 * Guidelines with no detectable standard phenotype (VKORC1 genotype-based,
 * ABCG2, etc.) are skipped + logged.
 *
 * evidence='dpwg' (rank 60): sits above cpic_C / cpic_optional, below
 * cpic_moderate. The evidence-strength gate means DPWG never downgrades a
 * stronger CPIC row; for the 85 CPIC-uncovered pairs these are brand-new.
 *
 * Audit-logged under source='dpwg'. Idempotent.
 *
 * Usage:
 *   sudo -u www-data php maintenance/run.php \
 *     extensions/Pharmacopedia/maintenance/IngestDpwgGuidelines.php \
 *     --username=MDElliottMD [--dry-run] [--verbose]
 */
$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\Pharmacopedia\InteractionStore;
use MediaWiki\Extension\Pharmacopedia\IngestionLog;

class IngestDpwgGuidelines extends Maintenance {

    private const ZIP_URL = 'https://s3.pgkb.org/data/guidelineAnnotations.json.zip';

    public function __construct() {
        parent::__construct();
        $this->addOption( 'username', 'User to attribute rows to', true, true );
        $this->addOption( 'dry-run', 'Preview without writing', false, false );
        $this->addOption( 'verbose', 'Print every edge', false, false );
        $this->addOption( 'dir', 'Use a pre-extracted directory of PA*.json '
            . 'instead of downloading the zip', false, true );
        $this->requireExtension( 'Pharmacopedia' );
    }

    public function execute() {
        $services = MediaWikiServices::getInstance();
        $user = $services->getUserFactory()->newFromName( $this->getOption( 'username' ) );
        if ( !$user || !$user->isRegistered() ) {
            $this->fatalError( "User not found" );
        }
        $userId = (int)$user->getId();
        $dryRun  = $this->hasOption( 'dry-run' );
        $verbose = $this->hasOption( 'verbose' );
        $store = new InteractionStore();

        // Source the guideline JSON files. Two paths:
        //   --dir=<path>  use a pre-extracted directory of PA*.json
        //   default       download the zip + extract via the `unzip` CLI
        //                  (this PHP build has no ZipArchive extension)
        $preDir = $this->getOption( 'dir' );
        $cleanupDir = false;
        if ( $preDir ) {
            $tmpDir = rtrim( (string)$preDir, '/' );
            if ( !is_dir( $tmpDir ) ) $this->fatalError( "--dir not a directory: $tmpDir" );
            $this->output( "Using pre-extracted directory: $tmpDir\n" );
        } else {
            $this->output( "Fetching " . self::ZIP_URL . " ...\n" );
            $body = $services->getHttpRequestFactory()->get( self::ZIP_URL,
                [ 'timeout' => 90 ], __METHOD__ );
            if ( !$body ) $this->fatalError( "Download failed" );
            $tmpZip = tempnam( sys_get_temp_dir(), 'dpwg' ) . '.zip';
            file_put_contents( $tmpZip, $body );
            $this->output( "Got " . strlen( $body ) . " bytes\n" );

            $tmpDir = sys_get_temp_dir() . '/dpwg_extract_' . getmypid();
            @mkdir( $tmpDir );
            $cleanupDir = true;
            // Extract via the system `unzip` (no ZipArchive on this build).
            $unzip = trim( (string)shell_exec( 'command -v unzip 2>/dev/null' ) );
            if ( $unzip === '' ) {
                @unlink( $tmpZip );
                $this->fatalError( "No ZipArchive extension and no `unzip` CLI. "
                    . "Pre-extract the zip and re-run with --dir=<path>." );
            }
            $cmd = escapeshellarg( $unzip ) . ' -o -q '
                . escapeshellarg( $tmpZip ) . ' -d ' . escapeshellarg( $tmpDir )
                . ' 2>&1';
            $rc = null;
            $out = [];
            exec( $cmd, $out, $rc );
            @unlink( $tmpZip );
            if ( $rc !== 0 ) {
                $this->fatalError( "unzip failed (rc=$rc): " . implode( ' ', $out ) );
            }
        }
        $files = glob( "$tmpDir/PA*.json" );
        if ( !$files ) $this->fatalError( "No PA*.json files found in $tmpDir" );
        $this->output( "Found " . count( $files ) . " guideline files\n\n" );

        $logId = $dryRun ? 0
            : IngestionLog::startRun( 'dpwg', 'dpwg-guidelines-' . date( 'Ymd' ) );

        $stats = [
            'dpwg_total' => 0, 'dpwg_actionable' => 0,
            'edges_emitted' => 0, 'inserted' => 0, 'updated' => 0,
            'unchanged' => 0, 'skip_no_phenotype' => 0,
            'skip_no_drug_or_gene' => 0, 'skip_already_covered' => 0,
            'pruned' => 0, 'errors' => 0,
        ];

        // Prune phase: drop existing dpwg rows whose (drug, phenotype) pair
        // is now strongly covered (CPIC/FDA). Self-heals rows left by an
        // earlier ungated run and keeps re-runs idempotent. Skipped on dry-run.
        if ( !$dryRun ) {
            $dbrP = MediaWikiServices::getInstance()
                ->getConnectionProvider()->getReplicaDatabase();
            $dpwgRows = $dbrP->select( 'pcp_interactions',
                [ 'pi_id', 'pi_element_id', 'pi_left_type', 'pi_left_slug',
                  'pi_right_type', 'pi_right_slug' ],
                [ 'pi_evidence' => 'dpwg' ], __METHOD__ );
            foreach ( $dpwgRows as $r ) {
                if ( $r->pi_left_type === 'medicine' ) {
                    $drugS = (string)$r->pi_left_slug;
                    $phenS = (string)$r->pi_right_slug;
                } else {
                    $drugS = (string)$r->pi_right_slug;
                    $phenS = (string)$r->pi_left_slug;
                }
                if ( $this->stronglyCovered( $drugS, $phenS ) ) {
                    $store->deleteInteraction( (int)$r->pi_element_id );
                    $stats['pruned']++;
                    if ( $verbose ) $this->output(
                        "  pruned dpwg pi_id={$r->pi_id}  $drugS <-> $phenS\n" );
                }
            }
            if ( $stats['pruned'] ) {
                $this->output( "Pruned {$stats['pruned']} stale dpwg rows "
                    . "(now CPIC/FDA-covered)\n\n" );
            }
        }
        $skippedGuidelines = [];

        foreach ( $files as $file ) {
            $d = json_decode( (string)file_get_contents( $file ), true );
            if ( !is_array( $d ) ) continue;
            $g = $d['guideline'] ?? $d;
            if ( ( $g['source'] ?? '' ) !== 'DPWG' ) continue;
            $stats['dpwg_total']++;
            if ( ( $g['recommendation'] ?? false ) !== true ) continue;
            $stats['dpwg_actionable']++;

            $chems = array_values( array_filter( array_map(
                fn( $c ) => trim( (string)( $c['name'] ?? '' ) ),
                $g['relatedChemicals'] ?? [] ) ) );
            $genes = array_values( array_filter( array_map(
                fn( $x ) => trim( (string)( $x['symbol'] ?? '' ) ),
                $g['relatedGenes'] ?? [] ) ) );
            if ( count( $chems ) !== 1 || count( $genes ) !== 1 ) {
                // Multi-drug or multi-gene guideline: skip for v1 (rare).
                $stats['skip_no_drug_or_gene']++;
                continue;
            }
            $drug = $chems[0];
            $gene = $genes[0];

            $summary = self::plainText( $g['summaryMarkdown'] ?? '' );
            if ( $summary === '' ) { $stats['skip_no_drug_or_gene']++; continue; }

            $phenos = self::detectPhenotypes( $summary, $gene );
            if ( !$phenos ) {
                $stats['skip_no_phenotype']++;
                $skippedGuidelines[] = "$drug x $gene";
                continue;
            }
            [ $rel, $intensity ] = self::inferRelationship( $summary );

            $drugSlug = self::medicineSlug( $drug );
            $geneLc = strtolower( $gene );
            $mech = mb_substr( "DPWG guideline: " . $summary, 0, 2048 );

            foreach ( $phenos as $suffix ) {
                $phenSlug = $geneLc . '_' . $suffix;
                // DPWG fills gaps only. If this (drug, phenotype) pair is
                // already covered at evidence stronger than dpwg (rank 60) --
                // by CPIC or FDA, in ANY relationship -- skip it. Prevents
                // both the evidence-downgrade clobber and cross-relationship
                // collisions (e.g. a DPWG dose_reduce row landing next to a
                // CPIC/FDA 'avoid' on the same drug-phenotype).
                if ( $this->stronglyCovered( $drugSlug, $phenSlug ) ) {
                    $stats['skip_already_covered']++;
                    if ( $verbose ) $this->output(
                        "  skip-covered  $drugSlug <-> phenotype:$phenSlug\n" );
                    continue;
                }
                $stats['edges_emitted']++;
                $label = "$drugSlug <-> phenotype:$phenSlug ($rel)";
                if ( $dryRun ) {
                    if ( $verbose ) $this->output( "  would-emit  $label\n" );
                    continue;
                }
                $pre = $this->findExisting( $drugSlug, $phenSlug, $rel );
                $row = $store->getOrCreate(
                    InteractionStore::TYPE_MEDICINE, $drugSlug,
                    InteractionStore::TYPE_PHENOTYPE, $phenSlug, $userId, [
                        'relationship' => $rel,
                        'intensity'    => $intensity,
                        'evidence'     => 'dpwg',
                        'mechanism'    => $mech,
                        'ingestion_id' => $logId,
                    ] );
                if ( !$row ) { $stats['errors']++; continue; }
                if ( !$pre ) {
                    $stats['inserted']++;
                    if ( $verbose ) $this->output( "  + $label\n" );
                } else {
                    $changed = false;
                    foreach ( [ 'pi_intensity', 'pi_evidence', 'pi_mechanism' ] as $c ) {
                        if ( (string)( $pre->{$c} ?? '' ) !== (string)( $row->{$c} ?? '' ) ) {
                            $changed = true; break;
                        }
                    }
                    if ( $changed ) { $stats['updated']++; if ( $verbose ) $this->output( "  ~ $label\n" ); }
                    else            { $stats['unchanged']++; }
                }
            }
        }

        // Cleanup extracted files (only if we created the temp dir).
        if ( $cleanupDir ) {
            foreach ( glob( "$tmpDir/*" ) as $f ) @unlink( $f );
            @rmdir( $tmpDir );
        }

        $this->output( "\nSummary:\n" );
        foreach ( $stats as $k => $v ) $this->output( sprintf( "  %-22s %d\n", $k, $v ) );
        if ( $skippedGuidelines ) {
            $this->output( "\nSkipped (no standard phenotype detected; activity-score / "
                . "genotype-based):\n" );
            foreach ( array_slice( $skippedGuidelines, 0, 20 ) as $s ) {
                $this->output( "  $s\n" );
            }
        }

        if ( !$dryRun ) {
            $note = sprintf( "DPWG guideline ingest: %d DPWG guidelines, %d actionable, "
                . "%d edges emitted (%d ins, %d upd, %d unchanged), "
                . "%d skipped-no-phenotype.",
                $stats['dpwg_total'], $stats['dpwg_actionable'], $stats['edges_emitted'],
                $stats['inserted'], $stats['updated'], $stats['unchanged'],
                $stats['skip_no_phenotype'] );
            IngestionLog::finishRun( $logId, $stats['inserted'], $stats['updated'], $note );
            $this->output( "\nLogged as pcp_ingestion_log.il_id=$logId\n" );
        }
    }

    /**
     * True if the (medicine, phenotype) pair already has any pcp_interactions
     * row at evidence stronger than dpwg (rank 60): fda_box, cpic_A,
     * cpic_strong, fda_label, cpic_B, cpic_moderate. DPWG defers to those.
     */
    private function stronglyCovered( string $drugSlug, string $phenSlug ): bool {
        static $strong = [ 'fda_box', 'cpic_A', 'cpic_strong',
                           'fda_label', 'cpic_B', 'cpic_moderate' ];
        $pair = InteractionStore::normalizePair(
            InteractionStore::TYPE_MEDICINE, $drugSlug,
            InteractionStore::TYPE_PHENOTYPE, $phenSlug );
        if ( !$pair ) return false;
        [ $nlt, $nls, $nrt, $nrs ] = $pair;
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $row = $dbr->selectRow( 'pcp_interactions', 'pi_id', [
            'pi_left_type' => $nlt, 'pi_left_slug' => $nls,
            'pi_right_type' => $nrt, 'pi_right_slug' => $nrs,
            'pi_evidence' => $strong,
        ], __METHOD__ );
        return (bool)$row;
    }

    private function findExisting( string $drugSlug, string $phenSlug, string $rel ) {
        $pair = InteractionStore::normalizePair(
            InteractionStore::TYPE_MEDICINE, $drugSlug,
            InteractionStore::TYPE_PHENOTYPE, $phenSlug );
        if ( !$pair ) return null;
        [ $nlt, $nls, $nrt, $nrs ] = $pair;
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        return $dbr->selectRow( 'pcp_interactions', '*', [
            'pi_left_type' => $nlt, 'pi_left_slug' => $nls,
            'pi_right_type' => $nrt, 'pi_right_slug' => $nrs,
            'pi_relationship' => $rel,
        ], __METHOD__ );
    }

    /** Strip HTML + entities + collapse whitespace. */
    private static function plainText( $md ): string {
        if ( is_array( $md ) ) $md = $md['html'] ?? $md['markdown'] ?? '';
        $t = html_entity_decode( (string)$md, ENT_QUOTES, 'UTF-8' );
        $t = preg_replace( '/<[^>]+>/', ' ', $t );
        return trim( preg_replace( '/\s+/u', ' ', $t ) );
    }

    /** Detect which standard phenotype suffixes a DPWG summary addresses. */
    private static function detectPhenotypes( string $summary, string $gene ): array {
        $s = strtolower( $summary );
        $out = [];
        if ( strpos( $s, 'ultrarapid metaboli' ) !== false
             || strpos( $s, 'ultra-rapid metaboli' ) !== false
             || strpos( $s, 'ultra rapid metaboli' ) !== false ) {
            $out['um'] = true;
        }
        // "rapid metaboliser" not preceded by "ultra".
        if ( preg_match( '/(?<!ultra)(?<!ultra[ -])\brapid metaboli[sz]er/', $s ) ) {
            $out['rm'] = true;
        }
        if ( strpos( $s, 'poor metaboli' ) !== false )          $out['pm'] = true;
        if ( strpos( $s, 'intermediate metaboli' ) !== false )  $out['im'] = true;
        if ( strpos( $s, 'normal metaboli' ) !== false
             || strpos( $s, 'extensive metaboli' ) !== false )  $out['nm'] = true;
        // DPYD activity-score phrasing.
        if ( preg_match( '/activity score of 0\b/', $s ) )       $out['pm'] = true;
        if ( preg_match( '/activity score of 1(\.5)?\b/', $s ) ) $out['im'] = true;
        return array_keys( $out );
    }

    /** Infer the headline relationship + intensity from a DPWG summary. */
    private static function inferRelationship( string $summary ): array {
        $s = strtolower( $summary );
        if ( strpos( $s, 'avoid' ) !== false ) {
            return [ 'avoid', 85 ];
        }
        if ( strpos( $s, 'alternative drug' ) !== false
             || strpos( $s, 'alternative to' ) !== false
             || strpos( $s, 'select an alternative' ) !== false
             || strpos( $s, 'choose an alternative' ) !== false ) {
            return [ 'prefer_alternative', 60 ];
        }
        if ( preg_match( '/\b50%|\bhalf the|\bhalve\b/', $s ) && strpos( $s, 'dose' ) !== false ) {
            return [ 'dose_reduce_50', 60 ];
        }
        if ( preg_match( '/reduc\w+ .{0,30}dose|lower\w* .{0,20}dose|dose reduction'
            . '|\d+% of the standard dose|reduce the (initial|maximum) dose/', $s ) ) {
            return [ 'dose_reduce_25', 45 ];
        }
        if ( preg_match( '/higher dose|increase\w* .{0,20}dose|-fold higher'
            . '|times the standard dose|use a \d/', $s ) ) {
            return [ 'dose_increase', 45 ];
        }
        if ( strpos( $s, 'monitor' ) !== false || strpos( $s, 'be alert' ) !== false ) {
            return [ 'monitor', 40 ];
        }
        if ( strpos( $s, 'no action' ) !== false ) {
            return [ 'normal_dose', 10 ];
        }
        return [ 'monitor', 40 ];   // conservative fallback
    }

    private static function medicineSlug( string $name ): string {
        $s = preg_replace( '/\s+/u', '_', trim( $name ) );
        if ( $s === '' ) return '';
        return mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 );
    }
}
$maintClass = IngestDpwgGuidelines::class;
require_once RUN_MAINTENANCE_IF_MAIN;
