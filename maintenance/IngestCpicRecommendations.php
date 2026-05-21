<?php
/**
 * Phase 1 PGx Step D: ingest CPIC /recommendation into pcp_interactions.
 *
 * For each CPIC recommendation row, emit one pcp_interactions row per
 * (gene, phenotype-or-allele) key it carries. Multi-gene combo rows
 * fan out: a CYP2C19+CYP2D6 rec produces one edge per gene.
 *
 * Mapping rules:
 *   drugid -> CPIC name -> first-letter-cap medicine slug
 *   gene + phenotype label -> phenotype slug ("cyp2d6_um", "tpmt_pm", ...)
 *   gene + allele status (HLA only, positive carriers) -> variant slug ("hla-b_5701_pos")
 *   classification -> evidence (cpic_strong/cpic_moderate/cpic_optional/cpic_D)
 *   drugrecommendation text -> relationship + intensity (heuristic)
 *
 * Skipped:
 *   - phenotype "No Result" / "Indeterminate" (non-actionable)
 *   - allelestatus "negative" + normal-dose recommendation (implicit default)
 *
 * Audit-logged under source='cpic_api'.
 *
 * Usage:
 *   sudo -u www-data php maintenance/run.php extensions/Pharmacopedia/maintenance/IngestCpicRecommendations.php \
 *     --username=MDElliottMD [--dry-run] [--limit=N] [--verbose]
 */
$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\Pharmacopedia\InteractionStore;
use MediaWiki\Extension\Pharmacopedia\IngestionLog;

class IngestCpicRecommendations extends Maintenance {

    /** Set by execute() at start-of-run; threaded into emitEdge() so each
     *  inserted pcp_interactions row stamps pi_ingestion_id at creation. */
    private $currentLogId = 0;

    private const PAGE_SIZE = 500;

    /** CPIC phenotype label -> compact slug suffix. */
    private const PHEN_SUFFIX = [
        'Ultrarapid Metabolizer'           => 'um',
        'Rapid Metabolizer'                => 'rm',
        'Normal Metabolizer'               => 'nm',
        'Intermediate Metabolizer'         => 'im',
        'Likely Intermediate Metabolizer'  => 'lim',
        'Poor Metabolizer'                 => 'pm',
        'Likely Poor Metabolizer'          => 'lpm',
        // SLCO1B1 + transporter-class function labels
        'Increased Function'               => 'if',
        'Normal Function'                  => 'nf',
        'Decreased Function'               => 'df',
        'Poor Function'                    => 'pf',
        'Possible Increased Function'      => 'pif',
        'Possible Decreased Function'      => 'pdf',
        'Possible Poor Function'           => 'ppf',
        // G6PD activity-based
        'Deficient'                        => 'def',
        'Deficient with CNSHA'             => 'def_cnsha',
        'Variable'                         => 'var',
        'Normal'                           => 'nm',
        // RYR1 / CACNA1S
        'Malignant Hyperthermia Susceptibility' => 'mh_susc',
        'Uncertain Susceptibility'         => 'uncertain',
        // MT-RNR1
        'Increased risk of aminoglycoside-induced hearing loss' => 'risk',
        'Normal risk of aminoglycoside-induced hearing loss'    => 'normal',
        'Uncertain risk of aminoglycoside-induced hearing loss' => 'uncertain',
        // 2026-05-18 hygiene additions:
        //   - Some genes (TPMT, NUDT15, CYP3A5) include a 'Possible IM' tier.
        //   - CFTR ivacaftor-response phenotype is a CF-specific efficacy split.
        'Possible Intermediate Metabolizer'      => 'pim',
        'ivacaftor responsive in CF patients'    => 'ivacaftor_r',
        'ivacaftor non-responsive in CF patients' => 'ivacaftor_nr',
    ];

    /** Skip these phenotypes entirely (non-actionable). */
    private const PHEN_SKIP = [
        'Indeterminate', 'No Result',
        'No CYP2D6 Result', 'No CYP2C19 Result', 'No HLA-A Result', 'No HLA-B Result',
    ];

    /** Pattern -> [relationship, intensity_bonus]. Order: most-specific first. */
    private const REL_PATTERNS = [
        [ '/\babacavir is not recommended\b/i',         'contraindication', 25 ],
        [ '/\bcontraindicat/i',                          'contraindication', 25 ],
        [ '/\bdo not use\b/i',                           'avoid', 20 ],
        [ '/\bis not recommended\b/i',                   'avoid', 18 ],
        [ '/\bavoid\b/i',                                'avoid', 15 ],
        [ '/reduce.*starting dose.*50|50%\s*reduction|halve.*dose|reduce.*by\s*50/i', 'dose_reduce_50', 10 ],
        [ '/reduce.*starting dose|reduce.*dose|lower.*starting|reduce.*by\s*25/i', 'dose_reduce_25', 5 ],
        [ '/consider.*alternative|alternative.*(drug|agent|medication)/i', 'prefer_alternative', 10 ],
        [ '/higher.*starting dose|increase.*starting dose|higher.*dose|increase.*dose/i', 'dose_increase', 5 ],
        [ '/monitor.*closely|closely.*monitor|intensified.*monitor|enhanced.*monitor|with monitoring/i', 'monitor', 0 ],
        [ '/per (?:label|standard)|standard dosing|normal dosing|use as recommended|standard precaution/i', 'normal_dose', -10 ],
        [ '/no\s+(?:action|adjustment|change)/i',        'normal_dose', -10 ],
        // G6PD info-only template -> route to monitor.
        [ '/ascertain.*g6pd status/i',                   'monitor', -5 ],
    ];

    /** Classification -> [evidence_code, base_intensity]. */
    private const CLASS_MAP = [
        'Strong'            => [ 'cpic_strong',   70 ],
        'Moderate'          => [ 'cpic_moderate', 45 ],
        'Optional'          => [ 'cpic_optional', 20 ],
        'No Recommendation' => [ 'cpic_D',         5 ],
        'n/a'               => [ 'cpic_D',         5 ],
    ];

    /** Ranking used to gate upserts: only replace an existing row when the
     *  incoming evidence is at least as strong. Prevents weaker rec rows
     *  (e.g. pediatric Optional) from downgrading a Strong general rec.
     */
    private const EVIDENCE_RANK = [
        'fda_box'       => 100,
        'cpic_A'        => 95,
        'cpic_strong'   => 90,
        'fda_label'     => 85,
        'cpic_B'        => 75,
        'cpic_moderate' => 70,
        'cpic_C'        => 50,
        'cpic_optional' => 45,
        'cpic_D'        => 30,
        'dpwg'          => 60,
        'primary'       => 50,
        'theoretical'   => 20,
        'derived'       => 25,
    ];

    public function __construct() {
        parent::__construct();
        $this->addOption( 'username', 'User to attribute rows to', true, true );
        $this->addOption( 'dry-run',  'Don\'t write anything',     false, false );
        $this->addOption( 'limit',    'Cap on rows fetched (testing)', false, true );
        $this->addOption( 'verbose',  'Print every emitted edge',  false, false );
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
        $limit = $this->hasOption( 'limit' ) ? (int)$this->getOption( 'limit' ) : 0;
        $http  = $services->getHttpRequestFactory();
        $store = new InteractionStore();

        // 1) Build drugid -> name map from /drug.
        $this->output( "Fetching /v1/drug ...\n" );
        $body = $http->get( 'https://api.cpicpgx.org/v1/drug?limit=1000',
            [ 'timeout' => 30 ], __METHOD__ );
        $drugs = json_decode( (string)$body, true ) ?: [];
        $drugMap = [];
        foreach ( $drugs as $d ) {
            $rxId = (string)( $d['drugid'] ?? '' );
            $name = trim( (string)( $d['name'] ?? '' ) );
            if ( $rxId && $name ) $drugMap[ $rxId ] = $name;
        }
        $this->output( "Got " . count( $drugMap ) . " drugid -> name mappings\n\n" );

        // 2) Paginate through /recommendation.
        $stats = [
            'fetched'         => 0,
            'considered'      => 0,
            'emitted'         => 0,
            'inserted'        => 0,
            'updated'         => 0,
            'unchanged'       => 0,
            'skip_no_drug'    => 0,
            'skip_phen'       => 0,
            'skip_neg_default' => 0,
            'skip_unmapped_class' => 0,
            'errors'          => 0,
        ];
        $unmappedDrugs = []; $unmappedPhens = [];

        // Provenance: open the audit log so each inserted row can stamp
        // pi_ingestion_id at creation. Closed at end with finishRun().
        $logId = $dryRun ? 0
            : IngestionLog::startRun( 'cpic_api', 'cpic-recommendation-' . date( 'Ymd' ) );
        $this->currentLogId = $logId;

        $offset = 0;
        while ( true ) {
            $url = sprintf( 'https://api.cpicpgx.org/v1/recommendation?limit=%d&offset=%d',
                self::PAGE_SIZE, $offset );
            $body = $http->get( $url, [ 'timeout' => 60 ], __METHOD__ );
            $recs = json_decode( (string)$body, true );
            if ( !is_array( $recs ) || !$recs ) break;
            $this->output( sprintf( "Fetched offset=%d count=%d\n", $offset, count( $recs ) ) );
            foreach ( $recs as $r ) {
                $stats['fetched']++;
                if ( $limit > 0 && $stats['fetched'] > $limit ) break 2;
                $this->processRec( $r, $drugMap, $store, $userId,
                    $dryRun, $verbose, $stats, $unmappedDrugs, $unmappedPhens );
            }
            $offset += self::PAGE_SIZE;
            if ( count( $recs ) < self::PAGE_SIZE ) break;
        }

        $this->output( "\nSummary:\n" );
        foreach ( $stats as $k => $v ) {
            $this->output( sprintf( "  %-22s %d\n", $k, $v ) );
        }
        if ( $unmappedDrugs ) {
            $this->output( "\nUnmapped drugids (top 10): " . implode( ', ',
                array_slice( array_keys( $unmappedDrugs ), 0, 10 ) ) . "\n" );
        }
        if ( $unmappedPhens ) {
            arsort( $unmappedPhens );
            $this->output( "\nUnmapped phenotype labels (top 10):\n" );
            $i = 0;
            foreach ( $unmappedPhens as $k => $v ) {
                $this->output( "  $v\t$k\n" );
                if ( ++$i >= 10 ) break;
            }
        }

        if ( !$dryRun ) {
            $note = sprintf( "CPIC /recommendation ingest: %d fetched / %d emitted "
                . "(%d ins, %d upd, %d unchanged); %d skipped-no-drug, "
                . "%d skipped-phen, %d skipped-neg, %d errors.",
                $stats['fetched'], $stats['emitted'], $stats['inserted'],
                $stats['updated'], $stats['unchanged'], $stats['skip_no_drug'],
                $stats['skip_phen'], $stats['skip_neg_default'], $stats['errors'] );
            IngestionLog::finishRun( $logId,
                $stats['inserted'], $stats['updated'], $note );
            $this->output( "Logged as pcp_ingestion_log.il_id=$logId\n" );
            $this->output( "Re-run inference engine to materialize derived edges from any new substrate/inhibitor rows.\n" );
        }
    }

    private function processRec( $r, array $drugMap, InteractionStore $store,
        int $userId, bool $dryRun, bool $verbose, array &$stats,
        array &$unmappedDrugs, array &$unmappedPhens ): void
    {
        $drugId = (string)( $r['drugid'] ?? '' );
        if ( !isset( $drugMap[$drugId] ) ) {
            $stats['skip_no_drug']++;
            $unmappedDrugs[$drugId] = true;
            return;
        }
        $rawName = $drugMap[$drugId];
        $medSlug = self::medicineSlug( $rawName );

        $rec = (string)( $r['drugrecommendation'] ?? '' );
        $cls = (string)( $r['classification'] ?? '' );
        if ( !isset( self::CLASS_MAP[$cls] ) ) { $stats['skip_unmapped_class']++; return; }
        [ $evidence, $baseI ] = self::CLASS_MAP[$cls];
        [ $rel, $bonusI ] = self::inferRelationship( $rec );
        $intensity = max( 0, min( 100, $baseI + $bonusI ) );

        $phenotypes = is_array( $r['phenotypes']   ?? null ) ? $r['phenotypes']   : [];
        $alleles    = is_array( $r['allelestatus'] ?? null ) ? $r['allelestatus'] : [];

        $cpicRecId = (int)( $r['id'] ?? 0 );
        $mechRaw = $rec !== '' ? $rec : "(no recommendation text)";
        $mech = mb_substr( "CPIC rec $cpicRecId [$cls]: $mechRaw", 0, 2048 );

        // --- Phenotype edges ---
        // The vocab places 'contraindication' under variant edges; the
        // phenotype-side severe-action equivalent is 'avoid'. Coerce here
        // so we don't ship phenotype rows that fail the invariant check.
        $phenRel = ( $rel === 'contraindication' ) ? 'avoid' : $rel;
        foreach ( $phenotypes as $gene => $phenLabel ) {
            $stats['considered']++;
            $gene = (string)$gene; $phenLabel = trim( (string)$phenLabel );
            if ( in_array( $phenLabel, self::PHEN_SKIP, true ) ) { $stats['skip_phen']++; continue; }
            $phenSlug = self::phenotypeSlug( $gene, $phenLabel );
            if ( !$phenSlug ) { $stats['skip_phen']++; $unmappedPhens[ "$gene: $phenLabel" ] = ( $unmappedPhens[ "$gene: $phenLabel" ] ?? 0 ) + 1; continue; }
            $this->emitEdge( $store, $userId,
                InteractionStore::TYPE_MEDICINE, $medSlug,
                InteractionStore::TYPE_PHENOTYPE, $phenSlug,
                $phenRel, $intensity, $evidence, $mech, null,
                $dryRun, $verbose, $stats );
        }

        // --- Variant edges (HLA-style allele status) ---
        foreach ( $alleles as $gene => $status ) {
            $stats['considered']++;
            $gene = (string)$gene; $status = trim( (string)$status );
            // Negative carrier + normal dose = implicit default; skip.
            if ( stripos( $status, 'negative' ) !== false
                 && ( $rel === 'normal_dose' || $rel === 'monitor' ) ) {
                $stats['skip_neg_default']++;
                continue;
            }
            if ( stripos( $status, 'no ' ) === 0 || stripos( $status, 'no result' ) !== false ) {
                $stats['skip_phen']++;
                continue;
            }
            $varSlug = self::variantSlug( $gene, $status );
            if ( !$varSlug ) { $stats['skip_phen']++; continue; }
            // For variant edges, recode 'avoid' as 'contraindication' and
            // 'normal_dose' as 'efficacy_loss' is wrong — for variants the
            // most-appropriate vocab depends on text. Map:
            //   contraindication / avoid -> 'contraindication'
            //   any other actionable     -> 'risk_hypersensitivity' (HLA default)
            $varRel = ( $rel === 'contraindication' || $rel === 'avoid' )
                ? 'contraindication' : 'risk_hypersensitivity';
            $this->emitEdge( $store, $userId,
                InteractionStore::TYPE_MEDICINE, $medSlug,
                InteractionStore::TYPE_VARIANT, $varSlug,
                $varRel, $intensity, $evidence, $mech, null,
                $dryRun, $verbose, $stats );
        }
    }

    private function emitEdge( InteractionStore $store, int $userId,
        string $lType, string $lSlug, string $rType, string $rSlug,
        string $rel, int $intensity, string $evidence, string $mechanism,
        ?string $kinetics, bool $dryRun, bool $verbose, array &$stats ): void
    {
        $stats['emitted']++;
        $label = sprintf( "%s:%s -- %s --> %s:%s (i=%d ev=%s)",
            $lType, $lSlug, $rel, $rType, $rSlug, $intensity, $evidence );
        if ( $dryRun ) {
            if ( $verbose ) $this->output( "  (would-emit) $label\n" );
            return;
        }
        // Check for pre-existence so we can classify insert vs update.
        $pair = InteractionStore::normalizePair( $lType, $lSlug, $rType, $rSlug );
        if ( !$pair ) { $stats['errors']++; return; }
        [ $nlt, $nls, $nrt, $nrs ] = $pair;
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $pre = $dbr->selectRow( 'pcp_interactions', '*', [
            'pi_left_type' => $nlt, 'pi_left_slug' => $nls,
            'pi_right_type' => $nrt, 'pi_right_slug' => $nrs,
            'pi_relationship' => $rel,
        ], __METHOD__ );
        // Don't overwrite curated rows that aren't from CPIC.
        if ( $pre && !in_array( (string)$pre->pi_evidence, [
            'cpic_strong','cpic_moderate','cpic_optional','cpic_D','cpic_A','cpic_B','cpic_C' ], true ) ) {
            $stats['unchanged']++;
            if ( $verbose ) $this->output( "  curated-protected $label\n" );
            return;
        }
        // Evidence-strength gate: when the same 5-tuple is re-emitted with
        // WEAKER evidence than the row already in place, skip the upsert
        // entirely. Equal-strength still allowed (last writer can refresh
        // mechanism text). Stronger-than-existing always wins.
        if ( $pre ) {
            $oldRank = self::EVIDENCE_RANK[ (string)( $pre->pi_evidence ?? '' ) ] ?? 0;
            $newRank = self::EVIDENCE_RANK[ $evidence ] ?? 0;
            if ( $newRank < $oldRank ) {
                $stats['unchanged']++;
                if ( !isset( $stats['skip_weaker_evidence'] ) ) $stats['skip_weaker_evidence'] = 0;
                $stats['skip_weaker_evidence']++;
                if ( $verbose ) $this->output( "  weaker-evidence-skip $label  (existing=" . $pre->pi_evidence . ")\n" );
                return;
            }
            // Equal-strength + same intensity = pure no-op; prefer to not write.
            if ( $newRank === $oldRank && (int)( $pre->pi_intensity ?? 0 ) >= $intensity ) {
                $stats['unchanged']++;
                return;
            }
        }
        $row = $store->getOrCreate( $lType, $lSlug, $rType, $rSlug, $userId, [
            'relationship' => $rel,
            'intensity'    => $intensity,
            'evidence'     => $evidence,
            'mechanism'    => $mechanism,
            'kinetics'     => $kinetics,
            'ingestion_id' => $this->currentLogId ?? 0,
        ] );
        if ( !$row ) { $stats['errors']++; return; }
        if ( !$pre ) {
            $stats['inserted']++;
            if ( $verbose ) $this->output( "  + $label\n" );
        } else {
            $changed = false;
            foreach ( [ 'pi_intensity', 'pi_evidence', 'pi_mechanism', 'pi_kinetics' ] as $col ) {
                if ( (string)( $pre->{$col} ?? '' ) !== (string)( $row->{$col} ?? '' ) ) {
                    $changed = true; break;
                }
            }
            if ( $changed ) { $stats['updated']++;   if ( $verbose ) $this->output( "  ~ $label\n" ); }
            else            { $stats['unchanged']++; }
        }
    }

    /** First-letter-cap, spaces->underscores. Matches InteractionStore TYPE_MEDICINE rule. */
    private static function medicineSlug( string $name ): string {
        $s = trim( $name );
        if ( $s === '' ) return '';
        // CPIC names are typically lowercase ("abacavir") but some are
        // categories with capitalized phrases.
        $s = preg_replace( '/\s+/u', '_', $s );
        return mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 );
    }

    private static function phenotypeSlug( string $gene, string $label ): ?string {
        // Case-insensitive: CPIC mixes "Ultrarapid Metabolizer" (Title Case)
        // with "increased risk of aminoglycoside-induced hearing loss" (lower).
        $needle = strtolower( trim( $label ) );
        $suf = null;
        foreach ( self::PHEN_SUFFIX as $k => $v ) {
            if ( strtolower( $k ) === $needle ) { $suf = $v; break; }
        }
        if ( $suf === null ) return null;
        // Gene slug lowercase. HLA stays "hla-a" etc.
        $g = strtolower( $gene );
        $g = str_replace( ' ', '_', $g );
        return $g . '_' . $suf;
    }

    /** "HLA-B*57:01 positive" -> "hla-b_5701_pos". Return null for unparseable. */
    private static function variantSlug( string $gene, string $status ): ?string {
        // Expect "HLA-X*NN:MM (positive|negative)"
        if ( preg_match( '/\*(\d+):(\d+)\s+(positive|negative)/i', $status, $m ) ) {
            $core = $m[1] . $m[2];
            $sign = strtolower( $m[3] ) === 'positive' ? 'pos' : 'neg';
            return strtolower( $gene ) . '_' . $core . '_' . $sign;
        }
        return null;
    }

    private static function inferRelationship( string $text ): array {
        foreach ( self::REL_PATTERNS as [ $pat, $rel, $bonus ] ) {
            if ( preg_match( $pat, $text ) ) return [ $rel, $bonus ];
        }
        return [ 'monitor', 0 ];  // fallback for unrecognized text
    }
}

$maintClass = IngestCpicRecommendations::class;
require_once RUN_MAINTENANCE_IF_MAIN;
