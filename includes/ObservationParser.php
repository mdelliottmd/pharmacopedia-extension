<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

/**
 * Plain-text observation parser. Takes input like:
 *
 *   "anxiety from bupropion in jan 2020"
 *   "did not experience anxiety from bupropion in feb 2018"
 *   "intense depersonalization on ketamine, april 2024"
 *   "no insomnia while on melatonin during summer 2023"
 *   "diagnosed with ADHD, 2019"
 *
 * And returns a structured parse with:
 *   - polarity (positive / negative / null)
 *   - date struct (PCPDatePicker JSON, or null)
 *   - linked references (subject + role-bound: cause, context)
 *     - each ref matched against user's meds / global effects / global problems
 *       / user's diagnoses, or falls back to free text
 *
 * Unrecognized references are stored as 'free' refs so the user can upgrade
 * them later (when they add the relevant med to their list, etc.).
 */
class ObservationParser {

    private $dbr;
    private $currentProfileId = 0;

    // Negation words. Order matters: longer phrases first so they consume first.
    private const NEGATION_PHRASES = [
        "did not experience", "did not have", "did not get",
        "didn't experience", "didn't have", "didn't get",
        "does not", "doesn't",
        "did not", "didn't",
        "without", "lacking",
        "never had", "never",
        "denied", "deny",
        "no ",  // trailing space prevents "noise" / "north" false positives
        "not ",
    ];

    // Role markers split a phrase into subject + role-bound noun.
    // Order matters: longer phrases first.
    private const ROLE_MARKERS = [
        'after taking'  => 'cause',
        'while taking'  => 'cause',
        'while on'      => 'cause',
        'caused by'     => 'cause',
        'due to'        => 'cause',
        'from taking'   => 'cause',
        'from'          => 'cause',
        'during'        => 'context',
        'while'         => 'context',
        'with'          => 'context',
        'on '           => 'cause',  // "on bupropion" — note trailing space
        'after '        => 'context',
    ];

    public function __construct() {
        $this->dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
    }

    /**
     * Main entry point.
     */
    public function parse( string $rawText, int $profileId ): array {
        $this->currentProfileId = $profileId;
        $original = trim( $rawText );
        $working  = ' ' . strtolower( $original ) . ' ';

        $result = [
            'original_text' => $original,
            'polarity'      => null,
            'polarity_word' => null,
            'date_struct'   => null,
            'date_text'     => null,
            'date_display'  => null,
            'subject_text'  => null,
            'numeric_value' => null,
            'refs'          => [],
            'confidence'    => 'low',
            'warnings'      => [],
            'is_episode'    => false,
            'episode_type'  => null,
            'episode_subtype' => null,
            'notes'         => [],
        ];

        // 1) Date extraction. Pre-process partial-pair shapes, then try:
        //    relative-now -> holiday -> range -> point.
        $working = $this->expandPartialMonthPair( $working );
        $dateRes = $this->extractRelativeNow( $working );
        if ( !$dateRes ) $dateRes = $this->extractHoliday( $working );
        if ( !$dateRes ) $dateRes = $this->extractDateRange( $working );
        if ( !$dateRes ) $dateRes = $this->extractDate( $working );
        if ( $dateRes ) {
            $result['date_text']    = $dateRes['text'];
            $result['date_struct']  = $dateRes['struct'];
            $working = $dateRes['remaining'];
            if ( !empty( $dateRes['warning'] ) ) $result['warnings'][] = $dateRes['warning'];

            // 1b) Time + timezone. After the date is consumed, look for a time
            //     token (anchored by @/at, or bare with am/pm/colon) optionally
            //     followed by a tz abbreviation. Attach onto the point field
            //     (or the range's from-point) so DatePicker normalize/display
            //     pick it up via the existing field-level time/timezone keys.
            $timeRes = $this->extractTimeAndTz( $working );
            if ( $timeRes ) {
                $targetKey = null;
                if ( ( $result['date_struct']['kind'] ?? '' ) === 'point' ) {
                    $targetKey = 'point';
                } elseif ( ( $result['date_struct']['kind'] ?? '' ) === 'range' ) {
                    $targetKey = isset( $result['date_struct']['from'] ) ? 'from' : null;
                }
                if ( $targetKey && isset( $result['date_struct'][$targetKey] ) ) {
                    $field = $result['date_struct'][$targetKey];
                    $field['time'] = [
                        'raw'    => $timeRes['raw_time'],
                        'parsed' => $timeRes['hms'],
                        'error'  => false,
                    ];
                    if ( !empty( $timeRes['tz_label'] ) ) {
                        $field['timezone'] = $timeRes['tz_label'];
                    }
                    $result['date_struct'][$targetKey] = $field;
                    $working = $timeRes['remaining'];
                    $result['date_text'] .= ' ' . trim( $timeRes['raw'] );
                }
            }

            $result['date_display'] = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatStructForCard( $result['date_struct'] );
        }

        // 2) Polarity extraction
        foreach ( self::NEGATION_PHRASES as $neg ) {
            $needle = ' ' . $neg;
            if ( strpos( $working, $needle ) !== false ) {
                $result['polarity'] = 0;
                $result['polarity_word'] = trim( $neg );
                $working = str_replace( $needle, ' ', $working );
                break;
            }
        }
        if ( $result['polarity'] === null && trim( $working ) !== '' ) {
            $result['polarity'] = 1;
        }

        // 2a) Extract a value token (grade / stars / percent / fraction /
        // leading-decimal / plain number) and route through
        // KeyframeValueNormalizer for the canonical 0-100 result.
        $valueTokenRe = '(?-i:[A-DF][+-]?)|\d+(?:\.\d+)?\s*\/\s*\d+(?:\.\d+)?\s*stars?|[-+]?\d+(?:\.\d+)?\s*%|[-+]?\d+(?:\.\d+)?\s*\/\s*[-+]?\d+(?:\.\d+)?|[-+]?(?:0)?\.\d+|[-+]?\d+(?:\.\d+)?';
        $verbRe = '/\b(?:was|is|are|were|=)\s+(?:a\s+|an\s+)?(' . $valueTokenRe . ')(?=\s|[,.;:!?]|$)/i';
        if ( preg_match( $verbRe, $working, $nm, PREG_OFFSET_CAPTURE ) ) {
            $rawVal = $nm[1][0];
            $r = \MediaWiki\Extension\Pharmacopedia\KeyframeValueNormalizer::normalize( $rawVal );
            if ( $r['value'] !== null ) {
                $isYearLike = ( $r['form'] === 'plain'
                    && $r['value'] >= 1900.0 && $r['value'] <= 2100.0
                    && $r['value'] == (int)$r['value']
                    && preg_match( '/^\d{4}$/', trim( $rawVal ) ) );
                if ( !$isYearLike ) {
                    $result['numeric_value'] = $r['value'];
                    $working = substr_replace( $working, ' ', $nm[0][1], strlen( $nm[0][0] ) );
                }
            }
        }

        // 2b) Strip leading verb phrases ("diagnosed with", "I experienced", etc.)
        $working = ' ' . $this->stripLeadingVerbs( trim( $working ) ) . ' ';

        // 3) Split on role markers into subject + cause/context noun phrases
        $remaining = trim( preg_replace( '/\s+/', ' ', $working ) );
        $remaining = trim( $remaining, " ,.;:" );

        $segments = $this->splitOnRoles( $remaining );
        // First segment = subject (role='subject'); subsequent = role-tagged
        if ( !empty( $segments ) ) {
            $result['subject_text'] = $segments[0]['text'];
            $result['refs'] = $this->resolveRefs( $segments, $profileId );
        }

        // Auto-promote unmatched free subjects to "trait_new" when we have
        // both a numeric value AND a date. Downstream this becomes a custom
        // keyframe rather than getting discarded as free text.
        if ( $result['numeric_value'] !== null && $result['date_struct'] ) {
            foreach ( $result['refs'] as &$_r ) {
                if ( ( $_r['role'] ?? '' ) === 'subject'
                     && ( $_r['type'] ?? '' ) === 'free'
                     && empty( $_r['matched'] ) ) {
                    $cleanLabel = preg_replace( '/^(?:my|the|a|an)\s+/i', '', (string)$_r['text'] );
                    $cleanLabel = trim( $cleanLabel );
                    if ( $cleanLabel === '' ) $cleanLabel = (string)$_r['text'];
                    $_r['type']    = 'trait_new';
                    $_r['label']   = $cleanLabel;
                    $_r['matched'] = true;
                }
            }
            unset( $_r );
        }


        // 3b) Episode-shape detection (e.g. "manic episode", "depressive episode",
        //     "panic attack"). Drives downstream routing to addEpisode.
        $epMap = [
            'manic'        => [ 'mood', 'manic' ],
            'hypomanic'    => [ 'mood', 'hypomanic' ],
            'depressive'   => [ 'mood', 'depressive' ],
            'depression'   => [ 'mood', 'depressive' ],
            'mixed'        => [ 'mood', 'mixed' ],
            'dysphoric'    => [ 'mood', 'dysphoric' ],
            'euthymic'     => [ 'mood', 'euthymic' ],
            'psychotic'    => [ 'psychotic', null ],
            'manic with psychotic features' => [ 'psychotic', 'manic with psychotic features' ],
            'depressive with psychotic features' => [ 'psychotic', 'depressive with psychotic features' ],
            'anxiety'      => [ 'anxiety', null ],
            'panic'        => [ 'panic', null ],
            'dissociative' => [ 'dissociative', null ],
            'grief'        => [ 'grief', null ],
            'substance'    => [ 'substance use', null ],
        ];
        $epRe = '/\\b([a-z]+(?:\\s+with\\s+psychotic\\s+features)?)\\s+(?:episode|attack|crisis|break|flare)\\b/i';
        if ( preg_match( $epRe, $original, $em ) ) {
            $key = strtolower( trim( $em[1] ) );
            if ( isset( $epMap[$key] ) ) {
                $result['is_episode']      = true;
                $result['episode_type']    = $epMap[$key][0];
                $result['episode_subtype'] = $epMap[$key][1];
            }
        }
        // A range date is normally an episode signal, BUT if the subject is a
        // user trait + we have a numeric value, the API submit handler treats
        // it as two keyframes instead. Avoid clobbering that branch here.
        $subjectIsTrait = false;
        if ( !empty( $result['refs'] ) ) {
            foreach ( $result['refs'] as $r ) {
                if ( ( $r['role'] ?? '' ) === 'subject' && in_array( $r['type'] ?? '', [ 'trait', 'trait_new' ], true ) ) {
                    $subjectIsTrait = true;
                    break;
                }
            }
        }
        if ( $result['date_struct'] && ( $result['date_struct']['kind'] ?? '' ) === 'range'
             && !( $subjectIsTrait && $result['numeric_value'] !== null ) ) {
            $result['is_episode'] = true;
        }

        // 4) Confidence heuristic
        $matched = 0; $total = count( $result['refs'] );
        foreach ( $result['refs'] as $r ) {
            if ( $r['matched'] ) $matched++;
        }
        if ( $result['date_struct'] && $matched === $total && $total >= 1 ) {
            $result['confidence'] = 'high';
        } elseif ( $matched > 0 || $result['date_struct'] ) {
            $result['confidence'] = 'medium';
        }

        if ( !$result['date_struct'] ) {
            $result['warnings'][] = 'No date detected. Add one in the date field below.';
        }
        // Recover original casing + leading articles for any subject/ref text
        // that came back as free / unmatched. Matching has already happened on
        // the lowercased / article-stripped form; for DISPLAY we want the
        // user's own phrasing back.
        foreach ( $result['refs'] as &$_ref ) {
            if ( !empty( $_ref['matched'] ) && ( $_ref['type'] ?? '' ) !== 'free' && ( $_ref['type'] ?? '' ) !== 'trait_new' ) continue;
            $recovered = $this->recoverOriginalSpan( (string)( $_ref['text'] ?? '' ), $original );
            if ( $recovered !== '' ) {
                $_ref['text']  = $recovered;
                // For trait_new, keep the cleanLabel (stripped article) but
                // display the original-cased phrasing.
                if ( ( $_ref['type'] ?? '' ) !== 'trait_new' ) {
                    $_ref['label'] = $recovered;
                }
            }
        }
        unset( $_ref );

        // Also fix up subject_text on the result for display.
        if ( !empty( $result['subject_text'] ) ) {
            $recovered = $this->recoverOriginalSpan( (string)$result['subject_text'], $original );
            if ( $recovered !== '' ) $result['subject_text'] = $recovered;
        }

        // Friendly note for any subjects we're auto-creating as new custom traits.
        // (Was previously a ⚠ warning; relocated as informational.)
        $_newTraits = [];
        foreach ( $result['refs'] as $_r ) {
            if ( ( $_r['type'] ?? '' ) === 'trait_new' ) {
                $_newTraits[] = (string)$_r['label'];
            }
        }
        if ( $_newTraits ) {
            $result['notes'][] = 'New custom trait: ' . implode( ', ', $_newTraits ) . '.';
        }

        // Friendly note for unmatched free-text references. Subject-free goes
        // through as a presumed title; non-subject-free as a free reference.
        $titles = [];
        $frees  = [];
        foreach ( $result['refs'] as $r ) {
            if ( !empty( $r['matched'] ) ) continue;
            if ( ( $r['type'] ?? '' ) !== 'free' ) continue;
            $label = '"' . (string)$r['text'] . '"';
            if ( ( $r['role'] ?? '' ) === 'subject' ) $titles[] = $label;
            else                                       $frees[]  = $label;
        }
        if ( $titles ) {
            $result['notes'][] = 'Presumed title: ' . implode( ', ', $titles ) . '.';
        }
        if ( $frees ) {
            $result['notes'][] = 'Free reference: ' . implode( ', ', $frees )
                . ' (link to a known med or effect later if you like).';
        }

        return $result;
    }

    // ===== Date extraction =====

    /**
     * Returns [ 'text' => 'jan 2020', 'struct' => [...DatePicker JSON...], 'remaining' => '...' ]
     * or null if no date found.
     */

    /**
     * Catch age-range phrases like "ages 2-10", "from ages 2 through 10",
     * "between age 5 and 12". Resolved against the owner's birthdate when
     * available (otherwise the 1990 fallback in applyAge() applies).
     */
    private function extractAgeRange( string $working ): ?array {
        // Two prefix shapes accepted:
        //   (a) explicit "ages?" keyword (with optional "from"/"between" before)
        //   (b) age-context prefix ("from", "between", "when I was", "aged", "at age") + optional "ages?"
        // The negative lookahead rules out non-age units (mg, ml, cm, ago, etc.)
        // so phrases like "10-20 mg" or "5-10 years ago" don't false-match.
        $re = '/\b(?:(?:from|between|when\s+i\s+(?:was|were)|aged|at)\s+(?:ages?\s+)?|ages?\s+)(\d{1,3})\s*(?:to|through|thru|till|until|\-|\x{2013}|\x{2014}|and)\s*(\d{1,3})\b(?!\s*(?:mg|mcg|g|kg|ml|cc|cm|mm|in|ft|lbs?|oz|hz|hrs?|min|sec|am|pm|\x{B0}|%|x|times|years?\s+ago|months?\s+ago|days?\s+ago|weeks?\s+ago))/iu';
        if ( !preg_match( $re, $working, $m, PREG_OFFSET_CAPTURE ) ) return null;
        $a1 = (int)$m[1][0];
        $a2 = (int)$m[2][0];
        // Sanity: both must look like ages (0-120).
        if ( $a1 > 120 || $a2 > 120 ) return null;
        if ( $a1 > $a2 ) { $tmp = $a1; $a1 = $a2; $a2 = $tmp; }
        $birthIso = $this->getBirthDate( $this->currentProfileId ?? 0 );
        $A = $this->applyAge( $a1, 0, 0, 0, 'age-year', "age $a1", $birthIso );
        $B = $this->applyAge( $a2, 0, 0, 0, 'age-year', "age $a2", $birthIso );
        if ( !$A || !$B ) return null;
        $struct = [
            'kind'    => 'range',
            'from'    => [
                'raw_text' => "age $a1",
                'parsed'   => [ 'kind' => 'point', 'precision' => $A['precision'], 'iso' => $A['iso'] ],
            ],
            'through' => [
                'raw_text' => "age $a2",
                'parsed'   => [ 'kind' => 'point', 'precision' => $B['precision'], 'iso' => $B['iso'] ],
            ],
        ];
        $remaining = substr_replace( $working, ' ', $m[0][1], strlen( $m[0][0] ) );
        // Also strip a leading "from " just before "ages" (if it survived).
        $remaining = preg_replace( '/\bfrom\s+$/i', '', $remaining );
        return [
            'text'      => "ages $a1 to $a2",
            'struct'    => $struct,
            'remaining' => $remaining,
            'warning'   => $A['warning'] ?? null,
        ];
    }

    /**
     * Try to extract a date RANGE from working text. Recognizes:
     *   "X to Y", "X till Y", "X until Y", "X through Y", "X thru Y",
     *   "X - Y" (with spaces), "from X to Y", "between X and Y",
     *   plus age ranges "ages 2-10", "between age 5 and 12".
     * Returns the same shape as extractDate (struct kind='range') or null.
     */
    private function extractDateRange( string $working ): ?array {
        // Age-range pattern first (it has a specific shape and the generic
        // logic below would partially match "ages 2" and then fail).
        $ageR = $this->extractAgeRange( $working );
        if ( $ageR ) return $ageR;
        // Strategy: find the first parseable date in the text. If immediately
        // after that date there's a range separator (till/until/through/thru/
        // to/and/-) followed by another parseable date, return a range struct.
        $a = $this->extractDate( $working );
        if ( !$a ) return null;
        // Locate the matched date phrase in the working text.
        $pos = stripos( $working, $a['text'] );
        if ( $pos === false ) return null;
        $afterFirst = substr( $working, $pos + strlen( $a['text'] ) );
        if ( !preg_match( '/^(?:\s+(?:till|until|through|thru|to|and)\s+|\s*[-\x{2013}\x{2014}]\s*(?=\w))/iu', $afterFirst, $sepM ) ) {
            return null;
        }
        $afterSep = substr( $afterFirst, strlen( $sepM[0] ) );
        $b = $this->extractDate( ' ' . $afterSep );
        if ( !$b ) return null;
        $fromPoint    = $a['struct']['point'] ?? null;
        $throughPoint = $b['struct']['point'] ?? null;
        if ( !$fromPoint || !$throughPoint ) return null;
        $struct = [
            'kind'    => 'range',
            'from'    => $fromPoint,
            'through' => $throughPoint,
        ];
        // Build remaining text by stripping the range expression out of $working:
        //   <before-first-date> + <leftover-after-second-date>
        $beforeFirst = substr( $working, 0, $pos );
        $bRemaining  = $b['remaining'] ?? '';
        // Also strip optional leading "from " / "between " just before the first date.
        $beforeFirst = preg_replace( '/(from|between)\s+$/i', '', $beforeFirst );
        $remaining = ' ' . trim( $beforeFirst ) . ' ' . trim( $bRemaining ) . ' ';
        return [
            'text'      => $a['text'] . ' to ' . $b['text'],
            'struct'    => $struct,
            'remaining' => $remaining,
        ];
    }



    /**
     * Relative-to-now phrases: "yesterday", "last week/month/year",
     * "X years/months/weeks/days ago", "a few months ago".
     */
    private function extractRelativeNow( string $working ): ?array {
        $now = new \DateTime();
        $patterns = [
            '/\byesterday\b/i'                                  => [ 'days',   1 ],
            '/\btoday\b/i'                                      => [ 'days',   0 ],
            '/\b(?:the\s+day\s+before\s+yesterday)\b/i'      => [ 'days',   2 ],
            '/\blast\s+(week|month|year)\b/i'                  => null,  // dynamic
            '/\b(\d+|a|an|a\s+few|several)\s+(day|week|month|year)s?\s+ago\b/i' => null,
        ];
        // Try each in order; first match wins.
        foreach ( $patterns as $re => $static ) {
            if ( !preg_match( $re, $working, $m, PREG_OFFSET_CAPTURE ) ) continue;
            $dt = clone $now;
            $disp = trim( $m[0][0] );
            if ( $static ) {
                $dt->modify( '-' . $static[1] . ' ' . $static[0] );
                $prec = 'day';
            } elseif ( stripos( $disp, 'last' ) === 0 ) {
                $unit = strtolower( $m[1][0] );
                $dt->modify( '-1 ' . $unit );
                $prec = $unit === 'year' ? 'year' : ( $unit === 'month' ? 'month' : 'day' );
            } else {
                // "X UNIT ago"
                $n = strtolower( trim( $m[1][0] ) );
                $unit = strtolower( $m[2][0] );
                $count = is_numeric( $n ) ? (int)$n : ( $n === 'a few' ? 3 : ( $n === 'several' ? 4 : 1 ) );
                $dt->modify( '-' . $count . ' ' . $unit );
                $prec = $unit === 'year' ? 'year' : ( $unit === 'month' ? 'month' : 'day' );
            }
            $iso = $dt->format( 'Y-m-d' );
            $remaining = substr_replace( $working, ' ', $m[0][1], strlen( $m[0][0] ) );
            $struct = [
                'kind'  => 'point',
                'point' => [
                    'raw_text' => $disp,
                    'parsed'   => [ 'kind' => 'point', 'precision' => $prec, 'iso' => $iso ],
                ],
            ];
            return [ 'text' => $disp, 'struct' => $struct, 'remaining' => $remaining ];
        }
        return null;
    }

    /**
     * Holiday-anchored dates: christmas, halloween, new year's, valentine's,
     * independence day / july 4th, thanksgiving (US: 4th Thursday of Nov).
     * Year may be specified or omitted (current year assumed).
     */
    private function extractHoliday( string $working ): ?array {
        $holidays = [
            'christmas eve'         => [ 12, 24 ],
            'christmas'             => [ 12, 25 ],
            'xmas'                  => [ 12, 25 ],
            'halloween'             => [ 10, 31 ],
            "new year's eve"        => [ 12, 31 ],
            "new years eve"         => [ 12, 31 ],
            "new year's"            => [  1,  1 ],
            "new years"             => [  1,  1 ],
            "new year"              => [  1,  1 ],
            "valentine's day"       => [  2, 14 ],
            "valentines day"        => [  2, 14 ],
            "valentine's"           => [  2, 14 ],
            'independence day'      => [  7,  4 ],
            'july 4th'              => [  7,  4 ],
            'fourth of july'        => [  7,  4 ],
            "st patrick's day"      => [  3, 17 ],
            'st patricks day'       => [  3, 17 ],
            'mardi gras'            => [  2, 13 ],   // approximate (varies)
            'super bowl'            => [  2,  1 ],   // approximate (early Feb)
            'mlk day'               => [  1, 15 ],
            'memorial day'          => [  5, 25 ],   // approximate (last Mon May)
            'labor day'             => [  9,  1 ],   // approximate (first Mon Sep)
        ];
        // Sort by length descending so "christmas eve" matches before "christmas".
        $keys = array_keys( $holidays );
        usort( $keys, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
        foreach ( $keys as $name ) {
            $reName = preg_quote( $name, '/' );
            $re = '/\b(?:around\s+|on\s+|,?\s*)?' . $reName . '(?:\s+(\d{4}))?\b/i';
            if ( !preg_match( $re, $working, $m, PREG_OFFSET_CAPTURE ) ) continue;
            list( $mo, $dy ) = $holidays[ $name ];
            $yr = !empty( $m[1][0] ) ? (int)$m[1][0] : (int)date( 'Y' );
            $iso = sprintf( '%04d-%02d-%02d', $yr, $mo, $dy );
            $disp = trim( $m[0][0] );
            $remaining = substr_replace( $working, ' ', $m[0][1], strlen( $m[0][0] ) );
            $struct = [
                'kind'  => 'point',
                'point' => [
                    'raw_text' => $disp,
                    'parsed'   => [ 'kind' => 'point', 'precision' => 'day', 'iso' => $iso ],
                ],
            ];
            // Thanksgiving needs dynamic calculation: 4th Thursday of November.
            if ( $name === 'thanksgiving' ) {
                $iso = self::computeThanksgiving( $yr );
                $struct['point']['parsed']['iso'] = $iso;
            }
            return [ 'text' => $disp, 'struct' => $struct, 'remaining' => $remaining ];
        }
        // Thanksgiving is special-cased separately because of the 4th Thu rule.
        if ( preg_match( '/\b(?:around\s+|on\s+|,?\s*)?thanksgiving(?:\s+(\d{4}))?\b/i', $working, $m, PREG_OFFSET_CAPTURE ) ) {
            $yr  = !empty( $m[1][0] ) ? (int)$m[1][0] : (int)date( 'Y' );
            $iso = self::computeThanksgiving( $yr );
            $disp = trim( $m[0][0] );
            $remaining = substr_replace( $working, ' ', $m[0][1], strlen( $m[0][0] ) );
            $struct = [
                'kind'  => 'point',
                'point' => [
                    'raw_text' => $disp,
                    'parsed'   => [ 'kind' => 'point', 'precision' => 'day', 'iso' => $iso ],
                ],
            ];
            return [ 'text' => $disp, 'struct' => $struct, 'remaining' => $remaining ];
        }
        return null;
    }

    /** 4th Thursday of November for a given year. */
    private static function computeThanksgiving( int $year ): string {
        $d = new \DateTime( "$year-11-01" );
        $weekday = (int)$d->format( 'N' );  // 1=Mon..7=Sun
        // Distance from current day to next Thursday (4).
        $offset = ( 4 - $weekday + 7 ) % 7;
        $d->modify( "+$offset days" );
        // Now $d is the first Thursday; add 3 weeks for the 4th.
        $d->modify( '+21 days' );
        return $d->format( 'Y-m-d' );
    }

    /**
     * Pre-process: if working text contains "between X and Y YYYY" where X is
     * a bare month and Y is a bare month, expand both to "X YYYY and Y YYYY".
     * Lets the range parser handle "between july and september 2021".
     */
    private function expandPartialMonthPair( string $working ): string {
        $months = '(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec|january|february|march|april|may|june|july|august|september|october|november|december)';
        $re = '/\b(' . $months . ')\s+(?:and|to|till|until|through|thru|\-)\s+(' . $months . ')\s+(\d{4})\b/i';
        return preg_replace_callback( $re, function ( $m ) {
            return $m[1] . ' ' . $m[3] . ' to ' . $m[2] . ' ' . $m[3];
        }, $working );
    }

    private function extractDate( string $working ): ?array {
        // Strip leading "in " before the date if present (consumed).
        // Patterns tried in order of specificity.

        // Bare age reference: "when I was 8" / "while I was 12". The number
        // has no unit; we treat it as years-of-age. Variants with units (yo,
        // years, etc.) are handled by the more general age regex below.
        if ( preg_match( '/\b(?:when|while)\s+i\s+(?:was|were)\s+(\d{1,2})\b(?!\s*(?:yo|y|yr|yrs|year|years|mo|mos|month|months|w|wk|wks|week|weeks|d|day|days))/i', $working, $bm, PREG_OFFSET_CAPTURE ) ) {
            $birthIso = $this->getBirthDate( $this->currentProfileId ?? 0 );
            $age = $this->applyAge( (int)$bm[1][0], 0, 0, 0, 'age-year', 'age ' . $bm[1][0], $birthIso );
            if ( $age ) {
                $remaining = substr_replace( $working, ' ', $bm[0][1], strlen( $bm[0][0] ) );
                $struct = [
                    'kind'  => 'point',
                    'point' => [
                        'raw_text' => $age['text'],
                        'parsed'   => [
                            'kind'      => 'point',
                            'precision' => $age['precision'],
                            'iso'       => $age['iso'],
                        ],
                    ],
                ];
                return [
                    'text'      => $age['text'],
                    'struct'    => $struct,
                    'remaining' => $remaining,
                    'warning'   => $age['warning'] ?? null,
                ];
            }
        }

        //
        // First: age-relative patterns. These can appear like "anxiety at 7y8mo"
        // or "depressed around age 14" or "happy in childhood". We look for an
        // age token preceded by "at ", "around ", "approx ", "approximately ",
        // "circa ", "when ", "during ", "in ", or none, and try to parse it.
        $ageRe = '/\b(?:at|around|approx(?:imately)?|circa|when|during)?\s*((?:as\s+(?:a|an)\s+(?:newborn|infant|baby|toddler|kid|child|tween|preteen|teen|teenager|young\s+adult|freshman|sophomore|junior|senior))|(?:(?:freshman|sophomore|junior|senior)\s+year)|(?:in\s+(?:toddlerhood|childhood|adolescence|young\s+adulthood|college|high\s+school|middle\s+school|elementary\s+school|grade\s+school))|(?:age\s+\d{1,3})|(?:(?:on\s+|at\s+)?(?:my\s+)?\d{1,3}(?:st|nd|rd|th)\s+(?:birthday|bday))|(?:\d+(?:\.\d+)?\s*yo)|(?:\d+\s*(?:y|yr|yrs|year|years|mo|mos|month|months|w|wk|wks|week|weeks|d|day|days)(?:\s*(?:and\s+)?\d+\s*(?:y|yr|yrs|year|years|mo|mos|month|months|w|wk|wks|week|weeks|d|day|days))*(?:\s+old)?))\b/i';
        if ( preg_match( $ageRe, $working, $am, PREG_OFFSET_CAPTURE ) ) {
            $birthIso = $this->getBirthDate( $this->currentProfileId ?? 0 );
            $age = $this->parseAgePhrase( trim( $am[1][0] ), $birthIso );
            if ( $age ) {
                $remaining = substr_replace( $working, ' ', $am[0][1], strlen( $am[0][0] ) );
                $struct = [
                    'kind'  => 'point',
                    'point' => [
                        'raw_text' => $age['text'],
                        'parsed'   => [
                            'kind'      => 'point',
                            'precision' => $age['precision'],
                            'iso'       => $age['iso'],
                        ],
                    ],
                ];
                return [
                    'text'      => $age['text'],
                    'struct'    => $struct,
                    'remaining' => $remaining,
                    'warning'   => $age['warning'] ?? null,
                ];
            }
        }
        $patterns = [
            // Bracketed exact ISO date
            '/\b(in\s+|on\s+|,\s*)?(\d{4}-\d{2}-\d{2})\b/i' => function ( $m ) {
                $iso = $m[2];
                return [ 'text' => $iso, 'iso' => $iso, 'precision' => 'day' ];
            },
            // US-style MM/DD/YYYY
            '/\b(in\s+|on\s+|,\s*)?(\d{1,2})\/(\d{1,2})\/(\d{4})\b/' => function ( $m ) {
                $iso = sprintf( '%04d-%02d-%02d', $m[4], $m[2], $m[3] );
                return [ 'text' => "{$m[2]}/{$m[3]}/{$m[4]}", 'iso' => $iso, 'precision' => 'day' ];
            },
            // US-style MM-DD-YYYY (hyphen form)
            '/\b(in\s+|on\s+|,\s*)?(\d{1,2})-(\d{1,2})-(\d{4})\b/' => function ( $m ) {
                $iso = sprintf( '%04d-%02d-%02d', $m[4], $m[2], $m[3] );
                return [ 'text' => "{$m[2]}-{$m[3]}-{$m[4]}", 'iso' => $iso, 'precision' => 'day' ];
            },
            // US-style MM-DD-YY (2-digit year; pivot at 30 -> 00-29=2000s, 30-99=1900s)
            '/\b(in\s+|on\s+|,\s*)?(\d{1,2})-(\d{1,2})-(\d{2})\b/' => function ( $m ) {
                $yy = (int)$m[4];
                $year = $yy < 30 ? 2000 + $yy : 1900 + $yy;
                $iso = sprintf( '%04d-%02d-%02d', $year, $m[2], $m[3] );
                return [ 'text' => "{$m[2]}-{$m[3]}-{$m[4]}", 'iso' => $iso, 'precision' => 'day' ];
            },
            // US-style MM/DD/YY (2-digit year; same pivot)
            '/\b(in\s+|on\s+|,\s*)?(\d{1,2})\/(\d{1,2})\/(\d{2})\b/' => function ( $m ) {
                $yy = (int)$m[4];
                $year = $yy < 30 ? 2000 + $yy : 1900 + $yy;
                $iso = sprintf( '%04d-%02d-%02d', $year, $m[2], $m[3] );
                return [ 'text' => "{$m[2]}/{$m[3]}/{$m[4]}", 'iso' => $iso, 'precision' => 'day' ];
            },
            // Month D, YYYY  or  Month D YYYY (sep 1 2020, feb 15, 2022)
            '/\b(in\s+|on\s+|,\s*)?(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec|january|february|march|april|may|june|july|august|september|october|november|december)\s+(\d{1,2})(?:st|nd|rd|th)?\s*,?\s+(\d{4})\b/i' => function ( $m ) {
                $mo = self::monthNumber( $m[2] );
                $iso = sprintf( '%04d-%02d-%02d', $m[4], $mo, (int)$m[3] );
                $disp = strtolower( $m[2] ) . ' ' . (int)$m[3] . ' ' . $m[4];
                return [ 'text' => $disp, 'iso' => $iso, 'precision' => 'day' ];
            },
            // Month YYYY (jan 2020, January 2020)
            '/\b(in\s+|on\s+|,\s*)?(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec|january|february|march|april|may|june|july|august|september|october|november|december)\s+(\d{4})\b/i' => function ( $m ) {
                $mo = self::monthNumber( $m[2] );
                $iso = sprintf( '%04d-%02d-01', $m[3], $mo );
                $disp = strtolower( $m[2] ) . ' ' . $m[3];
                return [ 'text' => $disp, 'iso' => $iso, 'precision' => 'month' ];
            },
            // Season YYYY
            '/\b(in\s+|,\s*)?(spring|summer|fall|autumn|winter)\s+(\d{4})\b/i' => function ( $m ) {
                $season = strtolower( $m[2] );
                $month = [ 'spring' => 3, 'summer' => 6, 'fall' => 9, 'autumn' => 9, 'winter' => 12 ][$season];
                $iso = sprintf( '%04d-%02d-01', $m[3], $month );
                return [ 'text' => "$season {$m[3]}", 'iso' => $iso, 'precision' => 'month' ];
            },
            // Qualified year
            '/\b(in\s+|,\s*)?(early|mid|late)\s+(\d{4})\b/i' => function ( $m ) {
                $iso = sprintf( '%04d-01-01', $m[3] );
                $q = strtolower( $m[2] );
                return [ 'text' => "$q {$m[3]}", 'iso' => $iso, 'precision' => 'year' ];
            },
            // Bare year (4 digits)
            '/\b(in\s+|on\s+|,\s*)?(\d{4})\b/' => function ( $m ) {
                $y = (int)$m[2];
                if ( $y < 1900 || $y > 2100 ) return null;
                return [ 'text' => $m[2], 'iso' => sprintf( '%04d-01-01', $y ), 'precision' => 'year' ];
            },
            // Decades: 2010s, '90s, 1990s
            '/\b(in\s+|,\s*)?(?:the\s+)?(\d{2}|\d{4})s\b/' => function ( $m ) {
                $d = (int)$m[2];
                if ( $d < 100 ) $d = ( $d > 30 ? 1900 : 2000 ) + $d;
                if ( $d < 1900 || $d > 2100 ) return null;
                return [ 'text' => "{$d}s", 'iso' => sprintf( '%04d-01-01', $d ), 'precision' => 'decade' ];
            },
        ];
        foreach ( $patterns as $regex => $builder ) {
            if ( preg_match( $regex, $working, $m, PREG_OFFSET_CAPTURE ) ) {
                $parts = [];
                foreach ( $m as $g ) $parts[] = $g[0];
                $built = $builder( $parts );
                if ( !$built ) continue;
                $remaining = substr_replace( $working, ' ', $m[0][1], strlen( $m[0][0] ) );
                $struct = [
                    'kind'  => 'point',
                    'point' => [
                        'raw_text' => $built['text'],
                        'parsed'   => [
                            'kind'      => 'point',
                            'precision' => $built['precision'],
                            'iso'       => $built['iso'],
                        ],
                    ],
                ];
                return [ 'text' => $built['text'], 'struct' => $struct, 'remaining' => $remaining ];
            }
        }
        return null;
    }


    /**
     * Get the owner's birthdate as YYYY-MM-DD, or null if not set / unparseable.
     * Reads pcp_profile_fields[demographics:birthday] which stores PCPDatePicker JSON.
     */
    private function getBirthDate( int $profileId ): ?string {
        $row = $this->dbr->newSelectQueryBuilder()
            ->select( 'pf_value_text' )
            ->from( 'pcp_profile_fields' )
            ->where( [
                'pf_profile_id' => $profileId,
                'pf_namespace'  => 'demographics',
                'pf_key'        => 'birthday',
            ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row || !$row->pf_value_text ) return null;
        $j = json_decode( (string)$row->pf_value_text, true );
        if ( !is_array( $j ) ) return null;
        // Walk the PCPDatePicker JSON to find the ISO date.
        $iso = $j['point']['parsed']['iso']     ?? null;
        $iso = $iso ?: ( $j['point']['parsed']['year']  ?? null );
        if ( !$iso && isset( $j['parsed']['iso'] ) ) $iso = $j['parsed']['iso'];
        if ( !$iso || !preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string)$iso ) ) return null;
        return $iso;
    }

    /**
     * Attempt to parse an age-relative date phrase.
     * Returns [ 'text' => 'when 7y8mo old', 'iso' => 'YYYY-MM-DD', 'precision' => 'age-year' ]
     * or null.
     */
    private function parseAgePhrase( string $phrase, ?string $birthIso ): ?array {
        $p = trim( $phrase );


        // Life stages (rough buckets). Anchor at midpoint age.
        static $STAGES = [
            'as a newborn' => 0,
            'as an infant' => 1,
            'as a baby'    => 1,
            'as a toddler' => 3,
            'in toddlerhood' => 3,
            'as a freshman' => 14, 'freshman year' => 14,
            'as a sophomore' => 15, 'sophomore year' => 15,
            'as a junior' => 16, 'junior year' => 16,
            'as a senior' => 17, 'senior year' => 17,
            'in college freshman year' => 18, 'in college sophomore year' => 19,
            'in college junior year' => 20, 'in college senior year' => 21,
            'as a kid'     => 8,
            'as a child'   => 8,
            'in childhood' => 8,
            'as a tween'   => 11,
            'as a preteen' => 11,
            'as a teen'    => 15,
            'as a teenager' => 15,
            'in adolescence' => 15,
            'as a young adult' => 22,
            'in young adulthood' => 22,
            'in college'   => 20,
            'in high school' => 16,
            'in middle school' => 13,
            'in elementary school' => 9,
            'in grade school' => 9,
        ];
        foreach ( $STAGES as $phrase => $age ) {
            if ( $p === $phrase || $p === substr( $phrase, 3 ) /* drop "as " for bare form */ ) {
                return $this->applyAge( $age, 0, 0, 0, 'age-year', $phrase, $birthIso, 'stage' );
            }
        }


        // "my Nth birthday" / "on my Nth"
        if ( preg_match( '/^(?:on\s+|at\s+)?(?:my\s+)?(\d{1,3})(?:st|nd|rd|th)\s+(?:birthday|bday)?$/i', $p, $m ) ) {
            return $this->applyAge( (int)$m[1], 0, 0, 0, 'age-year', $m[1] . 'th birthday', $birthIso, 'stage' );
        }

        // "(around|approx|approximately|circa|~) age N" — coarse year precision
        if ( preg_match( '/^(?:around\s+|approx(?:imately)?\s+|circa\s+|~\s*)?age\s+(\d{1,3})$/i', $p, $m ) ) {
            return $this->applyAge( (int)$m[1], 0, 0, 0, 'age-year', "age {$m[1]}", $birthIso, 'age' );
        }

        // Single token like "51.2yo" (decimal years)
        if ( preg_match( '/^(\d+(?:\.\d+)?)\s*yo$/i', $p, $m ) ) {
            $whole = (int)floor( (float)$m[1] );
            $frac = ((float)$m[1] - $whole);
            $months = (int)round( $frac * 12 );
            return $this->applyAge( $whole, $months, 0, 0, 'age-year', $m[1] . 'yo', $birthIso );
        }

        // Combined-units pattern: "7y8mo", "3y", "6mo", "10 years 4 months", "5 weeks 3 days old"
        // Units: y/yr/yrs/years/yo, mo/mos/month/months, w/wk/wks/week/weeks, d/day/days
        $unitRe = '(?:yo|years?|yrs?|y|months?|mos?|weeks?|wks?|w|days?|d)';
        $full = '/^\d+\s*' . $unitRe . '(?:\s*(?:and\s+)?\d+\s*' . $unitRe . ')*(?:\s+old)?$/i';
        if ( preg_match( $full, $p ) ) {
            $y = $mo = $w = $d = 0;
            $sawY = $sawMo = $sawW = $sawD = false;
            $tokens = [];
            preg_match_all( '/(\d+)\s*(' . $unitRe . ')/i', $p, $matches, PREG_SET_ORDER );
            foreach ( $matches as $tok ) {
                $n = (int)$tok[1];
                $u = strtolower( $tok[2] );
                if ( in_array( $u, [ 'yo', 'y', 'yr', 'yrs', 'year', 'years' ], true ) ) {
                    $y += $n; $sawY = true; $tokens[] = $n . 'y';
                } elseif ( in_array( $u, [ 'mo', 'mos', 'month', 'months' ], true ) ) {
                    $mo += $n; $sawMo = true; $tokens[] = $n . 'mo';
                } elseif ( in_array( $u, [ 'w', 'wk', 'wks', 'week', 'weeks' ], true ) ) {
                    $d += $n * 7; $sawW = true; $tokens[] = $n . 'w';
                } elseif ( in_array( $u, [ 'd', 'day', 'days' ], true ) ) {
                    $d += $n; $sawD = true; $tokens[] = $n . 'd';
                }
            }
            $prec = $sawD ? 'age-day' : ( $sawW ? 'age-week' : ( $sawMo ? 'age-month' : ( $sawY ? 'age-year' : null ) ) );
            if ( $prec ) {
                return $this->applyAge( $y, $mo, 0, $d, $prec, implode( '', $tokens ), $birthIso );
            }
        }
        return null;
    }

    private function applyAge( int $y, int $mo, int $w, int $d, string $prec, string $display, ?string $birthIso, string $wrap = 'old' ): ?array {
        $displayText = $this->wrapAgeDisplay( $display, $wrap );
        if ( !$birthIso ) {
            // No birthdate set; return a year-precision approximation using
            // 1990 as default birth year (matches PCPDatePicker's DEMO_BIRTH_YEAR).
            $year = 1990 + $y;
            return [
                'text'      => $displayText,
                'iso'       => sprintf( '%04d-01-01', $year ),
                'precision' => 'year',
                'warning'   => 'No birthdate on file; approximated via 1990 birth-year fallback. Add a birthday to Special:MyProfile for accuracy.',
            ];
        }
        $start = new \DateTime( $birthIso );
        $start->modify( "+{$y} years" );
        $start->modify( "+{$mo} months" );
        $start->modify( "+{$d} days" );
        $now = new \DateTime();
        $isFuture = $start > $now;
        return [
            'text'      => $displayText,
            'iso'       => $start->format( 'Y-m-d' ),
            'precision' => $prec,
            'warning'   => $isFuture ? 'This is the future. are you comfortable with that?' : null,
        ];
    }

    /**
     * Wrap an age display token in idiomatic English depending on its form:
     *   wrap='stage' -> as-is ("in childhood", "as a teen", "30th birthday")
     *   wrap='age'   -> "at age N"
     *   wrap='old'   -> "when {token} old" (for "7y8mo", "51.2yo", "3mo", etc.)
     */
    private function wrapAgeDisplay( string $display, string $wrap ): string {
        if ( $wrap === 'stage' ) return $display;
        if ( $wrap === 'age' )   return 'at ' . $display;
        return 'when ' . $display . ' old';
    }

    private static function monthNumber( string $name ): int {
        $map = [
            'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2,
            'mar' => 3, 'march' => 3, 'apr' => 4, 'april' => 4, 'may' => 5,
            'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7,
            'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9,
            'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11,
            'dec' => 12, 'december' => 12,
        ];
        return $map[strtolower($name)] ?? 1;
    }

    // ===== Role splitting =====

    /**
     * Split working text on role markers into a list of segments:
     *   [ ['text'=>'anxiety', 'role'=>'subject'],
     *     ['text'=>'bupropion', 'role'=>'cause'] ]
     */
    private function splitOnRoles( string $text ): array {
        $segments = [];
        $text = ' ' . trim( $text ) . ' ';
        $currentText = $text;
        $currentRole = 'subject';

        $maxSplits = 6;
        while ( $maxSplits-- > 0 ) {
            $earliest = null;
            $earliestRole = null;
            $earliestMarker = '';
            foreach ( self::ROLE_MARKERS as $marker => $role ) {
                $needle = ' ' . $marker;
                $pos = stripos( $currentText, $needle );
                if ( $pos !== false && ( $earliest === null || $pos < $earliest ) ) {
                    $earliest = $pos;
                    $earliestRole = $role;
                    $earliestMarker = $marker;
                }
            }
            if ( $earliest === null ) break;
            $before = trim( substr( $currentText, 0, $earliest ) );
            $after  = trim( substr( $currentText, $earliest + strlen( $earliestMarker ) + 1 ) );
            if ( $before !== '' ) {
                $segments[] = [ 'text' => $this->cleanNoun( $before ), 'role' => $currentRole ];
            }
            $currentText = ' ' . $after . ' ';
            $currentRole = $earliestRole;
        }
        $tail = trim( $currentText );
        if ( $tail !== '' ) {
            $segments[] = [ 'text' => $this->cleanNoun( $tail ), 'role' => $currentRole ];
        }
        return array_filter( $segments, function ( $s ) { return $s['text'] !== ''; } );
    }


    // Leading verb phrases that should be stripped (no semantic meaning by themselves;
    // they're filler around the actual subject noun phrase).
    private const LEADING_VERBS = [
        'i was diagnosed with', 'i was diagnosed',
        'was diagnosed with', 'was diagnosed',
        'diagnosed with', 'diagnosed',
        'i experienced', 'i had', 'i have', 'i felt', 'i feel',
        'i developed', 'i got', 'i was',
        'i started taking', 'started taking',
        'i stopped taking', 'stopped taking',
        'i was on', 'was on', 'i am on', 'am on',
        'i was taking', 'was taking', 'i am taking', 'am taking',
        'i took', 'took', 'i tried', 'tried',
        'i started', 'started', 'i stopped', 'stopped',
        'i used', 'used', 'i use', 'use',
        'experienced', 'felt', 'feeling', 'feel',
        'developed', 'noticed', 'observed',
        'had a', 'had', 'have a', 'have',
        'got a', 'got', 'getting',
    ];

    private function stripLeadingVerbs( string $text ): string {
        $t = ' ' . ltrim( $text ) . ' ';
        // Iterate: longer phrases first (constant is already ordered that way).
        $changed = true;
        while ( $changed ) {
            $changed = false;
            foreach ( self::LEADING_VERBS as $v ) {
                $prefix = ' ' . $v . ' ';
                if ( strncasecmp( $t, $prefix, strlen( $prefix ) ) === 0 ) {
                    $t = ' ' . substr( $t, strlen( $prefix ) );
                    $changed = true;
                    break;
                }
            }
        }
        return trim( $t );
    }

    private function cleanNoun( string $s ): string {
        $s = trim( $s, " ,.;:!?\"'" );
        // Strip leading articles / filler verbs
        $s = preg_replace( '/^(an?\s+|the\s+|some\s+|any\s+|experiencing\s+|experienced\s+|having\s+|had\s+|having\s+a\s+|got\s+|getting\s+)/i', '', $s );
        // Strip trailing adverbs that are descriptive but not part of the noun.
        $s = preg_replace( '/\s+(briefly|occasionally|frequently|regularly|often|sometimes|always|usually|rarely|barely|currently|now|lately|recently)$/i', '', $s );
        return $s;
    }

    // ===== Ref resolution =====

    /**
     * For each segment, try to match against:
     *   1. user's meds (pcp_user_meds where um_profile_id = ?)
     *   2. global effects (pcp_effects)
     *   3. global problems (pcp_problem) [and pcp_problem_alias]
     *   4. user's diagnoses (pcp_profile_diagnoses where pd_profile_id = ?)
     *   5. fallback: free text
     */
    private function resolveRefs( array $segments, int $profileId ): array {
        $out = [];
        foreach ( $segments as $seg ) {
            $text = $seg['text'];
            if ( $text === '' ) continue;
            $match = $this->resolveSingleRef( $text, $profileId );
            $out[] = [
                'role'    => $seg['role'],
                'type'    => $match ? $match['type'] : 'free',
                'id'      => $match ? $match['id']   : null,
                'text'    => $text,
                'label'   => $match ? $match['label'] : $text,
                'matched' => (bool)$match,
            ];
        }
        return $out;
    }

    private function resolveSingleRef( string $text, int $profileId ): ?array {
        $lower = strtolower( $text );

        // 1. user's meds — exact or substring on um_med_name
        $row = $this->dbr->newSelectQueryBuilder()
            ->select( [ 'um_id', 'um_med_name' ] )
            ->from( 'pcp_user_meds' )
            ->where( [ 'um_profile_id' => $profileId ] )
            ->andWhere( 'LOWER(CONVERT(um_med_name USING utf8mb4)) LIKE ' . $this->dbr->addQuotes( '%' . $lower . '%' ) )
            ->limit( 1 )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( $row ) {
            return [ 'type' => 'med', 'id' => (int)$row->um_id, 'label' => (string)$row->um_med_name ];
        }

        // 1b. Wiki articles in Category:Medicines (the global med catalog).
        //     Matches normalize: lower + underscores->spaces.
        $row = $this->dbr->newSelectQueryBuilder()
            ->select( [ 'p.page_id', 'p.page_title' ] )
            ->from( 'page', 'p' )
            ->join( 'categorylinks', 'cl', 'cl.cl_from = p.page_id' )
            ->join( 'linktarget', 'lt', 'lt.lt_id = cl.cl_target_id' )
            ->where( [
                'lt.lt_namespace'    => 14,
                'lt.lt_title'        => 'Medicines',
                'p.page_namespace'   => 0,
                'p.page_is_redirect' => 0,
            ] )
            ->andWhere( "REPLACE(LOWER(CONVERT(p.page_title USING utf8mb4)), '_', ' ') = " . $this->dbr->addQuotes( $lower ) )
            ->limit( 1 )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( $row ) {
            return [ 'type' => 'med_page', 'id' => (int)$row->page_id, 'label' => str_replace( '_', ' ', (string)$row->page_title ) ];
        }

        // 2. global effects — match on e_name or e_slug
        $row = $this->dbr->newSelectQueryBuilder()
            ->select( [ 'e_id', 'e_name' ] )
            ->from( 'pcp_effects' )
            ->where( 'LOWER(CONVERT(e_name USING utf8mb4)) = ' . $this->dbr->addQuotes( $lower ) )
            ->limit( 1 )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) {
            $row = $this->dbr->newSelectQueryBuilder()
                ->select( [ 'e_id', 'e_name' ] )
                ->from( 'pcp_effects' )
                ->where( 'LOWER(CONVERT(e_slug USING utf8mb4)) = ' . $this->dbr->addQuotes( str_replace( ' ', '-', $lower ) ) )
                ->limit( 1 )
                ->caller( __METHOD__ )
                ->fetchRow();
        }
        if ( $row ) {
            return [ 'type' => 'effect', 'id' => (int)$row->e_id, 'label' => (string)$row->e_name ];
        }

        // 3. global problems
        $row = $this->dbr->newSelectQueryBuilder()
            ->select( [ 'p_id', 'p_name' ] )
            ->from( 'pcp_problem' )
            ->where( 'LOWER(CONVERT(p_name USING utf8mb4)) = ' . $this->dbr->addQuotes( $lower ) )
            ->limit( 1 )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( $row ) {
            return [ 'type' => 'problem', 'id' => (int)$row->p_id, 'label' => (string)$row->p_name ];
        }

        // 3b. Problem aliases (synonyms).
        $row = $this->dbr->newSelectQueryBuilder()
            ->select( [ 'pa.pa_problem_id', 'p.p_name' ] )
            ->from( 'pcp_problem_alias', 'pa' )
            ->join( 'pcp_problem', 'p', 'p.p_id = pa.pa_problem_id' )
            ->where( 'LOWER(CONVERT(pa.pa_alias USING utf8mb4)) = ' . $this->dbr->addQuotes( $lower ) )
            ->limit( 1 )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( $row ) {
            return [ 'type' => 'problem', 'id' => (int)$row->pa_problem_id, 'label' => (string)$row->p_name ];
        }

        // 4a. User's custom keyframe traits (pcp_life_traits) — substring match
        //     on the trait label or key. This lets phrases like "my shyness was X"
        //     route to the existing "Shyness" trait the user has been tracking.
        $traitRes = $this->dbr->newSelectQueryBuilder()
            ->select( [ 'lt.lt_label', 'lt.lt_key' ] )
            ->distinct()
            ->from( 'pcp_life_traits', 'lt' )
            ->join( 'pcp_life_events', 'le', 'le.le_id = lt.lt_event_id' )
            ->where( [
                'le.le_profile_id' => $profileId,
                'lt.lt_namespace'  => 'custom',
            ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        foreach ( $traitRes as $tr ) {
            $label = (string)$tr->lt_label;
            $key   = (string)$tr->lt_key;
            $needle = '';
            if ( $label !== '' && stripos( $text, $label ) !== false ) { $needle = $label; }
            elseif ( $key !== '' && stripos( $text, $key ) !== false ) { $needle = $label !== '' ? $label : $key; }
            if ( $needle !== '' ) {
                return [ 'type' => 'trait', 'id' => 0, 'label' => $needle ];
            }
        }

        // 4. user's diagnoses — substring on pd_description
        $row = $this->dbr->newSelectQueryBuilder()
            ->select( [ 'pd_id', 'pd_description' ] )
            ->from( 'pcp_profile_diagnoses' )
            ->where( [ 'pd_profile_id' => $profileId ] )
            ->andWhere( 'LOWER(CONVERT(pd_description USING utf8mb4)) LIKE ' . $this->dbr->addQuotes( '%' . $lower . '%' ) )
            ->limit( 1 )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( $row ) {
            return [ 'type' => 'diagnosis', 'id' => (int)$row->pd_id, 'label' => (string)$row->pd_description ];
        }

        // 5. Global ICD/diagnosis abbreviation catalog (e.g. ADHD, OCD, PTSD).
        $row = $this->dbr->newSelectQueryBuilder()
            ->select( [ 'da_id', 'da_canonical' ] )
            ->from( 'pcp_diagnosis_abbreviations' )
            ->where( 'LOWER(CONVERT(da_token USING utf8mb4)) = ' . $this->dbr->addQuotes( $lower ) )
            ->limit( 1 )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( $row ) {
            return [ 'type' => 'diagnosis_code', 'id' => (int)$row->da_id, 'label' => (string)$row->da_canonical ];
        }

        return null;
    }
    /**
     * Recover the user's original casing for a parsed text fragment by
     * locating it (case-insensitive) in the untouched input. Also extends
     * leftward to include a leading article ("the", "a", "an") if it
     * directly preceded the fragment in the original.
     *
     * @param string $cleanedText  The article-stripped / lowercased fragment
     * @param string $original     The user's raw input text
     * @return string              Best-effort original-cased span, or input if not found
     */
    private function recoverOriginalSpan( string $cleanedText, string $original ): string {
        $needle = trim( $cleanedText );
        if ( $needle === '' ) return '';
        $pos = stripos( $original, $needle );
        if ( $pos === false ) return $cleanedText;
        $start = $pos;
        $end   = $pos + strlen( $needle );
        // Extend left to grab a leading article if it sits immediately before
        // the needle (one or more spaces between, word-bounded on the left).
        $before = substr( $original, 0, $start );
        if ( preg_match( '/(?:^|(?<=[\s,;:.!?\(\[\{"]))(the|an?)\s+$/i', $before, $m, PREG_OFFSET_CAPTURE ) ) {
            $start = $m[1][1];
        }
        return substr( $original, $start, $end - $start );
    }

    // ===== Time + timezone extraction =====

    /**
     * Look for a time token optionally followed by a timezone abbreviation.
     * Anchored form (\"@ 0200\" / \"at 2pm\") matches first; bare form requires
     * an explicit time indicator (colon, am/pm, or \"noon\"/\"midnight\").
     *
     * Returns [ 'raw' => matched span, 'raw_time' => time portion,
     *           'hms' => 'HH:MM:SS', 'tz_label' => 'ET'|'PST'|'+05:00'|null,
     *           'remaining' => updated working text ] or null.
     */
    private function extractTimeAndTz( string $working ): ?array {
        static $tzMap = null;
        if ( $tzMap === null ) {
            $tzMap = self::tzAbbrevMap();
        }
        $tzKeys = array_keys( $tzMap );
        usort( $tzKeys, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
        $tzAlt = implode( '|', array_map( function ( $k ) { return preg_quote( $k, '/' ); }, $tzKeys ) );

        // Symbolic times first: \"noon\" / \"midnight\" with optional anchor + tz.
        $sym = '/(?:@|\bat\b|,\s*|-\s*)?\s*(noon|midnight|midday)(?:\s+(' . $tzAlt . '|[+\-]\d{2}:?\d{2}|z))?\b/i';
        if ( preg_match( $sym, $working, $m, PREG_OFFSET_CAPTURE ) ) {
            $word = strtolower( $m[1][0] );
            $hms = ( $word === 'midnight' ) ? '00:00:00' : '12:00:00';
            $rawTz = isset( $m[2] ) ? (string)$m[2][0] : '';
            $tzInfo = self::resolveTzLabel( $rawTz, $tzMap );
            $offset = $m[0][1];
            $len = strlen( $m[0][0] );
            return [
                'raw'       => trim( $m[0][0] ),
                'raw_time'  => $word,
                'hms'       => $hms,
                'tz_label'  => $tzInfo['label'] ?? null,
                'remaining' => substr_replace( $working, ' ', $offset, $len ),
            ];
        }

        // Anchored numeric form: \"@ 0200\", \"at 2:30 pm\", \", 14:00\", \" - 2pm\"
        $anchored = '/(?:@|\bat\b|,\s*|-\s*)\s*'
            . '(?P<t>'
                . '\d{1,2}:\d{2}(?::\d{2})?(?:\s*[ap]\.?\s?m\.?)?'   // 02:00 / 14:30:15 / 2:30 pm / 2:30 a.m.
                . '|'
                . '\d{1,2}\s*[ap]\.?\s?m\.?'                          // 2am / 2 pm / 2 a.m. / 2 p m
                . '|'
                . '\d{3,4}'                                            // 0200 / 2300 military
            . ')'
            . '(?:\s*h(?:rs?|ours?)?\b)?'                              // optional \"h\"/\"hrs\"/\"hours\"
            . '(?:\s*(?P<tz>' . $tzAlt . '|[+\-]\d{2}:?\d{2}|z)\b)?'
            . '/i';

        // Bare form: requires colon or am/pm (military 4-digit without anchor is too ambiguous)
        $bare = '/\b'
            . '(?P<t>'
                . '\d{1,2}:\d{2}(?::\d{2})?(?:\s*[ap]\.?\s?m\.?)?'
                . '|'
                . '\d{1,2}\s*[ap]\.?\s?m\.?'
            . ')'
            . '(?:\s*h(?:rs?|ours?)?\b)?'
            . '(?:\s*(?P<tz>' . $tzAlt . '|[+\-]\d{2}:?\d{2}|z)\b)?'
            . '/i';

        // Bare military adjacent to a tz token (e.g. "0200ET", "1430 pst").
        // No "@"/"at" anchor needed because the tz proximity disambiguates.
        $bareMilTz = '/\b(?P<t>\d{3,4})(?:\s*h(?:rs?|ours?)?\b)?\s*(?P<tz>' . $tzAlt . '|[+\-]\d{2}:?\d{2}|z)\b/i';

        foreach ( [ $anchored, $bareMilTz, $bare ] as $re ) {
            if ( !preg_match( $re, $working, $m, PREG_OFFSET_CAPTURE ) ) continue;
            $rawT = trim( $m['t'][0] );
            $hms = self::parseTimeToken( $rawT );
            if ( !$hms ) continue;
            $rawTz = isset( $m['tz'] ) ? trim( (string)$m['tz'][0] ) : '';
            $tzInfo = self::resolveTzLabel( $rawTz, $tzMap );
            $offset = $m[0][1];
            $len = strlen( $m[0][0] );
            return [
                'raw'       => trim( $m[0][0] ),
                'raw_time'  => $rawT,
                'hms'       => $hms,
                'tz_label'  => $tzInfo['label'] ?? null,
                'remaining' => substr_replace( $working, ' ', $offset, $len ),
            ];
        }
        return null;
    }

    /** Time token to HH:MM:SS. Accepts colon, military, 12-hour with am/pm. */
    private static function parseTimeToken( string $tok ): ?string {
        $t = strtolower( trim( $tok ) );
        // Normalize separators: collapse whitespace, strip dots in a.m./p.m.
        $t = preg_replace( '/\s+/', '', $t );
        $t = str_replace( '.', '', $t );
        $ampm = null;
        if ( substr( $t, -2 ) === 'am' )      { $ampm = 'am'; $t = substr( $t, 0, -2 ); }
        elseif ( substr( $t, -2 ) === 'pm' )  { $ampm = 'pm'; $t = substr( $t, 0, -2 ); }
        $t = trim( $t );
        $h = $mi = $s = 0;
        if ( strpos( $t, ':' ) !== false ) {
            $parts = explode( ':', $t );
            $h  = (int)$parts[0];
            $mi = isset( $parts[1] ) ? (int)$parts[1] : 0;
            $s  = isset( $parts[2] ) ? (int)$parts[2] : 0;
        } elseif ( preg_match( '/^\d{3,4}$/', $t ) ) {
            $t = str_pad( $t, 4, '0', STR_PAD_LEFT );
            $h  = (int)substr( $t, 0, 2 );
            $mi = (int)substr( $t, 2, 2 );
        } elseif ( preg_match( '/^\d{1,2}$/', $t ) ) {
            $h = (int)$t;
        } else {
            return null;
        }
        if ( $ampm === 'pm' && $h < 12 )      $h += 12;
        elseif ( $ampm === 'am' && $h === 12 ) $h = 0;
        if ( $h < 0 || $h > 23 || $mi < 0 || $mi > 59 || $s < 0 || $s > 59 ) return null;
        return sprintf( '%02d:%02d:%02d', $h, $mi, $s );
    }

    /**
     * Resolve a tz token (e.g. \"ET\", \"pacific\", \"+0530\", \"Z\") to a
     * display-friendly label. Storage uses the user's compact form so card
     * output matches what they typed.
     */
    private static function resolveTzLabel( string $raw, array $tzMap ): array {
        if ( $raw === '' ) return [];
        $r = strtolower( trim( $raw ) );
        if ( isset( $tzMap[$r] ) ) {
            // Prefer the abbreviated upper-case form if user gave one; otherwise
            // title-case the long-form (\"Eastern\", \"Pacific\").
            $isAbbrev = (bool)preg_match( '/^[a-z]{1,5}$/', $r ) && $r !== 'eastern' && $r !== 'central'
                && $r !== 'mountain' && $r !== 'pacific' && $r !== 'hawaii' && $r !== 'alaska' && $r !== 'zulu';
            $label = $isAbbrev ? strtoupper( $r ) : ucfirst( $r );
            return [ 'label' => $label, 'iana' => $tzMap[$r] ];
        }
        if ( preg_match( '/^([+\-])(\d{2}):?(\d{2})$/', $r, $m ) ) {
            return [ 'label' => 'UTC' . $m[1] . $m[2] . ':' . $m[3], 'iana' => null ];
        }
        if ( $r === 'z' ) {
            return [ 'label' => 'UTC', 'iana' => 'UTC' ];
        }
        return [];
    }

    /** Lowercase tz abbreviation / name -> IANA name. */
    private static function tzAbbrevMap(): array {
        return [
            'et'   => 'America/New_York',  'est'  => 'America/New_York', 'edt'  => 'America/New_York',
            'ct'   => 'America/Chicago',   'cst'  => 'America/Chicago',  'cdt'  => 'America/Chicago',
            'mt'   => 'America/Denver',    'mst'  => 'America/Denver',   'mdt'  => 'America/Denver',
            'pt'   => 'America/Los_Angeles','pst' => 'America/Los_Angeles','pdt' => 'America/Los_Angeles',
            'akt'  => 'America/Anchorage', 'akst' => 'America/Anchorage','akdt' => 'America/Anchorage',
            'hst'  => 'Pacific/Honolulu',  'hast' => 'Pacific/Honolulu', 'hdt'  => 'Pacific/Honolulu',
            'utc'  => 'UTC',  'gmt'  => 'UTC',  'z' => 'UTC',  'zulu' => 'UTC',
            'bst'  => 'Europe/London',
            'cet'  => 'Europe/Paris',      'cest' => 'Europe/Paris',
            'eet'  => 'Europe/Athens',     'eest' => 'Europe/Athens',
            'jst'  => 'Asia/Tokyo',        'kst'  => 'Asia/Seoul',
            'ist'  => 'Asia/Kolkata',
            'aest' => 'Australia/Sydney',  'aedt' => 'Australia/Sydney',
            'awst' => 'Australia/Perth',   'acst' => 'Australia/Adelaide','acdt' => 'Australia/Adelaide',
            'nzst' => 'Pacific/Auckland',  'nzdt' => 'Pacific/Auckland',
            'eastern'  => 'America/New_York',
            'central'  => 'America/Chicago',
            'mountain' => 'America/Denver',
            'pacific'  => 'America/Los_Angeles',
            'hawaii'   => 'Pacific/Honolulu',
            'alaska'   => 'America/Anchorage',
        ];
    }

}
