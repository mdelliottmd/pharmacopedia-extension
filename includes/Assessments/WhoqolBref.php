<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * WHOQOL-BREF, 26-item brief Quality of Life assessment.
 *
 * WHOQOL Group (1998). Development of the World Health Organization
 * WHOQOL-BREF Quality of Life Assessment. Psychological Medicine, 28(3),
 * 551-558. Manual: Skevington, Lotfy, & O'Connell (2004). The World Health
 * Organization's WHOQOL-BREF quality of life assessment. Quality of Life
 * Research, 13(2), 299-310.
 *
 * 26 items: 2 overall facets (perceived QoL, perceived health) plus four
 * domains:
 *   Physical health (7 items)
 *   Psychological  (6 items)
 *   Social relationships (3 items)
 *   Environment    (8 items)
 *
 * The original WHOQOL-BREF uses four DIFFERENT 5-point anchor sets across
 * items (very poor / very good; very dissatisfied / very satisfied; not at
 * all / an extreme amount; not at all / completely; never / always). This
 * implementation uses a uniform 5-point Likert with generic
 * "Very poor/dissatisfied / Very good/satisfied" anchors to fit the
 * existing render pipeline. Numeric scoring is unaffected. Reverse items:
 * Q3, Q4, Q26.
 *
 * Standard domain scoring: sum the items in a domain, multiply by 4 to
 * rescale to the 4-20 range, then optionally rescale to 0-100 via
 * ((raw_mean - 1) * 25). Higher = better quality of life.
 *
 * Item content reconstructed from the open-access WHOQOL-BREF manual.
 * Verify against the official WHO instrument before clinical use.
 */
class WhoqolBref {
    public const KEY        = 'whoqolbref';
    public const NAME       = 'WHOQOL-BREF';
    public const FULL_NAME  = 'World Health Organization Quality of Life, Brief';
    public const CITATION   = 'WHOQOL Group 1998 (Psychological Medicine 28(3):551-558); Skevington et al. 2004';
    public const DESCRIPTION = 'A 26-item WHO measure of quality of life across four life domains: physical health, psychological, social relationships, and environment, plus two overall items. ~5 minutes.';
    public const WARNING    = '';
    public const PAGE_SIZE  = 26;

    public const RESPONSE_LABELS = [
        1 => 'Very poor / Very dissatisfied / Not at all',
        2 => 'Poor / Dissatisfied / A little',
        3 => 'Neither poor nor good / Neither / A moderate amount',
        4 => 'Good / Satisfied / Mostly',
        5 => 'Very good / Very satisfied / Completely',
    ];

    public const ITEMS = [
        1  => 'How would you rate your quality of life?',
        2  => 'How satisfied are you with your health?',
        3  => 'To what extent do you feel that physical pain prevents you from doing what you need to do?',
        4  => 'How much do you need any medical treatment to function in your daily life?',
        5  => 'How much do you enjoy life?',
        6  => 'To what extent do you feel your life to be meaningful?',
        7  => 'How well are you able to concentrate?',
        8  => 'How safe do you feel in your daily life?',
        9  => 'How healthy is your physical environment?',
        10 => 'Do you have enough energy for everyday life?',
        11 => 'Are you able to accept your bodily appearance?',
        12 => 'Have you enough money to meet your needs?',
        13 => 'How available to you is the information that you need in your day-to-day life?',
        14 => 'To what extent do you have the opportunity for leisure activities?',
        15 => 'How well are you able to get around?',
        16 => 'How satisfied are you with your sleep?',
        17 => 'How satisfied are you with your ability to perform your daily living activities?',
        18 => 'How satisfied are you with your capacity for work?',
        19 => 'How satisfied are you with yourself?',
        20 => 'How satisfied are you with your personal relationships?',
        21 => 'How satisfied are you with your sex life?',
        22 => 'How satisfied are you with the support you get from your friends?',
        23 => 'How satisfied are you with the conditions of your living place?',
        24 => 'How satisfied are you with your access to health services?',
        25 => 'How satisfied are you with your transport?',
        26 => 'How often do you have negative feelings such as blue mood, despair, anxiety, depression?',
    ];

    /** Reverse-scored items (score = 6 - response on the 1-5 scale). */
    public const REVERSE = [ 3, 4, 26 ];

    /**
     * Domain memberships per WHOQOL-BREF manual:
     *   Q1 + Q2 are overall facets (not in any domain, reported separately).
     *   Physical:      Q3, Q4, Q10, Q15, Q16, Q17, Q18
     *   Psychological: Q5, Q6, Q7, Q11, Q19, Q26
     *   Social:        Q20, Q21, Q22
     *   Environment:   Q8, Q9, Q12, Q13, Q14, Q23, Q24, Q25
     */
    public const SUBSCALES = [
        'PHY' => [ 'label' => 'Physical health',   'items' => [ 3, 4, 10, 15, 16, 17, 18 ] ],
        'PSY' => [ 'label' => 'Psychological',     'items' => [ 5, 6, 7, 11, 19, 26 ] ],
        'SOC' => [ 'label' => 'Social',            'items' => [ 20, 21, 22 ] ],
        'ENV' => [ 'label' => 'Environment',       'items' => [ 8, 9, 12, 13, 14, 23, 24, 25 ] ],
        // Overall facets reported separately:
        'OVR' => [ 'label' => 'Overall QoL + health', 'items' => [ 1, 2 ] ],
    ];

    /**
     * Returns:
     *   subscale_PHY .. subscale_ENV: domain mean rescaled to 0-100
     *   subscale_OVR: mean of Q1, Q2 rescaled to 0-100
     *   total: mean of all 26 items rescaled to 0-100
     * Reverse items are inverted before averaging.
     */
    public static function scoreResponses( array $responses ): array {
        $apply = function ( int $itemN, $raw ) {
            $v = (float)$raw;
            return in_array( $itemN, self::REVERSE, true ) ? ( 6 - $v ) : $v;
        };
        $rescale = function ( ?float $mean ): ?float {
            if ( $mean === null ) return null;
            return round( ( $mean - 1.0 ) * 25.0, 1 );
        };
        $scores = [];
        $allVals = [];
        foreach ( self::SUBSCALES as $k => $def ) {
            $vals = [];
            foreach ( $def['items'] as $itemN ) {
                if ( isset( $responses[ $itemN ] ) && $responses[ $itemN ] !== '' && $responses[ $itemN ] !== null ) {
                    $vals[]    = $apply( $itemN, $responses[ $itemN ] );
                    $allVals[] = $apply( $itemN, $responses[ $itemN ] );
                }
            }
            $mean = $vals ? array_sum( $vals ) / count( $vals ) : null;
            $scores[ 'subscale_' . $k ] = $rescale( $mean );
        }
        $totalMean = $allVals ? array_sum( $allVals ) / count( $allVals ) : null;
        $scores['total'] = $rescale( $totalMean );
        return $scores;
    }

    public static function interpret( array $scores ): string {
        $t = $scores['total'] ?? null;
        if ( $t === null ) return 'Incomplete.';
        if ( $t >= 75 ) return "Overall QoL {$t} / 100, generally high quality of life.";
        if ( $t >= 50 ) return "Overall QoL {$t} / 100, moderate quality of life.";
        if ( $t >= 25 ) return "Overall QoL {$t} / 100, lower quality of life.";
        return "Overall QoL {$t} / 100, markedly low quality of life.";
    }
}
