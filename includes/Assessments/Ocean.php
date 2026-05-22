<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * Ocean - the Big Five personality dimensions, measured with the 10-item
 * Big Five Inventory (BFI-10).
 *
 * Adapted ("-PCP"): on this wiki each item is answered on a 0-100 slider
 * ("how much does this apply to you") rather than the original five-point
 * Likert, so trait scores are continuous 0-100 means rather than summed
 * Likert points. Server-side scoring here mirrors the client-side BFI-10
 * compute used on Special:MyProfile.
 *
 * Five trait subscales (O C E A N), two items each. Reverse-worded items
 * are flipped (100 - value) before averaging.
 *
 * @license GPL-3.0-or-later
 */
class Ocean {

    public const KEY         = 'ocean';
    public const NAME        = 'BFI-10-PCP';
    public const FULL_NAME   = 'Big Five Inventory-10 (OCEAN)';
    public const CITATION    = 'Rammstedt B, John OP. Measuring personality in one minute or less: '
        . 'A 10-item short version of the Big Five Inventory. J Res Pers. 2007;41(1):203-212.';
    public const DESCRIPTION = 'The five broad personality dimensions - Openness, Conscientiousness, '
        . 'Extraversion, Agreeableness, Neuroticism - measured with a brief 10-item inventory, two '
        . 'items per dimension.  (Adapted from BFI-10)';

    /** The 0-100 percent-applicable scale. The slider is continuous; these bound it. */
    public const SCALE_MIN = 0;
    public const SCALE_MAX = 100;

    /** Vestigial: the slider form path does not read RESPONSE_LABELS. Kept as the two anchors. */
    public const RESPONSE_LABELS = [
        0   => 'Disagree',
        100 => 'Agree',
    ];

    /** The 10 item stems. Standard BFI-10 wording (Rammstedt & John 2007). */
    public const ITEMS = [
        1  => 'I see myself as someone who is reserved.',
        2  => 'I see myself as someone who is generally trusting.',
        3  => 'I see myself as someone who tends to be lazy.',
        4  => 'I see myself as someone who is relaxed, handles stress well.',
        5  => 'I see myself as someone who has few artistic interests.',
        6  => 'I see myself as someone who is outgoing, sociable.',
        7  => 'I see myself as someone who tends to find fault with others.',
        8  => 'I see myself as someone who does a thorough job.',
        9  => 'I see myself as someone who gets nervous easily.',
        10 => 'I see myself as someone who has an active imagination.',
    ];

    /** Reverse-worded items: direction value = 100 - raw. */
    public const REVERSE = [ 1, 3, 4, 5, 7 ];

    /** Trait subscales: trait letter => its two item numbers. */
    public const SUBSCALES = [
        'O' => [ 5, 10 ],
        'C' => [ 3, 8 ],
        'E' => [ 1, 6 ],
        'A' => [ 2, 7 ],
        'N' => [ 4, 9 ],
    ];

    /** Human-readable trait names. */
    public const TRAIT_NAMES = [
        'O' => 'Openness',
        'C' => 'Conscientiousness',
        'E' => 'Extraversion',
        'A' => 'Agreeableness',
        'N' => 'Neuroticism',
    ];

    /**
     * Score a set of 0-100 slider responses.
     *
     * Each trait is the mean of its two (reverse-corrected) item values.
     * This matches the client-side BFI-10 compute on Special:MyProfile;
     * items the respondent marked "Not sure" are simply absent, and a
     * trait with no answered items returns null.
     *
     * @param array $responses itemNumber (1-10) => numeric 0-100
     * @return array trait letter => 0-100 mean (int), or null
     */
    public static function scoreResponses( array $responses ): array {
        $out = [];
        foreach ( self::SUBSCALES as $trait => $items ) {
            $sum = 0.0;
            $n = 0;
            foreach ( $items as $itemNum ) {
                if ( !isset( $responses[ $itemNum ] ) ) {
                    continue;
                }
                $v = (float)$responses[ $itemNum ];
                if ( $v < self::SCALE_MIN ) {
                    $v = (float)self::SCALE_MIN;
                }
                if ( $v > self::SCALE_MAX ) {
                    $v = (float)self::SCALE_MAX;
                }
                if ( in_array( $itemNum, self::REVERSE, true ) ) {
                    $v = 100.0 - $v;
                }
                $sum += $v;
                $n++;
            }
            $out[ $trait ] = $n > 0 ? (int)round( $sum / $n ) : null;
        }
        return $out;
    }
}
