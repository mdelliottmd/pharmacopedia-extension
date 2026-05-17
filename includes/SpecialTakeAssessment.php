<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\Extension\Pharmacopedia\Assessments\Catq;
use MediaWiki\Extension\Pharmacopedia\Assessments\Pid5bf;
use MediaWiki\Extension\Pharmacopedia\Assessments\Raadsr;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Generic paginated assessment runner. Routes via $par:
 *   Special:TakeAssessment/pid5bf
 *   Special:TakeAssessment/raadsr
 *   Special:TakeAssessment/catq
 *
 * Per-page POST → store raw item responses + advance. Final page also computes
 * subscale + total scores into the test's primary namespace.
 *
 * Raw items live in {key}_raw (private). Scores live in {key}.
 * Per-test visibility lives in {key} namespace, key='_vis', as pf_value_num.
 */
class SpecialTakeAssessment extends SpecialPage {

    public function __construct() {
        parent::__construct( 'TakeAssessment' );
    }

    private function assessmentFor( string $key ): ?string {
        $map = [
            'pid5bf' => Pid5bf::class,
            'raadsr' => Raadsr::class,
            'catq'   => Catq::class,
        ];
        return $map[ $key ] ?? null;
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $out->addHTML( '<p>You must log in to take an assessment.</p>' );
            return;
        }

        $key = trim( (string)$par );
        $cls = $this->assessmentFor( $key );
        if ( !$cls ) {
            $out->setPageTitle( 'Take an assessment' );
            $this->renderIndex( $out );
            return;
        }

        $out->setPageTitle( $cls::FULL_NAME );

        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;
        $request = $this->getRequest();

        // POST: save the items on this page, then advance.
        if ( $request->wasPosted() && $user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
            $this->savePage( $store, $profileId, $cls, $request );
            $next = (int)$request->getVal( 'next_page', 1 );
            $url  = $this->getPageTitle( $key )->getLocalURL( [ 'page' => $next ] );
            $out->redirect( $url );
            return;
        }

        $page = max( 1, (int)$request->getVal( 'page', 0 ) );
        $totalItems = count( $cls::ITEMS );
        $pageSize   = (int)$cls::PAGE_SIZE;
        $totalPages = (int)ceil( $totalItems / $pageSize );

        if ( $page === 0 ) {
            // Intro screen
            $this->renderIntro( $out, $cls, $totalPages );
            return;
        }
        if ( $page > $totalPages ) {
            // Completion: score + persist results
            $this->finalize( $out, $store, $profileId, $cls );
            return;
        }

        $this->renderPage( $out, $store, $profileId, $cls, $page, $totalPages );
    }

    private function renderIndex( $out ) {
        $out->addHTML( '<h2>Available assessments</h2><ul>' );
        foreach ( [ Pid5bf::class, Raadsr::class, Catq::class ] as $cls ) {
            $url = htmlspecialchars( $this->getPageTitle( $cls::KEY )->getLocalURL() );
            $out->addHTML( '<li><a href="' . $url . '">' . htmlspecialchars( $cls::FULL_NAME )
                . '</a> — ' . htmlspecialchars( $cls::DESCRIPTION ) . '</li>' );
        }
        $out->addHTML( '</ul>' );
    }

    private function renderIntro( $out, string $cls, int $totalPages ) {
        $out->addHTML( '<p><strong>' . htmlspecialchars( $cls::FULL_NAME ) . '</strong></p>' );
        $out->addHTML( '<p>' . htmlspecialchars( $cls::DESCRIPTION ) . '</p>' );
        $out->addHTML( '<p><small>Source: ' . htmlspecialchars( $cls::CITATION ) . '</small></p>' );
        if ( $cls::WARNING ) {
            $out->addHTML( '<div class="pcp-banner"><span class="pcp-banner__title">Heads up</span>'
                . '<span class="pcp-banner__body">' . htmlspecialchars( $cls::WARNING ) . '</span></div>' );
        }
        $out->addHTML( '<p>' . $totalPages . ' page'
            . ( $totalPages === 1 ? '' : 's' )
            . '. Your responses are saved after each page so you can stop and resume.</p>' );
        $url = htmlspecialchars( $this->getPageTitle( $cls::KEY )->getLocalURL( [ 'page' => 1 ] ) );
        $out->addHTML( '<p><a href="' . $url . '" class="pcp-btn pcp-btn-primary">Begin</a></p>' );

        $back = htmlspecialchars( SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() );
        $out->addHTML( '<p><small><a href="' . $back . '">← back to My profile</a></small></p>' );
    }

    private function renderPage( $out, $store, int $profileId, string $cls, int $page, int $totalPages ) {
        $pageSize = (int)$cls::PAGE_SIZE;
        $items = $cls::ITEMS;
        $allNums = array_keys( $items );
        $start = ( $page - 1 ) * $pageSize;
        $slice = array_slice( $allNums, $start, $pageSize );

        $existing = $this->loadRaw( $store, $profileId, $cls::KEY . '_raw' );
        $labels   = $cls::RESPONSE_LABELS;

        $action = $this->getPageTitle( $cls::KEY )->getLocalURL();
        $token  = htmlspecialchars( $this->getUser()->getEditToken() );

        $out->addHTML( '<h2>' . htmlspecialchars( $cls::NAME )
            . ' — page ' . $page . ' of ' . $totalPages . '</h2>' );
        $out->addHTML( '<div class="pcp-progress"><div class="pcp-progress-bar" style="width:'
            . round( ( $page - 1 ) / $totalPages * 100 ) . '%"></div></div>' );

        $out->addHTML( '<form method="post" action="' . htmlspecialchars( $action ) . '" class="pcp-assess-form">' );
        $out->addHTML( '<input type="hidden" name="wpEditToken" value="' . $token . '">' );
        $out->addHTML( '<input type="hidden" name="title" value="'
            . htmlspecialchars( $this->getPageTitle( $cls::KEY )->getPrefixedDBkey() ) . '">' );
        $out->addHTML( '<input type="hidden" name="next_page" value="' . ( $page + 1 ) . '">' );

        foreach ( $slice as $n ) {
            $text = $items[ $n ];
            $cur  = $existing[ $n ] ?? '';
            $out->addHTML( '<fieldset class="pcp-assess-item"><legend><strong>' . (int)$n
                . '.</strong> ' . htmlspecialchars( $text ) . '</legend>' );
            $out->addHTML( '<div class="pcp-assess-choices">' );
            foreach ( $labels as $val => $label ) {
                $id = 'a_' . $cls::KEY . '_' . $n . '_' . $val;
                $checked = ( (string)$cur === (string)$val ) ? ' checked' : '';
                $out->addHTML( '<label for="' . $id . '">'
                    . '<input type="radio" id="' . $id . '" name="r[' . $n . ']" value="' . $val . '"' . $checked . '> '
                    . htmlspecialchars( $label ) . '</label>' );
            }
            $out->addHTML( '</div></fieldset>' );
        }

        $btnText = $page < $totalPages ? 'Save & continue' : 'Save & finish';
        $out->addHTML( '<div class="pcp-assess-actions">' );
        $out->addHTML( '<button type="submit" class="pcp-btn pcp-btn-primary">' . $btnText . '</button>' );
        $back = htmlspecialchars( SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() );
        $out->addHTML( ' <a href="' . $back . '" class="pcp-btn">Save & exit</a>' );
        $out->addHTML( '</div></form>' );
    }

    private function savePage( $store, int $profileId, string $cls, $request ) {
        $rNs = $cls::KEY . '_raw';
        $r = $request->getArray( 'r' ) ?: [];
        foreach ( $r as $itemN => $val ) {
            $itemN = (int)$itemN;
            $valStr = trim( (string)$val );
            if ( $valStr === '' ) continue;
            if ( !array_key_exists( $itemN, $cls::ITEMS ) ) continue;
            $store->setField( $profileId, $rNs, 'item_' . $itemN, null, (float)$valStr, 0 );
        }
    }

    private function finalize( $out, $store, int $profileId, string $cls ) {
        $raw = $this->loadRaw( $store, $profileId, $cls::KEY . '_raw' );
        $scores = $cls::scoreResponses( $raw );

        $vis = $this->loadVis( $store, $profileId, $cls::KEY );
        $ns = $cls::KEY;
        foreach ( $scores as $k => $v ) {
            if ( $v === null ) {
                $store->deleteField( $profileId, $ns, $k );
            } else {
                $store->setField( $profileId, $ns, $k, null, (float)$v, $vis );
            }
        }
        $now = MediaWikiServices::getInstance()->getConnectionProvider()
            ->getPrimaryDatabase()->timestamp();
        $store->setField( $profileId, $ns, 'taken_at', $now, null, $vis );

        $out->setPageTitle( $cls::NAME . ' — complete' );
        $out->addHTML( '<h2>' . htmlspecialchars( $cls::NAME ) . ' — results</h2>' );
        $out->addHTML( '<table class="pcp-pa-table"><tbody>' );
        foreach ( $cls::SUBSCALES as $k => $def ) {
            $v = $scores[ 'subscale_' . $k ] ?? null;
            $out->addHTML( '<tr><th>' . htmlspecialchars( $def['label'] ) . '</th>'
                . '<td>' . ( $v === null ? '—' : htmlspecialchars( (string)$v ) ) . '</td></tr>' );
        }
        if ( isset( $scores['total'] ) ) {
            $out->addHTML( '<tr><th>Total</th><td>'
                . ( $scores['total'] === null ? '—' : htmlspecialchars( (string)$scores['total'] ) )
                . '</td></tr>' );
        }
        $out->addHTML( '</tbody></table>' );
        $out->addHTML( '<p><em>' . htmlspecialchars( $cls::interpret( $scores ) ) . '</em></p>' );

        $back = htmlspecialchars( SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() );
        $out->addHTML( '<p><a href="' . $back . '" class="pcp-btn pcp-btn-primary">Back to My profile</a></p>' );
    }

    private function loadRaw( $store, int $profileId, string $rawNs ): array {
        $out = [];
        foreach ( $store->getFields( $profileId, $rawNs, 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) !== 0 ) continue;
            $n = (int)substr( $k, 5 );
            if ( $f->pf_value_num !== null ) {
                $out[ $n ] = (int)$f->pf_value_num;
            }
        }
        return $out;
    }

    private function loadVis( $store, int $profileId, string $ns ): int {
        foreach ( $store->getFields( $profileId, $ns, 0 ) as $f ) {
            if ( (string)$f->pf_key === '_vis' ) {
                return (int)( $f->pf_value_num ?? 0 );
            }
        }
        return 0; // default private
    }

    public function doesWrites() { return true; }
    protected function getGroupName() { return 'users'; }
}
