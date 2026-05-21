<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * ASRS Screener report renderer, for the SpecialMyAssessment splice.
 *
 * These are private methods of SpecialMyAssessment. At splice time, drop
 * the four methods below into SpecialMyAssessment.php next to the other
 * report blocks, and apply this wiring:
 *
 *   SpecialMyAssessment.php
 *     - use line:  use MediaWiki\Extension\Pharmacopedia\Assessments\Asrs;
 *     - dispatch:  if ( $key === 'asrs' ) { $this->renderAsrsReport( $user ); return; }
 *
 *   SpecialMyProfile.php  (the ASRS is a RADIO assessment, NOT a slider:
 *   it is administered as the published 5-choice instrument, so it goes
 *   in the radio path, NOT $sliderTests / $sliderBounds)
 *     - add 'asrs' to the rich-report key list
 *     - add a renderInlineAssessment( ...Assessments\Asrs::class ) call
 *     - add 'asrs' to $allowedTests
 *     - add 'asrs' => ...Assessments\Asrs::class to $clsMap
 *
 * The renderer recomputes from raw responses (asrs_raw / item_N). The
 * official intro, score note, and the mandatory attribution are rendered
 * VERBATIM from the Asrs class constants; do not paraphrase them.
 *
 * Wrapped in a throwaway class so `php -l` verifies it standalone; drop
 * the wrapper and the use line at splice time.
 */

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Extension\Pharmacopedia\Assessments\Asrs;

/**
 * ASRS Screener report methods, mixed into SpecialMyAssessment via `use`.
 * Dispatch: Special:MyAssessment/asrs -> renderAsrsReport().
 */
trait AsrsReportTrait {

    // ===== ASRS Screener report =====

    private function renderAsrsReport( $user ) {
        $out = $this->getOutput();
        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        $takenAt = null;
        foreach ( $store->getFields( $profileId, 'asrs', 0 ) as $f ) {
            if ( (string)$f->pf_key === 'taken_at' ) {
                $takenAt = (string)$f->pf_value_text;
                break;
            }
        }
        $rawByN = [];
        foreach ( $store->getFields( $profileId, 'asrs_raw', 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) !== 0 ) {
                continue;
            }
            $rawByN[ (int)substr( $k, 5 ) ] = [
                'num'  => $f->pf_value_num,
                'text' => $f->pf_value_text,
            ];
        }

        $out->setPageTitle( 'My ASRS Screener report' );
        if ( !$rawByN ) {
            $out->addWikiTextAsInterface(
                "No ASRS Screener responses on file. Take it on [[Special:MyProfile]] to see your report here."
            );
            return;
        }

        $responses = [];
        foreach ( $rawByN as $n => $entry ) {
            if ( $entry['num'] !== null ) {
                $responses[ (int)$n ] = (int)$entry['num'];
            }
        }
        $scores = Asrs::scoreResponses( $responses );

        $h = '<div class="pcp-cati-report pcp-asrs-report">';

        // Official intro and disclaimer, verbatim.
        $h .= '<div class="pcp-cati-cutoff-box">';
        foreach ( explode( "\n\n", Asrs::INTRO ) as $para ) {
            $h .= '<p>' . htmlspecialchars( $para ) . '</p>';
        }
        $h .= '</div>';

        $h .= '<p style="opacity:0.75;">' . htmlspecialchars( Asrs::FULL_NAME );
        if ( $takenAt ) {
            $h .= ' &middot; Last taken ' . htmlspecialchars( substr( $takenAt, 0, 10 ) );
        }
        $h .= ' &middot; <a href="'
            . htmlspecialchars( SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() )
            . '#asrs-take">Retake</a></p>';

        $h .= '<h2>Your screening result</h2>';
        $h .= $this->renderAsrsResultBox( $scores );

        $canRaw = $this->canViewRaw( $store, $profile, 'asrs', $this->isOwner ?? true );
        $h .= '<h2>Your responses</h2>';
        if ( $canRaw ) {
            $h .= $this->renderAsrsResponseTable( $rawByN );
        } else {
            $h .= $this->renderRawPrivate();
        }

        $h .= '<h2>About this screener</h2>';
        $h .= $this->renderAsrsMethodology();

        $h .= '</div>';
        $out->addHTML( $h );
    }

    private function renderAsrsResultBox( array $scores ): string {
        $h = '<div class="pcp-cati-cutoff-box">';
        if ( empty( $scores['complete'] ) ) {
            $h .= '<p>Incomplete. Answer all 6 questions for a screening result.</p></div>';
            return $h;
        }
        $count = (int)( $scores['shaded_count'] ?? 0 );
        $h .= '<p><strong>' . $count . ' of 6</strong> of your answers fell in the screening '
            . 'range, the official instrument\'s darkly shaded area.</p>';
        if ( !empty( $scores['positive'] ) ) {
            $h .= '<p style="color:#7c3aed;"><strong>This is a positive screen.</strong> '
                . 'Four or more is the screening threshold.</p>';
        } else {
            $h .= '<p>That is below the four-or-more screening threshold, so this is not a '
                . 'positive screen.</p>';
        }
        // Official score note, verbatim.
        $h .= '<p><em>' . htmlspecialchars( Asrs::SCORE_NOTE ) . '</em></p>';
        $h .= '<p>This is a screening questionnaire, not a diagnosis. An accurate diagnosis can '
            . 'only be made through a clinical evaluation.</p>';
        $h .= '</div>';
        return $h;
    }

    private function renderAsrsResponseTable( array $rawByN ): string {
        $labels = Asrs::RESPONSE_LABELS;
        $thresh = Asrs::SCREEN_THRESHOLD;
        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-cati-response-table" style="width:100%; font-size:0.9em;">';
        $h .= '<thead><tr><th>#</th><th>Question</th>';
        foreach ( $labels as $v => $lab ) {
            $h .= '<th style="width:5em; text-align:center;">' . htmlspecialchars( $lab ) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ( Asrs::ITEMS as $n => $text ) {
            $entry = $rawByN[ $n ] ?? null;
            $picked = ( $entry && $entry['num'] !== null ) ? (int)$entry['num'] : null;
            $t = $thresh[ $n ] ?? 99;
            $h .= '<tr><th style="text-align:center;">' . (int)$n . '</th>';
            $h .= '<td>' . htmlspecialchars( $text ) . '</td>';
            foreach ( $labels as $v => $lab ) {
                $isShaded = ( $v >= $t );
                $isPicked = ( $picked === $v );
                $cls = $isPicked ? ' class="pcp-cati-picked"' : '';
                $style = $isShaded ? ' style="text-align:center; background:rgba(124,58,237,0.16);"'
                                   : ' style="text-align:center;"';
                $h .= '<td' . $cls . $style . '>' . ( $isPicked ? '&#x2713;' : '' ) . '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        $h .= '<p style="opacity:0.6; font-size:0.85em;">The tinted cells are the official '
            . 'instrument\'s darkly shaded area; a check there counts toward the screen. Items '
            . '1 to 3 count from "Sometimes", items 4 to 6 from "Often".</p>';
        $h .= '</div>';
        return $h;
    }

    private function renderAsrsMethodology(): string {
        $h  = '<p>The Adult ADHD Self-Report Scale v1.1 (ASRS-v1.1) was developed by the World '
            . 'Health Organization in conjunction with the Workgroup on Adult ADHD. The '
            . '6-Question Screener used here is the subset of the 18-question ASRS-v1.1 Symptom '
            . 'Checklist found most predictive of ADHD. It is reproduced verbatim under '
            . 'licence; it is administered and scored exactly as published.</p>';
        $h .= '<p>Reference: <a href="https://pubmed.ncbi.nlm.nih.gov/15841682/">Kessler RC et '
            . 'al. The World Health Organization Adult ADHD Self-Report Scale (ASRS): a short '
            . 'screening scale for use in the general population. Psychol Med. '
            . '2005;35(2):245-256. PMID 15841682</a>. Background on the instrument: '
            . '<a href="/index.php/Adult_ADHD_Self-Report_Scale">Adult ADHD Self-Report '
            . 'Scale</a>.</p>';
        // Mandatory licence attribution, verbatim.
        $h .= '<p style="font-size:0.85em; opacity:0.85;">' . htmlspecialchars( Asrs::ATTRIBUTION )
            . '</p>';
        return $h;
    }

    // ===== End ASRS Screener report =====
}
