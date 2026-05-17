<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * MBTI — Myers-Briggs Type Indicator (Jungian Type)
 *
 * The 16 Myers-Briggs types arise from 4 bipolar continuous dimensions:
 *   E (Extraversion) ↔ I (Introversion)
 *   S (Sensing)      ↔ N (Intuition)
 *   T (Thinking)     ↔ F (Feeling)
 *   J (Judging)      ↔ P (Perceiving)
 *
 * Per the user's "no lumping, only precision" directive, this wiki treats
 * MBTI dimensionally: each user has a continuous position on each of 4 axes,
 * and the 4-letter type is just a label for which side of midpoint each
 * slider sits on. Self-assignment via 4 dichotomy sliders is the primary input;
 * the 32 OEJTS items below offer a "compute MBTI from your responses" path.
 *
 * Item content is the Open Extended Jungian Type Scales 1.2 (Eric Jorgenson,
 * 2014), available publicly at https://openpsychometrics.org/tests/OEJTS/ —
 * an open-source alternative to the proprietary MBTI. Items are 5-point
 * bipolar scales. The exact OEJTS scoring key (item→dichotomy mapping +
 * directionality) is not published; the mapping below is content-based,
 * informed by the loadings table in Appendix A of OEJTS development docs,
 * and can be re-validated by anyone who has the original scoring rubric.
 *
 * Type names use the historical Myers/Keirsey labels (Architect, Healer,
 * Champion, etc.); the alternative NERIS/16personalities names (Architect/
 * Logician/Mediator/Campaigner/etc.) appear in parentheses where they differ.
 *
 * Cognitive function stacks per type follow Jung's original framework as
 * elaborated by Beebe (1984) and presented in Myers et al. 1998 (MBTI Manual
 * 3rd ed.). The eight functions: Te, Ti, Fe, Fi, Se, Si, Ne, Ni.
 */
class Mbti {
    public const KEY        = 'mbti';
    public const NAME       = 'MBTI';
    public const FULL_NAME  = 'Myers-Briggs / Jungian Type Indicator';
    public const CITATION   = 'Myers & Briggs 1985; Jung 1921; OEJTS items: Jorgenson 2014.';
    public const DESCRIPTION = 'Four bipolar personality dimensions (E↔I, S↔N, T↔F, J↔P) yielding 16 types when collapsed. Treated here dimensionally — your scores are continuous positions, not discrete categories.';
    public const WARNING    = '';
    public const PAGE_SIZE  = 32;

    /** The 4 dichotomies, in canonical order. */
    public const DICHOTOMIES = [
        'EI' => [ 'left' => 'E', 'left_name' => 'Extraversion',  'right' => 'I', 'right_name' => 'Introversion' ],
        'SN' => [ 'left' => 'S', 'left_name' => 'Sensing',       'right' => 'N', 'right_name' => 'Intuition'    ],
        'TF' => [ 'left' => 'T', 'left_name' => 'Thinking',      'right' => 'F', 'right_name' => 'Feeling'      ],
        'JP' => [ 'left' => 'J', 'left_name' => 'Judging',       'right' => 'P', 'right_name' => 'Perceiving'   ],
    ];

    /**
     * Sliders use range -2 to +2 (positive = right pole letter).
     * Score thresholds: |score| < 0.5 = balanced; 0.5–1.0 = slight; 1.0–1.5 = clear; 1.5+ = strong.
     */
    public const DICH_MIN = -2.0;
    public const DICH_MAX = 2.0;

    /** 16 types: 4-letter key → [popular name, brief descriptor]. */
    public const TYPES = [
        'ISTJ' => [ 'Inspector (Logistician)',      'Practical, fact-minded, dependable. Drawn to clear duty and stable systems.' ],
        'ISFJ' => [ 'Protector (Defender)',         'Quietly devoted, conscientious caretakers. Hold tradition and shelter the people they love.' ],
        'INFJ' => [ 'Counselor (Advocate)',         'Insightful, principled, visionary about people. Move quietly toward meaningful long-term goals.' ],
        'INTJ' => [ 'Mastermind (Architect)',       'Strategic system-builders. Independent, long-horizon, sceptical of authority for its own sake.' ],
        'ISTP' => [ 'Crafter (Virtuoso)',           'Tactical problem-solver, hands-on. Quiet observer who acts decisively when it matters.' ],
        'ISFP' => [ 'Composer (Adventurer)',        'Gentle aesthetes; values-driven. Express themselves through what they do rather than what they say.' ],
        'INFP' => [ 'Healer (Mediator)',            'Idealistic, deeply private, attuned to authenticity. Quietly committed to causes that matter to them.' ],
        'INTP' => [ 'Architect (Logician)',         'Theory-builders. Detached and rigorous in thought; restless with received wisdom.' ],
        'ESTP' => [ 'Promoter (Entrepreneur)',      'Adaptable, observant, fast-moving in the concrete present. Thrive on engagement with the world.' ],
        'ESFP' => [ 'Performer (Entertainer)',      'Warm, spontaneous, sensory. Bring playful energy to whoever they\'re with.' ],
        'ENFP' => [ 'Champion (Campaigner)',        'Enthusiastic, possibility-oriented connectors. Move toward what excites them and the people they love.' ],
        'ENTP' => [ 'Visionary (Debater)',          'Inventive, argument-loving, idea-generating. Comfortable across many domains and disinclined to settle.' ],
        'ESTJ' => [ 'Supervisor (Executive)',       'Organizers and enforcers of practical standards. Direct, hardworking, results-focused.' ],
        'ESFJ' => [ 'Provider (Consul)',            'Warm, conscientious, group-oriented. Attentive to what others need and to social harmony.' ],
        'ENFJ' => [ 'Teacher (Protagonist)',        'Charismatic and people-shaping. Mobilize others toward a vision of who they could become.' ],
        'ENTJ' => [ 'Field Marshal (Commander)',    'Strategic, decisive, naturally take command. Build systems and drive them forward.' ],
    ];

    /**
     * Cognitive function stack per type — dominant, auxiliary, tertiary, inferior.
     * Notation: Te=ext. Thinking, Ti=int. Thinking, Fe=ext. Feeling, Fi=int. Feeling,
     *           Se=ext. Sensing, Si=int. Sensing, Ne=ext. Intuition, Ni=int. Intuition.
     */
    public const FUNCTIONS = [
        'ISTJ' => [ 'Si', 'Te', 'Fi', 'Ne' ],
        'ISFJ' => [ 'Si', 'Fe', 'Ti', 'Ne' ],
        'INFJ' => [ 'Ni', 'Fe', 'Ti', 'Se' ],
        'INTJ' => [ 'Ni', 'Te', 'Fi', 'Se' ],
        'ISTP' => [ 'Ti', 'Se', 'Ni', 'Fe' ],
        'ISFP' => [ 'Fi', 'Se', 'Ni', 'Te' ],
        'INFP' => [ 'Fi', 'Ne', 'Si', 'Te' ],
        'INTP' => [ 'Ti', 'Ne', 'Si', 'Fe' ],
        'ESTP' => [ 'Se', 'Ti', 'Fe', 'Ni' ],
        'ESFP' => [ 'Se', 'Fi', 'Te', 'Ni' ],
        'ENFP' => [ 'Ne', 'Fi', 'Te', 'Si' ],
        'ENTP' => [ 'Ne', 'Ti', 'Fe', 'Si' ],
        'ESTJ' => [ 'Te', 'Si', 'Ne', 'Fi' ],
        'ESFJ' => [ 'Fe', 'Si', 'Ne', 'Ti' ],
        'ENFJ' => [ 'Fe', 'Ni', 'Se', 'Ti' ],
        'ENTJ' => [ 'Te', 'Ni', 'Se', 'Fi' ],
    ];

    public const FUNCTION_NAMES = [
        'Te' => 'Extraverted Thinking',  'Ti' => 'Introverted Thinking',
        'Fe' => 'Extraverted Feeling',   'Fi' => 'Introverted Feeling',
        'Se' => 'Extraverted Sensing',   'Si' => 'Introverted Sensing',
        'Ne' => 'Extraverted Intuition', 'Ni' => 'Introverted Intuition',
    ];

    /**
     * 32 OEJTS bipolar items, 8 per dichotomy. Each item is:
     *   [ left_phrase, right_phrase, dichotomy, right_pole ]
     *
     * - left/right_phrase: the two ends of the 5-point bipolar slider
     * - dichotomy: 'EI' | 'SN' | 'TF' | 'JP'
     * - right_pole: which letter the right-phrase aligns with
     *   (e.g. 'I' means picking the right phrase pushes the user toward Introversion)
     *
     * Scoring: response 1-5 (1 = leftmost, 3 = neutral, 5 = rightmost).
     * Each item contributes (response - 3) × ±1 to its dichotomy total
     * (sign depends on whether right_pole matches DICHOTOMIES[X]['right']).
     * Total is averaged over 8 items to land in -2..+2 range.
     */
    public const ITEMS = [
        1  => [ 'makes lists',                                            'relies on memory',                                          'JP', 'P' ],
        2  => [ 'sceptical',                                              'wants to believe',                                          'TF', 'F' ],
        3  => [ 'bored by time alone',                                    'needs time alone',                                          'EI', 'I' ],
        4  => [ 'accepts things as they are',                             'unsatisfied with the ways things are',                      'SN', 'N' ],
        5  => [ 'keeps a clean room',                                     'just puts stuff where ever',                                'JP', 'P' ],
        6  => [ 'thinks "robotic" is an insult',                          'strives to have a mechanical mind',                         'TF', 'T' ],
        7  => [ 'energetic',                                              'mellow',                                                    'EI', 'I' ],
        8  => [ 'prefer to take multiple choice tests',                   'prefer essay answers',                                      'SN', 'N' ],
        9  => [ 'chaotic',                                                'organized',                                                 'JP', 'J' ],
        10 => [ 'easily hurt',                                            'thick-skinned',                                             'TF', 'T' ],
        11 => [ 'works best in groups',                                   'works best alone',                                          'EI', 'I' ],
        12 => [ 'focused on the present',                                 'focused on the future',                                     'SN', 'N' ],
        13 => [ 'plans far ahead',                                        'plans at the last minute',                                  'JP', 'P' ],
        14 => [ 'wants people\'s respect',                                'wants their love',                                          'TF', 'F' ],
        15 => [ 'gets worn out by parties',                               'gets fired up by parties',                                  'EI', 'E' ],
        16 => [ 'fits in',                                                'stands out',                                                'SN', 'N' ],
        17 => [ 'keeps options open',                                     'commits',                                                   'JP', 'J' ],
        18 => [ 'wants to be good at fixing things',                      'wants to be good at fixing people',                         'TF', 'F' ],
        19 => [ 'talks more',                                             'listens more',                                              'EI', 'I' ],
        20 => [ 'when describing an event, tells what happened',          'when describing an event, tells what it meant',             'SN', 'N' ],
        21 => [ 'gets work done right away',                              'procrastinates',                                            'JP', 'P' ],
        22 => [ 'follows the heart',                                      'follows the head',                                          'TF', 'T' ],
        23 => [ 'stays at home',                                          'goes out on the town',                                      'EI', 'E' ],
        24 => [ 'wants the big picture',                                  'wants the details',                                         'SN', 'S' ],
        25 => [ 'improvises',                                             'prepares',                                                  'JP', 'J' ],
        26 => [ 'bases morality on justice',                              'bases morality on compassion',                              'TF', 'F' ],
        27 => [ 'finds it difficult to yell very loudly',                 'yelling to others far away comes naturally',                'EI', 'E' ],
        28 => [ 'theoretical',                                            'empirical',                                                 'SN', 'S' ],
        29 => [ 'works hard',                                             'plays hard',                                                'JP', 'P' ],
        30 => [ 'uncomfortable with emotions',                            'values emotions',                                           'TF', 'F' ],
        31 => [ 'likes to perform in front of other people',              'avoids public speaking',                                    'EI', 'I' ],
        32 => [ 'likes to know "who?", "what?", "when?"',                 'likes to know "why?"',                                      'SN', 'N' ],
    ];

    /**
     * Compute dichotomy scores from raw item responses.
     * @param array $responses [itemNum => float] of 1.0-5.0 values (or 'unsure' skipped)
     * @return array ['EI' => float -2..+2, 'SN' => ..., 'TF' => ..., 'JP' => ...]
     *   Where positive = E/N/T/P (right pole of each dichotomy in canonical letter order),
     *   negative = I/S/F/J.
     *   Wait — the DICHOTOMIES 'right' fields are I,N,F,P (introvert/intuition/feeling/perceiving).
     *   So we use the convention: positive score = DICHOTOMIES['right'] letter (I/N/F/P).
     */
    public static function scoreResponses( array $responses ): array {
        $byDich = [ 'EI' => [], 'SN' => [], 'TF' => [], 'JP' => [] ];
        foreach ( self::ITEMS as $n => [ $left, $right, $dich, $rightPole ] ) {
            if ( !isset( $responses[ $n ] ) ) continue;
            $v = $responses[ $n ];
            if ( $v === '' || $v === null ) continue;
            $raw = (float)$v;  // 1.0 .. 5.0
            $centered = $raw - 3.0;  // -2 .. +2 (positive = right phrase, negative = left)
            // Whether right-phrase aligns with the canonical positive pole of the dichotomy:
            $canonicalRight = self::DICHOTOMIES[ $dich ][ 'right' ]; // I, N, F, or P
            $sign = ( $rightPole === $canonicalRight ) ? 1.0 : -1.0;
            $byDich[ $dich ][] = $centered * $sign;
        }
        $out = [];
        foreach ( $byDich as $d => $vals ) {
            $out[ $d ] = $vals ? round( array_sum( $vals ) / count( $vals ), 2 ) : null;
        }
        return $out;
    }

    /** Build 4-letter type from dichotomy scores (positive = right pole letter). */
    public static function letterTypeFromScores( array $scores ): ?string {
        $letters = '';
        foreach ( self::DICHOTOMIES as $d => $def ) {
            $s = $scores[ $d ] ?? null;
            if ( $s === null ) return null;
            $letters .= ( $s >= 0 ) ? $def[ 'right' ] : $def[ 'left' ];
        }
        return $letters;
    }

    /**
     * Plain-language descriptor for a single dichotomy score.
     * Returns ['letter' => 'I'|'E'|..., 'strength' => 'balanced'|'slight'|'clear'|'strong', 'phrase' => '...']
     */
    public static function describeAxis( string $dich, ?float $score ): array {
        if ( $score === null ) {
            return [ 'letter' => '?', 'strength' => '', 'phrase' => 'no data' ];
        }
        $def = self::DICHOTOMIES[ $dich ];
        $letter = ( $score >= 0 ) ? $def['right'] : $def['left'];
        $name   = ( $score >= 0 ) ? $def['right_name'] : $def['left_name'];
        $abs = abs( $score );
        $strength = $abs < 0.5 ? 'balanced' : ( $abs < 1.0 ? 'slight' : ( $abs < 1.5 ? 'clear' : 'strong' ) );
        $phrase = $strength === 'balanced'
            ? 'roughly balanced between ' . $def['left_name'] . ' and ' . $def['right_name']
            : $strength . ' preference for ' . $name . ' (' . $letter . ')';
        return [ 'letter' => $letter, 'strength' => $strength, 'phrase' => $phrase ];
    }

    public static function interpret( array $scores ): string {
        $type = self::letterTypeFromScores( $scores );
        if ( !$type ) return 'Incomplete.';
        $name = self::TYPES[ $type ][ 0 ] ?? '?';
        return $type . ' — ' . $name;
    }
}
