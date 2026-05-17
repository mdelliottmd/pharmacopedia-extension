<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * WHOQOL-BREF reference data + interpretation bands.
 *
 * Sources:
 *   WHOQOL Group 1998 (Psychological Medicine 28(3):551-558)
 *   Skevington, Lotfy, & O'Connell 2004 (Quality of Life Research 13(2):299-310)
 *   Hawthorne, Herrman, & Murphy 2006 (Social Indicators Research 77(1):37-59),
 *   norms for the four 0-100 domain scores in a community Australian sample.
 *
 * Domain scores are reported on a 0-100 scale. There are no diagnostic
 * cutoffs; the bands below are descriptive only and are useful for
 * relating a single score to the distribution in general-population
 * samples.
 */
class WhoqolBrefNorms {

    /** Approximate community-sample reference (Hawthorne et al. 2006). */
    public const DOMAIN_TYPICAL_MEAN = [
        'PHY' => 73.5,
        'PSY' => 70.6,
        'SOC' => 71.5,
        'ENV' => 75.1,
        'OVR' => 70.0,
    ];

    /** Below 50 on a domain suggests substantial QoL impairment in that
     *  area. Below 25 is markedly low. Above 75 is generally high. */
    public const BAND_MARKEDLY_LOW = 25.0;
    public const BAND_LOW          = 50.0;
    public const BAND_HIGH         = 75.0;

    public const SUBSCALE_BLURBS = [
        'PHY' => [ 'Physical health',
            'Captures activities of daily living, energy / fatigue, mobility, '
            . 'pain & discomfort, sleep & rest, work capacity, and dependence '
            . 'on medication.' ],
        'PSY' => [ 'Psychological',
            'Captures positive feelings, thinking / learning / concentration, '
            . 'self-esteem, body image, and negative feelings.' ],
        'SOC' => [ 'Social relationships',
            'Captures personal relationships, social support, and sexual '
            . 'activity satisfaction.' ],
        'ENV' => [ 'Environment',
            'Captures financial resources, freedom and safety, health and '
            . 'social-care accessibility, home environment, opportunities '
            . 'for new information and leisure, physical environment quality, '
            . 'and transport.' ],
        'OVR' => [ 'Overall QoL + health',
            'The two general-facet items (Q1 perceived QoL, Q2 satisfaction '
            . 'with health), averaged and rescaled to 0-100.' ],
    ];

    public static function classify( ?float $domainScore ): string {
        if ( $domainScore === null ) return 'incomplete';
        if ( $domainScore >= self::BAND_HIGH )          return 'high';
        if ( $domainScore <= self::BAND_MARKEDLY_LOW )  return 'markedly_low';
        if ( $domainScore <= self::BAND_LOW )           return 'low';
        return 'typical';
    }
}
