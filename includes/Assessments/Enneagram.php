<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * Enneagram of Personality — dimensional treatment.
 *
 * The Enneagram is a typology of 9 interrelated personality patterns,
 * developed in the 20th century by Oscar Ichazo (Arica school, 1960s)
 * and Claudio Naranjo (psychiatric integration, 1970s), and popularized
 * in the English-speaking world by Riso & Hudson (1987, 1996, 1999),
 * Helen Palmer (1988), and Don Riso (1990). The 9 types are arranged
 * around a circle (the enneagram figure) with inner lines connecting
 * each type to two others — its "stress" line (direction of
 * disintegration under pressure) and its "growth" line (direction of
 * integration in health). Each type also has two "wings": the adjacent
 * types on the circle, one of which usually colors the primary pattern
 * more strongly.
 *
 * Per the user's "no lumping, only precision" directive, this wiki
 * treats the Enneagram dimensionally: every user has a continuous
 * score (0–100) on each of the 9 type patterns. The "primary type"
 * is just whichever score happens to be highest at the moment — and
 * the report explicitly shows secondary and tertiary patterns too.
 *
 * The standard instruments (RHETI, WEPSS) are proprietary. Items
 * below are content-validated screening statements grounded in the
 * canonical Riso–Hudson descriptions (Riso & Hudson 1999, *The
 * Wisdom of the Enneagram*) — they are NOT a standardized test, and
 * are presented honestly as such. Each item is rated on a 1–5
 * Likert slider (Strongly disagree → Strongly agree).
 *
 * Per-type score = mean response across that type's items, mapped
 * from [1,5] → [0,100] via (mean − 1) × 25.
 */
class Enneagram {
    public const KEY         = 'enneagram';
    public const NAME        = 'Enneagram';
    public const FULL_NAME   = 'Enneagram of Personality';
    public const CITATION    = 'Ichazo (Arica, 1960s); Naranjo 1970s; Riso & Hudson 1999 (Wisdom of the Enneagram). Items: content-derived screening statements.';
    public const DESCRIPTION = 'Nine interrelated personality patterns arranged on a circle, with stress/growth lines and wings. Treated here dimensionally — every user has a continuous score on every type, and the "primary type" is simply the current high score.';
    public const WARNING     = '';
    public const PAGE_SIZE   = 45;

    public const TYPE_MIN = 0.0;
    public const TYPE_MAX = 100.0;

    /**
     * The 9 types. Each entry:
     *   [ name, epithet, basic_fear, basic_desire, vice (passion), virtue,
     *     holy_idea, brief_descriptor ]
     *
     * Vice/Virtue/Holy Idea are the Ichazo/Naranjo triadic structure
     * (Ichazo 1972 Arica trainings; cf. Maitri 2000 *Spiritual Dimension*).
     */
    public const TYPES = [
        1 => [
            'name'         => 'The Reformer',
            'epithet'      => 'Perfectionist / Principled Idealist',
            'basic_fear'   => 'Being corrupt, defective, or "bad"',
            'basic_desire' => 'To have integrity, to be good and right',
            'vice'         => 'Anger (held as resentment)',
            'virtue'       => 'Serenity',
            'holy_idea'    => 'Holy Perfection',
            'descriptor'   => 'Rational, principled, self-disciplined. Carries a strong inner critic and an exacting sense of how things should be.',
        ],
        2 => [
            'name'         => 'The Helper',
            'epithet'      => 'Giver / Caring Connector',
            'basic_fear'   => 'Being unwanted, unloved for who they are',
            'basic_desire' => 'To feel loved and needed',
            'vice'         => 'Pride (in indispensability)',
            'virtue'       => 'Humility',
            'holy_idea'    => 'Holy Will / Holy Freedom',
            'descriptor'   => 'Warm, attentive to others, generous — sometimes at the cost of their own needs going unnamed.',
        ],
        3 => [
            'name'         => 'The Achiever',
            'epithet'      => 'Performer / Status-Seeker',
            'basic_fear'   => 'Being worthless apart from achievement',
            'basic_desire' => 'To feel valuable, admired, successful',
            'vice'         => 'Deceit (of self and image)',
            'virtue'       => 'Truthfulness / Authenticity',
            'holy_idea'    => 'Holy Hope / Holy Law',
            'descriptor'   => 'Driven, image-aware, adaptable. Reads what is admired in a context and becomes the version of self most likely to be admired.',
        ],
        4 => [
            'name'         => 'The Individualist',
            'epithet'      => 'Romantic / Aesthete',
            'basic_fear'   => 'Having no identity or personal significance',
            'basic_desire' => 'To find themselves and their unique meaning',
            'vice'         => 'Envy (of what others have / are)',
            'virtue'       => 'Equanimity / Emotional Balance',
            'holy_idea'    => 'Holy Origin',
            'descriptor'   => 'Introspective, expressive, sensitive to nuance and atmosphere. Drawn to depth, longing, beauty — sometimes melancholy.',
        ],
        5 => [
            'name'         => 'The Investigator',
            'epithet'      => 'Observer / Theorist',
            'basic_fear'   => 'Being useless, incapable, or overwhelmed',
            'basic_desire' => 'Mastery, capable engagement on their own terms',
            'vice'         => 'Avarice (of energy, time, knowledge)',
            'virtue'       => 'Non-attachment / Generosity',
            'holy_idea'    => 'Holy Omniscience / Holy Transparency',
            'descriptor'   => 'Cerebral, perceptive, conserving. Withdraws to think, build inner models, and master a domain before acting.',
        ],
        6 => [
            'name'         => 'The Loyalist',
            'epithet'      => 'Skeptic / Trooper',
            'basic_fear'   => 'Being without support or guidance',
            'basic_desire' => 'Security, belonging, a reliable framework',
            'vice'         => 'Fear / Anxiety',
            'virtue'       => 'Courage',
            'holy_idea'    => 'Holy Faith / Holy Strength',
            'descriptor'   => 'Engaging, committed, vigilant. Scans for threats and inconsistencies; loyal to people and frameworks they have decided to trust.',
        ],
        7 => [
            'name'         => 'The Enthusiast',
            'epithet'      => 'Epicure / Visionary',
            'basic_fear'   => 'Deprivation, pain, being trapped',
            'basic_desire' => 'Satisfaction, variety, freedom',
            'vice'         => 'Gluttony (for experience)',
            'virtue'       => 'Sobriety / Constancy',
            'holy_idea'    => 'Holy Plan / Holy Work',
            'descriptor'   => 'Quick, versatile, future-oriented. Keeps options open, generates possibilities, reframes pain to keep moving.',
        ],
        8 => [
            'name'         => 'The Challenger',
            'epithet'      => 'Protector / Boss',
            'basic_fear'   => 'Being harmed, controlled, or violated',
            'basic_desire' => 'Self-determination, to protect themselves and their own',
            'vice'         => 'Lust (intensity, life-force)',
            'virtue'       => 'Innocence (open vulnerability)',
            'holy_idea'    => 'Holy Truth',
            'descriptor'   => 'Powerful, direct, self-reliant. Takes charge, confronts what others avoid, fiercely protects who they consider theirs.',
        ],
        9 => [
            'name'         => 'The Peacemaker',
            'epithet'      => 'Mediator / Harmonizer',
            'basic_fear'   => 'Loss, fragmentation, separation',
            'basic_desire' => 'Inner stability, peace of mind, harmony',
            'vice'         => 'Sloth (inattention to own agenda)',
            'virtue'       => 'Right Action / Engaged Presence',
            'holy_idea'    => 'Holy Love',
            'descriptor'   => 'Receptive, easy-going, stabilizing. Merges with others to maintain peace; can lose track of own priorities in the process.',
        ],
    ];

    /** Wings: the two adjacent types on the circle (1 ↔ 2 ↔ … ↔ 9 ↔ 1). */
    public const WINGS = [
        1 => [ 9, 2 ], 2 => [ 1, 3 ], 3 => [ 2, 4 ],
        4 => [ 3, 5 ], 5 => [ 4, 6 ], 6 => [ 5, 7 ],
        7 => [ 6, 8 ], 8 => [ 7, 9 ], 9 => [ 8, 1 ],
    ];

    /**
     * Centers (triads).
     *   Head (Thinking, root affect: fear):    5, 6, 7
     *   Heart (Feeling, root affect: shame):   2, 3, 4
     *   Body / Gut (Instinct, root affect: anger): 8, 9, 1
     */
    public const CENTERS = [
        'head'  => [ 'label' => 'Head (Thinking)',      'affect' => 'fear',  'types' => [ 5, 6, 7 ] ],
        'heart' => [ 'label' => 'Heart (Feeling)',      'affect' => 'shame', 'types' => [ 2, 3, 4 ] ],
        'body'  => [ 'label' => 'Body / Gut (Instinct)','affect' => 'anger', 'types' => [ 8, 9, 1 ] ],
    ];

    /** Hornevian groups (after Karen Horney) — interpersonal strategy. */
    public const HORNEVIAN = [
        'assertive' => [ 'label' => 'Assertive (move against)',         'types' => [ 3, 7, 8 ], 'gloss' => 'Expand into the environment; demand what they want; respond to challenge by pushing back.' ],
        'compliant' => [ 'label' => 'Compliant / Dutiful (move toward)','types' => [ 1, 2, 6 ], 'gloss' => 'Respond to a sense of duty or "shoulds"; orient toward what authority, group, or principle calls for.' ],
        'withdrawn' => [ 'label' => 'Withdrawn (move away)',            'types' => [ 4, 5, 9 ], 'gloss' => 'Respond to overwhelm by retreating inward; defend their inner space and emotional / cognitive territory.' ],
    ];

    /** Harmonic groups — how the type handles conflict / unmet need. */
    public const HARMONIC = [
        'positive'   => [ 'label' => 'Positive Outlook', 'types' => [ 2, 7, 9 ], 'gloss' => 'When something is wrong, reframe it; emphasize what is going well; sometimes at the cost of facing the negative.' ],
        'competency' => [ 'label' => 'Competency',       'types' => [ 1, 3, 5 ], 'gloss' => 'When something is wrong, set feelings aside and focus on the task; emphasize efficiency, expertise, getting it right.' ],
        'reactive'   => [ 'label' => 'Reactive',         'types' => [ 4, 6, 8 ], 'gloss' => 'When something is wrong, the emotional charge comes out — needs an authentic response from the other person.' ],
    ];

    /** Stress (disintegration) and Growth (integration) lines (Riso–Hudson). */
    public const STRESS_LINE = [ 1=>4, 2=>8, 3=>9, 4=>2, 5=>7, 6=>3, 7=>1, 8=>5, 9=>6 ];
    public const GROWTH_LINE = [ 1=>7, 2=>4, 3=>6, 4=>1, 5=>8, 6=>9, 7=>5, 8=>2, 9=>3 ];

    /** Instinctual variants (subtypes). */
    public const INSTINCTS = [
        'sp' => [ 'label' => 'Self-Preservation (sp)', 'gloss' => 'Attention to safety, comfort, resources, the body, the home; "do I have what I need to survive and be at ease?"' ],
        'so' => [ 'label' => 'Social (so)',            'gloss' => 'Attention to the group, status, belonging, reading the room; "where do I fit, and how is the collective doing?"' ],
        'sx' => [ 'label' => 'Sexual / One-to-One (sx)','gloss' => 'Attention to intense one-on-one bonds, attraction, chemistry, the charge between two people; "who lights me up?"' ],
    ];

    /**
     * 45 content-validated screening items — 5 per type.
     * Each: [ statement, type (1-9) ]. All positively keyed to their type.
     * Rated 1–5 (Strongly disagree → Strongly agree).
     */
    public const ITEMS = [
        // ----- Type 1 — The Reformer -----
        1  => [ 'I have a strong inner sense of how things "should be," and it bothers me when reality falls short.',                            1 ],
        2  => [ 'I notice errors quickly — in my work, in others\' work, in the world around me.',                                                1 ],
        3  => [ 'I work hard to control my own behavior so I can be a good person.',                                                              1 ],
        4  => [ 'When I am frustrated, it often comes out as a critical voice — toward myself or others.',                                       1 ],
        5  => [ 'I believe most situations could be improved if people just put in more effort and did things the right way.',                   1 ],

        // ----- Type 2 — The Helper -----
        6  => [ 'I find myself anticipating what others need before they even ask.',                                                              2 ],
        7  => [ 'I am uncomfortable receiving help — I would much rather be the one giving it.',                                                  2 ],
        8  => [ 'Knowing I am important to someone matters more to me than almost anything else.',                                                2 ],
        9  => [ 'I can feel quietly resentful when my generosity is not recognized, even though I would never say so.',                          2 ],
        10 => [ 'I tailor how I show up to whatever each person seems to need from me.',                                                          2 ],

        // ----- Type 3 — The Achiever -----
        11 => [ 'I work hard to be successful at whatever I take on, and I dislike losing or coming up short.',                                  3 ],
        12 => [ 'I am naturally aware of how I am being perceived, and I can adjust my image to fit a context.',                                  3 ],
        13 => [ 'I tend to set goals quickly and find efficient routes to them.',                                                                 3 ],
        14 => [ 'I sometimes worry that if I stopped achieving, people would not value me.',                                                      3 ],
        15 => [ 'I am comfortable being the public face of a project, and I usually enjoy it.',                                                   3 ],

        // ----- Type 4 — The Individualist -----
        16 => [ 'I feel like there is something missing in me that other people seem to have.',                                                   4 ],
        17 => [ 'My emotional experience often feels deeper, more intense, or more authentic than what those around me show.',                  4 ],
        18 => [ 'I am drawn to what is beautiful, melancholy, or evocative — even when it is sad.',                                              4 ],
        19 => [ 'I want to express what is most truly me — even when it sets me apart from everyone else.',                                       4 ],
        20 => [ 'I sometimes idealize what I do not have, and then feel disappointed when I get it.',                                             4 ],

        // ----- Type 5 — The Investigator -----
        21 => [ 'I need substantial alone time to think, to recharge, and to follow my own line of inquiry.',                                    5 ],
        22 => [ 'I would rather understand something deeply before I act on it than improvise.',                                                  5 ],
        23 => [ 'I am careful with my energy and my resources, and I dislike sudden demands on them.',                                            5 ],
        24 => [ 'I withhold what I know until I am sure I have understood it well enough to share.',                                              5 ],
        25 => [ 'I sometimes feel like a detached observer of life rather than a participant in it.',                                             5 ],

        // ----- Type 6 — The Loyalist -----
        26 => [ 'I am always running scenarios of what could go wrong, and I plan accordingly.',                                                  6 ],
        27 => [ 'I am loyal to people, groups, and ideas I have committed to — sometimes past the point others would walk away.',                6 ],
        28 => [ 'I find it hard to fully trust authority, but I also find it hard to trust myself without it.',                                  6 ],
        29 => [ 'I notice threats and inconsistencies quickly — sometimes before others see them at all.',                                       6 ],
        30 => [ 'I oscillate between caution and counter-phobic action when I am afraid.',                                                        6 ],

        // ----- Type 7 — The Enthusiast -----
        31 => [ 'I keep my options open as long as possible because I do not want to miss anything.',                                             7 ],
        32 => [ 'I get bored easily and look for the next stimulating idea, project, or experience.',                                             7 ],
        33 => [ 'I reframe pain or difficulty quickly — looking for the silver lining or the next move.',                                         7 ],
        34 => [ 'I am a connector of ideas — I love seeing how different domains fit together.',                                                  7 ],
        35 => [ 'I sometimes find it hard to commit fully to one thing because it would mean losing the others.',                                7 ],

        // ----- Type 8 — The Challenger -----
        36 => [ 'I trust my own strength and I am willing to push against people or systems I disagree with.',                                    8 ],
        37 => [ 'I have a strong protective instinct toward the people I consider mine.',                                                         8 ],
        38 => [ 'I would rather confront a problem directly than dance around it.',                                                               8 ],
        39 => [ 'I have little patience for weakness — including my own — and I am uncomfortable showing vulnerability.',                        8 ],
        40 => [ 'I have a big presence in a room, and I am comfortable taking up space and making decisions.',                                    8 ],

        // ----- Type 9 — The Peacemaker -----
        41 => [ 'I can see most situations from multiple sides, which can make it hard to land on my own position.',                              9 ],
        42 => [ 'I prefer harmony in my environment and I will merge with what others want to keep the peace.',                                   9 ],
        43 => [ 'I sometimes lose track of what I want and only realize it later, often in a small flare of resentment.',                         9 ],
        44 => [ 'I get into routines and find it hard to start something that disrupts my flow.',                                                  9 ],
        45 => [ 'I am grounded and steady — people often feel calmer around me.',                                                                  9 ],
    ];

    /**
     * Compute per-type scores from item responses.
     * @param array $responses [itemNum => float] of 1.0-5.0 values
     * @return array ['type_1' => 0..100|null, ..., 'type_9' => 0..100|null]
     */
    public static function scoreResponses( array $responses ): array {
        $byType = array_fill( 1, 9, [] );
        foreach ( self::ITEMS as $n => [ $stmt, $type ] ) {
            if ( !isset( $responses[ $n ] ) ) continue;
            $v = $responses[ $n ];
            if ( $v === '' || $v === null ) continue;
            $byType[ $type ][] = (float)$v;
        }
        $out = [];
        for ( $t = 1; $t <= 9; $t++ ) {
            $vals = $byType[ $t ];
            if ( !$vals ) { $out[ 'type_' . $t ] = null; continue; }
            $mean = array_sum( $vals ) / count( $vals );           // 1..5
            $out[ 'type_' . $t ] = round( ( $mean - 1.0 ) * 25.0, 1 );  // 0..100
        }
        return $out;
    }

    /** Return primary (highest-scoring) type 1-9, or null. */
    public static function primaryType( array $scores ): ?int {
        $best = null; $bestVal = -INF;
        for ( $t = 1; $t <= 9; $t++ ) {
            $v = $scores[ 'type_' . $t ] ?? null;
            if ( $v === null ) continue;
            if ( (float)$v > $bestVal ) { $bestVal = (float)$v; $best = $t; }
        }
        return $best;
    }

    /**
     * Wing analysis for a given primary type.
     * Returns ['wing'=>int|null, 'opposite'=>int|null,
     *          'wing_score'=>float|null, 'opposite_score'=>float|null,
     *          'label'=>'1w9'|'1w2'|'1 (balanced wings 9/2)'|'1 (no wing data)']
     */
    public static function wingFor( int $primary, array $scores ): array {
        if ( !isset( self::WINGS[ $primary ] ) ) {
            return [ 'wing' => null, 'opposite' => null, 'wing_score' => null, 'opposite_score' => null, 'label' => (string)$primary ];
        }
        [ $a, $b ] = self::WINGS[ $primary ];
        $sa = $scores[ 'type_' . $a ] ?? null;
        $sb = $scores[ 'type_' . $b ] ?? null;
        if ( $sa === null && $sb === null ) {
            return [ 'wing' => null, 'opposite' => null, 'wing_score' => null, 'opposite_score' => null, 'label' => $primary . ' (no wing data)' ];
        }
        $saVal = $sa === null ? -INF : (float)$sa;
        $sbVal = $sb === null ? -INF : (float)$sb;
        if ( $sa !== null && $sb !== null && abs( $saVal - $sbVal ) < 3.0 ) {
            return [ 'wing' => null, 'opposite' => null, 'wing_score' => max( $saVal, $sbVal ), 'opposite_score' => min( $saVal, $sbVal ), 'label' => $primary . ' (balanced wings ' . $a . '/' . $b . ')' ];
        }
        if ( $saVal >= $sbVal ) {
            return [ 'wing' => $a, 'opposite' => $b, 'wing_score' => $sa, 'opposite_score' => $sb, 'label' => $primary . 'w' . $a ];
        }
        return [ 'wing' => $b, 'opposite' => $a, 'wing_score' => $sb, 'opposite_score' => $sa, 'label' => $primary . 'w' . $b ];
    }

    /**
     * Tritype: top type in each center, ordered with primary first.
     * Returns ['code'=>'459'|null, 'detail'=>[head=>['type'=>5,'score'=>x],...], 'order'=>[4,5,9]]
     */
    public static function tritype( array $scores ): array {
        $bestPerCenter = [];
        foreach ( self::CENTERS as $cKey => $cDef ) {
            $best = null; $bestVal = -INF;
            foreach ( $cDef['types'] as $t ) {
                $v = $scores[ 'type_' . $t ] ?? null;
                if ( $v === null ) continue;
                if ( (float)$v > $bestVal ) { $bestVal = (float)$v; $best = $t; }
            }
            $bestPerCenter[ $cKey ] = [ 'type' => $best, 'score' => $best === null ? null : $bestVal ];
        }
        $primary = self::primaryType( $scores );
        if ( $primary === null ) return [ 'code' => null, 'detail' => $bestPerCenter, 'order' => [] ];
        $primaryCenter = null;
        foreach ( self::CENTERS as $cKey => $cDef ) {
            if ( in_array( $primary, $cDef['types'], true ) ) { $primaryCenter = $cKey; break; }
        }
        $others = [];
        foreach ( $bestPerCenter as $cKey => $info ) {
            if ( $cKey === $primaryCenter ) continue;
            if ( $info['type'] !== null ) $others[] = [ 'type' => $info['type'], 'score' => $info['score'] ];
        }
        usort( $others, fn( $a, $b ) => $b['score'] <=> $a['score'] );
        $order = [ $primary ];
        foreach ( $others as $o ) $order[] = $o['type'];
        return [ 'code' => implode( '', $order ), 'detail' => $bestPerCenter, 'order' => $order ];
    }

    /** Mean score across each center's types. */
    public static function centerEnergy( array $scores ): array {
        $out = [];
        foreach ( self::CENTERS as $cKey => $cDef ) {
            $vals = [];
            foreach ( $cDef['types'] as $t ) {
                $v = $scores[ 'type_' . $t ] ?? null;
                if ( $v === null ) continue;
                $vals[] = (float)$v;
            }
            $out[ $cKey ] = $vals ? round( array_sum( $vals ) / count( $vals ), 1 ) : null;
        }
        return $out;
    }

    /** Mean score across each Hornevian or Harmonic group. */
    public static function groupEnergy( array $scores, string $grouping ): array {
        $src = $grouping === 'hornevian' ? self::HORNEVIAN : self::HARMONIC;
        $out = [];
        foreach ( $src as $gKey => $gDef ) {
            $vals = [];
            foreach ( $gDef['types'] as $t ) {
                $v = $scores[ 'type_' . $t ] ?? null;
                if ( $v === null ) continue;
                $vals[] = (float)$v;
            }
            $out[ $gKey ] = $vals ? round( array_sum( $vals ) / count( $vals ), 1 ) : null;
        }
        return $out;
    }

    public static function interpret( array $scores ): string {
        $primary = self::primaryType( $scores );
        if ( !$primary ) return 'Incomplete.';
        $wing = self::wingFor( $primary, $scores );
        $tritype = self::tritype( $scores );
        $bits = [ self::TYPES[ $primary ]['name'] . ' — ' . $wing['label'] ];
        if ( $tritype['code'] ) $bits[] = 'tritype ' . $tritype['code'];
        return implode( ' · ', $bits );
    }
}
