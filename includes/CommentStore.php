<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

class CommentStore {
    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }
    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    /** Map a user id to its opaque voter hash. */
    public function voterHash( $userId ): string {
        global $wgPharmacopediaVoteHashSecret;
        if ( !$wgPharmacopediaVoteHashSecret ) {
            throw new \RuntimeException( '$wgPharmacopediaVoteHashSecret must be set' );
        }
        return hash_hmac( 'sha256', (string)$userId, $wgPharmacopediaVoteHashSecret );
    }

    public function getThread( $elementId ) {
        $res = $this->dbr()->select(
            'pcp_comments',
            [ 'c_id', 'c_element_id', 'c_voter_hash', 'c_display_name', 'c_parent_id',
              'c_text', 'c_timestamp', 'c_edited', 'c_deleted' ],
            [ 'c_element_id' => $elementId ],
            __METHOD__,
            [ 'ORDER BY' => 'c_id ASC' ]
        );
        $out = [];
        foreach ( $res as $row ) { $out[] = $row; }
        return $out;
    }

    /**
     * @param int      $elementId
     * @param int      $userId
     * @param int|null $parentId
     * @param string   $text
     * @param string|null $displayName  null = anonymous; a string = show this name publicly
     */
    public function add( $elementId, $userId, $parentId, $text, $displayName = null ) {
        $dbw = $this->dbw();
        $dbw->insert( 'pcp_comments', [
            'c_element_id'   => $elementId,
            'c_voter_hash'   => $this->voterHash( $userId ),
            'c_display_name' => $displayName,
            'c_parent_id'    => $parentId,
            'c_text'         => $text,
            'c_timestamp'    => $dbw->timestamp(),
        ], __METHOD__ );
        return $dbw->insertId();
    }

    public function edit( $commentId, $userId, $text, $isSysop = false ) {
        $dbw = $this->dbw();
        $row = $dbw->selectRow( 'pcp_comments',
            [ 'c_voter_hash', 'c_deleted' ],
            [ 'c_id' => $commentId ], __METHOD__ );
        if ( !$row ) { return false; }
        if ( !$isSysop && (string)$row->c_voter_hash !== $this->voterHash( $userId ) ) { return false; }
        if ( $row->c_deleted == 2 ) { return false; }
        $dbw->update( 'pcp_comments',
            [ 'c_text' => $text, 'c_edited' => $dbw->timestamp() ],
            [ 'c_id' => $commentId ], __METHOD__ );
        return true;
    }

    public function delete( $commentId, $userId, $isSysop = false ) {
        $dbw = $this->dbw();
        $row = $dbw->selectRow( 'pcp_comments',
            [ 'c_voter_hash', 'c_deleted' ],
            [ 'c_id' => $commentId ], __METHOD__ );
        if ( !$row ) { return false; }
        if ( !$isSysop && (string)$row->c_voter_hash !== $this->voterHash( $userId ) ) { return false; }
        $deletedFlag = $isSysop ? 2 : 1;
        $dbw->update( 'pcp_comments',
            [ 'c_deleted' => $deletedFlag, 'c_edited' => $dbw->timestamp() ],
            [ 'c_id' => $commentId ], __METHOD__ );
        return true;
    }
}
