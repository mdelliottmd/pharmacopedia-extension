<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\Pharmacopedia\ElementStore;
use MediaWiki\Extension\Pharmacopedia\VoteTag;

class VoteApi extends ApiBase {

    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( 'pharmacopedia-login-required', 'notloggedin' );
        }
        if ( !$user->isAllowed( 'pharmacopedia-vote' ) ) {
            $this->dieWithError( 'pharmacopedia-permission-denied', 'permissiondenied' );
        }

        $params      = $this->extractRequestParams();
        $elementId   = (int)$params['element_id'];
        $value       = (int)$params['value'];
        $choicesRaw  = (string)( $params['choices']   ?? '' );
        $optionsH    = (string)( $params['options_h'] ?? '' );
        $userId      = (int)$user->getId();

        $store   = new ElementStore();
        $element = $store->getById( $elementId );
        if ( !$element ) {
            $this->dieWithError( 'pharmacopedia-element-not-found', 'notfound' );
        }

        $isChoice = in_array( (string)$element->ve_type, [ 'single', 'multi' ], true );

        // ===== Routing =====
        if ( $choicesRaw !== '' || $optionsH !== '' ) {
            // Choice / multi vote path.
            $choices = [];
            foreach ( explode( ',', $choicesRaw ) as $c ) {
                if ( $c !== '' ) $choices[] = (int)$c;
            }
            try {
                $store->castChoice( $elementId, $userId, $choices, $optionsH );
            } catch ( \Throwable $e ) {
                $this->dieWithError( [ 'apierror-exceptioncaught', wfEscapeWikiText( $e->getMessage() ) ], 'castchoice-failed' );
            }
            $userVote = 0;
        } else {
            // Binary vote path.
            if ( !in_array( $value, [ -1, 0, 1 ], true ) ) {
                $this->dieWithError( 'pharmacopedia-invalid-vote-value', 'badvalue' );
            }
            $store->castVote( $elementId, $userId, $value );
            $userVote = $value;
        }

        // Re-read the element post-write to get fresh counts.
        $updated = $store->getById( $elementId );

        // Compute tally + user choices for response.
        $tally  = $isChoice ? $store->tallyChoices( $elementId ) : null;
        $userCh = $isChoice ? $store->getUserChoices( $elementId, $userId ) : null;

        // Apply results-visibility policy.
        $policy    = (string)( $updated->ve_results_policy ?? 'live' );
        $showTally = VoteTag::shouldShowTally( $policy, $userCh ?? [] );
        if ( !$showTally ) {
            $tally = null;
        }

        $this->getResult()->addValue( null, 'pharmacopediavote', [
            'element_id'   => (int)$updated->ve_id,
            'upvotes'      => (int)$updated->ve_upvotes,
            'downvotes'    => (int)$updated->ve_downvotes,
            'score'        => $showTally ? ( (int)$updated->ve_upvotes - (int)$updated->ve_downvotes ) : null,
            'user_vote'    => $userVote,
            'tally'        => $tally,
            'user_choices' => $userCh,
            'policy'       => $policy,
            'show_tally'   => $showTally,
        ] );
    }

    public function getAllowedParams() {
        return [
            'element_id' => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'value'      => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => false, ApiBase::PARAM_DFLT => 0 ],
            'choices'    => [ ApiBase::PARAM_TYPE => 'string',  ApiBase::PARAM_REQUIRED => false ],
            'options_h'  => [ ApiBase::PARAM_TYPE => 'string',  ApiBase::PARAM_REQUIRED => false ],
        ];
    }

    public function needsToken()   { return 'csrf'; }
    public function isWriteMode()  { return true; }
    public function mustBePosted() { return true; }
}
