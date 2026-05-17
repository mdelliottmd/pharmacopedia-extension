<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * RAADS-R — Ritvo Autism Asperger Diagnostic Scale, Revised
 * Ritvo, R. A., Ritvo, E. R., Guthrie, D., Ritvo, M. J., Hufnagel, D. H., McMahon, W.,
 * Tonge, B., Mataix-Cols, D., Jassi, A., Attwood, T., & Eloff, J. (2011).
 * J Autism Dev Disord, 41(8), 1076-1089.
 *
 * 80 items, 4-point response. Four subscales:
 *   Language (LANG), Social Relatedness (SOC), Sensory/Motor (SEN), Circumscribed Interests (CI).
 *
 * Scoring (Ritvo 2011):
 *   Standard items: NT=0, TOY=1, TON=2, TNW=3
 *   Reverse-keyed (normative) items: NT=3, TOY=2, TON=2, TNW=0
 * Total cutoff ≥ 65 suggests likely ASD.
 *
 * ============================================================================
 * VERIFICATION REQUIRED — Item text, subscale assignments, and reverse-keyed
 * item list below are a best-effort reconstruction. Eyeball them against the
 * Ritvo 2011 publication (Tables 1-5) before relying on results clinically.
 * Edit this file directly to correct any errors; scoring will recompute.
 * ============================================================================
 */
class Raadsr {
    public const KEY        = 'raadsr';
    public const NAME       = 'RAADS-R';
    public const FULL_NAME  = 'Ritvo Autism Asperger Diagnostic Scale — Revised';
    public const CITATION   = 'Ritvo et al. 2011 (J Autism Dev Disord 41(8):1076-1089)';
    public const DESCRIPTION = 'Adult-focused autism trait inventory, particularly sensitive to late-diagnosed and high-masking presentations. Four subscales: Language, Social Relatedness, Sensory/Motor, Circumscribed Interests.';
    public const WARNING    = 'The RAADS-R is long and arduous — 80 items, paginated 10 at a time across 8 pages. Plan on ~20–30 minutes. Your progress is saved after each page, so you can stop and resume.';
    public const PAGE_SIZE  = 10; // 8 pages total

    public const RESPONSE_LABELS = [
        0 => 'Never true',
        1 => 'True only when I was younger than 16',
        2 => 'True only now (as an adult)',
        3 => 'True now and when I was young',
    ];

    public const ITEMS = [
        1  => 'It is difficult for me to understand how other people are feeling when we are talking.',
        2  => 'Some ordinary textures that do not bother others feel very offensive when they touch my skin.',
        3  => 'It is very difficult for me to work and function in groups.',
        4  => 'It is difficult to figure out what other people expect of me.',
        5  => "I often don't know how to act in social situations.",
        6  => 'I can chat and make small talk with people.',
        7  => 'When I feel overwhelmed by my senses, I have to isolate myself to shut them down.',
        8  => 'How to make friends and socialize is a mystery to me.',
        9  => 'When talking to someone, I have a hard time telling when it is my turn to talk or to listen.',
        10 => 'Sometimes I have to cover my ears to block out painful noises (like vacuum cleaners or people talking too much or too loudly).',
        11 => "It can be very hard to read someone's face, hand, and body movements when we are talking.",
        12 => 'I focus on details rather than the overall idea.',
        13 => 'I take things too literally, so I often miss what people are trying to say.',
        14 => 'I get extremely upset when the way I like to do things is suddenly changed.',
        15 => 'I have never wanted or needed to have what other people call an "intimate relationship".',
        16 => 'It is difficult for me to understand what other people are feeling when we are talking.',
        17 => 'I am considered a compassionate type of person.',
        18 => 'I cannot tell when someone is flirting with me.',
        19 => 'I have been told that I have an unusual voice (e.g., flat, monotone, childish, or high-pitched).',
        20 => 'I sometimes use words and phrases from my favorite movies and television shows in conversations.',
        21 => 'I tend to use different tones of voice (such as for emphasis or to differentiate the persons in a story) when I tell a story.',
        22 => 'I am very interested in figuring out how things work, and I sometimes take them apart.',
        23 => 'I can keep a conversation going with others by chatting about my interests for as long as they like.',
        24 => 'I can tell when someone says one thing but means another.',
        25 => 'I would rather go out to eat in a restaurant by myself than with someone I know.',
        26 => 'I cannot imagine what it would be like to want to kiss someone.',
        27 => 'I like having a conversation with several people, for instance around a dinner table, at school, or at work.',
        28 => 'I am considered to be a very compassionate person, even though I have a difficult time expressing it.',
        29 => "I find it difficult to understand other people's facial expressions.",
        30 => 'I keep lists of things that interest me, even when they have no practical use (e.g., sports statistics, train schedules, calendar dates, historical facts).',
        31 => 'When I feel overwhelmed by my emotions, I have to isolate myself to shut them down.',
        32 => 'I like to talk things over with my friends.',
        33 => 'I am told that I talk too loudly or too softly without being aware of it.',
        34 => 'I have made friends in school or at work.',
        35 => 'I am bothered by the fact that I am not able to socialize and communicate with others the way some people can.',
        36 => 'The same sound sometimes seems very loud or very soft, even though I know it has not changed.',
        37 => 'I am sometimes surprised when others tell me I have been rude.',
        38 => 'I only like to talk to people who share my special interests.',
        39 => 'I have always wondered what it would be like to be normal and have not been able to figure it out.',
        40 => "I can't tolerate things I dislike (e.g., smells, textures, sounds, colors).",
        41 => "I don't like to be hugged or held.",
        42 => 'When I go somewhere I have to have a routine, or I become extremely upset.',
        43 => 'It calms me to spin around or to rock back and forth in my body.',
        44 => 'I have to fight to be normal and not be different.',
        45 => 'I cannot tell if someone is interested or bored with what I am saying.',
        46 => 'It can be hard to be friends with someone who has different opinions than me.',
        47 => "People have to talk to me for a while before I notice that they are joking or being sarcastic.",
        48 => 'I have been told to make a point clearer, because I tend to ramble or get lost in detail.',
        49 => 'I have to "act normal" to please other people and make them like me.',
        50 => 'Meeting new people is usually easy for me.',
        51 => 'I get highly confused when someone interrupts me when I am talking about something I am very interested in.',
        52 => 'It is difficult for me to maintain a friendship.',
        53 => 'I have been told that I am rude even though I think I am being polite.',
        54 => 'When I am invited somewhere, I tend to ask many questions until I am completely clear about what is expected of me.',
        55 => "I can't seem to figure out the best timing for things — what to say when, when to stop talking.",
        56 => 'I have read literary classics with great pleasure for the way they are written.',
        57 => 'I can talk on and on without giving the other person a chance to talk.',
        58 => 'People tell me I give too much detail.',
        59 => 'I find it easier to talk to one person at a time rather than to a group.',
        60 => 'I am usually aware of how the people around me are feeling.',
        61 => 'I tend to point out when other people are not following the rules.',
        62 => 'I get along better with younger people than with people of my own age.',
        63 => 'I cannot relate to my peers because I think differently than they do.',
        64 => 'I have play-acted social situations in advance to learn how to behave properly in them.',
        65 => 'It is difficult to learn how to do something new without explicit step-by-step instructions.',
        66 => 'I can tell when someone is interested in me romantically by their facial expressions and body language.',
        67 => 'If I am in a place where there are many smells, textures to feel, noises, or bright lights, I feel anxious or frightened.',
        68 => 'I tend to fixate on small details that others would not notice or care about.',
        69 => 'Sometimes a thought or a subject gets stuck in my head, and I have to talk about it even if no one is interested.',
        70 => 'I do certain things with my hands over and over again (like flapping, twirling sticks or strings, waving things in front of my eyes).',
        71 => 'I have never been interested in what most of the people I know consider interesting.',
        72 => 'I am considered to be a loner by those who know me best.',
        73 => "I have unusually sensitive senses (e.g., I can smell or hear things that others can't).",
        74 => 'When I look at someone, I miss small details that other people would catch.',
        75 => 'I get along with other people by following a set of specific rules that help me look normal.',
        76 => 'It is very difficult for me to understand when someone is embarrassed or jealous.',
        77 => 'Some games or activities I enjoy (or used to enjoy) involve very specific rules or steps.',
        78 => 'I get along with other people in groups easily.',
        79 => 'People often talk to me in a "babying" tone or treat me like a child.',
        80 => 'I tend to focus on details that other people miss.',
    ];

    /**
     * Reverse-keyed ("normative") items. Per Ritvo 2011 there are 14.
     * Best-effort identification; verify against the source.
     */
    public const REVERSE = [ 6, 17, 21, 23, 24, 27, 32, 34, 50, 56, 60, 66, 78, 22 ];

    /**
     * Subscale assignments (best-effort thematic). Verify against Ritvo 2011 Table 5.
     * Counts target: LANG 7, SOC 39, SEN 20, CI 14.
     */
    public const SUBSCALES = [
        'SOC'  => [ 'label' => 'Social Relatedness', 'items' => [
            1, 3, 4, 5, 6, 8, 9, 11, 13, 15, 16, 17, 18, 23, 25, 26, 27, 28, 29, 32,
            34, 35, 37, 39, 41, 44, 45, 49, 50, 52, 53, 60, 62, 63, 64, 72, 75, 76, 78,
        ] ],
        'CI'   => [ 'label' => 'Circumscribed Interests', 'items' => [
            12, 14, 22, 30, 38, 42, 46, 51, 54, 61, 65, 68, 71, 77,
        ] ],
        'LANG' => [ 'label' => 'Language', 'items' => [
            19, 20, 21, 33, 47, 48, 55, 57, 58, 69, 79,
        ] ],
        'SEN'  => [ 'label' => 'Sensory/Motor', 'items' => [
            2, 7, 10, 24, 31, 36, 40, 43, 56, 59, 66, 67, 70, 73, 74, 80,
        ] ],
    ];

    /**
     * Compute subscale + total scores using Ritvo's documented scoring:
     *   Normal items: response value (0-3) is the score.
     *   Reverse items: NT(0)→3, TOY(1)→2, TON(2)→2, TNW(3)→0.
     */
    public static function scoreResponses( array $responses ): array {
        $scoreItem = function ( int $itemN, $raw ) {
            if ( $raw === '' || $raw === null ) return null;
            $v = (int)$raw;
            if ( in_array( $itemN, self::REVERSE, true ) ) {
                // Ritvo reverse mapping: NT=3, TOY=2, TON=2, TNW=0
                return [ 0 => 3, 1 => 2, 2 => 2, 3 => 0 ][ $v ] ?? 0;
            }
            return $v; // 0-3 direct
        };

        $scores = [];
        $totalSum = 0;
        $totalAnswered = 0;
        foreach ( self::SUBSCALES as $k => $def ) {
            $sum = 0;
            $answered = 0;
            foreach ( $def['items'] as $itemN ) {
                $s = $scoreItem( $itemN, $responses[ $itemN ] ?? null );
                if ( $s === null ) continue;
                $sum += $s;
                $answered++;
            }
            $scores[ 'subscale_' . $k ] = $answered > 0 ? $sum : null;
            $totalSum += $sum;
            $totalAnswered += $answered;
        }
        $scores['total'] = $totalAnswered > 0 ? $totalSum : null;
        $scores['answered'] = $totalAnswered;
        return $scores;
    }

    public static function interpret( array $scores ): string {
        $total    = $scores['total'] ?? null;
        $answered = $scores['answered'] ?? 0;
        if ( $total === null ) return 'Incomplete.';
        if ( $answered < 80 ) {
            return "Partial: total {$total} based on {$answered}/80 items. Threshold (≥ 65) interpretable only with all 80 answered.";
        }
        if ( $total >= 65 ) {
            return "Total {$total} / 240 — at or above the Ritvo 2011 likely-ASD threshold of 65.";
        }
        return "Total {$total} / 240 — below the Ritvo 2011 likely-ASD threshold of 65.";
    }
}
