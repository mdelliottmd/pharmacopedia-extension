<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;

/**
 * Special:MyPerspectives - the owner-facing side of the Perspective
 * subsystem: generate invites, and review perspectives given on your
 * objects (the consent inbox, gate 2).
 *
 * Two sections:
 *   Invite - generate an invite link (v1: an AMAAS-OR observer rating
 *            of yourself), and copy or revoke existing links.
 *   Inbox  - perspectives submitted on your objects; per item, consent
 *            to publish, withdraw consent, or delete. Nothing is public
 *            until you consent.
 *
 * The interaction design of these surfaces is designer-claude's; this
 * page emits semantic classes for that styling. The consent and delete
 * confirm-steps designer-claude specified are the UI layer's job
 * (interface-claude); the backend forms here are plain.
 *
 * See perspective_subsystem_spec.md sections 5 and 7.
 *
 * WIRING (at install): register in extension.json SpecialPages as
 * "MyPerspectives" => SpecialMyPerspectives::class.
 */
class SpecialMyPerspectives extends SpecialPage {

    public function __construct() {
        parent::__construct( 'MyPerspectives' );
    }

    public function doesWrites() {
        return true;
    }

    protected function getGroupName() {
        return 'users';
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );
        $out->addModules( [ 'ext.pharmacopedia.perspective' ] );
        $user = $this->getUser();
        $out->setPageTitle( 'My perspectives' );

        if ( !$user->isRegistered() ) {
            $out->addWikiTextAsInterface(
                'You must be logged in to generate invites and review perspectives.'
            );
            return;
        }

        $store = new PerspectiveStore();
        $profile = ( new UserProfileStore() )->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;
        $request = $this->getRequest();

        // POST: act, then redirect (post-redirect-get) so a refresh does not resubmit.
        if ( $request->wasPosted() ) {
            if ( $user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
                $this->handlePost( $request, $store, $profileId, (int)$user->getId() );
            }
            $out->redirect( $this->getPageTitle()->getLocalURL() );
            return;
        }

        $h  = '<div class="pcp-perspectives-page">';
        $h .= $this->renderInviteSection( $store, $profileId, $user );
        $h .= $this->renderInboxSection( $store, $profileId, $user );
        $h .= '</div>';
        $out->addHTML( $h );
    }

    private function handlePost( $request, PerspectiveStore $store, int $profileId, int $userId ) {
        switch ( (string)$request->getVal( 'do', '' ) ) {
            case 'invite':
                $name = trim( (string)$request->getVal( 'display_name', '' ) );
                if ( $name !== '' ) {
                    // v1: the only registered object type / perspective type.
                    $store->mintInvite( $profileId, 'profile', (string)$profileId,
                        'amaas_or', $name, null, $userId );
                }
                break;
            case 'revoke':
                $store->revokeInvite( (int)$request->getVal( 'invite_id', 0 ), $profileId );
                break;
            case 'consent':
                $store->consent( (int)$request->getVal( 'psp_id', 0 ), $profileId );
                break;
            case 'unconsent':
                $store->unconsent( (int)$request->getVal( 'psp_id', 0 ), $profileId );
                break;
            case 'delete':
                $store->deletePerspective( (int)$request->getVal( 'psp_id', 0 ), $profileId );
                break;
        }
    }

    private function renderInviteSection( PerspectiveStore $store, int $profileId, $user ): string {
        $token  = htmlspecialchars( $user->getEditToken() );
        $action = htmlspecialchars( $this->getPageTitle()->getLocalURL() );

        $h  = '<section class="pcp-perspectives-invite">';
        $h .= '<h2>Invite an observer</h2>';
        $h .= '<p>Generate a private link and send it to someone who knows you well, a '
            . 'partner, a parent, a close friend. They answer the AMAAS-OR observer '
            . 'questionnaire about you, with no account needed. Their answers come back to '
            . 'you privately; nothing is shown to anyone else unless you choose to share it.</p>';

        $h .= '<form method="post" action="' . $action . '" class="pcp-perspectives-invite-form">';
        $h .= '<input type="hidden" name="wpEditToken" value="' . $token . '">';
        $h .= '<input type="hidden" name="do" value="invite">';
        $h .= '<label for="pcp-pv-name">What should the observer call you? A first name or '
            . 'nickname; this is the only thing about you they will see.</label> ';
        $h .= '<input type="text" id="pcp-pv-name" name="display_name" maxlength="128" required> ';
        $h .= '<button type="submit" class="pcp-btn pcp-btn-primary">Create invite link</button>';
        $h .= '</form>';

        $active = [];
        foreach ( $store->listInvitesForOwner( $profileId ) as $inv ) {
            if ( (string)$inv->pvi_status === 'active' ) {
                $active[] = $inv;
            }
        }
        if ( $active ) {
            $h .= '<h3>Your active invite links</h3><ul class="pcp-perspectives-invite-list">';
            foreach ( $active as $inv ) {
                $url = SpecialPage::getTitleFor( 'Perspective', (string)$inv->pvi_token )
                    ->getFullURL();
                $uses = (int)$inv->pvi_uses;
                $h .= '<li><code class="pcp-perspectives-link">' . htmlspecialchars( $url )
                    . '</code> <span class="pcp-perspectives-link-meta">shown as &ldquo;'
                    . htmlspecialchars( (string)$inv->pvi_display_name ) . '&rdquo;, '
                    . $uses . ' response' . ( $uses === 1 ? '' : 's' ) . ' so far</span> ';
                $h .= '<form method="post" action="' . $action
                    . '" class="pcp-perspectives-inline-form">'
                    . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
                    . '<input type="hidden" name="do" value="revoke">'
                    . '<input type="hidden" name="invite_id" value="' . (int)$inv->pvi_id . '">'
                    . '<button type="submit" class="pcp-btn">Revoke</button></form></li>';
            }
            $h .= '</ul>';
        }
        $h .= '</section>';
        return $h;
    }

    private function renderInboxSection( PerspectiveStore $store, int $profileId, $user ): string {
        $token  = htmlspecialchars( $user->getEditToken() );
        $action = htmlspecialchars( $this->getPageTitle()->getLocalURL() );

        $h  = '<section class="pcp-perspectives-inbox">';
        $h .= '<h2>Perspectives shared with you</h2>';

        $rows = $store->listForOwner( $profileId );
        if ( !$rows ) {
            $h .= '<p class="pcp-perspectives-empty">No perspectives yet. When someone you '
                . 'invited submits their answers, they appear here for you to review.</p></section>';
            return $h;
        }

        foreach ( $rows as $p ) {
            $handler   = PerspectiveRegistry::handler( (string)$p->psp_perspective_type );
            $consented = ( (int)$p->psp_consent === 1 );
            $h .= '<div class="pcp-perspective-card" data-psp-id="' . (int)$p->psp_id . '"'
                . ' data-consent="' . ( $consented ? '1' : '0' ) . '">';

            $label = trim( (string)( $p->psp_giver_label ?? '' ) );
            $sub   = (string)$p->psp_submitted;
            $date  = strlen( $sub ) >= 8
                ? substr( $sub, 0, 4 ) . '-' . substr( $sub, 4, 2 ) . '-' . substr( $sub, 6, 2 )
                : '';
            $h .= '<div class="pcp-perspective-card-head">';
            $h .= '<strong>' . htmlspecialchars( $label !== '' ? $label : 'Unlabelled' ) . '</strong>';
            $h .= ' <span class="pcp-perspective-card-who">'
                . ( $p->psp_giver_user_id !== null ? 'registered user' : 'non-user' ) . '</span>';
            if ( $date !== '' ) {
                $h .= ' <span class="pcp-perspective-card-date">' . htmlspecialchars( $date )
                    . '</span>';
            }
            $h .= '</div>';

            $h .= $this->validityLine( trim( (string)( $p->psp_validity ?? '' ) ) );

            if ( $handler ) {
                $h .= '<div class="pcp-perspective-card-summary">'
                    . $handler->summarize( $p ) . '</div>';
            }

            $h .= '<div class="pcp-perspective-card-actions">';
            $h .= '<span class="pcp-perspective-card-state">'
                . ( $consented ? 'Shared: you consented to publish this.'
                              : 'Private to you.' ) . '</span> ';
            if ( !$consented ) {
                // Per designer-claude: publish is never a primary button; the three
                // actions carry equal visual weight. The confirm step is the UI layer's.
                $h .= $this->actionForm( $action, $token, 'consent', (int)$p->psp_id,
                    'Consent to publish', '' );
            } else {
                $h .= $this->actionForm( $action, $token, 'unconsent', (int)$p->psp_id,
                    'Make private again', '' );
            }
            $h .= $this->actionForm( $action, $token, 'delete', (int)$p->psp_id, 'Delete', '' );
            $h .= '</div>';

            $h .= '</div>';
        }
        $h .= '</section>';
        return $h;
    }

    private function actionForm(
        string $action, string $token, string $do, int $pspId, string $label, string $btnClass
    ): string {
        $cls = 'pcp-btn' . ( $btnClass !== '' ? ' ' . $btnClass : '' );
        return '<form method="post" action="' . $action
            . '" class="pcp-perspectives-inline-form">'
            . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
            . '<input type="hidden" name="do" value="' . htmlspecialchars( $do ) . '">'
            . '<input type="hidden" name="psp_id" value="' . $pspId . '">'
            . '<button type="submit" class="' . $cls . '">' . htmlspecialchars( $label )
            . '</button></form>';
    }

    /**
     * Map a perspective's validity code to a three-state inbox line.
     * Pass / flagged / not-assessed stay distinct (designer-claude); the
     * CSS class lets each be styled. An empty code renders nothing.
     */
    private function validityLine( string $code ): string {
        switch ( $code ) {
            case 'none':
                return '<p class="pcp-perspective-card-validity pcp-validity-pass">'
                    . 'Response-validity check passed.</p>';
            case 'caution':
                return '<p class="pcp-perspective-card-validity pcp-validity-flagged">'
                    . 'One response-validity check was unusual; weigh this rating gently.</p>';
            case 'invalid':
                return '<p class="pcp-perspective-card-validity pcp-validity-flagged">'
                    . 'The response-validity checks suggest this may not be a careful or '
                    . 'literal account; weigh it with strong caution.</p>';
            case 'not_assessed':
                return '<p class="pcp-perspective-card-validity pcp-validity-notassessed">'
                    . 'Response validity was not assessed: the observer marked the validity '
                    . 'items "Not sure".</p>';
            default:
                return '';
        }
    }
}
