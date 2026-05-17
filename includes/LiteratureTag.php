<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\Context\RequestContext;
use MediaWiki\Title\Title;

/**
 * <pharmaLiterature/> -- per-medicine Relevant Literature section.
 * Lists approved entries from pcp_literature plus a collapsed submission form.
 */
class LiteratureTag {
    public static function render( $input, array $args, $parser, $frame ) {
        $title = $parser->getTitle();
        if ( !$title ) { return ''; }
        $pageId = $title->getArticleID();
        if ( $pageId <= 0 ) { return ''; }

        $parser->getOutput()->updateCacheExpiry( 0 );
        $parser->getOutput()->addModules( [ 'ext.pharmacopedia' ] );
        $parser->getOutput()->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        $store = new LiteratureStore();
        $user = RequestContext::getMain()->getUser();
        $loggedIn = $user->isRegistered();
        $canSubmit = $loggedIn && $user->isAllowed( 'pharmacopedia-literature-submit' );
        $canUpload = $loggedIn && $user->isAllowed( 'pharmacopedia-literature-upload' );
        $canReview = $loggedIn && $user->isAllowed( 'pharmacopedia-literature-review' );

        $approved = $store->listApproved( $pageId );
        $myPending = $loggedIn ? $store->listPendingForUser( $pageId, $user->getId() ) : [];

        $h  = '<div class="pcp-literature" data-page-id="' . (int)$pageId . '"';
        $h .= ' data-can-submit="' . ( $canSubmit ? '1' : '0' ) . '"';
        $h .= ' data-can-upload="' . ( $canUpload ? '1' : '0' ) . '">';

        if ( !$approved && !$myPending ) {
            $h .= '<p class="pcp-lit-empty"><em>No literature entries yet.</em></p>';
        } else {
            $h .= '<ul class="pcp-lit-list">';
            foreach ( $approved as $row ) {
                $h .= self::renderEntry( $row, /*pending*/ false, /*canReview*/ $canReview );
            }
            // Your own pending entries (visible only to you and admins).
            foreach ( $myPending as $row ) {
                $h .= self::renderEntry( $row, /*pending*/ true, /*canReview*/ $canReview, /*ownPending*/ true );
            }
            $h .= '</ul>';
        }

        if ( !$loggedIn ) {
            $h .= '<p class="pcp-lit-loginnote">' .
                  '<a href="' . htmlspecialchars( Title::makeTitle( NS_SPECIAL, 'UserLogin' )->getLocalURL() ) . '">Log in</a>' .
                  ' to submit relevant literature.</p>';
        } elseif ( !$canSubmit ) {
            $h .= '<p class="pcp-lit-loginnote"><em>You do not have permission to submit literature.</em></p>';
        } else {
            $h .= self::renderFormDetails( $canUpload );
        }

        $h .= '</div>';
        return $h;
    }

    private static function renderEntry( $row, bool $pending, bool $canReview,
                                          bool $ownPending = false ): string {
        $title = htmlspecialchars( (string)$row->l_title );
        $authorsRaw = (string)( $row->l_authors ?? '' );
        $etAl = !empty( $row->l_et_al );
        $authors = '';
        if ( $authorsRaw !== '' ) {
            $authors = htmlspecialchars( $authorsRaw );
            if ( $etAl ) { $authors .= ' <em>et al.</em>'; }
        }
        $year = $row->l_year ? ' (' . (int)$row->l_year . ')' : '';

        $links = [];
        if ( !empty( $row->l_url ) ) {
            $u = htmlspecialchars( (string)$row->l_url );
            $links[] = '<a class="external" href="' . $u . '" target="_blank" rel="noopener nofollow">link</a>';
        }
        if ( !empty( $row->l_doi ) ) {
            $d = htmlspecialchars( (string)$row->l_doi );
            $links[] = '<a class="external" href="https://doi.org/' . $d . '" target="_blank" rel="noopener">doi:' . $d . '</a>';
        }
        if ( !empty( $row->l_pmid ) ) {
            $p = (int)$row->l_pmid;
            $links[] = '<a class="external" href="https://pubmed.ncbi.nlm.nih.gov/' . $p . '/" target="_blank" rel="noopener">PMID ' . $p . '</a>';
        }
        if ( !$pending && !empty( $row->l_file_path ) ) {
            $docUrl = htmlspecialchars(
                Title::makeTitle( NS_SPECIAL, 'LiteratureDoc/' . (int)$row->l_id )->getLocalURL()
            );
            $links[] = '<a href="' . $docUrl . '" target="_blank" rel="noopener">&#128206; PDF</a>';
        }

        $byline = '';
        if ( !empty( $row->submitter_name ) ) {
            $u = htmlspecialchars( $row->submitter_name );
            $byline = '<span class="pcp-lit-byline">submitted by <a href="' .
                      htmlspecialchars( Title::makeTitle( NS_USER, $u )->getLocalURL() ) . '">' .
                      $u . '</a></span>';
        } else {
            $byline = '<span class="pcp-lit-byline">submitted anonymously</span>';
        }
        $li  = '<li class="pcp-lit-entry' . ( $pending ? ' pcp-lit-pending' : '' ) . '"';
        $li .= ' data-id="' . (int)$row->l_id . '">';
        $li .= '<div class="pcp-lit-line">';
        if ( $authors !== '' ) { $li .= '<strong>' . $authors . '</strong> &mdash; '; }
        $li .= '<em>' . $title . '</em>' . $year . '. ';
        if ( $links ) { $li .= '[ ' . implode( ' &middot; ', $links ) . ' ]'; }
        $li .= '</div>';
        if ( $pending ) {
            $li .= '<div class="pcp-lit-meta"><span class="pcp-lit-status">&#9203; pending review</span>';
            if ( $ownPending ) {
                $li .= ' <button type="button" class="pcp-lit-delete-own mw-ui-button mw-ui-quiet" ' .
                       'data-id="' . (int)$row->l_id . '">Delete</button>';
            }
            $li .= '</div>';
        } elseif ( $byline ) {
            $li .= '<div class="pcp-lit-meta">' . $byline . '</div>';
        }
        $li .= '</li>';
        return $li;
    }

    private static function renderFormDetails( bool $canUpload ): string {
        $h  = '<details class="pcp-lit-form-wrap">';
        $h .= '<summary>+ Submit relevant literature</summary>';
        $h .= '<form class="pcp-lit-form" onsubmit="return false;">';

        $h .= '<div class="pcp-lit-field">';
        $h .= '<label>Title <span class="pcp-lit-req">*</span></label>';
        $h .= '<input type="text" class="pcp-lit-title" maxlength="500" required>';
        $h .= '</div>';

        $h .= '<div class="pcp-lit-field">';
        $h .= '<label>Author(s) <small>(first author last name, or full citation)</small></label>';
        $h .= '<input type="text" class="pcp-lit-authors" maxlength="500" placeholder="Smith J">';
        $h .= '<label class="pcp-lit-etal"><input type="checkbox" class="pcp-lit-etal-cb"> show as "<em>et al.</em>"</label>';
        $h .= '</div>';

        $h .= '<div class="pcp-lit-field pcp-lit-row">';
        $h .= '<div><label>Year</label>';
        $h .= '<input type="number" class="pcp-lit-year" min="1800" max="2100" placeholder="2024"></div>';
        $h .= '<div><label>DOI <small>(optional)</small></label>';
        $h .= '<input type="text" class="pcp-lit-doi" maxlength="255" placeholder="10.1000/xyz"></div>';
        $h .= '<div><label>PubMed ID <small>(optional)</small></label>';
        $h .= '<input type="number" class="pcp-lit-pmid" min="1" placeholder="12345678"></div>';
        $h .= '</div>';

        $h .= '<div class="pcp-lit-field">';
        $h .= '<label>URL to article</label>';
        $h .= '<input type="url" class="pcp-lit-url" maxlength="2048" placeholder="https://...">';
        $h .= '</div>';

        if ( $canUpload ) {
            $h .= '<div class="pcp-lit-field">';
            $h .= '<label>Upload PDF <small>(optional, max 22.22 MB)</small></label>';
            $h .= '<input type="file" class="pcp-lit-file" accept="application/pdf,.pdf">';
            $h .= '<small class="pcp-lit-fileinfo"></small>';
            $h .= '</div>';
        }

        $h .= '<p class="pcp-lit-note"><em>At least one of URL or file is required. ' .
              'Submissions are reviewed by a moderator before they appear publicly.</em></p>';

        $h .= '<div class="pcp-lit-form-actions">';
        $h .= '<label class="pcp-lit-shownameLabel"><input type="checkbox" class="pcp-lit-showname"/> Show my username publicly (default: submit anonymously)</label>';
        $h .= '<button type="button" class="pcp-lit-submit mw-ui-button mw-ui-progressive">Submit for review</button>';
        $h .= '<span class="pcp-lit-status"></span>';
        $h .= '</div>';

        $h .= '</form>';
        $h .= '</details>';
        return $h;
    }
}
