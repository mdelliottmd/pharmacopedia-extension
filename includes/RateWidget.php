<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * Reusable .pcp-rate widget: a 0-5 efficacy star rating that is
 * both a display (aggregate fill + 0-5 number) and an input
 * (mouseover / keyboard / touch). Used on problem cards
 * (ProblemTag) and in the COMMON USES sidebar list
 * (CommonUsesTag). The JS interaction + commit-via-
 * action=pharmacopedialikert lives in ext.pharmacopedia.js.
 */
class RateWidget {
    /**
     * @param int    $elementId  pcp_votable_elements row id
     * @param float  $mean       aggregate 0-5
     * @param int    $n          rater count
     * @param string $forTitle   used in the aria-label
     * @return string HTML for the .pcp-rate widget
     */
    public static function render( int $elementId, float $mean, int $n, string $forTitle ): string {
        $m   = max( 0.0, min( 5.0, $mean ) );
        $num = $n > 0 ? number_format( $m, 1 ) : '—';
        $fill = $n > 0 ? sprintf( '%.2f', $m / 5 * 100 ) : '0';
        $valueText = $n > 0 ? ( $num . ' out of 5' ) : 'unrated';
        $h  = '<span class="pcp-rate" role="slider" tabindex="0"';
        $h .= ' data-element-id="' . $elementId . '"';
        $h .= ' data-agg="' . sprintf( '%.2f', $m ) . '"';
        $h .= ' data-agg-n="' . $n . '"';
        $h .= ' aria-label="Rate efficacy for ' . htmlspecialchars( $forTitle ) . '"';
        $h .= ' aria-valuemin="0" aria-valuemax="5"';
        $h .= ' aria-valuenow="' . sprintf( '%.2f', $m ) . '"';
        $h .= ' aria-valuetext="' . htmlspecialchars( $valueText ) . '">';
        $h .= '<span class="pcp-rate-stars" aria-hidden="true">';
        $h .= '<span class="row pcp-rate-empty">&#9733;&#9733;&#9733;&#9733;&#9733;</span>';
        $h .= '<span class="row pcp-rate-fill" style="width:' . $fill . '%">';
        $h .= '&#9733;&#9733;&#9733;&#9733;&#9733;</span>';
        $h .= '<svg class="pcp-rate-your-mark" xmlns="http://www.w3.org/2000/svg"'
            . ' viewBox="0 -10 9.6 18.2" aria-hidden="true">'
            . '<path d="M0,-10 L2.25,-3.09 L9.51,-3.09 L3.63,1.18 L5.88,8.09 L0,3.82 Z"'
            . ' fill="none" stroke="#ffe08a" stroke-width="1.2" stroke-linejoin="round"/>'
            . '</svg>';
        $h .= '</span>';
        $h .= '<span class="pcp-rate-num">' . htmlspecialchars( $num ) . '</span>';
        $h .= '</span>';
        return $h;
    }
}
