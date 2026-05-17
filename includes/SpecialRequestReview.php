<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;

class SpecialRequestReview extends SpecialPage {

    public function __construct() {
        parent::__construct( 'RequestReview', 'pharmacopedia-fr-review' );
    }

    public function execute( $par ) {
        $this->setHeaders();
        $this->checkPermissions();
        $out = $this->getOutput();
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );
        $out->addModules( [ 'ext.pharmacopedia' ] );
        $out->setPageTitle( 'Feature-request review console' );

        $store = new FeatureRequestStore();
        $req = $this->getRequest();
        $showResolved = $req->getCheck( 'show_resolved' );
        $focusId = (int)$req->getVal( 'id', 0 );

        $filters = [];
        if ( !$showResolved ) $filters['includeResolved'] = false;
        $filters['orderBy'] = 'fr_priority DESC, fr_status ASC, fr_created ASC';
        $rows = $store->listRequests( $filters, 500 );

        $counts = $store->countByStatus();

        $h  = '<div class="pcp-fr pcp-fr-review">';

        // Status counters
        $h .= '<div class="pcp-fr-counters">';
        foreach ( FeatureRequestStore::STATUSES as $k => $label ) {
            $h .= '<div class="pcp-fr-counter pcp-fr-counter-' . htmlspecialchars( $k ) . '"><span class="pcp-fr-counter-n">'
                . (int)( $counts[ $k ] ?? 0 ) . '</span><span class="pcp-fr-counter-l">' . htmlspecialchars( $label ) . '</span></div>';
        }
        $h .= '</div>';

        $togUrl = $this->getPageTitle()->getLocalURL( $showResolved ? [] : [ 'show_resolved' => '1' ] );
        $h .= '<div class="pcp-fr-review-toolbar">';
        $h .= '<a class="pcp-fr-btn" href="' . htmlspecialchars( $togUrl ) . '">' . ( $showResolved ? 'Hide resolved' : 'Show resolved' ) . '</a>';
        $h .= '<a class="pcp-fr-btn" href="' . htmlspecialchars( SpecialPage::getTitleFor( 'FeatureRequests' )->getLocalURL() ) . '">View public page &rarr;</a>';
        $h .= '</div>';

        if ( !$rows ) {
            $h .= '<div class="pcp-fr-empty"><p>No feature requests in scope.</p></div>';
            $h .= '</div>';
            $out->addHTML( $h );
            return;
        }

        $token = htmlspecialchars( $this->getUser()->getEditToken() );
        $h .= '<input type="hidden" id="pcp-fr-review-token" value="' . $token . '">';

        $h .= '<div class="pcp-fr-review-list">';
        foreach ( $rows as $row ) {
            $h .= $this->renderReviewRow( $row, $store, $focusId === (int)$row->fr_id );
        }
        $h .= '</div>';

        $h .= '</div>';
        $out->addHTML( $h );
    }

    private function renderReviewRow( \stdClass $row, FeatureRequestStore $store, bool $expanded ): string {
        $id = (int)$row->fr_id;
        $userFactory = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory();
        $submitter = $userFactory->newFromId( (int)$row->fr_user_id );
        $submitterName = $submitter ? $submitter->getName() : 'Unknown';
        $statusKey = (string)$row->fr_status;
        $priority = (int)$row->fr_priority;

        $h  = '<div class="pcp-fr-rrow" data-fr-id="' . $id . '"' . ( $expanded ? ' data-expanded="1"' : '' ) . '>';
        $h .= '<div class="pcp-fr-rrow-summary">';
        $h .= '<span class="pcp-fr-rrow-id">#' . $id . '</span>';
        $h .= '<button class="pcp-fr-rrow-toggle" type="button" aria-expanded="' . ( $expanded ? 'true' : 'false' ) . '">';
        $h .= '<span class="pcp-fr-rrow-title">' . htmlspecialchars( (string)$row->fr_title ) . '</span>';
        $h .= '</button>';
        $h .= '<span class="pcp-fr-rrow-submitter">' . htmlspecialchars( $submitterName );
        if ( (int)$row->fr_username_vis === 3 ) {
            $h .= ' <small class="pcp-fr-anon-tag">(submitted as anonymous)</small>';
        }
        $h .= '</span>';
        $h .= '<span class="pcp-fr-rrow-date">' . $this->relativeDate( (string)$row->fr_created ) . '</span>';

        $h .= '<select class="pcp-fr-rrow-status" data-field="status">';
        foreach ( FeatureRequestStore::STATUSES as $k => $label ) {
            $sel = ( $statusKey === $k ) ? ' selected' : '';
            $h .= '<option value="' . htmlspecialchars( $k ) . '"' . $sel . '>' . htmlspecialchars( $label ) . '</option>';
        }
        $h .= '</select>';

        $h .= '<select class="pcp-fr-rrow-prio" data-field="priority">';
        foreach ( FeatureRequestStore::PRIORITIES as $p => $label ) {
            $sel = ( $priority === $p ) ? ' selected' : '';
            $h .= '<option value="' . $p . '"' . $sel . '>' . htmlspecialchars( $label ) . '</option>';
        }
        $h .= '</select>';

        if ( (int)$row->fr_content_vis === 1 ) {
            $h .= '<span class="pcp-fr-privacy-tag" title="Sysops only">priv</span>';
        }
        $h .= '<span class="pcp-fr-rrow-savedmark" aria-hidden="true"></span>';
        $h .= '</div>';

        // Expanded body + attachments + sysop notes + sysop reply
        $h .= '<div class="pcp-fr-rrow-detail"' . ( $expanded ? '' : ' hidden' ) . '>';
        $h .= '<div class="pcp-fr-rrow-bodyhead">Body</div>';
        $h .= '<div class="pcp-fr-rrow-body">' . nl2br( htmlspecialchars( (string)$row->fr_body ) ) . '</div>';

        // Attachments
        $atts = $store->listAttachments( $id );
        if ( $atts ) {
            $h .= '<div class="pcp-fr-rrow-bodyhead">Attachments</div>';
            $h .= '<ul class="pcp-fr-attlist">';
            foreach ( $atts as $a ) {
                $dlUrl = wfScript( 'index' ) . '?title=Special:FeatureRequests&fr_dl=' . (int)$a->fra_id;
                $sizeMb = number_format( (int)$a->fra_size / 1024 / 1024, 2 );
                $h .= '<li><a href="' . htmlspecialchars( $dlUrl ) . '">' . htmlspecialchars( (string)$a->fra_filename ) . '</a>'
                    . ' <small class="pcp-fr-att-meta">(' . $sizeMb . ' MB)</small></li>';
            }
            $h .= '</ul>';
        }

        // Comments preview
        $comments = $store->listComments( $id );
        if ( $comments ) {
            $h .= '<div class="pcp-fr-rrow-bodyhead">Discussion</div>';
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

        // Sysop notes (private)
        $h .= '<div class="pcp-fr-rrow-bodyhead">Sysop notes <small>(private, never shown to submitter)</small></div>';
        $h .= '<textarea class="pcp-fr-rrow-notes" data-field="sysop_notes" rows="3" placeholder="Internal notes...">' . htmlspecialchars( (string)( $row->fr_sysop_notes ?? '' ) ) . '</textarea>';

        $h .= '<div class="pcp-fr-rrow-links">';
        $h .= '<a href="' . htmlspecialchars( SpecialPage::getTitleFor( 'FeatureRequests', (string)$id )->getLocalURL() ) . '">Open public detail page &rarr;</a>';
        if ( $submitter ) {
            $h .= ' &middot; <a href="' . htmlspecialchars( $submitter->getUserPage()->getLocalURL() ) . '">' . htmlspecialchars( $submitterName ) . '\'s user page &rarr;</a>';
        }
        $h .= '</div>';
        $h .= '</div>';

        $h .= '</div>';
        return $h;
    }

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
