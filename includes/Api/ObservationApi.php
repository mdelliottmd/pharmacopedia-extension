<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use ApiBase;
use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\Extension\Pharmacopedia\ObservationParser;
use MediaWiki\Extension\Pharmacopedia\LifeStoryStore;
use MediaWiki\Extension\Pharmacopedia\UserProfileStore;

/**
 * Observation parse + submit API.
 *
 *   op=preview  -> POSTed text returned as parsed structure (no DB writes)
 *   op=submit   -> POSTed text persisted as a pcp_life_events row (type=3=OBSERVATION)
 *                  with optional override fields (date_struct, polarity, refs[])
 */
class ObservationApi extends ApiBase {

    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( 'pharmacopedia-login-required', 'notloggedin' );
        }
        $params = $this->extractRequestParams();
        $op = (string)$params['op'];

        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        $parser = new ObservationParser();

        switch ( $op ) {
            case 'preview':
                $text = (string)$params['text'];
                $parsed = $parser->parse( $text, $profileId );
                $this->getResult()->addValue( null, 'parsed', $parsed );
                break;

            case 'submit':
                if ( !$this->getRequest()->wasPosted() ) {
                    $this->dieWithError( 'apierror-mustbeposted', 'mustbeposted' );
                }
                $token = (string)$this->getRequest()->getVal( 'token', '' );
                if ( $token === '' || !$user->matchEditToken( $token ) ) {
                    $this->dieWithError( 'apierror-badtoken', 'badtoken' );
                }
                $text   = (string)$params['text'];
                $parsed = $parser->parse( $text, $profileId );

                // Optional client overrides
                if ( $params['date_struct_override'] !== '' ) {
                    $j = json_decode( (string)$params['date_struct_override'], true );
                    if ( is_array( $j ) ) $parsed['date_struct'] = $j;
                }
                if ( $params['polarity_override'] !== '' ) {
                    $parsed['polarity'] = (int)$params['polarity_override'];
                }

                $ls = new LifeStoryStore();
                if ( !empty( $parsed['is_episode'] ) ) {
                    $eventId = $ls->addEpisode( $profileId, [
                        'title'           => $this->titleFromParse( $parsed ),
                        'body'            => $text,
                        'date_struct'     => $parsed['date_struct'],
                        'episode_type'    => $parsed['episode_type'],
                        'episode_subtype' => $parsed['episode_subtype'],
                        'severity'        => null,
                        'visibility'      => 0,
                    ] );
                    $ls->setEventRefs( $eventId, $parsed['refs'] );
                } else {
                    $eventId = $ls->addObservation( $profileId, [
                        'raw_text'    => $text,
                        'title'       => $this->titleFromParse( $parsed ),
                        'date_struct' => $parsed['date_struct'],
                        'polarity'    => $parsed['polarity'],
                        'visibility'  => 0,
                    ] );
                    $ls->setEventRefs( $eventId, $parsed['refs'] );
                }

                $this->getResult()->addValue( null, 'event_id', $eventId );
                $this->getResult()->addValue( null, 'parsed', $parsed );
                $this->getResult()->addValue( null, 'success', true );
                break;

            default:
                $this->dieWithError( 'pharmacopedia-bad-op', 'badop' );
        }
    }

    /**
     * Build a short title from the parse for display in the timeline list.
     * Examples:
     *   "anxiety from bupropion"        (positive)
     *   "no anxiety from bupropion"     (negative)
     */
    private function titleFromParse( array $parsed ): string {
        $parts = [];
        if ( $parsed['polarity'] === 0 ) $parts[] = 'no';
        $subj = $parsed['subject_text'] ?? '';
        if ( $subj !== '' ) $parts[] = $subj;
        foreach ( $parsed['refs'] as $r ) {
            if ( $r['role'] === 'cause' )   $parts[] = 'from ' . $r['label'];
            if ( $r['role'] === 'context' ) $parts[] = 'with ' . $r['label'];
        }
        $title = trim( implode( ' ', $parts ) );
        if ( $title === '' ) $title = mb_substr( (string)$parsed['original_text'], 0, 140 );
        return mb_substr( $title, 0, 200 );
    }

    public function getAllowedParams() {
        return [
            'op'   => [ ParamValidator::PARAM_TYPE => [ 'preview', 'submit' ], ParamValidator::PARAM_REQUIRED => true ],
            'text' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_REQUIRED => true ],
            'date_struct_override' => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
            'polarity_override'    => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
        ];
    }

    public function isWriteMode() {
        $op = $this->getRequest()->getVal( 'op', 'preview' );
        return $op === 'submit';
    }

    public function mustBePosted() {
        $op = $this->getRequest()->getVal( 'op', 'preview' );
        return $op === 'submit';
    }
}
