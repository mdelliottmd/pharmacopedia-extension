<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * ASRS-v1.1 6-Question Screener.
 *
 * The Adult ADHD Self-Report Scale v1.1, 6-Question Screener
 * (ASRS-V1.1 6QS): a validated brief screener for adult ADHD symptoms,
 * developed with the World Health Organization.
 *
 * REPRODUCED VERBATIM. This is a licensed instrument. Its licence (the
 * NYU Technology Opportunities portal, verified by home-claude) permits
 * a free electronic version, including commercial and non-clinical use,
 * on two conditions: the instrument is reproduced WITHOUT MODIFICATION,
 * and the required attribution is displayed wherever it appears. So the
 * six items, the five-point response scale, the per-item screening
 * thresholds, and the 4-of-6 scoring rule are exactly as published. Do
 * not reword, rescale, or restructure any of them; this is not the
 * AMAAS pattern. The item text, intro, instruction, score note, and
 * attribution constants below are transcribed from the official
 * instrument document 6QASRSEng.pdf.
 *
 * Rights: New York University and President and Fellows of Harvard
 * College. All rights reserved.
 *
 * Primary citation: Kessler RC et al. The World Health Organization
 * Adult ADHD Self-Report Scale (ASRS): a short screening scale for use
 * in the general population. Psychol Med. 2005;35(2):245-256.
 * PMID 15841682.
 */
class Asrs {
    public const KEY         = 'asrs';
    public const NAME        = 'ASRS Screener';
    public const FULL_NAME   = 'Adult ADHD Self-Report Scale v1.1, 6-Question Screener';
    public const CITATION    = 'Kessler RC et al. 2005, Psychol Med 35(2):245-256, PMID 15841682';
    public const DESCRIPTION = 'The validated 6-question screener for adult ADHD symptoms, from the Adult ADHD Self-Report Scale v1.1 (developed with the World Health Organization). A screening starting point, not a diagnosis.';
    public const WARNING     = '';
    public const PAGE_SIZE   = 6;

    /**
     * Official intro and disclaimer text, verbatim from 6QASRSEng.pdf.
     * Three paragraphs, separated by blank lines.
     */
    public const INTRO =
        "Many adults have been living with Adult Attention-Deficit/Hyperactivity Disorder "
        . "(Adult ADHD) and don't recognize it. Why? Because its symptoms are often mistaken "
        . "for a stressful life. If you've felt this type of frustration most of your life, you "
        . "may have Adult ADHD \u{2013} a condition your doctor can help diagnose and treat.\n\n"
        . "The following questionnaire can be used as a starting point to help you recognize "
        . "the signs/symptoms of Adult ADHD but is not meant to replace consultation with a "
        . "trained healthcare professional. An accurate diagnosis can only be made through a "
        . "clinical evaluation. Regardless of the questionnaire results, if you have concerns "
        . "about diagnosis and treatment of Adult ADHD, please discuss your concerns with your "
        . "physician.\n\n"
        . "This Adult Self-Report Scale-V1.1 (ASRS-V1.1) Screener is intended for people aged "
        . "18 years or older.";

    /** Official instruction line, verbatim from 6QASRSEng.pdf. */
    public const INSTRUCTION =
        "Check the box that best describes how you have felt and conducted yourself over the "
        . "past 6 months. Please give the completed questionnaire to your healthcare "
        . "professional during your next appointment to discuss the results.";

    /** Official scoring note, verbatim from 6QASRSEng.pdf. */
    public const SCORE_NOTE =
        "Add the number of checkmarks that appear in the darkly shaded area. Four (4) or more "
        . "checkmarks indicate that your symptoms may be consistent with Adult ADHD. It may be "
        . "beneficial for you to talk with your healthcare provider about an evaluation.";

    /**
     * Mandatory attribution, verbatim from the 6QASRSEng.pdf instrument
     * footer. The licence requires this be displayed wherever the
     * Screener appears (the take form and the report).
     */
    public const ATTRIBUTION =
        "The 6-question Adult Self-Report Scale-Version1.1 (ASRS-V1.1) Screener is a subset of "
        . "the 18-question Adult ADHD Self-Report Scale-Version1.1 (Adult ASRS-V1.1) Symptom "
        . "Checklist. \u{00A9} New York University and President and Fellows of Harvard "
        . "College. All rights reserved";

    /**
     * The five-point response scale, exact labels from the official
     * form. The instrument is administered as discrete choices (a radio
     * row per item), NOT a slider. Values 0-4 are the coding used for
     * the screening-threshold check; the official form itself prints
     * only the labels.
     */
    public const RESPONSE_LABELS = [
        0 => 'Never',
        1 => 'Rarely',
        2 => 'Sometimes',
        3 => 'Often',
        4 => 'Very Often',
    ];

    /** The six screener items, verbatim from 6QASRSEng.pdf. */
    public const ITEMS = [
        1 => 'How often do you have trouble wrapping up the final details of a project, once the challenging parts have been done?',
        2 => 'How often do you have difficulty getting things in order when you have to do a task that requires organization?',
        3 => 'How often do you have problems remembering appointments or obligations?',
        4 => 'When you have a task that requires a lot of thought, how often do you avoid or delay getting started?',
        5 => 'How often do you fidget or squirm with your hands or feet when you have to sit down for a long time?',
        6 => 'How often do you feel overly active and compelled to do things, like you were driven by a motor?',
    ];

    /** The ASRS has no reverse-keyed items. */
    public const REVERSE = [];

    /** The ASRS 6Q Screener has no subscales; present for framework conformance. */
    public const SUBSCALES = [];

    /**
     * Per-item screening threshold: the minimum response value (on the
     * 0-4 coding) that falls in the official "darkly shaded area".
     * Items 1-3 count from Sometimes up; items 4-6 from Often up. This
     * is the instrument's own published scoring, not a house choice.
     */
    public const SCREEN_THRESHOLD = [
        1 => 2, 2 => 2, 3 => 2,
        4 => 3, 5 => 3, 6 => 3,
    ];

    /** Four or more screening-range responses is a positive screen. */
    public const POSITIVE_COUNT = 4;

    /**
     * Score a set of responses (item number => 0-4 value). Returns
     * numeric values only:
     *   shaded_count  responses that fell in the screening range (0-6)
     *   positive      1 if shaded_count >= 4, else 0
     *   complete      1 if all six items answered, else 0
     */
    public static function scoreResponses( array $responses ): array {
        $count = 0;
        $answered = 0;
        foreach ( self::SCREEN_THRESHOLD as $n => $threshold ) {
            if ( isset( $responses[$n] ) && $responses[$n] !== '' && $responses[$n] !== null ) {
                $answered++;
                if ( (int)$responses[$n] >= $threshold ) {
                    $count++;
                }
            }
        }
        return [
            'shaded_count' => $count,
            'positive'     => ( $count >= self::POSITIVE_COUNT ) ? 1 : 0,
            'complete'     => ( $answered === 6 ) ? 1 : 0,
        ];
    }

    /** Plain-language screening result. A screen, never a diagnosis. */
    public static function interpret( array $scores ): string {
        if ( empty( $scores['complete'] ) ) {
            return 'Incomplete. Answer all 6 questions for a screening result.';
        }
        $count = (int)( $scores['shaded_count'] ?? 0 );
        if ( !empty( $scores['positive'] ) ) {
            return $count . ' of 6 responses fell in the screening range. Four or more is a '
                . 'positive screen: these symptoms may be consistent with adult ADHD, and an '
                . 'evaluation with a healthcare provider may be worthwhile. A screen, not a '
                . 'diagnosis.';
        }
        return $count . ' of 6 responses fell in the screening range, below the four-or-more '
            . 'positive-screen threshold. A screen, not a diagnosis.';
    }
}
