<?php
/**
 * FormalTestStore
 *
 * Read/write the formal-testing catalog + user score entries.
 * Pure DB layer; no auth checks (callers do that).
 *
 * @license GPL-3.0-or-later
 */

namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

class FormalTestStore {

    /**
     * Return the full catalog of standardized tests, ordered by category + sort_key.
     * @return array list of stdClass rows
     */
    public function getCatalog(): array {
        $db = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $res = $db->newSelectQueryBuilder()
            ->select( [ 'ft_id', 'ft_abbrev', 'ft_full_name', 'ft_category',
                        'ft_score_min', 'ft_score_max', 'ft_score_format',
                        'ft_percentile_available', 'ft_sort_key', 'ft_legacy',
                        'ft_aka', 'ft_notes' ] )
            ->from( 'pcp_formal_tests' )
            ->orderBy( [ 'ft_category', 'ft_sort_key' ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $res as $row ) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Look up a single test by ID.
     */
    public function getById( int $ftId ) {
        if ( $ftId <= 0 ) return null;
        $db = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        return $db->newSelectQueryBuilder()
            ->select( '*' )
            ->from( 'pcp_formal_tests' )
            ->where( [ 'ft_id' => $ftId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
    }

    /**
     * Return all of a user's score entries, joined with catalog data when present.
     * Custom entries (uts_test_id NULL) carry their own custom_abbrev/custom_name.
     * @param int $profId pcp_user_profiles.prof_id
     * @return array
     */
    public function getUserScores( int $profId, int $minVis = 0 ): array {
        if ( $profId <= 0 ) return [];
        $db = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $res = $db->newSelectQueryBuilder()
            ->select( [
                'uts_id', 'uts_test_id', 'uts_custom_abbrev', 'uts_custom_name',
                'uts_raw_score', 'uts_raw_is_estimate', 'uts_scaled_score', 'uts_percentile', 'uts_pct_is_estimate',
                'uts_year_taken', 'uts_pass_fail', 'uts_notes', 'uts_vis',
                'uts_vis_raw', 'uts_vis_pct', 'uts_vis_passfail',
                'uts_created_at', 'uts_updated_at',
                'ft_abbrev', 'ft_full_name', 'ft_category',
                'ft_score_min', 'ft_score_max', 'ft_score_format',
                'ft_percentile_available', 'ft_legacy'
            ] )
            ->from( 'pcp_user_test_scores' )
            ->leftJoin( 'pcp_formal_tests', null, 'uts_test_id=ft_id' )
            ->where( $minVis > 0
                ? [ 'uts_prof_id' => $profId, 'uts_vis != ' . $db->addQuotes( '0' ) ]
                : [ 'uts_prof_id' => $profId ] )
            ->orderBy( [ 'uts_year_taken DESC', 'uts_id DESC' ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $res as $row ) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Return a single score entry by id (with catalog data).
     */
    public function getScoreById( int $utsId ) {
        if ( $utsId <= 0 ) return null;
        $db = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        return $db->newSelectQueryBuilder()
            ->select( '*' )
            ->from( 'pcp_user_test_scores' )
            ->where( [ 'uts_id' => $utsId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
    }

    /**
     * Insert a new score entry. Returns the new uts_id or 0 on validation failure.
     * Caller is responsible for auth (prof_id ownership check).
     * @param int $profId pcp_user_profiles.prof_id
     * @param array $payload validated payload (see normalizePayload)
     */
    public function addScore( int $profId, array $payload ): int {
        if ( $profId <= 0 ) return 0;
        $now = wfTimestamp( TS_MW );
        $row = $this->normalizePayload( $payload ) + [
            'uts_prof_id'    => $profId,
            'uts_created_at' => $now,
            'uts_updated_at' => $now,
        ];
        $db = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
        $db->newInsertQueryBuilder()
            ->insertInto( 'pcp_user_test_scores' )
            ->row( $row )
            ->caller( __METHOD__ )
            ->execute();
        return (int)$db->insertId();
    }

    /**
     * Update an existing score. Ownership check happens here.
     * @return bool true on success, false if not found or wrong owner.
     */
    public function updateScore( int $utsId, int $profId, array $payload ): bool {
        if ( $utsId <= 0 || $profId <= 0 ) return false;
        $existing = $this->getScoreById( $utsId );
        if ( !$existing || (int)$existing->uts_prof_id !== $profId ) return false;
        $row = $this->normalizePayload( $payload ) + [
            'uts_updated_at' => wfTimestamp( TS_MW ),
        ];
        $db = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
        $db->newUpdateQueryBuilder()
            ->update( 'pcp_user_test_scores' )
            ->set( $row )
            ->where( [ 'uts_id' => $utsId, 'uts_prof_id' => $profId ] )
            ->caller( __METHOD__ )
            ->execute();
        return true;
    }

    /**
     * Delete a score. Ownership check happens here.
     */
    public function deleteScore( int $utsId, int $profId ): bool {
        if ( $utsId <= 0 || $profId <= 0 ) return false;
        $db = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
        $db->newDeleteQueryBuilder()
            ->deleteFrom( 'pcp_user_test_scores' )
            ->where( [ 'uts_id' => $utsId, 'uts_prof_id' => $profId ] )
            ->caller( __METHOD__ )
            ->execute();
        return $db->affectedRows() > 0;
    }

    /**
     * Normalize an inbound payload into a DB row. Strips nulls/empties to NULL,
     * casts numeric types, clamps strings. Either test_id OR custom_name must be set.
     */
    private function normalizePayload( array $p ): array {
        $testId       = isset( $p['test_id'] ) && (int)$p['test_id'] > 0 ? (int)$p['test_id'] : null;
        $customAbbrev = $testId === null ? $this->str( $p['custom_abbrev'] ?? '', 40 )  : null;
        $customName   = $testId === null ? $this->str( $p['custom_name']   ?? '', 255 ) : null;
        $raw          = $this->floatOrNull( $p['raw_score']    ?? null );
        $scaled       = $this->floatOrNull( $p['scaled_score'] ?? null );
        $pct          = $this->floatOrNull( $p['percentile']   ?? null );
        $year         = $this->intOrNull( $p['year_taken'] ?? null );
        if ( $year !== null && ( $year < 1900 || $year > (int)date( 'Y' ) + 1 ) ) {
            $year = null;
        }
        $rawEst = isset( $p['raw_is_estimate'] ) && (int)$p['raw_is_estimate'] === 1 ? 1 : 0;
        $pctEst = isset( $p['pct_is_estimate'] ) && (int)$p['pct_is_estimate'] === 1 ? 1 : 0;
        $pf = isset( $p['pass_fail'] ) && $p['pass_fail'] !== '' && $p['pass_fail'] !== null
            ? (int)( (bool)( (int)$p['pass_fail'] ) )
            : null;
        $notes = $this->str( $p['notes'] ?? '', 2000 );
        $clampVis = static function ( $v ) {
            $i = is_numeric( $v ) ? (int)$v : 0;
            return ( $i < 0 || $i > 3 ) ? 0 : $i;
        };
        $visFallback = $p['vis'] ?? 0;
        $visRaw = $clampVis( $p['vis_raw']      ?? $visFallback );
        $visPct = $clampVis( $p['vis_pct']      ?? $visFallback );
        $visPf  = $clampVis( $p['vis_passfail'] ?? $visFallback );

        return [
            'uts_test_id'       => $testId,
            'uts_custom_abbrev' => $customAbbrev !== '' ? $customAbbrev : null,
            'uts_custom_name'   => $customName !== '' ? $customName : null,
            'uts_raw_score'     => $raw,
            'uts_raw_is_estimate' => $rawEst,
            'uts_scaled_score'  => $scaled,
            'uts_percentile'    => $pct,
            'uts_pct_is_estimate' => $pctEst,
            'uts_year_taken'    => $year,
            'uts_pass_fail'     => $pf,
            'uts_notes'         => $notes !== '' ? $notes : null,
            'uts_vis'           => (string)max( $visRaw, $visPct, $visPf ),
            'uts_vis_raw'       => $visRaw,
            'uts_vis_pct'       => $visPct,
            'uts_vis_passfail'  => $visPf,
        ];
    }

    private function floatOrNull( $v ) {
        if ( $v === null || $v === '' || $v === false ) return null;
        if ( !is_numeric( $v ) ) return null;
        return (float)$v;
    }
    private function intOrNull( $v ) {
        if ( $v === null || $v === '' || $v === false ) return null;
        if ( !is_numeric( $v ) ) return null;
        return (int)$v;
    }
    private function str( $v, int $max ): string {
        $s = trim( (string)$v );
        if ( strlen( $s ) > $max ) $s = substr( $s, 0, $max );
        return $s;
    }
}
