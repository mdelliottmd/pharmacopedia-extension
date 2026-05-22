<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\Pharmacopedia\Assessments\AdminCrypto;
use MediaWiki\Extension\Pharmacopedia\Assessments\AssessmentRegistry;

/**
 * Special:RespondToAssessment/<token> - the respondent take-flow and, once
 * every scale is complete, the respondent's own persistent dashboard.
 *
 * "Administer to others" Phase 2 surface 2. An outside respondent (no
 * account) follows a one-time invite link, consents, takes the invited
 * scale(s), and submits. Each submission is scored with the existing
 * Assessments scorer and stored three ways:
 *   - sealed to the inviting owner's public key (the owner's copy);
 *   - sealed to a key derived from the invite token, so the respondent can
 *     read their own results back (powers the dashboard below);
 *   - a decoupled, de-identified copy in the research pool.
 *
 * Once all scales are done the SAME url becomes the respondent's dashboard:
 * their scored results, revisitable until either the respondent or the
 * inviting owner deletes it.
 *
 * The rendering model for each instrument comes from AssessmentRegistry:
 * 'radio' (discrete options), 'slider' (one continuous slider per item),
 * 'bipolar' (a slider between the two phrases of an MBTI item).
 */
class SpecialRespondToAssessment extends SpecialPage {

    public function __construct() {
        // Unlisted: reached only by token link, never via Special:SpecialPages.
        parent::__construct( 'RespondToAssessment', '', false );
    }

    public function doesWrites() {
        return true;
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->setPageTitle( 'Assessment invitation' );
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles', 'ext.pharmacopedia.administer' ] );
        $out->addModules( [ 'ext.pharmacopedia.administer' ] );

        $token = trim( (string)$par );
        if ( $token === '' ) {
            $out->addHTML( $this->errorBox( 'This page needs an invitation link. '
                . 'Please use the link you were sent.' ) );
            return;
        }

        $cp = MediaWikiServices::getInstance()->getConnectionProvider();
        $dbr = $cp->getReplicaDatabase();
        $hash = AdminCrypto::hashInviteToken( $token );
        $inv = $dbr->selectRow( 'pcp_administer_invites',
            [ 'inv_id', 'inv_owner_user_id', 'inv_status', 'inv_expires' ],
            [ 'inv_token_hash' => $hash ], __METHOD__
        );
        if ( !$inv ) {
            $out->addHTML( $this->errorBox( 'This invitation link is not valid. It may have '
                . 'been mistyped, or the dashboard it led to may have been deleted.' ) );
            return;
        }
        $now = wfTimestamp( TS_MW );
        // Only an un-taken (pending) invite expires; a completed dashboard persists.
        if ( (string)$inv->inv_status === 'expired'
            || ( (string)$inv->inv_status === 'pending' && (string)$inv->inv_expires < $now )
        ) {
            $out->addHTML( $this->errorBox( 'This invitation has expired. Please ask '
                . 'the person who invited you to send a new link.' ) );
            return;
        }

        $invId = (int)$inv->inv_id;
        $ownerUid = (int)$inv->inv_owner_user_id;
        $req = $this->getRequest();
        $action = (string)$req->getVal( 'pcp_action', '' );

        $scales = $this->loadScales( $dbr, $invId );
        if ( !$scales ) {
            $out->addHTML( $this->errorBox( 'This invitation has no scales attached.' ) );
            return;
        }

        // The invite token is the bearer credential; there is no edit token
        // (the respondent has no account or session). Knowing the token is
        // already full authority over this invite.

        // Respondent confirmed deletion of the dashboard.
        if ( $req->wasPosted() && $action === 'delete_confirm' ) {
            $this->deleteInvite( $invId );
            $out->addHTML( $this->renderDeleted() );
            return;
        }

        // A submitted scale.
        if ( $req->wasPosted() && $action === 'answer' ) {
            $this->handleAnswer( $ownerUid, $invId, $scales, $req, $token );
            // Re-read from the PRIMARY database: handleAnswer just wrote
            // the 'done' status, and a replica read here can lag and miss
            // it, which would re-show an already-completed scale.
            $scales = $this->loadScales(
                MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase(),
                $invId
            );
        }

        $pending = null;
        $doneCount = 0;
        foreach ( $scales as $s ) {
            if ( $s['done'] ) {
                $doneCount++;
            } elseif ( $pending === null ) {
                $pending = $s;
            }
        }

        // Every scale done: this url is now the respondent's dashboard.
        if ( $pending === null ) {
            if ( $req->wasPosted() && $action === 'delete' ) {
                $out->addHTML( $this->renderDeleteConfirm( $token ) );
                return;
            }
            $out->addHTML( $this->renderRespondentReport( $invId, $token ) );
            return;
        }

        // Scales still to take. Consent first, unless the respondent is
        // mid-flow or just clicked Continue.
        $begun = ( $req->wasPosted() && $action === 'begin' );
        if ( $doneCount === 0 && !$begun
            && !( $req->wasPosted() && $action === 'answer' )
        ) {
            $out->addHTML( $this->renderConsent( $ownerUid, $token ) );
            return;
        }
        $out->addHTML( $this->renderScale( $pending, $doneCount, count( $scales ), $token ) );
    }

    /** Read the invite's scales, in order. */
    private function loadScales( $dbr, int $invId ): array {
        $scales = [];
        $res = $dbr->select( 'pcp_administer_assessments',
            [ 'aa_id', 'aa_instrument', 'aa_status' ],
            [ 'aa_invite_id' => $invId ],
            __METHOD__, [ 'ORDER BY' => 'aa_order' ]
        );
        foreach ( $res as $row ) {
            $scales[] = [
                'id'   => (int)$row->aa_id,
                'slug' => (string)$row->aa_instrument,
                'done' => ( (string)$row->aa_status === 'done' ),
            ];
        }
        return $scales;
    }

    /** Score, seal (owner + respondent copies), and store one submitted scale. */
    private function handleAnswer( int $ownerUid, int $invId, array $scales, $req, string $rawToken ): void {
        $aaId = (int)$req->getVal( 'pcp_aa_id' );
        $scale = null;
        foreach ( $scales as $s ) {
            if ( $s['id'] === $aaId ) {
                $scale = $s;
                break;
            }
        }
        if ( $scale === null || $scale['done'] ) {
            return;
        }
        $slug = $scale['slug'];
        $scorer = AssessmentRegistry::scorerClass( $slug );
        if ( $scorer === null ) {
            return;
        }
        $spec = AssessmentRegistry::spec( $slug ) ?? [];
        $isFloat = ( AssessmentRegistry::model( $slug ) !== 'radio' );
        $min = (float)( $spec['min'] ?? 0 );
        $max = (float)( $spec['max'] ?? 100 );

        // Collect responses (r[<itemNumber>]) and "Not sure" items (unsure[<n>]).
        $raw    = $req->getArray( 'r' ) ?: [];
        $unsure = $req->getArray( 'unsure' ) ?: [];
        $responses = [];
        $idkItems  = [];
        foreach ( array_keys( $scorer::ITEMS ) as $n ) {
            $n = (int)$n;
            if ( !empty( $unsure[ $n ] ) ) {
                $idkItems[] = $n;
                continue;
            }
            if ( !isset( $raw[ $n ] ) || $raw[ $n ] === '' || $raw[ $n ] === null ) {
                continue;
            }
            if ( $isFloat ) {
                $v = (float)$raw[ $n ];
                if ( $v < $min ) {
                    $v = $min;
                }
                if ( $v > $max ) {
                    $v = $max;
                }
                $responses[ $n ] = $v;
            } else {
                $responses[ $n ] = (int)$raw[ $n ];
            }
        }

        // Every scorer accepts $responses; the few that use a second
        // argument ($idkItems) get it, and the rest ignore the extra.
        $scores = $scorer::scoreResponses( $responses, $idkItems );
        $payload = json_encode( [
            'responses' => $responses,
            'idk'       => $idkItems,
            'scores'    => $scores,
        ] );

        $dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
        $now = wfTimestamp( TS_MW );

        // Owner copy (sealed to the owner's public key) and respondent copy
        // (sealed to a key derived from the invite token they hold).
        $dbw->update( 'pcp_administer_assessments', [
            'aa_status'         => 'done',
            'aa_payload_enc'    => AdminCrypto::encryptForOwner( $ownerUid, $payload ),
            'aa_respondent_enc' => AdminCrypto::encryptForRespondent( $rawToken, $payload ),
            'aa_completed_at'   => $now,
        ], [ 'aa_id' => $aaId, 'aa_status' => 'pending' ], __METHOD__ );

        // The update is guarded on aa_status = 'pending', so it changes
        // exactly one row only when THIS request is the one that completed
        // the scale. A resubmit, back-button, or replica-lag race updates
        // zero rows; in that case the scale was already scored and the
        // de-identified research copy must NOT be written again (the
        // consent text promises a single copy).
        if ( $dbw->affectedRows() === 1 ) {
            // De-identified research copy: no owner, no respondent, no FK,
            // random id, month only.
            $dbw->insert( 'pcp_administer_research', [
                'res_id'         => random_bytes( 16 ),
                'res_instrument' => $slug,
                'res_payload'    => $payload,
                'res_month'      => gmdate( 'Y-m' ),
            ], __METHOD__ );
        }

        // If every scale is now done, complete the invite.
        $stillPending = $dbw->selectField( 'pcp_administer_assessments', 'COUNT(*)',
            [ 'aa_invite_id' => $invId, 'aa_status' => 'pending' ], __METHOD__
        );
        if ( (int)$stillPending === 0 ) {
            $dbw->update( 'pcp_administer_invites', [
                'inv_status'       => 'completed',
                'inv_completed_at' => $now,
            ], [ 'inv_id' => $invId ], __METHOD__ );
        }
    }

    /**
     * Delete an invite and its per-scale rows (the owner and respondent
     * encrypted copies). The de-identified research copy is decoupled and
     * anonymous and is intentionally left in the research pool.
     */
    private function deleteInvite( int $invId ): void {
        $dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
        $dbw->delete( 'pcp_administer_assessments', [ 'aa_invite_id' => $invId ], __METHOD__ );
        $dbw->delete( 'pcp_administer_invites', [ 'inv_id' => $invId ], __METHOD__ );
    }

    /** The consent screen. Approved copy; encryption block is mode-aware. */
    private function renderConsent( int $ownerUid, string $token ): string {
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $mode = (string)$dbr->selectField( 'pcp_administer_userkey', 'uk_mode',
            [ 'uk_user_id' => $ownerUid ], __METHOD__
        );
        $self = htmlspecialchars( $this->getPageTitle( $token )->getLocalURL() );

        $h = '<div class="pcp-adm"><div class="consent">';
        $h .= '<h3 class="consent-h serif">Before you begin</h3>';
        $h .= '<p>Someone has invited you to fill out one or more brief questionnaires '
            . 'on pharmacopedia.wiki. Your answers go to the person who invited you. You '
            . 'do not need an account, and this site does not ask you for your name or '
            . 'contact details. (The person who invited you has labeled their invitation '
            . 'with a name of their own choosing; only they see it.)</p>';
        if ( $mode === 'passphrase' ) {
            $h .= '<div class="enc"><p class="mode-tag">Encryption, Mode A, passphrase</p>'
                . '<p>Your answers are encrypted with a key held only by the person who '
                . 'invited you. They are stored in a form no one else can read, not even '
                . "this site's administrator. Only your inviter can open your results.</p></div>";
        } else {
            $h .= '<div class="enc modeb"><p class="mode-tag">Encryption, Mode B, managed</p>'
                . '<p>Your answers are encrypted before they are stored. For convenience, '
                . 'the person who invited you chose to let this site help manage their '
                . 'encryption key. Because of that, someone with full administrative access '
                . "to this site's servers could technically read your results. Your inviter "
                . 'made that choice knowingly.</p></div>';
        }
        $h .= '<p>This site is not a doctor, a clinic, or a healthcare provider. These '
            . 'questionnaires are not a medical record, and this process is not covered by '
            . 'HIPAA or other medical-privacy law. Please treat it as an informal '
            . 'questionnaire rather than confidential clinical care.</p>';
        $h .= '<p>When you submit your answers, a second copy is saved with everything '
            . 'identifying removed: no name, no connection to you or to the person who '
            . 'invited you, and only the month rather than the exact date. That '
            . 'de-identified copy may be pooled with many others and used for research. '
            . 'It cannot be traced back to you.</p>';
        $h .= '<p>When you finish, this same link becomes your own results page. You can '
            . 'return to it anytime to see your results, and either you or the person who '
            . 'invited you can delete it whenever you choose.</p>';
        $h .= '<p>If you are comfortable with all of this, continue. If not, simply close '
            . 'this page; nothing is saved unless you submit.</p>';
        $h .= '<form method="post" action="' . $self . '">'
            . '<input type="hidden" name="pcp_action" value="begin">'
            . '<div class="btn-row"><button type="submit" class="btn btn-primary">'
            . 'Continue</button></div></form>';
        $h .= '</div></div>';
        return $h;
    }

    /** One scale's items, rendered for its registry model. */
    private function renderScale( array $scale, int $doneCount, int $total, string $token ): string {
        $slug = $scale['slug'];
        $scorer = AssessmentRegistry::scorerClass( $slug );
        if ( $scorer === null ) {
            return $this->errorBox( 'This scale is not available.' );
        }
        $model = AssessmentRegistry::model( $slug );
        $spec  = AssessmentRegistry::spec( $slug ) ?? [];
        $items = $scorer::ITEMS;
        $name  = $scorer::NAME;
        $self  = htmlspecialchars( $this->getPageTitle( $token )->getLocalURL() );

        $h = '<div class="pcp-adm"><div class="consent">';
        $h .= '<h3 class="consent-h serif">' . htmlspecialchars( (string)$name ) . '</h3>';
        if ( $total > 1 ) {
            $h .= '<p class="mode-tag">Scale ' . ( $doneCount + 1 ) . ' of ' . $total . '</p>';
        }
        if ( $model === 'radio' ) {
            $h .= '<p class="rp-note">Choose the answer that fits best for each item. '
                . 'If an item truly does not apply, tick "Not sure".</p>';
        } else {
            $h .= '<p class="rp-note">Move each slider to where it fits. If an item truly '
                . 'does not apply, tick "Not sure".</p>';
        }
        $h .= '</div>';

        $h .= '<form method="post" action="' . $self . '" class="pcp-adm-take">';
        $h .= '<input type="hidden" name="pcp_action" value="answer">';
        $h .= '<input type="hidden" name="pcp_aa_id" value="' . (int)$scale['id'] . '">';
        foreach ( $items as $n => $itemData ) {
            $h .= $this->renderItem( $model, $spec, $scorer, (int)$n, $itemData );
        }
        $h .= '<div class="pcp-adm"><div class="btn-row"><button type="submit" '
            . 'class="btn btn-primary">Submit</button></div></div>';
        $h .= '</form>';
        return $h;
    }

    /** Dispatch one item to the renderer for its model. */
    private function renderItem( string $model, array $spec, string $scorer, int $n, $itemData ): string {
        if ( $model === 'radio' ) {
            $stem = is_array( $itemData ) ? (string)( $itemData[0] ?? '' ) : (string)$itemData;
            return $this->renderRadioItem( $scorer, $n, $stem );
        }
        if ( $model === 'bipolar' ) {
            // MBTI: itemData = [ leftPhrase, rightPhrase, dichotomy, rightPole ].
            $left  = is_array( $itemData ) ? (string)( $itemData[0] ?? '' ) : '';
            $right = is_array( $itemData ) ? (string)( $itemData[1] ?? '' ) : '';
            return $this->renderSliderItem( $spec, $n, '', $left, $right );
        }
        // slider
        $stem = is_array( $itemData ) ? (string)( $itemData[0] ?? '' ) : (string)$itemData;
        return $this->renderSliderItem( $spec, $n, $stem,
            (string)( $spec['lo'] ?? '' ), (string)( $spec['hi'] ?? '' ) );
    }

    /** A discrete radio-button item. */
    private function renderRadioItem( string $scorer, int $n, string $stem ): string {
        $labels = $scorer::RESPONSE_LABELS;
        $h = '<fieldset class="pcp-assess-item" data-itemnum="' . $n . '">';
        $h .= '<legend>' . $n . '. ' . htmlspecialchars( $stem ) . '</legend>';
        $h .= '<div class="pcp-adm-opts">';
        foreach ( $labels as $val => $label ) {
            $id = 'r_' . $n . '_' . (int)$val;
            $h .= '<label for="' . $id . '" class="pcp-adm-opt">'
                . '<input type="radio" id="' . $id . '" name="r[' . $n . ']" '
                . 'value="' . (int)$val . '" required> '
                . htmlspecialchars( (string)$label ) . '</label>';
        }
        $h .= '</div>';
        $h .= $this->unsureControl( $n );
        $h .= '</fieldset>';
        return $h;
    }

    /**
     * A slider item. $stem empty means a bipolar (MBTI) item, where the
     * lo/hi anchors are the two phrases and there is no statement.
     */
    private function renderSliderItem( array $spec, int $n, string $stem, string $lo, string $hi ): string {
        $min  = $spec['min'] ?? 0;
        $max  = $spec['max'] ?? 100;
        $step = $spec['step'] ?? 1;
        $default = ( $min + $max ) / 2;
        $h = '<fieldset class="pcp-assess-item pcp-adm-slideritem" data-itemnum="' . $n . '">';
        if ( $stem !== '' ) {
            $h .= '<legend>' . $n . '. ' . htmlspecialchars( $stem ) . '</legend>';
        } else {
            $h .= '<legend>' . $n . '.</legend>';
        }
        $h .= '<div class="pcp-adm-slider-row">';
        $h .= '<span class="pcp-adm-anchor pcp-adm-anchor-lo">' . htmlspecialchars( $lo ) . '</span>';
        $h .= '<input type="range" class="pcp-adm-slider" name="r[' . $n . ']" '
            . 'min="' . htmlspecialchars( (string)$min ) . '" '
            . 'max="' . htmlspecialchars( (string)$max ) . '" '
            . 'step="' . htmlspecialchars( (string)$step ) . '" '
            . 'value="' . htmlspecialchars( (string)$default ) . '">';
        $h .= '<output class="pcp-adm-sliderval">' . htmlspecialchars( (string)$default ) . '</output>';
        $h .= '<span class="pcp-adm-anchor pcp-adm-anchor-hi">' . htmlspecialchars( $hi ) . '</span>';
        $h .= '</div>';
        $h .= $this->unsureControl( $n );
        $h .= '</fieldset>';
        return $h;
    }

    /** The uniform per-item "Not sure" control. */
    private function unsureControl( int $n ): string {
        return '<label class="pcp-adm-unsure">'
            . '<input type="checkbox" name="unsure[' . $n . ']" value="1"> Not sure</label>';
    }

    /**
     * The respondent's own results dashboard, shown once every scale is
     * done - on finish and on every later revisit to this same link.
     */
    private function renderRespondentReport( int $invId, string $token ): string {
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $rows = $dbr->select( 'pcp_administer_assessments',
            [ 'aa_instrument', 'aa_respondent_enc', 'aa_completed_at' ],
            [ 'aa_invite_id' => $invId, 'aa_status' => 'done' ],
            __METHOD__, [ 'ORDER BY' => 'aa_order' ]
        );
        $self = htmlspecialchars( $this->getPageTitle( $token )->getLocalURL() );

        $h = '<div class="pcp-adm">';
        $h .= '<div class="done"><div class="done-mark"><span></span></div>';
        $h .= '<h3 class="serif">Your results</h3>';
        $h .= '<p>Thank you for completing this. Here is what you reported. The person who '
            . 'invited you can also see these results. You can return to this same link '
            . 'anytime to view this page again.</p></div>';

        foreach ( $rows as $row ) {
            $slug  = (string)$row->aa_instrument;
            $label = AssessmentRegistry::label( $slug );
            $data  = null;
            if ( $row->aa_respondent_enc !== null && $row->aa_respondent_enc !== '' ) {
                try {
                    $json = AdminCrypto::decryptForRespondent( $token, $row->aa_respondent_enc );
                    $decoded = json_decode( $json, true );
                    if ( is_array( $decoded ) ) {
                        $data = $decoded;
                    }
                } catch ( \RuntimeException $e ) {
                    $data = null;
                }
            }
            $h .= '<div class="block"><div class="block-h"><span class="bh-name serif">'
                . htmlspecialchars( $label ) . '</span>';
            if ( $row->aa_completed_at ) {
                $h .= '<span class="bh-meta">' . htmlspecialchars(
                    $this->getLanguage()->date( (string)$row->aa_completed_at )
                ) . '</span>';
            }
            $h .= '</div>';
            if ( $data === null || !isset( $data['scores'] ) || !is_array( $data['scores'] ) ) {
                $h .= '<p class="block-copy">Results for this scale could not be displayed.</p>';
            } else {
                $h .= $this->renderScoreBlock( $data['scores'] );
            }
            $h .= '</div>';
        }

        $h .= '<div class="block"><form method="post" action="' . $self . '">'
            . '<input type="hidden" name="pcp_action" value="delete">'
            . '<p class="block-copy">Finished with this? You can permanently delete this '
            . 'dashboard. It will no longer be visible to you or to the person who '
            . 'invited you.</p>'
            . '<div class="btn-row"><button type="submit" class="btn">'
            . 'Delete this dashboard</button></div></form></div>';
        $h .= '</div>';
        return $h;
    }

    /** Render one scale's score array as a clean labelled list. */
    private function renderScoreBlock( array $scores ): string {
        $totalRow = '';
        $rows = '';
        foreach ( $scores as $key => $val ) {
            if ( !is_int( $val ) && !is_float( $val ) ) {
                continue;
            }
            $label = $this->scoreKeyLabel( (string)$key );
            if ( $label === null ) {
                continue;
            }
            if ( is_float( $val ) ) {
                $display = rtrim( rtrim( number_format( $val, 2, '.', '' ), '0' ), '.' );
            } else {
                $display = (string)$val;
            }
            if ( (string)$key === 'total' ) {
                $totalRow = '<div class="rep-row rep-row-total"><span class="rep-label">'
                    . htmlspecialchars( $label ) . '</span><span class="rep-val">'
                    . htmlspecialchars( $display ) . '</span></div>';
            } else {
                $rows .= '<div class="rep-row"><span class="rep-label">'
                    . htmlspecialchars( $label ) . '</span><span class="rep-val">'
                    . htmlspecialchars( $display ) . '</span></div>';
            }
        }
        if ( $totalRow === '' && $rows === '' ) {
            return '<p class="block-copy">Your responses were recorded.</p>';
        }
        return '<div class="rep-scores">' . $totalRow . $rows . '</div>';
    }

    /**
     * A human label for a score-array key, or null for internal bookkeeping
     * keys a respondent should not see. Generic; the full per-instrument
     * interpreted report is a planned follow-up.
     */
    private function scoreKeyLabel( string $key ): ?string {
        $skip = [
            'complete', 'count_INA', 'count_HI',
            'answered_INA', 'answered_HYP', 'answered_IMP',
            'answered',
        ];
        if ( in_array( $key, $skip, true ) ) {
            return null;
        }
        $known = [
            'total'        => 'Total score',
            'O'            => 'Openness',
            'C'            => 'Conscientiousness',
            'E'            => 'Extraversion',
            'A'            => 'Agreeableness',
            'N'            => 'Neuroticism',
            'EI'           => 'Extraversion - Introversion',
            'SN'           => 'Sensing - Intuition',
            'TF'           => 'Thinking - Feeling',
            'JP'           => 'Judging - Perceiving',
            'subscale_INA' => 'Inattention',
            'subscale_HYP' => 'Hyperactivity',
            'subscale_IMP' => 'Impulsivity',
            'dimension_HI' => 'Hyperactivity / impulsivity',
            'module_IMPAIR' => 'Functional impairment',
        ];
        if ( isset( $known[ $key ] ) ) {
            return $known[ $key ];
        }
        if ( preg_match( '/^type_(\d+)$/', $key, $m ) ) {
            return 'Type ' . $m[1];
        }
        if ( strpos( $key, 'subscale_' ) === 0 ) {
            return ucwords( str_replace( '_', ' ', substr( $key, 9 ) ) );
        }
        return ucfirst( str_replace( '_', ' ', $key ) );
    }

    /** Confirm step before the respondent deletes their dashboard. */
    private function renderDeleteConfirm( string $token ): string {
        $self = htmlspecialchars( $this->getPageTitle( $token )->getLocalURL() );
        return '<div class="pcp-adm"><div class="consent">'
            . '<h3 class="consent-h serif">Delete this dashboard?</h3>'
            . '<p>This permanently removes your results and this dashboard. The person who '
            . 'invited you will no longer be able to see them either. This cannot be '
            . 'undone.</p>'
            . '<p class="rp-note">A fully anonymous copy, with nothing that identifies you, '
            . 'remains in the research pool as described when you began. It cannot be '
            . 'traced back to you and is not affected.</p>'
            . '<form method="post" action="' . $self . '">'
            . '<input type="hidden" name="pcp_action" value="delete_confirm">'
            . '<div class="btn-row"><button type="submit" class="btn btn-danger">'
            . 'Delete permanently</button> '
            . '<a class="btn" href="' . $self . '">Cancel</a></div>'
            . '</form></div></div>';
    }

    /** Shown after the respondent deletes their dashboard. */
    private function renderDeleted(): string {
        return '<div class="pcp-adm"><div class="done">'
            . '<h3 class="serif">Dashboard deleted</h3>'
            . '<p>Your results and this dashboard have been permanently deleted. There is '
            . 'nothing more here; you can close this page.</p></div></div>';
    }

    private function errorBox( string $text ): string {
        return '<div class="pcp-adm"><div class="consent"><p>'
            . htmlspecialchars( $text ) . '</p></div></div>';
    }
}
