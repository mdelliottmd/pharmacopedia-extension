<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\MediaWikiServices;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;

class SpecialReviewExperience extends SpecialPage {
    public function __construct() {
        parent::__construct( 'ReviewExperience', 'pharmacopedia-verify-review' );
    }

    public function doesWrites() { return true; }

    public function execute( $par ) {
        $this->setHeaders();
        $this->checkPermissions();
        $out = $this->getOutput();
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );
        $out->setPageTitle( 'Review experience submissions' );

        $store = new ExperienceStore();
        $req = $this->getRequest();

        // POST: approve / reject
        if ( $req->wasPosted() ) {
            if ( !$this->getUser()->matchEditToken( $req->getVal( 'wpEditToken' ) ) ) {
                $out->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ], 'Invalid token.' ) );
            } else {
                $xrId = (int)$req->getVal( 'xr_id' );
                $decision = $req->getVal( 'decision' );
                if ( $xrId > 0 && $decision === 'approve' ) {
                    $res = $store->approve( $xrId, $this->getUser() );
                    $out->addHTML( Html::element( 'div',
                        [ 'class' => $res['ok'] ? 'successbox' : 'errorbox' ],
                        $res['ok'] ? 'Approved and committed.' : ( 'Approve failed: ' . ( $res['error'] ?? '?' ) ) ) );
                } elseif ( $xrId > 0 && $decision === 'reject' ) {
                    $res = $store->reject( $xrId, $this->getUser() );
                    $out->addHTML( Html::element( 'div',
                        [ 'class' => $res['ok'] ? 'successbox' : 'errorbox' ],
                        $res['ok'] ? 'Rejected; payload discarded.' : ( 'Reject failed: ' . ( $res['error'] ?? '?' ) ) ) );
                }
            }
        }

        $pending = $store->listPending( 0, 100 );
        $out->addHTML( '<p>' . count( $pending ) . ' submission' . ( count( $pending ) === 1 ? '' : 's' ) .
            ' awaiting review. Approving atomically commits the staged effect ratings, ' .
            'indication ratings, and anecdote, and adds the tags to the page.</p>' );

        if ( !$pending ) {
            $out->addHTML( '<p><em>Queue is empty.</em></p>' );
            return;
        }

        $token = $this->getUser()->getEditToken();
        $uf = MediaWikiServices::getInstance()->getUserFactory();

        $curMapPersonal = [ 1 => 'Still taking it', 2 => 'Stopped', 3 => 'Tried briefly' ];
        $curMapClinical = [ 1 => 'Still prescribing', 2 => 'No longer prescribing', 3 => 'Prescribe rarely' ];
        $stopMap = [
            1 => 'Side effects', 2 => "Didn't work", 3 => 'Cost',
            4 => 'No longer needed', 5 => 'Clinician advised', 6 => 'Other',
        ];

        foreach ( $pending as $row ) {
            $isClinical = ( (int)$row->xr_perspective === ExperienceStore::PERSPECTIVE_CLINICAL );
            $title = Title::newFromID( (int)$row->xr_page_id );
            $pageLink = $title
                ? '<a href="' . htmlspecialchars( $title->getLocalURL() ) . '">' .
                  htmlspecialchars( $title->getPrefixedText() ) . '</a>'
                : '<em>(page #' . (int)$row->xr_page_id . ' missing)</em>';
            // Anonymized: submitter identity is not retained.
            $userName = 'Anonymous submitter (' . substr( (string)$row->xr_voter_hash, 0, 8 ) . '…)';

            $h = '<div class="pcp-xp-review">';
            $h .= '<div class="pcp-xp-review-head">';
            $h .= '<strong>' . $pageLink . '</strong> &mdash; ';
            $h .= htmlspecialchars( $userName ) . ' &middot; ';
            $h .= '<span class="pcp-xp-review-persp">' . ( $isClinical ? 'Clinical' : 'Personal' ) . '</span>';
            $h .= '</div>';

            // Numbers
            $curMap = $isClinical ? $curMapClinical : $curMapPersonal;
            $bits = [];
            $bits[] = 'Status: ' . htmlspecialchars( $curMap[ (int)$row->xr_current ] ?? '—' );
            if ( $row->xr_duration_days !== null ) {
                $bits[] = 'Duration: ' . ExperienceStore::formatDuration( (int)$row->xr_duration_days );
            }
            if ( !$isClinical && $row->xr_dose_mg !== null ) {
                $bits[] = 'Dose: ' . ExperienceStore::formatDose( $row->xr_dose_mg ) . ' mg/day';
            }
            if ( $isClinical && $row->xr_patient_count !== null ) {
                $pcMin = (int)$row->xr_patient_count;
                $pcMax = isset( $row->xr_patient_count_max ) && $row->xr_patient_count_max !== null
                    ? (int)$row->xr_patient_count_max : null;
                $bits[] = ( $pcMax !== null && $pcMax > $pcMin )
                    ? 'Patients: ' . $pcMin . '–' . $pcMax
                    : 'Patients: ' . $pcMin;
            }
            if ( $row->xr_efficacy !== null ) { $bits[] = 'Efficacy: ' . (int)$row->xr_efficacy . '/100'; }
            if ( $row->xr_burden !== null )   { $bits[] = 'Burden: ' . (int)$row->xr_burden . '/100'; }
            if ( !$isClinical && $row->xr_stop_reason !== null ) {
                $srLabelMap = [
                    'side_effects' => 'Side effects', 'ineffective' => "Didn't work", 'cost' => 'Cost',
                    'no_longer_needed' => 'No longer needed', 'clinician_advised' => 'Clinician advised', 'other' => 'Other',
                ];
                $sr = json_decode( (string)$row->xr_stop_reason, true );
                if ( is_array( $sr ) && $sr ) {
                    $parts = [];
                    foreach ( $sr as $e ) {
                        if ( !is_array( $e ) || !isset( $e['code'] ) ) continue;
                        $lbl = $srLabelMap[ $e['code'] ] ?? $e['code'];
                        if ( isset( $e['severity'] ) ) $lbl .= ' (' . (int)$e['severity'] . '/100)';
                        $parts[] = htmlspecialchars( $lbl );
                    }
                    if ( $parts ) $bits[] = 'Stop reasons: ' . implode( ', ', $parts );
                }
            }
            $h .= '<div class="pcp-xp-review-numbers">' . implode( ' &middot; ', $bits ) . '</div>';

            // Payload
            $payload = $store->decodePayload( $row );
            $inds = $payload['indications'] ?? [];
            $effs = $payload['effects'] ?? [];
            $anec = trim( (string)( $payload['anecdote'] ?? '' ) );

            if ( $inds ) {
                $parts = [];
                foreach ( $inds as $it ) {
                    $name = $it['new_name'] ?? ( $it['ref'] ?? '?' );
                    $r = isset( $it['rating'] ) && $it['rating'] !== null ? $it['rating'] : null;
                    $rTxt = $r === null ? 'unrated' : ( $r === -1 ? "don't know" : ( $r . '/100' ) );
                    $parts[] = htmlspecialchars( str_replace( '_', ' ', (string)$name ) ) .
                        ( isset( $it['new_name'] ) ? ' <em>(new)</em>' : '' ) . ' (' . $rTxt . ')';
                }
                $h .= '<div class="pcp-xp-review-payload"><strong>Indications:</strong> ' . implode( ', ', $parts ) . '</div>';
            }
            if ( $effs ) {
                $parts = [];
                foreach ( $effs as $it ) {
                    $name = $it['new_name'] ?? ( $it['label'] ?? ( $it['slug'] ?? '?' ) );
                    $v = isset( $it['valence'] ) && $it['valence'] !== null ? sprintf( '%+d', $it['valence'] ) : '—';
                    $f = isset( $it['frequency'] ) && $it['frequency'] !== null ? ( $it['frequency'] . '%' ) : null;
                    $detail = $isClinical && $f !== null ? ( 'freq ' . $f . ', val ' . $v ) : ( 'val ' . $v );
                    $parts[] = htmlspecialchars( str_replace( '_', ' ', (string)$name ) ) .
                        ( isset( $it['new_name'] ) ? ' <em>(new)</em>' : '' ) . ' (' . $detail . ')';
                }
                $h .= '<div class="pcp-xp-review-payload"><strong>Effects:</strong> ' . implode( ', ', $parts ) . '</div>';
            }
            if ( $anec !== '' ) {
                $h .= '<div class="pcp-xp-review-payload"><strong>Anecdote:</strong> ' .
                    nl2br( htmlspecialchars( $anec ) ) . '</div>';
            }

            // Actions
            $h .= '<div class="pcp-xp-review-actions">';
            foreach ( [ 'approve' => 'Approve & commit', 'reject' => 'Reject' ] as $dec => $lbl ) {
                $h .= Html::openElement( 'form', [
                    'method' => 'POST', 'style' => 'display:inline; margin-right:0.5em;',
                    'action' => $this->getPageTitle()->getLocalURL(),
                ] );
                $h .= Html::hidden( 'wpEditToken', $token );
                $h .= Html::hidden( 'xr_id', (int)$row->xr_id );
                $h .= Html::hidden( 'decision', $dec );
                $h .= Html::submitButton( $lbl, [
                    'class' => 'mw-ui-button ' . ( $dec === 'approve' ? 'mw-ui-progressive' : 'mw-ui-destructive' ),
                ] );
                $h .= Html::closeElement( 'form' );
            }
            $h .= '</div>';

            $h .= '</div>';
            $out->addHTML( $h );
        }
    }

    protected function getGroupName() {
        return 'users';
    }
}
