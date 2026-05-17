<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * BPNS, Basic Psychological Need Satisfaction Scale, "in general" version.
 *
 * Gagné, M. (2003). The role of autonomy support and autonomy orientation in
 * prosocial behavior engagement. Motivation and Emotion, 27(3), 199-223.
 * Items adapted by Deci & Ryan from the original work-domain BPNS, presented
 * at selfdeterminationtheory.org as the canonical general-life version.
 *
 * 21 items, 7-point Likert (1 not at all true to 7 very true). Three
 * subscales rooted in Self-Determination Theory:
 *   Autonomy (7 items), Competence (6 items), Relatedness (8 items).
 * Reverse-keyed items: 3, 4, 7, 11, 15, 16, 18, 19, 20.
 *
 * Item content reconstructed from the open SDT instrument page. Verify
 * against the official version at selfdeterminationtheory.org before
 * clinical use.
 */
class Bpns {
    public const KEY        = 'bpns';
    public const NAME       = 'BPNS';
    public const FULL_NAME  = 'Basic Psychological Need Satisfaction Scale';
    public const CITATION   = 'Deci & Ryan; Gagné 2003 (Motivation & Emotion 27(3):199-223)';
    public const DESCRIPTION = 'Self-determination-theory measure of how satisfied your three basic psychological needs are in everyday life: Autonomy (acting volitionally), Competence (feeling effective), Relatedness (feeling connected). 21 items, ~5 minutes.';
    public const WARNING    = '';
    public const PAGE_SIZE  = 21;

    public const RESPONSE_LABELS = [
        1 => 'Not at all true',
        2 => 'Mostly not true',
        3 => 'Slightly not true',
        4 => 'Somewhat true',
        5 => 'Slightly true',
        6 => 'Mostly true',
        7 => 'Very true',
    ];

    public const ITEMS = [
        1  => 'I feel like I am free to decide for myself how to live my life.',
        2  => 'I really like the people I interact with.',
        3  => 'Often, I do not feel very competent.',
        4  => 'I feel pressured in my life.',
        5  => 'People I know tell me I am good at what I do.',
        6  => 'I get along with people I come into contact with.',
        7  => "I pretty much keep to myself and don't have a lot of social contacts.",
        8  => 'I generally feel free to express my ideas and opinions.',
        9  => 'I consider the people I regularly interact with to be my friends.',
        10 => 'I have been able to learn interesting new skills recently.',
        11 => 'In my daily life, I frequently have to do what I am told.',
        12 => 'People in my life care about me.',
        13 => 'Most days I feel a sense of accomplishment from what I do.',
        14 => 'People I interact with on a daily basis tend to take my feelings into consideration.',
        15 => 'In my life I do not get much of a chance to show how capable I am.',
        16 => 'There are not many people that I am close to.',
        17 => 'I feel like I can pretty much be myself in my daily situations.',
        18 => 'The people I interact with regularly do not seem to like me much.',
        19 => 'I often do not feel very capable.',
        20 => 'There is not much opportunity for me to decide for myself how to do things in my daily life.',
        21 => 'People are generally pretty friendly towards me.',
    ];

    /** Reverse-scored items (score = 8 - response). */
    public const REVERSE = [ 3, 4, 7, 11, 15, 16, 18, 19, 20 ];

    public const SUBSCALES = [
        'AUT' => [ 'label' => 'Autonomy',    'items' => [ 1, 4, 8, 11, 14, 17, 20 ] ],
        'COM' => [ 'label' => 'Competence',  'items' => [ 3, 5, 10, 13, 15, 19 ] ],
        'REL' => [ 'label' => 'Relatedness', 'items' => [ 2, 6, 7, 9, 12, 16, 18, 21 ] ],
    ];

    /**
     * Returns subscale means (1-7), total mean (1-7).
     * Reverse-keyed items are inverted before averaging.
     */
    public static function scoreResponses( array $responses ): array {
        $apply = function ( int $itemN, $raw ) {
            $v = (float)$raw;
            return in_array( $itemN, self::REVERSE, true ) ? ( 8 - $v ) : $v;
        };
        $scores = [];
        $allVals = [];
        foreach ( self::SUBSCALES as $k => $def ) {
            $vals = [];
            foreach ( $def['items'] as $itemN ) {
                if ( isset( $responses[ $itemN ] ) && $responses[ $itemN ] !== '' && $responses[ $itemN ] !== null ) {
                    $vals[]    = $apply( $itemN, $responses[ $itemN ] );
                    $allVals[] = $apply( $itemN, $responses[ $itemN ] );
                }
            }
            $scores[ 'subscale_' . $k ] = $vals ? round( array_sum( $vals ) / count( $vals ), 2 ) : null;
        }
        $scores['total'] = $allVals ? round( array_sum( $allVals ) / count( $allVals ), 2 ) : null;
        return $scores;
    }

    public static function interpret( array $scores ): string {
        $t = $scores['total'] ?? null;
        if ( $t === null ) return 'Incomplete.';
        if ( $t >= 5.5 ) return "Total mean {$t} / 7, generally high basic-need satisfaction.";
        if ( $t >= 4.0 ) return "Total mean {$t} / 7, moderate basic-need satisfaction.";
        return "Total mean {$t} / 7, lower basic-need satisfaction (autonomy, competence, or relatedness frustrated).";
    }
}
