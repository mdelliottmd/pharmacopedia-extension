<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

/**
 * Audit-trail writer for any pcp_interactions ingestion run.
 *
 * One row per run: source (cpic_api / fda_table / derived / sandbox_seed / ...),
 * version (API timestamp or local seed-script revision), counts of rows
 * inserted vs. updated, optional freeform notes (unmapped names, error
 * counts, etc.). Lets any edge in pcp_interactions be traced back to its
 * source-of-record + when it landed.
 */
class IngestionLog {

    /**
     * Record an ingestion run. Returns the new il_id.
     *
     * @param string      $source     'cpic_api' | 'fda_table' | 'derived' |
     *                                'sandbox_seed' | 'dpwg' | etc.
     * @param string      $version    External version identifier (API
     *                                snapshot timestamp, FDA-table version,
     *                                or local seed-script revision).
     * @param int         $inserted   Rows newly inserted into pcp_interactions.
     * @param int         $updated    Rows whose metadata was updated.
     * @param string|null $notes      Freeform notes; unmapped names, error
     *                                counts, things to revisit. Stored in BLOB.
     */
    public static function record(
        string $source,
        string $version,
        int $inserted,
        int $updated,
        ?string $notes = null
    ): int {
        $dbw = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getPrimaryDatabase();
        $dbw->insert( 'pcp_ingestion_log', [
            'il_source'        => $source,
            'il_version'       => $version,
            'il_timestamp'     => $dbw->timestamp(),
            'il_rows_inserted' => $inserted,
            'il_rows_updated'  => $updated,
            'il_notes'         => $notes,
        ], __METHOD__ );
        return (int)$dbw->insertId();
    }

    /**
     * Begin an ingestion run; returns the new il_id immediately so the
     * ingest script can stamp it onto every pcp_interactions row it inserts
     * (via opts['ingestion_id'] -> pi_ingestion_id on insert). Counts are
     * placeholders (0/0); they get filled in by finishRun() at end-of-run.
     */
    public static function startRun( string $source, string $version ): int {
        return self::record( $source, $version, 0, 0, '(in progress)' );
    }

    /**
     * Finalize a run started with startRun(). Updates the existing log row
     * in place with final counts + notes. Doesn't insert a new row.
     */
    public static function finishRun( int $logId, int $inserted, int $updated, ?string $notes = null ): void {
        $dbw = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getPrimaryDatabase();
        $dbw->update( 'pcp_ingestion_log', [
            'il_rows_inserted' => $inserted,
            'il_rows_updated'  => $updated,
            'il_notes'         => $notes,
        ], [ 'il_id' => $logId ], __METHOD__ );
    }

    /** Most recent row for a given source, or null. */
    public static function latest( string $source ) {
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        return $dbr->selectRow( 'pcp_ingestion_log', '*',
            [ 'il_source' => $source ], __METHOD__,
            [ 'ORDER BY' => 'il_id DESC', 'LIMIT' => 1 ] );
    }
}
