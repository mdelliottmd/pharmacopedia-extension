<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * CAT-Q — Camouflaging Autistic Traits Questionnaire
 * Hull, L., Mandy, W., Lai, M-C., et al. (2019). Development and Validation of the
 * Camouflaging Autistic Traits Questionnaire (CAT-Q). Journal of Autism and
 * Developmental Disorders, 49(3), 819-833.
 *
 * 25 items, 7-point Likert (1-7). Three factors: Compensation, Masking, Assimilation.
 * Items 3, 12, 19, 22 are reverse-scored.
 *
 * Item content is from the open-access publication. Verify against the original
 * before clinical use.
 */
class Catq {
    public const KEY        = 'catq';
    public const NAME       = 'CAT-Q-PCP';
    public const FULL_NAME  = 'Camouflaging Autistic Traits Questionnaire';
    public const CITATION   = 'Hull et al. 2019 (J Autism Dev Disord 49(3):819-833)';
    public const DESCRIPTION = 'Measures social camouflaging strategies often used by autistic adults: Compensation, Masking, and Assimilation. 25 items, ~5–10 minutes.  (Adapted from CAT-Q)';
    public const WARNING    = '';
    public const PAGE_SIZE  = 25; // single page

    public const RESPONSE_LABELS = [
        1 => 'Strongly disagree',
        2 => 'Disagree',
        3 => 'Somewhat disagree',
        4 => 'Neither agree nor disagree',
        5 => 'Somewhat agree',
        6 => 'Agree',
        7 => 'Strongly agree',
    ];

    public const ITEMS = [
        1  => 'When I am interacting with someone, I deliberately copy their body language or facial expressions.',
        2  => 'I monitor my body language or facial expressions so that I appear relaxed.',
        3  => 'I rarely feel the need to put on an act in order to get through a social situation.',
        4  => 'I have developed a script to follow in social situations (for example, a list of questions or topics of conversation).',
        5  => 'I will repeat phrases that I have heard others say in the exact same way that I first heard them.',
        6  => 'I adjust my body language or facial expressions so that I appear interested by the person I am interacting with.',
        7  => "In social situations, I feel like I'm 'performing' rather than being myself.",
        8  => 'In my own social interactions, I use behaviours that I have learned from watching other people interacting.',
        9  => 'I always think about the impression I make on other people.',
        10 => 'I need the support of other people in order to socialise.',
        11 => 'I practice my facial expressions and body language to make sure they look natural.',
        12 => "I don't feel the need to make eye contact with other people if I don't want to.",
        13 => 'I have to force myself to interact with people when I am in social situations.',
        14 => 'I have tried to improve my understanding of social skills by watching other people.',
        15 => 'I monitor my body language or facial expressions so that I appear interested by the person I am interacting with.',
        16 => 'I avoid interacting with others.',
        17 => "My interactions with other people feel like a 'performance'.",
        18 => 'I have researched the rules of social interactions (for example, by studying psychology or reading books on human behaviour) to improve my own social skills.',
        19 => 'I am always aware of the impression I make on other people.',
        20 => 'I feel free to be myself when I am with other people.',
        21 => 'I learn how people use their bodies and faces to interact by watching television or films, or by reading fiction.',
        22 => 'I do not pay attention to my facial expression or body language in social interactions.',
        23 => 'In social interactions, I do not pay attention to what my face or body are doing.',
        24 => 'In social situations, I feel like I am pretending to be "normal".',
        25 => 'I adjust my body language or facial expressions so that I appear interested by the person I am interacting with.',
    ];

    // Reverse-scored items (score = 8 - response)
    public const REVERSE = [ 3, 12, 19, 22 ];

    public const SUBSCALES = [
        'CO'  => [ 'label' => 'Compensation', 'items' => [ 1, 4, 5, 8, 11, 14, 18, 21 ] ],
        'MSK' => [ 'label' => 'Masking',      'items' => [ 2, 6, 9, 15, 19, 22, 23, 25 ] ],
        'ASS' => [ 'label' => 'Assimilation', 'items' => [ 3, 7, 10, 12, 13, 16, 17, 20, 24 ] ],
    ];

    /**
     * Returns ['subscale_CO' => sum (8-56), …, 'total' => sum (25-175)].
     * Reverse-coded items are inverted (8 - response) before summing.
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
        if ( $t >= 100 ) {
            return "Total {$t} / 175 — consistent with significant camouflaging (Hull 2019 suggested threshold ≈ 100).";
        }
        return "Total {$t} / 175 — below typical camouflaging threshold (≈ 100).";
    }
}
