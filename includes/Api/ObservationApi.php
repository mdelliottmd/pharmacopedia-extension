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

                // Hard-override entry type from Quick Add picker. Empty / 'auto' = parser routing.
                $entryType = (string)( $params['entry_type'] ?? '' );

                // event / story: skip the parser's keyframe-vs-episode-vs-observation tree
                // entirely. Create one event row with the chosen le_type.
                if ( $entryType === 'event' || $entryType === 'story' ) {
                    $leType = $entryType === 'event'
                        ? \MediaWiki\Extension\Pharmacopedia\LifeStoryStore::TYPE_EVENT
                        : \MediaWiki\Extension\Pharmacopedia\LifeStoryStore::TYPE_STORY;
                    $_struct = $parsed['date_struct'];
                    $_iso = null;
                    if ( is_array( $_struct ) ) {
                        $_k = (string)( $_struct['kind'] ?? '' );
                        if ( $_k === 'point' ) {
                            $_iso = $_struct['point']['parsed']['iso'] ?? null;
                        } elseif ( $_k === 'range' ) {
                            $_iso = $_struct['from']['parsed']['iso'] ?? null;
                        }
                    }
                    $_disp = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatStructForCard( $_struct );

                    $pageId = null;
                    $pageUrl = null;
                    if ( $entryType === 'story' ) {
                        $rawTitle = trim( (string)( $params['story_title'] ?? '' ) );
                        if ( $rawTitle === '' ) {
                            $rawTitle = trim( preg_replace( '/\s+/', ' ', (string)$text ) );
                            if ( $rawTitle === '' ) {
                                $rawTitle = 'Story ' . ( $_iso ?: date( 'Y-m-d' ) );
                            } elseif ( mb_strlen( $rawTitle ) > 60 ) {
                                $rawTitle = mb_substr( $rawTitle, 0, 60 );
                            }
                        }
                        $rawTitle = preg_replace( '/[\[\]#{}<>\|]/', '', $rawTitle );
                        $rawTitle = trim( preg_replace( '/\s+/', ' ', $rawTitle ) );
                        if ( $rawTitle === '' ) $rawTitle = 'Story ' . date( 'Y-m-d' );

                        $titleStr = $rawTitle;
                        $titleObj = \MediaWiki\Title\Title::newFromText( $titleStr, NS_STORY );
                        $n = 2;
                        while ( $titleObj && $titleObj->exists() && $n <= 50 ) {
                            $titleStr = $rawTitle . ' (' . $n . ')';
                            $titleObj = \MediaWiki\Title\Title::newFromText( $titleStr, NS_STORY );
                            $n++;
                        }
                        if ( !$titleObj ) {
                            $this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $rawTitle ) ], 'invalidtitle' );
                        }

                        $services = \MediaWiki\MediaWikiServices::getInstance();
                        $wikiPage = $services->getWikiPageFactory()->newFromTitle( $titleObj );
                        $updater  = $wikiPage->newPageUpdater( $user );
                        $content  = \MediaWiki\Content\ContentHandler::makeContent( (string)$text, $titleObj );
                        $updater->setContent( \MediaWiki\Revision\SlotRecord::MAIN, $content );
                        $summary = \CommentStoreComment::newUnsavedComment( 'Created from Quick Add' );
                        $rev = $updater->saveRevision( $summary );
                        if ( !$rev ) {
                            $this->dieWithError( 'apierror-pagecannotsave', 'pagecannotsave' );
                        }
                        $pageId  = (int)$wikiPage->getId();
                        $pageUrl = $titleObj->getLocalURL();

                        $profStore = new \MediaWiki\Extension\Pharmacopedia\UserProfileStore();
                        $ownerProfile = $profStore->getOrCreateForUser( $user->getId() );
                        if ( $ownerProfile ) {
                            $dbw = $services->getConnectionProvider()->getPrimaryDatabase();
                            $now = $dbw->timestamp();
                            $dbw->newInsertQueryBuilder()
                                ->insertInto( 'pcp_visibility_rules' )
                                ->row( [
                                    'vr_profile_id' => (int)$ownerProfile->prof_id,
                                    'vr_namespace'  => 'story',
                                    'vr_key'        => (string)$pageId,
                                    'vr_rule_type'  => 'private',
                                    'vr_attribution'=> 1,
                                    'vr_created'    => $now,
                                    'vr_updated'    => $now,
                                ] )
                                ->caller( __METHOD__ )
                                ->execute();
                        }
                    }

                    $eventId = $ls->addEvent( $profileId, [
                        'date_iso'       => $_iso,
                        'date_precision' => 5,
                        'date_display'   => $_disp !== '' ? $_disp : null,
                        'date_struct'    => $_struct ? json_encode( $_struct, JSON_UNESCAPED_UNICODE ) : null,
                        'type'           => $leType,
                        'page_id'        => $pageId,
                        'title'          => $this->titleFromParse( $parsed ),
                        'body'           => $text,
                        'visibility'     => 0,
                        'tags'           => 'quickadd',
                    ] );
                    if ( !empty( $parsed['refs'] ) ) {
                        $ls->setEventRefs( $eventId, $parsed['refs'] );
                    }
                    $this->getResult()->addValue( null, 'event_id', $eventId );
                    if ( $pageId !== null ) {
                        $this->getResult()->addValue( null, 'page_id',  $pageId );
                        $this->getResult()->addValue( null, 'page_url', $pageUrl );
                    }
                    $this->getResult()->addValue( null, 'parsed', $parsed );
                    $this->getResult()->addValue( null, 'success', true );
                    break;
                }

                // observation / episode overrides: keep going to the parser tree below,
                // but force the relevant flag and skip the keyframe path.
                $skipKeyframePath = false;
                if ( $entryType === 'episode' ) {
                    $parsed['is_episode'] = true;
                    $skipKeyframePath = true;
                } elseif ( $entryType === 'observation' ) {
                    $parsed['is_episode'] = false;
                    $skipKeyframePath = true;
                }

                // Special case: subject is a custom trait + date range + numeric value.
                // Emit TWO keyframes (one at each endpoint of the range) with the
                // trait+value attached, instead of a single observation/episode.
                $traitRef = null;
                foreach ( $parsed['refs'] as $rr ) {
                    if ( ( $rr['role'] ?? '' ) === 'subject' && in_array( $rr['type'] ?? '', [ 'trait', 'trait_new' ], true ) ) {
                        $traitRef = $rr;
                        break;
                    }
                }
                $kind = $parsed['date_struct']['kind'] ?? '';
                $isTraitValue = $traitRef && $parsed['numeric_value'] !== null
                    && ( $kind === 'range' || $kind === 'point' );

                if ( $isTraitValue && empty( $skipKeyframePath ) ) {
                    $val = (float)$parsed['numeric_value'];
                    $label = (string)$traitRef['label'];
                    $key   = strtolower( preg_replace( '/[^a-z0-9_]+/i', '_', $label ) );
                    $key   = trim( $key, '_' );
                    if ( $key === '' ) $key = 'trait';
                    $endpoints = [];
                    if ( $kind === 'range' ) {
                        foreach ( [ 'from', 'through' ] as $end ) {
                            $iso = $parsed['date_struct'][ $end ]['parsed']['iso'] ?? null;
                            if ( !$iso ) continue;
                            $endpoints[] = [
                                'iso' => $iso,
                                'raw' => (string)( $parsed['date_struct'][ $end ]['raw_text'] ?? '' ),
                                'prec'=> (string)( $parsed['date_struct'][ $end ]['parsed']['precision'] ?? 'year' ),
                            ];
                        }
                    } else {
                        $iso = $parsed['date_struct']['point']['parsed']['iso'] ?? null;
                        if ( $iso ) {
                            $endpoints[] = [
                                'iso' => $iso,
                                'raw' => (string)( $parsed['date_struct']['point']['raw_text'] ?? '' ),
                                'prec'=> (string)( $parsed['date_struct']['point']['parsed']['precision'] ?? 'year' ),
                            ];
                        }
                    }
                    $eventIds = [];
                    foreach ( $endpoints as $ep ) {
                        $kfStruct = [
                            'kind' => 'point',
                            'point' => [
                                'raw_text' => $ep['raw'],
                                'parsed'   => [
                                    'kind'      => 'point',
                                    'precision' => $ep['prec'],
                                    'iso'       => $ep['iso'],
                                ],
                            ],
                        ];
                        $eid = $ls->addEvent( $profileId, [
                            'date_iso'       => $ep['iso'],
                            'date_precision' => 5, // DP_YEAR; setEventTraits will keep value
                            'date_display'   => \MediaWiki\Extension\Pharmacopedia\DatePicker::formatStructForCard( $kfStruct ),
                            'date_struct'    => json_encode( $kfStruct, JSON_UNESCAPED_UNICODE ),
                            'type'           => \MediaWiki\Extension\Pharmacopedia\LifeStoryStore::TYPE_OBSERVATION,
                            'title'          => $label . ' = ' . $val,
                            'body'           => $text,
                            'visibility'     => 0,
                            'tags'           => 'quickadd,trait',
                        ] );
                        $ls->setTraits( $eid, [ [
                            'namespace' => 'custom',
                            'key'       => $key,
                            'label'     => $label,
                            'value'     => $val,
                            'min'       => null,
                            'max'       => null,
                            'estimated' => 0,
                        ] ] );
                        $eventIds[] = $eid;
                    }
                    if ( $eventIds ) {
                        $this->getResult()->addValue( null, 'event_ids', $eventIds );
                        $this->getResult()->addValue( null, 'event_id', $eventIds[0] );
                        $this->getResult()->addValue( null, 'parsed', $parsed );
                        $this->getResult()->addValue( null, 'keyframes_count', count( $eventIds ) );
                        $this->getResult()->addValue( null, 'success', true );
                        break;
                    }
                }

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
            'entry_type'           => [ ParamValidator::PARAM_TYPE => [ '', 'auto', 'observation', 'event', 'episode', 'story' ], ParamValidator::PARAM_DEFAULT => '' ],
            'story_title'          => [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ],
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
