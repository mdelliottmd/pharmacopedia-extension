<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

class ElementStore {
    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }
    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    public function getOrCreate( $pageId, $slug, $type, $label ) {
        $dbw = $this->dbw();
        $row = $dbw->selectRow(
            'pcp_votable_elements', '*',
            [ 've_page_id' => $pageId, 've_slug' => $slug ],
            __METHOD__
        );
        if ( $row ) return $row;

        $dbw->insert( 'pcp_votable_elements', [
            've_page_id' => $pageId,
            've_slug'    => $slug,
            've_type'    => $type,
            've_label'   => mb_substr( (string)$label, 0, 500 ),
            've_upvotes' => 0,
            've_downvotes' => 0,
            've_created' => $dbw->timestamp(),
        ], __METHOD__, [ 'IGNORE' ] );

        return $dbw->selectRow(
            'pcp_votable_elements', '*',
            [ 've_page_id' => $pageId, 've_slug' => $slug ],
            __METHOD__
        );
    }

    public function getById( $id ) {
        return $this->dbr()->selectRow( 'pcp_votable_elements', '*', [ 've_id' => $id ], __METHOD__ );
    }

    public function getUserVote( $elementId, $userId ) {
        $hash = $this->voterHash( $userId );
        $row = $this->dbr()->selectRow( 'pcp_votes', 'v_value',
            [ 'v_element_id' => $elementId, 'v_voter_hash' => $hash ], __METHOD__ );
        return $row ? (int)$row->v_value : 0;
    }

    public function castVote( $elementId, $userId, $value ) {
        $hash = $this->voterHash( $userId );
        $dbw = $this->dbw();
        $dbw->startAtomic( __METHOD__ );
        try {
            $existing = $dbw->selectRow( 'pcp_votes', 'v_value',
                [ 'v_element_id' => $elementId, 'v_voter_hash' => $hash ],
                __METHOD__, [ 'FOR UPDATE' ] );

            if ( $value === 0 ) {
                if ( $existing ) {
                    $dbw->delete( 'pcp_votes',
                        [ 'v_element_id' => $elementId, 'v_voter_hash' => $hash ], __METHOD__ );
                    $this->adjustCount( $elementId, (int)$existing->v_value, -1 );
                }
            } elseif ( $existing ) {
                if ( (int)$existing->v_value !== $value ) {
                    $dbw->update( 'pcp_votes',
                        [ 'v_value' => $value, 'v_timestamp' => $dbw->timestamp() ],
                        [ 'v_element_id' => $elementId, 'v_voter_hash' => $hash ], __METHOD__ );
                    $this->adjustCount( $elementId, (int)$existing->v_value, -1 );
                    $this->adjustCount( $elementId, $value, +1 );
                }
            } else {
                $dbw->insert( 'pcp_votes', [
                    'v_element_id'   => $elementId,
                    'v_voter_hash'   => $hash,
                    'v_value'        => $value,
                    'v_timestamp'    => $dbw->timestamp(),
                ], __METHOD__ );
                $this->adjustCount( $elementId, $value, +1 );
            }
            $dbw->endAtomic( __METHOD__ );
        } catch ( \Throwable $e ) {
            $dbw->cancelAtomic( __METHOD__ );
            throw $e;
        }
        return $this->getById( $elementId );
    }

    private function adjustCount( $elementId, $voteValue, $delta ) {
        $dbw = $this->dbw();
        $col = $voteValue > 0 ? 've_upvotes' : 've_downvotes';
        $dbw->query(
            "UPDATE " . $dbw->tableName( 'pcp_votable_elements' ) .
            " SET $col = $col + " . (int)$delta . " WHERE ve_id = " . (int)$elementId,
            __METHOD__
        );
    }

    /**
     * Compute the opaque voter hash for a user.
     * Vote rows store this hash instead of user_id so admins reading the DB
     * cannot map votes back to identities without the HMAC secret.
     */
    public function voterHash( $userId ): string {
        global $wgPharmacopediaVoteHashSecret;
        if ( !$wgPharmacopediaVoteHashSecret ) {
            throw new \RuntimeException( '$wgPharmacopediaVoteHashSecret must be configured in LocalSettings.php' );
        }
        return hash_hmac( 'sha256', (string)$userId, $wgPharmacopediaVoteHashSecret );
    }

}
