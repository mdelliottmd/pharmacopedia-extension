<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * AMAAS-PCP-SR report renderer, pre-built for step 3.
 *
 * These are private methods of SpecialMyAssessment. At step 3, splice
 * the six methods below into SpecialMyAssessment.php, and apply the
 * dispatch wiring in /tmp/amaas_wiring.md. Drop the throwaway wrapper
 * class and the `use` line; the wrapper exists only so `php -l` can
 * verify this file standalone.
 *
 * The renderer RECOMPUTES from raw responses: it reads amaas_raw/item_N,
 * separates answered items from "Not sure" items, and calls
 * Amaas::scoreResponses() + Amaas::validityFlag(). It does not depend on
 * which aggregate keys the save path persisted.
 *
 * Response model: one 0-100 percent slider per item; every answer is an
 * approximate self-estimate (stated instrument-wide, not per item);
 * "Not sure" items are excluded and the subscales prorate over them.
 */

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Extension\Pharmacopedia\Assessments\Amaas;

/**
 * AMAAS-PCP-SR report methods, mixed into SpecialMyAssessment via `use`.
 * Dispatch: Special:MyAssessment/amaas -> renderAmaasReport().
 */
trait AmaasReportTrait {

    // ===== AMAAS-PCP-SR report =====

    private function renderAmaasReport( $user ) {
        $out = $this->getOutput();
        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        $takenAt = null;
        foreach ( $store->getFields( $profileId, 'amaas', 0 ) as $f ) {
            if ( (string)$f->pf_key === 'taken_at' ) {
                $takenAt = (string)$f->pf_value_text;
                break;
            }
        }
        $rawByN = [];
        foreach ( $store->getFields( $profileId, 'amaas_raw', 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) !== 0 ) {
                continue;
            }
            $rawByN[ (int)substr( $k, 5 ) ] = [
                'num'  => $f->pf_value_num,
                'text' => $f->pf_value_text,
            ];
        }

        $out->setPageTitle( 'My AMAAS-PCP-SR report' );
        if ( !$rawByN ) {
            $out->addWikiTextAsInterface(
                "No AMAAS-PCP-SR responses on file. Take it on [[Special:MyProfile]] to see your report here."
            );
            return;
        }

        // Separate answered items from "Not sure" items, then recompute.
        $responses = [];
        $idkItems  = [];
        foreach ( $rawByN as $n => $entry ) {
            if ( (string)( $entry['text'] ?? '' ) === 'unsure' ) {
                $idkItems[] = (int)$n;
            } elseif ( $entry['num'] !== null ) {
                $responses[ (int)$n ] = (float)$entry['num'];
            }
        }
        $scores = Amaas::scoreResponses( $responses, $idkItems );
        $validity = Amaas::validityFlag( $responses );

        $h = '<div class="pcp-cati-report pcp-amaas-report">';

        $h .= '<div class="pcp-cati-cutoff-box" style="border-left:4px solid #d97757;">';
        $h .= '<p style="margin:0;"><strong>Experimental instrument.</strong> '
            . htmlspecialchars( Amaas::WARNING ) . '</p>';
        $h .= '</div>';

        $h .= '<p style="opacity:0.9;"><strong>' . htmlspecialchars( Amaas::APPROX_NOTE ) . '</strong></p>';

        $h .= '<p style="opacity:0.75;">' . htmlspecialchars( Amaas::FULL_NAME );
        if ( $takenAt ) {
            $h .= ' &middot; Last taken ' . htmlspecialchars( substr( $takenAt, 0, 10 ) );
        }
        $h .= ' &middot; <a href="'
            . htmlspecialchars( SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() )
            . '#amaas-take">Retake</a></p>';

        $h .= '<h2>AMAAS-PCP-SR results</h2>';
        $h .= $this->renderAmaasScoreTable( $scores );

        $h .= '<h2>What your scores mean</h2>';
        $h .= $this->renderAmaasReadingSection( $scores, $validity );

        $concordance = $this->renderAmaasConcordance( $scores, $profileId, $this->isOwner ?? true );
        if ( $concordance !== '' ) {
            $h .= '<h2>Self vs observers</h2>';
            $h .= $concordance;
        }

        $canRaw = $this->canViewRaw( $store, $profile, 'amaas', $this->isOwner ?? true );
        $h .= '<h2>Top-endorsed items per domain</h2>';
        if ( $canRaw ) {
            $h .= '<p style="opacity:0.75; margin-top:-0.3em;">Within each symptom domain, the '
                . 'items you estimated highest. Reverse-worded items are inverted before ranking.</p>';
            $h .= $this->renderAmaasTopItems( $rawByN );
        } else {
            $h .= $this->renderRawPrivate();
        }

        $h .= '<h2>All 30 responses</h2>';
        if ( $canRaw ) {
            $h .= $this->renderAmaasResponseTable( $rawByN );
        } else {
            $h .= $this->renderRawPrivate();
        }

        $h .= '<h2>About AMAAS-PCP-SR</h2>';
        $h .= $this->renderAmaasMethodology();

        $h .= '</div>';
        $out->addHTML( $h );
    }

    private function renderAmaasScoreTable( array $scores ): string {
        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-cati-scores"><thead><tr>';
        $h .= '<th>Domain</th><th>Average</th><th>Items estimated</th><th>Band (descriptive)</th>';
        $h .= '</tr></thead><tbody>';

        foreach ( Amaas::SUBSCALES as $code => $def ) {
            $prorated = $scores[ 'subscale_' . $code ] ?? null;
            $answered = (int)( $scores[ 'answered_' . $code ] ?? 0 );
            $n = count( $def['items'] );
            $h .= '<tr><th style="text-align:left;">' . htmlspecialchars( $def['label'] ) . '</th>';
            if ( $prorated === null ) {
                $h .= '<td colspan="3" style="opacity:0.5;">no items estimated</td></tr>';
                continue;
            }
            $mean = $prorated / $n;
            $band = Amaas::band( (float)$prorated, Amaas::subscaleMax( $code ) );
            $h .= '<td style="text-align:center; font-weight:bold;">' . number_format( $mean, 0 ) . '%</td>';
            $h .= '<td style="text-align:center; opacity:0.7;">' . $answered . ' of ' . $n . '</td>';
            $h .= '<td style="text-align:center;">' . htmlspecialchars( Amaas::bandLabel( $band ) ) . '</td>';
            $h .= '</tr>';
        }

        $imp = $scores['module_IMPAIR'] ?? null;
        $impAns = (int)( $scores['answered_IMPAIR'] ?? 0 );
        $impN = count( Amaas::MODULES['IMPAIR']['items'] );
        $h .= '<tr><th style="text-align:left;">Functional interference</th>';
        if ( $imp === null ) {
            $h .= '<td colspan="3" style="opacity:0.5;">no items estimated</td></tr>';
        } else {
            $impMean = $imp / $impN;
            $impBand = Amaas::band( (float)$imp, (float)( $impN * Amaas::SCALE_MAX ) );
            $impWord = [
                'few'  => 'Low reported interference',
                'some' => 'Moderate reported interference',
                'many' => 'High reported interference',
            ][ $impBand ] ?? 'Not enough responses';
            $h .= '<td style="text-align:center; font-weight:bold;">' . number_format( $impMean, 0 ) . '%</td>';
            $h .= '<td style="text-align:center; opacity:0.7;">' . $impAns . ' of ' . $impN . '</td>';
            $h .= '<td style="text-align:center;">' . htmlspecialchars( $impWord ) . '</td>';
            $h .= '</tr>';
        }

        $h .= '</tbody></table></div>';
        $h .= '<p style="opacity:0.7; font-size:0.9em;">Average is the mean of the items you '
            . 'estimated in that domain ("Not sure" items are left out). Bands are arithmetic '
            . 'thirds of the range, not validated clinical cutoffs.</p>';
        return $h;
    }

    private function renderAmaasReadingSection( array $scores, string $validity ): string {
        $h = '<div class="pcp-cati-cutoff-box">';

        $cIna = (int)( $scores['count_INA'] ?? 0 );
        $aIna = (int)( $scores['answered_INA'] ?? 0 );
        $cHi  = (int)( $scores['count_HI'] ?? 0 );
        $aHi  = (int)( $scores['answered_HI'] ?? 0 );

        if ( $aIna > 0 || $aHi > 0 ) {
            $h .= '<p>Counting items you estimated at 60% of the time or higher: <strong>'
                . $cIna . ' of ' . $aIna . '</strong> inattention items, and <strong>'
                . $cHi . ' of ' . $aHi . '</strong> hyperactivity / impulsivity items.</p>';
        }

        $h .= '<p>For conceptual reference, DSM-5 describes an adult symptom-count landmark of '
            . 'about 5 endorsed symptoms within a dimension. AMAAS-PCP-SR uses more items per '
            . 'dimension than DSM lists symptoms, and every answer here is an approximate '
            . 'estimate, so this is a way of thinking about the pattern, <strong>not a direct '
            . 'score comparison and not a threshold</strong>.</p>';

        $h .= '<p><em>AMAAS-PCP-SR is experimental, approximate, and not a diagnostic instrument.</em> '
            . 'Every answer is a rough self-estimate, so every figure above is approximate. A '
            . 'diagnosis of ADHD is made by a clinician and requires evidence of childhood onset, '
            . 'difficulty in more than one setting, real functional impairment, and ruling out '
            . 'other explanations. This report is a structured reflection on what you estimated, '
            . 'nothing more. For a validated, free self-report covering the same ground, the '
            . 'ASRS-v1.1 is the standard option.</p>';

        if ( $validity === 'invalid' ) {
            $h .= '<p style="color:#b91c1c;"><strong>Response-validity note.</strong> The validity '
                . 'items suggest this set may not be a careful or literal account. Read the rest of '
                . 'this report with strong caution.</p>';
        } elseif ( $validity === 'caution' ) {
            $h .= '<p style="color:#b45309;"><strong>Response-validity note.</strong> One validity '
                . 'check was unusual. The report is probably fine, but interpret gently.</p>';
        } elseif ( $validity === 'not_assessed' ) {
            $h .= '<p style="opacity:0.7;"><strong>Response-validity note.</strong> The validity '
                . 'items were marked "Not sure", so response validity was not assessed.</p>';
        }

        $h .= '</div>';
        return $h;
    }

    private function renderAmaasTopItems( array $rawByN ): string {
        $min = (int)Amaas::SCALE_MIN;
        $max = (int)Amaas::SCALE_MAX;
        $h = '<dl class="pcp-cati-top-items">';
        foreach ( Amaas::SUBSCALES as $code => $def ) {
            $byScore = [];
            foreach ( $def['items'] as $n ) {
                $entry = $rawByN[ $n ] ?? null;
                if ( !$entry || $entry['num'] === null
                    || (string)( $entry['text'] ?? '' ) === 'unsure' ) {
                    continue;
                }
                $raw = (float)$entry['num'];
                $dir = in_array( $n, Amaas::REVERSE, true ) ? ( $max - $raw ) : $raw;
                $byScore[ $n ] = $dir;
            }
            arsort( $byScore );
            $top = array_slice( $byScore, 0, 4, true );
            if ( !$top ) {
                continue;
            }
            $h .= '<dt>' . htmlspecialchars( $def['label'] ) . '</dt><dd><ul class="pcp-cati-top-items-list">';
            foreach ( $top as $n => $dirScore ) {
                $itemText = Amaas::ITEMS[ $n ] ?? ( '(item ' . $n . ')' );
                $raw = (float)$rawByN[ $n ]['num'];
                $rawInt = max( $min, min( $max, (int)round( $raw ) ) );
                $isRev = in_array( $n, Amaas::REVERSE, true );
                $h .= '<li><strong>' . $n . '.</strong> ' . htmlspecialchars( $itemText )
                    . ' <span class="pcp-cati-top-meta">you estimated <em>' . $rawInt . '%</em>'
                    . ( $isRev ? ' <span class="pcp-cati-top-rev" title="reverse-worded item">&#x21bb;</span>' : '' )
                    . '</span></li>';
            }
            $h .= '</ul></dd>';
        }
        $h .= '</dl>';
        return $h;
    }

    private function renderAmaasResponseTable( array $rawByN ): string {
        $min = (int)Amaas::SCALE_MIN;
        $max = (int)Amaas::SCALE_MAX;
        $impairItems = Amaas::MODULES['IMPAIR']['items'];
        $validItems  = Amaas::MODULES['VALID']['items'];

        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-cati-response-table" style="width:100%; font-size:0.9em;">';
        $h .= '<thead><tr><th style="width:2.5em;">#</th><th>Item</th>'
            . '<th style="width:7em; text-align:center;">Your estimate</th></tr></thead><tbody>';

        for ( $n = 1; $n <= 30; $n++ ) {
            $entry = $rawByN[ $n ] ?? null;
            $isUnsure = $entry && (string)( $entry['text'] ?? '' ) === 'unsure';
            $rawNum = ( $entry && $entry['num'] !== null ) ? (float)$entry['num'] : null;
            $itemText = Amaas::ITEMS[ $n ] ?? ( '(item ' . $n . ', not yet authored)' );

            $h .= '<tr><th style="text-align:center;">' . $n . '</th>';
            $h .= '<td>' . htmlspecialchars( $itemText );
            if ( in_array( $n, Amaas::REVERSE, true ) ) {
                $h .= ' <em style="opacity:0.6;">(reverse-worded)</em>';
            }
            if ( in_array( $n, $impairItems, true ) ) {
                $h .= ' <em style="opacity:0.6;">(interference item)</em>';
            } elseif ( in_array( $n, $validItems, true ) ) {
                $h .= ' <em style="opacity:0.6;">(validity item)</em>';
            }
            $h .= '</td>';

            if ( $isUnsure ) {
                $h .= '<td style="text-align:center; opacity:0.55; font-style:italic;">Not sure</td>';
            } elseif ( $rawNum !== null ) {
                $shown = max( $min, min( $max, (int)round( $rawNum ) ) );
                $h .= '<td style="text-align:center; font-weight:bold;">' . $shown . '%</td>';
            } else {
                $h .= '<td style="text-align:center; opacity:0.4;">&ndash;</td>';
            }
            $h .= '</tr>';
        }

        $h .= '</tbody></table>';
        $h .= '<p style="opacity:0.6; font-size:0.85em;">Each answer is the percent of the time '
            . 'you estimated, on a 0 to 100 slider. "Not sure" items were left out of scoring.</p>';
        $h .= '</div>';
        return $h;
    }

    private function renderAmaasMethodology(): string {
        $h  = '<p>AMAAS-PCP-SR is the self-report form of the Adult Multi-perspective Attentional '
            . 'Attributes Scale, an instrument developed for this wiki. It is original, written '
            . 'from the DSM-5 ADHD construct rather than adapted from any existing rating scale. '
            . 'Each item asks, on a 0 to 100 slider, what percent of the time an experience '
            . 'applies; every answer is a rough self-estimate.</p>';
        $h .= '<p><strong>It is experimental and not validated.</strong> It has no norms, no '
            . 'validated cutoffs, and no established sensitivity or specificity. Subscale scores '
            . 'are prorated over the items you estimated ("Not sure" items are excluded); the '
            . 'descriptive bands are arithmetic thirds of the range, chosen for readability, not '
            . 'clinical thresholds. The instrument is on a validation roadmap (expert review, '
            . 'pilot testing, factor analysis, reliability and criterion-validity studies, '
            . 'norming); the experimental label is removed only when that work supports it.</p>';
        $h .= '<p>A planned observer-report form (AMAAS-PCP-OR) will let someone who knows the '
            . 'respondent well rate the same domains, producing a self-versus-observer concordance '
            . 'report. That form is not yet built.</p>';
        $h .= '<p>References: American Psychiatric Association, DSM-5 (ADHD diagnostic criteria); '
            . '<a href="https://pubmed.ncbi.nlm.nih.gov/27189265/">Faraone et al. 2015 '
            . '(PMID 27189265)</a>; <a href="https://pubmed.ncbi.nlm.nih.gov/30453134/">Kooij '
            . 'et al. 2019 (PMID 30453134)</a>; <a href="https://pubmed.ncbi.nlm.nih.gov/33549739/">'
            . 'Faraone et al. 2021 (PMID 33549739)</a>. These support the construct and the '
            . 'adult-presentation framing; they are not item sources.</p>';
        return $h;
    }

    /**
     * Self-vs-observer concordance: the subject's own AMAAS-PCP-SR domain
     * scores beside each AMAAS-PCP-OR observer rating of them, pulled from
     * the Perspective subsystem. The owner sees every observer
     * perspective; a non-owner viewing a shared report sees only those
     * the owner consented to publish. Returns '' when there is nothing
     * to show (so the caller omits the section heading).
     */
    private function renderAmaasConcordance( array $selfScores, int $profileId, bool $isOwner ): string {
        if ( !class_exists( 'MediaWiki\\Extension\\Pharmacopedia\\PerspectiveStore' ) ) {
            return '';
        }
        $store = new \MediaWiki\Extension\Pharmacopedia\PerspectiveStore();
        $obs = [];
        foreach ( $store->listForOwner( $profileId, 'profile', (string)$profileId ) as $p ) {
            if ( (string)$p->psp_perspective_type !== 'amaas_or' ) {
                continue;
            }
            if ( !$isOwner && (int)$p->psp_consent !== 1 ) {
                continue;
            }
            $payload = json_decode( (string)$p->psp_payload, true );
            if ( !is_array( $payload ) ) {
                continue;
            }
            $resp = ( isset( $payload['responses'] ) && is_array( $payload['responses'] ) )
                ? $payload['responses'] : [];
            $idk = ( isset( $payload['idk'] ) && is_array( $payload['idk'] ) )
                ? $payload['idk'] : [];
            $obs[] = [
                'label'  => trim( (string)( $p->psp_giver_label ?? '' ) ),
                'scores' => Amaas::scoreResponses( $resp, $idk ),
            ];
        }
        if ( !$obs ) {
            if ( $isOwner ) {
                return '<p style="opacity:0.75;">No observer ratings yet. You can invite an '
                    . 'observer to rate you from <a href="'
                    . htmlspecialchars( SpecialPage::getTitleFor( 'MyPerspectives' )->getLocalURL() )
                    . '">My perspectives</a>.</p>';
            }
            return '';
        }
        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-cati-scores"><thead><tr><th>Domain</th><th>You</th>';
        foreach ( $obs as $i => $o ) {
            $lab = $o['label'] !== '' ? $o['label'] : ( 'Observer ' . ( $i + 1 ) );
            $h .= '<th>' . htmlspecialchars( $lab ) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ( Amaas::SUBSCALES as $code => $def ) {
            $n = count( $def['items'] );
            $h .= '<tr><th style="text-align:left;">' . htmlspecialchars( $def['label'] ) . '</th>';
            $sRaw = $selfScores[ 'subscale_' . $code ] ?? null;
            $sMean = $sRaw !== null ? $sRaw / $n : null;
            $h .= '<td style="text-align:center; font-weight:bold;">'
                . ( $sMean !== null ? number_format( $sMean, 0 ) . '%' : 'n/a' ) . '</td>';
            foreach ( $obs as $o ) {
                $oRaw = $o['scores'][ 'subscale_' . $code ] ?? null;
                $oMean = $oRaw !== null ? $oRaw / $n : null;
                $h .= '<td style="text-align:center;">'
                    . ( $oMean !== null ? number_format( $oMean, 0 ) . '%' : 'n/a' ) . '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table></div>';
        $h .= '<p style="opacity:0.75; font-size:0.9em;">Your own rating beside each '
            . 'observer\'s. All figures are approximate estimates. Where an observer sees '
            . 'something differently from how you see yourself, that gap is itself worth '
            . 'noticing; it is the point of a multi-perspective scale.</p>';
        return $h;
    }

    // ===== End AMAAS-PCP-SR report =====
}
