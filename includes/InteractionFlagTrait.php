<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * Granular PGx interaction-voting: the flag dimensions, the store
 * methods, and validation, mixed into InteractionStore via `use`. Spec
 * by interface-claude, 2026-05-20. The methods rely on InteractionStore's
 * existing dbw() / dbr() / voterHash() helpers.
 */
trait InteractionFlagTrait {

    /**
     * The five flag dimensions: type code => [ min value, max value ].
     * A type outside this set, or a value outside its range, is
     * rejected. mechanism_flag and kinetics_flag may carry pif_note.
     */
    public const FLAG_DIMENSIONS = [
        'clinical_relevance' => [ 1, 5 ],
        'derived_confidence' => [ 1, 5 ],
        'mechanism_flag'     => [ 1, 3 ],
        'kinetics_flag'      => [ 1, 1 ],
        'noise'              => [ 1, 1 ],
    ];

    /**
     * Upsert one voter's flag on an element, keyed (element, voter,
     * type). Validates the type and the value range; returns false (no
     * write) if either is invalid.
     */
    public function submitFlag( int $elementId, $userId, string $type, int $value, ?string $note ): bool {
        if ( !isset( self::FLAG_DIMENSIONS[ $type ] ) ) {
            return false;
        }
        [ $min, $max ] = self::FLAG_DIMENSIONS[ $type ];
        if ( $value < $min || $value > $max ) {
            return false;
        }
        $hash = $this->voterHash( $userId );
        $dbw  = $this->dbw();
        $now  = $dbw->timestamp();
        $existing = $dbw->selectRow( 'pcp_interaction_flags', 'pif_id', [
            'pif_element_id' => $elementId,
            'pif_voter_hash' => $hash,
            'pif_type'       => $type,
        ], __METHOD__ );
        if ( $existing ) {
            $dbw->update( 'pcp_interaction_flags', [
                'pif_value'   => $value,
                'pif_note'    => $note,
                'pif_updated' => $now,
            ], [ 'pif_id' => $existing->pif_id ], __METHOD__ );
        } else {
            $dbw->insert( 'pcp_interaction_flags', [
                'pif_element_id' => $elementId,
                'pif_voter_hash' => $hash,
                'pif_type'       => $type,
                'pif_value'      => $value,
                'pif_note'       => $note,
                'pif_created'    => $now,
                'pif_updated'    => $now,
            ], __METHOD__ );
        }
        return true;
    }

    /** Delete one voter's flag of a given type on an element. */
    public function clearFlag( int $elementId, $userId, string $type ): bool {
        if ( !isset( self::FLAG_DIMENSIONS[ $type ] ) ) {
            return false;
        }
        $this->dbw()->delete( 'pcp_interaction_flags', [
            'pif_element_id' => $elementId,
            'pif_voter_hash' => $this->voterHash( $userId ),
            'pif_type'       => $type,
        ], __METHOD__ );
        return true;
    }

    /**
     * One voter's flags on an element:
     *   [ type => [ 'value' => int, 'note' => ?string ], ... ]
     */
    public function getUserFlags( int $elementId, $userId ): array {
        $rows = $this->dbr()->select( 'pcp_interaction_flags',
            [ 'pif_type', 'pif_value', 'pif_note' ],
            [ 'pif_element_id' => $elementId, 'pif_voter_hash' => $this->voterHash( $userId ) ],
            __METHOD__ );
        $out = [];
        foreach ( $rows as $r ) {
            $out[ (string)$r->pif_type ] = [
                'value' => (int)$r->pif_value,
                'note'  => $r->pif_note !== null ? (string)$r->pif_note : null,
            ];
        }
        return $out;
    }

    /**
     * Aggregates for one element:
     *   [ type => [ 'n' => int, 'mean' => ?float, 'dist' => [value=>count] ], ... ]
     * Thin wrapper over the batch method.
     */
    public function getFlagAggregates( int $elementId ): array {
        $batch = $this->getFlagAggregatesBatch( [ $elementId ] );
        return $batch[ $elementId ] ?? [];
    }

    /**
     * Aggregates for many elements in ONE query (the PGx render passes
     * every edge id at once, so there is no N+1):
     *   [ elementId => <getFlagAggregates shape>, ... ]
     * Every requested element gets an entry, [] if it has no flags.
     */
    public function getFlagAggregatesBatch( array $elementIds ): array {
        $ids = [];
        foreach ( $elementIds as $e ) {
            $e = (int)$e;
            if ( $e > 0 ) {
                $ids[ $e ] = true;
            }
        }
        $out = [];
        foreach ( array_keys( $ids ) as $e ) {
            $out[ $e ] = [];
        }
        if ( !$ids ) {
            return $out;
        }
        $rows = $this->dbr()->select( 'pcp_interaction_flags',
            [ 'pif_element_id', 'pif_type', 'pif_value', 'c' => 'COUNT(*)' ],
            [ 'pif_element_id' => array_keys( $ids ) ],
            __METHOD__,
            [ 'GROUP BY' => [ 'pif_element_id', 'pif_type', 'pif_value' ] ]
        );
        $acc = [];
        foreach ( $rows as $r ) {
            $e = (int)$r->pif_element_id;
            $t = (string)$r->pif_type;
            $v = (int)$r->pif_value;
            $c = (int)$r->c;
            if ( !isset( $acc[ $e ][ $t ] ) ) {
                $acc[ $e ][ $t ] = [ 'n' => 0, 'sum' => 0, 'dist' => [] ];
            }
            $acc[ $e ][ $t ]['n']   += $c;
            $acc[ $e ][ $t ]['sum'] += $v * $c;
            $acc[ $e ][ $t ]['dist'][ $v ] = $c;
        }
        foreach ( $acc as $e => $byType ) {
            foreach ( $byType as $t => $a ) {
                $n = (int)$a['n'];
                $out[ $e ][ $t ] = [
                    'n'    => $n,
                    'mean' => $n > 0 ? round( $a['sum'] / $n, 2 ) : null,
                    'dist' => $a['dist'],
                ];
            }
        }
        return $out;
    }
}
