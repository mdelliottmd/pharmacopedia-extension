<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * Hyd - "How Ya Doin?" (HYD-PCP), a brief wellbeing check-in.
 *
 * Eight everyday domains, each answered on one bipolar slider from -100
 * (really poorly) through 0 (so-so) to +100 (really well). Each item
 * carries its own time window, tuned to how fast that part of life
 * actually moves: mood over the last few days, exercise and social
 * connection over the last few weeks, the rest over about a week.
 *
 * Scoring is deliberately simple: every item is its own domain (there are
 * no multi-item subscales), and the overall figure is just the mean of
 * the domains answered. It is built to be taken again and again and
 * watched over time.
 *
 * Original instrument, locally brewed, not validated. Not diagnostic.
 *
 * @license GPL-3.0-or-later
 */
class Hyd {

    public const KEY         = 'hyd';
    public const NAME        = 'HYD-PCP';
    public const FULL_NAME   = 'How Ya Doin? (wellbeing check-in)';
    public const CITATION    = 'Original instrument, Pharmacopedia 2026. Locally brewed, not yet validated.';
    public const DESCRIPTION = 'A quick check-in on how you have been doing lately, across eight everyday '
        . 'parts of life. Each one is a single slider from really poorly, through so-so in the middle, to '
        . 'really well. There are no right answers and nothing here is diagnostic; it is a snapshot you '
        . 'can take again and watch over time. If an item does not apply or you cannot say, tick "Not '
        . 'sure".  (locally brewed. not [yet] validated)';
    public const WARNING     = 'HYD-PCP is an informal wellbeing check-in, not a clinical or diagnostic '
        . 'instrument. It has no norms and no validated cutoffs. Treat it as a structured way to notice '
        . 'how you have been doing, and to watch that change over time.';

    /** The bipolar scale. The slider is continuous; these bound it. 0 is neutral. */
    public const SCALE_MIN = -100;
    public const SCALE_MAX = 100;

    /** Slider anchor text, shown at the two ends of every item. */
    public const ANCHOR_LOW  = 'Really poorly';
    public const ANCHOR_HIGH = 'Really well';

    /** Vestigial: the slider form path does not read RESPONSE_LABELS. Kept as the two anchors. */
    public const RESPONSE_LABELS = [
        -100 => 'Really poorly',
        100  => 'Really well',
    ];

    /**
     * The eight items. Each is its own domain; each carries its own time
     * window in the stem (deliberately varied, not harmonized).
     */
    public const ITEMS = [
        1 => 'Over the last few days, how have you been feeling in yourself, overall?',
        2 => 'Over the past week, how well have you been functioning: looking after yourself, '
            . 'the people you love, and your responsibilities?',
        3 => 'How has your sleep been this past week?',
        4 => 'Over the past couple of weeks, how have movement and exercise been going for you?',
        5 => 'Over the last few weeks, how socially connected have you felt?',
        6 => 'How has your eating been this past week, in terms of nourishing yourself?',
        7 => 'How have your energy levels been this past week?',
        8 => 'Over the past week, how have you been doing with stress and feeling overwhelmed?',
    ];

    /** Item number => human domain label, for the per-domain results profile. */
    public const DOMAINS = [
        1 => 'Mood',
        2 => 'Functioning',
        3 => 'Sleep',
        4 => 'Movement',
        5 => 'Social connection',
        6 => 'Eating',
        7 => 'Energy',
        8 => 'Stress',
    ];

    /** Item number => short slug, used as the scores-array keys. */
    public const DOMAIN_SLUGS = [
        1 => 'mood',
        2 => 'functioning',
        3 => 'sleep',
        4 => 'movement',
        5 => 'social',
        6 => 'eating',
        7 => 'energy',
        8 => 'stress',
    ];

    /** No reverse-worded items: every domain runs really poorly (-100) to really well (+100). */
    public const REVERSE = [];

    /** No multi-item subscales: HYD-PCP scores live under DOMAIN_SLUGS keys directly
     *  (mood, functioning, sleep, ...). The shared MyProfile inline-assessment renderer
     *  iterates SUBSCALES for the per-subscale table; empty means "just show the total". */
    public const SUBSCALES = [];

    /**
     * Score a check-in.
     *
     * @param array $responses itemNumber (1-8) => numeric -100..100
     * @param array $idkItems item numbers the respondent marked "Not sure"
     * @return array per-domain slug => float|null, plus:
     *   'total'    => mean of the domains answered (-100..100), or null
     *   'answered' => how many of the 8 domains were answered
     */
    public static function scoreResponses( array $responses, array $idkItems = [] ): array {
        $out = [];
        $sum = 0.0;
        $n = 0;
        foreach ( self::DOMAIN_SLUGS as $itemNum => $slug ) {
            if ( in_array( $itemNum, $idkItems, true ) || !isset( $responses[ $itemNum ] ) ) {
                $out[ $slug ] = null;
                continue;
            }
            $v = (float)$responses[ $itemNum ];
            if ( $v < self::SCALE_MIN ) {
                $v = (float)self::SCALE_MIN;
            }
            if ( $v > self::SCALE_MAX ) {
                $v = (float)self::SCALE_MAX;
            }
            $out[ $slug ] = round( $v, 1 );
            $sum += $v;
            $n++;
        }
        $out['total']    = $n > 0 ? round( $sum / $n, 1 ) : null;
        $out['answered'] = $n;
        return $out;
    }

    /**
     * A gentle plain-language reading of a check-in. Deliberately modest:
     * this is a snapshot, not a verdict.
     *
     * @param array $scores the scoreResponses() output
     * @return array [ 'overall' => string, 'low_domains' => string[] ]
     */
    public static function interpret( array $scores ): array {
        $total = $scores['total'] ?? null;
        if ( $total === null ) {
            $overall = 'Nothing was answered, so there is no snapshot to read yet.';
        } elseif ( $total >= 40 ) {
            $overall = 'Taken together, you have been doing pretty well lately.';
        } elseif ( $total >= 10 ) {
            $overall = 'Taken together, things have been more good than not.';
        } elseif ( $total > -10 ) {
            $overall = 'Taken together, lately has been a mixed picture, somewhere in the middle.';
        } elseif ( $total > -40 ) {
            $overall = 'Taken together, this has been a harder stretch than not.';
        } else {
            $overall = 'Taken together, this looks like a genuinely rough stretch.';
        }
        $low = [];
        foreach ( self::DOMAIN_SLUGS as $itemNum => $slug ) {
            $v = $scores[ $slug ] ?? null;
            if ( $v !== null && $v <= -40 ) {
                $low[] = self::DOMAINS[ $itemNum ];
            }
        }
        return [ 'overall' => $overall, 'low_domains' => $low ];
    }
}
