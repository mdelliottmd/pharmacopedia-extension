<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * Server-side helpers for the pcp-date-input widget.
 *
 *   renderWidget()   — HTML to embed in a form
 *   parseSubmitted() — validate + normalize POSTed JSON; returns array or null
 *   formatForDisplay() — human-readable string for read-only views
 *   sortKeyIso()     — best-guess earliest ISO timestamp for DB indexing
 */
class DatePicker {

    /** One-time injection flag so we only emit the birthday <script> once per request. */
    private static $birthdayInjected = false;

    /** Maximum permitted JSON payload size in bytes (paranoia / DoS guard). */
    private const MAX_JSON_BYTES = 65536;

    /** Allowed precision values produced by the client parser. */
    private const PRECISIONS = [ 'day', 'month', 'year', 'season', 'decade', 'approx-age', 'age-year', 'age-month', 'age-week', 'age-day' ];

    /**
     * Render the widget HTML. Caller must ensure the page already includes
     * the 'ext.pharmacopedia.datepicker' resource module.
     *
     * @param string $name        Form-field name (becomes the hidden input's name=).
     * @param array|null $initial Optional struct to pre-fill (decoded JSON shape).
     * @return string HTML fragment safe to embed in any form.
     */
    public static function renderWidget( string $name, ?array $initial = null, array $opts = [] ): string {
        $h = static function ( $s ) { return htmlspecialchars( (string)$s, ENT_QUOTES ); };
        $birthdayScript = self::injectBirthdayContextOnce();
        $attrs  = ' data-name="' . $h( $name ) . '"';
        if ( $initial !== null ) {
            $attrs .= ' data-initial="' . $h( json_encode( $initial, JSON_UNESCAPED_UNICODE ) ) . '"';
        }
        if ( !empty( $opts['lock_mode'] ) ) {
            $attrs .= ' data-lock-mode="' . $h( (string)$opts['lock_mode'] ) . '"';
        }
        return $birthdayScript . '<div class="pcp-date-input"' . $attrs . '></div>';
    }

    /**
     * Emit a one-time <script> that sets window.PCPDatePickerBirthday (YYYY-MM-DD)
     * and window.PCPDatePickerBirthYear so the JS parser can resolve age-relative
     * inputs like "11yo" / "6mo" / "3y2m" against the logged-in user's birthday.
     * Returns an empty string after the first call per request, or if no usable
     * birthday is on file.
     */
    private static function injectBirthdayContextOnce(): string {
        if ( self::$birthdayInjected ) return '';
        self::$birthdayInjected = true;
        $ctx = self::currentUserBirthdayContext();
        if ( !$ctx ) return '';
        $js = '';
        if ( !empty( $ctx['birthday'] ) ) {
            $js .= 'window.PCPDatePickerBirthday=' . json_encode( $ctx['birthday'] ) . ';';
        }
        if ( !empty( $ctx['year'] ) ) {
            $js .= 'window.PCPDatePickerBirthYear=' . (int)$ctx['year'] . ';';
        }
        return $js === '' ? '' : '<script>' . $js . '</script>';
    }

    /**
     * Look up the current user's birthday from pcp_profile_fields and extract
     * a Y-M-D (when day-precision) and/or a year.
     * Returns [ 'birthday' => 'YYYY-MM-DD'|null, 'year' => int|null ] or null if
     * no usable value is on file.
     */
    private static function currentUserBirthdayContext(): ?array {
        $user = \RequestContext::getMain()->getUser();
        if ( !$user || !$user->isRegistered() ) return null;
        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        if ( !$profile ) return null;
        $dbr = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $row = $dbr->selectRow(
            'pcp_profile_fields',
            [ 'pf_value_text' ],
            [
                'pf_profile_id' => (int)$profile->prof_id,
                'pf_namespace'  => 'demographics',
                'pf_key'        => 'birthday',
            ],
            __METHOD__
        );
        if ( !$row || !$row->pf_value_text ) return null;
        $val = (string)$row->pf_value_text;
        $iso = null;
        if ( $val !== '' && $val[0] === '{' ) {
            $j = json_decode( $val, true );
            if ( is_array( $j ) ) {
                $point = null;
                if ( ( $j['kind'] ?? '' ) === 'point' && is_array( $j['point'] ?? null ) ) {
                    $point = $j['point'];
                } elseif ( ( $j['kind'] ?? '' ) === 'possibility' && !empty( $j['options'][0]['point'] ) ) {
                    $point = $j['options'][0]['point'];
                }
                if ( $point ) {
                    if ( isset( $point['effective_iso'] ) ) {
                        $iso = (string)$point['effective_iso'];
                    } elseif ( isset( $point['parsed']['iso'] ) ) {
                        $iso = (string)$point['parsed']['iso'];
                    }
                    // Only treat as full birthday if precision is 'day'
                    $prec = $point['parsed']['precision'] ?? null;
                    if ( $prec !== 'day' ) {
                        // Drop full-date claim; keep year-only context
                        if ( $iso && preg_match( '/^(\d{4})/', $iso, $ym ) ) {
                            return [ 'birthday' => null, 'year' => (int)$ym[1] ];
                        }
                        $iso = null;
                    }
                }
            }
        } elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ) {
            $iso = $val;
        }
        if ( $iso && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m ) ) {
            return [ 'birthday' => $iso, 'year' => (int)$m[1] ];
        }
        if ( preg_match( '/(\d{4})/', $val, $m ) ) {
            return [ 'birthday' => null, 'year' => (int)$m[1] ];
        }
        return null;
    }

    /**
     * Build a canonical "point" struct from a legacy YYYY-MM-DD ISO date string.
     * Used to pre-fill the widget when an existing field stored only a plain ISO string.
     */
    public static function structFromIso( string $iso, string $precision = 'day', ?string $display = null ): ?array {
        if ( !preg_match( '/^\d{4}-\d{2}-\d{2}$/', $iso ) ) return null;
        return [
            'kind'  => 'point',
            'point' => [
                'raw_text' => $display ?? $iso,
                'parsed'   => [
                    'kind'      => 'point',
                    'precision' => $precision,
                    'display'   => $display,
                    'year'      => (int)substr( $iso, 0, 4 ),
                    'month'     => (int)substr( $iso, 5, 2 ),
                    'day'       => (int)substr( $iso, 8, 2 ),
                    'iso'       => $iso,
                ],
                'time'          => null,
                'timezone'      => null,
                'effective_iso' => $iso,
            ],
        ];
    }

        /**
     * Validate and normalize a JSON payload submitted from the widget.
     * Returns a sanitized array (kind + sub-fields) or null on any failure.
     */
    public static function parseSubmitted( ?string $json ): ?array {
        if ( $json === null || $json === '' )      return null;
        if ( strlen( $json ) > self::MAX_JSON_BYTES ) return null;
        $raw = json_decode( $json, true );
        if ( !is_array( $raw ) )                   return null;
        $kind = (string)( $raw['kind'] ?? '' );
        switch ( $kind ) {
            case 'point':
                $p = self::normalizeField( $raw['point'] ?? null );
                return $p ? [ 'kind' => 'point', 'point' => $p ] : null;
            case 'range':
                $start = self::normalizeField( $raw['start'] ?? null );
                $end   = self::normalizeField( $raw['end']   ?? null );
                if ( !$start && !$end ) return null;
                return [ 'kind' => 'range', 'start' => $start, 'end' => $end ];
            case 'possibility':
                $opts = [];
                foreach ( ( $raw['options'] ?? [] ) as $opt ) {
                    if ( !is_array( $opt ) ) continue;
                    $optKind = (string)( $opt['kind'] ?? '' );
                    if ( $optKind === 'range' ) {
                        $s = self::normalizeField( $opt['start'] ?? null );
                        $e = self::normalizeField( $opt['end']   ?? null );
                        if ( $s || $e ) {
                            $opts[] = [ 'kind' => 'range', 'start' => $s, 'end' => $e ];
                        }
                    } else {
                        // Treat as point. New shape: { kind:'point', point:{...} }. Legacy/flat: {...}.
                        $point = ( is_array( $opt['point'] ?? null ) ) ? $opt['point'] : $opt;
                        $n = self::normalizeField( $point );
                        if ( $n ) $opts[] = [ 'kind' => 'point', 'point' => $n ];
                    }
                }
                if ( !$opts ) return null;
                return [ 'kind' => 'possibility', 'options' => $opts ];
            default:
                return null;
        }
    }

    /** Per-field sanitization. Returns canonical sub-struct or null if empty/invalid. */
    private static function normalizeField( $f ): ?array {
        if ( !is_array( $f ) )                                    return null;
        $rawText  = isset( $f['raw_text'] ) ? trim( (string)$f['raw_text'] ) : '';
        $parsedIn = is_array( $f['parsed'] ?? null ) ? $f['parsed'] : null;
        $timeIn   = is_array( $f['time']   ?? null ) ? $f['time']   : null;
        $tzIn     = isset( $f['timezone'] ) ? (string)$f['timezone'] : null;
        $effIso   = isset( $f['effective_iso'] ) ? (string)$f['effective_iso'] : null;

        // Drop completely-empty fields
        if ( $rawText === '' && !$parsedIn && !$timeIn ) return null;

        $parsed = null;
        if ( $parsedIn ) {
            $iso = isset( $parsedIn['iso'] ) ? (string)$parsedIn['iso'] : '';
            if ( !preg_match( '/^\d{4}-\d{2}-\d{2}$/', $iso ) ) return null;
            $prec = (string)( $parsedIn['precision'] ?? 'day' );
            if ( !in_array( $prec, self::PRECISIONS, true ) ) $prec = 'day';
            $isoEnd = null;
            if ( isset( $parsedIn['iso_end'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string)$parsedIn['iso_end'] ) ) {
                $isoEnd = (string)$parsedIn['iso_end'];
            }
            $parsed = [
                'kind'      => (string)( $parsedIn['kind'] ?? 'point' ),
                'precision' => $prec,
                'display'   => isset( $parsedIn['display'] ) ? (string)$parsedIn['display'] : null,
                'year'      => (int)( $parsedIn['year']  ?? 0 ),
                'month'     => (int)( $parsedIn['month'] ?? 0 ),
                'day'       => (int)( $parsedIn['day']   ?? 0 ),
                'iso'       => $iso,
                'iso_end'   => $isoEnd,
            ];
        }

        $time = null;
        if ( $timeIn && isset( $timeIn['parsed'] ) && is_string( $timeIn['parsed'] ) ) {
            $t = $timeIn['parsed'];
            if ( preg_match( '/^\d{2}:\d{2}:\d{2}$/', $t ) ) {
                $time = [
                    'raw'    => isset( $timeIn['raw'] ) ? (string)$timeIn['raw'] : null,
                    'parsed' => $t,
                    'error'  => false,
                ];
            }
        }

        $tz = null;
        if ( $tzIn !== null && $tzIn !== '' ) {
            // Accept either IANA-ish format or plain ASCII identifier; reject anything weird.
            if ( preg_match( '/^[A-Za-z][A-Za-z0-9_\/\+\-]{0,63}$/', $tzIn ) ) {
                $tz = $tzIn;
            }
        }

        $out = [
            'raw_text'      => $rawText !== '' ? mb_substr( $rawText, 0, 255 ) : null,
            'parsed'        => $parsed,
            'time'          => $time,
            'timezone'      => $tz,
            'effective_iso' => null,
        ];
        if ( $parsed ) {
            $out['effective_iso'] = $parsed['iso'] . ( $time ? 'T' . $time['parsed'] : '' );
        } elseif ( $effIso && preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2})?$/', $effIso ) ) {
            $out['effective_iso'] = $effIso;
        }

        return $out;
    }

    /**
     * Build a human-readable display string for a sanitized struct.
     * Used in read-only contexts (event cards, profile pages, lists).
     */
    public static function formatForDisplay( ?array $struct ): string {
        if ( !$struct ) return '';
        $kind = (string)( $struct['kind'] ?? '' );
        if ( $kind === 'point' ) {
            return self::formatField( $struct['point'] ?? null );
        }
        if ( $kind === 'range' ) {
            $s = self::formatField( $struct['start'] ?? null );
            $e = self::formatField( $struct['end']   ?? null );
            if ( $s && $e ) return $s . ' – ' . $e;
            if ( $s )       return 'from ' . $s;
            if ( $e )       return 'until ' . $e;
            return '';
        }
        if ( $kind === 'possibility' ) {
            $parts = [];
            foreach ( ( $struct['options'] ?? [] ) as $o ) {
                if ( !is_array( $o ) ) continue;
                $okind = (string)( $o['kind'] ?? '' );
                if ( $okind === 'range' ) {
                    $s = self::formatField( $o['start'] ?? null );
                    $e = self::formatField( $o['end']   ?? null );
                    if ( $s && $e )      $parts[] = $s . ' – ' . $e;
                    elseif ( $s )        $parts[] = 'from ' . $s;
                    elseif ( $e )        $parts[] = 'until ' . $e;
                } else {
                    $point = is_array( $o['point'] ?? null ) ? $o['point'] : $o;
                    $f = self::formatField( $point );
                    if ( $f !== '' ) $parts[] = $f;
                }
            }
            if ( count( $parts ) === 0 ) return '';
            if ( count( $parts ) === 1 ) return $parts[0];
            $last = array_pop( $parts );
            return implode( ', ', $parts ) . ' or ' . $last;
        }
        return '';
    }

    private static function formatField( ?array $f ): string {
        if ( !$f ) return '';
        if ( !empty( $f['raw_text'] ) && !empty( $f['parsed']['display'] ) ) {
            // User typed a fuzzy form (e.g. "summer 2008"); honour their phrasing
            $out = (string)$f['parsed']['display'];
        } elseif ( !empty( $f['parsed']['iso'] ) ) {
            $prec = $f['parsed']['precision'] ?? 'day';
            $iso  = $f['parsed']['iso'];
            if ( $prec === 'year' )  $out = substr( $iso, 0, 4 );
            elseif ( $prec === 'month' ) $out = substr( $iso, 0, 7 );
            elseif ( $prec === 'decade' ) $out = substr( $iso, 0, 3 ) . '0s';
            else $out = $iso;
        } elseif ( !empty( $f['raw_text'] ) ) {
            $out = (string)$f['raw_text'];
        } else {
            return '';
        }
        if ( !empty( $f['time']['parsed'] ) ) {
            $out .= ' ' . $f['time']['parsed'];
            if ( !empty( $f['timezone'] ) ) {
                $out .= ' ' . $f['timezone'];
            }
        }
        return $out;
    }

    /**
     * Best-guess earliest ISO timestamp for the struct, for DB sort indexing.
     * For points: the point's iso. For ranges: start (or end if start absent). For
     * possibility: earliest option.
     */
    public static function sortKeyIso( ?array $struct ): ?string {
        if ( !$struct ) return null;
        $kind = (string)( $struct['kind'] ?? '' );
        $candidates = [];
        $pick = static function ( ?array $f ) use ( &$candidates ) {
            if ( $f && !empty( $f['effective_iso'] ) ) $candidates[] = $f['effective_iso'];
            elseif ( $f && !empty( $f['parsed']['iso'] ) ) $candidates[] = $f['parsed']['iso'];
        };
        if ( $kind === 'point' )       $pick( $struct['point'] ?? null );
        elseif ( $kind === 'range' ) {
            $pick( $struct['start'] ?? null );
            if ( !$candidates ) $pick( $struct['end'] ?? null );
        } elseif ( $kind === 'possibility' ) {
            foreach ( ( $struct['options'] ?? [] ) as $o ) {
                if ( !is_array( $o ) ) continue;
                $okind = (string)( $o['kind'] ?? '' );
                if ( $okind === 'range' ) {
                    $pick( $o['start'] ?? null );
                    $pick( $o['end']   ?? null );
                } else {
                    $pick( is_array( $o['point'] ?? null ) ? $o['point'] : $o );
                }
            }
        }
        if ( !$candidates ) return null;
        sort( $candidates );
        // Trim time portion if present so we keep BINARY(10) compatibility for legacy iso columns
        return substr( $candidates[0], 0, 10 );
    }
}
