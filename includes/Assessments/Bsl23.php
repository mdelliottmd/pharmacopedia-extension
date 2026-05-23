<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * Bsl23 - BSL-23-PCP, a brief borderline-spectrum symptom check-in.
 *
 * Twenty-three first-person symptom statements covering the borderline
 * personality construct: affective instability, emptiness, identity
 * disturbance, self-directed hostility and shame, dissociation, distrust,
 * fear of abandonment, disconnection, loss of control, and self-harm urges.
 * Each item is answered on one 0-to-4 slider, from "not at all" to "very
 * strongly", for the past week.
 *
 * ORIGINAL instrument. The items were authored from the borderline
 * construct itself, not transcribed from the Borderline Symptom List
 * (BSL-23); BSL-23-PCP is inspired by that instrument, not a copy of it.
 * The severity bands are borrowed from the published BSL-23 bands
 * (Kleindienst, Jungkunz and Bohus, 2020) as a reference frame only: this
 * is an original, uncalibrated instrument, so a band is orientation, not a
 * validated cutoff.
 *
 * NOTE: item 18 asks about urges to self-harm. When BSL-23-PCP is sent to
 * an outside respondent through Special:RespondToAssessment, that take-flow
 * should surface crisis-support resources. Tracked as a follow-up.
 *
 * Not validated, not diagnostic. Locally brewed.
 *
 * @license GPL-3.0-or-later
 */
class Bsl23 {

    public const KEY         = 'bsl23';
    public const NAME        = 'BSL-23-PCP';
    public const FULL_NAME   = 'Borderline-spectrum symptom check (inspired by the BSL-23)';
    public const CITATION    = 'Original instrument, Pharmacopedia 2026, inspired by the short '
        . 'Borderline Symptom List (BSL-23; Bohus M et al. Psychopathology. 2009;42(1):32-39). '
        . 'Severity bands after Kleindienst N, Jungkunz M, Bohus M. Borderline Personal Disord '
        . 'Emot Dysregul. 2020;7:11. Not validated.';
    public const DESCRIPTION = 'A brief check-in on borderline-spectrum experiences over the past '
        . 'week, across twenty-three items: mood, emptiness, identity, self-image, dissociation, '
        . 'trust, closeness, control, and more. Each is a single slider from "not at all" to '
        . '"very strongly". Nothing here is a diagnosis; it is a structured snapshot you can take '
        . 'again over time. If an item does not apply or you cannot say, tick "Not sure".  '
        . '(inspired by the BSL-23; locally brewed, not [yet] validated)';
    public const WARNING     = 'BSL-23-PCP is an informal check of borderline-spectrum symptoms. '
        . 'It is not a diagnostic instrument and not a crisis tool: it has no norms and no '
        . 'validated cutoffs, and a borderline diagnosis is made by a clinician, not a '
        . 'questionnaire. If you are having thoughts of harming yourself, please reach out for '
        . 'support now. In the US you can call or text 988, the Suicide and Crisis Lifeline; '
        . 'elsewhere, contact a local crisis line or a health professional.';

    /** The 0-to-4 symptom-intensity scale. The slider is continuous; these bound it. */
    public const SCALE_MIN = 0;
    public const SCALE_MAX = 4;

    /** Slider anchor text, shown at the two ends of every item. */
    public const ANCHOR_LOW  = 'Not at all';
    public const ANCHOR_HIGH = 'Very strongly';

    /** Vestigial: the slider form path does not read RESPONSE_LABELS. Kept as the two anchors. */
    public const RESPONSE_LABELS = [
        0 => 'Not at all',
        4 => 'Very strongly',
    ];

    /**
     * The 23 items. First-person symptom statements for the past week,
     * answered 0 (not at all) to 4 (very strongly); higher is more symptom.
     * Domains are interleaved so a respondent is not given a run of
     * same-theme items. Item 18 is the self-harm item.
     */
    public const ITEMS = [
        1  => 'My moods shifted quickly and sharply, sometimes within the same day.',
        2  => 'I felt empty inside.',
        3  => 'I was unsure who I really am.',
        4  => 'I felt worthless.',
        5  => 'I was afraid that someone I care about would leave me.',
        6  => 'I felt detached from myself, as if observing my own life from outside.',
        7  => 'I felt overwhelmed by the intensity of my own emotions.',
        8  => 'I felt unable to trust the people around me.',
        9  => 'I felt disgust or hatred toward myself.',
        10 => 'I felt emotionally numb, as if I could not feel much of anything.',
        11 => 'I struggled to control my impulses.',
        12 => 'I felt completely alone, even when other people were around me.',
        13 => 'I felt a deep emotional pain that was hard to put into words.',
        14 => 'My sense of who I am seemed to change depending on who I was with.',
        15 => 'I was overcome by shame.',
        16 => 'I felt that other people might be against me or wish me harm.',
        17 => 'I felt rejected or unwanted.',
        18 => 'I had urges to hurt myself.',
        19 => 'I felt like a fundamentally bad person.',
        20 => 'The world around me felt unreal or distant.',
        21 => 'I had the urge to punish myself.',
        22 => 'I felt sudden, intense anger.',
        23 => 'I felt unable to control what I was doing or feeling.',
    ];

    /** No reverse-worded items: every item runs not-at-all (0) to very-strongly (4). */
    public const REVERSE = [];

    /**
     * Severity bands, as published for the BSL-23 by Kleindienst, Jungkunz
     * and Bohus (2020), keyed by inclusive lower bound (descending order).
     * Borrowed as a reference frame; BSL-23-PCP is itself uncalibrated.
     */
    public const SEVERITY_BANDS = [
        [ 3.47, 'Extremely high' ],
        [ 2.67, 'Very high' ],
        [ 1.87, 'High' ],
        [ 1.07, 'Moderate' ],
        [ 0.28, 'Mild' ],
        [ 0.0,  'None or low' ],
    ];

    /** Footnote shown wherever a severity band is displayed. */
    public const BANDS_NOTE = 'Severity bands are the published BSL-23 bands (Kleindienst, '
        . 'Jungkunz and Bohus, 2020). BSL-23-PCP borrows them as a reference frame only; it is '
        . 'an original, uncalibrated instrument, so a band is orientation, not a validated cutoff.';

    /**
     * Score a set of 0-to-4 responses.
     *
     * @param array $responses itemNumber (1-23) => numeric 0-4
     * @param array $idkItems item numbers the respondent marked "Not sure"
     * @return array 'total' => mean of the items answered (0-4, 2 dp) or null,
     *   'answered' => how many of the 23 items were answered
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
        return [
            'total'    => $n > 0 ? round( $sum / $n, 2 ) : null,
            'answered' => $n,
        ];
    }

    /** The severity band for a mean total, or null. See SEVERITY_BANDS. */
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
        $band = self::severityBand( $total );
        if ( $total === null ) {
            return [
                'band'    => null,
                'overall' => 'Nothing was answered, so there is no snapshot to read yet.',
            ];
        }
        switch ( $band ) {
            case 'None or low':
                $overall = 'Across these items, you reported little of these experiences '
                    . 'this past week.';
                break;
            case 'Mild':
                $overall = 'Across these items, you reported a mild level of these '
                    . 'experiences this past week.';
                break;
            case 'Moderate':
                $overall = 'Across these items, you reported a moderate level of these '
                    . 'experiences this past week.';
                break;
            case 'High':
                $overall = 'Across these items, you reported a high level of these '
                    . 'experiences this past week. It may be worth talking this over with '
                    . 'someone you trust or a professional.';
                break;
            default:
                $overall = 'Across these items, you reported a very high level of these '
                    . 'experiences this past week. If the week has been hard to bear, please '
                    . 'consider reaching out to someone you trust or a professional; you do '
                    . 'not have to sit with it alone.';
                break;
        }
        return [ 'band' => $band, 'overall' => $overall ];
    }
}
