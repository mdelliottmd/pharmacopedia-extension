<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * CATI — Comprehensive Autistic Trait Inventory
 * English, M. C. W., Gignac, G. E., Visser, T. A. W., Whitehouse, A. J. O.,
 * Enns, J. T., & Maybery, M. T. (2021). The Comprehensive Autistic Trait
 * Inventory (CATI): development and validation of a new measure of autistic
 * traits in the general population. Molecular Autism, 12(1), 37.
 *
 * 42 items, 5-point Likert (Definitely Disagree .. Definitely Agree).
 * 6 subscales of 7 items each:
 *   SOC = Social Interactions
 *   COM = Communication
 *   CAM = Social Camouflage
 *   FLX = Cognitive (In)Flexibility
 *   REG = Self-regulatory Behaviours
 *   SEN = Sensory Sensitivity
 *
 * Reverse-keyed items (score = 6 - response): 8, 15, 19, 23, 28
 * Subscale range: 7–35. Total range: 42–210.
 *
 * Item text + scoring key sourced from CATI v1.1 official downloads at
 * https://www.cati-autism.com/download (2026-05-17).
 */
class Cati {
    public const KEY        = 'cati';
    public const NAME       = 'CATI-PCP';
    public const FULL_NAME  = 'Comprehensive Autistic Trait Inventory';
    public const CITATION   = 'English et al. 2021 (Mol Autism 12(1):37). PMID 34593033.';
    public const DESCRIPTION = '42-item self-report measure of six dimensions of autistic traits: Social Interactions, Communication, Social Camouflage, Cognitive (In)Flexibility, Self-regulatory Behaviours, and Sensory Sensitivity. ~5–10 minutes.  (Adapted from CATI)';
    public const WARNING    = '';
    public const PAGE_SIZE  = 42; // single page

    public const RESPONSE_LABELS = [
        1 => 'Definitely Disagree',
        2 => 'Somewhat Disagree',
        3 => 'Neither Agree nor Disagree',
        4 => 'Somewhat Agree',
        5 => 'Definitely Agree',
    ];

    public const ITEMS = [
        1  => 'I often find myself fiddling or playing repetitively with objects (e.g. clicking pens).',
        2  => 'I like to stick to certain routines for every-day tasks.',
        3  => 'I expend a lot of mental energy trying to fit in with others.',
        4  => 'I am very sensitive to bright lighting.',
        5  => 'There are certain activities that I always choose to do the same way, every time.',
        6  => 'Sometimes I watch people interacting and try to copy them when I need to socialise.',
        7  => 'I often rock when sitting in a chair.',
        8  => 'I generally enjoy social events.',
        9  => 'I look for strategies and ways to appear more sociable.',
        10 => 'In social situations, I try to avoid interactions with other people.',
        11 => 'There are times when I feel that my senses are overloaded.',
        12 => 'There are certain objects that I fiddle or play with that can help me calm down or collect my thoughts.',
        13 => 'Reading non-verbal cues (e.g. facial expressions, body language) is difficult for me.',
        14 => 'I like my belongings to be sorted in certain ways and will spend time making sure they are that way.',
        15 => 'Social interaction is easy for me.',
        16 => 'When interacting with other people, I spend a lot of effort monitoring how I am coming across.',
        17 => 'I find social interactions stressful.',
        18 => 'I am very sensitive to touch.',
        19 => 'I can tell how people feel from their facial expressions.',
        20 => 'I have a tendency to pace or move around in a repetitive path.',
        21 => 'I feel discomfort when prevented from completing a particular routine.',
        22 => 'I rely on a set of scripts when I talk with people.',
        23 => 'I find it easy to sense what someone else is feeling.',
        24 => 'I am very sensitive to particular tastes (e.g. salty, sour, spicy, or sweet).',
        25 => 'I engage in certain repetitive actions when I feel stressed.',
        26 => 'I rarely use non-verbal cues in my interactions with others.',
        27 => 'I often insist on doing things in a certain way, or re-doing things until they are \'just right\'.',
        28 => 'I feel confident or capable when meeting new people.',
        29 => 'Before engaging in a social situation, I will create a script to follow where possible.',
        30 => 'Social occasions are often challenging for me.',
        31 => 'Sometimes the presence of a smell makes it hard for me to focus on anything else.',
        32 => 'There are certain repetitive actions that others consider to be \'characteristic\' of me (e.g. stroking my hair).',
        33 => 'Metaphors or \'figures of speech\' often confuse me.',
        34 => 'It annoys me when plans I have made are changed.',
        35 => 'I find it difficult to make new friends.',
        36 => 'I react strongly to unexpected loud noises.',
        37 => 'I have difficulty understanding someone else\'s point-of-view.',
        38 => 'I like to arrange items in rows or patterns.',
        39 => 'I try to follow certain \'rules\' in order to get by in social situations.',
        40 => 'I am sensitive to flickering lights.',
        41 => 'I have certain habits that I find difficult to stop (e.g. biting/tearing nails, pulling strands of hair).',
        42 => 'I have difficulty understanding the \'unspoken rules\' of social situations.',
    ];

    // Reverse-scored items (score = 6 - response on the 1–5 scale)
    public const REVERSE = [ 8, 15, 19, 23, 28 ];

    public const SUBSCALES = [
        'SOC' => [ 'label' => 'Social Interactions',         'items' => [ 8, 10, 15, 17, 28, 30, 35 ] ],
        'COM' => [ 'label' => 'Communication',               'items' => [ 13, 19, 23, 26, 33, 37, 42 ] ],
        'CAM' => [ 'label' => 'Social Camouflage',           'items' => [ 3, 6, 9, 16, 22, 29, 39 ] ],
        'FLX' => [ 'label' => 'Cognitive (In)Flexibility',   'items' => [ 2, 5, 14, 21, 27, 34, 38 ] ],
        'REG' => [ 'label' => 'Self-regulatory Behaviours',  'items' => [ 1, 7, 12, 20, 25, 32, 41 ] ],
        'SEN' => [ 'label' => 'Sensory Sensitivity',         'items' => [ 4, 11, 18, 24, 31, 36, 40 ] ],
    ];

    /**
     * Returns ['subscale_SOC' => sum (7-35), …, 'total' => sum (42-210)].
     * Reverse-coded items are inverted (6 - response) before summing.
     */
    public static function scoreResponses( array $responses ): array {
        $apply = function ( int $itemN, $raw ) {
            $v = (float)$raw;
            return in_array( $itemN, self::REVERSE, true ) ? ( 6 - $v ) : $v;
        };
        $scores = [];
        $allVals = [];
        foreach ( self::SUBSCALES as $k => $def ) {
            $vals = [];
            foreach ( $def['items'] as $itemN ) {
                if ( isset( $responses[ $itemN ] ) && $responses[ $itemN ] !== '' && $responses[ $itemN ] !== null ) {
                    $vals[] = $apply( $itemN, $responses[ $itemN ] );
                }
            }
            $scores[ 'subscale_' . $k ] = $vals ? round( array_sum( $vals ), 2 ) : null;
        }
        foreach ( self::ITEMS as $i => $_ ) {
            if ( isset( $responses[ $i ] ) && $responses[ $i ] !== '' && $responses[ $i ] !== null ) {
                $allVals[] = $apply( $i, $responses[ $i ] );
            }
        }
        $scores['total'] = $allVals ? round( array_sum( $allVals ), 2 ) : null;
        return $scores;
    }

    public static function interpret( array $scores ): string {
        $t = $scores['total'] ?? null;
        if ( $t === null ) return 'Incomplete.';
        // English 2021 reported group means: autistic ~112–118, non-autistic ~73–78.
        if ( $t >= 100 ) {
            return "Total {$t} / 210 — within the range typical of autistic samples in English 2021 (mean ≈ 115).";
        }
        if ( $t >= 80 ) {
            return "Total {$t} / 210 — between the typical autistic (≈ 115) and non-autistic (≈ 75) group means.";
        }
        return "Total {$t} / 210 — within the range typical of non-autistic samples in English 2021 (mean ≈ 75).";
    }
}
