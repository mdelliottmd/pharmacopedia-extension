<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Special:Perspective/<token> - the public, unauthenticated invitee page
 * of the Perspective subsystem.
 *
 * Gate 1: the opaque invite token in the URL is the only thing that
 * admits a contribution. The URL carries nothing else; the page shows
 * only the owner-chosen display name (pvi_display_name).
 *
 * Gate 2: a submission is recorded with psp_consent = 0, private to the
 * owner until the owner consents.
 *
 * Anti-abuse on POST, cheapest first (server-claude, spec section 9):
 * resolve token, per-IP pingLimiter, per-token APCu velocity counter,
 * Turnstile verify, then record. No edit token: the invitee has no
 * session, so the invite token plus Turnstile is the gate.
 *
 * See perspective_subsystem_spec.md.
 *
 * WIRING (at install): register in extension.json SpecialPages as
 * "Perspective" => SpecialPerspective::class. A small ResourceLoader
 * module to show the live slider value is a follow-up; the range inputs
 * are functional without it.
 */
class SpecialPerspective extends SpecialPage {

    /** Generic invitee intro (home-claude copy); {NAME} = the display name. */
    private const INTRO =
        "{NAME} has asked for your perspective.\n\n"
        . "You have been invited to answer a short set of questions about {NAME}. You do not "
        . "need an account and there is nothing to sign up for: answer the questions on this "
        . "page and submit them.\n\n"
        . "Please answer based on what you have actually noticed about {NAME}, as honestly and "
        . "as accurately as you can. There are no right or wrong answers, and if a question "
        . "asks about something you genuinely cannot judge, you can mark it rather than guess.";

    /** Generic consent notice (home-claude copy); {NAME} = the display name. */
    private const CONSENT =
        "Your answers are not anonymous. {NAME} invited you, and {NAME} will see your "
        . "responses. They are not shown to anyone else unless {NAME} chooses to share them. "
        . "Please continue only if you are comfortable with {NAME} seeing your answers.";

    /** Relationship options for the giver-label picker (psp_giver_label). */
    private const RELATIONSHIPS = [
        'partner'   => 'Partner or spouse',
        'parent'    => 'Parent',
        'sibling'   => 'Sibling',
        'child'     => 'Adult child',
        'friend'    => 'Friend',
        'colleague' => 'Colleague',
        'clinician' => 'Clinician',
        'other'     => 'Other',
    ];

    public function __construct() {
        parent::__construct( 'Perspective' );
    }

    public function doesWrites() {
        return true;
    }

    /** Reached only by an invite link; never listed in Special:SpecialPages. */
    public function isListed() {
        return false;
    }

    protected function getGroupName() {
        return 'other';
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );
        $out->addModules( [ 'ext.pharmacopedia.perspective' ] );

        // No page-scoped CSP is set here. The wiki carries a site-wide
        // Content-Security-Policy at the Apache level (security-headers.conf),
        // and Apache's "Header always set" overrides any CSP a PHP page
        // emits, so a header set here would never reach the client. The
        // site-wide policy already covers this endpoint, including the
        // challenges.cloudflare.com allowlist Turnstile needs; clickjacking
        // of the write form is covered by site-wide X-Frame-Options:
        // SAMEORIGIN. (server-claude, step-7 review.)

        $token = trim( (string)$par );
        $store = new PerspectiveStore();
        $invite = $store->resolveToken( $token );
        $handler = $invite
            ? PerspectiveRegistry::handler( (string)$invite->pvi_perspective_type )
            : null;
        if ( !$invite || !$handler ) {
            $this->renderInvalid( $out );
            return;
        }

        $request = $this->getRequest();
        if ( $request->wasPosted() ) {
            $this->handleSubmit( $out, $request, $store, $invite, $handler );
            return;
        }
        $this->renderForm( $out, $invite, $handler );
    }

    /** Neutral dead-end. Leaks nothing about the owner or the token. */
    private function renderInvalid( $out ) {
        $out->setPageTitle( 'Invitation link' );
        $out->addHTML( '<div class="pcp-perspective-page pcp-perspective-invalid">'
            . '<p>This invitation link is not valid. It may have been withdrawn, or it may '
            . 'already have been used. If you think this is a mistake, ask the person who sent '
            . 'it to you for a fresh link.</p></div>' );
    }

    private function renderForm( $out, \stdClass $invite, PerspectiveTypeHandler $handler ) {
        $name = htmlspecialchars( (string)$invite->pvi_display_name );
        $out->setPageTitle( 'Share your perspective' );

        $h = '<div class="pcp-perspective-page">';

        // B1, generic invitee intro.
        foreach ( explode( "\n\n", self::INTRO ) as $para ) {
            $h .= '<p class="pcp-perspective-intro">'
                . str_replace( '{NAME}', $name, htmlspecialchars( $para ) ) . '</p>';
        }
        // B2, generic consent notice.
        $h .= '<p class="pcp-perspective-consent">'
            . str_replace( '{NAME}', $name, htmlspecialchars( self::CONSENT ) ) . '</p>';

        $action = $this->getPageTitle( (string)$invite->pvi_token )->getLocalURL();
        $h .= '<form method="post" action="' . htmlspecialchars( $action ) . '" '
            . 'class="pcp-perspective-form-wrap">';

        // Relationship picker -> psp_giver_label (optional).
        $h .= '<div class="pcp-perspective-rel">';
        $h .= '<label for="pcp-perspective-rel">Your relationship to this person (optional)</label> ';
        $h .= '<select id="pcp-perspective-rel" name="giver_label">';
        $h .= '<option value="">Prefer not to say</option>';
        foreach ( self::RELATIONSHIPS as $val => $label ) {
            $h .= '<option value="' . htmlspecialchars( $val ) . '">'
                . htmlspecialchars( $label ) . '</option>';
        }
        $h .= '</select></div>';

        // Type-specific content: the handler renders its descriptor,
        // answering instruction, and the items.
        $h .= $handler->renderForm( $invite );

        // Turnstile.
        $h .= $this->captchaHtml( $out );

        $h .= '<div class="pcp-perspective-actions">'
            . '<button type="submit" class="pcp-btn pcp-btn-primary">Submit your answers</button>'
            . '</div>';
        $h .= '</form></div>';
        $out->addHTML( $h );
    }

    private function handleSubmit(
        $out, $request, PerspectiveStore $store, \stdClass $invite, PerspectiveTypeHandler $handler
    ) {
        // Per-IP / per-subnet rate limit.
        if ( $this->getUser()->pingLimiter( 'pharmacopedia-perspective-submit' ) ) {
            $this->renderRejected( $out, 'Too many submissions from your connection just now. '
                . 'Please wait a little and try again.' );
            return;
        }
        // Per-token velocity cap: contains a single leaked link.
        $cache = MediaWikiServices::getInstance()->getObjectCacheFactory()
            ->getLocalClusterInstance();
        $ckey = $cache->makeKey( 'pcp-perspective-token', (string)$invite->pvi_id );
        if ( (int)$cache->incrWithInit( $ckey, 3600, 1 ) > 30 ) {
            $this->renderRejected( $out, 'This invitation link has received too many '
                . 'submissions in a short time. Please try again later.' );
            return;
        }
        // Turnstile. Fail closed.
        if ( !$this->verifyCaptcha( $request ) ) {
            $this->renderRejected( $out, 'The check that confirms you are a person did not '
                . 'pass. Please go back and try again.' );
            return;
        }
        // Parse, then record. recordPerspective stores psp_consent = 0.
        $payload   = $handler->parseSubmission( $request );
        $validity  = $handler->validity( $payload );
        $giverLabel = trim( (string)$request->getVal( 'giver_label', '' ) );
        $user = $this->getUser();
        $store->recordPerspective(
            $invite,
            $user->isRegistered() ? (int)$user->getId() : null,
            $giverLabel !== '' ? $giverLabel : null,
            $payload,
            $validity,
            $request->getIP()
        );
        $this->renderThankYou( $out, $invite );
    }

    private function renderThankYou( $out, \stdClass $invite ) {
        $name = htmlspecialchars( (string)$invite->pvi_display_name );
        $out->setPageTitle( 'Thank you' );
        $out->addHTML( '<div class="pcp-perspective-page pcp-perspective-thanks">'
            . '<p>Thank you. Your answers about ' . $name . ' have been sent to them. You do '
            . 'not need to do anything else, and you can close this page.</p></div>' );
    }

    private function renderRejected( $out, string $why ) {
        $out->setPageTitle( 'Share your perspective' );
        $out->addHTML( '<div class="pcp-perspective-page pcp-perspective-rejected">'
            . '<p>' . htmlspecialchars( $why ) . '</p></div>' );
    }

    /**
     * Turnstile widget HTML. server-claude: ConfirmEdit getFormInformation.
     * If ConfirmEdit is unavailable, returns nothing and verifyCaptcha
     * then fails closed.
     */
    private function captchaHtml( $out ): string {
        $cls = '\\MediaWiki\\Extension\\ConfirmEdit\\Hooks';
        if ( !class_exists( $cls ) ) {
            return '';
        }
        $captcha = $cls::getInstance();
        $info = $captcha->getFormInformation( 1, $out );
        // getFormInformation does NOT add the Cloudflare api.js loader
        // itself. Without its head items the Turnstile widget never
        // renders, every token is empty, and verifyCaptcha() then
        // fail-closes every submission (server-claude, step-7 review).
        foreach ( $info['headitems'] ?? [] as $n => $item ) {
            $out->addHeadItem( "pcp-turnstile-$n", $item );
        }
        return '<div class="pcp-perspective-captcha">'
            . ( $info['html'] ?? '' ) . '</div>';
    }

    /**
     * Verify Turnstile. Fail closed: if ConfirmEdit is unavailable, or
     * verification does not pass, the submission is blocked.
     */
    private function verifyCaptcha( $request ): bool {
        $cls = '\\MediaWiki\\Extension\\ConfirmEdit\\Hooks';
        if ( !class_exists( $cls ) ) {
            return false;
        }
        $captcha = $cls::getInstance();
        return (bool)$captcha->passCaptchaLimitedFromRequest( $request, $this->getUser() );
    }
}
