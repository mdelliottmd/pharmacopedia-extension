<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * PID-5-BF reference data and suggestive cutoffs.
 *
 * Sources:
 *   - Krueger, R. F., Derringer, J., Markon, K. E., Watson, D., & Skodol, A. E.
 *     (2013). The Personality Inventory for DSM-5, Brief Form (PID-5-BF).
 *     Personality Disorders: Theory, Research, and Treatment, 4(3), 264-269.
 *   - APA's official PID-5-BF reporting guidance: mean >= 2.0 on a domain
 *     suggests elevated trait expression worth clinical attention.
 *
 * Domain ranges: each item is 0-3 Likert; per-domain score is the MEAN over
 * the domain's 5 items, so each subscale and the total span 0.0-3.0.
 *
 * Cutoffs here are descriptive, not diagnostic. No sensitivity/specificity
 * for distinguishing PD-cases from controls has been formally published for
 * the brief form (the parent PID-5 has more validation work).
 */
class Pid5bfNorms {

    /** APA / Krueger 2013 reporting threshold for elevated trait expression. */
    public const CUTOFF_MEAN = 2.0;

    /** Subscale-key, label, plain-English blurb. */
    public const SUBSCALE_BLURBS = [
        'NA'  => [ 'Negative Affectivity',
            'High levels and reactivity of negative emotions, especially anxiety, '
            . 'fear, worry, and emotional lability. Maps to the Internalizing '
            . 'spectrum and to high Neuroticism in the Big Five.' ],
        'DET' => [ 'Detachment',
            'Avoidance of social and emotional contact and intimacy. Reduced '
            . 'experience of positive emotions. Maps to low Extraversion in '
            . 'the Big Five and to social withdrawal across PD models.' ],
        'ANT' => [ 'Antagonism',
            'Manipulative, grandiose, callous, and attention-seeking behaviour '
            . 'in interpersonal contexts. Maps to low Agreeableness in the '
            . 'Big Five and to the externalising-narcissistic spectrum.' ],
        'DIS' => [ 'Disinhibition',
            'Acting on impulse without regard for consequences; difficulty '
            . 'planning, persisting, and tolerating frustration. Maps to '
            . 'low Conscientiousness in the Big Five.' ],
        'PSY' => [ 'Psychoticism',
            'Unusual perceptions, beliefs, and patterns of thought. May '
            . 'include depersonalisation, derealisation, magical thinking, '
            . 'or perceptual oddities. Maps to schizotypy and the '
            . 'psychotic-spectrum dimension.' ],
    ];

    /** Subscale-key, max possible (item-mean), min possible. */
    public const SUBSCALE_MAX = [
        'NA' => 3.0, 'DET' => 3.0, 'ANT' => 3.0, 'DIS' => 3.0, 'PSY' => 3.0,
    ];
    public const TOTAL_MAX = 3.0;
    public const TOTAL_MIN = 0.0;

    /** True if score >= cutoff (2.0); null only if score itself is null. */
    public static function exceedsCutoff( ?float $score ): ?bool {
        if ( $score === null ) return null;
        return $score >= self::CUTOFF_MEAN;
    }
}
