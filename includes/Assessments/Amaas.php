<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * AMAAS-PCP-SR, the Self-Report form of the Adult Multi-perspective
 * Attentional Attributes Scale (AMAAS).
 *
 * AMAAS is an ORIGINAL instrument, authored from the public DSM-5 ADHD
 * construct. It is not derived from, and not an adaptation of, the
 * BAARS-IV, the Conners/CAARS, the Brown EF/A scales, the ASRS, or the
 * NICHQ Vanderbilt.
 *
 * NOT VALIDATED. No norms, no validated cutoffs, no established
 * sensitivity or specificity. Score bands are arithmetic thirds, not
 * clinical thresholds. Not a diagnosis. There is deliberately no
 * AmaasNorms class.
 *
 * RESPONSE MODEL. Every item is a "what percent of the time ...?"
 * question answered on one 0-100 continuous slider. EVERY ANSWER IS A
 * ROUGH SELF-ESTIMATE; the instrument is approximate by design, stated
 * instrument-wide (DESCRIPTION + WARNING + the report) rather than with
 * a per-item flag. Each item has two states:
 *   ANSWERED         a 0-100 value, counted in scoring
 *   "Not sure" (IDK) excluded from scoring; subscales prorate over it
 *
 * SCORING. Reverse items are flipped (100 - value) first. Each subscale
 * is prorated: prorated raw = (sum of answered / answered count) * item
 * count. A subscale with zero answered items is null (reported NA).
 *
 * v1 = AMAAS-PCP-SR, self-report, 30 items. v2 will add AMAAS-PCP-OR (a
 * collateral observer form) and the deferred EXM / EMO / childhood-onset
 * content.
 */
class Amaas {
    public const KEY         = 'amaas';
    public const NAME        = 'AMAAS-PCP-SR';
    public const FULL_NAME   = 'Adult Multi-perspective Attentional Attributes Scale (Self-Report form)';
    public const CITATION    = 'Original instrument, Pharmacopedia 2026. Experimental, not yet validated.';
    public const DESCRIPTION = 'An experimental 30-item self-report on adult attention, activity, and impulse experiences over the past 6 months. Every answer is a rough self-estimate: move each slider to roughly the percent of the time that fits. If you genuinely cannot estimate an item, tick "Not sure" and it is left out of your results. Not a diagnostic instrument.  (locally brewed. not [yet] validated)';
    public const WARNING     = 'AMAAS-PCP-SR is an experimental instrument in development. It has not been validated: no norms, no validated cutoffs, no established accuracy. Every answer is an approximate self-estimate, so every score here is approximate. Results are a structured self-reflection, not a screening result and not a diagnosis. A diagnosis of ADHD requires a clinician, evidence of childhood onset, symptoms across more than one setting, functional impairment, and exclusion of other explanations. For a validated free self-report, see the ASRS-v1.1.';
    public const PAGE_SIZE   = 10;

    /** The 0-100 percent scale. The slider is continuous; these bound it. */
    public const SCALE_MIN = 0;
    public const SCALE_MAX = 100;

    /** A symptom "counts" when its direction-scored value reaches this percent. */
    public const SYMPTOM_COUNT_THRESHOLD = 60;

    /** An infrequency item is a strike at or above this percent (honest respondents sit near 0). */
    public const INFREQUENCY_THRESHOLD = 60;

    /** Consistency-pair discrepancy that counts as a strike, on the 0-100 scale. */
    public const CONSISTENCY_GAP = 40;

    /** DSM-5 adult symptom-count landmark per dimension. Informational reference only, NOT a cutoff. */
    public const DSM_ADULT_LANDMARK = 5;

    /**
     * Vestigial for AMAAS: the slider form path does not read
     * RESPONSE_LABELS (only the radio path does). Kept as the two scale
     * anchors so the constant exists for any generic consumer.
     */
    public const RESPONSE_LABELS = [
        0   => '0% of the time',
        100 => '100% of the time',
    ];

    /** Instrument-wide approximation note, surfaced on the form and in the report. */
    public const APPROX_NOTE = 'Every answer on AMAAS-PCP-SR is a rough self-estimate, not a precise count. Treat all scores here as approximate.';

    /** The 30 item stems. Authored by home-claude from the DSM-5 ADHD construct (original wording). */
    public const ITEMS = [
        1  => 'What percent of the time do you lose focus on a task before you finish it?',
        2  => 'What percent of the time do you make careless mistakes by missing small details?',
        3  => 'What percent of the time can you read or listen to someone for a long time without your mind wandering?',
        4  => 'What percent of the time do you move on to something new before you finish what you were doing?',
        5  => 'What percent of the time do you struggle to keep yourself organized?',
        6  => 'What percent of the time do you put off tasks that take a long stretch of concentration?',
        7  => 'What percent of the time do you misplace everyday things such as your keys, phone, or wallet?',
        8  => 'What percent of the time can you tune out noise and activity around you and stay on what you are doing?',
        9  => 'What percent of the time do you forget to do routine tasks, such as errands or chores?',
        10 => 'What percent of the time do you feel restless inside even when you are sitting quietly?',
        11 => 'What percent of the time do you feel driven to stay busy, as though you cannot slow down?',
        12 => 'What percent of the time do you find it hard to unwind, even when there is nothing you need to do?',
        13 => 'What percent of the time do you fidget with your hands or feet when you stay in one place?',
        14 => 'What percent of the time can you sit still comfortably for a long time?',
        15 => 'What percent of the time do you feel an urge to get up and move when you are expected to stay seated?',
        16 => 'What percent of the time do you act on impulse without thinking things through?',
        17 => 'What percent of the time do you interrupt others while they are speaking?',
        18 => 'What percent of the time do you find it hard to wait your turn, such as in a line?',
        19 => 'What percent of the time do you think carefully about whether to say something before you say it?',
        20 => 'What percent of the time do you buy things on impulse that you did not plan to buy?',
        21 => 'What percent of the time do you feel impatient when things move more slowly than you would like?',
        22 => 'What percent of the time do these patterns get in the way of your work or studies?',
        23 => 'What percent of the time do these patterns get in the way of handling everyday tasks at home?',
        24 => 'What percent of the time do these patterns get in the way of your close relationships?',
        25 => 'What percent of the time do these patterns get in the way of managing your time?',
        26 => 'What percent of the time do these patterns get in the way of managing your money?',
        27 => 'What percent of the time do these patterns get in the way of taking care of yourself day to day?',
        28 => 'What percent of the time do you get lost inside your own home?',
        29 => 'What percent of the time do you forget the names of your own close family members?',
        30 => 'What percent of the time do you lose track of where you put everyday items like your keys or phone?',
    ];

    /** Reverse-worded items (phrased as strengths): direction value = 100 - raw. */
    public const REVERSE = [ 3, 8, 14, 19 ];

    /** Consistency pair for the validity check: [ validityItem => coreItem ]. */
    public const CONSISTENCY_PAIR = [ 30 => 7 ];

    /** Validity infrequency items: rare experiences; a high value is a strike. */
    public const INFREQUENCY_ITEMS = [ 28, 29 ];

    /**
     * Symptom subscales. Framework-facing: the SpecialMyProfile inline
     * block iterates SUBSCALES and reads subscale_<CODE> score keys.
     */
    public const SUBSCALES = [
        'INA' => [ 'label' => 'Inattention',   'items' => [ 1, 2, 3, 4, 5, 6, 7, 8, 9 ] ],
        'HYP' => [ 'label' => 'Hyperactivity', 'items' => [ 10, 11, 12, 13, 14, 15 ] ],
        'IMP' => [ 'label' => 'Impulsivity',   'items' => [ 16, 17, 18, 19, 20, 21 ] ],
    ];

    /** Non-symptom modules. Consumed by the report renderer, not the framework. */
    public const MODULES = [
        'IMPAIR' => [ 'label' => 'Functional interference', 'items' => [ 22, 23, 24, 25, 26, 27 ] ],
        'VALID'  => [ 'label' => 'Response validity',       'items' => [ 28, 29, 30 ] ],
    ];

    /**
     * Score a set of responses. $responses is [ itemNumber => 0-100 ]
     * for ANSWERED items only; "Not sure" items are simply absent.
     * $idkItems optionally lists the item numbers marked "Not sure", used
     * only to compute `complete`; scoring itself needs only $responses.
     *
     * Returns numeric values only (the framework casts each to float):
     *   subscale_INA / subscale_HYP / subscale_IMP   prorated raw, or null
     *   answered_INA / answered_HYP / answered_IMP   answered-item counts
     *   dimension_HI                                 prorated HYP + IMP
     *   answered_HI                                  answered HYP + IMP items
     *   total                                        prorated INA + HYP + IMP
     *   count_INA / count_HI                         items at the symptom threshold
     *   module_IMPAIR / answered_IMPAIR              interference module
     *   complete                                     1 if all 30 addressed
     *
     * The validity flag is not in this array (non-numeric); call
     * validityFlag() for it.
     */
    public static function scoreResponses( array $responses, array $idkItems = [] ): array {
        $out = [];
        $proratedDomains = [];

        foreach ( self::SUBSCALES as $code => $def ) {
            $vals = self::directionalValues( $responses, $def['items'] );
            $a = count( $vals );
            $n = count( $def['items'] );
            $out[ 'answered_' . $code ] = $a;
            $prorated = $a > 0 ? round( ( array_sum( $vals ) / $a ) * $n, 2 ) : null;
            $out[ 'subscale_' . $code ] = $prorated;
            if ( $prorated !== null ) {
                $proratedDomains[] = $prorated;
            }
        }

        $hiParts = [];
        if ( $out['subscale_HYP'] !== null ) {
            $hiParts[] = $out['subscale_HYP'];
        }
        if ( $out['subscale_IMP'] !== null ) {
            $hiParts[] = $out['subscale_IMP'];
        }
        $out['dimension_HI'] = $hiParts ? round( array_sum( $hiParts ), 2 ) : null;
        $out['answered_HI']  = $out['answered_HYP'] + $out['answered_IMP'];
        $out['total']        = $proratedDomains ? round( array_sum( $proratedDomains ), 2 ) : null;

        $out['count_INA'] = self::symptomCount( $responses, self::SUBSCALES['INA']['items'] );
        $out['count_HI']  = self::symptomCount(
            $responses,
            array_merge( self::SUBSCALES['HYP']['items'], self::SUBSCALES['IMP']['items'] )
        );

        $impVals = self::directionalValues( $responses, self::MODULES['IMPAIR']['items'] );
        $aImp = count( $impVals );
        $nImp = count( self::MODULES['IMPAIR']['items'] );
        $out['answered_IMPAIR'] = $aImp;
        $out['module_IMPAIR']   = $aImp > 0 ? round( ( array_sum( $impVals ) / $aImp ) * $nImp, 2 ) : null;

        $addressed = [];
        foreach ( array_keys( $responses ) as $k ) {
            $addressed[ (int)$k ] = true;
        }
        foreach ( $idkItems as $k ) {
            $addressed[ (int)$k ] = true;
        }
        $out['complete'] = ( count( $addressed ) >= 30 ) ? 1 : 0;

        return $out;
    }

    /**
     * Count items whose direction-scored value reaches the symptom
     * threshold. Reverse items are flipped first.
     */
    public static function symptomCount( array $responses, array $itemNums ): int {
        $c = 0;
        foreach ( $itemNums as $n ) {
            if ( !self::answered( $responses, $n ) ) {
                continue;
            }
            $raw = (float)$responses[$n];
            $dir = in_array( $n, self::REVERSE, true ) ? ( self::SCALE_MAX - $raw ) : $raw;
            if ( $dir >= self::SYMPTOM_COUNT_THRESHOLD ) {
                $c++;
            }
        }
        return $c;
    }

    /**
     * Response-validity flag from the VALID module.
     *  - infrequency: each of items 28, 29 answered at or above the
     *    infrequency threshold is a strike;
     *  - consistency: items 30 and 7 differing by CONSISTENCY_GAP or
     *    more is a strike;
     * A check whose item(s) are "Not sure" (absent here) does not run.
     * Returns 'not_assessed' if no check ran, else 'none' / 'caution'
     * (1 strike) / 'invalid' (2+).
     */
    public static function validityFlag( array $responses ): string {
        $strikes = 0;
        $checksRun = 0;
        foreach ( self::INFREQUENCY_ITEMS as $n ) {
            if ( self::answered( $responses, $n ) ) {
                $checksRun++;
                if ( (float)$responses[$n] >= self::INFREQUENCY_THRESHOLD ) {
                    $strikes++;
                }
            }
        }
        foreach ( self::CONSISTENCY_PAIR as $vItem => $coreItem ) {
            if ( self::answered( $responses, (int)$vItem )
                && self::answered( $responses, (int)$coreItem ) ) {
                $checksRun++;
                if ( abs( (float)$responses[$vItem] - (float)$responses[$coreItem] ) >= self::CONSISTENCY_GAP ) {
                    $strikes++;
                }
            }
        }
        if ( $checksRun === 0 ) {
            return 'not_assessed';
        }
        if ( $strikes >= 2 ) {
            return 'invalid';
        }
        if ( $strikes === 1 ) {
            return 'caution';
        }
        return 'none';
    }

    /**
     * Descriptive band for a score against its maximum: arithmetic
     * thirds. NOT a clinical cutoff. 'few' | 'some' | 'many', or null.
     */
    public static function band( ?float $score, float $max ): ?string {
        if ( $score === null || $max <= 0 ) {
            return null;
        }
        $frac = $score / $max;
        if ( $frac < 1 / 3 ) {
            return 'few';
        }
        if ( $frac < 2 / 3 ) {
            return 'some';
        }
        return 'many';
    }

    /** Human-readable band label. */
    public static function bandLabel( ?string $band ): string {
        switch ( $band ) {
            case 'few':
                return 'Few traits endorsed';
            case 'some':
                return 'Some traits endorsed';
            case 'many':
                return 'Many traits endorsed';
            default:
                return 'Not enough responses';
        }
    }

    /** Maximum prorated raw for a subscale (item count x top of scale). */
    public static function subscaleMax( string $code ): float {
        if ( !isset( self::SUBSCALES[$code] ) ) {
            return 0.0;
        }
        return count( self::SUBSCALES[$code]['items'] ) * (float)self::SCALE_MAX;
    }

    /**
     * Short plain-language summary, non-diagnostic and approximate by
     * construction. Operates on numeric stored scores.
     */
    public static function interpret( array $scores ): string {
        if ( empty( $scores['complete'] ) ) {
            return 'Incomplete. Answer or skip all 30 items for a full reflection.';
        }
        $parts = [];
        foreach ( self::SUBSCALES as $code => $def ) {
            $raw = $scores[ 'subscale_' . $code ] ?? null;
            $band = self::band( $raw !== null ? (float)$raw : null, self::subscaleMax( $code ) );
            $parts[] = $def['label'] . ' ' . strtolower( self::bandLabel( $band ) );
        }
        return implode( '; ', $parts )
            . '. An experimental, approximate self-reflection, not a diagnosis.';
    }

    /* ---- helpers ---- */

    /** True if item $n has a numeric answer. "Not sure" items are absent, so this is false for them. */
    private static function answered( array $responses, int $n ): bool {
        return isset( $responses[$n] ) && $responses[$n] !== '' && $responses[$n] !== null;
    }

    /**
     * Direction-scored values for the answered items among $itemNums:
     * reverse items flipped to 100 - raw, others left as raw.
     */
    private static function directionalValues( array $responses, array $itemNums ): array {
        $vals = [];
        foreach ( $itemNums as $n ) {
            if ( !self::answered( $responses, $n ) ) {
                continue;
            }
            $raw = (float)$responses[$n];
            $vals[] = in_array( $n, self::REVERSE, true ) ? ( self::SCALE_MAX - $raw ) : $raw;
        }
        return $vals;
    }
}
