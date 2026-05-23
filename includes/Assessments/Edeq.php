<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * Edeq - EDE-Q-PCP, a brief eating-pattern and body-image questionnaire.
 *
 * Thirty items in five blocks, all referenced to the past 30 days. Where the
 * original EDE-Q uses ordinal categorical responses, EDE-Q-PCP uses continuous
 * sliders for arbitrary precision: a frequency item is a slider in days, a
 * proportion item is a slider in percent, an intensity item is a slider on
 * the 0-6 scale. The subscale scorer normalizes each slider value back onto
 * the 0-6 scale before averaging, so the Fairburn-style subscale means and
 * global score remain comparable to the original instrument.
 *
 *   1-12, 19    Slider 0-30 days. Frequency-of-behavior items covering
 *               deliberate restraint, dietary rules, food and shape/weight
 *               preoccupation, fear of losing control or gaining weight, and
 *               eating in secret.
 *   13-15       Plain integer counts: episodes of objectively large eating,
 *               episodes of loss of control over eating, and days on which
 *               the two occurred together (objective binge days).
 *   16-18       Gated integer counts: did you do this, and if so how many
 *               times? Covers self-induced vomiting, laxative use, and
 *               driven exercise.
 *   20          Slider 0-100%. The proportion of meals at which guilt was
 *               felt.
 *   21-28       Slider 0-6 (continuous). Intensity items on judging oneself
 *               by shape or weight, dissatisfaction with shape or weight,
 *               and discomfort with one's own body and with others seeing
 *               it.
 *   29-30       Numeric weight and height, with a unit switch (lb+in or
 *               kg+cm); stored canonical (kg, cm) and used to derive BMI.
 *
 * Subscales follow Fairburn and Beglin (2008): Restraint (1-5), Eating Concern
 * (7, 9, 19, 20, 21), Shape Concern (6, 8, 10, 11, 23, 26, 27, 28), and
 * Weight Concern (8, 12, 22, 24, 25); item 8 ("how much shape or weight has
 * affected your judgement of yourself") double-counts on Shape and Weight, as
 * in the original. Each subscale item is normalized to a 0-6 equivalent
 * (value * 6 / subscale_max), the subscale is the mean of its items, and the
 * global score is the mean of the four subscales.
 *
 * ORIGINAL instrument. The items were rewritten from the eating-disorder
 * construct in this codebase's voice, not transcribed from the EDE-Q.
 * EDE-Q-PCP is inspired by Fairburn and Beglin's EDE-Q 6.0, not a copy of it.
 * Inspired by Fairburn CG, Beglin SJ. Eating Disorder Examination
 * Questionnaire (EDE-Q 6.0). In: Fairburn CG, ed. Cognitive Behavior Therapy
 * and Eating Disorders. New York: Guilford Press; 2008:309-313.
 *
 * Not validated, not diagnostic. Locally brewed.
 *
 * @license GPL-3.0-or-later
 */
class Edeq {

    public const KEY         = 'edeq';
    public const NAME        = 'EDE-Q-PCP';
    public const FULL_NAME   = 'Eating and body-image check (inspired by the EDE-Q)';
    public const CITATION    = 'Original instrument, Pharmacopedia 2026, inspired by the Eating '
        . 'Disorder Examination Questionnaire (EDE-Q 6.0; Fairburn CG, Beglin SJ. In: Fairburn CG, '
        . 'ed. Cognitive Behavior Therapy and Eating Disorders. Guilford, 2008:309-313). '
        . 'Not validated.';
    public const DESCRIPTION = 'A check on eating patterns, body image, and weight-control '
        . 'behaviours over the past month, across thirty items in five blocks: continuous '
        . 'sliders for how many days each pattern showed up, plain counts for binges and '
        . 'compensatory behaviours, sliders for guilt and being seen eating, sliders for how '
        . 'shape and weight have felt to live in. Closes with weight and height for context. '
        . 'Nothing here is a diagnosis; it is a structured snapshot you can take again over '
        . 'time. If an item does not apply or you cannot say, tick "Not sure".  (inspired by the '
        . 'EDE-Q; locally brewed, not [yet] validated)';
    public const WARNING     = 'EDE-Q-PCP is an informal check of eating-pattern and body-image '
        . 'experiences. It is not a diagnostic instrument and not a substitute for clinical '
        . 'assessment: it has no norms and no validated cutoffs, and an eating-disorder diagnosis '
        . 'is made by a clinician, not a questionnaire. If any of these items have been hard to '
        . 'sit with, or if eating-pattern concerns are weighing on you, please consider reaching '
        . 'out to a health professional, or in the US to the Suicide and Crisis Lifeline at 988.';

    /** The Fairburn subscale scale: items are normalized to 0-6 before averaging. */
    public const SCALE_MIN = 0;
    public const SCALE_MAX = 6;

    /** Vestigial for radio-only consumers; this instrument is mixed-model. */
    public const RESPONSE_LABELS = [
        0 => 'Not at all',
        6 => 'Markedly',
    ];

    /**
     * The thirty items, each tagged with its rendering type. Slider items
     * carry their own min/max/anchors and a 'subscale_max' that scales their
     * raw value back onto the 0-6 Fairburn axis for subscale averaging.
     * Counts and gated counts collect integers; numerics collect a
     * measurement with a unit switch. All items reference the past 30 days.
     */
    public const ITEMS = [

        // 1-5: Restraint. 0-30 day sliders.
        1 => [
            'type' => 'slider',
            'stem' => 'Over the past 30 days, on how many days have you deliberately tried '
                . 'to limit how much you eat in order to influence your shape or weight, '
                . 'whether or not you succeeded?',
            'min' => 0, 'max' => 30, 'step' => 'any', 'default' => 0,
            'lo'  => '0 days', 'hi' => '30 days', 'unit' => 'days', 'precision' => 0,
            'subscale_max' => 30,
        ],
        2 => [
            'type' => 'slider',
            'stem' => 'On how many days have you gone for long stretches of eight waking '
                . 'hours or more without eating anything, in order to influence your shape '
                . 'or weight?',
            'min' => 0, 'max' => 30, 'step' => 'any', 'default' => 0,
            'lo'  => '0 days', 'hi' => '30 days', 'unit' => 'days', 'precision' => 0,
            'subscale_max' => 30,
        ],
        3 => [
            'type' => 'slider',
            'stem' => 'On how many days have you tried to keep foods you like out of your '
                . 'diet, in order to influence your shape or weight, whether or not you '
                . 'succeeded?',
            'min' => 0, 'max' => 30, 'step' => 'any', 'default' => 0,
            'lo'  => '0 days', 'hi' => '30 days', 'unit' => 'days', 'precision' => 0,
            'subscale_max' => 30,
        ],
        4 => [
            'type' => 'slider',
            'stem' => 'On how many days have you tried to follow definite rules about your '
                . 'eating, such as a calorie limit, in order to influence your shape or '
                . 'weight, whether or not you succeeded?',
            'min' => 0, 'max' => 30, 'step' => 'any', 'default' => 0,
            'lo'  => '0 days', 'hi' => '30 days', 'unit' => 'days', 'precision' => 0,
            'subscale_max' => 30,
        ],
        5 => [
            'type' => 'slider',
            'stem' => 'On how many days have you wanted your stomach to be empty, with the '
                . 'aim of influencing your shape or weight?',
            'min' => 0, 'max' => 30, 'step' => 'any', 'default' => 0,
            'lo'  => '0 days', 'hi' => '30 days', 'unit' => 'days', 'precision' => 0,
            'subscale_max' => 30,
        ],

        // 6-12: Shape, eating, and weight items, 0-30 day sliders.
        6 => [
            'type' => 'slider',
            'stem' => 'On how many days have you wanted your stomach to be completely flat?',
            'min' => 0, 'max' => 30, 'step' => 'any', 'default' => 0,
            'lo'  => '0 days', 'hi' => '30 days', 'unit' => 'days', 'precision' => 0,
            'subscale_max' => 30,
        ],
        7 => [
            'type' => 'slider',
            'stem' => 'On how many days has thinking about food, eating, or calories made '
                . 'it hard to concentrate on something you cared about, such as work, '
                . 'reading, or following a conversation?',
            'min' => 0, 'max' => 30, 'step' => 'any', 'default' => 0,
            'lo'  => '0 days', 'hi' => '30 days', 'unit' => 'days', 'precision' => 0,
            'subscale_max' => 30,
        ],
        8 => [
            'type' => 'slider',
            'stem' => 'On how many days has thinking about your shape or weight made it '
                . 'hard to concentrate on something you cared about, such as work, reading, '
                . 'or following a conversation?',
            'min' => 0, 'max' => 30, 'step' => 'any', 'default' => 0,
            'lo'  => '0 days', 'hi' => '30 days', 'unit' => 'days', 'precision' => 0,
            'subscale_max' => 30,
        ],
        9 => [
            'type' => 'slider',
            'stem' => 'On how many days have you felt a definite fear of losing control '
                . 'over your eating?',
            'min' => 0, 'max' => 30, 'step' => 'any', 'default' => 0,
            'lo'  => '0 days', 'hi' => '30 days', 'unit' => 'days', 'precision' => 0,
            'subscale_max' => 30,
        ],
        10 => [
            'type' => 'slider',
            'stem' => 'On how many days have you felt a definite fear that you might gain '
                . 'weight?',
            'min' => 0, 'max' => 30, 'step' => 'any', 'default' => 0,
            'lo'  => '0 days', 'hi' => '30 days', 'unit' => 'days', 'precision' => 0,
            'subscale_max' => 30,
        ],
        11 => [
            'type' => 'slider',
            'stem' => 'On how many days have you felt fat?',
            'min' => 0, 'max' => 30, 'step' => 'any', 'default' => 0,
            'lo'  => '0 days', 'hi' => '30 days', 'unit' => 'days', 'precision' => 0,
            'subscale_max' => 30,
        ],
        12 => [
            'type' => 'slider',
            'stem' => 'On how many days have you had a strong desire to lose weight?',
            'min' => 0, 'max' => 30, 'step' => 'any', 'default' => 0,
            'lo'  => '0 days', 'hi' => '30 days', 'unit' => 'days', 'precision' => 0,
            'subscale_max' => 30,
        ],

        // 13-15: Plain integer counts (episodes / days).
        13 => [
            'type' => 'count',
            'stem' => 'Over the past 30 days, how many times have you eaten what other '
                . 'people would think of as an unusually large amount of food, given the '
                . 'circumstances?',
            'unit' => 'times',
            'min'  => 0, 'max' => 999,
        ],
        14 => [
            'type' => 'count',
            'stem' => 'On how many of those occasions did you have a sense of having lost '
                . 'control over your eating at the time?',
            'unit' => 'times',
            'min'  => 0, 'max' => 999,
        ],
        15 => [
            'type' => 'count',
            'stem' => 'Over the past 30 days, on how many days have such episodes of '
                . 'overeating happened, where you ate an unusually large amount and also '
                . 'felt out of control at the time?',
            'unit' => 'days',
            'min'  => 0, 'max' => 30,
        ],

        // 16-18: Gated counts (have you / if so how many).
        16 => [
            'type'       => 'gated_count',
            'gate_stem'  => 'Over the past 30 days, have you made yourself sick (vomited) '
                . 'as a way of controlling your shape or weight?',
            'count_stem' => 'How many times?',
            'unit'       => 'times',
            'min'        => 0, 'max' => 999,
        ],
        17 => [
            'type'       => 'gated_count',
            'gate_stem'  => 'Over the past 30 days, have you used laxatives as a way of '
                . 'controlling your shape or weight?',
            'count_stem' => 'How many times?',
            'unit'       => 'times',
            'min'        => 0, 'max' => 999,
        ],
        18 => [
            'type'       => 'gated_count',
            'gate_stem'  => 'Over the past 30 days, have you exercised in a driven or '
                . 'compulsive way as a way of controlling your shape, weight, or body fat, '
                . 'or to burn off calories?',
            'count_stem' => 'How many times?',
            'unit'       => 'times',
            'min'        => 0, 'max' => 999,
        ],

        // 19: Eating concern, 0-30 day slider.
        19 => [
            'type' => 'slider',
            'stem' => 'Over the past 30 days, on how many days have you eaten in secret '
                . '(in a way you would not want others to see)? Do not count the binge '
                . 'episodes above.',
            'min' => 0, 'max' => 30, 'step' => 'any', 'default' => 0,
            'lo'  => '0 days', 'hi' => '30 days', 'unit' => 'days', 'precision' => 0,
            'subscale_max' => 30,
        ],

        // 20: Eating concern, 0-100% proportion slider.
        20 => [
            'type' => 'slider',
            'stem' => 'On what proportion of the times you have eaten have you felt guilty '
                . 'about its effect on your shape or weight? Do not count the binge '
                . 'episodes above.',
            'min' => 0, 'max' => 100, 'step' => 'any', 'default' => 0,
            'lo'  => 'None of the times', 'hi' => 'Every time',
            'unit' => '%', 'precision' => 0,
            'subscale_max' => 100,
        ],

        // 21-28: Intensity items, continuous 0-6 sliders.
        21 => [
            'type' => 'slider',
            'stem' => 'Over the past 30 days, how concerned have you been about other '
                . 'people seeing you eat? Do not count the binge episodes above.',
            'min' => 0, 'max' => 6, 'step' => 'any', 'default' => 0,
            'lo'  => 'Not at all', 'hi' => 'Markedly', 'precision' => 1,
            'subscale_max' => 6,
        ],
        22 => [
            'type' => 'slider',
            'stem' => 'Over the past 30 days, how much has your weight affected how you '
                . 'judge yourself as a person?',
            'min' => 0, 'max' => 6, 'step' => 'any', 'default' => 0,
            'lo'  => 'Not at all', 'hi' => 'Markedly', 'precision' => 1,
            'subscale_max' => 6,
        ],
        23 => [
            'type' => 'slider',
            'stem' => 'How much has your shape affected how you judge yourself as a person?',
            'min' => 0, 'max' => 6, 'step' => 'any', 'default' => 0,
            'lo'  => 'Not at all', 'hi' => 'Markedly', 'precision' => 1,
            'subscale_max' => 6,
        ],
        24 => [
            'type' => 'slider',
            'stem' => 'How much would it have upset you to weigh yourself once a week '
                . 'over the next four weeks?',
            'min' => 0, 'max' => 6, 'step' => 'any', 'default' => 0,
            'lo'  => 'Not at all', 'hi' => 'Markedly', 'precision' => 1,
            'subscale_max' => 6,
        ],
        25 => [
            'type' => 'slider',
            'stem' => 'How dissatisfied have you been with your weight?',
            'min' => 0, 'max' => 6, 'step' => 'any', 'default' => 0,
            'lo'  => 'Not at all', 'hi' => 'Markedly', 'precision' => 1,
            'subscale_max' => 6,
        ],
        26 => [
            'type' => 'slider',
            'stem' => 'How dissatisfied have you been with your shape?',
            'min' => 0, 'max' => 6, 'step' => 'any', 'default' => 0,
            'lo'  => 'Not at all', 'hi' => 'Markedly', 'precision' => 1,
            'subscale_max' => 6,
        ],
        27 => [
            'type' => 'slider',
            'stem' => 'How uncomfortable have you felt seeing your own body, for example '
                . 'in a mirror, in a window reflection, or while undressing, bathing, or '
                . 'showering?',
            'min' => 0, 'max' => 6, 'step' => 'any', 'default' => 0,
            'lo'  => 'Not at all', 'hi' => 'Markedly', 'precision' => 1,
            'subscale_max' => 6,
        ],
        28 => [
            'type' => 'slider',
            'stem' => 'How uncomfortable have you felt about other people seeing your '
                . 'body, for example in a communal changing room, while swimming, or in '
                . 'close-fitting clothes?',
            'min' => 0, 'max' => 6, 'step' => 'any', 'default' => 0,
            'lo'  => 'Not at all', 'hi' => 'Markedly', 'precision' => 1,
            'subscale_max' => 6,
        ],

        // 29-30: Weight and height. Unit switch lb+in / kg+cm; stored canonical (kg, cm).
        29 => [
            'type'  => 'numeric',
            'stem'  => 'What is your weight at present? An approximate figure is fine.',
            'kind'  => 'weight',
            'units' => [ 'kg' => 'kg', 'lb' => 'lb' ],
            'min'   => 20.0, 'max' => 500.0, 'step' => 0.1,
        ],
        30 => [
            'type' => 'height',
            'stem' => 'What is your height?',
        ],
    ];

    /** No reverse-worded items. */
    public const REVERSE = [];

    /**
     * Subscale composition, Fairburn and Beglin (2008). Each subscale is the
     * mean of its constituent items, each normalized to a 0-6 equivalent via
     * its 'subscale_max'. Item 8 is shared between Shape and Weight, as in
     * the original.
     */
    public const SUBSCALES = [
        'restraint' => [ 'label' => 'Restraint',       'items' => [ 1, 2, 3, 4, 5 ] ],
        'eating'    => [ 'label' => 'Eating concern',  'items' => [ 7, 9, 19, 20, 21 ] ],
        'shape'     => [ 'label' => 'Shape concern',   'items' => [ 6, 8, 10, 11, 23, 26, 27, 28 ] ],
        'weight'    => [ 'label' => 'Weight concern',  'items' => [ 8, 12, 22, 24, 25 ] ],
    ];

    /** Behavioural-count item numbers, with score-key slugs. */
    public const BEHAVIORS = [
        13 => 'overeating_episodes',
        14 => 'loss_of_control_episodes',
        15 => 'objective_binge_days',
        16 => 'vomit_episodes',
        17 => 'laxative_episodes',
        18 => 'driven_exercise_episodes',
    ];

    /** Numeric-measurement item numbers, with canonical score-key slugs. */
    public const NUMERICS = [
        29 => 'weight_kg',
        30 => 'height_cm',
    ];

    /** Conversion factors to canonical units. */
    public const POUND_TO_KG = 0.45359237;
    public const INCH_TO_CM  = 2.54;

    /**
     * Canonicalize a numeric measurement to its stored unit.
     *
     * @param string $kind 'weight' or 'height'
     * @param float  $value the entered value
     * @param string $unit  'kg'|'lb' for weight, 'cm'|'in' for height
     * @return float canonical value: kg for weight, cm for height
     */
    public static function toCanonical( string $kind, float $value, string $unit ): float {
        if ( $kind === 'weight' ) {
            return $unit === 'lb' ? $value * self::POUND_TO_KG : $value;
        }
        if ( $kind === 'height' ) {
            return $unit === 'in' ? $value * self::INCH_TO_CM : $value;
        }
        return $value;
    }

    /**
     * Score a completed EDE-Q-PCP.
     *
     * For each subscale item, the raw value is normalized to a 0-6 equivalent
     * via value * 6 / subscale_max (clamped to 0-6); the subscale is the mean
     * of those normalized values; items the respondent skipped or marked
     * "Not sure" are dropped. The global score is the mean of the four
     * subscales (null if any subscale has no items answered). Behavioural
     * counts pass through as integers; weight and height are canonical
     * (kg, cm); BMI is derived when both are present.
     *
     * @param array $responses itemNumber => numeric value (canonical units for 29-30)
     * @param array $idkItems  item numbers the respondent marked "Not sure"
     * @return array score-array keyed for the dashboard
     */
    public static function scoreResponses( array $responses, array $idkItems = [] ): array {
        $out = [];

        // Subscale means: each item normalized to 0-6 via its subscale_max.
        $subMeans = [];
        foreach ( self::SUBSCALES as $key => $def ) {
            $sum = 0.0;
            $n = 0;
            foreach ( $def['items'] as $itemNum ) {
                if ( in_array( $itemNum, $idkItems, true ) ) {
                    continue;
                }
                if ( !isset( $responses[ $itemNum ] ) ) {
                    continue;
                }
                $itemData = self::ITEMS[ $itemNum ] ?? [];
                $subMax = (float)( $itemData['subscale_max'] ?? 6 );
                if ( $subMax <= 0 ) {
                    $subMax = 6.0;
                }
                $v = (float)$responses[ $itemNum ];
                $normalized = $v * 6.0 / $subMax;
                if ( $normalized < 0.0 ) {
                    $normalized = 0.0;
                }
                if ( $normalized > 6.0 ) {
                    $normalized = 6.0;
                }
                $sum += $normalized;
                $n++;
            }
            $mean = $n > 0 ? round( $sum / $n, 2 ) : null;
            $out[ 'subscale_' . $key ] = $mean;
            if ( $mean !== null ) {
                $subMeans[] = $mean;
            }
        }
        $out['total'] = count( $subMeans ) === count( self::SUBSCALES )
            ? round( array_sum( $subMeans ) / count( $subMeans ), 2 )
            : null;

        // Behavioural counts pass through as non-negative integers.
        foreach ( self::BEHAVIORS as $itemNum => $slug ) {
            if ( in_array( $itemNum, $idkItems, true ) || !isset( $responses[ $itemNum ] ) ) {
                $out[ $slug ] = null;
                continue;
            }
            $out[ $slug ] = max( 0, (int)$responses[ $itemNum ] );
        }

        // Canonical weight (kg) and height (cm), then BMI.
        foreach ( self::NUMERICS as $itemNum => $slug ) {
            if ( in_array( $itemNum, $idkItems, true ) || !isset( $responses[ $itemNum ] ) ) {
                $out[ $slug ] = null;
                continue;
            }
            $v = (float)$responses[ $itemNum ];
            $out[ $slug ] = $v > 0 ? round( $v, 2 ) : null;
        }
        $w = $out['weight_kg'] ?? null;
        $h = $out['height_cm'] ?? null;
        $out['bmi'] = ( $w !== null && $h !== null && $h > 0 )
            ? round( $w / ( ( $h / 100 ) ** 2 ), 1 )
            : null;

        // How much of the instrument was answered.
        $answered = 0;
        foreach ( array_keys( self::ITEMS ) as $itemNum ) {
            if ( !in_array( (int)$itemNum, $idkItems, true ) && isset( $responses[ $itemNum ] ) ) {
                $answered++;
            }
        }
        $out['answered'] = $answered;

        return $out;
    }

    /**
     * A gentle, non-diagnostic plain-language reading of the scores.
     *
     * @param array $scores the scoreResponses() output
     * @return array [ 'overall' => string, 'flags' => string[] ]
     */
    public static function interpret( array $scores ): array {
        $total = $scores['total'] ?? null;
        if ( $total === null ) {
            $overall = 'Not enough was answered to give an overall reading.';
        } elseif ( $total >= 4.0 ) {
            $overall = 'Across these items, you reported a high level of eating-pattern and '
                . 'body-image concerns over the past month. It may be worth talking this '
                . 'over with someone you trust or a clinician.';
        } elseif ( $total >= 2.5 ) {
            $overall = 'Across these items, you reported a moderate level of eating-pattern '
                . 'and body-image concerns over the past month.';
        } elseif ( $total >= 1.0 ) {
            $overall = 'Across these items, you reported a mild level of eating-pattern and '
                . 'body-image concerns over the past month.';
        } else {
            $overall = 'Across these items, you reported little eating-pattern or body-image '
                . 'concern over the past month.';
        }

        $flags = [];
        if ( !empty( $scores['vomit_episodes'] ) ) {
            $flags[] = 'Self-induced vomiting was reported.';
        }
        if ( !empty( $scores['laxative_episodes'] ) ) {
            $flags[] = 'Laxative use as weight control was reported.';
        }
        if ( !empty( $scores['driven_exercise_episodes'] ) ) {
            $flags[] = 'Driven or compulsive exercise was reported.';
        }
        $binges = (int)( $scores['objective_binge_days'] ?? 0 );
        if ( $binges >= 4 ) {
            $flags[] = 'Recurrent objective binge days were reported (four or more in a month).';
        }

        return [ 'overall' => $overall, 'flags' => $flags ];
    }
}
