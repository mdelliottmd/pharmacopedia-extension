<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * OCI-PCP — Obsessive-Compulsive Inventory, Pharmacopedia adaptation.
 *
 * Adapted from the OCI-R (Obsessive-Compulsive Inventory, Revised):
 * Foa EB, Huppert JD, Leiberman S, Langner R, Kichic R, Hajcak G,
 * Salkovskis PM. (2002). The Obsessive-Compulsive Inventory: development
 * and validation of a short version. Psychological Assessment, 14(4),
 * 485-496. OCI-R items copyright Edna B. Foa 2002.
 *
 * The OCI-PCP keeps the 18 OCI-R items verbatim and the 6-subscale
 * structure, but ADAPTS the response format: continuous 0-4 sliders with
 * a per-item don't-know option, in place of the original discrete 0-4
 * Likert. Because the response format is adapted, the OCI-R cutoff and
 * norms are INHERITED and should be read as approximate, not
 * independently validated for this adapted form.
 *
 * 18 items, 0-4 per item. 6 subscales of 3 items each: Washing,
 * Obsessing, Hoarding, Ordering, Checking, Neutralizing. No
 * reverse-scored items. Subscale score = sum of its 3 items (0-12).
 * Total = sum of all 18 (0-72). OCI-R screening cutoff: total >= 21
 * (Foa et al. 2002).
 */
class Ocipcp {
    public const KEY        = 'ocipcp';
    public const NAME       = 'OCI-PCP';
    public const FULL_NAME  = 'OCI-PCP (Adapted from OCI-R)';
    public const CITATION   = 'Adapted from OCI-R: Foa et al. 2002, Psychol Assess 14(4):485-496. OCI-R items copyright Edna B. Foa 2002.';
    public const DESCRIPTION = 'A self-report screen for obsessive-compulsive symptoms across six domains: Washing, Obsessing, Hoarding, Ordering, Checking, and Neutralizing. 18 items, about 5 minutes. Adapted from OCI-R: the items and subscales are the OCI-R\'s; the response format is the Pharmacopedia continuous-slider form.';
    public const WARNING    = 'A screening measure, not a diagnosis. The continuous-slider response format is adapted from the validated OCI-R, so the screening cutoff and norms here are inherited and approximate. The Hoarding subscale predates the DSM-5 reclassification of hoarding as a separate disorder and does not validly screen for Hoarding Disorder.';
    public const PAGE_SIZE  = 18;

    /** Inherited OCI-R screening cutoff (Foa et al. 2002): total >= 21. */
    public const CUTOFF_TOTAL = 21;
    /** Subscale-level concern guide: a 3-item subscale sum of >= 7.5 is
     *  the OCI-R manual's "mean >= 2.5" threshold expressed as a sum. */
    public const SUBSCALE_CONCERN = 7.5;

    public const RESPONSE_LABELS = [
        0 => 'Not at all',
        1 => 'A little',
        2 => 'Moderately',
        3 => 'A lot',
        4 => 'Extremely',
    ];

    public const ITEMS = [
        1  => 'I have saved up so many things that they get in the way.',
        2  => 'I check things more often than necessary.',
        3  => 'I get upset if objects are not arranged properly.',
        4  => 'I feel compelled to count while I am doing things.',
        5  => 'I find it difficult to touch an object when I know it has been touched by strangers or certain people.',
        6  => 'I find it difficult to control my own thoughts.',
        7  => "I collect things I don't need.",
        8  => 'I repeatedly check doors, windows, drawers, etc.',
        9  => 'I get upset if others change the way I have arranged things.',
        10 => 'I feel I have to repeat certain numbers.',
        11 => 'I sometimes have to wash or clean myself simply because I feel contaminated.',
        12 => 'I am upset by unpleasant thoughts that come into my mind against my will.',
        13 => 'I avoid throwing things away because I am afraid I might need them later.',
        14 => 'I repeatedly check gas and water taps and light switches after turning them off.',
        15 => 'I need things to be arranged in a particular order.',
        16 => 'I feel that there are good and bad numbers.',
        17 => 'I wash my hands more often and longer than necessary.',
        18 => 'I frequently get nasty thoughts and have difficulty in getting rid of them.',
    ];

    /** OCI-PCP has no reverse-scored items. */
    public const REVERSE = [];

    public const SUBSCALES = [
        'WSH' => [ 'label' => 'Washing',      'items' => [ 5, 11, 17 ] ],
        'OBS' => [ 'label' => 'Obsessing',    'items' => [ 6, 12, 18 ] ],
        'HRD' => [ 'label' => 'Hoarding',     'items' => [ 1, 7, 13 ] ],
        'ORD' => [ 'label' => 'Ordering',     'items' => [ 3, 9, 15 ] ],
        'CHK' => [ 'label' => 'Checking',     'items' => [ 2, 8, 14 ] ],
        'NEU' => [ 'label' => 'Neutralizing', 'items' => [ 4, 10, 16 ] ],
    ];

    /**
     * Returns [ 'subscale_WSH' => sum 0-12, ..., 'total' => sum 0-72 ].
     * No reverse-coded items; each response is summed as submitted.
     */
    public static function scoreResponses( array $responses ): array {
        $scores = [];
        foreach ( self::SUBSCALES as $k => $def ) {
            $vals = [];
            foreach ( $def['items'] as $itemN ) {
                if ( isset( $responses[ $itemN ] ) && $responses[ $itemN ] !== '' && $responses[ $itemN ] !== null ) {
                    $vals[] = (float)$responses[ $itemN ];
                }
            }
            $scores[ 'subscale_' . $k ] = $vals ? round( array_sum( $vals ), 2 ) : null;
        }
        $allVals = [];
        foreach ( self::ITEMS as $i => $_ ) {
            if ( isset( $responses[ $i ] ) && $responses[ $i ] !== '' && $responses[ $i ] !== null ) {
                $allVals[] = (float)$responses[ $i ];
            }
        }
        $scores['total'] = $allVals ? round( array_sum( $allVals ), 2 ) : null;
        return $scores;
    }

    public static function interpret( array $scores ): string {
        $t = $scores['total'] ?? null;
        if ( $t === null ) { return 'Incomplete.'; }
        $disp = number_format( (float)$t, $t == (int)$t ? 0 : 1 );
        if ( (float)$t >= self::CUTOFF_TOTAL ) {
            return "Total {$disp} / 72, at or above the OCI-R screening cutoff (21 or more; Foa et al. 2002). Further assessment for OCD is warranted; this is a screen, not a diagnosis.";
        }
        return "Total {$disp} / 72, below the OCI-R screening cutoff (21 or more).";
    }

    /**
     * 6-axis hexagonal radar SVG for the OCI-PCP subscales. Each subscale
     * is a 0-12 sum; the dashed ring marks the OCI-R subscale concern
     * level (manual "mean 2.5" expressed as a 3-item sum of 7.5). Reuses
     * the shared .pcp-up-cati-radar CSS. Returns an <svg> string. Used by
     * both the Special:UserProfile card and the Special:MyAssessment report.
     */
    public static function radarSvg( array $scores ): string {
        $cx = 100; $cy = 100; $maxR = 58;
        $rawLo = 0.0; $span = 12.0;

        // [ scoreKey, angleDeg, shortLabel, fullLabel, labelX, labelY ]
        $axes = [
            [ 'subscale_WSH', 90,   'Washing',  'Washing',      100, 29  ],
            [ 'subscale_CHK', 30,   'Checking', 'Checking',     170, 63  ],
            [ 'subscale_ORD', -30,  'Ordering', 'Ordering',     170, 142 ],
            [ 'subscale_HRD', -90,  'Hoarding', 'Hoarding',     100, 174 ],
            [ 'subscale_NEU', -150, 'Neutral.', 'Neutralizing',  30, 142 ],
            [ 'subscale_OBS', 150,  'Obsess.',  'Obsessing',     30, 63  ],
        ];

        $vertexAtRaw = function ( float $raw, float $deg ) use ( $cx, $cy, $maxR, $rawLo, $span ) {
            $norm = max( 0.0, min( 1.0, ( $raw - $rawLo ) / $span ) );
            $r = $maxR * $norm;
            $rad = deg2rad( $deg );
            return [ $cx + $r * cos( $rad ), $cy - $r * sin( $rad ) ];
        };
        $hexAtRadius = function ( float $r ) use ( $axes, $cx, $cy ) {
            $pts = [];
            foreach ( $axes as [ , $deg, , , , ] ) {
                $rad = deg2rad( $deg );
                $pts[] = number_format( $cx + $r * cos( $rad ), 2 ) . ',' . number_format( $cy - $r * sin( $rad ), 2 );
            }
            return implode( ' ', $pts );
        };

        $ringInner = '<polygon class="ring" points="' . $hexAtRadius( $maxR / 3.0 ) . '"/>';
        $ringOuter = '<polygon class="ring" points="' . $hexAtRadius( $maxR ) . '"/>';

        // Concern ring: regular hexagon at the 7.5/12 subscale-concern radius.
        $concernR = $maxR * ( self::SUBSCALE_CONCERN / $span );
        $concernPoly = '<polygon class="ring-cutoff" points="' . $hexAtRadius( $concernR ) . '"/>';

        $lines = '';
        foreach ( $axes as [ , $deg, , , , ] ) {
            $rad = deg2rad( $deg );
            $lines .= '<line class="axis" x1="' . $cx . '" y1="' . $cy . '" x2="'
                . number_format( $cx + $maxR * cos( $rad ), 2 ) . '" y2="'
                . number_format( $cy - $maxR * sin( $rad ), 2 ) . '"/>';
        }

        // Ticks on the top axis at raw 4, 8, 12.
        $ticks = '';
        foreach ( [ 4, 8, 12 ] as $tr ) {
            [ , $ty ] = $vertexAtRaw( (float)$tr, 90 );
            $ticks .= '<text class="tick-label" x="102" y="' . number_format( $ty + 2, 1 ) . '">' . $tr . '</text>';
        }

        $dataPts = []; $dots = '';
        foreach ( $axes as [ $key, $deg, , , , ] ) {
            $v = isset( $scores[ $key ] ) && $scores[ $key ] !== null ? (float)$scores[ $key ] : 0.0;
            [ $x, $y ] = $vertexAtRaw( $v, $deg );
            $dataPts[] = number_format( $x, 2 ) . ',' . number_format( $y, 2 );
            $isElev = $v >= self::SUBSCALE_CONCERN;
            $dots .= '<circle class="dot' . ( $isElev ? ' is-elev' : '' ) . '" cx="' . number_format( $x, 2 )
                . '" cy="' . number_format( $y, 2 ) . '" r="' . ( $isElev ? '3.5' : '2.6' ) . '"/>';
        }
        $dataPoly = '<polygon class="data" points="' . implode( ' ', $dataPts ) . '"/>';

        $labels = '';
        foreach ( $axes as [ $key, , $shortLabel, $fullLabel, $lx, $ly ] ) {
            $v = isset( $scores[ $key ] ) && $scores[ $key ] !== null ? (float)$scores[ $key ] : 0.0;
            $cls = 'label' . ( $v >= self::SUBSCALE_CONCERN ? ' is-elev' : '' );
            $labels .= '<text class="' . $cls . '" x="' . $lx . '" y="' . $ly . '">'
                . '<title>' . htmlspecialchars( $fullLabel ) . '</title>'
                . htmlspecialchars( $shortLabel ) . '</text>';
        }

        return '<svg class="pcp-up-cati-radar pcp-up-ocipcp-radar" viewBox="0 0 200 200">'
            . $ringInner . $ringOuter . $concernPoly
            . '<g>' . $lines . '</g>'
            . $ticks . $dataPoly . $dots . $labels
            . '</svg>';
    }
}
