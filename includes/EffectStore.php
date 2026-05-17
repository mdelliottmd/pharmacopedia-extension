<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

class EffectStore {
    const PERSPECTIVE_PATIENT  = 1;
    const PERSPECTIVE_PROVIDER = 2;

    /** Allowed frequency values (matches the 5 button presets). */
    const FREQUENCY_VALUES = [ -1, 0, 5, 20, 33, 50, 66, 80, 95 ];

    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }
    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    public function getAggregates( $elementId, $perspective ) {
        $db = $this->dbr();
        if ( $perspective === self::PERSPECTIVE_PATIENT ) {
            $row = $db->selectRow(
                'pcp_effect_reports',
                [
                    'n'           => 'COUNT(*)',
                    'yes'         => "SUM(CASE WHEN er_experienced = 1 THEN 1 ELSE 0 END)",
                    'no'          => "SUM(CASE WHEN er_experienced = 0 THEN 1 ELSE 0 END)",
                    'unsure'      => "SUM(CASE WHEN er_experienced = 2 THEN 1 ELSE 0 END)",
                    'valence_sum' => 'SUM(er_valence)',
                    'valence_n'   => 'SUM(CASE WHEN er_valence IS NOT NULL THEN 1 ELSE 0 END)',
                ],
                [ 'er_element_id' => $elementId, 'er_perspective' => $perspective ],
                __METHOD__
            );
            if ( !$row || (int)$row->n === 0 ) {
                return [ 'n' => 0, 'yes' => 0, 'no' => 0, 'unsure' => 0,
                         'valence_mean' => null, 'valence_n' => 0 ];
            }
            $vn = (int)$row->valence_n;
            return [
                'n'            => (int)$row->n,
                'yes'          => (int)$row->yes,
                'no'           => (int)$row->no,
                'unsure'       => (int)$row->unsure,
                'valence_mean' => $vn > 0 ? round( (float)$row->valence_sum / $vn, 2 ) : null,
                'valence_n'    => $vn,
            ];
        }
        // PROVIDER
        $row = $db->selectRow(
            'pcp_effect_reports',
            [
                'n'           => 'COUNT(*)',
                'freq_sum'    => 'SUM(CASE WHEN er_frequency_pct >= 0 THEN er_frequency_pct ELSE 0 END)',
                'freq_n'      => "SUM(CASE WHEN er_frequency_pct IS NOT NULL AND er_frequency_pct >= 0 THEN 1 ELSE 0 END)",
                'dk_n'        => "SUM(CASE WHEN er_frequency_pct = -1 THEN 1 ELSE 0 END)",
                'valence_sum' => 'SUM(er_valence)',
                'valence_n'   => 'SUM(CASE WHEN er_valence IS NOT NULL THEN 1 ELSE 0 END)',
            ],
            [ 'er_element_id' => $elementId, 'er_perspective' => $perspective ],
            __METHOD__
        );
        if ( !$row || (int)$row->n === 0 ) {
            return [ 'n' => 0, 'frequency_mean' => null, 'frequency_n' => 0, 'frequency_dk' => 0,
                     'valence_mean' => null, 'valence_n' => 0 ];
        }
        $fn = (int)$row->freq_n;
        $vn = (int)$row->valence_n;
        return [
            'n'              => (int)$row->n,
            'frequency_mean' => $fn > 0 ? (int)round( (float)$row->freq_sum / $fn ) : null,
            'frequency_n'    => $fn,
            'frequency_dk'   => (int)( $row->dk_n ?? 0 ),
            'valence_mean'   => $vn > 0 ? round( (float)$row->valence_sum / $vn, 2 ) : null,
            'valence_n'      => $vn,
        ];
    }

    public function getUserReport( $elementId, $userId, $perspective ) {
        return $this->getUserReportByHash( $elementId, $this->voterHash( $userId ), $perspective );
    }

    public function getUserReportByHash( $elementId, $voterHash, $perspective ) {
        return $this->dbr()->selectRow(
            'pcp_effect_reports', '*',
            [
                'er_element_id'  => $elementId,
                'er_voter_hash'  => $voterHash,
                'er_perspective' => $perspective,
            ],
            __METHOD__
        );
    }

    public function submitReport( $elementId, $userId, $perspective, $experienced, $frequency, $valence ) {
        $this->submitReportByHash( $elementId, $this->voterHash( $userId ), $perspective, $experienced, $frequency, $valence );
    }

    public function submitReportByHash( $elementId, $voterHash, $perspective, $experienced, $frequency, $valence ) {
        $dbw = $this->dbw();
        $existing = $dbw->selectRow(
            'pcp_effect_reports', 'er_id',
            [
                'er_element_id'  => $elementId,
                'er_voter_hash'  => $voterHash,
                'er_perspective' => $perspective,
            ],
            __METHOD__
        );
        $fields = [
            'er_experienced'   => $experienced,
            'er_frequency_pct' => $frequency,
            'er_valence'       => $valence,
            'er_timestamp'     => $dbw->timestamp(),
        ];
        if ( $existing ) {
            $dbw->update( 'pcp_effect_reports', $fields,
                [ 'er_id' => $existing->er_id ], __METHOD__ );
        } else {
            $dbw->insert( 'pcp_effect_reports', $fields + [
                'er_element_id'  => $elementId,
                'er_voter_hash'  => $voterHash,
                'er_perspective' => $perspective,
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
