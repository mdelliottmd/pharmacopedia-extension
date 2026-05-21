<?php
/**
 * KeyframeValueNormalizer
 *
 * Pure static utility for normalizing free-text keyframe values to the
 * canonical 0-100 scale used by the trait-trajectory graph.
 *
 * Accepts (in priority order):
 *   1. Letter grade        "B+"           -> 88
 *   2. Stars w/ total      "3/5 stars"    -> 60
 *   3. Unicode star string "###--" (mix)  -> filled / total * 100
 *   4. Percent             "40%"          -> 40
 *   5. Fraction            "4/10"         -> 40
 *   6. Leading-decimal     ".4" / "0.4"   -> 40
 *   7. Plain number        "40"           -> 40
 *
 * Values OUTSIDE 0-100 are PRESERVED (not clamped). The trait-graph's
 * Y-axis is responsible for extending its range when in_range = false.
 *
 * Return shape:
 *   [
 *     'value'    => float|null,  // normalized 0-100 (null on reject)
 *     'raw'      => string,      // original input echoed back
 *     'form'     => string|null, // grade|stars|percent|fraction|decimal|plain|null
 *     'in_range' => bool,        // true iff 0 <= value <= 100
 *     'error'    => string|null, // human-readable reason on reject
 *   ]
 *
 * Always returns floats for precision. 1/3 -> 33.333333... (not 33).
 *
 * @license GPL-3.0-or-later
 */

namespace MediaWiki\Extension\Pharmacopedia;

class KeyframeValueNormalizer {

	/**
	 * Letter-grade table. Band-midpoints, A+ caps at 100.
	 * Pattern: -3 inside a letter band, -4 between letter bands.
	 * F is set to 25 by project decision (clearly failing, not a polite 50).
	 * F+ and F- aren't conventional but are accepted for internal consistency
	 * via the standard +/- = 3-point offset.
	 */
	private const GRADES = [
		'A+' => 100.0, 'A' => 95.0,  'A-' => 92.0,
		'B+' => 88.0,  'B' => 85.0,  'B-' => 82.0,
		'C+' => 78.0,  'C' => 75.0,  'C-' => 72.0,
		'D+' => 68.0,  'D' => 65.0,  'D-' => 62.0,
		'F+' => 28.0,  'F' => 25.0,  'F-' => 22.0,
	];

	/**
	 * Normalize a free-text keyframe value to the 0-100 scale.
	 *
	 * @param string $raw User-provided input.
	 * @return array See class docblock for shape.
	 */
	public static function normalize( string $raw ): array {
		$original = $raw;
		$s = trim( $raw );

		// Empty
		if ( $s === '' ) {
			return self::reject( $original, 'empty input' );
		}

		// 1. Letter grade: A, A+, A-, B, B+, ... F+, F-
		if ( preg_match( '/^([A-DF])([+-])?$/i', $s, $m ) ) {
			$key = strtoupper( $m[1] ) . ( $m[2] ?? '' );
			if ( isset( self::GRADES[$key] ) ) {
				return self::accept( $original, self::GRADES[$key], 'grade' );
			}
			return self::reject( $original, "unrecognized grade: $key" );
		}

		// 2. Stars with explicit total: "3/5 stars", "4.5 / 5 star"
		if ( preg_match( '/^(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)\s*stars?$/i', $s, $m ) ) {
			$num   = (float)$m[1];
			$denom = (float)$m[2];
			if ( $denom == 0.0 ) {
				return self::reject( $original, 'star denominator is zero' );
			}
			return self::accept( $original, ( $num / $denom ) * 100.0, 'stars' );
		}

		// 3. Unicode star strings: mix of filled (*, U+2605, U+2B50) + empty (U+2606)
		// Accepts: "###--" (3 of 5), "####" (4 of 4 OR 4/5 -> assume 5-star), etc.
		if ( preg_match( '/^[\x{2605}\x{2B50}\x{2606}]+$/u', $s ) ) {
			$filledCount = preg_match_all( '/[\x{2605}\x{2B50}]/u', $s );
			$emptyCount  = preg_match_all( '/[\x{2606}]/u', $s );
			$total       = $filledCount + $emptyCount;
			// If only filled stars present, assume 5-star scale (common convention).
			if ( $emptyCount === 0 ) {
				$total = 5;
			}
			if ( $total === 0 ) {
				return self::reject( $original, 'no stars parsed' );
			}
			return self::accept( $original, ( $filledCount / $total ) * 100.0, 'stars' );
		}

		// 4. Percent: "40%", "12.5%", "-5%", "200%"
		if ( preg_match( '/^([-+]?\d+(?:\.\d+)?)\s*%$/', $s, $m ) ) {
			return self::accept( $original, (float)$m[1], 'percent' );
		}

		// 5. Fraction: "4/10", "1/3", "-1/4"
		if ( preg_match( '/^([-+]?\d+(?:\.\d+)?)\s*\/\s*([-+]?\d+(?:\.\d+)?)$/', $s, $m ) ) {
			$num   = (float)$m[1];
			$denom = (float)$m[2];
			if ( $denom == 0.0 ) {
				return self::reject( $original, 'fraction denominator is zero' );
			}
			return self::accept( $original, ( $num / $denom ) * 100.0, 'fraction' );
		}

		// 6. Leading-decimal: ".4", "0.4", "-0.04", "+.999"
		// Must have a leading dot OR leading 0-then-dot. Excludes "1.5" (handled by step 7).
		if ( preg_match( '/^[-+]?(?:0)?\.\d+$/', $s ) ) {
			return self::accept( $original, ( (float)$s ) * 100.0, 'decimal' );
		}

		// 7. Plain number: "40", "85.5", "-5", "200", "1.5", "1.0"
		// Already on 0-100 scale, use as-is.
		if ( preg_match( '/^[-+]?\d+(?:\.\d+)?$/', $s ) ) {
			return self::accept( $original, (float)$s, 'plain' );
		}

		return self::reject( $original, "unparseable: '$s'" );
	}

	/**
	 * Accept path: build success return shape.
	 */
	private static function accept( string $raw, float $value, string $form ): array {
		return [
			'value'    => $value,
			'raw'      => $raw,
			'form'     => $form,
			'in_range' => ( $value >= 0.0 && $value <= 100.0 ),
			'error'    => null,
		];
	}

	/**
	 * Reject path: build failure return shape.
	 */
	private static function reject( string $raw, string $error ): array {
		return [
			'value'    => null,
			'raw'      => $raw,
			'form'     => null,
			'in_range' => false,
			'error'    => $error,
		];
	}
}

/* ============================================================
   Self-test cases (for reference; not executed in production).
   To run:  php -r "require 'KeyframeValueNormalizer.php'; ...test loop..."

   Input            -> Expected value | form
   -----------------+------------------+----------
   "40%"            ->  40.0          | percent
   "100%"           -> 100.0          | percent
   "0%"             ->   0.0          | percent
   "200%"           -> 200.0          | percent       (NOT clamped, in_range=false)
   "-5%"            ->  -5.0          | percent       (NOT clamped, in_range=false)
   "40"             ->  40.0          | plain
   "85.5"           ->  85.5          | plain
   "1.5"            ->   1.5          | plain         (NOT *100; >= 1 treated as 0-100)
   "1.0"            ->   1.0          | plain
   "0"              ->   0.0          | plain
   "200"            -> 200.0          | plain         (in_range=false)
   "-5"             ->  -5.0          | plain         (in_range=false)
   "4/10"           ->  40.0          | fraction
   "1/3"            ->  33.3333...    | fraction
   "10/10"          -> 100.0          | fraction
   "3/5 stars"      ->  60.0          | stars
   "4.5/5 star"     ->  90.0          | stars
   ".4"             ->  40.0          | decimal
   "0.4"            ->  40.0          | decimal
   "0.04"           ->   4.0          | decimal
   ".999"           ->  99.9          | decimal
   "-.5"            -> -50.0          | decimal       (in_range=false)
   "A+"             -> 100.0          | grade
   "A"              ->  95.0          | grade
   "A-"             ->  92.0          | grade
   "B+"             ->  88.0          | grade
   "B"              ->  85.0          | grade
   "C-"             ->  72.0          | grade
   "D"              ->  65.0          | grade
   "F"              ->  25.0          | grade
   "f+"             ->  28.0          | grade         (case-insensitive)
   "***--" (3 of 5) ->  60.0          | stars         (U+2605 + U+2606)
   "*****"          -> 100.0          | stars         (5 of 5, no empties)
   ""               -> null           | null          (error: empty)
   "abc"            -> null           | null          (error: unparseable)
   "5/0"            -> null           | null          (error: denominator zero)
   "B++"            -> null           | null          (error: unparseable)
   "1.5%/10"        -> null           | null          (error: unparseable)
   ============================================================ */
