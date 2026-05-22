<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\Extension\Pharmacopedia\Assessments\Amaas;

/**
 * AmaasObserverHandler: the AMAAS-PCP-OR perspective-type handler, the
 * Observer-Report form of the Adult Multi-perspective Attentional
 * Attributes Scale. Consumer #1 of the Perspective subsystem.
 *
 * An observer, invited by a subject, rates the subject on 30 items that
 * mirror AMAAS-SR re-pointed to observer phrasing. Scoring reuses the
 * Amaas class unchanged (it is response-form agnostic); this handler
 * adds only the OR item text, the form, and the self-vs-observer
 * concordance summary.
 *
 * See perspective_subsystem_spec.md and amaas_or_design.md.
 */
class AmaasObserverHandler implements PerspectiveTypeHandler {

    /**
     * The 30 observer-phrased item stems, the re-point of the AMAAS-SR
     * stems to observer phrasing (delivered by home-claude 2026-05-20).
     * Same numbering, same 0-100 scale, same reverse items (3, 8, 14,
     * 19), same validity items (28, 29 infrequency; 30 the consistency
     * partner of item 7).
     */
    public const ITEMS_OR = [
        1  => "What percent of the time does this person lose focus on a task before they finish it?",
        2  => "What percent of the time does this person make careless mistakes by missing small details?",
        3  => "What percent of the time can this person read or listen to someone for a long time without their mind wandering?",
        4  => "What percent of the time does this person move on to something new before they finish what they were doing?",
        5  => "What percent of the time does this person struggle to keep themselves organized?",
        6  => "What percent of the time does this person put off tasks that take a long stretch of concentration?",
        7  => "What percent of the time does this person misplace everyday things such as their keys, phone, or wallet?",
        8  => "What percent of the time can this person tune out noise and activity around them and stay on what they are doing?",
        9  => "What percent of the time does this person forget to do routine tasks, such as errands or chores?",
        10 => "What percent of the time does this person feel restless inside even when they are sitting quietly?",
        11 => "What percent of the time does this person feel driven to stay busy, as though they cannot slow down?",
        12 => "What percent of the time does this person find it hard to unwind, even when there is nothing they need to do?",
        13 => "What percent of the time does this person fidget with their hands or feet when they stay in one place?",
        14 => "What percent of the time can this person sit still comfortably for a long time?",
        15 => "What percent of the time does this person feel an urge to get up and move when they are expected to stay seated?",
        16 => "What percent of the time does this person act on impulse without thinking things through?",
        17 => "What percent of the time does this person interrupt others while they are speaking?",
        18 => "What percent of the time does this person find it hard to wait their turn, such as in a line?",
        19 => "What percent of the time does this person think carefully about whether to say something before they say it?",
        20 => "What percent of the time does this person buy things on impulse that they did not plan to buy?",
        21 => "What percent of the time does this person feel impatient when things move more slowly than they would like?",
        22 => "What percent of the time do these patterns get in the way of this person's work or studies?",
        23 => "What percent of the time do these patterns get in the way of this person handling everyday tasks at home?",
        24 => "What percent of the time do these patterns get in the way of this person's close relationships?",
        25 => "What percent of the time do these patterns get in the way of this person managing their time?",
        26 => "What percent of the time do these patterns get in the way of this person managing their money?",
        27 => "What percent of the time do these patterns get in the way of this person taking care of themselves day to day?",
        28 => "What percent of the time does this person get lost inside their own home?",
        29 => "What percent of the time does this person forget the names of their own close family members?",
        30 => "What percent of the time does this person lose track of where they put everyday items like their keys or phone?",
    ];

    /** AMAAS-PCP-OR descriptor shown to the invitee (home-claude copy). */
    public const DESCRIPTOR =
        "This questionnaire, AMAAS-PCP-OR, asks about everyday attention, restlessness, and "
        . "impulsivity in adults; it is an experimental, approximate tool, not a diagnosis, "
        . "and nothing is decided from your answers alone.";

    /** AMAAS-PCP-OR answering instruction (home-claude copy; {NAME} = the owner-chosen display name). */
    public const ANSWER_INSTRUCTION =
        "For each question, think about how things have been for {NAME} over the past six "
        . "months and move the slider to the percent of the time that best matches what you "
        . "have seen. If you genuinely cannot judge a question, use 'Not sure'; that question "
        . "is then left out of the results rather than counted as a guess.";

    public function typeKey(): string {
        return 'amaas_or';
    }

    public function label(): string {
        return 'AMAAS-PCP-OR (observer report)';
    }

    /**
     * The observer form: the owner's chosen display name, then the 30
     * items, each a 0-100 slider with a "Not sure" checkbox. Returns the
     * inner form content; Special:Perspective supplies the <form>
     * wrapper, the invite token, Turnstile, and the submit control.
     *
     * CSP-clean: plain HTML controls only, no inline script, style, or
     * event handlers. A ResourceLoader module enhances the sliders.
     */
    public function renderForm( \stdClass $invite ): string {
        $name = htmlspecialchars( (string)$invite->pvi_display_name );
        $h  = '<div class="pcp-perspective-form pcp-amaas-or-form">';
        $h .= '<p class="pcp-perspective-descriptor">'
            . htmlspecialchars( self::DESCRIPTOR ) . '</p>';
        $h .= '<p class="pcp-perspective-help">'
            . str_replace( '{NAME}', $name, htmlspecialchars( self::ANSWER_INSTRUCTION ) ) . '</p>';
        // Progress indicator container (designer-claude request); a
        // ResourceLoader module fills the count and bar as items are answered.
        $h .= '<div class="pcp-perspective-progress" data-total="' . count( self::ITEMS_OR ) . '">'
            . '<span class="pcp-perspective-progress-count"></span>'
            . '<span class="pcp-perspective-progress-bar"></span></div>';
        $h .= '<ol class="pcp-perspective-items">';
        foreach ( self::ITEMS_OR as $n => $text ) {
            $n = (int)$n;
            $itemId = 'pcp-or-item-' . $n;
            $h .= '<li class="pcp-perspective-item" data-itemnum="' . $n . '">';
            $h .= '<div class="pcp-perspective-item-text" id="' . $itemId . '">' . $n . '. '
                . htmlspecialchars( (string)$text ) . '</div>';
            $h .= '<div class="pcp-perspective-slider-row">';
            $h .= '<span class="pcp-perspective-anchor">Never (0%)</span>';
            $h .= '<input type="range" class="pcp-perspective-slider" name="r[' . $n . ']" '
                . 'min="0" max="100" step="1" value="50" aria-labelledby="' . $itemId . '">';
            $h .= '<output class="pcp-perspective-out">50</output>';
            $h .= '<span class="pcp-perspective-anchor">Always (100%)</span>';
            $h .= '</div>';
            $h .= '<label class="pcp-perspective-idk">'
                . '<input type="checkbox" name="idk[' . $n . ']" value="1"> Not sure</label>';
            $h .= '</li>';
        }
        $h .= '</ol></div>';
        return $h;
    }

    /**
     * Parse the submission into the payload:
     *   responses  [ itemN => 0-100 int ] for items not marked "Not sure"
     *   idk        [ itemN, ... ] for items marked "Not sure"
     *
     * @param \MediaWiki\Request\WebRequest $request
     */
    public function parseSubmission( $request ): array {
        $r   = $request->getArray( 'r' ) ?: [];
        $idk = $request->getArray( 'idk' ) ?: [];
        $responses = [];
        $idkItems  = [];
        foreach ( self::ITEMS_OR as $n => $text ) {
            $n = (int)$n;
            if ( isset( $idk[ $n ] ) && (string)$idk[ $n ] === '1' ) {
                $idkItems[] = $n;
                continue;
            }
            if ( isset( $r[ $n ] ) && $r[ $n ] !== '' && $r[ $n ] !== null ) {
                $v = (int)round( (float)$r[ $n ] );
                $v = max( 0, min( 100, $v ) );
                $responses[ $n ] = $v;
            }
        }
        return [ 'responses' => $responses, 'idk' => $idkItems ];
    }

    /**
     * Quality status. Returns Amaas::validityFlag's short code directly,
     * one of 'none' / 'caution' / 'invalid' / 'not_assessed'. The code is
     * stored verbatim in psp_validity (16 bytes is ample); the consent
     * inbox maps the code to a display treatment, keeping pass / flagged
     * / not-assessed distinct. 'not_assessed' (the observer marked the
     * validity items "Not sure") must not collapse into a clean pass.
     */
    public function validity( array $payload ): ?string {
        $responses = ( isset( $payload['responses'] ) && is_array( $payload['responses'] ) )
            ? $payload['responses'] : [];
        return Amaas::validityFlag( $responses );
    }

    /**
     * Owner-facing summary: the self-vs-observer concordance. Scores the
     * observer's responses, looks up the owner's own AMAAS-SR result,
     * and shows them side by side with the gap. If the owner has no
     * self-result yet, shows the observer alone.
     */
    public function summarize( \stdClass $perspective ): string {
        $payload = json_decode( (string)$perspective->psp_payload, true );
        if ( !is_array( $payload ) ) {
            $payload = [];
        }
        $obsResponses = ( isset( $payload['responses'] ) && is_array( $payload['responses'] ) )
            ? $payload['responses'] : [];
        $obsIdk = ( isset( $payload['idk'] ) && is_array( $payload['idk'] ) )
            ? $payload['idk'] : [];
        $obs  = Amaas::scoreResponses( $obsResponses, $obsIdk );
        $self = $this->ownerSelfScores( (int)$perspective->psp_owner_id );

        $h  = '<div class="pcp-amaas-or-summary">';
        $h .= '<table class="wikitable pcp-cati-scores"><thead><tr><th>Domain</th>'
            . '<th>Observer</th>'
            . ( $self !== null ? '<th>You</th><th>Gap</th>' : '' )
            . '</tr></thead><tbody>';
        foreach ( Amaas::SUBSCALES as $code => $def ) {
            $n = count( $def['items'] );
            $oRaw = $obs[ 'subscale_' . $code ] ?? null;
            $oMean = $oRaw !== null ? $oRaw / $n : null;
            $h .= '<tr><th style="text-align:left;">' . htmlspecialchars( $def['label'] ) . '</th>';
            $h .= '<td style="text-align:center;">'
                . ( $oMean !== null ? number_format( $oMean, 0 ) . '%' : 'n/a' ) . '</td>';
            if ( $self !== null ) {
                $sRaw = $self[ 'subscale_' . $code ] ?? null;
                $sMean = $sRaw !== null ? $sRaw / $n : null;
                $h .= '<td style="text-align:center;">'
                    . ( $sMean !== null ? number_format( $sMean, 0 ) . '%' : 'n/a' ) . '</td>';
                if ( $oMean !== null && $sMean !== null ) {
                    $h .= '<td style="text-align:center;">'
                        . number_format( abs( $oMean - $sMean ), 0 ) . '</td>';
                } else {
                    $h .= '<td style="text-align:center;">n/a</td>';
                }
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        if ( $self === null ) {
            $h .= '<p style="opacity:0.75;">You have not taken AMAAS-SR yet, so there is no '
                . 'self-rating to compare against. Take it to see the concordance.</p>';
        }
        $h .= '<p style="opacity:0.75; font-size:0.9em;">All figures are approximate estimates, '
            . 'self and observer alike, not measurements.</p>';
        $h .= '</div>';
        return $h;
    }

    /**
     * The owner's own AMAAS-SR scores, recomputed from their stored raw
     * responses (the amaas_raw profile namespace), or null if they have
     * not taken AMAAS-SR.
     */
    private function ownerSelfScores( int $ownerProfileId ): ?array {
        $store = new UserProfileStore();
        $responses = [];
        $idk = [];
        $any = false;
        foreach ( $store->getFields( $ownerProfileId, 'amaas_raw', 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) !== 0 ) {
                continue;
            }
            $any = true;
            $n = (int)substr( $k, 5 );
            if ( (string)( $f->pf_value_text ?? '' ) === 'unsure' ) {
                $idk[] = $n;
            } elseif ( $f->pf_value_num !== null ) {
                $responses[ $n ] = (float)$f->pf_value_num;
            }
        }
        if ( !$any ) {
            return null;
        }
        return Amaas::scoreResponses( $responses, $idk );
    }
}
