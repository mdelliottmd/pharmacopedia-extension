<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * NFCS-15 reference data + descriptive cutoffs.
 *
 * Source: Roets & Van Hiel (2011, PAID 50(1):90-94). The brief NFCS does
 * not have published diagnostic-style cutoffs; reference points used here
 * are descriptive bands derived from sample means / standard deviations
 * in their validation work.
 */
class NfcsNorms {

    /** Sample mean reported by Roets & Van Hiel 2011 (rounded). Used as the
     *  midpoint of the "typical" band. */
    public const TOTAL_MEAN_REF = 55.0;
    public const TOTAL_HIGH_REF = 70.0;
    public const TOTAL_LOW_REF  = 40.0;

    /** Plain-English description of each facet. */
    public const SUBSCALE_BLURBS = [
        'ORD' => [ 'Order',
            'Preference for orderly, well-organised, and tidy environments. '
            . 'Discomfort with disorder and disarray.' ],
        'PRD' => [ 'Predictability',
            'Preference for stable, predictable, and known situations. '
            . 'Discomfort with unforeseen events or unpredictable people.' ],
        'DEC' => [ 'Decisiveness',
            'Preference for arriving at decisions quickly. Discomfort with '
            . 'unresolved problems or open-ended deliberation.' ],
        'AMB' => [ 'Ambiguity intolerance',
            'Dislike of situations or statements that admit multiple '
            . 'interpretations or that resist a single clear meaning.' ],
        'CLM' => [ 'Closed-mindedness',
            'Reluctance to consider alternative viewpoints; preference for '
            . 'sticking with an initially-formed opinion.' ],
    ];

    public const SUBSCALE_MAX = [
        'ORD' => 18, 'PRD' => 18, 'DEC' => 18, 'AMB' => 18, 'CLM' => 18,
    ];
    public const TOTAL_MAX = 90;
    public const TOTAL_MIN = 15;

    public static function classifyTotal( ?float $total ): string {
        if ( $total === null ) return 'incomplete';
        if ( $total >= self::TOTAL_HIGH_REF ) return 'high';
        if ( $total <= self::TOTAL_LOW_REF )  return 'low';
        return 'typical';
    }
}
