<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * PID-5-BF — Personality Inventory for DSM-5, Brief Form
 * Krueger, R. F., Derringer, J., Markon, K. E., Watson, D., & Skodol, A. E. (2013).
 *
 * 25 items, 4-point Likert (0-3). Five maladaptive trait domains corresponding to
 * the DSM-5 Section III Alternative Model of Personality Disorders + ICD-11 PD model.
 *
 * Item content is the public APA release. Verify against the official PDF
 * (psychiatry.org / Krueger 2013) before clinical use.
 */
class Pid5bf {
    public const KEY        = 'pid5bf';
    public const NAME       = 'PID-5-BF';
    public const FULL_NAME  = 'Personality Inventory for DSM-5 — Brief Form';
    public const CITATION   = 'Krueger et al. 2013 (Personality Disorders, 4(3), 264-269)';
    public const DESCRIPTION = 'Five trait domains mapping to the DSM-5 Section III (Alternative Model) and ICD-11 PD trait model. 25 items, ~5 minutes.';
    public const WARNING    = '';
    public const PAGE_SIZE  = 25; // single page

    public const RESPONSE_LABELS = [
        0 => 'Very false or often false',
        1 => 'Sometimes or somewhat false',
        2 => 'Sometimes or somewhat true',
        3 => 'Very true or often true',
    ];

    public const ITEMS = [
        1  => 'People would describe me as reckless.',
        2  => 'I feel like I act totally on impulse.',
        3  => "Even though I know better, I can't stop making rash decisions.",
        4  => 'I often feel like nothing I do really matters.',
        5  => 'Others see me as irresponsible.',
        6  => "I'm not good at planning ahead.",
        7  => "My thoughts often don't make sense to others.",
        8  => 'I worry about almost everything.',
        9  => 'I get emotional easily, often for very little reason.',
        10 => 'I fear being alone in life more than anything else.',
        11 => "I get stuck on one way of doing things, even when it's clear it won't work.",
        12 => "I have seen things that weren't really there.",
        13 => 'I steer clear of romantic relationships.',
        14 => "I'm not interested in making friends.",
        15 => 'I get irritated easily by all sorts of things.',
        16 => "I don't like to get too close to people.",
        17 => "It's no big deal if I hurt other people's feelings.",
        18 => 'I rarely get enthusiastic about anything.',
        19 => 'I crave attention.',
        20 => 'I often have to deal with people who are less important than me.',
        21 => 'I often have thoughts that make sense to me but that other people say are strange.',
        22 => 'I use people to get what I want.',
        23 => 'I often "zone out" and then suddenly come to and realize that a lot of time has passed.',
        24 => 'Things around me often feel unreal, or more real than usual.',
        25 => 'It is easy for me to take advantage of others.',
    ];

    public const REVERSE = []; // No reverse-keyed items in PID-5-BF.

    public const SUBSCALES = [
        'NA'  => [ 'label' => 'Negative Affectivity', 'items' => [ 8, 9, 10, 11, 15 ] ],
        'DET' => [ 'label' => 'Detachment',           'items' => [ 4, 13, 14, 16, 18 ] ],
        'ANT' => [ 'label' => 'Antagonism',           'items' => [ 17, 19, 20, 22, 25 ] ],
        'DIS' => [ 'label' => 'Disinhibition',        'items' => [ 1, 2, 3, 5, 6 ] ],
        'PSY' => [ 'label' => 'Psychoticism',         'items' => [ 7, 12, 21, 23, 24 ] ],
    ];

    /**
     * Returns ['subscale_NA' => mean (0-3), …, 'total' => overall mean (0-3)].
     * Missing items are dropped from their subscale's mean.
     */
    public static function scoreResponses( array $responses ): array {
        $scores = [];
        $allVals = [];
        foreach ( self::SUBSCALES as $k => $def ) {
            $vals = [];
            foreach ( $def['items'] as $itemN ) {
                if ( isset( $responses[ $itemN ] ) && $responses[ $itemN ] !== '' && $responses[ $itemN ] !== null ) {
                    $vals[]    = (float)$responses[ $itemN ];
                    $allVals[] = (float)$responses[ $itemN ];
                }
            }
            $scores[ 'subscale_' . $k ] = $vals ? round( array_sum( $vals ) / count( $vals ), 2 ) : null;
        }
        $scores['total'] = $allVals ? round( array_sum( $allVals ) / count( $allVals ), 2 ) : null;
        return $scores;
    }

    public static function interpret( array $scores ): string {
        $flags = [];
        foreach ( self::SUBSCALES as $k => $def ) {
            $v = $scores[ 'subscale_' . $k ] ?? null;
            if ( $v !== null && $v >= 2.0 ) {
                $flags[] = $def['label'] . ' (mean ' . $v . ')';
            }
        }
        if ( !$flags ) return 'No domain exceeds the suggested clinical threshold of mean ≥ 2.0.';
        return 'Elevated (mean ≥ 2.0): ' . implode( ', ', $flags ) . '.';
    }
}
