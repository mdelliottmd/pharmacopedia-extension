<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

class GlobalEffectStore {
    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }
    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    public function getBySlug( $slug ) {
        return $this->dbr()->selectRow( 'pcp_effects', '*',
            [ 'e_slug' => $slug ], __METHOD__ );
    }

    public function getById( $id ) {
        return $this->dbr()->selectRow( 'pcp_effects', '*',
            [ 'e_id' => (int)$id ], __METHOD__ );
    }

    /**
     * Type-ahead search across name + aliases. Active (non-retired) only.
     */
    public function search( $query, $limit = 8 ) {
        $query = trim( $query );
        if ( $query === '' ) {
            return $this->dbr()->select( 'pcp_effects', '*',
                [ 'e_retired' => 0 ], __METHOD__,
                [ 'ORDER BY' => 'e_name', 'LIMIT' => $limit ] );
        }
        $dbr = $this->dbr();
        $like = $dbr->buildLike( $dbr->anyString(), $query, $dbr->anyString() );
        return $dbr->select( 'pcp_effects', '*',
            $dbr->makeList( [
                'e_retired' => 0,
                $dbr->makeList( [
                    "e_name $like",
                    "e_aliases $like",
                    "e_slug $like",
                ], LIST_OR ),
            ], LIST_AND ),
            __METHOD__,
            [ 'ORDER BY' => 'e_name', 'LIMIT' => $limit ]
        );
    }

    public function listAll( $offset = 0, $limit = 100, $includeRetired = false ) {
        $cond = $includeRetired ? [] : [ 'e_retired' => 0 ];
        return $this->dbr()->select( 'pcp_effects', '*', $cond, __METHOD__,
            [ 'ORDER BY' => 'e_name', 'LIMIT' => $limit, 'OFFSET' => $offset ] );
    }

    public function countAll( $includeRetired = false ) {
        $cond = $includeRetired ? [] : [ 'e_retired' => 0 ];
        return (int)$this->dbr()->selectField( 'pcp_effects', 'COUNT(*)', $cond, __METHOD__ );
    }

    /**
     * Returns a new effect's e_id (or null on slug collision).
     */
    public function create( $slug, $name, $description, $aliases, $createdBy ) {
        $slug = self::normalizeSlug( $slug !== '' ? $slug : $name );
        if ( $slug === '' ) { return null; }
        // If slug already exists, return existing row's id (caller can decide)
        $existing = $this->getBySlug( $slug );
        if ( $existing ) { return (int)$existing->e_id; }

        $dbw = $this->dbw();
        $dbw->insert( 'pcp_effects', [
            'e_slug'        => $slug,
            'e_name'        => mb_substr( $name, 0, 255 ),
            'e_description' => $description !== '' ? $description : null,
            'e_aliases'     => $aliases !== '' ? $aliases : null,
            'e_created_by'  => (int)$createdBy,
            'e_created'     => $dbw->timestamp(),
            'e_updated'     => $dbw->timestamp(),
            'e_retired'     => 0,
        ], __METHOD__ );
        return $dbw->insertId();
    }

    public function update( $id, $fields ) {
        $allowed = [ 'e_name', 'e_description', 'e_aliases' ];
        $set = [];
        foreach ( $allowed as $col ) {
            if ( array_key_exists( $col, $fields ) ) {
                $set[ $col ] = $fields[ $col ];
            }
        }
        if ( !$set ) { return; }
        $set['e_updated'] = $this->dbw()->timestamp();
        $this->dbw()->update( 'pcp_effects', $set,
            [ 'e_id' => (int)$id ], __METHOD__ );
    }

    public function retire( $id, $mergeIntoId = null ) {
        $this->dbw()->update( 'pcp_effects', [
            'e_retired'     => 1,
            'e_merged_into' => $mergeIntoId !== null ? (int)$mergeIntoId : null,
            'e_updated'     => $this->dbw()->timestamp(),
        ], [ 'e_id' => (int)$id ], __METHOD__ );
    }

    public function unretire( $id ) {
        $this->dbw()->update( 'pcp_effects', [
            'e_retired'     => 0,
            'e_merged_into' => null,
            'e_updated'     => $this->dbw()->timestamp(),
        ], [ 'e_id' => (int)$id ], __METHOD__ );
    }

    public static function normalizeSlug( $s ) {
        $s = strtolower( preg_replace( '/[^a-zA-Z0-9-]+/', '-', $s ) );
        return trim( $s, '-' );
    }

    /**
     * Resolve a ref slug to the canonical (un-retired, follow merges) effect.
     * Returns the resolved row, or null if not found.
     */
    public function resolve( $slug ) {
        $row = $this->getBySlug( $slug );
        for ( $i = 0; $i < 5 && $row && $row->e_retired && $row->e_merged_into; $i++ ) {
            $row = $this->getById( (int)$row->e_merged_into );
        }
        return $row && !$row->e_retired ? $row : null;
    }
}
