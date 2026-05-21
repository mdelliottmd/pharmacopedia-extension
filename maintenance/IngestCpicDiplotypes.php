<?php
/**
 * Ingest CPIC's diplotype -> phenotype table into pcp_pgx_diplotype.
 *
 * Source: api.cpicpgx.org/v1/diplotype, filtered to exclude CYP2D6
 * (handled by PhenotypeResolver's activity-score compute path) and RYR1 /
 * G6PD (huge combinatorics, non-metabolizer phenotype models). ~17,775
 * diplotypes across ~15 dosing-relevant genes.
 *
 * Each row carries CPIC's authoritative generesult (the phenotype). The
 * ingest computes the wiki slug from it and the canonical lookup key
 * (PhenotypeResolver::diplotypeKey, so ingest + resolver agree on key
 * form). 'Indeterminate' results store a NULL slug; the resolver returns
 * status 'indeterminate' for those.
 *
 * The table is a complete CPIC mirror, so the ingest is wipe-and-reload:
 * delete all rows, then batch-insert fresh. Deterministic, no stale rows,
 * no upsert-conflict subtlety. Audit-logged under source='cpic_diplotype'.
 *
 * Usage:
 *   sudo -u www-data php maintenance/run.php \
 *     extensions/Pharmacopedia/maintenance/IngestCpicDiplotypes.php \
 *     --username=MDElliottMD [--dry-run]
 */
$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\Pharmacopedia\IngestionLog;
use MediaWiki\Extension\Pharmacopedia\PhenotypeResolver;

class IngestCpicDiplotypes extends Maintenance {

    private const PAGE_SIZE = 1000;
    private const API = 'https://api.cpicpgx.org/v1/diplotype'
        . '?genesymbol=not.in.(CYP2D6,RYR1,G6PD)';

    /** CPIC generesult -> wiki phenotype-slug suffix. Missing => NULL slug. */
    private const SUFFIX = [
        'Poor Metabolizer'                  => 'pm',
        'Intermediate Metabolizer'          => 'im',
        'Normal Metabolizer'                => 'nm',
        'Rapid Metabolizer'                 => 'rm',
        'Ultrarapid Metabolizer'            => 'um',
        'Possible Intermediate Metabolizer' => 'pim',
        'Likely Poor Metabolizer'           => 'lpm',
        'Likely Intermediate Metabolizer'   => 'lim',
        'Poor Function'                     => 'pf',
        'Decreased Function'                => 'df',
        'Normal Function'                   => 'nf',
        'Increased Function'                => 'if',
        'Possible Decreased Function'       => 'pdf',
        'Malignant Hyperthermia Susceptibility' => 'mh_susc',
        'Uncertain Susceptibility'          => 'uncertain',
        'increased risk of aminoglycoside-induced hearing loss' => 'risk',
        'normal risk of aminoglycoside-induced hearing loss'    => 'normal',
        'uncertain risk of aminoglycoside-induced hearing loss' => 'uncertain',
        'ivacaftor responsive in CF patients'     => 'ivacaftor_r',
        'ivacaftor non-responsive in CF patients' => 'ivacaftor_nr',
        // 'Indeterminate' intentionally absent -> NULL slug.
    ];

    public function __construct() {
        parent::__construct();
        $this->addOption( 'username', 'User to attribute rows to', true, true );
        $this->addOption( 'dry-run', 'Preview without writing', false, false );
        $this->requireExtension( 'Pharmacopedia' );
    }

    public function execute() {
        $services = MediaWikiServices::getInstance();
        $user = $services->getUserFactory()->newFromName( $this->getOption( 'username' ) );
        if ( !$user || !$user->isRegistered() ) {
            $this->fatalError( "User not found" );
        }
        $userId = (int)$user->getId();
        $dryRun = $this->hasOption( 'dry-run' );
        $http = $services->getHttpRequestFactory();
        $dbw  = $services->getConnectionProvider()->getPrimaryDatabase();

        $logId = $dryRun ? 0
            : IngestionLog::startRun( 'cpic_diplotype', 'cpic-diplotype-' . date( 'Ymd' ) );

        // Wipe-and-reload: the table is a pure CPIC mirror.
        if ( !$dryRun ) {
            $dbw->delete( 'pcp_pgx_diplotype', '*', __METHOD__ );
        }

        $stats = [ 'fetched' => 0, 'inserted' => 0, 'no_slug' => 0,
                   'dup_skipped' => 0, 'skipped' => 0 ];
        $byGene = [];
        $seen = [];
        $now = $dbw->timestamp();
        $offset = 0;

        while ( true ) {
            $url = self::API . '&limit=' . self::PAGE_SIZE . '&offset=' . $offset;
            $body = $http->get( $url, [ 'timeout' => 90 ], __METHOD__ );
            $rows = json_decode( (string)$body, true );
            if ( !is_array( $rows ) || !$rows ) break;
            $this->output( sprintf( "Fetched offset=%d count=%d\n", $offset, count( $rows ) ) );

            $batch = [];
            foreach ( $rows as $r ) {
                $stats['fetched']++;
                $gene      = strtoupper( trim( (string)( $r['genesymbol'] ?? '' ) ) );
                $diplotype = trim( (string)( $r['diplotype'] ?? '' ) );
                if ( $gene === '' || $diplotype === '' ) { $stats['skipped']++; continue; }

                $parts = explode( '/', $diplotype );
                $key = ( count( $parts ) === 2 )
                    ? PhenotypeResolver::diplotypeKey( $parts[0], $parts[1] )
                    : $diplotype;

                $seenKey = $gene . '|' . $key;
                if ( isset( $seen[$seenKey] ) ) { $stats['dup_skipped']++; continue; }
                $seen[$seenKey] = true;

                $generesult = trim( (string)( $r['generesult'] ?? '' ) );
                $slug = self::phenotypeSlug( $gene, $generesult );
                if ( $slug === null ) $stats['no_slug']++;

                $batch[] = [
                    'pd_gene'            => $gene,
                    'pd_diplotype'       => mb_substr( $diplotype, 0, 128 ),
                    'pd_diplotype_key'   => mb_substr( $key, 0, 128 ),
                    'pd_phenotype'       => $generesult !== '' ? mb_substr( $generesult, 0, 80 ) : null,
                    'pd_phenotype_slug'  => $slug,
                    'pd_activity_score'  => self::nv( $r['totalactivityscore'] ?? null, 16 ),
                    'pd_ehr_priority'    => self::nv( $r['ehrpriority'] ?? null, 64 ),
                    'pd_ingestion_id'    => $logId ?: null,
                    'pd_created_user_id' => $userId,
                    'pd_created'         => $now,
                ];
                $byGene[$gene] = ( $byGene[$gene] ?? 0 ) + 1;
            }

            if ( !$dryRun && $batch ) {
                $dbw->insert( 'pcp_pgx_diplotype', $batch, __METHOD__ );
                $stats['inserted'] += count( $batch );
            }

            $offset += self::PAGE_SIZE;
            if ( count( $rows ) < self::PAGE_SIZE ) break;
        }

        $this->output( "\nSummary:\n" );
        foreach ( $stats as $k => $v ) $this->output( sprintf( "  %-13s %d\n", $k, $v ) );
        $this->output( "\nDiplotypes per gene:\n" );
        ksort( $byGene );
        foreach ( $byGene as $g => $n ) $this->output( sprintf( "  %-12s %d\n", $g, $n ) );

        if ( !$dryRun ) {
            $note = sprintf( "CPIC diplotype mirror: %d fetched, %d inserted, "
                . "%d genes, %d Indeterminate (no slug), %d dup-key skipped.",
                $stats['fetched'], $stats['inserted'], count( $byGene ),
                $stats['no_slug'], $stats['dup_skipped'] );
            IngestionLog::finishRun( $logId, $stats['inserted'], 0, $note );
            $this->output( "\nLogged as pcp_ingestion_log.il_id=$logId\n" );
        }
    }

    /** generesult -> "<gene>_<suffix>" wiki slug, or null. */
    private static function phenotypeSlug( string $gene, string $generesult ): ?string {
        if ( $generesult === '' ) return null;
        $g = strtolower( $gene );
        if ( isset( self::SUFFIX[$generesult] ) ) {
            return $g . '_' . self::SUFFIX[$generesult];
        }
        // HLA allele-status results: "*57:01 positive" -> "<gene>_5701_pos".
        if ( preg_match( '/\*(\d+):(\d+)\s+(positive|negative)/i', $generesult, $m ) ) {
            $sign = strtolower( $m[3] ) === 'positive' ? 'pos' : 'neg';
            return $g . '_' . $m[1] . $m[2] . '_' . $sign;
        }
        return null;  // Indeterminate, or an unmapped result
    }

    private static function nv( $v, int $cap ): ?string {
        if ( $v === null ) return null;
        $s = trim( (string)$v );
        if ( $s === '' || strtolower( $s ) === 'n/a' ) return null;
        return mb_substr( $s, 0, $cap );
    }
}
$maintClass = IngestCpicDiplotypes::class;
require_once RUN_MAINTENANCE_IF_MAIN;
