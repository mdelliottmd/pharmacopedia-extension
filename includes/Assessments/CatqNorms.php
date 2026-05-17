<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * CAT-Q normative reference data and suggested cutoffs.
 *
 * Source 1: Hull, L., et al. (2019). Development and Validation of the
 *   Camouflaging Autistic Traits Questionnaire (CAT-Q).
 *   J Autism Dev Disord 49(3):819-833.
 *
 * Source 2: NeurodivUrgent (neurodivurgent.health/results). Community
 *   reference page that publishes recalibrated suggestive thresholds:
 *     Total ≥ 110 (vs Hull's original ≥ 100)
 *     Compensation ≥ 35
 *     Assimilation ≥ 40
 *     Masking: non-discriminating, no cutoff suggested.
 *
 * IMPORTANT: there is no published diagnostic-accuracy data for CAT-Q
 * (no sensitivity / specificity / AUC against a diagnostic ground truth).
 * Cutoffs below are "suggestive" reference points, not diagnostic
 * thresholds. The report consuming this data is intentionally more cautious
 * than the CATI report for this reason.
 */
class CatqNorms {

    /** NeurodivUrgent recalibrated cutoffs (current Pharmacopedia default). */
    public const CUTOFF_TOTAL_NEURODIV = 110;
    public const CUTOFF_TOTAL_HULL2019 = 100;
    public const CUTOFFS_SUBSCALE = [
        'CO'  => 35,    // Compensation
        'ASS' => 40,    // Assimilation
        // Masking: no published suggestive cutoff (Hull 2019 found Masking
        // to be the least discriminating factor). UI displays "-" instead.
    ];

    /** Plain-English description of each subscale, for the report. */
    public const SUBSCALE_BLURBS = [
        'CO'  => [ 'Compensation',
            'Strategies that substitute for autistic-typical processing of '
            . 'social information: explicit study of social rules, copying '
            . 'observed behaviour, scripted conversation. High scores '
            . 'indicate heavy reliance on these strategies.' ],
        'MSK' => [ 'Masking',
            'Active suppression of autistic-typical expression: monitoring '
            . 'body language, rehearsing facial expressions, hiding stims '
            . 'and other autistic behaviours from observers.' ],
        'ASS' => [ 'Assimilation',
            'The experience of social interaction as performance: feeling '
            . 'unable to be oneself in company, "putting on an act" to fit '
            . 'in, dependence on others to socialise. Hull 2019 found this '
            . 'subscale most strongly associated with mental-health '
            . 'burden.' ],
    ];

    /** Subscale-key → max possible (sum of items × max-likert 7). */
    public const SUBSCALE_MAX = [
        'CO'  => 56,    // 8 items × 7
        'MSK' => 56,    // 8 items × 7
        'ASS' => 63,    // 9 items × 7
    ];
    public const TOTAL_MAX = 175;

    /** Subscale-key → min possible (sum of items × min-likert 1). */
    public const SUBSCALE_MIN = [
        'CO'  => 8,
        'MSK' => 8,
        'ASS' => 9,
    ];
    public const TOTAL_MIN = 25;

    /**
     * Resolve whether a score exceeds the suggestive cutoff for a given
     * scale. Returns true / false / null (null = no cutoff defined, e.g. MSK).
     */
    public static function exceedsCutoff( string $scaleKey, float $score ): ?bool {
        if ( $scaleKey === 'total' ) {
            return $score >= self::CUTOFF_TOTAL_NEURODIV;
        }
        if ( !isset( self::CUTOFFS_SUBSCALE[ $scaleKey ] ) ) return null;
        return $score >= self::CUTOFFS_SUBSCALE[ $scaleKey ];
    }
}
