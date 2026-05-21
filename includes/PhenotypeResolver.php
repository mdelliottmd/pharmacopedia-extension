<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

/**
 * Resolve a diplotype (a pair of star alleles) to a pharmacogenomic
 * phenotype. The callable layer on top of the genotype-anchor data: a
 * clinician or UI passes "CYP2D6 *1/*4" and gets back the phenotype slug
 * the rest of the PGx subsystem keys on.
 *
 * Two resolution paths:
 *
 *   ACTIVITY-SCORE COMPUTE  - CYP2D6 only. The two alleles' activity
 *     values (from pcp_pgx_allele) sum to a total, mapped to a phenotype
 *     band by the canonical 2019-consensus thresholds. CYP2D6's bands are
 *     unambiguous and it is the most clinically queried gene.
 *
 *   DIPLOTYPE LOOKUP  - every other dosing-relevant gene. CPIC's
 *     /diplotype endpoint pre-computed the phenotype (generesult) for
 *     every diplotype; pcp_pgx_diplotype mirrors it. The lookup uses
 *     CPIC's authoritative call, so function-combination genes (CYP2C19,
 *     TPMT, NUDT15) resolve correctly without reimplementing CPIC's
 *     per-gene rules.
 *
 * RYR1 and G6PD are deliberately unmodeled (huge combinatorics, and
 * non-metabolizer phenotype models); they return a structured
 * 'unresolved'.
 *
 * resolve() returns an array, never throws:
 *   status          'resolved' | 'indeterminate' | 'unresolved'
 *                   | 'unknown_allele' | 'unknown_diplotype'
 *   gene            normalized gene symbol
 *   phenotype_slug  e.g. 'cyp2d6_im' / 'cyp2c19_pm'  (null unless resolved)
 *   phenotype_label human-readable phenotype (null unless resolved)
 *   activity_score  float total (null when not an activity-score result)
 *   detail          human-readable explanation
 */
class PhenotypeResolver {

    /**
     * Activity-score genes: ordered phenotype bands [ suffix, label,
     * upperInclusive ]; walked low-to-high, first band the score does not
     * exceed wins, open-ended band catches the top.
     */
    private const ACTIVITY_BANDS = [
        'CYP2D6' => [
            [ 'pm', 'Poor Metabolizer',          0.0  ],
            [ 'im', 'Intermediate Metabolizer',  1.25 ],
            [ 'nm', 'Normal Metabolizer',        2.25 ],
            [ 'um', 'Ultrarapid Metabolizer',    INF  ],
        ],
    ];

    /** Genes with no resolver model (combinatorial / non-metabolizer). */
    private const LOOKUP_SKIP = [ 'RYR1', 'G6PD' ];

    /**
     * Resolve a (gene, alleleA, alleleB) diplotype to a phenotype.
     */
    public static function resolve( string $gene, string $alleleA, string $alleleB ): array {
        $gene = strtoupper( trim( $gene ) );
        if ( isset( self::ACTIVITY_BANDS[$gene] ) ) {
            return self::resolveActivityScore( $gene, $alleleA, $alleleB );
        }
        if ( in_array( $gene, self::LOOKUP_SKIP, true ) ) {
            return self::base( $gene, 'unresolved',
                "$gene is not modeled by the resolver (combinatorial / "
                . "non-metabolizer phenotype model)." );
        }
        return self::resolveByLookup( $gene, $alleleA, $alleleB );
    }

    /** CYP2D6 activity-score path: sum allele activity values, band-map. */
    private static function resolveActivityScore( string $gene, string $aA, string $aB ): array {
        $aA = self::normalizeAllele( $aA );
        $aB = self::normalizeAllele( $aB );
        $vA = self::activityValue( $gene, $aA );
        $vB = self::activityValue( $gene, $aB );
        if ( $vA === null || $vB === null ) {
            $missing = [];
            if ( $vA === null ) $missing[] = $aA;
            if ( $vB === null ) $missing[] = $aB;
            $r = self::base( $gene, 'unknown_allele',
                "No catalog activity value for $gene allele(s): "
                . implode( ', ', $missing ) . "." );
            return $r;
        }
        $score = $vA + $vB;
        $suffix = null; $label = null;
        foreach ( self::ACTIVITY_BANDS[$gene] as [ $sfx, $lbl, $upper ] ) {
            if ( $score <= $upper ) { $suffix = $sfx; $label = $lbl; break; }
        }
        $r = self::base( $gene, 'resolved', sprintf(
            '%s + %s activity %s + %s = %s -> %s.',
            $aA, $aB, self::fmt( $vA ), self::fmt( $vB ), self::fmt( $score ), $label ) );
        $r['phenotype_slug']  = strtolower( $gene ) . '_' . $suffix;
        $r['phenotype_label'] = $label;
        $r['activity_score']  = $score;
        return $r;
    }

    /** Lookup path: pcp_pgx_diplotype keyed on the canonical diplotype key. */
    private static function resolveByLookup( string $gene, string $aA, string $aB ): array {
        $key = self::diplotypeKey( $aA, $aB );
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $row = $dbr->selectRow( 'pcp_pgx_diplotype', '*',
            [ 'pd_gene' => $gene, 'pd_diplotype_key' => $key ], __METHOD__ );
        if ( !$row ) {
            return self::base( $gene, 'unknown_diplotype',
                "No CPIC diplotype record for $gene $key." );
        }
        $score = ( $row->pd_activity_score !== null && is_numeric( (string)$row->pd_activity_score ) )
            ? (float)$row->pd_activity_score : null;
        if ( $row->pd_phenotype_slug === null || (string)$row->pd_phenotype_slug === '' ) {
            $r = self::base( $gene, 'indeterminate',
                "CPIC result for $gene $key: "
                . (string)( $row->pd_phenotype ?? 'indeterminate' ) . "." );
            $r['phenotype_label'] = $row->pd_phenotype !== null
                ? (string)$row->pd_phenotype : null;
            $r['activity_score'] = $score;
            return $r;
        }
        $r = self::base( $gene, 'resolved',
            "CPIC diplotype lookup: $gene $key -> "
            . (string)$row->pd_phenotype . "." );
        $r['phenotype_slug']  = (string)$row->pd_phenotype_slug;
        $r['phenotype_label'] = $row->pd_phenotype !== null
            ? (string)$row->pd_phenotype : null;
        $r['activity_score']  = $score;
        return $r;
    }

    /**
     * Canonical lookup key for a diplotype: two alleles normalized,
     * numeric-sorted, joined with "/". Shared by IngestCpicDiplotypes
     * (splitting CPIC's diplotype string) and the lookup path, so the key
     * agrees regardless of allele order.
     */
    public static function diplotypeKey( string $a, string $b ): string {
        $pair = [ self::normalizeAllele( $a ), self::normalizeAllele( $b ) ];
        usort( $pair, [ self::class, 'cmpAllele' ] );
        return $pair[0] . '/' . $pair[1];
    }

    /** Numeric-aware star-allele comparator: *2 sorts before *10. */
    private static function cmpAllele( string $x, string $y ): int {
        $nx = preg_match( '/\*(\d+)/', $x, $m ) ? (int)$m[1] : PHP_INT_MAX;
        $ny = preg_match( '/\*(\d+)/', $y, $m ) ? (int)$m[1] : PHP_INT_MAX;
        if ( $nx !== $ny ) return $nx <=> $ny;
        return strcmp( $x, $y );
    }

    /** Genes the resolver can currently resolve. */
    public static function supportedGenes(): array {
        return [
            'activity_score' => array_keys( self::ACTIVITY_BANDS ),
            'lookup'         => 'all genes in pcp_pgx_diplotype',
            'unmodeled'      => self::LOOKUP_SKIP,
        ];
    }

    /** Skeleton result array. */
    private static function base( string $gene, string $status, string $detail ): array {
        return [
            'status'          => $status,
            'gene'            => $gene,
            'phenotype_slug'  => null,
            'phenotype_label' => null,
            'activity_score'  => null,
            'detail'          => $detail,
        ];
    }

    /**
     * Allele activity value from pcp_pgx_allele. float, or null if absent
     * or non-numeric ("n/a").
     */
    private static function activityValue( string $gene, string $allele ): ?float {
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $row = $dbr->selectRow( 'pcp_pgx_allele', 'pa_activity_value',
            [ 'pa_gene' => $gene, 'pa_allele' => $allele ], __METHOD__ );
        if ( !$row || $row->pa_activity_value === null ) return null;
        $num = preg_replace( '/[^0-9.]/', '', (string)$row->pa_activity_value );
        if ( $num === '' || !is_numeric( $num ) ) return null;
        return (float)$num;
    }

    /**
     * Normalize an allele token to catalog form. Accepts "*4", "4",
     * "CYP2D6*4"; leaves rs-IDs and named alleles alone.
     */
    private static function normalizeAllele( string $a ): string {
        $a = trim( $a );
        $a = preg_replace( '/^[A-Za-z0-9]+\*/', '*', $a );
        if ( preg_match( '/^\d/', $a ) ) $a = '*' . $a;
        return $a;
    }

    /** Compact float formatting: 2.0 -> "2", 0.25 stays. */
    private static function fmt( float $v ): string {
        $s = rtrim( rtrim( sprintf( '%.2f', $v ), '0' ), '.' );
        return $s === '' ? '0' : $s;
    }
}
