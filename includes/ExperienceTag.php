<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\Context\RequestContext;

/**
 * <pharmaExperience/> -- the per-medicine (Personal/Clinical) Experience section.
 */
class ExperienceTag {
    public static function render( $input, array $args, $parser, $frame ) {
        $title = $parser->getTitle();
        if ( !$title ) { return ''; }
        $pageId = $title->getArticleID();
        if ( $pageId <= 0 ) { return ''; }

        $parser->getOutput()->updateCacheExpiry( 0 );
        $parser->getOutput()->addModules( [ 'ext.pharmacopedia' ] );
        $parser->getOutput()->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        $store = new ExperienceStore();
        $user = RequestContext::getMain()->getUser();
        $loggedIn = $user->isRegistered();
        $canProvider = $loggedIn && $user->isAllowed( 'pharmacopedia-effect-as-provider' );

        $personal = $store->getApprovedAggregates( $pageId, ExperienceStore::PERSPECTIVE_PERSONAL );
        $clinical = $store->getApprovedAggregates( $pageId, ExperienceStore::PERSPECTIVE_CLINICAL );

        $h  = '<div class="pcp-experience" data-page-id="' . (int)$pageId . '"';
        $h .= ' data-logged-in="' . ( $loggedIn ? '1' : '0' ) . '"';
        $h .= ' data-can-provider="' . ( $canProvider ? '1' : '0' ) . '">';

        $h .= self::renderReadout( $personal, $clinical );

        if ( !$loggedIn ) {
            $h .= '<p class="pcp-xp-loginnote">Log in to add your own experience.</p>';
        } else {
            $mine = $store->getForUserAll( $pageId, $user->getId() );
            $h .= self::renderInteractive( $mine, $canProvider, $pageId, $store );
        }

        $h .= '</div>';
        return $h;
    }

    private static function renderReadout( $personal, $clinical ) {
        $h = '<div class="pcp-xp-readout">';

        $h .= '<div class="pcp-xp-readout-line pcp-xp-readout-personal">';
        $h .= '<span class="pcp-xp-icon">&#128101;</span> ';
        if ( (int)$personal['n'] > 0 ) {
            $parts = [ '<strong>' . (int)$personal['n'] . '</strong> personal report' . ( (int)$personal['n'] === 1 ? '' : 's' ) ];
            if ( $personal['efficacy_mean'] !== null ) {
                $parts[] = 'avg efficacy ' . number_format( (float)$personal['efficacy_mean'], 1 ) . '/100';
            }
            if ( $personal['burden_mean'] !== null ) {
                $parts[] = 'avg side-effect burden ' . number_format( (float)$personal['burden_mean'], 1 ) . '/100';
            }
            if ( $personal['duration_median_days'] !== null ) {
                $parts[] = 'median use ' . ExperienceStore::formatDuration( $personal['duration_median_days'] );
            }
            if ( isset( $personal['dose_median_mg'] ) && $personal['dose_median_mg'] !== null ) {
                $parts[] = 'median dose ' . ExperienceStore::formatDose( $personal['dose_median_mg'] ) . ' mg/day';
            }
            if ( $personal['current_pct'] !== null ) {
                $parts[] = $personal['current_pct'] . '% still taking it';
            }
            $h .= implode( ' &middot; ', $parts );
        } else {
            $h .= '<span class="pcp-xp-empty">No personal reports yet</span>';
        }
        $h .= '</div>';

        $h .= '<div class="pcp-xp-readout-line pcp-xp-readout-clinical">';
        $h .= '<span class="pcp-xp-icon">&#9877;</span> ';
        if ( (int)$clinical['n'] > 0 ) {
            $n = (int)$clinical['n'];
            $parts = [ '<strong>' . $n . '</strong> provider report' . ( $n === 1 ? '' : 's' ) ];
            if ( $clinical['efficacy_mean'] !== null ) {
                $parts[] = 'avg efficacy ' . number_format( (float)$clinical['efficacy_mean'], 1 ) . '/100';
            }
            if ( $clinical['burden_mean'] !== null ) {
                $parts[] = 'avg side-effect burden ' . number_format( (float)$clinical['burden_mean'], 1 ) . '/100';
            }
            if ( $clinical['patient_count_sum'] !== null && (int)$clinical['patient_count_sum'] > 0 ) {
                $parts[] = number_format( (int)$clinical['patient_count_sum'] ) . ' patients managed total';
            }
            $h .= implode( ' &middot; ', $parts );
        } else {
            $h .= '<span class="pcp-xp-empty">No clinical reports yet</span>';
        }
        $h .= '</div>';

        $h .= '</div>';
        return $h;
    }

    private static function renderInteractive( $mine, $canProvider, $pageId, $store ) {
        $h = '<div class="pcp-xp-interactive">';

        if ( $mine ) {
            foreach ( $mine as $persp => $row ) {
                $h .= self::renderMyReport( (int)$persp, $row );
                $h .= '<div class="pcp-xp-edit-mount" hidden>';
                $h .= self::renderForm( $pageId, $canProvider, $row, $store );
                $h .= '</div>';
            }
        } else {
            $gateQ = $canProvider
                ? 'Do you have experience with this medicine?'
                : 'Do you have personal experience with this medicine?';
            $h .= '<div class="pcp-xp-gate">';
            $h .= '<span class="pcp-xp-gate-q">' . htmlspecialchars( $gateQ ) . '</span> ';
            $h .= '<button type="button" class="pcp-xp-gate-yes">Yes</button> ';
            $h .= '<button type="button" class="pcp-xp-gate-no">No</button>';
            $h .= '</div>';
            $h .= '<div class="pcp-xp-form-mount" hidden>';
            $h .= self::renderForm( $pageId, $canProvider, null, $store );
            $h .= '</div>';
            $h .= '<a href="#" class="pcp-xp-reopen" hidden>&#8624; I do have experience with this</a>';
        }

        $h .= '</div>';
        return $h;
    }

    /**
     * @param int        $pageId
     * @param bool       $canProvider
     * @param object|null $editRow  When set, the form renders in edit mode:
     *                              perspective locked, no toggle, data-prefill emitted.
     * @param ExperienceStore|null $store  Needed for edit-mode prefill.
     */
    private static function renderForm( $pageId, $canProvider, $editRow = null, $store = null ) {
        $pid = (int)$pageId;
        $editMode = ( $editRow !== null );
        $perspective = $editMode ? (int)$editRow->xr_perspective : 1;
        $formKey = $editMode ? (string)$perspective : 'new';

        // Build the data-prefill JSON for edit mode.
        $prefillAttr = '';
        if ( $editMode && $store ) {
            $payload = $store->decodePayload( $editRow );
            $dur = ExperienceStore::denormalizeDuration(
                $editRow->xr_duration_days !== null ? (int)$editRow->xr_duration_days : 0
            );
            $globalInd = new ProblemStore();
            $globalEff = new GlobalEffectStore();
            $inds = [];
            foreach ( ( $payload['indications'] ?? [] ) as $it ) {
                $label = $it['new_name'] ?? '';
                if ( !empty( $it['ref'] ) ) {
                    $g = $globalInd->resolve( $it['ref'] );
                    $label = $g ? $g->p_name : str_replace( '_', ' ', $it['ref'] );
                }
                $inds[] = [
                    'ref'      => $it['ref'] ?? null,
                    'new_name' => $it['new_name'] ?? null,
                    'label'    => $label,
                    'rating'   => $it['rating'] ?? null,
                ];
            }
            $effs = [];
            foreach ( ( $payload['effects'] ?? [] ) as $it ) {
                $label = $it['label'] ?? ( $it['new_name'] ?? '' );
                if ( $label === '' && !empty( $it['slug'] ) ) {
                    $g = $globalEff->resolve( $it['slug'] );
                    $label = $g ? $g->e_name : str_replace( '_', ' ', $it['slug'] );
                }
                $effs[] = [
                    'slug'      => $it['slug'] ?? null,
                    'new_name'  => $it['new_name'] ?? null,
                    'label'     => $label,
                    'valence'   => $it['valence'] ?? null,
                    'frequency' => $it['frequency'] ?? null,
                ];
            }
            $prefill = [
                'perspective'    => $perspective,
                'current'        => $editRow->xr_current !== null ? (int)$editRow->xr_current : null,
                'duration_value' => $editRow->xr_duration_days !== null ? $dur['value'] : '',
                'duration_unit'  => $dur['unit'],
                'dose_mg'        => $editRow->xr_dose_mg !== null ? (float)$editRow->xr_dose_mg : '',
                'route'          => $editRow->xr_route !== null ? (string)$editRow->xr_route : '',
                'schedule'       => $editRow->xr_schedule !== null ? (string)$editRow->xr_schedule : '',
                'patient_count'  => $editRow->xr_patient_count !== null ? (int)$editRow->xr_patient_count : '',
                'efficacy'       => $editRow->xr_efficacy !== null ? (int)$editRow->xr_efficacy : null,
                'burden'         => $editRow->xr_burden !== null ? (int)$editRow->xr_burden : null,
                'stop_reasons'   => $editRow->xr_stop_reason !== null ? (string)$editRow->xr_stop_reason : null,
                'patient_count_max' => $editRow->xr_patient_count_max !== null ? (int)$editRow->xr_patient_count_max : '',
                'indications'    => $inds,
                'effects'        => $effs,
                'anecdote'       => $payload['anecdote'] ?? '',
            ];
            $prefillAttr = ' data-prefill="' .
                htmlspecialchars( json_encode( $prefill ), ENT_QUOTES ) . '"';
        }

        $h = '<form class="pcp-xp-form" data-perspective="' . $perspective . '"' .
             ' data-form-key="' . htmlspecialchars( $formKey ) . '"' . $prefillAttr .
             ' onsubmit="return false;">';

        // Perspective toggle -- new-mode + provider only. Fieldset + visually-
        // hidden legend per WCAG 1.3.1 / 3.3.2 (a11y-claude baseline 2026-05-22).
        if ( !$editMode && $canProvider ) {
            $h .= '<fieldset class="pcp-xp-field pcp-xp-persp-row">';
            $h .= '<legend class="visuallyhidden">Perspective for this experience</legend>';
            $h .= '<label><input type="radio" name="pcp-xp-persp-' . $pid . '-' . $formKey . '" value="1" checked> Personal experience</label> ';
            $h .= '<label><input type="radio" name="pcp-xp-persp-' . $pid . '-' . $formKey . '" value="2"> Clinical experience</label>';
            $h .= '</fieldset>';
        }

        // Currently taking / prescribing. Fieldset wraps the radio group; the
        // <legend> is the existing dual-mode question text repurposed as a
        // screen-reader-only group label so visible UI is unchanged.
        $h .= '<fieldset class="pcp-xp-field">';
        $h .= '<legend class="visuallyhidden">Are you currently taking or prescribing this medicine?</legend>';
        $h .= '<div class="pcp-xp-q pcp-xp-q-personal" aria-hidden="true">Are you currently taking it?</div>';
        $h .= '<div class="pcp-xp-q pcp-xp-q-clinical" aria-hidden="true">Do you still prescribe / manage this medicine?</div>';
        $cur = [
            1 => [ 'Still taking it', 'Still do' ],
            2 => [ 'Stopped', 'No longer' ],
            3 => [ 'Tried briefly', 'Rarely' ],
        ];
        $h .= '<div class="pcp-xp-radiorow">';
        foreach ( $cur as $v => $labels ) {
            $h .= '<label><input type="radio" name="pcp-xp-current-' . $pid . '-' . $formKey . '" value="' . $v . '"> ' .
                  '<span class="pcp-xp-lbl-personal">' . htmlspecialchars( $labels[0] ) . '</span>' .
                  '<span class="pcp-xp-lbl-clinical">' . htmlspecialchars( $labels[1] ) . '</span></label> ';
        }
        $h .= '</div></fieldset>';

        // Duration
        $h .= '<div class="pcp-xp-field">';
        $h .= '<div class="pcp-xp-q pcp-xp-q-personal">How long have you taken it, in total?</div>';
        $h .= '<div class="pcp-xp-q pcp-xp-q-clinical">How long have you been prescribing it?</div>';
        $h .= '<input type="number" class="pcp-xp-duration-value" min="1" step="1" placeholder="e.g. 18"> ';
        $h .= '<select class="pcp-xp-duration-unit">';
        foreach ( [ 'days' => 'days', 'weeks' => 'weeks', 'months' => 'months', 'years' => 'years' ] as $v => $l ) {
            $sel = ( $v === 'months' ) ? ' selected' : '';
            $h .= '<option value="' . $v . '"' . $sel . '>' . $l . '</option>';
        }
        $h .= '</select></div>';

        // Daily dose (mg) -- personal only
        $h .= '<div class="pcp-xp-field pcp-xp-personal-only">';
        $h .= '<div class="pcp-xp-q">What daily dose did you take? (mg per day)</div>';
        $h .= '<input type="number" class="pcp-xp-dose-mg" min="0" step="any" placeholder="e.g. 20"> '
            . '<span class="pcp-xp-dose-unit">mg/day</span>';
        $h .= '</div>';

        // Patient count -- clinical only
        $h .= '<div class="pcp-xp-field pcp-xp-clinical-only">';
        $h .= '<div class="pcp-xp-q">Roughly how many patients have you managed on it? (single number, or a min–max range)</div>';
        $h .= '<input type="number" class="pcp-xp-patient-count" min="0" step="1" placeholder="e.g. 80"> ';
        $h .= '<span class="pcp-xp-pc-to">to (optional)</span> ';
        $h .= '<input type="number" class="pcp-xp-patient-count-max" min="0" step="1" placeholder="e.g. 120">';
        $h .= '</div>';

        // Route + schedule (personal only — clinical reports rely on patient narrative)
        $h .= '<div class="pcp-xp-field pcp-xp-q-personal-wrap">';
        $h .= '<div class="pcp-xp-q pcp-xp-q-personal">Route + schedule (optional)</div>';
        $h .= '<select class="pcp-xp-route">';
        $routes = [ '' => '— route —', 'PO' => 'PO (oral)', 'IV' => 'IV', 'IM' => 'IM', 'SC' => 'SC', 'SL' => 'SL', 'buccal' => 'Buccal', 'inhaled' => 'Inhaled', 'intranasal' => 'Intranasal', 'topical' => 'Topical', 'transdermal' => 'Transdermal', 'PR' => 'PR', 'ophthalmic' => 'Ophthalmic', 'otic' => 'Otic', 'vaginal' => 'Vaginal', 'insufflated' => 'Insufflated', 'other' => 'Other' ];
        foreach ( $routes as $rv => $rlab ) {
            $h .= '<option value="' . htmlspecialchars( (string)$rv ) . '">' . htmlspecialchars( $rlab ) . '</option>';
        }
        $h .= '</select> ';
        $h .= '<input type="text" class="pcp-xp-schedule" placeholder="schedule (BID, qHS, q6h, PRN, ...)" list="pcp-schedule-suggest">';
        $h .= '<datalist id="pcp-schedule-suggest"><option value="QD"><option value="BID"><option value="TID"><option value="QID"><option value="q4h"><option value="q6h"><option value="q8h"><option value="q12h"><option value="qHS"><option value="qAM"><option value="qPM"><option value="PRN"></datalist>';
        $h .= '</div>';

        // Efficacy 0-5
        $h .= '<div class="pcp-xp-field">';
        $h .= '<div class="pcp-xp-q pcp-xp-q-personal">How effective has it been, overall?</div>';
        $h .= '<div class="pcp-xp-q pcp-xp-q-clinical">How effective is it, in your experience?</div>';
        $h .= '<div class="pcp-xp-btnrow pcp-xp-efficacy-row">';
        $h .= '<span class="pcp-xp-rate-wrap">';
        $h .= '<input type="range" class="pcp-xp-efficacy-slider" aria-label="How effective has it been" min="0" max="100" step="1" value="50" oninput="this.nextElementSibling.value=this.value">';
        $h .= '<output>50</output>';
        $h .= '</span>';
        $h .= '</div></div>';

        // Side-effect burden 0-5
        $h .= '<div class="pcp-xp-field">';
        $h .= '<div class="pcp-xp-q pcp-xp-q-personal">How heavy was the side-effect burden?</div>';
        $h .= '<div class="pcp-xp-q pcp-xp-q-clinical">How heavy is the side-effect burden in your patients?</div>';
        $h .= '<div class="pcp-xp-btnrow pcp-xp-burden-row">';
        $h .= '<span class="pcp-xp-rate-wrap">';
        $h .= '<input type="range" class="pcp-xp-burden-slider" aria-label="How heavy the side-effect burden was" min="0" max="100" step="1" value="50" oninput="this.nextElementSibling.value=this.value">';
        $h .= '<output>50</output>';
        $h .= '</span>';
        $h .= '</div></div>';

        // Stop reasons — multi-select chips with optional severity per chip.
        $h .= '<div class="pcp-xp-field pcp-xp-stopreason" hidden>';
        $h .= '<div class="pcp-xp-q">Why did you stop? (pick any that apply; optional severity 0–100 per reason)</div>';
        $reasons = [
            'side_effects'      => 'Side effects',
            'ineffective'       => "Didn't work",
            'cost'              => 'Cost',
            'no_longer_needed'  => 'No longer needed',
            'clinician_advised' => 'Clinician advised',
            'other'             => 'Other',
        ];
        $h .= '<div class="pcp-xp-sr-grid">';
        foreach ( $reasons as $code => $label ) {
            $h .= '<div class="pcp-xp-sr-row" data-code="' . htmlspecialchars( $code ) . '">';
            $h .= '<label class="pcp-xp-sr-toggle-label">';
            $h .= '<input type="checkbox" class="pcp-xp-sr-toggle" value="' . htmlspecialchars( $code ) . '"> ';
            $h .= htmlspecialchars( $label );
            $h .= '</label>';
            $h .= '<span class="pcp-xp-sr-sev" hidden>';
            $h .= ' severity ';
            $h .= '<input type="range" class="pcp-xp-sr-slider" aria-label="Severity of this reason for stopping" min="0" max="100" step="1" value="50" oninput="this.nextElementSibling.value=this.value">';
            $h .= '<output>50</output>';
            $h .= '</span>';
            $h .= '</div>';
        }
        $h .= '</div></div>';

        // Indications picker
        $h .= '<div class="pcp-xp-field pcp-xp-indication-field">';
        $h .= '<div class="pcp-xp-q pcp-xp-q-personal">What did you use it for?</div>';
        $h .= '<div class="pcp-xp-q pcp-xp-q-clinical">What do you prescribe it for?</div>';
        $h .= '<div class="pcp-xp-chips pcp-xp-indication-chips"></div>';
        $h .= '<button type="button" class="pcp-xp-add pcp-xp-add-indication">+ Add indication</button>';
        $h .= '</div>';

        // Effects picker
        $h .= '<div class="pcp-xp-field pcp-xp-effect-field">';
        $h .= '<div class="pcp-xp-q pcp-xp-q-personal">Which effects did you notice?</div>';
        $h .= '<div class="pcp-xp-q pcp-xp-q-clinical">Which effects do you see?</div>';
        $h .= '<div class="pcp-xp-chips pcp-xp-effect-chips"></div>';
        $h .= '<button type="button" class="pcp-xp-add pcp-xp-add-effect">+ Add effect</button>';
        $h .= '</div>';

        // Anecdote
        $h .= '<div class="pcp-xp-field">';
        $h .= '<div class="pcp-xp-q">Anything else worth sharing?</div>';
        $h .= '<textarea class="pcp-xp-anecdote" aria-label="Anything else worth sharing" rows="3" maxlength="8000" placeholder="Optional. Posted as an anecdote on this page once your submission is approved."></textarea>';
        $h .= '</div>';

        // Actions
        $h .= '<div class="pcp-xp-form-actions">';
        $submitLabel = $editMode ? 'Resubmit for review' : 'Submit for review';
        $h .= '<button type="button" class="pcp-xp-submit mw-ui-button mw-ui-progressive">' . $submitLabel . '</button>';
        $h .= '<span class="pcp-xp-form-status"></span>';
        $h .= '</div>';

        $note = $editMode
            ? 'Editing sends your report back to the moderation queue; it leaves the public aggregate until re-approved.'
            : 'Submissions are reviewed by a moderator before they appear publicly.';
        $h .= '<p class="pcp-xp-form-note">' . $note . '</p>';

        $h .= '</form>';
        return $h;
    }

    private static function renderMyReport( $persp, $row ) {
        $isClinical = ( $persp === ExperienceStore::PERSPECTIVE_CLINICAL );
        $perspLabel = $isClinical ? 'Clinical' : 'Personal';

        $statusInt = (int)$row->xr_status;
        if ( $statusInt === ExperienceStore::STATUS_APPROVED ) {
            $statusHtml = '<span class="pcp-xp-status pcp-xp-status-approved">&#10003; approved</span>';
        } elseif ( $statusInt === ExperienceStore::STATUS_REJECTED ) {
            $statusHtml = '<span class="pcp-xp-status pcp-xp-status-rejected">&#10007; not accepted</span>';
        } else {
            $statusHtml = '<span class="pcp-xp-status pcp-xp-status-pending">&#9203; awaiting review</span>';
        }

        $curMap = $isClinical
            ? [ 1 => 'Still prescribing', 2 => 'No longer prescribing', 3 => 'Prescribe rarely' ]
            : [ 1 => 'Still taking it',   2 => 'Stopped',               3 => 'Tried briefly' ];
        $cur = $curMap[ (int)$row->xr_current ] ?? '&mdash;';

        $bits = [ htmlspecialchars( $cur ) ];
        if ( $row->xr_duration_days !== null ) {
            $bits[] = 'used ' . ExperienceStore::formatDuration( (int)$row->xr_duration_days );
        }
        if ( !$isClinical && $row->xr_dose_mg !== null ) {
            $bits[] = ExperienceStore::formatDose( $row->xr_dose_mg ) . ' mg/day';
        }
        if ( !$isClinical && isset( $row->xr_route ) && $row->xr_route ) {
            $bits[] = htmlspecialchars( (string)$row->xr_route );
        }
        if ( !$isClinical && isset( $row->xr_schedule ) && $row->xr_schedule ) {
            $bits[] = htmlspecialchars( (string)$row->xr_schedule );
        }
        if ( $isClinical && $row->xr_patient_count !== null ) {
            $pcMin = (int)$row->xr_patient_count;
            $pcMax = isset( $row->xr_patient_count_max ) && $row->xr_patient_count_max !== null
                ? (int)$row->xr_patient_count_max : null;
            $bits[] = ( $pcMax !== null && $pcMax > $pcMin )
                ? $pcMin . '–' . $pcMax . ' patients'
                : $pcMin . ' patients';
        }
        if ( $row->xr_efficacy !== null ) {
            $bits[] = 'efficacy ' . (int)$row->xr_efficacy . '/100';
        }
        if ( $row->xr_burden !== null ) {
            $bits[] = 'burden ' . (int)$row->xr_burden . '/100';
        }

        $h  = '<div class="pcp-xp-myreport">';
        $h .= '<div class="pcp-xp-myreport-head">';
        $h .= '<strong>Your ' . htmlspecialchars( strtolower( $perspLabel ) ) . ' experience</strong> ';
        $h .= $statusHtml;
        $h .= ' <button type="button" class="pcp-xp-edit-btn">Edit</button>';
        $h .= '</div>';
        $h .= '<div class="pcp-xp-myreport-body">' . implode( ' &middot; ', $bits ) . '</div>';
        $h .= '</div>';
        return $h;
    }
}
