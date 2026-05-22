<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\Pharmacopedia\Assessments\AdminCrypto;
use MediaWiki\Extension\Pharmacopedia\Assessments\AssessmentRegistry;

/**
 * Special:AdministerAssessments - "Administer to others", the owner hub.
 *
 * Phase 2 surfaces 1 + 4. A logged-in owner sends assessment scales to
 * outside respondents and follows their results over time. On first run
 * the owner picks a key mode: managed (the seamless default) or a self-
 * set passphrase (the unrecoverable zero-knowledge opt-in). A passphrase-
 * mode owner unlocks once per login; the passphrase is held in the
 * MediaWiki session and decrypts the dashboard for that login only.
 */
class SpecialAdministerAssessments extends SpecialPage {

    /** Minimum passphrase length for Mode A. */
    private const MIN_PASSPHRASE = 8;

    public function __construct() {
        parent::__construct( 'AdministerAssessments' );
    }

    public function doesWrites() {
        return true;
    }

    public function getGroupName() {
        return 'users';
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->setPageTitle( 'Administer assessments' );
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles', 'ext.pharmacopedia.administer' ] );
        $user = $this->getUser();

        if ( !$user->isRegistered() ) {
            $out->addHTML(
                '<div class="pcp-adm"><p class="hub-intro">Please log in to '
                . 'administer assessments to others.</p></div>'
            );
            return;
        }
        $uid = (int)$user->getId();
        $req = $this->getRequest();
        $session = $req->getSession();
        $ppKey = 'pcp-adm-pp-' . $uid;

        $mode = $this->ownerKeyMode( $uid );
        $notice = '';
        $minted = '';
        $formError = '';

        if ( $req->wasPosted() && $user->matchEditToken( $req->getVal( 'wpEditToken' ) ) ) {
            $action = $req->getVal( 'pcp_action' );

            if ( $action === 'setup_key' && $mode === null ) {
                $keymode = $req->getVal( 'pcp_keymode' );
                if ( $keymode === 'managed' ) {
                    AdminCrypto::setupOwnerKey( $uid, 'managed' );
                    $mode = 'managed';
                } elseif ( $keymode === 'passphrase' ) {
                    $pp = (string)$req->getVal( 'pcp_passphrase' );
                    if ( mb_strlen( $pp ) < self::MIN_PASSPHRASE ) {
                        $formError = 'Choose a passphrase of at least '
                            . self::MIN_PASSPHRASE . ' characters.';
                    } else {
                        AdminCrypto::setupOwnerKey( $uid, 'passphrase', $pp );
                        $mode = 'passphrase';
                        $session->set( $ppKey, $pp );
                        $session->persist();
                    }
                }
            } elseif ( $action === 'unlock' && $mode === 'passphrase' ) {
                $pp = (string)$req->getVal( 'pcp_passphrase' );
                if ( AdminCrypto::verifyPassphrase( $uid, $pp ) ) {
                    $session->set( $ppKey, $pp );
                    $session->persist();
                } else {
                    $formError = 'That passphrase did not match. Try again.';
                }
            } elseif ( $action === 'lock' ) {
                $session->remove( $ppKey );
            } elseif ( $mode !== null && (
                $action === 'add_respondent' || $action === 'send'
                || $action === 'delete_invite'
            ) ) {
                list( $notice, $minted ) = $this->handlePost( $uid, $req, $action );
            }
        }

        // First run: no key yet. Show the mode choice.
        if ( $mode === null ) {
            $out->addHTML( $this->renderModeChoice( $formError ) );
            return;
        }

        // Unlock the owner secret key for this request only.
        $secretKey = null;
        if ( $mode === 'managed' ) {
            try {
                $secretKey = AdminCrypto::unlockSecretKey( $uid );
            } catch ( \RuntimeException $e ) {
                $secretKey = null;
            }
        } else {
            $pp = $session->get( $ppKey );
            if ( $pp === null || $pp === '' ) {
                $out->addHTML( $this->renderUnlock( $formError ) );
                return;
            }
            try {
                $secretKey = AdminCrypto::unlockSecretKey( $uid, (string)$pp );
            } catch ( \RuntimeException $e ) {
                $session->remove( $ppKey );
                $out->addHTML( $this->renderUnlock(
                    'Your unlocked session ended. Please enter your passphrase again.' ) );
                return;
            }
        }

        $out->addHTML( $this->renderHub( $uid, $secretKey, $mode, $notice, $minted ) );
    }

    /** Current key mode for the owner: 'managed', 'passphrase', or null. */
    private function ownerKeyMode( int $uid ): ?string {
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $m = $dbr->selectField( 'pcp_administer_userkey', 'uk_mode',
            [ 'uk_user_id' => $uid ], __METHOD__ );
        return ( $m === false || $m === null ) ? null : (string)$m;
    }

    /** First-run mode choice. Managed = recommended/seamless; passphrase = opt-in. */
    private function renderModeChoice( string $error ): string {
        $self = htmlspecialchars( $this->getPageTitle()->getLocalURL() );
        $token = htmlspecialchars( $this->getUser()->getEditToken() );

        $h = '<div class="pcp-adm">';
        $h .= '<h3 class="keyhead serif">How should your respondents&#8217; results '
            . 'be protected?</h3>';
        if ( $error !== '' ) {
            $h .= '<p class="block-copy" style="color:var(--a-danger,#c25a52)">'
                . htmlspecialchars( $error ) . '</p>';
        }
        $h .= '<div class="mode-pick">';
        // managed - recommended
        $h .= '<div class="mode-card rec"><p class="mc-tag">Recommended</p>'
            . '<p class="mc-name serif">Managed</p>'
            . '<p class="mc-desc">No passphrase to remember, and a forgotten site '
            . 'password never costs you your data. The site holds the key, so a site '
            . 'administrator could technically read the results.</p>'
            . '<form method="post" action="' . $self . '">'
            . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
            . '<input type="hidden" name="pcp_action" value="setup_key">'
            . '<input type="hidden" name="pcp_keymode" value="managed">'
            . '<div class="btn-row"><button type="submit" class="btn btn-primary">'
            . 'Use managed protection</button></div></form></div>';
        // passphrase - opt-in
        $h .= '<div class="mode-card warn"><p class="mc-tag">Advanced</p>'
            . '<p class="mc-name serif">Passphrase</p>'
            . '<p class="mc-desc">A passphrase only you know. No one else, including '
            . 'this site, can read the results. It cannot be recovered if lost.</p>'
            . '<form method="post" action="' . $self . '">'
            . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
            . '<input type="hidden" name="pcp_action" value="setup_key">'
            . '<input type="hidden" name="pcp_keymode" value="passphrase">'
            . '<div class="caution severe">'
            . '<p>This passphrase encrypts your respondents&#8217; results. It is never '
            . 'sent to this site and never stored here, which is what keeps those '
            . 'results readable by you alone.</p>'
            . '<p><strong>It also cannot be recovered or reset.</strong> If you forget '
            . 'or lose it, every result you have collected becomes permanently '
            . 'unreadable, by you and by everyone else. No one can restore it for you.</p>'
            . '<p>Choose something you will not lose, and write it down somewhere safe '
            . 'before you continue.</p></div>'
            . '<p class="field-label">Passphrase (at least ' . self::MIN_PASSPHRASE
            . ' characters)</p>'
            . '<input type="password" name="pcp_passphrase" class="field-input" '
            . 'autocomplete="new-password">'
            . '<div class="btn-row"><button type="submit" class="btn btn-primary">'
            . 'Set this passphrase</button></div></form></div>';
        $h .= '</div></div>';
        return $h;
    }

    /** Per-login unlock prompt for a passphrase-mode owner. */
    private function renderUnlock( string $error ): string {
        $self = htmlspecialchars( $this->getPageTitle()->getLocalURL() );
        $token = htmlspecialchars( $this->getUser()->getEditToken() );
        $h = '<div class="pcp-adm">';
        $h .= '<h3 class="keyhead serif">Unlock your results</h3>';
        if ( $error !== '' ) {
            $h .= '<p class="block-copy" style="color:var(--a-danger,#c25a52)">'
                . htmlspecialchars( $error ) . '</p>';
        }
        $h .= '<form method="post" action="' . $self . '">'
            . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
            . '<input type="hidden" name="pcp_action" value="unlock">'
            . '<p class="field-label">Enter your passphrase to open your '
            . 'respondents&#8217; results</p>'
            . '<input type="password" name="pcp_passphrase" class="field-input" '
            . 'autocomplete="current-password">'
            . '<p class="rp-note" style="margin:9px 0 0;">Held for this login session '
            . 'only, never stored. You will enter it again next time you log in.</p>'
            . '<div class="btn-row"><button type="submit" class="btn btn-primary">'
            . 'Unlock</button></div></form>';
        $h .= '</div>';
        return $h;
    }

    /**
     * Handle the add-respondent and send POST actions.
     * @return array [ noticeHtml, mintedLinkHtml ]
     */
    private function handlePost( int $uid, $req, string $action ): array {
        $dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
        $now = wfTimestamp( TS_MW );

        if ( $action === 'add_respondent' ) {
            $name = trim( (string)$req->getVal( 'pcp_resp_name' ) );
            if ( $name === '' ) {
                return [ $this->notice( 'A respondent needs a name.', true ), '' ];
            }
            if ( mb_strlen( $name ) > 120 ) {
                $name = mb_substr( $name, 0, 120 );
            }
            $dbw->insert( 'pcp_administer_respondents', [
                'r_owner_user_id' => $uid,
                'r_name_enc'      => AdminCrypto::encryptForOwner( $uid, $name ),
                'r_created'       => $now,
                'r_updated'       => $now,
            ], __METHOD__ );
            return [ $this->notice( 'Respondent added.', false ), '' ];
        }

        if ( $action === 'delete_invite' ) {
            $invId = (int)$req->getVal( 'pcp_invite_id' );
            // Only the owning user may delete; the WHERE scopes it by owner.
            $owns = $dbw->selectField( 'pcp_administer_invites', 'inv_id', [
                'inv_id' => $invId, 'inv_owner_user_id' => $uid,
            ], __METHOD__ );
            if ( !$owns ) {
                return [ $this->notice( 'Unknown invite.', true ), '' ];
            }
            // Removes the owner and respondent encrypted copies and the
            // invite. The de-identified research copy is decoupled and stays.
            $dbw->delete( 'pcp_administer_assessments', [ 'aa_invite_id' => $invId ], __METHOD__ );
            $dbw->delete( 'pcp_administer_invites', [ 'inv_id' => $invId ], __METHOD__ );
            return [ $this->notice( 'Dashboard deleted. It is no longer visible to you '
                . 'or to the respondent.', false ), '' ];
        }

        // action === 'send'
        $respId = (int)$req->getVal( 'pcp_resp_id' );
        $scales = $req->getArray( 'pcp_scales' ) ?: [];
        $scales = array_values( array_intersect( AssessmentRegistry::keys(), $scales ) );
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $owns = $dbr->selectField( 'pcp_administer_respondents', 'r_id', [
            'r_id' => $respId, 'r_owner_user_id' => $uid,
        ], __METHOD__ );
        if ( !$owns ) {
            return [ $this->notice( 'Unknown respondent.', true ), '' ];
        }
        if ( !$scales ) {
            return [ $this->notice( 'Pick at least one scale to send.', true ), '' ];
        }
        list( $rawToken, $tokenHash ) = AdminCrypto::mintInviteToken();
        $expires = wfTimestamp( TS_MW, time() + 30 * 24 * 3600 );
        $dbw->insert( 'pcp_administer_invites', [
            'inv_respondent_id' => $respId,
            'inv_owner_user_id' => $uid,
            'inv_token_hash'    => $tokenHash,
            'inv_status'        => 'pending',
            'inv_created'       => $now,
            'inv_expires'       => $expires,
        ], __METHOD__ );
        $invId = (int)$dbw->insertId();
        $order = 0;
        foreach ( $scales as $slug ) {
            $dbw->insert( 'pcp_administer_assessments', [
                'aa_invite_id'      => $invId,
                'aa_instrument'     => $slug,
                'aa_order'          => $order++,
                'aa_status'         => 'pending',
                'aa_scheme_version' => AdminCrypto::SCHEME_VERSION,
            ], __METHOD__ );
        }
        $url = SpecialPage::getTitleFor( 'RespondToAssessment', $rawToken )->getFullURL();
        $link = '<div class="link-out"><span class="lo-url">'
            . htmlspecialchars( $url ) . '</span></div>'
            . '<p class="block-copy">Copy this one-time link and send it to your '
            . 'respondent yourself. It works once and expires in 30 days.</p>';
        return [ $this->notice( 'Link generated.', false ), $link ];
    }

    private function notice( string $text, bool $isError ): string {
        return '<p class="block-copy" style="color:'
            . ( $isError ? 'var(--a-danger,#c25a52)' : 'var(--a-green,#6f9c6a)' ) . '">'
            . htmlspecialchars( $text ) . '</p>';
    }

    /** Render the whole hub. */
    private function renderHub( int $uid, string $secretKey, string $mode,
        string $notice, string $minted ): string {
        $token = htmlspecialchars( $this->getUser()->getEditToken() );
        $self = htmlspecialchars( $this->getPageTitle()->getLocalURL() );
        $respondents = $this->loadRespondents( $uid, $secretKey );

        $h = '<div class="pcp-adm">';
        $h .= '<h2 class="hub-h serif">Administer assessments</h2>';
        $h .= '<p class="hub-intro">Send assessment scales to people outside the wiki '
            . 'and follow their results over time. The people you send to do not need '
            . 'an account.</p>';
        if ( $mode === 'passphrase' ) {
            $h .= '<p class="block-copy">Your results are protected by your passphrase '
                . '(unlocked for this login). '
                . '<form method="post" action="' . $self . '" style="display:inline">'
                . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
                . '<input type="hidden" name="pcp_action" value="lock">'
                . '<button type="submit" class="lo-copy" style="cursor:pointer">'
                . 'Lock now</button></form></p>';
        }
        $h .= $notice;

        // --- compose-and-send ---
        $h .= '<div class="block"><div class="block-h"><span class="bh-name serif">'
            . 'Send a scale</span></div>';
        if ( !$respondents ) {
            $h .= '<p class="block-copy">Add a respondent below first, then come back '
                . 'here to send them a scale.</p>';
        } else {
            $h .= '<form method="post" action="' . $self . '">';
            $h .= '<input type="hidden" name="wpEditToken" value="' . $token . '">';
            $h .= '<input type="hidden" name="pcp_action" value="send">';
            $h .= '<div class="compose">';
            $h .= '<p class="field-label">Respondent</p><select name="pcp_resp_id" class="field-input">';
            foreach ( $respondents as $r ) {
                $h .= '<option value="' . (int)$r['id'] . '">'
                    . htmlspecialchars( $r['name'] ) . '</option>';
            }
            $h .= '</select>';
            $h .= '<p class="field-label" style="margin-top:13px;">Scales</p>';
            $h .= '<div class="scale-grid">';
            foreach ( AssessmentRegistry::keysByLabel() as $slug ) {
                $h .= '<label class="scale-opt"><input type="checkbox" name="pcp_scales[]" '
                    . 'value="' . htmlspecialchars( $slug ) . '"> '
                    . htmlspecialchars( AssessmentRegistry::label( $slug ) ) . '</label>';
            }
            $h .= '</div>';
            $h .= '<div class="btn-row"><button type="submit" class="btn btn-primary">'
                . 'Generate one-time link</button></div>';
            $h .= $minted;
            $h .= '</div></form>';
        }
        $h .= '</div>';

        // --- respondents + dashboards ---
        $h .= '<div class="block"><div class="block-h"><span class="bh-name serif">'
            . 'Respondents</span></div>';
        $h .= '<p class="block-copy">Add someone by giving them a name; use whatever '
            . 'helps you recognize them. Only you see this list.</p>';
        foreach ( $respondents as $r ) {
            $h .= $this->renderRespondentPanel( $uid, $secretKey, $r );
        }
        $h .= '<form method="post" action="' . $self . '" class="resp-add-form">'
            . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
            . '<input type="hidden" name="pcp_action" value="add_respondent">'
            . '<div class="compose"><p class="field-label">New respondent name</p>'
            . '<input type="text" name="pcp_resp_name" class="field-input" maxlength="120" '
            . 'autocomplete="off">'
            . '<div class="btn-row"><button type="submit" class="btn btn-primary">'
            . 'Add a respondent</button></div></div></form>';
        $h .= '</div>';

        // --- sent invites ---
        $h .= $this->renderSentInvites( $uid, $respondents );

        $h .= '</div>';
        return $h;
    }

    /** Owner's respondents, names decrypted with the unlocked secret key. */
    private function loadRespondents( int $uid, string $secretKey ): array {
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $rows = $dbr->select( 'pcp_administer_respondents',
            [ 'r_id', 'r_name_enc' ],
            [ 'r_owner_user_id' => $uid ],
            __METHOD__, [ 'ORDER BY' => 'r_id' ]
        );
        $out = [];
        foreach ( $rows as $row ) {
            $name = '(unreadable)';
            try {
                $name = AdminCrypto::decryptForOwner( $uid, $secretKey, $row->r_name_enc );
            } catch ( \RuntimeException $e ) {
                $name = '(unreadable)';
            }
            $out[] = [ 'id' => (int)$row->r_id, 'name' => $name ];
        }
        return $out;
    }

    /** One respondent panel with its results-over-time dashboard. */
    private function renderRespondentPanel( int $uid, string $secretKey, array $r ): string {
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $rows = $dbr->select(
            [ 'pcp_administer_assessments', 'pcp_administer_invites' ],
            [ 'aa_instrument', 'aa_payload_enc', 'aa_completed_at' ],
            [
                'inv_respondent_id' => $r['id'],
                'inv_owner_user_id' => $uid,
                'aa_status'         => 'done',
            ],
            __METHOD__,
            [ 'ORDER BY' => 'aa_completed_at' ],
            [ 'pcp_administer_invites' => [ 'INNER JOIN', 'aa_invite_id = inv_id' ] ]
        );
        $series = [];
        $flat = [];
        foreach ( $rows as $row ) {
            $instr = (string)$row->aa_instrument;
            try {
                $json = AdminCrypto::decryptForOwner( $uid, $secretKey, $row->aa_payload_enc );
                $data = json_decode( $json, true );
            } catch ( \RuntimeException $e ) {
                continue;
            }
            $total = ( is_array( $data ) && isset( $data['scores']['total'] ) )
                ? $data['scores']['total'] : null;
            if ( is_int( $total ) || is_float( $total ) ) {
                $series[ $instr ][] = (float)$total;
            } else {
                $flat[ $instr ] = ( $flat[ $instr ] ?? 0 ) + 1;
            }
        }

        $scaleCount = count( $series ) + count( $flat );
        $h = '<div class="resp"><div class="resp-head">'
            . '<span class="resp-name serif">' . htmlspecialchars( $r['name'] ) . '</span>'
            . '<span class="resp-meta">' . $scaleCount . ' scale'
            . ( $scaleCount === 1 ? '' : 's' ) . '</span></div>';
        if ( !$series && !$flat ) {
            $h .= '<div class="trend-row"><div class="tr-scale">No completed '
                . 'assessments yet.</div></div>';
        } else {
            foreach ( $series as $instr => $vals ) {
                $h .= $this->renderTrendRow( $instr, $vals );
            }
            foreach ( $flat as $instr => $count ) {
                $h .= $this->renderFlatRow( $instr, $count );
            }
        }
        $h .= '</div>';
        return $h;
    }

    /** One trend row: scale name, sparkline, latest, delta. */
    private function renderTrendRow( string $instr, array $vals ): string {
        $label = AssessmentRegistry::label( $instr );
        $n = count( $vals );
        $latest = $vals[ $n - 1 ];
        $h = '<div class="trend-row">';
        $h .= '<div class="tr-scale">' . htmlspecialchars( $label )
            . ' <span class="tr-n">&middot; ' . $n . ' take' . ( $n === 1 ? '' : 's' )
            . '</span></div>';
        $h .= '<div class="tr-spark">' . $this->sparkline( $vals ) . '</div>';
        $h .= '<div class="tr-latest">' . htmlspecialchars( (string)round( $latest, 1 ) )
            . '</div>';
        if ( $n < 2 ) {
            $h .= '<div class="tr-delta first">first take</div>';
        } else {
            $delta = $latest - $vals[ $n - 2 ];
            $cls = $delta > 0 ? 'up' : ( $delta < 0 ? 'down' : 'flat' );
            $glyph = $delta > 0 ? '&#9650; ' : ( $delta < 0 ? '&#9660; ' : '' );
            $h .= '<div class="tr-delta ' . $cls . '">' . $glyph
                . htmlspecialchars( (string)round( abs( $delta ), 1 ) ) . '</div>';
        }
        $h .= '</div>';
        return $h;
    }

    /** A completed scale with no single summary score: a plain done row. */
    private function renderFlatRow( string $instr, int $count ): string {
        $label = AssessmentRegistry::label( $instr );
        return '<div class="trend-row trend-row-flat">'
            . '<div class="tr-scale">' . htmlspecialchars( $label )
            . ' <span class="tr-n">&middot; ' . $count . ' take'
            . ( $count === 1 ? '' : 's' ) . '</span></div>'
            . '<div class="tr-flat-note">Completed</div></div>';
    }

    /** Inline SVG sparkline, 210x40, computed from the score series. */
    private function sparkline( array $vals ): string {
        $n = count( $vals );
        $min = min( $vals );
        $max = max( $vals );
        $span = ( $max - $min ) ?: 1.0;
        $x = function ( $i ) use ( $n ) {
            return $n < 2 ? 105.0 : 10.0 + ( 190.0 * $i / ( $n - 1 ) );
        };
        $y = function ( $v ) use ( $min, $span ) {
            return 31.0 - ( 21.0 * ( ( $v - $min ) / $span ) );
        };
        $svg = '<svg viewBox="0 0 210 40" preserveAspectRatio="none" aria-hidden="true">';
        if ( $n >= 2 ) {
            $pts = [];
            for ( $i = 0; $i < $n; $i++ ) {
                $pts[] = round( $x( $i ), 1 ) . ',' . round( $y( $vals[ $i ] ), 1 );
            }
            $svg .= '<polyline points="' . implode( ' ', $pts ) . '" fill="none" '
                . 'stroke="#8b5cf6" stroke-width="1.6"/>';
            for ( $i = 0; $i < $n - 1; $i++ ) {
                $svg .= '<circle cx="' . round( $x( $i ), 1 ) . '" cy="'
                    . round( $y( $vals[ $i ] ), 1 ) . '" r="2" fill="#5a4a78"/>';
            }
        }
        $svg .= '<circle cx="' . round( $x( $n - 1 ), 1 ) . '" cy="'
            . round( $y( $vals[ $n - 1 ] ), 1 ) . '" r="3" fill="#a78bfa"/>';
        $svg .= '</svg>';
        return $svg;
    }

    /** The sent-invites list. */
    private function renderSentInvites( int $uid, array $respondents ): string {
        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $rows = $dbr->select( 'pcp_administer_invites',
            [ 'inv_id', 'inv_respondent_id', 'inv_status', 'inv_created', 'inv_expires' ],
            [ 'inv_owner_user_id' => $uid ],
            __METHOD__, [ 'ORDER BY' => 'inv_created DESC' ]
        );
        $names = [];
        foreach ( $respondents as $r ) {
            $names[ $r['id'] ] = $r['name'];
        }
        $now = wfTimestamp( TS_MW );
        $token = htmlspecialchars( $this->getUser()->getEditToken() );
        $self  = htmlspecialchars( $this->getPageTitle()->getLocalURL() );
        $h = '<div class="block"><div class="block-h"><span class="bh-name serif">'
            . 'Sent</span></div>';
        $h .= '<p class="block-copy">Each link works once and expires 30 days after '
            . 'you create it. Once taken it becomes a results dashboard you and the '
            . 'respondent both keep; deleting it here removes it for both of you.</p>';
        $any = false;
        foreach ( $rows as $row ) {
            $any = true;
            $status = (string)$row->inv_status;
            if ( $status === 'pending' && (string)$row->inv_expires < $now ) {
                $status = 'expired';
            }
            $pill = '<span class="pill ' . htmlspecialchars( $status ) . '">'
                . htmlspecialchars( ucfirst( $status ) ) . '</span>';
            $rname = $names[ (int)$row->inv_respondent_id ] ?? '(removed)';
            $when = htmlspecialchars(
                $this->getLanguage()->userDate( $row->inv_created, $this->getUser() )
            );
            $del = '<form method="post" action="' . $self . '" class="sr-del">'
                . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
                . '<input type="hidden" name="pcp_action" value="delete_invite">'
                . '<input type="hidden" name="pcp_invite_id" value="' . (int)$row->inv_id . '">'
                . '<button type="submit" class="lo-copy">Delete</button></form>';
            $h .= '<div class="sent-row"><span class="sr-resp serif">'
                . htmlspecialchars( $rname ) . '</span>' . $pill
                . '<span class="sr-when">' . $when . '</span>' . $del . '</div>';
        }
        if ( !$any ) {
            $h .= '<p class="block-copy">Nothing sent yet.</p>';
        }
        $h .= '</div>';
        return $h;
    }
}
