<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * Ess - ESS-PCP, a brief daytime-sleepiness check.
 *
 * Eight everyday situations, each quiet and low in stimulation. For each, the
 * respondent rates on one 0-to-3 slider how likely they would be to doze off
 * or fall asleep in it, as opposed to merely feeling tired, going by their
 * life in recent weeks.
 *
 * ORIGINAL instrument. The situations were authored from the daytime-
 * sleepiness construct itself, not transcribed from the Epworth Sleepiness
 * Scale (ESS); ESS-PCP is inspired by that instrument, not a copy of it.
 * The 0-to-3 response format and the cutoff of 10 are borrowed from the
 * published ESS (Johns, 1991) as a reference frame only: this is an original,
 * uncalibrated instrument, so the cutoff is orientation, not a validated
 * threshold.
 *
 * Not validated, not diagnostic. Locally brewed.
 *
 * @license GPL-3.0-or-later
 */
class Ess {

    public const KEY         = 'ess';
    public const NAME        = 'ESS-PCP';
    public const FULL_NAME   = 'Daytime-sleepiness check (inspired by the Epworth Sleepiness Scale)';
    public const CITATION    = 'Original instrument, Pharmacopedia 2026, inspired by the Epworth '
        . 'Sleepiness Scale (ESS; Johns MW. Sleep. 1991;14(6):540-545). The 0-to-3 response '
        . 'format and the cutoff of 10 are borrowed from the ESS as a reference frame. '
        . 'Not validated.';
    public const DESCRIPTION = 'A brief check on how easily you doze off during the day. For each '
        . 'of eight quiet, passive situations, you rate on a single slider how likely you would '
        . 'be to fall asleep in it, not just to feel tired, going by your life in recent weeks. '
        . 'Higher means sleepier. It is a structured snapshot you can take again over time. If a '
        . 'situation does not come up in your life, or you cannot say, tick "Not sure".  '
        . '(inspired by the Epworth Sleepiness Scale; locally brewed, not [yet] validated)';
    public const WARNING     = 'ESS-PCP is an informal check of daytime sleepiness. It is not a '
        . 'diagnostic instrument and it has no validated cutoff. Persistent daytime sleepiness '
        . 'can point to insufficient sleep or to a sleep disorder such as sleep apnea or '
        . 'narcolepsy, and it is worth raising with a clinician. If you find yourself dozing '
        . 'while driving, or doing anything else where falling asleep would be dangerous, treat '
        . 'that as urgent and stop until you have been assessed.';

    /** The 0-to-3 dozing-likelihood scale. The slider is continuous; these bound it. */
    public const SCALE_MIN = 0;
    public const SCALE_MAX = 3;

    /** Per-subscale slugs in display order. Empty for single-scale
     *  instruments. renderInlineAssessment iterates SUBSCALES for the
     *  per-subscale table; empty means "just show the total".
     *  (L2 follow-up per server-claude 2026-05-23 audit; mirrors the
     *  Hyd::SUBSCALES patch.) */
    public const SUBSCALES = [];

    /** Slider anchor text, shown at the two ends of every item. */
    public const ANCHOR_LOW  = 'Would never doze';
    public const ANCHOR_HIGH = 'High chance of dozing';

    /** The four reference points of the original 0-to-3 scale. The slider is continuous. */
    public const RESPONSE_LABELS = [
        0 => 'Would never doze',
        1 => 'Slight chance of dozing',
        2 => 'Moderate chance of dozing',
        3 => 'High chance of dozing',
    ];

    /**
     * The 8 items. Each names a quiet, passive, everyday situation; the
     * respondent rates the chance of dozing off in it, 0 (would never) to
     * 3 (high chance). Higher is sleepier.
     */
    public const ITEMS = [
        1 => 'Reading something quietly, such as a book, a long article, or your phone.',
        2 => 'Watching television or a film at home.',
        3 => 'Sitting through a meeting, a lecture, or a presentation.',
        4 => 'Riding as a passenger on a long, uneventful car or bus journey.',
        5 => 'Lying down to rest in the afternoon when the day allows it.',
        6 => 'Sitting and listening as someone talks with you one to one.',
        7 => 'Sitting quietly in the hour or so after a midday meal.',
        8 => 'Waiting with little to do, such as in a waiting room or a slow queue.',
    ];

    /** No reverse-worded items: every item runs would-never (0) to high-chance (3). */
    public const REVERSE = [];

    /**
     * Bands keyed by inclusive lower bound (descending). The cutoff of 10 is
     * the conventional ESS threshold for elevated daytime sleepiness, borrowed
     * as a reference frame; ESS-PCP is itself uncalibrated.
     */
    public const SEVERITY_BANDS = [
        [ 10.0, 'Elevated' ],
        [ 0.0,  'Lower range' ],
    ];

    /** Footnote shown wherever the band is displayed. */
    public const BANDS_NOTE = 'The cutoff of 10 is the conventional Epworth Sleepiness Scale '
        . 'threshold (Johns, 1991). ESS-PCP borrows it as a reference frame only; it is an '
        . 'original, uncalibrated instrument, so the cutoff is orientation, not a validated '
        . 'threshold.';

    /**
     * Score a set of 0-to-3 responses.
     *
     * The Epworth total is a sum across all eight items (0-24). When items are
     * unanswered or marked "Not sure", the sum of the answered items is scaled
     * up to the full eight so the total stays comparable to the cutoff of 10.
     *
     * @param array $responses itemNumber (1-8) => numeric 0-3
     * @param array $idkItems item numbers the respondent marked "Not sure"
     * @return array 'total' => sum scaled to all 8 items (0-24, 1 dp) or null,
     *   'answered' => how many of the 8 items were answered
     */
    public static function scoreResponses( array $responses, array $idkItems = [] ): array {
        $sum = 0.0;
        $n = 0;
        foreach ( array_keys( self::ITEMS ) as $itemNum ) {
            $itemNum = (int)$itemNum;
            if ( in_array( $itemNum, $idkItems, true ) || !isset( $responses[ $itemNum ] ) ) {
                continue;
            }
            $v = (float)$responses[ $itemNum ];
            if ( $v < self::SCALE_MIN ) {
                $v = (float)self::SCALE_MIN;
            }
            if ( $v > self::SCALE_MAX ) {
                $v = (float)self::SCALE_MAX;
            }
            $sum += $v;
            $n++;
        }
        $itemCount = count( self::ITEMS );
        return [
            'total'    => $n > 0 ? round( $sum * $itemCount / $n, 1 ) : null,
            'answered' => $n,
        ];
    }

    /** The band for a 0-to-24 total, or null. See SEVERITY_BANDS. */
    public static function severityBand( ?float $total ): ?string {
        if ( $total === null ) {
            return null;
        }
        foreach ( self::SEVERITY_BANDS as $b ) {
            if ( $total >= $b[0] ) {
                return $b[1];
            }
        }
        return null;
    }

    /**
     * A gentle, non-diagnostic plain-language reading of a check-in.
     *
     * @param array $scores the scoreResponses() output
     * @return array [ 'band' => string|null, 'overall' => string ]
     */
    public static function interpret( array $scores ): array {
        $total = isset( $scores['total'] ) && $scores['total'] !== null
            ? (float)$scores['total'] : null;
        $answered = (int)( $scores['answered'] ?? 0 );
        if ( $total === null ) {
            return [
                'band'    => null,
                'overall' => 'Nothing was answered, so there is no snapshot to read yet.',
            ];
        }
        $band = self::severityBand( $total );
        $partial = $answered < count( self::ITEMS )
            ? ' This is estimated from the ' . $answered . ' item'
                . ( $answered === 1 ? '' : 's' ) . ' you answered.'
            : '';
        if ( $band === 'Elevated' ) {
            $overall = 'Across these situations, you reported a fair amount of daytime '
                . 'sleepiness recently. A total around 10 or above is where the Epworth scale '
                . 'flags elevated sleepiness, so it may be worth looking at how much sleep you '
                . 'are getting and talking it over with a clinician.' . $partial;
        } else {
            $overall = 'Across these situations, you reported relatively little daytime '
                . 'sleepiness recently.' . $partial;
        }
        return [ 'band' => $band, 'overall' => $overall ];
    }
}
