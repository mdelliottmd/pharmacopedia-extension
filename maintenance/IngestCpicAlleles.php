<?php
/**
 * Ingest the CPIC star-allele catalog into pcp_pgx_allele.
 *
 * Source: https://api.cpicpgx.org/v1/allele  (~1349 alleles, 22 genes).
 *
 * This is the genotype-anchor layer. The wiki's phenotype system
 * (phenotype slugs, medicine<->phenotype edges, the spectrum widget) had
 * no connection to actual genetics: a clinician with "CYP2D6 *1/*4"
 * couldn't get from that genotype to a phenotype. The allele catalog
 * closes that: each allele carries a clinical function + activity value,
 * so a diplotype resolves by summing activity values and mapping the
 * total to a phenotype band.
 *
 * Bonus data captured: per-population allele frequency (stored as JSON in
 * pa_frequency) and the CPIC function-assignment rationale (pa_findings) --
 * both useful content for the eventual Enzyme:/Phenotype: pages.
 *
 * Idempotent: upsert keyed on (gene, allele). Audit-logged under
 * source='cpic_allele'.
 *
 * Usage:
 *   sudo -u www-data php maintenance/run.php \
 *     extensions/Pharmacopedia/maintenance/IngestCpicAlleles.php \
 *     --username=MDElliottMD [--dry-run] [--verbose]
 */
$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\Pharmacopedia\IngestionLog;

class IngestCpicAlleles extends Maintenance {

    private const PAGE_SIZE = 500;

    public function __construct() {
        parent::__construct();
        $this->addOption( 'username', 'User to attribute rows to', true, true );
        $this->addOption( 'dry-run', 'Preview without writing', false, false );
        $this->addOption( 'verbose', 'Print every allele', false, false );
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
        $http = $services->getHttpRequestFactory();
        $dbw  = $services->getConnectionProvider()->getPrimaryDatabase();
        $dbr  = $services->getConnectionProvider()->getReplicaDatabase();

        $logId = $dryRun ? 0
            : IngestionLog::startRun( 'cpic_allele', 'cpic-allele-' . date( 'Ymd' ) );

        $stats = [
            'fetched' => 0, 'inserted' => 0, 'updated' => 0,
            'unchanged' => 0, 'skipped' => 0, 'errors' => 0,
        ];
        $byGene = [];

        $offset = 0;
        while ( true ) {
            $url = sprintf( 'https://api.cpicpgx.org/v1/allele?limit=%d&offset=%d',
                self::PAGE_SIZE, $offset );
            $body = $http->get( $url, [ 'timeout' => 60 ], __METHOD__ );
            $rows = json_decode( (string)$body, true );
            if ( !is_array( $rows ) || !$rows ) break;
            $this->output( sprintf( "Fetched offset=%d count=%d\n", $offset, count( $rows ) ) );
            foreach ( $rows as $a ) {
                $stats['fetched']++;
                $gene   = strtoupper( trim( (string)( $a['genesymbol'] ?? '' ) ) );
                $allele = trim( (string)( $a['name'] ?? '' ) );
                if ( $gene === '' || $allele === '' ) { $stats['skipped']++; continue; }
                $byGene[$gene] = ( $byGene[$gene] ?? 0 ) + 1;

                $fields = [
                    'pa_function'       => self::nv( $a['clinicalfunctionalstatus'] ?? null ),
                    'pa_activity_value' => self::nv( $a['activityvalue'] ?? null, 16 ),
                    'pa_strength'       => self::nv( $a['strength'] ?? null, 16 ),
                    'pa_cpic_allele_id' => isset( $a['id'] ) ? (int)$a['id'] : null,
                    'pa_frequency'      => isset( $a['frequency'] ) && is_array( $a['frequency'] )
                        ? json_encode( $a['frequency'] ) : null,
                    'pa_findings'       => self::nv( $a['findings'] ?? null, 0 ),
                ];

                if ( $dryRun ) {
                    if ( $verbose ) $this->output( sprintf( "  would-upsert %s %s (%s)\n",
                        $gene, $allele, $fields['pa_function'] ?? '?' ) );
                    continue;
                }

                $pre = $dbr->selectRow( 'pcp_pgx_allele', '*',
                    [ 'pa_gene' => $gene, 'pa_allele' => $allele ], __METHOD__ );
                if ( !$pre ) {
                    $dbw->insert( 'pcp_pgx_allele', $fields + [
                        'pa_gene'            => $gene,
                        'pa_allele'          => $allele,
                        'pa_ingestion_id'    => $logId,
                        'pa_created_user_id' => $userId,
                        'pa_created'         => $dbw->timestamp(),
                    ], __METHOD__ );
                    $stats['inserted']++;
                    if ( $verbose ) $this->output( "  + $gene $allele\n" );
                } else {
                    $changed = false;
                    foreach ( [ 'pa_function', 'pa_activity_value', 'pa_strength',
                                'pa_frequency', 'pa_findings' ] as $c ) {
                        if ( (string)( $pre->{$c} ?? '' ) !== (string)( $fields[$c] ?? '' ) ) {
                            $changed = true; break;
                        }
                    }
                    if ( $changed ) {
                        $dbw->update( 'pcp_pgx_allele', $fields,
                            [ 'pa_id' => (int)$pre->pa_id ], __METHOD__ );
                        $stats['updated']++;
                        if ( $verbose ) $this->output( "  ~ $gene $allele\n" );
                    } else {
                        $stats['unchanged']++;
                    }
                }
            }
            $offset += self::PAGE_SIZE;
            if ( count( $rows ) < self::PAGE_SIZE ) break;
        }

        $this->output( "\nSummary:\n" );
        foreach ( $stats as $k => $v ) $this->output( sprintf( "  %-12s %d\n", $k, $v ) );
        $this->output( "\nAlleles per gene:\n" );
        ksort( $byGene );
        foreach ( $byGene as $g => $n ) $this->output( sprintf( "  %-12s %d\n", $g, $n ) );

        if ( !$dryRun ) {
            $note = sprintf( "CPIC allele catalog: %d fetched, %d inserted, "
                . "%d updated, %d unchanged, %d genes.",
                $stats['fetched'], $stats['inserted'], $stats['updated'],
                $stats['unchanged'], count( $byGene ) );
            IngestionLog::finishRun( $logId, $stats['inserted'], $stats['updated'], $note );
            $this->output( "\nLogged as pcp_ingestion_log.il_id=$logId\n" );
        }
    }

    /** Normalize a nullable string field; optional byte cap (0 = no cap). */
    private static function nv( $v, int $cap = 64 ): ?string {
        if ( $v === null ) return null;
        $s = trim( (string)$v );
        if ( $s === '' ) return null;
        return $cap > 0 ? mb_substr( $s, 0, $cap ) : $s;
    }
}
$maintClass = IngestCpicAlleles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
