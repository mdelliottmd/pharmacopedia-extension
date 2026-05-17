<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * BPNS reference data + descriptive bands.
 *
 * Source: Gagné (2003) and replications (e.g., Johnston & Finney 2010,
 * Contemporary Educational Psychology 35(4):280-296). The BPNS does not
 * have published diagnostic cutoffs; the descriptive bands below reflect
 * sample-mean clustering rather than clinical thresholds.
 */
class BpnsNorms {

    /** Subscale means hover around 5.0-5.5 (out of 7) in general adult
     *  samples. Below 4.0 is suggestive of unmet needs in that domain. */
    public const NEED_THRESHOLD_LOW  = 4.0;
    public const NEED_THRESHOLD_HIGH = 5.5;

    public const SUBSCALE_BLURBS = [
        'AUT' => [ 'Autonomy',
            'The experience of acting volitionally, of doing things because '
            . 'they fit who you are rather than because you have to. Low '
            . 'scores often track with feeling pressured or controlled.' ],
        'COM' => [ 'Competence',
            'The experience of effectance, of feeling capable in your '
            . 'pursuits. Low scores often track with frustration, '
            . 'self-doubt, or a sense of being underused.' ],
        'REL' => [ 'Relatedness',
            'The experience of meaningful connection with others. Low '
            . 'scores often track with social isolation or feeling '
            . 'unimportant to the people around you.' ],
    ];

    public const SUBSCALE_MAX = [ 'AUT' => 7.0, 'COM' => 7.0, 'REL' => 7.0 ];
    public const SUBSCALE_MIN = [ 'AUT' => 1.0, 'COM' => 1.0, 'REL' => 1.0 ];
    public const TOTAL_MAX = 7.0;
    public const TOTAL_MIN = 1.0;

    public static function classifySubscale( ?float $mean ): string {
        if ( $mean === null ) return 'incomplete';
        if ( $mean >= self::NEED_THRESHOLD_HIGH ) return 'high';
        if ( $mean <= self::NEED_THRESHOLD_LOW )  return 'low';
        return 'typical';
    }
}
