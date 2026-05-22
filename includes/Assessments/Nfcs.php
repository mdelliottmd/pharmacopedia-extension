<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * NFCS, Need for Closure Scale, 15-item brief version.
 *
 * Roets, A., & Van Hiel, A. (2011). Item selection and validation of a brief,
 * 15-item version of the Need for Closure Scale. Personality and Individual
 * Differences, 50(1), 90-94. doi:10.1016/j.paid.2010.09.004
 *
 * Original 42-item scale: Webster, D. M., & Kruglanski, A. W. (1994).
 * Individual differences in need for cognitive closure. Journal of
 * Personality and Social Psychology, 67(6), 1049-1062.
 *
 * 6-point Likert (1 strongly disagree to 6 strongly agree). Five facets, each
 * 3 items: Order, Predictability, Decisiveness, Ambiguity intolerance,
 * Closed-mindedness. The 15-item brief omits the "Need to avoid invalidity"
 * lie-scale items from the parent NFCS; no reverse-keyed items.
 *
 * Item content reconstructed from the open-access publication. Verify
 * against the original before clinical use.
 */
class Nfcs {
    public const KEY        = 'nfcs';
    public const NAME       = 'NFCS-PCP';
    public const FULL_NAME  = 'Need for Closure Scale (brief)';
    public const CITATION   = 'Roets & Van Hiel 2011 (PAID 50(1):90-94); original Webster & Kruglanski 1994';
    public const DESCRIPTION = 'A 15-item measure of individual differences in the desire for definite knowledge and aversion to ambiguity. Five facets: Order, Predictability, Decisiveness, Ambiguity intolerance, Closed-mindedness.  (Adapted from NFCS)';
    public const WARNING    = '';
    public const PAGE_SIZE  = 15;

    public const RESPONSE_LABELS = [
        1 => 'Strongly disagree',
        2 => 'Disagree',
        3 => 'Slightly disagree',
        4 => 'Slightly agree',
        5 => 'Agree',
        6 => 'Strongly agree',
    ];

    public const ITEMS = [
        1  => "I don't like situations that are uncertain.",
        2  => 'I dislike questions which could be answered in many different ways.',
        3  => 'I find that a well ordered life with regular hours suits my temperament.',
        4  => "I feel uncomfortable when I don't understand the reason why an event occurred in my life.",
        5  => 'I feel irritated when one person disagrees with what everyone else in a group believes.',
        6  => "I don't like to go into a situation without knowing what I can expect from it.",
        7  => 'When I have made a decision, I feel relieved.',
        8  => "When I am confronted with a problem, I'm dying to reach a solution very quickly.",
        9  => 'I would quickly become impatient and irritated if I would not find a solution to a problem immediately.',
        10 => "I don't like to be with people who are capable of unexpected actions.",
        11 => "I dislike it when a person's statement could mean many different things.",
        12 => 'I find that establishing a consistent routine enables me to enjoy life more.',
        13 => 'I enjoy having a clear and structured mode of life.',
        14 => 'I do not usually consult many different opinions before forming my own view.',
        15 => 'I dislike unpredictable situations.',
    ];

    public const REVERSE = []; // No reverse-keyed items in the brief version.

    public const SUBSCALES = [
        'ORD' => [ 'label' => 'Order',                  'items' => [ 3, 12, 13 ] ],
        'PRD' => [ 'label' => 'Predictability',         'items' => [ 6, 10, 15 ] ],
        'DEC' => [ 'label' => 'Decisiveness',           'items' => [ 7, 8, 9 ] ],
        'AMB' => [ 'label' => 'Ambiguity intolerance',  'items' => [ 1, 2, 11 ] ],
        'CLM' => [ 'label' => 'Closed-mindedness',      'items' => [ 4, 5, 14 ] ],
    ];

    /**
     * Returns ['subscale_ORD' => sum (3..18), ..., 'total' => sum (15..90)].
     */
    public static function scoreResponses( array $responses ): array {
        $scores = [];
        $allVals = [];
        foreach ( self::SUBSCALES as $k => $def ) {
            $vals = [];
            foreach ( $def['items'] as $itemN ) {
                if ( isset( $responses[ $itemN ] ) && $responses[ $itemN ] !== '' && $responses[ $itemN ] !== null ) {
                    $v = (float)$responses[ $itemN ];
                    $vals[]    = $v;
                    $allVals[] = $v;
                }
            }
            $scores[ 'subscale_' . $k ] = $vals ? round( array_sum( $vals ), 2 ) : null;
        }
        $scores['total'] = $allVals ? round( array_sum( $allVals ), 2 ) : null;
        return $scores;
    }

    public static function interpret( array $scores ): string {
        $t = $scores['total'] ?? null;
        if ( $t === null ) return 'Incomplete.';
        // Roets & Van Hiel describe means around 55 in general adult samples;
        // higher scores indicate stronger preference for closure.
        if ( $t >= 70 ) return "Total {$t} / 90, high need for closure.";
        if ( $t >= 50 ) return "Total {$t} / 90, typical need for closure.";
        return "Total {$t} / 90, low need for closure (high tolerance for ambiguity).";
    }
}
