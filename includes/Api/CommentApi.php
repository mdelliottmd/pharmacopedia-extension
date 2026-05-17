<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\Pharmacopedia\ElementStore;
use MediaWiki\Extension\Pharmacopedia\CommentStore;
use MediaWiki\Extension\Pharmacopedia\CommentTag;

class CommentApi extends ApiBase {
    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( 'pharmacopedia-login-required', 'notloggedin' );
        }
        if ( !$user->isAllowed( 'pharmacopedia-comment' ) ) {
            $this->dieWithError( 'pharmacopedia-permission-denied', 'permissiondenied' );
        }

        $params = $this->extractRequestParams();
        $op = $params['op'];
        $elementId = (int)$params['element_id'];

        $elementStore = new ElementStore();
        $element = $elementStore->getById( $elementId );
        if ( !$element ) {
            $this->dieWithError( 'pharmacopedia-element-not-found', 'notfound' );
        }

        $store = new CommentStore();
        $isSysop = $user->isAllowed( 'delete' );

        switch ( $op ) {
            case 'add':
                $text = trim( (string)$params['text'] );
                if ( $text === '' ) {
                    $this->dieWithError( 'pharmacopedia-empty-comment', 'empty' );
                }
                if ( mb_strlen( $text ) > 5000 ) {
                    $this->dieWithError( 'pharmacopedia-comment-too-long', 'toolong' );
                }
                $parentId = $params['parent_id'] !== null && $params['parent_id'] !== ''
                    ? (int)$params['parent_id'] : null;
                $displayName = null;
                if ( !empty( $params['show_name'] ) ) {
                    $displayName = $user->getName();
                }
                $store->add( $elementId, $user->getId(), $parentId, $text, $displayName );
                break;
            case 'edit':
                $commentId = (int)$params['comment_id'];
                $text = trim( (string)$params['text'] );
                if ( $text === '' ) { $this->dieWithError( 'pharmacopedia-empty-comment', 'empty' ); }
                if ( !$store->edit( $commentId, $user->getId(), $text, $isSysop ) ) {
                    $this->dieWithError( 'pharmacopedia-edit-failed', 'failed' );
                }
                break;
            case 'delete':
                $commentId = (int)$params['comment_id'];
                if ( !$store->delete( $commentId, $user->getId(), $isSysop ) ) {
                    $this->dieWithError( 'pharmacopedia-delete-failed', 'failed' );
                }
                break;
            default:
                $this->dieWithError( 'pharmacopedia-invalid-op', 'badvalue' );
        }

        $html = CommentTag::renderThread( $elementId, $user );
        $this->getResult()->addValue( null, 'pharmacopediacomment', [
            'element_id' => $elementId,
            'html'       => $html,
        ] );
    }

    public function getAllowedParams() {
        return [
            'op'         => [ ApiBase::PARAM_TYPE => [ 'add', 'edit', 'delete' ], ApiBase::PARAM_REQUIRED => true ],
            'element_id' => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'comment_id' => [ ApiBase::PARAM_TYPE => 'integer' ],
            'parent_id'  => [ ApiBase::PARAM_TYPE => 'integer' ],
            'text'       => [ ApiBase::PARAM_TYPE => 'string' ],
            'show_name'  => [ ApiBase::PARAM_TYPE => 'boolean' ],
        ];
    }
    public function needsToken()   { return 'csrf'; }
    public function isWriteMode()  { return true; }
    public function mustBePosted() { return true; }
}
