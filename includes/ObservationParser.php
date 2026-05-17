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
            'subject_text'  => null,
            'refs'          => [],
            'confidence'    => 'low',
            'warnings'      => [],
            'is_episode'    => false,
            'episode_type'  => null,
            'episode_subtype' => null,
        ];

        // 1) Date extraction. Try range first, then point.
        $dateRes = $this->extractDateRange( $working );
        if ( !$dateRes ) {
            $dateRes = $this->extractDate( $working );
        }
        if ( $dateRes ) {
            $result['date_text']   = $dateRes['text'];
            $result['date_struct'] = $dateRes['struct'];
            $working = $dateRes['remaining'];
            if ( !empty( $dateRes['warning'] ) ) $result['warnings'][] = $dateRes['warning'];
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
        // A range date by itself is a strong episode signal.
        if ( $result['date_struct'] && ( $result['date_struct']['kind'] ?? '' ) === 'range' ) {
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
        if ( $total > 0 && $matched < $total ) {
            $unmatched = [];
            foreach ( $result['refs'] as $r ) {
                if ( !$r['matched'] ) $unmatched[] = $r['text'];
            }
            $result['warnings'][] = 'Unrecognized: ' . implode( ', ', $unmatched )
                . ' (stored as free text; link them later).';
        }

        return $result;
    }

    // ===== Date extraction =====

    /**
     * Returns [ 'text' => 'jan 2020', 'struct' => [...DatePicker JSON...], 'remaining' => '...' ]
     * or null if no date found.
     */

    /**
     * Try to extract a date RANGE from working text. Recognizes:
     *   "X to Y", "X till Y", "X until Y", "X through Y", "X thru Y",
     *   "X - Y" (with spaces), "from X to Y", "between X and Y"
     * Returns the same shape as extractDate (struct kind='range') or null.
     */
    private function extractDateRange( string $working ): ?array {
        // Strategy: find the first parseable date in the text. If immediately
        // after that date there's a range separator (till/until/through/thru/
        // to/and/-) followed by another parseable date, return a range struct.
        $a = $this->extractDate( $working );
        if ( !$a ) return null;
        // Locate the matched date phrase in the working text.
        $pos = stripos( $working, $a['text'] );
        if ( $pos === false ) return null;
        $afterFirst = substr( $working, $pos + strlen( $a['text'] ) );
        if ( !preg_match( '/^\s+(till|until|through|thru|to|and|\-)\s+/i', $afterFirst, $sepM ) ) {
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


    private function extractDate( string $working ): ?array {
        // Strip leading "in " before the date if present (consumed).
        // Patterns tried in order of specificity.
        //
        // First: age-relative patterns. These can appear like "anxiety at 7y8mo"
        // or "depressed around age 14" or "happy in childhood". We look for an
        // age token preceded by "at ", "around ", "approx ", "approximately ",
        // "circa ", "when ", "during ", "in ", or none, and try to parse it.
        $ageRe = '/\b(?:at|around|approx(?:imately)?|circa|when|during)?\s*((?:as\s+(?:a|an)\s+(?:newborn|infant|baby|toddler|kid|child|tween|preteen|teen|teenager|young\s+adult))|(?:in\s+(?:toddlerhood|childhood|adolescence|young\s+adulthood|college|high\s+school|middle\s+school|elementary\s+school|grade\s+school))|(?:age\s+\d{1,3})|(?:\d+(?:\.\d+)?\s*yo)|(?:\d+\s*(?:y|yr|yrs|year|years|mo|mos|month|months|w|wk|wks|week|weeks|d|day|days)(?:\s*(?:and\s+)?\d+\s*(?:y|yr|yrs|year|years|mo|mos|month|months|w|wk|wks|week|weeks|d|day|days))*(?:\s+old)?))\b/i';
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
                return $this->applyAge( $age, 0, 0, 0, 'age-year', $phrase, $birthIso );
            }
        }

        // "(around|approx|approximately|circa|~) age N" — coarse year precision
        if ( preg_match( '/^(?:around\s+|approx(?:imately)?\s+|circa\s+|~\s*)?age\s+(\d{1,3})$/i', $p, $m ) ) {
            return $this->applyAge( (int)$m[1], 0, 0, 0, 'age-year', "age {$m[1]}", $birthIso );
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

    private function applyAge( int $y, int $mo, int $w, int $d, string $prec, string $display, ?string $birthIso ): ?array {
        if ( !$birthIso ) {
            // No birthdate set; return a year-precision approximation using
            // 1990 as default birth year (matches PCPDatePicker's DEMO_BIRTH_YEAR).
            $year = 1990 + $y;
            return [
                'text'      => "when {$display} old",
                'iso'       => sprintf( '%04d-01-01', $year ),
                'precision' => 'year',
                'warning'   => 'No birthdate on file; approximated via 1990 birth-year fallback. Add a birthday to Special:MyProfile for accuracy.',
            ];
        }
        $bp = explode( '-', $birthIso );
        $start = new \DateTime( $birthIso );
        $start->modify( "+{$y} years" );
        $start->modify( "+{$mo} months" );
        $start->modify( "+{$d} days" );
        return [
            'text'      => "when {$display} old",
            'iso'       => $start->format( 'Y-m-d' ),
            'precision' => $prec,
        ];
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
}
