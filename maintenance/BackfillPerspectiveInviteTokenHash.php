<?php
/**
 * Backfill maintenance script:
 *   pcp_perspective_invite.pvi_token_hash <- SHA-256(pvi_token)
 *
 * M3 follow-up from server-claude's 2026-05-21 audit.
 *
 * Idempotent: only processes rows WHERE pvi_token_hash IS NULL.
 * Batches of 500 reads, single-row UPDATE per row (so a partial
 * crash leaves a consistent partial state).
 *
 * --dry-run prints what would happen without writing.
 *
 * Once code reads-by-hash and this backfill completes on prod,
 * 0.9.8.8 drops pvi_token + the cleartext-fallback read path.
 */

$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\Extension\Pharmacopedia\Assessments\AdminCrypto;
use MediaWiki\MediaWikiServices;

class BackfillPerspectiveInviteTokenHash extends \Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addDescription(
            'Backfill pcp_perspective_invite.pvi_token_hash = sha256(pvi_token) for rows where the hash is NULL.'
        );
        $this->addOption( 'dry-run',
            'Report what would be updated without writing.', false, false );
        $this->addOption( 'batch-size',
            'Rows per read batch (default 500).', false, true );
    }

    public function execute() {
        $dryRun    = $this->hasOption( 'dry-run' );
        $batchSize = (int)( $this->getOption( 'batch-size', 500 ) );
        if ( $batchSize < 1 || $batchSize > 5000 ) {
            $this->fatalError( "batch-size out of range" );
        }

        $lb  = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $lb->getConnection( DB_PRIMARY );

        $total = (int)$dbw->newSelectQueryBuilder()
            ->select( 'COUNT(*)' )
            ->from( 'pcp_perspective_invite' )
            ->where( [ 'pvi_token_hash' => null ] )
            ->caller( __METHOD__ )
            ->fetchField();

        $this->output( "Rows pending backfill: $total\n" );
        if ( $total === 0 ) {
            $this->output( "Nothing to do.\n" );
            return;
        }
        if ( $dryRun ) {
            $this->output( "DRY RUN: no writes will occur.\n" );
        }

        $updated = 0;
        $errors  = 0;
        $lastId  = 0;

        while ( true ) {
            $rows = $dbw->newSelectQueryBuilder()
                ->select( [ 'pvi_id', 'pvi_token' ] )
                ->from( 'pcp_perspective_invite' )
                ->where( [
                    'pvi_token_hash' => null,
                    $dbw->expr( 'pvi_id', '>', $lastId ),
                ] )
                ->orderBy( 'pvi_id', 'ASC' )
                ->limit( $batchSize )
                ->caller( __METHOD__ )
                ->fetchResultSet();

            if ( $rows->numRows() === 0 ) {
                break;
            }

            foreach ( $rows as $row ) {
                $lastId = (int)$row->pvi_id;
                $raw    = (string)$row->pvi_token;
                if ( $raw === '' ) {
                    $this->output( "  pvi_id={$row->pvi_id}: empty pvi_token, skipped\n" );
                    $errors++;
                    continue;
                }
                $hash = AdminCrypto::hashInviteToken( $raw );
                if ( !$dryRun ) {
                    $dbw->newUpdateQueryBuilder()
                        ->update( 'pcp_perspective_invite' )
                        ->set( [ 'pvi_token_hash' => $hash ] )
                        ->where( [
                            'pvi_id'         => $row->pvi_id,
                            'pvi_token_hash' => null,
                        ] )
                        ->caller( __METHOD__ )
                        ->execute();
                }
                $updated++;
            }

            $this->output( "  Processed batch ending at pvi_id=$lastId; running total updated=$updated\n" );

            // Avoid hammering the primary on a one-shot script; small breath.
            if ( !$dryRun && $rows->numRows() === $batchSize ) {
                $lb->waitForReplication();
            }
        }

        $verb = $dryRun ? 'Would update' : 'Updated';
        $this->output( "Done. $verb: $updated · Errors: $errors\n" );
    }
}

$maintClass = BackfillPerspectiveInviteTokenHash::class;
require_once "$IP/maintenance/doMaintenance.php";
