<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\MediaWikiServices;

class SpecialProviderApplications extends SpecialPage {
    public function __construct() {
        parent::__construct( 'ProviderApplications', 'pharmacopedia-verify-review' );
    }

    public function execute( $subPage ) {
        $this->setHeaders();
        $this->checkPermissions();
        $out = $this->getOutput();
        $out->setPageTitle( 'Provider applications' );

        $store = new ProviderAppStore();
        $request = $this->getRequest();

        // Handle approve/reject action
        if ( $request->wasPosted() && $request->getCheck( 'app_id' ) ) {
            $appId = (int)$request->getVal( 'app_id' );
            $action = $request->getVal( 'action_decision' );
            $notes = trim( $request->getText( 'admin_notes' ) );
            $reviewer = $this->getUser();

            if ( !$this->getUser()->matchEditToken( $request->getVal( 'token' ) ) ) {
                $out->addWikiTextAsContent( "''Invalid CSRF token.''" );
                return;
            }

            $status = $action === 'approve'
                ? ProviderAppStore::STATUS_APPROVED
                : ProviderAppStore::STATUS_REJECTED;
            $ok = $store->decide( $appId, $reviewer->getId(), $status, $notes );
            $out->addWikiTextAsContent( $ok
                ? "''Decision recorded. Application "
                  . ( $status === ProviderAppStore::STATUS_APPROVED ? 'approved' : 'rejected' )
                  . " and documents deleted.''"
                : "''Could not process decision (application may have already been reviewed).''"
            );
        }

        // Subpage = specific app review
        if ( $subPage && is_numeric( $subPage ) ) {
            $this->renderAppDetails( (int)$subPage );
            return;
        }

        // Default: list pending
        $pending = $store->listPending();
        if ( !$pending ) {
            $out->addWikiTextAsContent( "''No pending applications.''" );
            return;
        }
        $out->addWikiTextAsContent( "== Pending applications ==" );
        $userFactory = MediaWikiServices::getInstance()->getUserFactory();
        $rows = '';
        foreach ( $pending as $app ) {
            $username = $app->user_name ?: ( '(user #' . $app->pa_user_id . ')' );
            $submitted = wfTimestamp( TS_RFC2822, $app->pa_submitted );
            $rows .= "|-\n| [[User:$username|$username]] || " . htmlspecialchars( $app->pa_profession ) . " || " . htmlspecialchars( $app->pa_jurisdiction ) . " || $submitted || [[Special:ProviderApplications/$app->pa_id|Review]]\n";
        }
        $out->addWikiTextAsContent(
            "{| class=\"wikitable\" style=\"width:100%;\"\n" .
            "! User !! Profession !! Jurisdiction !! Submitted !! Action\n" .
            $rows .
            "|}"
        );
    }

    private function renderAppDetails( $appId ) {
        $out = $this->getOutput();
        $store = new ProviderAppStore();
        $app = $store->getById( $appId );
        if ( !$app ) {
            $out->addWikiTextAsContent( "''Application not found.''" );
            return;
        }

        $userFactory = MediaWikiServices::getInstance()->getUserFactory();
        $applicant = $userFactory->newFromId( (int)$app->pa_user_id );
        $username = $applicant ? $applicant->getName() : '(unknown)';
        $statusText = [ 0 => 'Pending', 1 => 'Approved', 2 => 'Rejected' ][ (int)$app->pa_status ] ?? 'Unknown';

        $out->addWikiTextAsContent( "== Application #$appId ==" );
        $out->addWikiTextAsContent(
            "; Applicant\n: [[User:$username|$username]]\n" .
            "; Status\n: $statusText\n" .
            "; Submitted\n: " . htmlspecialchars( wfTimestamp( TS_RFC2822, $app->pa_submitted ) ) . "\n" .
            "; Profession\n: " . htmlspecialchars( $app->pa_profession ) . "\n" .
            "; Specialty\n: " . htmlspecialchars( $app->pa_specialty ?: '—' ) . "\n" .
            "; Jurisdiction\n: " . htmlspecialchars( $app->pa_jurisdiction ) . "\n" .
            "; License number\n: " . htmlspecialchars( $app->pa_license_number ) . "\n" .
            "; Name on license / ID\n: " . htmlspecialchars( $app->pa_real_name ) . "\n" .
            "; Applicant notes\n: " . ( $app->pa_notes ? htmlspecialchars( $app->pa_notes ) : "'''(none)'''" ) . "\n"
        );

        $paths = json_decode( $app->pa_doc_paths ?? '[]', true ) ?: [];
        if ( $paths && (int)$app->pa_status === ProviderAppStore::STATUS_PENDING ) {
            $html = '<p><strong>Documents</strong></p><ul class="pcp-vp-docs">';
            foreach ( $paths as $i => $p ) {
                $name = basename( $p );
                $docTitle = \Title::makeTitle( NS_SPECIAL, "VerificationDoc/$appId/$name" );
                $url = $docTitle->getLocalURL();
                $isImage = preg_match( '/\.(jpe?g|png|gif|webp)$/i', $name );
                $html .= '<li>';
                if ( $isImage ) {
                    $html .= '<a href="' . htmlspecialchars( $url ) . '" target="_blank">' .
                        '<img src="' . htmlspecialchars( $url ) . '" alt="' . htmlspecialchars( $name ) . '" ' .
                        'style="max-width:240px; max-height:240px; border:1px solid #888; border-radius:4px;"></a><br>';
                }
                $html .= '<a href="' . htmlspecialchars( $url ) . '" target="_blank">Document ' . ( $i + 1 ) . '</a> ' .
                    '<small>(' . htmlspecialchars( $name ) . ')</small>';
                $html .= '</li>';
            }
            $html .= '</ul>';
            $out->addHTML( $html );
        } elseif ( (int)$app->pa_status !== ProviderAppStore::STATUS_PENDING ) {
            $out->addWikiTextAsContent( "''Documents have been deleted (decision made).''" );
        }

        if ( (int)$app->pa_status === ProviderAppStore::STATUS_PENDING ) {
            $token = htmlspecialchars( $this->getUser()->getEditToken() );
            $formAction = htmlspecialchars( $this->getPageTitle()->getLocalURL() );
            $html = '<form method="post" action="' . $formAction . '" style="margin-top:1em; padding:1em; border:1px solid #ccc; border-radius:6px;">';
            $html .= '<input type="hidden" name="app_id" value="' . $appId . '">';
            $html .= '<input type="hidden" name="token" value="' . $token . '">';
            $html .= '<label>Admin notes (visible to applicant if rejected):<br><textarea name="admin_notes" rows="3" cols="60"></textarea></label><br><br>';
            $html .= '<button type="submit" name="action_decision" value="approve" style="background:#16a34a; color:#fff; padding:6px 14px; border:none; border-radius:4px; margin-right:0.5em;">Approve</button>';
            $html .= '<button type="submit" name="action_decision" value="reject" style="background:#dc2626; color:#fff; padding:6px 14px; border:none; border-radius:4px;">Reject</button>';
            $html .= '</form>';
            $out->addHTML( $html );
        } else {
            $out->addWikiTextAsContent(
                "== Decision ==\n" .
                "Reviewed: " . htmlspecialchars( wfTimestamp( TS_RFC2822, $app->pa_reviewed ) ) . "\n\n" .
                "Admin notes: " . ( $app->pa_admin_notes ? htmlspecialchars( $app->pa_admin_notes ) : '(none)' )
            );
        }
    }

    protected function getGroupName() { return 'users'; }
}
