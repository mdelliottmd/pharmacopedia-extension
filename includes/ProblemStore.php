<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

/**
 * Store for the unified Problems repository (replacing the old indications repo
 * for medicine pages — see Pharmacopedia:Citation needed adjacent context).
 *
 * A Problem is anything a medicine is used FOR — a diagnosis, a symptom,
 * a functional/life state, a lab target, etc. Distinct from a user's personal
 * diagnosis (which lives in pcp_profile_diagnoses).
 */
class ProblemStore {
    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }
    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    public function getBySlug( $slug ) {
        return $this->dbr()->selectRow( 'pcp_problem', '*',
            [ 'p_slug' => $slug ], __METHOD__ );
    }

    public function getById( $id ) {
        return $this->dbr()->selectRow( 'pcp_problem', '*',
            [ 'p_id' => (int)$id ], __METHOD__ );
    }

    /**
     * Search by name, alias, or slug substring. Returns problem rows
     * with aliases joined via subquery.
     */
    public function search( $query, $limit = 8 ) {
        $query = trim( $query );
        $dbr = $this->dbr();
        if ( $query === '' ) {
            return $dbr->select( 'pcp_problem', '*',
                [ 'p_retired' => 0 ], __METHOD__,
                [ 'ORDER BY' => 'p_name', 'LIMIT' => $limit ] );
        }
        $like = $dbr->buildLike( $dbr->anyString(), $query, $dbr->anyString() );
        // Match name/slug directly; for aliases, subquery the alias table.
        $aliasMatchSubq = $dbr->newSelectQueryBuilder()
            ->select( 'pa_problem_id' )
            ->from( 'pcp_problem_alias' )
            ->where( "pa_alias $like" )
            ->caller( __METHOD__ )
            ->getSQL();
        return $dbr->select( 'pcp_problem', '*',
            $dbr->makeList( [
                'p_retired' => 0,
                $dbr->makeList( [
                    "p_name $like",
                    "p_slug $like",
                    "p_id IN ($aliasMatchSubq)",
                ], LIST_OR ),
            ], LIST_AND ),
            __METHOD__,
            [ 'ORDER BY' => 'p_name', 'LIMIT' => $limit ]
        );
    }

    public function listAll( $offset = 0, $limit = 100, $includeRetired = false, $category = null ) {
        $cond = $includeRetired ? [] : [ 'p_retired' => 0 ];
        if ( $category !== null ) {
            $cond['p_category'] = $category;
        }
        return $this->dbr()->select( 'pcp_problem', '*', $cond, __METHOD__,
            [ 'ORDER BY' => 'p_name', 'LIMIT' => $limit, 'OFFSET' => $offset ] );
    }

    public function countAll( $includeRetired = false ) {
        $cond = $includeRetired ? [] : [ 'p_retired' => 0 ];
        return (int)$this->dbr()->selectField( 'pcp_problem', 'COUNT(*)', $cond, __METHOD__ );
    }

    public function listCategories() {
        $dbr = $this->dbr();
        $rows = $dbr->select( 'pcp_problem',
            [ 'p_category', 'n' => 'COUNT(*)' ],
            [ 'p_retired' => 0, "p_category IS NOT NULL" ],
            __METHOD__,
            [ 'GROUP BY' => 'p_category', 'ORDER BY' => 'p_category' ]
        );
        $out = [];
        foreach ( $rows as $r ) {
            $out[ (string)$r->p_category ] = (int)$r->n;
        }
        return $out;
    }

    public function getAliases( $problemId ) {
        $rows = $this->dbr()->select( 'pcp_problem_alias', 'pa_alias',
            [ 'pa_problem_id' => (int)$problemId ], __METHOD__,
            [ 'ORDER BY' => 'pa_alias' ] );
        $out = [];
        foreach ( $rows as $r ) { $out[] = (string)$r->pa_alias; }
        return $out;
    }

    /**
     * Create a new problem. $aliases may be a string (comma-separated, legacy
     * format compatible with the old indications repo) or an array of strings.
     * Returns the new problem id, or null if slug invalid, or existing id if
     * slug already exists (idempotent).
     */
    public function create( $slug, $name, $description = '', $aliases = '', $createdBy = 0, $category = null ) {
        $slug = self::normalizeSlug( $slug !== '' ? $slug : $name );
        if ( $slug === '' ) { return null; }
        $existing = $this->getBySlug( $slug );
        if ( $existing ) { return (int)$existing->p_id; }
        $dbw = $this->dbw();
        $dbw->insert( 'pcp_problem', [
            'p_slug'        => $slug,
            'p_name'        => mb_substr( $name, 0, 255 ),
            'p_description' => $description !== '' ? $description : null,
            'p_category'    => $category !== null && $category !== '' ? $category : null,
            'p_created_by'  => (int)$createdBy,
            'p_created'     => $dbw->timestamp(),
            'p_updated'     => $dbw->timestamp(),
            'p_retired'     => 0,
        ], __METHOD__ );
        $id = (int)$dbw->insertId();
        $this->setAliases( $id, $aliases );
        return $id;
    }

    public function update( $id, $fields ) {
        $allowed = [ 'p_name', 'p_description', 'p_category' ];
        $set = [];
        foreach ( $allowed as $col ) {
            if ( array_key_exists( $col, $fields ) ) {
                $set[ $col ] = $fields[ $col ];
            }
        }
        if ( $set ) {
            $set['p_updated'] = $this->dbw()->timestamp();
            $this->dbw()->update( 'pcp_problem', $set,
                [ 'p_id' => (int)$id ], __METHOD__ );
        }
        if ( array_key_exists( 'aliases', $fields ) ) {
            $this->setAliases( (int)$id, $fields['aliases'] );
        }
    }

    public function setAliases( $problemId, $aliases ) {
        if ( is_string( $aliases ) ) {
            $aliases = array_filter( array_map( 'trim', explode( ',', $aliases ) ) );
        }
        if ( !is_array( $aliases ) ) { $aliases = []; }
        $dbw = $this->dbw();
        $dbw->delete( 'pcp_problem_alias',
            [ 'pa_problem_id' => (int)$problemId ], __METHOD__ );
        foreach ( $aliases as $a ) {
            $a = mb_substr( trim( $a ), 0, 255 );
            if ( $a === '' ) { continue; }
            $dbw->insert( 'pcp_problem_alias', [
                'pa_problem_id' => (int)$problemId,
                'pa_alias'      => $a,
            ], __METHOD__ );
        }
    }

    public function retire( $id, $mergeIntoId = null ) {
        $this->dbw()->update( 'pcp_problem', [
            'p_retired'     => 1,
            'p_merged_into' => $mergeIntoId !== null ? (int)$mergeIntoId : null,
            'p_updated'     => $this->dbw()->timestamp(),
        ], [ 'p_id' => (int)$id ], __METHOD__ );
    }

    public function unretire( $id ) {
        $this->dbw()->update( 'pcp_problem', [
            'p_retired'     => 0,
            'p_merged_into' => null,
            'p_updated'     => $this->dbw()->timestamp(),
        ], [ 'p_id' => (int)$id ], __METHOD__ );
    }

    public static function normalizeSlug( $s ) {
        $s = strtolower( preg_replace( '/[^a-zA-Z0-9-]+/', '-', $s ) );
        return trim( $s, '-' );
    }

    /**
     * Resolve a slug to the canonical (non-retired) Problem row, following
     * merged_into chains up to 5 hops. Returns null if no live problem.
     */
    public function resolve( $slug ) {
        $row = $this->getBySlug( $slug );
        for ( $i = 0; $i < 5 && $row && $row->p_retired && $row->p_merged_into; $i++ ) {
            $row = $this->getById( (int)$row->p_merged_into );
        }
        return $row && !$row->p_retired ? $row : null;
    }
}
