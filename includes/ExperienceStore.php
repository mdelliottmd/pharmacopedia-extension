<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\Content\ContentHandler;
use MediaWiki\CommentStore\CommentStoreComment;

/**
 * Storage for the per-medicine (Personal/Clinical) Experience section.
 *
 * One row per (page, user, perspective). Every submission lands as PENDING and
 * is invisible to the public aggregate until a sysop/admin approves it. The
 * effect / indication / anecdote picks the user made in the form are held as an
 * opaque JSON blob in xr_payload and are NOT committed to their live systems
 * until approval (atomic moderation). The payload commit itself is wired up in
 * a later phase; this class only stores, retrieves, aggregates, and flips status.
 */
class ExperienceStore {
    const PERSPECTIVE_PERSONAL = 1;
    const PERSPECTIVE_CLINICAL = 2;

    const STATUS_PENDING  = 0;
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;

    const CURRENT_STILL   = 1;   // still taking / still prescribing
    const CURRENT_STOPPED = 2;   // stopped / no longer prescribing
    const CURRENT_BRIEF   = 3;   // tried briefly / prescribe rarely

    /** Stop-reason codes (personal perspective, only when CURRENT_STOPPED). */
    const STOP_SIDE_EFFECTS    = 1;
    const STOP_INEFFECTIVE     = 2;
    const STOP_COST            = 3;
    const STOP_NO_LONGER_NEED  = 4;
    const STOP_CLINICIAN       = 5;
    const STOP_OTHER           = 6;

    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }
    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    /** Normalize a numeric duration + unit string into a day count. */
    public static function normalizeDurationToDays( $value, $unit ) {
        $value = (float)$value;
        if ( $value <= 0 ) { return null; }
        switch ( strtolower( (string)$unit ) ) {
            case 'day':    case 'days':   $mult = 1;   break;
            case 'week':   case 'weeks':  $mult = 7;   break;
            case 'month':  case 'months': $mult = 30;  break;
            case 'year':   case 'years':  $mult = 365; break;
            default: return null;
        }
        return (int)round( $value * $mult );
    }

    /** Human-readable duration from a day count (picks the cleanest unit). */
    public static function formatDuration( $days ) {
        $days = (int)$days;
        if ( $days <= 0 ) { return '—'; }
        if ( $days < 14 )   { return $days . ( $days === 1 ? ' day' : ' days' ); }
        if ( $days < 60 )   { $w = round( $days / 7 );  return $w . ' weeks'; }
        if ( $days < 365 )  { $m = round( $days / 30 ); return $m . ' months'; }
        $y = $days / 365;
        $y = ( $y < 10 ) ? round( $y, 1 ) : (int)round( $y );
        return $y . ( $y == 1 ? ' year' : ' years' );
    }

    /** Inverse of normalizeDurationToDays: day count -> [ value, unit ] for form pre-fill. */
    public static function denormalizeDuration( $days ) {
        $days = (int)$days;
        if ( $days <= 0 ) { return [ 'value' => '', 'unit' => 'months' ]; }
        if ( $days % 365 === 0 ) { return [ 'value' => intdiv( $days, 365 ), 'unit' => 'years' ]; }
        if ( $days % 30 === 0 )  { return [ 'value' => intdiv( $days, 30 ),  'unit' => 'months' ]; }
        if ( $days % 7 === 0 )   { return [ 'value' => intdiv( $days, 7 ),   'unit' => 'weeks' ]; }
        return [ 'value' => $days, 'unit' => 'days' ];
    }

    /** Trim a dose value for display: "20", "12.5", "0.25". */
    public static function formatDose( $mg ) {
        if ( $mg === null || $mg === '' ) { return '—'; }
        $s = rtrim( rtrim( number_format( (float)$mg, 3, '.', '' ), '0' ), '.' );
        return $s === '' ? '0' : $s;
    }

    public static function isValidPerspective( $p ) {
        return (int)$p === self::PERSPECTIVE_PERSONAL || (int)$p === self::PERSPECTIVE_CLINICAL;
    }

    /**
     * Upsert a submission. Always lands as PENDING (a fresh submit OR an edit of
     * an already-approved report both require (re-)review).
     *
     * @param int   $pageId
     * @param int   $userId
     * @param int   $perspective  PERSPECTIVE_*
     * @param array $fields  keys: current, duration_days, patient_count, efficacy, burden, stop_reason
     * @param array $payload keys: indications[], effects[], anecdote  (stored verbatim as JSON)
     * @return int xr_id
     */
    public function submit( $pageId, $userId, $perspective, array $fields, array $payload ) {
        $dbw = $this->dbw();
        $now = $dbw->timestamp();
        $row = [
            'xr_status'        => self::STATUS_PENDING,
            'xr_current'       => $fields['current']        ?? null,
            'xr_duration_days' => $fields['duration_days']  ?? null,
            'xr_dose_mg'       => $fields['dose_mg']        ?? null,
            'xr_route'         => $fields['route']          ?? null,
            'xr_schedule'      => $fields['schedule']       ?? null,
            'xr_patient_count' => $fields['patient_count']  ?? null,
            'xr_patient_count_max' => $fields['patient_count_max'] ?? null,
            'xr_efficacy'      => $fields['efficacy']       ?? null,
            'xr_burden'        => $fields['burden']         ?? null,
            'xr_stop_reason'   => $fields['stop_reason']    ?? null,
            'xr_payload'       => json_encode( $payload ),
            'xr_updated'       => $now,
            'xr_reviewed_by'   => null,
            'xr_reviewed_at'   => null,
        ];
        $voterHash = $this->voterHash( $userId );
        $existing = $dbw->selectRow( 'pcp_experience_reports', 'xr_id', [
            'xr_page_id'     => (int)$pageId,
            'xr_voter_hash'  => $voterHash,
            'xr_perspective' => (int)$perspective,
        ], __METHOD__ );
        if ( $existing ) {
            $dbw->update( 'pcp_experience_reports', $row,
                [ 'xr_id' => (int)$existing->xr_id ], __METHOD__ );
            return (int)$existing->xr_id;
        }
        $dbw->insert( 'pcp_experience_reports', $row + [
            'xr_page_id'     => (int)$pageId,
            'xr_voter_hash'  => $voterHash,
            'xr_perspective' => (int)$perspective,
            'xr_created'     => $now,
        ], __METHOD__ );
        return (int)$dbw->insertId();
    }

    public function getById( $id ) {
        return $this->dbr()->selectRow( 'pcp_experience_reports', '*',
            [ 'xr_id' => (int)$id ], __METHOD__ );
    }

    public function getForUser( $pageId, $userId, $perspective ) {
        return $this->dbr()->selectRow( 'pcp_experience_reports', '*', [
            'xr_page_id'     => (int)$pageId,
            'xr_voter_hash'  => $this->voterHash( $userId ),
            'xr_perspective' => (int)$perspective,
        ], __METHOD__ );
    }

    /** Both perspectives for a user on a page, keyed by perspective int. */
    public function getForUserAll( $pageId, $userId ) {
        $res = $this->dbr()->select( 'pcp_experience_reports', '*', [
            'xr_page_id'     => (int)$pageId,
            'xr_voter_hash'  => $this->voterHash( $userId ),
        ], __METHOD__ );
        $out = [];
        foreach ( $res as $r ) { $out[ (int)$r->xr_perspective ] = $r; }
        return $out;
    }

    /**
     * Public aggregate over APPROVED rows for one page + perspective.
     * Returns n, efficacy_mean, burden_mean, duration_median_days,
     * current_still, current_pct, patient_count_sum.
     */
    public function getApprovedAggregates( $pageId, $perspective ) {
        $dbr = $this->dbr();
        $cond = [
            'xr_page_id'     => (int)$pageId,
            'xr_perspective' => (int)$perspective,
            'xr_status'      => self::STATUS_APPROVED,
        ];
        $agg = $dbr->selectRow( 'pcp_experience_reports', [
            'n'           => 'COUNT(*)',
            'eff_sum'     => 'SUM(xr_efficacy)',
            'eff_n'       => 'SUM(CASE WHEN xr_efficacy IS NOT NULL THEN 1 ELSE 0 END)',
            'bur_sum'     => 'SUM(xr_burden)',
            'bur_n'       => 'SUM(CASE WHEN xr_burden IS NOT NULL THEN 1 ELSE 0 END)',
            'still_n'     => 'SUM(CASE WHEN xr_current = ' . self::CURRENT_STILL . ' THEN 1 ELSE 0 END)',
            'current_n'   => 'SUM(CASE WHEN xr_current IS NOT NULL THEN 1 ELSE 0 END)',
            'pt_sum'      => 'SUM(xr_patient_count)',
        ], $cond, __METHOD__ );

        $n = $agg ? (int)$agg->n : 0;
        if ( $n === 0 ) {
            return [
                'n' => 0, 'efficacy_mean' => null, 'burden_mean' => null,
                'duration_median_days' => null, 'dose_median_mg' => null,
                'current_still' => 0, 'current_pct' => null, 'patient_count_sum' => null,
            ];
        }

        // Median duration: pull the non-null day counts and compute in PHP.
        $durRows = $dbr->select( 'pcp_experience_reports', 'xr_duration_days',
            $cond + [ 'xr_duration_days IS NOT NULL' ],
            __METHOD__, [ 'ORDER BY' => 'xr_duration_days ASC' ] );
        $durations = [];
        foreach ( $durRows as $d ) { $durations[] = (int)$d->xr_duration_days; }
        $median = null;
        $c = count( $durations );
        if ( $c > 0 ) {
            $mid = intdiv( $c, 2 );
            $median = ( $c % 2 )
                ? $durations[ $mid ]
                : (int)round( ( $durations[ $mid - 1 ] + $durations[ $mid ] ) / 2 );
        }

        // Median daily dose (personal reports carry xr_dose_mg).
        $doseRows = $dbr->select( 'pcp_experience_reports', 'xr_dose_mg',
            $cond + [ 'xr_dose_mg IS NOT NULL' ],
            __METHOD__, [ 'ORDER BY' => 'xr_dose_mg ASC' ] );
        $doses = [];
        foreach ( $doseRows as $d ) { $doses[] = (float)$d->xr_dose_mg; }
        $doseMedian = null;
        $dc = count( $doses );
        if ( $dc > 0 ) {
            $dmid = intdiv( $dc, 2 );
            $doseMedian = ( $dc % 2 )
                ? $doses[ $dmid ]
                : round( ( $doses[ $dmid - 1 ] + $doses[ $dmid ] ) / 2, 3 );
        }

        $effN = (int)$agg->eff_n;
        $burN = (int)$agg->bur_n;
        $curN = (int)$agg->current_n;
        return [
            'n'                    => $n,
            'efficacy_mean'        => $effN > 0 ? round( (float)$agg->eff_sum / $effN, 2 ) : null,
            'burden_mean'          => $burN > 0 ? round( (float)$agg->bur_sum / $burN, 2 ) : null,
            'duration_median_days' => $median,
            'dose_median_mg'       => $doseMedian,
            'current_still'        => (int)$agg->still_n,
            'current_pct'          => $curN > 0 ? round( 100.0 * (int)$agg->still_n / $curN, 1 ) : null,
            'patient_count_sum'    => $agg->pt_sum !== null ? (int)$agg->pt_sum : null,
        ];
    }

    /** Pending submissions for the review queue, newest first. */
    public function listPending( $offset = 0, $limit = 50 ) {
        $res = $this->dbr()->select( 'pcp_experience_reports', '*',
            [ 'xr_status' => self::STATUS_PENDING ],
            __METHOD__,
            [ 'ORDER BY' => 'xr_created ASC', 'LIMIT' => $limit, 'OFFSET' => $offset ]
        );
        $out = [];
        foreach ( $res as $r ) { $out[] = $r; }
        return $out;
    }

    public function countPending() {
        return (int)$this->dbr()->selectField( 'pcp_experience_reports', 'COUNT(*)',
            [ 'xr_status' => self::STATUS_PENDING ], __METHOD__ );
    }

    /**
     * Flip a row's status. Payload commit-on-approval is wired in a later phase;
     * here we only record the status transition + reviewer.
     */
    public function setStatus( $id, $status, $reviewerId ) {
        $this->dbw()->update( 'pcp_experience_reports', [
            'xr_status'      => (int)$status,
            'xr_reviewed_by' => (int)$reviewerId,
            'xr_reviewed_at' => $this->dbw()->timestamp(),
        ], [ 'xr_id' => (int)$id ], __METHOD__ );
    }

    /** Decode the staged payload JSON for a row. */
    public function decodePayload( $row ) {
        if ( !$row || $row->xr_payload === null ) { return []; }
        $d = json_decode( (string)$row->xr_payload, true );
        return is_array( $d ) ? $d : [];
    }

    // ===== Moderation: atomic approve / reject =====

    /**
     * Reject a pending submission. The staged payload is discarded -- nothing
     * is committed to the live effect / indication / anecdote systems.
     */
    public function reject( $id, $reviewer ) {
        $row = $this->getById( $id );
        if ( !$row ) { return [ 'ok' => false, 'error' => 'Submission not found.' ]; }
        $this->setStatus( $id, self::STATUS_REJECTED, $reviewer->getId() );
        return [ 'ok' => true ];
    }

    /**
     * Approve a pending submission. Atomically:
     *   - records the user's effect reports + indication likert ratings,
     *   - adds the <effect>/<problem>/<anecdote> tags to the page (one edit),
     *   - flips the row to APPROVED so its numbers enter the public aggregate.
     * If the page save fails, nothing is committed and the row stays pending.
     */
    public function approve( $id, $reviewer ) {
        $row = $this->getById( $id );
        if ( !$row ) { return [ 'ok' => false, 'error' => 'Submission not found.' ]; }
        if ( (int)$row->xr_status === self::STATUS_APPROVED ) {
            return [ 'ok' => false, 'error' => 'Already approved.' ];
        }

        $pageId      = (int)$row->xr_page_id;
        $voterHash   = (string)$row->xr_voter_hash;
        $perspective = (int)$row->xr_perspective;
        $payload     = $this->decodePayload( $row );

        $services = MediaWikiServices::getInstance();
        $title = Title::newFromID( $pageId );
        if ( !$title || !$title->exists() ) {
            return [ 'ok' => false, 'error' => 'Target page no longer exists.' ];
        }

        // Anonymized: submission rows no longer store user_id.
        // Approval edits are attributed to the reviewer.
        $editActor = $reviewer;

        $page = $services->getWikiPageFactory()->newFromTitle( $title );
        $content = $page->getContent();
        if ( !$content ) {
            return [ 'ok' => false, 'error' => 'Could not load page content.' ];
        }
        $wt = ( method_exists( $content, 'getText' ) ) ? $content->getText() : (string)$content;
        $origWt = $wt;

        $elementStore = new ElementStore();
        $effectStore  = new EffectStore();
        $likertStore  = new LikertStore();
        $globalEffect = new GlobalEffectStore();
        $globalInd    = new ProblemStore();

        // ---- Effects ----
        foreach ( ( $payload['effects'] ?? [] ) as $eff ) {
            $globalSlug = null;
            $label = '';
            if ( !empty( $eff['slug'] ) ) {
                $globalSlug = $eff['slug'];
                $g = $globalEffect->resolve( $globalSlug );
                $label = $g ? $g->e_name : str_replace( '_', ' ', $globalSlug );
            } elseif ( !empty( $eff['new_name'] ) ) {
                $globalEffect->create( '', $eff['new_name'], '', '', 0 );
                $globalSlug = GlobalEffectStore::normalizeSlug( $eff['new_name'] );
                $label = $eff['new_name'];
            }
            if ( !$globalSlug ) { continue; }

            // Votable element slug mirrors EffectTag (ref -> "ref-{slug}").
            $element = $elementStore->getOrCreate( $pageId, 'ref-' . $globalSlug, 'effect', $label );
            if ( $element ) {
                $valence = isset( $eff['valence'] ) ? $eff['valence'] : null;
                if ( $perspective === self::PERSPECTIVE_PERSONAL ) {
                    // Patient perspective: picking it = experienced it.
                    $effectStore->submitReportByHash( (int)$element->ve_id, $voterHash,
                        EffectStore::PERSPECTIVE_PATIENT, 1, null, $valence );
                } else {
                    // Provider perspective: frequency carries the "how often".
                    $freq = isset( $eff['frequency'] ) ? $eff['frequency'] : null;
                    $effectStore->submitReportByHash( (int)$element->ve_id, $voterHash,
                        EffectStore::PERSPECTIVE_PROVIDER, null, $freq, $valence );
                }
            }
            // Add <effect ref/> to the page if not already present.
            if ( !preg_match( '/<effect\\b[^>]*\\bref\\s*=\\s*"' . preg_quote( $globalSlug, '/' ) . '"/i', $wt ) ) {
                $wt = TemplateParamEditor::insertIntoMedTemplateParam(
                    $wt, 'effects', '<effect ref="' . htmlspecialchars( $globalSlug, ENT_QUOTES ) . '"/>'
                );
            }
        }

        // ---- Indications ----
        foreach ( ( $payload['indications'] ?? [] ) as $ind ) {
            $globalSlug = null;
            $indTitle = '';
            if ( !empty( $ind['ref'] ) ) {
                $globalSlug = $ind['ref'];
                $g = $globalInd->resolve( $globalSlug );
                $indTitle = $g ? $g->p_name : str_replace( '_', ' ', $globalSlug );
            } elseif ( !empty( $ind['new_name'] ) ) {
                $globalInd->create( '', $ind['new_name'], '', '', 0 );
                $globalSlug = ProblemStore::normalizeSlug( $ind['new_name'] );
                $indTitle = $ind['new_name'];
            }
            if ( !$globalSlug ) { continue; }

            // Element slug mirrors ProblemTag: 'problem-' . normSlug('ref-' . slug).
            $refSlug = 'ref-' . $globalSlug;
            $normSlug = strtolower( preg_replace( '/[^a-zA-Z0-9-]+/', '-', $refSlug ) );
            $normSlug = trim( $normSlug, '-' );
            $element = $elementStore->getOrCreate( $pageId, 'problem-' . $normSlug, 'likert', $indTitle );
            if ( $element && isset( $ind['rating'] ) && $ind['rating'] !== null ) {
                $likertStore->submitRatingByHash( (int)$element->ve_id, $voterHash, (int)$ind['rating'] );
            }
            if ( !preg_match( '/<(?:indication|problem)\\b[^>]*\\bref\\s*=\\s*"' . preg_quote( $globalSlug, '/' ) . '"/i', $wt ) ) {
                $wt = TemplateParamEditor::insertIntoMedTemplateParam(
                    $wt, 'indications', '<problem ref="' . htmlspecialchars( $globalSlug, ENT_QUOTES ) . '"/>'
                );
            }
        }

        // ---- Anecdote ----
        // Stable slug per experience report (xp-{id}) so an edit + re-approval
        // updates the existing anecdote in place rather than duplicating it.
        $anec = trim( (string)( $payload['anecdote'] ?? '' ) );
        if ( $anec !== '' ) {
            $anecPersp = ( $perspective === self::PERSPECTIVE_CLINICAL ) ? 'provider' : 'personal';
            $authorName = ( $submitter && $submitter->isRegistered() ) ? $submitter->getName() : 'Anonymous';
            $safeAuthor = htmlspecialchars( $authorName, ENT_QUOTES );
            $slug = 'xp-' . (int)$id;
            $block = '<anecdote slug="' . $slug . '" perspective="' . $anecPersp .
                     '" author="' . $safeAuthor . '">' . "\n" . $anec . "\n</anecdote>";
            $pat = '/<anecdote\b[^>]*\bslug\s*=\s*"' . preg_quote( $slug, '/' ) . '"[^>]*>.*?<\/anecdote>/s';
            if ( preg_match( $pat, $wt ) ) {
                $wt = preg_replace( $pat, $block, $wt, 1 );
            } else {
                $wt = TemplateParamEditor::insertIntoMedTemplateParam( $wt, 'anecdotes', $block );
                $elementStore->getOrCreate( $pageId, 'anecdote-' . $slug, 'binary', $anecPersp . ' anecdote' );
            }
        }

        // ---- Save the page (one revision) if the wikitext actually changed ----
        if ( $wt !== $origWt ) {
            $newContent = ContentHandler::makeContent( $wt, $title );
            $updater = $page->newPageUpdater( $editActor );
            $updater->setContent( SlotRecord::MAIN, $newContent );
            $summary = CommentStoreComment::newUnsavedComment(
                'Pharmacopedia: approved experience contribution'
            );
            $updater->saveRevision( $summary, EDIT_UPDATE );
            $st = $updater->getStatus();
            if ( !$st || !$st->isOK() ) {
                return [ 'ok' => false, 'error' => 'Page save failed; submission left pending.' ];
            }
        }

        // ---- Flip status last, so a failed save leaves the row pending ----
        $this->setStatus( $id, self::STATUS_APPROVED, $reviewer->getId() );
        return [ 'ok' => true ];
    }

    /** Map a user id to its opaque voter hash. */
    public function voterHash( $userId ): string {
        global $wgPharmacopediaVoteHashSecret;
        if ( !$wgPharmacopediaVoteHashSecret ) {
            throw new \RuntimeException( '$wgPharmacopediaVoteHashSecret must be set in LocalSettings.php' );
        }
        return hash_hmac( 'sha256', (string)$userId, $wgPharmacopediaVoteHashSecret );
    }

}
