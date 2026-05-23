<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

class LikertStore {
    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }
    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    /** @return array{n:int, mean:float|null, n_dontknow:int} */
    public function getAggregates( $elementId ) {
        // Mean only over actual ratings (0..5). Don't Know (NULL) abstains but is reported.
        $dbr = $this->dbr();
        $row = $dbr->selectRow( 'pcp_likert_reports',
            [
                'n_rated'    => 'SUM(CASE WHEN pl_value >= 0 THEN 1 ELSE 0 END)',
                'sum_rated'  => 'SUM(CASE WHEN pl_value >= 0 THEN pl_value ELSE 0 END)',
                'n_dontknow' => 'SUM(CASE WHEN pl_value IS NULL OR pl_value < 0 THEN 1 ELSE 0 END)',
            ],
            [ 'pl_element_id' => (int)$elementId ],
            __METHOD__
        );
        $nRated = (int)( $row->n_rated ?? 0 );
        $nDontKnow = (int)( $row->n_dontknow ?? 0 );
        $mean = $nRated > 0 ? (float)$row->sum_rated / $nRated : null;
        return [ 'n' => $nRated, 'mean' => $mean, 'n_dontknow' => $nDontKnow ];
    }

    public function getUserRating( $elementId, $userId ) {
        $row = $this->dbr()->selectRow( 'pcp_likert_reports', 'pl_value',
            [ 'pl_element_id' => (int)$elementId, 'pl_voter_hash' => $this->voterHash( $userId ) ],
            __METHOD__
        );
        if ( !$row ) {
            return null;
        }
        // A NULL pl_value is "Don't know", surfaced as -1, the token the
        // <problem> tag expects. A stored rating returns as its 0-5 value.
        return $row->pl_value === null ? -1 : (float)$row->pl_value;
    }

    /**
     * Insert or update the user's rating. Pass null to delete.
     */
    public function submitRating( $elementId, $userId, $value ) {
        $this->submitRatingByHash( $elementId, $this->voterHash( $userId ), $value );
    }

    public function submitRatingByHash( $elementId, $voterHash, $value ) {
        $dbw = $this->dbw();
        if ( $value === null ) {
            $dbw->delete( 'pcp_likert_reports',
                [ 'pl_element_id' => (int)$elementId, 'pl_voter_hash' => $voterHash ],
                __METHOD__ );
            return;
        }
        // pl_value is a 0-5 rating, or NULL for "Don't know". A negative
        // value (the -1 wire token) means Don't know and stores as NULL.
        $value = (float)$value;
        $v = $value < 0 ? null : max( 0.0, min( 5.0, $value ) );
        $existing = $dbw->selectRow( 'pcp_likert_reports', 'pl_id',
            [ 'pl_element_id' => (int)$elementId, 'pl_voter_hash' => $voterHash ],
            __METHOD__ );
        if ( $existing ) {
            $dbw->update( 'pcp_likert_reports',
                [ 'pl_value' => $v, 'pl_timestamp' => $dbw->timestamp() ],
                [ 'pl_id' => $existing->pl_id ], __METHOD__ );
        } else {
            $dbw->insert( 'pcp_likert_reports', [
                'pl_element_id' => (int)$elementId,
                'pl_voter_hash' => $voterHash,
                'pl_value'      => $v,
                'pl_timestamp'  => $dbw->timestamp(),
            ], __METHOD__ );
        }
    }

    /** Map a user id to its opaque voter hash. */
    public function voterHash( $userId ): string {
        global $wgPharmacopediaVoteHashSecret;
        if ( !$wgPharmacopediaVoteHashSecret ) {
            throw new \RuntimeException( '$wgPharmacopediaVoteHashSecret must be set in LocalSettings.php' );
        }
        return hash_hmac( 'sha256', (string)$userId, $wgPharmacopediaVoteHashSecret );
    }

}
