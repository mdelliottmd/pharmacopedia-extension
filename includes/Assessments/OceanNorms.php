<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * Reference data + plain-language descriptions for the Big Five (OCEAN)
 * personality trait model.
 *
 * Sources:
 *   - Goldberg, L. R. (1990). An alternative "description of personality":
 *     The Big-Five factor structure. JPSP, 59(6), 1216-1229.
 *   - John, O. P., & Srivastava, S. (1999). The Big Five trait taxonomy.
 *     Handbook of Personality (2nd ed.), Guilford.
 *   - BFI-10: Rammstedt & John 2007 (J Research in Personality 41:203-212).
 *
 * Scoring on this wiki uses a 0-100 scale per trait. Bands here are
 * descriptive (low / average / high), not clinical thresholds. The
 * Big Five model itself does not yield diagnostic categories.
 */
class OceanNorms {

    public const BAND_LOW  = 30.0;
    public const BAND_HIGH = 70.0;

    /** Trait-key, label, brief one-line description. */
    public const TRAIT_BLURBS = [
        'O' => [ 'Openness to Experience',
            'Curiosity, imagination, willingness to engage with novel ideas, '
            . 'art, and unconventional values. Tracks aesthetic sensitivity, '
            . 'intellectual curiosity, and tolerance for ambiguity.' ],
        'C' => [ 'Conscientiousness',
            'Self-discipline, organisation, planning, dependability, '
            . 'achievement striving. Tracks productivity, persistence, and '
            . 'attention to detail.' ],
        'E' => [ 'Extraversion',
            'Energy drawn from social engagement, assertiveness, positive '
            . 'affect, sociability. Tracks talkativeness, warmth toward '
            . 'others, and seeking out activity.' ],
        'A' => [ 'Agreeableness',
            'Cooperation, empathy, trust, accommodation of others. Tracks '
            . 'tendency to prioritise group harmony, forgive, and assume '
            . "the best of others' intentions." ],
        'N' => [ 'Neuroticism',
            'Emotional reactivity, susceptibility to negative affect '
            . '(anxiety, sadness, irritability), stress sensitivity. Tracks '
            . 'how strongly and easily negative emotions are felt.' ],
    ];

    /**
     * Per-trait, per-band descriptive sentences. Format:
     *   [TRAIT][BAND] = "..."
     * Bands: 'low' (< 30), 'avg' (30-70), 'high' (> 70).
     */
    public const TRAIT_LEVELS = [
        'O' => [
            'low'  => 'A preference for the conventional, familiar, and concrete. Practical and grounded; less drawn to abstract speculation or aesthetic novelty.',
            'avg' => 'A balance between conventional and exploratory tendencies. Open to new ideas in some domains and content with the familiar in others.',
            'high' => 'Strong intellectual curiosity, aesthetic sensitivity, and openness to unconventional ideas. Drawn to art, theory, and novel experience.',
        ],
        'C' => [
            'low'  => 'Spontaneous, flexible, more comfortable with loose structure than detailed planning. May find rigid schedules constraining.',
            'avg' => 'Reasonably organised and reliable but not strictly methodical. Plans when it matters; improvises when it does not.',
            'high' => 'Highly organised, disciplined, and reliable. Plans, follows through, and pays attention to detail.',
        ],
        'E' => [
            'low'  => 'Reserved, drained by extended social contact, prefers quieter, lower-stimulation environments. Comfortable in solitude.',
            'avg' => 'Sociable in some contexts and reserved in others. Enjoys company but also values time alone.',
            'high' => 'Energised by social engagement, talkative, warm, and outgoing. Seeks activity and the company of others.',
        ],
        'A' => [
            'low'  => 'Direct, candid, sceptical of others\' motives at times. Comfortable with conflict; prioritises honesty over harmony.',
            'avg' => 'Balances cooperation with self-advocacy. Trusts but verifies; helps where it makes sense without overextending.',
            'high' => 'Empathic, cooperative, trusting, and accommodating. Prioritises group harmony and the wellbeing of others.',
        ],
        'N' => [
            'low'  => 'Emotionally stable and calm under pressure. Recovers quickly from setbacks; less reactive to stress and ambiguity.',
            'avg' => 'Typical emotional reactivity. Feels negative affect when prompted by circumstance but is not chronically destabilised.',
            'high' => 'Strong susceptibility to negative affect: anxiety, sadness, irritability. Stress is felt intensely; recovery from setbacks takes effort.',
        ],
    ];

    public static function classify( ?float $score ): string {
        if ( $score === null ) return 'incomplete';
        if ( $score < self::BAND_LOW )  return 'low';
        if ( $score > self::BAND_HIGH ) return 'high';
        return 'avg';
    }

    public static function bandLabel( string $band ): string {
        return [ 'low' => 'Low', 'avg' => 'Average', 'high' => 'High' ][ $band ] ?? 'Unknown';
    }

    /** Plain-English description for a trait at a band. */
    public static function descriptionFor( string $trait, string $band ): string {
        return self::TRAIT_LEVELS[ $trait ][ $band ] ?? '';
    }
}
