<?php
/**
 * Ingest the FDA "Table of Substrates, Inhibitors and Inducers" (the drug-
 * interaction guidance table) into pcp_interactions as medicine <-> enzyme
 * and medicine <-> transporter edges.
 *
 * Source: https://www.fda.gov/drugs/drug-interactions-labeling/
 *         drug-development-and-drug-interactions-table-substrates-inhibitors-and-inducers
 *
 * This is the data the inference engine has been starved for: CPIC's
 * /recommendation endpoint is phenotype-dosing only and carries no
 * substrate/inhibitor metadata. The FDA DDI table classifies clinical
 * substrates + inhibitors + inducers by strength, which is exactly what
 * inferDerivedEdges cross-products into derived med-med PK interactions.
 *
 * Tables consumed (identified by header signature, not position):
 *   "Sensitive index substrates"  -> substrate_major  (CYP, intensity 80)
 *   "Strong index inhibitors"     -> inhibitor_strong  (CYP, intensity 90)
 *   "Moderate index inhibitors"   -> inhibitor_moderate(CYP, intensity 55)
 *   "Strong inducers"             -> inducer_strong    (CYP, intensity 90)
 *   "Moderate inducers"           -> inducer_moderate  (CYP, intensity 55)
 *   Transporter Substrate table   -> substrate         (intensity 70)
 *   Transporter Inhibitor table   -> inhibitor         (intensity 70)
 *
 * Skipped: the in-vitro experimental-probe tables (chemical reagents like
 * furafylline, alpha-naphthoflavone — not medicines).
 *
 * Evidence: 'primary' for all edges. Substrate/inhibitor classification is
 * established pharmacology fact; FDA's table is a curation of it. NOT
 * 'fda_label' — the validator (correctly) flags mild-relationship rows
 * carrying fda_* evidence as the over-promotion bug pattern.
 *
 * Research-chemical filter: transporter tables mix real drugs with probes
 * (PhIP, elacridar, ko143, estradiol-glucuronide, etc.). Filtered by a
 * blocklist + heuristics (brackets, Greek letters, '+', leading digit,
 * uppercase-abbrev parens, metabolite/conjugate keywords).
 *
 * Audit-logged under source='fda_table'. After ingest, re-run
 * inferDerivedEdges to materialize the new derived edges.
 *
 * Usage:
 *   sudo -u www-data php maintenance/run.php \
 *     extensions/Pharmacopedia/maintenance/IngestFdaCypDdi.php \
 *     --username=MDElliottMD [--dry-run] [--verbose]
 */
$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\Pharmacopedia\InteractionStore;
use MediaWiki\Extension\Pharmacopedia\IngestionLog;

class IngestFdaCypDdi extends Maintenance {

    private const URL = 'https://www.fda.gov/drugs/drug-interactions-labeling/'
        . 'drug-development-and-drug-interactions-table-substrates-inhibitors-and-inducers';

    /** Research chemicals / metabolites / endogenous compounds — not medicines. */
    private const BLOCKLIST = [
        'creatinine', 'elacridar', 'valspodar', 'zosuquidar', 'fumitremorgin c',
        'ko143', 'bromosulfophthalein', 'rifamycin sv', 'n-methylquinidine',
        'cholecystokinin octapeptide',
    ];

    public function __construct() {
        parent::__construct();
        $this->addOption( 'username', 'User to attribute rows to', true, true );
        $this->addOption( 'dry-run', 'Preview without writing', false, false );
        $this->addOption( 'verbose', 'Print every edge emitted', false, false );
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

        $this->output( "Fetching FDA DDI table ...\n" );
        $html = $services->getHttpRequestFactory()->get( self::URL, [
            'timeout' => 30,
            'userAgent' => 'Mozilla/5.0 (X11; Linux x86_64) Pharmacopedia/0.9',
        ], __METHOD__ );
        if ( !$html ) $this->fatalError( "FDA fetch failed" );
        $this->output( "Got " . strlen( $html ) . " bytes\n" );

        $tables = $this->parseTables( $html );
        $this->output( "Parsed " . count( $tables ) . " HTML tables\n\n" );

        $logId = $dryRun ? 0
            : IngestionLog::startRun( 'fda_table', 'fda-cyp-ddi-' . date( 'Ymd' ) );

        $stats = [
            'edges_considered' => 0, 'inserted' => 0, 'updated' => 0,
            'unchanged' => 0, 'filtered_nonmed' => 0, 'errors' => 0,
        ];
        $filtered = [];

        foreach ( $tables as $tbl ) {
            $header = $tbl[0] ?? [];
            $hjoined = strtolower( implode( ' | ', $header ) );

            // --- CYP substrate table ---
            if ( strpos( $hjoined, 'sensitive index substrate' ) !== false ) {
                foreach ( array_slice( $tbl, 1 ) as $row ) {
                    if ( count( $row ) < 2 ) continue;
                    $enzyme = self::normEnzyme( $row[0] );
                    foreach ( self::splitDrugCell( $row[1] ) as $tok ) {
                        $this->emitCypEdge( $store, $userId, $logId, $tok, $enzyme,
                            'substrate_major', 80, 'sensitive index substrate',
                            $dryRun, $verbose, $stats, $filtered );
                    }
                }
                continue;
            }
            // --- CYP inhibitor table (strong + moderate columns) ---
            if ( strpos( $hjoined, 'strong index inhibitor' ) !== false ) {
                foreach ( array_slice( $tbl, 1 ) as $row ) {
                    if ( count( $row ) < 3 ) continue;
                    $enzyme = self::normEnzyme( $row[0] );
                    foreach ( self::splitDrugCell( $row[1] ) as $tok ) {
                        $this->emitCypEdge( $store, $userId, $logId, $tok, $enzyme,
                            'inhibitor_strong', 90, 'strong index inhibitor',
                            $dryRun, $verbose, $stats, $filtered );
                    }
                    foreach ( self::splitDrugCell( $row[2] ) as $tok ) {
                        $this->emitCypEdge( $store, $userId, $logId, $tok, $enzyme,
                            'inhibitor_moderate', 55, 'moderate index inhibitor',
                            $dryRun, $verbose, $stats, $filtered );
                    }
                }
                continue;
            }
            // --- CYP inducer table (strong + moderate columns) ---
            if ( strpos( $hjoined, 'strong inducer' ) !== false ) {
                foreach ( array_slice( $tbl, 1 ) as $row ) {
                    if ( count( $row ) < 3 ) continue;
                    $enzyme = self::normEnzyme( $row[0] );
                    if ( $enzyme === '' ) continue;
                    foreach ( self::splitDrugCell( $row[1] ) as $tok ) {
                        $this->emitCypEdge( $store, $userId, $logId, $tok, $enzyme,
                            'inducer_strong', 90, 'strong inducer',
                            $dryRun, $verbose, $stats, $filtered );
                    }
                    foreach ( self::splitDrugCell( $row[2] ) as $tok ) {
                        $this->emitCypEdge( $store, $userId, $logId, $tok, $enzyme,
                            'inducer_moderate', 55, 'moderate inducer',
                            $dryRun, $verbose, $stats, $filtered );
                    }
                }
                continue;
            }
            // --- Transporter substrate / inhibitor tables ---
            if ( $hjoined === 'transporter | gene | substrate'
                 || $hjoined === 'transporter | gene | inhibitor' ) {
                $isInhib = ( strpos( $hjoined, 'inhibitor' ) !== false );
                $rel = $isInhib ? 'inhibitor' : 'substrate';
                $note = $isInhib ? 'transporter inhibitor' : 'transporter substrate';
                foreach ( array_slice( $tbl, 1 ) as $row ) {
                    if ( count( $row ) < 3 ) continue;
                    $gene = self::normTransporterGene( $row[1] );
                    if ( $gene === '' ) continue;
                    foreach ( self::splitDrugCell( $row[2] ) as $tok ) {
                        $this->emitTransporterEdge( $store, $userId, $logId, $tok,
                            $gene, $rel, 70, $note, $dryRun, $verbose, $stats, $filtered );
                    }
                }
                continue;
            }
            // else: in-vitro experimental table — skip silently.
        }

        $this->output( "\nSummary:\n" );
        foreach ( $stats as $k => $v ) $this->output( sprintf( "  %-20s %d\n", $k, $v ) );
        if ( $filtered ) {
            $u = array_count_values( $filtered );
            arsort( $u );
            $this->output( "\nFiltered non-medicine tokens (top 15):\n" );
            $i = 0;
            foreach ( $u as $name => $n ) {
                $this->output( "  $name\n" );
                if ( ++$i >= 15 ) break;
            }
        }

        if ( !$dryRun ) {
            $note = sprintf( "FDA CYP/transporter DDI table: %d edges considered, "
                . "%d inserted, %d updated, %d unchanged, %d non-med filtered.",
                $stats['edges_considered'], $stats['inserted'], $stats['updated'],
                $stats['unchanged'], $stats['filtered_nonmed'] );
            IngestionLog::finishRun( $logId, $stats['inserted'], $stats['updated'], $note );
            $this->output( "\nLogged as pcp_ingestion_log.il_id=$logId\n" );
            $this->output( "Re-run inferDerivedEdges to materialize the new derived edges.\n" );
        }
    }

    private function emitCypEdge( InteractionStore $store, int $userId, int $logId,
        string $token, string $enzyme, string $rel, int $intensity, string $noteTag,
        bool $dryRun, bool $verbose, array &$stats, array &$filtered ): void
    {
        $drug = self::cleanDrugName( $token );
        if ( $drug === null ) {
            $stats['filtered_nonmed']++;
            $filtered[] = trim( $token );
            return;
        }
        if ( $enzyme === '' ) return;
        $stats['edges_considered']++;
        $label = "$drug <-> enzyme:$enzyme ($rel)";
        if ( $dryRun ) {
            if ( $verbose ) $this->output( "  would-emit  $label\n" );
            return;
        }
        $pre = $this->findExisting( $store, InteractionStore::TYPE_MEDICINE, $drug,
            InteractionStore::TYPE_ENZYME, $enzyme, $rel );
        $row = $store->getOrCreate(
            InteractionStore::TYPE_MEDICINE, $drug,
            InteractionStore::TYPE_ENZYME, $enzyme, $userId, [
                'relationship' => $rel,
                'intensity'    => $intensity,
                'evidence'     => 'primary',
                'mechanism'    => "FDA Drug Interactions Table: $noteTag of $enzyme.",
                'ingestion_id' => $logId,
            ] );
        $this->tallyUpsert( $row, $pre, $label, $verbose, $stats );
    }

    private function emitTransporterEdge( InteractionStore $store, int $userId, int $logId,
        string $token, string $gene, string $rel, int $intensity, string $noteTag,
        bool $dryRun, bool $verbose, array &$stats, array &$filtered ): void
    {
        $drug = self::cleanDrugName( $token );
        if ( $drug === null ) {
            $stats['filtered_nonmed']++;
            $filtered[] = trim( $token );
            return;
        }
        $stats['edges_considered']++;
        $label = "$drug <-> transporter:$gene ($rel)";
        if ( $dryRun ) {
            if ( $verbose ) $this->output( "  would-emit  $label\n" );
            return;
        }
        $pre = $this->findExisting( $store, InteractionStore::TYPE_MEDICINE, $drug,
            InteractionStore::TYPE_TRANSPORTER, $gene, $rel );
        $row = $store->getOrCreate(
            InteractionStore::TYPE_MEDICINE, $drug,
            InteractionStore::TYPE_TRANSPORTER, $gene, $userId, [
                'relationship' => $rel,
                'intensity'    => $intensity,
                'evidence'     => 'primary',
                'mechanism'    => "FDA Drug Interactions Table: $noteTag ($gene).",
                'ingestion_id' => $logId,
            ] );
        $this->tallyUpsert( $row, $pre, $label, $verbose, $stats );
    }

    private function findExisting( InteractionStore $store, string $lt, string $ls,
        string $rt, string $rs, string $rel )
    {
        $pair = InteractionStore::normalizePair( $lt, $ls, $rt, $rs );
        if ( !$pair ) return null;
        [ $nlt, $nls, $nrt, $nrs ] = $pair;
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        return $dbr->selectRow( 'pcp_interactions', '*', [
            'pi_left_type' => $nlt, 'pi_left_slug' => $nls,
            'pi_right_type' => $nrt, 'pi_right_slug' => $nrs,
            'pi_relationship' => $rel,
        ], __METHOD__ );
    }

    private function tallyUpsert( $row, $pre, string $label, bool $verbose, array &$stats ): void {
        if ( !$row ) { $stats['errors']++; return; }
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

    /** Parse all HTML tables into list-of-rows-of-cells via DOMDocument. */
    private function parseTables( string $html ): array {
        $prev = libxml_use_internal_errors( true );
        $dom = new \DOMDocument();
        $dom->loadHTML( $html );
        libxml_use_internal_errors( $prev );
        $out = [];
        foreach ( $dom->getElementsByTagName( 'table' ) as $table ) {
            $rows = [];
            foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
                $cells = [];
                foreach ( $tr->childNodes as $c ) {
                    if ( $c->nodeType !== XML_ELEMENT_NODE ) continue;
                    if ( $c->nodeName !== 'td' && $c->nodeName !== 'th' ) continue;
                    $cells[] = trim( preg_replace( '/\s+/u', ' ',
                        html_entity_decode( $c->textContent, ENT_QUOTES, 'UTF-8' ) ) );
                }
                if ( $cells ) $rows[] = $cells;
            }
            if ( $rows ) $out[] = $rows;
        }
        return $out;
    }

    /** Split a drug-list cell on commas, protecting commas inside ()/[]. */
    private static function splitDrugCell( string $cell ): array {
        $depth = 0; $buf = '';
        $len = strlen( $cell );
        for ( $i = 0; $i < $len; $i++ ) {
            $ch = $cell[$i];
            if ( $ch === '(' || $ch === '[' ) $depth++;
            elseif ( $ch === ')' || $ch === ']' ) $depth = max( 0, $depth - 1 );
            $buf .= ( $ch === ',' && $depth > 0 ) ? "\x01" : $ch;
        }
        $out = [];
        foreach ( explode( ',', $buf ) as $p ) {
            $p = trim( str_replace( "\x01", ',', $p ) );
            if ( $p !== '' && $p !== '-' ) $out[] = $p;
        }
        return $out;
    }

    /**
     * Clean a single drug token: strip footnote parens, reject research
     * chemicals / metabolites / endogenous compounds. Returns null to skip.
     */
    private static function cleanDrugName( string $tok ): ?string {
        // Strip footnote parens: lowercase letters / commas / spaces only.
        $t = preg_replace( '/\s*\([a-z][a-z,\s]*\)\s*/', ' ', $tok );
        $t = trim( $t );
        if ( $t === '' || $t === '-' ) return null;
        // Reject brackets, '+', non-ASCII (Greek), leading digit.
        if ( strpbrk( $t, '[]+' ) !== false ) return null;
        if ( preg_match( '/[^\x00-\x7F]/', $t ) ) return null;
        if ( preg_match( '/^\d/', $t ) ) return null;
        // Uppercase-abbreviation paren remaining => research chemical.
        if ( preg_match( '/\([A-Z0-9]/', $t ) ) return null;
        // Metabolite / conjugate / endogenous keywords.
        if ( preg_match( '/glucuronide|sulfate|octapeptide|aminohippurate'
            . '|pyridinium|ethylammonium/i', $t ) ) return null;
        $low = strtolower( $t );
        if ( in_array( $low, self::BLOCKLIST, true ) ) return null;
        // Normalizations.
        if ( $low === 's-warfarin' || $low === 'r-warfarin' ) $t = 'warfarin';
        elseif ( $low === 'rifampicin' )                       $t = 'rifampin';
        // First-letter-cap to match medicine slug convention.
        return mb_strtoupper( mb_substr( $t, 0, 1 ) ) . mb_substr( $t, 1 );
    }

    private static function normEnzyme( string $raw ): string {
        $e = preg_replace( '/\s*\([a-z][a-z,\s]*\)\s*/', '', $raw );
        $e = strtoupper( trim( $e ) );
        // FDA uses the CYP3A subfamily label; our data uses the dominant
        // isoform CYP3A4.
        if ( $e === 'CYP3A' ) $e = 'CYP3A4';
        return $e;
    }

    private static function normTransporterGene( string $geneCell ): string {
        // "SLCO1B1, SLCO1B3" -> first gene as canonical.
        $parts = explode( ',', $geneCell );
        return strtoupper( trim( $parts[0] ) );
    }
}
$maintClass = IngestFdaCypDdi::class;
require_once RUN_MAINTENANCE_IF_MAIN;
