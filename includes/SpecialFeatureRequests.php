<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;

class SpecialFeatureRequests extends SpecialPage {

    public function __construct() {
        // Requires 'pharmacopedia-fr-submit'. Logged-out + non-'user' members
        // get the standard "permission denied" page.
        parent::__construct( 'FeatureRequests', 'pharmacopedia-fr-submit' );
    }

    public function execute( $par ) {
        $this->setHeaders();
        $this->checkPermissions();

        // Attachment download bypasses normal HTML rendering.
        if ( $this->getRequest()->getVal( 'fr_dl' ) ) {
            if ( $this->maybeServeDownload() ) return;
        }

        $out = $this->getOutput();
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );
        $out->addModules( [ 'ext.pharmacopedia' ] );

        $par = trim( (string)$par );

        if ( $par === '' ) {
            $this->renderListView( false );
            return;
        }
        if ( $par === 'history' ) {
            $this->renderListView( true );
            return;
        }
        if ( $par === 'new' ) {
            $this->renderSubmitForm();
            return;
        }
        if ( preg_match( '/^(\d+)$/', $par, $m ) ) {
            $this->renderDetailView( (int)$m[1] );
            return;
        }
        if ( preg_match( '/^(\d+)\/edit$/', $par, $m ) ) {
            $this->renderEditForm( (int)$m[1] );
            return;
        }
        $out->setPageTitle( 'Feature Requests' );
        $out->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ], 'Unknown subpage.' ) );
    }

    // ===== LIST + HISTORY =====

    private function renderListView( bool $historyOnly ) {
        $out = $this->getOutput();
        $req = $this->getRequest();
        $user = $this->getUser();
        $store = new FeatureRequestStore();

        $filter = $req->getVal( 'filter', $historyOnly ? 'resolved' : 'open' );
        $out->setPageTitle( $historyOnly ? 'Feature requests, history' : 'Feature Requests' );

        $filters = [];
        if ( $historyOnly ) {
            $filters['onlyResolved'] = true;
        } else {
            if ( $filter === 'open' ) {
                $filters['includeResolved'] = false;
            } elseif ( $filter === 'mine' ) {
                $filters['userId'] = $user->getId();
            } elseif ( $filter === 'resolved' ) {
                $filters['onlyResolved'] = true;
            }
        }
        // Oldest first: queue management default
        $filters['orderBy'] = 'fr_created ASC';

        $rows = $store->listRequests( $filters, 500 );

        $h = '<div class="pcp-fr">';
        // Top bar
        $h .= '<div class="pcp-fr-topbar">';
        if ( !$historyOnly ) {
            $h .= '<div class="pcp-fr-filters" role="tablist">';
            foreach ( [
                'open'     => 'Open queue',
                'mine'     => 'My requests',
                'all'      => 'All open + resolved',
            ] as $k => $label ) {
                $isActive = ( $filter === $k );
                $url = $this->getPageTitle()->getLocalURL( [ 'filter' => $k ] );
                $h .= '<a href="' . htmlspecialchars( $url ) . '" class="pcp-fr-filter' . ( $isActive ? ' is-active' : '' ) . '">'
                    . htmlspecialchars( $label ) . '</a>';
            }
            $h .= '</div>';
        } else {
            $h .= '<div class="pcp-fr-filters"><span class="pcp-fr-filter is-active">History (resolved)</span></div>';
        }
        $h .= '<a href="' . htmlspecialchars( $this->getPageTitle( 'new' )->getLocalURL() ) . '" class="pcp-fr-newbtn">Submit a request</a>';
        $h .= '</div>';

        // Cards
        if ( !$rows ) {
            $h .= '<div class="pcp-fr-empty">';
            $h .= '<p>No feature requests match this filter yet.</p>';
            if ( !$historyOnly ) {
                $h .= '<p><a class="pcp-fr-newbtn" href="' . htmlspecialchars( $this->getPageTitle( 'new' )->getLocalURL() ) . '">Submit the first one</a></p>';
            }
            $h .= '</div>';
        } else {
            $h .= '<div class="pcp-fr-cardlist">';
            foreach ( $rows as $row ) {
                $h .= $this->renderCard( $row, $store, $user );
            }
            $h .= '</div>';
        }

        // Footer link
        $h .= '<div class="pcp-fr-footer">';
        if ( !$historyOnly ) {
            $h .= '<a class="pcp-fr-footlink" href="' . htmlspecialchars( $this->getPageTitle( 'history' )->getLocalURL() ) . '">Request history (resolved) &rarr;</a>';
        } else {
            $h .= '<a class="pcp-fr-footlink" href="' . htmlspecialchars( $this->getPageTitle()->getLocalURL() ) . '">&larr; Back to active queue</a>';
        }
        $h .= '</div>';

        $h .= '</div>';
        $out->addHTML( $h );
    }

    private function renderCard( \stdClass $row, FeatureRequestStore $store, $viewer ): string {
        $detailUrl = $this->getPageTitle( (string)$row->fr_id )->getLocalURL();
        $canSeeBody = $store->canViewBody( $row, $viewer );
        $isSysop = $viewer->isAllowed( 'pharmacopedia-fr-review' );
        $submitter = $store->submitterDisplay( $row, $viewer );

        $statusKey = (string)$row->fr_status;
        $statusLabel = FeatureRequestStore::STATUSES[ $statusKey ] ?? $statusKey;
        $priority = (int)$row->fr_priority;
        $attCount = $store->countAttachments( (int)$row->fr_id );

        $excerpt = '';
        if ( $canSeeBody ) {
            $bodyText = trim( (string)$row->fr_body );
            $excerpt = mb_substr( $bodyText, 0, 180 );
            if ( mb_strlen( $bodyText ) > 180 ) $excerpt .= '...';
        } else {
            $excerpt = '(Private to sysops)';
        }

        $h  = '<a class="pcp-fr-card pcp-fr-status-' . htmlspecialchars( $statusKey ) . '" href="' . htmlspecialchars( $detailUrl ) . '">';
        $h .= '<div class="pcp-fr-card-head">';
        $h .= '<span class="pcp-fr-card-id">#' . (int)$row->fr_id . '</span>';
        $h .= '<span class="pcp-fr-badge pcp-fr-badge-' . htmlspecialchars( $statusKey ) . '">' . htmlspecialchars( $statusLabel ) . '</span>';
        if ( $isSysop && $priority > 0 ) {
            $h .= '<span class="pcp-fr-prio pcp-fr-prio-' . $priority . '">' . htmlspecialchars( FeatureRequestStore::PRIORITIES[ $priority ] ) . '</span>';
        }
        if ( (int)$row->fr_content_vis === 1 ) {
            $h .= '<span class="pcp-fr-privacy-tag" title="Body visible only to submitter + sysops">private</span>';
        }
        $h .= '</div>';
        $h .= '<div class="pcp-fr-card-title">' . htmlspecialchars( (string)$row->fr_title ) . '</div>';
        $h .= '<div class="pcp-fr-card-body">' . htmlspecialchars( $excerpt ) . '</div>';
        $h .= '<div class="pcp-fr-card-foot">';
        $h .= '<span class="pcp-fr-card-submitter">' . htmlspecialchars( $submitter ) . '</span>';
        $h .= '<span class="pcp-fr-card-date">' . $this->relativeDate( (string)$row->fr_created ) . '</span>';
        if ( $attCount > 0 ) {
            $h .= '<span class="pcp-fr-card-att">' . $attCount . ' file' . ( $attCount === 1 ? '' : 's' ) . '</span>';
        }
        $h .= '</div>';
        $h .= '</a>';
        return $h;
    }

    // ===== SUBMIT =====

    private function renderSubmitForm() {
        $out = $this->getOutput();
        $req = $this->getRequest();
        $user = $this->getUser();
        $out->setPageTitle( 'Submit a feature request' );

        if ( $req->wasPosted() ) {
            $token = $req->getVal( 'token' );
            if ( !$user->matchEditToken( $token, 'pcp-fr-submit' ) ) {
                $out->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ], 'Session token invalid; please reload.' ) );
                return;
            }
            $title = trim( (string)$req->getVal( 'fr_title', '' ) );
            $body  = trim( (string)$req->getVal( 'fr_body', '' ) );
            $usernameVis = (int)$req->getVal( 'fr_username_vis', '1' );
            $priv  = $req->getVal( 'fr_content_vis' ) === '1';
            $errs = [];
            if ( mb_strlen( $title ) < 3 )  $errs[] = 'Title is too short (minimum 3 characters).';
            if ( mb_strlen( $title ) > 200 ) $errs[] = 'Title is too long (maximum 200 characters).';
            if ( mb_strlen( $body ) < 10 )   $errs[] = 'Body is too short (minimum 10 characters).';
            if ( $errs ) {
                $out->addHTML( '<div class="errorbox"><ul><li>' . implode( '</li><li>', array_map( 'htmlspecialchars', $errs ) ) . '</li></ul></div>' );
                $this->renderSubmitFormBody( $title, $body, $usernameVis, $priv );
                return;
            }
            $store = new FeatureRequestStore();
            $newId = $store->create( $user->getId(), $title, $body, $usernameVis, $priv );

            // Process attachments
            $errors = [];
            $files = $req->getUpload( 'fr_files' );
            // PHP normalizes multi-file uploads into a struct; we read $_FILES directly.
            if ( !empty( $_FILES['fr_files']['name'] ) && is_array( $_FILES['fr_files']['name'] ) ) {
                $existing = 0;
                foreach ( $_FILES['fr_files']['name'] as $i => $name ) {
                    if ( $name === '' ) continue;
                    if ( $existing >= FeatureRequestStore::ATTACHMENT_MAX_PER_REQ ) {
                        $errors[] = 'Maximum 10 attachments per request; remaining files ignored.';
                        break;
                    }
                    $err = (int)$_FILES['fr_files']['error'][ $i ];
                    if ( $err !== UPLOAD_ERR_OK ) {
                        $errors[] = htmlspecialchars( (string)$name ) . ': upload failed (code ' . $err . ').';
                        continue;
                    }
                    $tmp = (string)$_FILES['fr_files']['tmp_name'][ $i ];
                    $disp = (string)$_FILES['fr_files']['name'][ $i ];
                    $result = $this->handleUpload( $newId, $user->getId(), $tmp, $disp, $store );
                    if ( !$result['ok'] ) {
                        $errors[] = htmlspecialchars( $disp ) . ': ' . htmlspecialchars( $result['error'] );
                    } else {
                        $existing++;
                    }
                }
            }
            if ( $errors ) {
                $this->getRequest()->getSession()->set( 'pcp-fr-flash', [
                    'request_id' => $newId,
                    'errors'     => $errors,
                ] );
            }
            $out->redirect( $this->getPageTitle( (string)$newId )->getFullURL() );
            return;
        }

        $this->renderSubmitFormBody( '', '', 1, false );
    }

    /** Move + scan + record one uploaded file. */
    private function handleUpload( int $requestId, int $userId, string $tmpPath, string $displayName, FeatureRequestStore $store ): array {
        // First scan in temp location, only move if clean.
        $scan = AttachmentScanner::scanFile( $tmpPath );
        if ( $scan['status'] === AttachmentScanner::STATUS_INFECTED ) {
            @unlink( $tmpPath );
            return [ 'ok' => false, 'error' => 'rejected by virus scanner (' . $scan['message'] . ')' ];
        }
        if ( $scan['status'] === AttachmentScanner::STATUS_ERROR ) {
            @unlink( $tmpPath );
            return [ 'ok' => false, 'error' => 'virus scan failed; not stored' ];
        }
        $move = AttachmentStorage::moveUploaded( $tmpPath, $requestId, $displayName );
        if ( !$move['ok'] ) {
            return $move;
        }
        $store->addAttachment(
            $requestId, $userId,
            $displayName, $move['storage_name'],
            $move['mime'], $move['size'],
            AttachmentScanner::STATUS_CLEAN, $scan['message']
        );
        return [ 'ok' => true ];
    }

    private function renderSubmitFormBody( string $title, string $body, int $usernameVis, bool $priv ) {
        $out = $this->getOutput();
        $action = $this->getPageTitle( 'new' )->getLocalURL();
        $token = htmlspecialchars( $this->getUser()->getEditToken( 'pcp-fr-submit' ) );
        $maxBytes = FeatureRequestStore::ATTACHMENT_MAX_BYTES;
        $maxN     = FeatureRequestStore::ATTACHMENT_MAX_PER_REQ;
        $allowedExt = implode( ', ', AttachmentStorage::ALLOWED_EXTENSIONS );

        $h  = '<div class="pcp-fr pcp-fr-form-wrap">';
        $h .= '<form method="POST" action="' . htmlspecialchars( $action ) . '" enctype="multipart/form-data" class="pcp-fr-form">';
        $h .= '<input type="hidden" name="token" value="' . $token . '">';

        $h .= '<label class="pcp-fr-field">';
        $h .= '<span class="pcp-fr-label">Title <small>(short, descriptive; 200 chars max)</small></span>';
        $h .= '<input type="text" name="fr_title" required maxlength="200" value="' . htmlspecialchars( $title ) . '" class="pcp-fr-input">';
        $h .= '</label>';

        $h .= '<label class="pcp-fr-field">';
        $h .= '<span class="pcp-fr-label">Describe what you want and why</span>';
        $h .= '<textarea name="fr_body" required rows="10" class="pcp-fr-textarea">' . htmlspecialchars( $body ) . '</textarea>';
        $h .= '</label>';

        // Attachments
        $h .= '<label class="pcp-fr-field">';
        $h .= '<span class="pcp-fr-label">Attachments <small>(up to ' . $maxN . ' files, '
            . round( $maxBytes / 1024 / 1024 ) . ' MB each; ClamAV-scanned)</small></span>';
        $h .= '<input type="file" name="fr_files[]" multiple accept="' . htmlspecialchars( '.' . implode( ',.', AttachmentStorage::ALLOWED_EXTENSIONS ) ) . '" class="pcp-fr-fileinput">';
        $h .= '<small class="pcp-fr-help">Allowed: ' . htmlspecialchars( $allowedExt ) . '</small>';
        $h .= '</label>';

        // Privacy
        $h .= '<fieldset class="pcp-fr-privacy">';
        $h .= '<legend>Privacy</legend>';

        $h .= '<label class="pcp-fr-field">';
        $h .= '<span class="pcp-fr-label">Who is shown as the submitter?</span>';
        $h .= '<select name="fr_username_vis" class="pcp-fr-input">';
        foreach ( [
            1 => 'Use my profile default attribution',
            2 => 'Show my username',
            3 => 'Anonymous',
        ] as $v => $label ) {
            $sel = ( $usernameVis === $v ) ? ' selected' : '';
            $h .= '<option value="' . $v . '"' . $sel . '>' . htmlspecialchars( $label ) . '</option>';
        }
        $h .= '</select>';
        $h .= '<small class="pcp-fr-help">Sysops can always see who submitted, regardless of this setting.</small>';
        $h .= '</label>';

        $h .= '<div class="pcp-fr-radiogroup">';
        $h .= '<span class="pcp-fr-label">Who can read the body and attachments?</span>';
        $h .= '<label class="pcp-fr-radio">';
        $h .= '<input type="radio" name="fr_content_vis" value="0"' . ( !$priv ? ' checked' : '' ) . '>';
        $h .= '<span>Public. Any logged-in user with access to this page can read it.</span>';
        $h .= '</label>';
        $h .= '<label class="pcp-fr-radio">';
        $h .= '<input type="radio" name="fr_content_vis" value="1"' . ( $priv ? ' checked' : '' ) . '>';
        $h .= '<span>Sysops only. The title stays visible, but the body and attachments are hidden from other users.</span>';
        $h .= '</label>';
        $h .= '</div>';
        $h .= '</fieldset>';

        $h .= '<div class="pcp-fr-actions">';
        $h .= '<button type="submit" class="pcp-fr-newbtn">Submit request</button>';
        $h .= ' <a class="pcp-fr-cancel" href="' . htmlspecialchars( $this->getPageTitle()->getLocalURL() ) . '">Cancel</a>';
        $h .= '</div>';

        $h .= '</form>';
        $h .= '</div>';
        $out->addHTML( $h );
    }

    // ===== DETAIL =====

    private function renderDetailView( int $id ) {
        $out = $this->getOutput();
        $req = $this->getRequest();
        $user = $this->getUser();
        $store = new FeatureRequestStore();
        $row = $store->getById( $id );
        if ( !$row ) {
            $out->setPageTitle( 'Feature request not found' );
            $out->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ], 'No feature request with that ID.' ) );
            return;
        }

        // Session flash from submit/edit (attachment rejections etc.)
        $session = $this->getRequest()->getSession();
        $flash = $session->get( 'pcp-fr-flash' );
        if ( is_array( $flash ) && (int)( $flash['request_id'] ?? 0 ) === $id && !empty( $flash['errors'] ) ) {
            $h0  = '<div class="warningbox"><strong>Some attachments were rejected:</strong><ul>';
            foreach ( (array)$flash['errors'] as $e ) {
                $h0 .= '<li>' . $e . '</li>';
            }
            $h0 .= '</ul></div>';
            $out->addHTML( $h0 );
            $session->remove( 'pcp-fr-flash' );
        }

        // POST actions
        if ( $req->wasPosted() ) {
            $token = $req->getVal( 'token' );
            if ( !$user->matchEditToken( $token, 'pcp-fr-detail' ) ) {
                $out->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ], 'Session token invalid; please reload.' ) );
            } else {
                $action = $req->getVal( 'fr_action' );
                if ( $action === 'resolve' && $store->canResolve( $row, $user ) ) {
                    $store->updateStatus( $id, 'done', $user->getId() );
                } elseif ( $action === 'reopen' && $user->isAllowed( 'pharmacopedia-fr-review' ) ) {
                    $store->updateStatus( $id, 'acknowledged', $user->getId() );
                } elseif ( $action === 'delete' && ( (int)$row->fr_user_id === $user->getId() || $user->isAllowed( 'pharmacopedia-fr-review' ) ) ) {
                    $store->delete( $id );
                    $out->redirect( $this->getPageTitle()->getFullURL() );
                    return;
                } elseif ( $action === 'comment' ) {
                    $body = trim( (string)$req->getVal( 'fr_comment', '' ) );
                    if ( mb_strlen( $body ) >= 2 ) {
                        $isSysop = $user->isAllowed( 'pharmacopedia-fr-review' );
                        $store->addComment( $id, $user->getId(), $body, $isSysop );
                    }
                }
                $row = $store->getById( $id );
                if ( !$row ) {
                    $out->redirect( $this->getPageTitle()->getFullURL() );
                    return;
                }
            }
        }

        $out->setPageTitle( 'Feature request: ' . mb_substr( (string)$row->fr_title, 0, 80 ) );

        $canSeeBody  = $store->canViewBody( $row, $user );
        $canEdit     = $store->canEdit( $row, $user );
        $canResolve  = $store->canResolve( $row, $user );
        $isSubmitter = ( (int)$row->fr_user_id === $user->getId() );
        $isSysop     = $user->isAllowed( 'pharmacopedia-fr-review' );

        $statusKey   = (string)$row->fr_status;
        $statusLabel = FeatureRequestStore::STATUSES[ $statusKey ] ?? $statusKey;
        $priority    = (int)$row->fr_priority;

        $h  = '<div class="pcp-fr pcp-fr-detail">';
        $h .= '<a class="pcp-fr-back" href="' . htmlspecialchars( $this->getPageTitle()->getLocalURL() ) . '">&larr; Back to all requests</a>';
        $h .= '<div class="pcp-fr-detail-head">';
        $h .= '<span class="pcp-fr-card-id">#' . $id . '</span>';
        $h .= '<span class="pcp-fr-badge pcp-fr-badge-' . htmlspecialchars( $statusKey ) . '">' . htmlspecialchars( $statusLabel ) . '</span>';
        if ( $isSysop && $priority > 0 ) {
            $h .= '<span class="pcp-fr-prio pcp-fr-prio-' . $priority . '">' . htmlspecialchars( FeatureRequestStore::PRIORITIES[ $priority ] ) . '</span>';
        }
        if ( (int)$row->fr_content_vis === 1 ) {
            $h .= '<span class="pcp-fr-privacy-tag" title="Body visible only to submitter + sysops">Sysops-only</span>';
        }
        $h .= '</div>';
        $h .= '<h1 class="pcp-fr-detail-title">' . htmlspecialchars( (string)$row->fr_title ) . '</h1>';
        $h .= '<div class="pcp-fr-detail-meta">';
        $h .= 'Submitted by <strong>' . htmlspecialchars( $store->submitterDisplay( $row, $user ) ) . '</strong> ';
        $h .= '<span class="pcp-fr-card-date">' . $this->relativeDate( (string)$row->fr_created ) . '</span>';
        if ( $row->fr_updated && $row->fr_updated !== $row->fr_created ) {
            $h .= ' (last updated ' . $this->relativeDate( (string)$row->fr_updated ) . ')';
        }
        if ( in_array( $statusKey, FeatureRequestStore::TERMINAL_STATUSES, true ) && $row->fr_resolved_by ) {
            $resolvedBySubmitter = ( (int)$row->fr_resolved_by === (int)$row->fr_user_id );
            $h .= ' &middot; <em>Closed by ' . ( $resolvedBySubmitter ? 'submitter' : 'sysop' ) . '</em>';
        }
        $h .= '</div>';

        $h .= '<div class="pcp-fr-detail-body">';
        if ( $canSeeBody ) {
            $h .= nl2br( htmlspecialchars( (string)$row->fr_body ) );
        } else {
            $h .= '<em class="pcp-fr-private-msg">The body of this request is visible only to the submitter and sysops.</em>';
        }
        $h .= '</div>';

        // Attachments
        $atts = $store->listAttachments( $id );
        if ( $atts ) {
            $h .= '<div class="pcp-fr-attachments">';
            $h .= '<h2 class="pcp-fr-section-h2">Attachments</h2>';
            if ( $canSeeBody ) {
                $h .= '<ul class="pcp-fr-attlist">';
                foreach ( $atts as $a ) {
                    $url = SpecialPage::getTitleFor( 'FeatureRequests' )->getLocalURL( [] )
                         . '/' . $id;
                    $dlUrl = wfScript( 'index' ) . '?title=Special:FeatureRequests&fr_dl=' . (int)$a->fra_id;
                    $sizeMb = number_format( (int)$a->fra_size / 1024 / 1024, 2 );
                    $h .= '<li>';
                    $h .= '<a href="' . htmlspecialchars( $dlUrl ) . '">' . htmlspecialchars( (string)$a->fra_filename ) . '</a>';
                    $h .= ' <small class="pcp-fr-att-meta">(' . $sizeMb . ' MB, scanned clean)</small>';
                    $h .= '</li>';
                }
                $h .= '</ul>';
            } else {
                $h .= '<em>' . count( $atts ) . ' attachment(s); visible to submitter + sysops only.</em>';
            }
            $h .= '</div>';
        }

        // Action buttons
        $h .= '<div class="pcp-fr-detail-actions">';
        if ( $canEdit ) {
            $h .= '<a href="' . htmlspecialchars( $this->getPageTitle( $id . '/edit' )->getLocalURL() ) . '" class="pcp-fr-btn">Edit</a> ';
        }
        if ( $canResolve ) {
            $h .= $this->postButton( $id, 'resolve', $isSubmitter ? 'Close (I no longer need this)' : 'Mark as done' );
        }
        if ( in_array( $statusKey, FeatureRequestStore::TERMINAL_STATUSES, true ) && $isSysop ) {
            $h .= $this->postButton( $id, 'reopen', 'Reopen' );
        }
        if ( $isSubmitter || $isSysop ) {
            $h .= $this->postButton( $id, 'delete', 'Withdraw', 'pcp-fr-btn-danger', 'Permanently delete this request, including attachments and comments?' );
        }
        if ( $isSysop ) {
            $h .= ' <a class="pcp-fr-btn" href="' . htmlspecialchars( SpecialPage::getTitleFor( 'RequestReview' )->getLocalURL( [ 'id' => $id ] ) ) . '">Open in review console &rarr;</a>';
        }
        $h .= '</div>';

        // Comments
        $comments = $store->listComments( $id );
        $h .= '<div class="pcp-fr-comments">';
        $h .= '<h2 class="pcp-fr-section-h2">Discussion</h2>';
        if ( !$comments ) {
            $h .= '<p class="pcp-fr-comments-empty">No replies yet.</p>';
        } else {
            $h .= '<ul class="pcp-fr-commentlist">';
            $userFactory = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory();
            foreach ( $comments as $c ) {
                $cu = $userFactory->newFromId( (int)$c->frc_user_id );
                $cName = $cu ? $cu->getName() : 'Unknown';
                $sysopBadge = ( (int)$c->frc_is_sysop === 1 ) ? ' <span class="pcp-fr-sysop-badge">sysop</span>' : '';
                $h .= '<li class="pcp-fr-comment' . ( $c->frc_is_sysop ? ' is-sysop' : '' ) . '">';
                $h .= '<div class="pcp-fr-comment-head"><strong>' . htmlspecialchars( $cName ) . '</strong>' . $sysopBadge . ' <span class="pcp-fr-card-date">' . $this->relativeDate( (string)$c->frc_created ) . '</span></div>';
                $h .= '<div class="pcp-fr-comment-body">' . nl2br( htmlspecialchars( (string)$c->frc_body ) ) . '</div>';
                $h .= '</li>';
            }
            $h .= '</ul>';
        }

        // Comment form (anyone with access)
        $h .= '<form method="POST" action="' . htmlspecialchars( $this->getPageTitle( (string)$id )->getLocalURL() ) . '" class="pcp-fr-comment-form">';
        $h .= '<input type="hidden" name="token" value="' . htmlspecialchars( $user->getEditToken( 'pcp-fr-detail' ) ) . '">';
        $h .= '<input type="hidden" name="fr_action" value="comment">';
        $h .= '<textarea name="fr_comment" rows="3" placeholder="Reply..." class="pcp-fr-textarea" required minlength="2"></textarea>';
        $h .= '<div class="pcp-fr-actions"><button type="submit" class="pcp-fr-btn">Post reply</button></div>';
        $h .= '</form>';
        $h .= '</div>';

        $h .= '</div>';
        $out->addHTML( $h );
    }

    private function postButton( int $id, string $action, string $label, string $extraCls = '', string $confirm = '' ): string {
        $token = htmlspecialchars( $this->getUser()->getEditToken( 'pcp-fr-detail' ) );
        $url = $this->getPageTitle( (string)$id )->getLocalURL();
        $confirmAttr = $confirm ? ' class="js-pcp-confirm-delete" data-confirm="' . htmlspecialchars( $confirm ) . '"' : '';
        $h  = '<form method="POST" action="' . htmlspecialchars( $url ) . '" style="display:inline-block;"' . $confirmAttr . '>';
        $h .= '<input type="hidden" name="token" value="' . $token . '">';
        $h .= '<input type="hidden" name="fr_action" value="' . htmlspecialchars( $action ) . '">';
        $h .= '<button type="submit" class="pcp-fr-btn ' . htmlspecialchars( $extraCls ) . '">' . htmlspecialchars( $label ) . '</button>';
        $h .= '</form> ';
        return $h;
    }

    // ===== EDIT =====

    private function renderEditForm( int $id ) {
        $out = $this->getOutput();
        $req = $this->getRequest();
        $user = $this->getUser();
        $store = new FeatureRequestStore();
        $row = $store->getById( $id );
        if ( !$row ) {
            $out->setPageTitle( 'Feature request not found' );
            $out->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ], 'No feature request with that ID.' ) );
            return;
        }
        if ( !$store->canEdit( $row, $user ) ) {
            $out->setPageTitle( 'Permission denied' );
            $out->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ], 'You cannot edit this request.' ) );
            return;
        }

        if ( $req->wasPosted() ) {
            $token = $req->getVal( 'token' );
            if ( !$user->matchEditToken( $token, 'pcp-fr-edit' ) ) {
                $out->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ], 'Session token invalid; please reload.' ) );
                return;
            }
            // Delete attachment?
            $delAtt = (int)$req->getVal( 'delete_att', 0 );
            if ( $delAtt > 0 ) {
                $att = $store->getAttachment( $delAtt );
                if ( $att && (int)$att->fra_request_id === $id ) {
                    $store->deleteAttachment( $delAtt, true );
                    $out->redirect( $this->getPageTitle( $id . '/edit' )->getFullURL() );
                    return;
                }
            }
            $title = trim( (string)$req->getVal( 'fr_title', '' ) );
            $body  = trim( (string)$req->getVal( 'fr_body', '' ) );
            $usernameVis = (int)$req->getVal( 'fr_username_vis', '1' );
            $priv  = $req->getVal( 'fr_content_vis' ) === '1';
            $errs = [];
            if ( mb_strlen( $title ) < 3 )  $errs[] = 'Title too short.';
            if ( mb_strlen( $body ) < 10 )   $errs[] = 'Body too short.';
            if ( $errs ) {
                $out->addHTML( '<div class="errorbox"><ul><li>' . implode( '</li><li>', array_map( 'htmlspecialchars', $errs ) ) . '</li></ul></div>' );
            } else {
                $store->updateContent( $id, $title, $body, $usernameVis, $priv );

                // New attachments
                if ( !empty( $_FILES['fr_files']['name'] ) && is_array( $_FILES['fr_files']['name'] ) ) {
                    $existing = $store->countAttachments( $id );
                    foreach ( $_FILES['fr_files']['name'] as $i => $name ) {
                        if ( $name === '' ) continue;
                        if ( $existing >= FeatureRequestStore::ATTACHMENT_MAX_PER_REQ ) break;
                        $err = (int)$_FILES['fr_files']['error'][ $i ];
                        if ( $err !== UPLOAD_ERR_OK ) continue;
                        $tmp = (string)$_FILES['fr_files']['tmp_name'][ $i ];
                        $disp = (string)$_FILES['fr_files']['name'][ $i ];
                        $r = $this->handleUpload( $id, $user->getId(), $tmp, $disp, $store );
                        if ( $r['ok'] ) $existing++;
                    }
                }
                $out->redirect( $this->getPageTitle( (string)$id )->getFullURL() );
                return;
            }
        }

        $out->setPageTitle( 'Edit feature request' );
        $title = (string)$row->fr_title;
        $body  = (string)$row->fr_body;
        $usernameVis = (int)$row->fr_username_vis;
        $priv  = ( (int)$row->fr_content_vis === 1 );

        $action = $this->getPageTitle( $id . '/edit' )->getLocalURL();
        $token = htmlspecialchars( $user->getEditToken( 'pcp-fr-edit' ) );

        $h  = '<div class="pcp-fr pcp-fr-form-wrap">';
        $h .= '<form method="POST" action="' . htmlspecialchars( $action ) . '" enctype="multipart/form-data" class="pcp-fr-form">';
        $h .= '<input type="hidden" name="token" value="' . $token . '">';

        $h .= '<label class="pcp-fr-field"><span class="pcp-fr-label">Title</span>';
        $h .= '<input type="text" name="fr_title" required maxlength="200" value="' . htmlspecialchars( $title ) . '" class="pcp-fr-input"></label>';

        $h .= '<label class="pcp-fr-field"><span class="pcp-fr-label">Body</span>';
        $h .= '<textarea name="fr_body" required rows="10" class="pcp-fr-textarea">' . htmlspecialchars( $body ) . '</textarea></label>';

        // Existing attachments
        $existingAtt = $store->listAttachments( $id );
        if ( $existingAtt ) {
            $h .= '<div class="pcp-fr-field"><span class="pcp-fr-label">Existing attachments</span>';
            $h .= '<ul class="pcp-fr-attlist">';
            foreach ( $existingAtt as $a ) {
                $h .= '<li>' . htmlspecialchars( (string)$a->fra_filename )
                    . ' <button type="submit" name="delete_att" value="' . (int)$a->fra_id . '" class="pcp-fr-btn pcp-fr-btn-danger js-pcp-confirm-delete" data-pcp-confirm="Delete this attachment?">Delete</button></li>';
            }
            $h .= '</ul></div>';
        }

        $h .= '<label class="pcp-fr-field"><span class="pcp-fr-label">Add more attachments</span>';
        $h .= '<input type="file" name="fr_files[]" multiple accept="' . htmlspecialchars( '.' . implode( ',.', AttachmentStorage::ALLOWED_EXTENSIONS ) ) . '" class="pcp-fr-fileinput"></label>';

        $h .= '<fieldset class="pcp-fr-privacy"><legend>Privacy</legend>';
        $h .= '<label class="pcp-fr-field"><span class="pcp-fr-label">Submitter shown as</span>';
        $h .= '<select name="fr_username_vis" class="pcp-fr-input">';
        foreach ( [
            1 => 'Use my profile default attribution',
            2 => 'Show my username',
            3 => 'Anonymous',
        ] as $v => $label ) {
            $sel = ( $usernameVis === $v ) ? ' selected' : '';
            $h .= '<option value="' . $v . '"' . $sel . '>' . htmlspecialchars( $label ) . '</option>';
        }
        $h .= '</select></label>';
        $h .= '<div class="pcp-fr-radiogroup">';
        $h .= '<label class="pcp-fr-radio"><input type="radio" name="fr_content_vis" value="0"' . ( !$priv ? ' checked' : '' ) . '><span>Public</span></label>';
        $h .= '<label class="pcp-fr-radio"><input type="radio" name="fr_content_vis" value="1"' . ( $priv ? ' checked' : '' ) . '><span>Sysops only</span></label>';
        $h .= '</div></fieldset>';

        $h .= '<div class="pcp-fr-actions">';
        $h .= '<button type="submit" class="pcp-fr-newbtn">Save changes</button>';
        $h .= ' <a class="pcp-fr-cancel" href="' . htmlspecialchars( $this->getPageTitle( (string)$id )->getLocalURL() ) . '">Cancel</a>';
        $h .= '</div>';

        $h .= '</form></div>';
        $out->addHTML( $h );
    }

    // ===== ATTACHMENT DOWNLOAD =====

    /**
     * Called from the dispatcher when ?fr_dl=N is set. Streams the file if
     * the viewer has access. Kept in a dedicated method to bypass normal
     * HTML rendering.
     */
    public function maybeServeDownload(): bool {
        $req = $this->getRequest();
        $id = (int)$req->getVal( 'fr_dl', 0 );
        if ( $id <= 0 ) return false;
        $store = new FeatureRequestStore();
        $att = $store->getAttachment( $id );
        if ( !$att || (int)$att->fra_deleted === 1 || (int)$att->fra_scan_status !== AttachmentScanner::STATUS_CLEAN ) {
            $this->getOutput()->setStatusCode( 404 );
            $this->getOutput()->addHTML( 'Not found.' );
            return true;
        }
        $row = $store->getById( (int)$att->fra_request_id );
        if ( !$row ) {
            $this->getOutput()->setStatusCode( 404 );
            $this->getOutput()->addHTML( 'Not found.' );
            return true;
        }
        $viewer = $this->getUser();
        if ( !$store->canViewBody( $row, $viewer ) ) {
            $this->getOutput()->setStatusCode( 403 );
            $this->getOutput()->addHTML( 'Permission denied.' );
            return true;
        }
        $path = AttachmentStorage::pathFor( (int)$att->fra_request_id, (string)$att->fra_storage_name );
        if ( !$path || !is_file( $path ) ) {
            $this->getOutput()->setStatusCode( 404 );
            $this->getOutput()->addHTML( 'File missing.' );
            return true;
        }
        $this->getOutput()->disable();
        header( 'Content-Type: ' . (string)$att->fra_mime );
        header( 'Content-Length: ' . (int)$att->fra_size );
        header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', (string)$att->fra_filename ) . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $path );
        return true;
    }

    // ===== HELPERS =====

    private function relativeDate( string $ts ): string {
        $t = wfTimestamp( TS_UNIX, $ts );
        if ( !$t ) return '';
        $diff = time() - (int)$t;
        if ( $diff < 60 )         return 'just now';
        if ( $diff < 3600 )       return floor( $diff / 60 ) . ' min ago';
        if ( $diff < 86400 )      return floor( $diff / 3600 ) . ' hr ago';
        if ( $diff < 86400 * 14 ) return floor( $diff / 86400 ) . ' days ago';
        return date( 'Y-m-d', (int)$t );
    }

    protected function getGroupName() { return 'pharmacopedia'; }
}
