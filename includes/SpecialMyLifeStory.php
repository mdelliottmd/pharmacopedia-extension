<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Special:MyLifeStory — owner-edit timeline.
 *   $par = ''         → chronological list of events + Add button
 *   $par = 'add'      → add-event form
 *   $par = 'edit/<id>'→ edit-event form
 *   POST routes:
 *     action=save_event   → upsert event (+ optional image + optional traits)
 *     action=delete_event → delete event
 *     action=delete_image → delete one image attached to an event
 */
class SpecialMyLifeStory extends SpecialPage {

    public function __construct() {
        parent::__construct( 'MyLifeStory' );
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->setPageTitle( 'My life story' );
        $urlEvt = htmlspecialchars( $this->getPageTitle( 'add' )->getLocalURL() );
        $urlEpi = htmlspecialchars( $this->getPageTitle( 'add-episode' )->getLocalURL() );
        $out->addHTML(
            '<div class="pcp-life-add-row">'
            . '<a class="pcp-life-btn pcp-life-btn-event" href="' . $urlEvt . '">'
                . '<span class="pcp-life-btn-icon">📅</span>'
                . '<span class="pcp-life-btn-main">Event</span>'
                . '<span class="pcp-life-btn-sub">a single moment</span>'
            . '</a>'
            . '<a class="pcp-life-btn pcp-life-btn-episode" href="' . $urlEpi . '">'
                . '<span class="pcp-life-btn-icon">🌀</span>'
                . '<span class="pcp-life-btn-main">Episode</span>'
                . '<span class="pcp-life-btn-sub">a span of time</span>'
            . '</a>'
            . '</div>'
        );
        $out->addHTML( '<div class="pcp-obs-quickadd">' .
            '<h3>Quick add: log an observation</h3>' .
            '<textarea class="pcp-obs-input" rows="2" placeholder="e.g. anxiety from bupropion in jan 2020 — or — no insomnia while on melatonin in summer 2023"></textarea>' .
            '<div class="pcp-obs-preview"></div>' .
            '<button type="button" class="pcp-btn pcp-obs-submit">Add to timeline</button>' .
        '</div>' );
        $out->setSubtitle( '<a href="#" class="pcp-share-trigger" data-ns="life_events" data-label="Life story">🔗 Share</a>' );
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles', 'ext.pharmacopedia.datepicker.styles', 'ext.pharmacopedia.share', 'ext.pharmacopedia.observation' ] );
        $out->addModules( [ 'ext.pharmacopedia.datepicker', 'ext.pharmacopedia.share', 'ext.pharmacopedia.observation', 'ext.pharmacopedia.lifetimeline', 'ext.pharmacopedia.lifegraph' ] );

        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $out->addHTML( '<p>You must <a href="' .
                htmlspecialchars( SpecialPage::getTitleFor( 'UserLogin' )->getLocalURL() ) .
                '">log in</a> to view or edit your life story.</p>' );
            return;
        }

        $profileStore = new UserProfileStore();
        $profile = $profileStore->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;
        $store = new LifeStoryStore();
        $request = $this->getRequest();

        // ----- POST routing -----
        if ( $request->wasPosted() && $user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
            $action = $request->getVal( 'action', '' );
            if ( $action === 'save_episode' ) {
                $eventId = $this->saveEpisode( $store, $profileId, $request );
                $out->redirect( $this->getPageTitle()->getLocalURL( [ 'saved' => $eventId ] ) );
                return;
            }
            if ( $action === 'save_event' ) {
                $eventId = $this->saveEvent( $store, $profileId, $request );
                $out->redirect( $this->getPageTitle()->getLocalURL( [ 'saved' => $eventId ] ) );
                return;
            }
            if ( $action === 'delete_event' ) {
                $eid = (int)$request->getVal( 'event_id', 0 );
                $event = $store->getEvent( $eid );
                if ( $event && (int)$event->le_profile_id === $profileId ) {
                    $store->deleteEvent( $eid );
                }
                $out->redirect( $this->getPageTitle()->getLocalURL( [ 'deleted' => 1 ] ) );
                return;
            }
            if ( $action === 'delete_image' ) {
                $iid = (int)$request->getVal( 'image_id', 0 );
                $img = $store->getImage( $iid );
                if ( $img ) {
                    $event = $store->getEvent( (int)$img->li_event_id );
                    if ( $event && (int)$event->le_profile_id === $profileId ) {
                        $store->deleteImage( $iid );
                    }
                }
                $out->redirect( $this->getPageTitle()->getLocalURL(
                    [ 'edit_event' => (int)$request->getVal( 'event_id', 0 ) ]
                ) );
                return;
            }
        }

        // ----- Banner -----
        if ( $request->getVal( 'saved' ) ) {
            $out->addHTML( $this->banner( 'Saved.', 'Event saved.' ) );
        }
        if ( $request->getVal( 'deleted' ) ) {
            $out->addHTML( $this->banner( 'Deleted.', 'Event removed.' ) );
        }
        if ( $request->getVal( 'upload_error' ) ) {
            $out->addHTML( $this->banner( 'Upload error',
                (string)$request->getVal( 'upload_error' ), true ) );
        }

        // ----- View / Form routing -----
        $par = trim( (string)$par );
        $editId = (int)$request->getVal( 'edit_event', 0 );
        if ( $par === 'add-episode' ) {
            $this->renderEpisodeForm( $out, $user, $profileId, null );
            return;
        }
        if ( preg_match( '#^edit-episode/(\d+)$#', $par, $em ) ) {
            $event = $store->getEvent( (int)$em[1] );
            if ( $event && (int)$event->le_profile_id === $profileId ) {
                $this->renderEpisodeForm( $out, $user, $profileId, $event );
                return;
            }
        }
        if ( $par === 'add' ) {
            $this->renderEventForm( $out, $user, $profileId, null );
            return;
        }
        if ( preg_match( '#^edit/(\d+)$#', $par, $m ) ) {
            $editId = (int)$m[1];
        }
        if ( $editId > 0 ) {
            $event = $store->getEvent( $editId );
            if ( $event && (int)$event->le_profile_id === $profileId ) {
                $this->renderEventForm( $out, $user, $profileId, $event );
                return;
            }
        }

        // Phase 2: derived-events toggle (defaults ON for self viewer)
        $derivedOn = $this->getRequest()->getBool( 'derived', true );
        $this->renderToggleBar( $out, $derivedOn );

        // Phase 2: trajectory charts at top
        // disabled: trajectory widget removed per user request

        // Event list, optionally merged with derived events
        $this->renderTimelineMount( $out, $store, $profileId, $derivedOn );
        $this->renderEventList( $out, $store, $user, $profileId, $derivedOn );
        $this->closeListView( $out );
    }

    private function renderToggleBar( $out, bool $derivedOn ) {
        $pageUrl = $this->getPageTitle()->getLocalURL();
        $onUrl   = htmlspecialchars( $this->getPageTitle()->getLocalURL( [ 'derived' => 1 ] ) );
        $offUrl  = htmlspecialchars( $this->getPageTitle()->getLocalURL( [ 'derived' => 0 ] ) );
        $cur = $derivedOn ? 'on' : 'off';
        $out->addHTML( '<div class="pcp-life-toggle-bar">' );
        $out->addHTML( '<span class="pcp-prof-help"><small>Derived events (meds, diagnoses, experience reports): <strong>' . $cur . '</strong>. ' );
        $out->addHTML( '<a href="' . ( $derivedOn ? $offUrl : $onUrl ) . '">turn ' . ( $derivedOn ? 'off' : 'on' ) . '</a>' );
        $out->addHTML( '</small></span></div>' );
    }

    private function renderTrajectoryCharts( $out, $store, int $profileId ) {
        $series = $store->getKeyframeTimeseries( $profileId );
        if ( !$series ) return;
        $scales = [
            'pid5bf' => [ 'min' => 0,  'max' => 3 ],
            'catq'   => [ 'min' => 0,  'max' => 175 ],
            'raadsr' => [ 'min' => 0,  'max' => 240 ],
            'ocean'  => [ 'min' => 0,  'max' => 100 ],
        ];
        $titles = [
            'pid5bf' => 'PID-5-BF trajectory',
            'catq'   => 'CAT-Q trajectory',
            'raadsr' => 'RAADS-R trajectory',
            'ocean'  => 'OCEAN trajectory',
        ];
        $out->addHTML( '<details class="pcp-life-charts" open><summary>📈 Personality trajectory</summary>' );
        foreach ( $series as $ns => $traits ) {
            $scale = $scales[ $ns ] ?? null;
            $title = $titles[ $ns ] ?? ( $ns . ' trajectory' );

            // For 'custom', each trait may have its own min/max scale — normalize each to 0-1
            if ( $ns === 'custom' ) {
                $normalized = [];
                foreach ( $traits as $key => $pts ) {
                    $vals = array_column( $pts, 'value' );
                    $mins = array_filter( array_column( $pts, 'min' ), function ( $v ) { return $v !== null; } );
                    $maxs = array_filter( array_column( $pts, 'max' ), function ( $v ) { return $v !== null; } );
                    $lo = $mins ? min( $mins ) : min( $vals );
                    $hi = $maxs ? max( $maxs ) : max( $vals );
                    if ( abs( $hi - $lo ) < 0.0001 ) $hi = $lo + 1;
                    $normPts = [];
                    foreach ( $pts as $p ) {
                        $normPts[] = [
                            'date'      => $p['date'],
                            'value'     => ( $p['value'] - $lo ) / ( $hi - $lo ),
                            'label'     => $p['label'] . ' (norm)',
                            'estimated' => $p['estimated'],
                        ];
                    }
                    $normalized[ $key ] = $normPts;
                }
                $svg = \MediaWiki\Extension\Pharmacopedia\LifeStoryStore::renderTrajectorySvg(
                    'Custom traits (normalized 0–1)', $normalized,
                    [ 'min' => 0, 'max' => 1 ]
                );
            } else {
                $svg = \MediaWiki\Extension\Pharmacopedia\LifeStoryStore::renderTrajectorySvg(
                    $title, $traits, $scale
                );
            }
            $out->addHTML( '<div class="pcp-life-chart-wrap">' . $svg . '</div>' );
        }
        $out->addHTML( '</details>' );
    }

    // ===== List view =====



    private function renderTraitGraph( $out, $store, int $profileId ): void {
        $ts = $store->getKeyframeTimeseries( $profileId );
        $groups = [];
        $items  = [];
        // Stable palette: pull from a curated set; cycle if more series.
        $palette = [ '#c4b5fd', '#86efac', '#fda4af', '#fdba74', '#7dd3fc', '#a78bfa', '#f0abfc', '#34d399', '#fb923c' ];
        $idx = 0;
        foreach ( $ts as $ns => $byKey ) {
            foreach ( $byKey as $key => $points ) {
                if ( count( $points ) < 2 ) continue;  // need ≥2 for a line
                $gid = $ns . ':' . $key;
                $color = $palette[ $idx % count( $palette ) ];
                $idx++;
                $groups[] = [
                    'id'      => $gid,
                    'content' => $ns . ' / ' . $key,
                    'options' => [
                        'drawPoints' => [ 'size' => 6, 'style' => 'circle' ],
                        'shaded'     => false,
                        'style'      => 'stroke: ' . $color . '; stroke-width: 2; fill: ' . $color . ';'
                    ]
                ];
                foreach ( $points as $p ) {
                    if ( empty( $p['date'] ) || !isset( $p['value'] ) ) continue;
                    $items[] = [
                        'x'     => (string)$p['date'],
                        'y'     => (float)$p['value'],
                        'group' => $gid,
                        'label' => isset( $p['label'] ) ? (string)$p['label'] : null,
                    ];
                }
            }
        }
        $payload = [ 'groups' => $groups, 'items' => $items ];
        $out->addHTML( '<script>window.PCP_LIFE_GRAPH_DATA = ' . json_encode( $payload, JSON_UNESCAPED_SLASHES ) . ';</script>' );
        $out->addHTML(
            '<div class="pcp-life-graph-header">'
            . '<h3>📈 Trait trajectories</h3>'
            . '<button type="button" class="pcp-life-graph-fit">Fit graph</button>'
            . '</div>'
            . '<div id="pcp-life-graph-mount"></div>'
        );
    }

    private function renderTimelineMount( $out, $store, int $profileId, bool $derivedOn ): void {
        $stored  = $store->getEventsForProfile( $profileId, 0 );
        $derived = $derivedOn ? $store->getDerivedEvents( $profileId, '' ) : [];
        $items = [];
        $linkPage = $this->getPageTitle();
        foreach ( $stored as $e ) {
            $items[] = $this->timelineItemForStored( $e, $linkPage );
        }
        $derivedIdx = 0;
        foreach ( $derived as $e ) {
            $items[] = $this->timelineItemForDerived( $e, $derivedIdx++ );
        }
        $payload = [ 'events' => $items ];
        $json = json_encode( $payload, JSON_UNESCAPED_SLASHES );
        $out->addHTML( '<script>window.PCP_LIFE_TIMELINE_DATA = ' . $json . ';</script>' );
        $groups = [
            'episodes'     => [ 'label' => 'Episodes',     'on' => true  ],
            'events'       => [ 'label' => 'Events',       'on' => true  ],
            'observations' => [ 'label' => 'Observations', 'on' => true  ],
            'keyframes'    => [ 'label' => 'Keyframes',    'on' => true  ],
            'derived'      => [ 'label' => 'Derived',      'on' => false ],
        ];
        $togglesHtml = '';
        foreach ( $groups as $gid => $meta ) {
            $checked = $meta['on'] ? ' checked' : '';
            $togglesHtml .= '<label class="pcp-life-timeline-group-toggle-wrap">'
                . '<input type="checkbox" class="pcp-life-timeline-group-toggle" value="' . htmlspecialchars( $gid ) . '"' . $checked . '>'
                . ' ' . htmlspecialchars( $meta['label'] )
                . '</label>';
        }
        $out->addHTML(
            '<div class="pcp-life-view-tabs">'
                . '<button type="button" class="pcp-life-view-toggle active" data-view="visual">Visual timeline</button>'
                . '<button type="button" class="pcp-life-view-toggle" data-view="list">Card list</button>'
            . '</div>'
            . '<div class="pcp-life-timeline-controls">'
                . $togglesHtml
                . '<span class="pcp-life-toolbar-spacer"></span>'
                . '<button type="button" class="pcp-life-timeline-zoomout" title="Zoom out">&minus;</button>'
                . '<button type="button" class="pcp-life-timeline-zoomin" title="Zoom in">+</button>'
                . '<button type="button" class="pcp-life-timeline-fit" title="Fit visible groups">Fit visible</button>'
                . '<button type="button" class="pcp-life-timeline-fit-all" title="Fit everything (incl. derived/keyframes)">Fit everything</button>'
            . '</div>'
        );
        $out->addHTML( '<div class="pcp-life-view active" data-view="visual">'
            . '<div id="pcp-life-timeline-mount"></div>'
        . '</div>' );
        $out->addHTML( '<div class="pcp-life-view active" data-view="visual" data-section="graph">' );
        $this->renderTraitGraph( $out, $store, $profileId );
        $out->addHTML( '</div>' );
        $out->addHTML( '<div class="pcp-life-view" data-view="list">' );
    }

    private function closeListView( $out ): void {
        $out->addHTML( '</div>' );
    }

    private function timelineItemForStored( $e, $linkPage ): array {
        $type = (int)$e->le_type;
        $group = [ 0=>'events', 1=>'events', 2=>'keyframes', 3=>'observations', 4=>'episodes' ][ $type ] ?? 'events';
        $start = null; $end = null;
        if ( $e->le_date_struct ) {
            $s = json_decode( (string)$e->le_date_struct, true );
            if ( is_array( $s ) ) {
                if ( ( $s['kind'] ?? '' ) === 'range' ) {
                    $start = $s['from']['parsed']['iso']    ?? null;
                    $end   = $s['through']['parsed']['iso'] ?? null;
                } elseif ( ( $s['kind'] ?? '' ) === 'point' ) {
                    $start = $s['point']['parsed']['iso'] ?? null;
                }
            }
        }
        if ( !$start && $e->le_date_iso ) $start = (string)$e->le_date_iso;
        $editUrl = $type === 4
            ? $linkPage->getSubpage( 'edit-episode/' . (int)$e->le_id )->getLocalURL()
            : $linkPage->getLocalURL( [ 'edit_event' => (int)$e->le_id ] );
        return [
            'id'        => (int)$e->le_id,
            'group'     => $group,
            'title'     => (string)$e->le_title,
            'subtitle'  => $type === 4 && $e->le_episode_type ? (string)$e->le_episode_type . ( $e->le_episode_subtype ? ' / ' . (string)$e->le_episode_subtype : '' ) : null,
            'start'     => $start,
            'end'       => $end,
            'severity'  => $e->le_severity !== null ? (float)$e->le_severity : null,
            'polarity'  => $e->le_polarity !== null ? (int)$e->le_polarity : null,
            'dateLabel' => (string)( $e->le_date_display ?? '' ),
            'editUrl'   => $editUrl,
        ];
    }

    private function timelineItemForDerived( $e, int $idx = 0 ): array {
        return [
            'id'        => 'd-' . $idx . '-' . md5( ( $e->_source ?? '' ) . ':' . ( $e->_source_id ?? '' ) ),
            'group'     => 'derived',
            'title'     => (string)( $e->le_title ?? '' ),
            'subtitle'  => 'derived: ' . (string)( $e->_source ?? '' ),
            'start'     => isset( $e->le_date_iso ) ? (string)$e->le_date_iso : null,
            'end'       => null,
            'severity'  => null,
            'polarity'  => null,
            'dateLabel' => (string)( $e->le_date_display ?? '' ),
            'editUrl'   => null,
        ];
    }

    private function renderEventList( $out, $store, $user, int $profileId, bool $derivedOn = false ) {
        $events = $store->getEventsForProfile( $profileId, 0 );

        if ( $derivedOn ) {
            $pStore = new UserProfileStore();
            $profile = $pStore->getOrCreateForUser( $user->getId() );
            $voterHash = (string)$profile->prof_voter_hash;
            $derived = $store->getDerivedEvents( $profileId, $voterHash );
            $events = array_merge( $events, $derived );
            // Sort by date_iso (nulls last), then id stable
            usort( $events, function ( $a, $b ) {
                $da = (string)( $a->le_date_iso ?? '' );
                $db = (string)( $b->le_date_iso ?? '' );
                if ( $da === '' && $db !== '' ) return 1;
                if ( $db === '' && $da !== '' ) return -1;
                return strcmp( $da, $db );
            } );
        }

        $addUrl = htmlspecialchars( $this->getPageTitle( 'add' )->getLocalURL() );
        $out->addHTML(
            '<div class="pcp-lifestory-actions">' .
            '<a class="pcp-btn pcp-btn-primary" href="' . $addUrl . '">+ Add event</a> ' .
            '<span class="pcp-prof-help"><small>' . count( $events ) . ' event' .
            ( count( $events ) === 1 ? '' : 's' ) . ' stored.</small></span>' .
            '</div>'
        );

        if ( !$events ) {
            $out->addHTML( '<p><em>Your life story is empty. Click <strong>+ Add event</strong> to begin.</em></p>' );
            return;
        }

        $lastYear = null;
        foreach ( $events as $e ) {
            $year = $e->le_date_iso ? (int)substr( (string)$e->le_date_iso, 0, 4 ) : null;
            if ( $year !== $lastYear ) {
                $out->addHTML( '<h2 class="pcp-life-year">' .
                    ( $year !== null ? (int)$year : 'undated' ) . '</h2>' );
                $lastYear = $year;
            }
            $out->addHTML( $this->renderEventCard( $store, $e, true ) );
        }
    }

    private function renderEventCard( $store, $event, bool $editable ): string {
        $h = function ( $s ) { return htmlspecialchars( (string)$s ); };
        // Derived events render as a distinct read-only card
        if ( !empty( $event->_is_derived ?? false ) ) {
            return $this->renderDerivedCard( $event, $h );
        }
        $typeLabel = [ 0=>'story', 1=>'image', 2=>'keyframe', 3=>'observation', 4=>'episode' ][ (int)$event->le_type ] ?? 'story';
        $visIcon   = [ 0=>'🔒', 1=>'👁', 2=>'🆔', 3=>'🎭' ][ (int)$event->le_visibility ] ?? '🔒';
        $dateText  = $this->formatDate( $event );

        $out = '<div class="pcp-life-card pcp-life-type-' . $h( $typeLabel ) . '">';
        $out .= '<div class="pcp-life-card-header">';
        $out .= '<span class="pcp-life-date">' . $h( $dateText ) . '</span> ';
        $out .= '<span class="pcp-life-type-badge">' . $h( $typeLabel ) . '</span> ';
        if ( (int)$event->le_type === LifeStoryStore::TYPE_OBSERVATION ) {
            $pol = $event->le_polarity;
            if ( $pol === '0' || $pol === 0 ) {
                $out .= '<span class="pcp-obs-polarity-neg">✕ did NOT</span> ';
            } elseif ( $pol === '1' || $pol === 1 ) {
                $out .= '<span class="pcp-obs-polarity-pos">✓ did</span> ';
            }
        }
        $out .= '<span class="pcp-life-vis-badge" title="visibility">' . $visIcon . '</span>';
        if ( $editable ) {
            if ( (int)$event->le_type === LifeStoryStore::TYPE_EPISODE ) {
                $editUrl = htmlspecialchars( $this->getPageTitle( 'edit-episode/' . (int)$event->le_id )->getLocalURL() );
            } else {
                $editUrl = htmlspecialchars( $this->getPageTitle()->getLocalURL(
                    [ 'edit_event' => (int)$event->le_id ] ) );
            }
            $out .= ' <a class="pcp-life-edit" href="' . $editUrl . '">edit</a>';
        }
        $out .= '</div>';
        $out .= '<h3 class="pcp-life-title">' . $h( $event->le_title ) . '</h3>';
        if ( $event->le_body !== null && $event->le_body !== '' ) {
            $out .= '<div class="pcp-life-body">' . nl2br( $h( (string)$event->le_body ) ) . '</div>';
        }

        if ( (int)$event->le_type === LifeStoryStore::TYPE_EPISODE ) {
            $epType = $event->le_episode_type ? (string)$event->le_episode_type : '';
            $epSub  = $event->le_episode_subtype ? ' / ' . (string)$event->le_episode_subtype : '';
            if ( $epType ) {
                $out .= '<div class="pcp-ep-type-line">' . $h( $epType . $epSub ) . '</div>';
            }
            if ( $event->le_severity !== null ) {
                $sv = (int)$event->le_severity;
                $out .= '<div class="pcp-ep-severity-row">severity: ' . $sv
                    . ' <div class="pcp-ep-severity-bar"><div style="width:' . $sv . '%"></div></div></div>';
            }
        }

        // Observation details (refs + raw text)
        if ( (int)$event->le_type === LifeStoryStore::TYPE_OBSERVATION ) {
            $out .= $this->renderObservationDetails( $store, $event );
        }

        // Images
        $images = $store->getImagesForEvent( (int)$event->le_id );
        if ( $images ) {
            $out .= '<div class="pcp-life-images">';
            foreach ( $images as $im ) {
                $url = htmlspecialchars( SpecialPage::getTitleFor( 'LifeImage',
                    (int)$event->le_id . '/' . (int)$im->li_id )->getLocalURL() );
                $alt = $h( $im->li_caption ?? $im->li_orig_name );
                $out .= '<figure class="pcp-life-img"><img src="' . $url . '" alt="' . $alt . '">';
                if ( $im->li_caption ) {
                    $out .= '<figcaption>' . $h( $im->li_caption ) . '</figcaption>';
                }
                $out .= '</figure>';
            }
            $out .= '</div>';
        }

        // Keyframe traits
        if ( (int)$event->le_type === LifeStoryStore::TYPE_KEYFRAME ) {
            $traits = $store->getTraitsForEvent( (int)$event->le_id );
            if ( $traits ) {
                $out .= '<table class="pcp-pa-table pcp-life-traits"><tbody>';
                foreach ( $traits as $t ) {
                    $label = $t->lt_label ? (string)$t->lt_label : (string)$t->lt_namespace . '/' . (string)$t->lt_key;
                    $v = rtrim( rtrim( (string)$t->lt_value_num, '0' ), '.' );
                    $scale = '';
                    if ( $t->lt_min !== null || $t->lt_max !== null ) {
                        $scale = ' <small>(' .
                            ( $t->lt_min !== null ? rtrim( rtrim( (string)$t->lt_min, '0' ), '.' ) : '' ) .
                            '–' . ( $t->lt_max !== null ? rtrim( rtrim( (string)$t->lt_max, '0' ), '.' ) : '' ) .
                            ')</small>';
                    }
                    $est = (int)$t->lt_estimated ? ' <em>(estimated)</em>' : '';
                    $out .= '<tr><th>' . $h( $label ) . '</th>'
                        . '<td>' . $h( $v ) . $scale . $est . '</td></tr>';
                }
                $out .= '</tbody></table>';
            }
        }

        // Tags
        if ( $event->le_tags ) {
            $tags = array_filter( array_map( 'trim', explode( ',', (string)$event->le_tags ) ) );
            if ( $tags ) {
                $out .= '<div class="pcp-life-tags">';
                foreach ( $tags as $t ) {
                    $out .= '<span class="pcp-life-tag">' . $h( $t ) . '</span> ';
                }
                $out .= '</div>';
            }
        }

        $out .= '</div>';
        return $out;
    }



    private function renderObservationDetails( $store, $event ): string {
        $h = function ( $s ) { return htmlspecialchars( (string)$s ); };
        $out = '';
        $refs = $store->getRefsForEvent( (int)$event->le_id );
        if ( $refs ) {
            $out .= '<div class="pcp-life-obs-refs">';
            foreach ( $refs as $r ) {
                $roleLabel = $r['role'] === 'subject' ? '' :
                             ( $r['role'] === 'cause' ? 'from ' :
                             ( $r['role'] === 'context' ? 'with ' : $r['role'] . ' ' ) );
                $cls = 'pcp-obs-refchip ' . ( $r['matched'] ? 'matched' : 'unmatched' );
                $body = $h( $roleLabel ) . $h( $r['text'] );
                if ( $r['matched'] && $r['type'] === 'med_page' && $r['ref_id'] ) {
                    $titleObj = \MediaWiki\Title\Title::newFromID( (int)$r['ref_id'] );
                    if ( $titleObj ) {
                        $url = htmlspecialchars( $titleObj->getLocalURL() );
                        $body = $h( $roleLabel ) . '<a href="' . $url . '">' . $h( $titleObj->getText() ) . '</a>';
                    }
                }
                $out .= '<span class="' . $cls . '">' . $body
                    . ' <small class="pcp-obs-reftype">[' . $h( $r['type'] ) . ']</small></span> ';
            }
            $out .= '</div>';
        }
        if ( !empty( $event->le_raw_text ) ) {
            $out .= '<div class="pcp-life-obs-raw"><em>typed: ' . $h( $event->le_raw_text ) . '</em></div>';
        }
        return $out;
    }

    private function renderDerivedCard( $event, callable $h ): string {
        $source = (string)( $event->_source ?? '' );
        $sourceLabel = [ 'diagnosis' => 'diagnosis', 'med' => 'medicine', 'xr' => 'experience report' ][ $source ] ?? $source;
        $dateText = $this->formatDate( $event );
        $out  = '<div class="pcp-life-card pcp-life-derived">';
        $out .= '<div class="pcp-life-card-header">';
        $out .= '<span class="pcp-life-date">' . $h( $dateText ) . '</span> ';
        $out .= '<span class="pcp-life-type-badge pcp-life-derived-badge">derived &middot; ' . $h( $sourceLabel ) . '</span>';
        $out .= '</div>';
        $out .= '<h3 class="pcp-life-title">' . $h( $event->le_title ) . '</h3>';
        if ( $event->le_body !== null && $event->le_body !== '' ) {
            $out .= '<div class="pcp-life-body">' . nl2br( $h( (string)$event->le_body ) ) . '</div>';
        }
        $out .= '</div>';
        return $out;
    }

    private function formatDate( $event ): string {
        // Prefer the structured widget payload if present
        if ( !empty( $event->le_date_struct ) ) {
            $s = json_decode( (string)$event->le_date_struct, true );
            if ( is_array( $s ) ) {
                $rendered = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatForDisplay( $s );
                if ( $rendered !== '' ) return $rendered;
            }
        }
        // Legacy fallback
        if ( $event->le_date_display !== null && (string)$event->le_date_display !== '' ) {
            return (string)$event->le_date_display;
        }
        $iso = (string)$event->le_date_iso;
        if ( $iso === '' ) return 'undated';
        $prec = (int)$event->le_date_precision;
        if ( $prec === LifeStoryStore::DP_YEAR )   return substr( $iso, 0, 4 );
        if ( $prec === LifeStoryStore::DP_MONTH )  return substr( $iso, 0, 7 );
        if ( $prec === LifeStoryStore::DP_DECADE ) return substr( $iso, 0, 3 ) . '0s';
        return $iso;
    }

    // ===== Form (add / edit) =====


    private function renderEpisodeForm( $out, $user, int $profileId, ?\stdClass $event ) {
        $h = function ( $s ) { return htmlspecialchars( (string)$s ); };
        $isEdit = (bool)$event;
        $store  = new LifeStoryStore();
        $struct = null;
        if ( $isEdit && $event->le_date_struct ) {
            $struct = json_decode( (string)$event->le_date_struct, true );
        }
        $structJson = $struct ? $h( json_encode( $struct ) ) : '';

        $epType    = $isEdit ? (string)$event->le_episode_type    : '';
        $epSubtype = $isEdit ? (string)$event->le_episode_subtype : '';
        $severity  = $isEdit && $event->le_severity !== null ? (float)$event->le_severity : null;
        $title     = $isEdit ? (string)$event->le_title : '';
        $body      = $isEdit ? (string)$event->le_body  : '';
        $vis       = $isEdit ? (int)$event->le_visibility : 0;

        $out->setPageTitle( $isEdit ? 'Edit episode' : 'New episode' );
        $token = $h( $user->getEditToken() );
        $action = $h( $this->getPageTitle()->getLocalURL() );

        // Prefilled types. User can also type a custom value.
        $types = [
            'mood', 'psychotic', 'anxiety', 'panic',
            'trauma response', 'dissociative', 'substance use',
            'eating', 'sleep disturbance', 'pain flare',
            'migraine', 'medication adjustment', 'hospitalization',
            'creative surge', 'spiritual / transcendent',
            'relationship crisis', 'grief', 'somatic', 'other',
        ];
        $subtypesByType = [
            'mood'      => [ 'depressive', 'manic', 'hypomanic', 'mixed', 'dysphoric', 'euthymic' ],
            'anxiety'   => [ 'generalized', 'social', 'health', 'separation' ],
            'psychotic' => [ 'manic with psychotic features', 'depressive with psychotic features', 'brief reactive', 'substance-induced' ],
            'eating'    => [ 'restrictive', 'binge', 'purge' ],
            'sleep disturbance' => [ 'insomnia', 'hypersomnia', 'fragmented' ],
        ];
        $subtypesJson = $h( json_encode( $subtypesByType ) );

        $out->addHTML( '<form method="post" enctype="multipart/form-data" action="' . $action . '" class="pcp-episode-form">' );
        $out->addHTML( '<input type="hidden" name="wpEditToken" value="' . $token . '">' );
        $out->addHTML( '<input type="hidden" name="action" value="save_episode">' );
        if ( $isEdit ) {
            $out->addHTML( '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">' );
        }

        // Type
        $out->addHTML( '<div class="pcp-form-row"><label>Type</label>' );
        $out->addHTML( '<select name="episode_type" class="pcp-ep-type">' );
        $out->addHTML( '<option value="">— pick —</option>' );
        foreach ( $types as $t ) {
            $sel = $epType === $t ? ' selected' : '';
            $out->addHTML( '<option value="' . $h( $t ) . '"' . $sel . '>' . $h( $t ) . '</option>' );
        }
        $out->addHTML( '</select></div>' );

        // Subtype (text; can be auto-suggested via subtypesJson by JS later)
        $out->addHTML( '<div class="pcp-form-row"><label>Subtype (optional)</label>' );
        $out->addHTML( '<input type="text" name="episode_subtype" class="pcp-ep-subtype" value="' . $h( $epSubtype ) . '" list="pcp-ep-subtypes" placeholder="e.g. depressive, manic, generalized">' );
        $out->addHTML( '<datalist id="pcp-ep-subtypes"></datalist>' );
        $out->addHTML( '</div>' );

        // Severity (0-100 slider per precision doctrine)
        $sv = $severity !== null ? $severity : 50;
        $out->addHTML( '<div class="pcp-form-row"><label>Severity (0 = none, 100 = extreme)</label>' );
        $out->addHTML( '<input type="range" name="severity" min="0" max="100" step="1" value="' . $h( (string)$sv ) . '" class="pcp-ep-severity" oninput="this.nextElementSibling.value=this.value">' );
        $out->addHTML( '<output>' . $h( (string)$sv ) . '</output>' );
        $out->addHTML( '</div>' );

        // Date range
        $out->addHTML( '<div class="pcp-form-row"><label>Date range</label>' );
        $out->addHTML( \MediaWiki\Extension\Pharmacopedia\DatePicker::renderWidget( 'event_date', $struct, [ 'lock_mode' => 'range' ] ) );
        $out->addHTML( '</div>' );

        // Title
        $out->addHTML( '<div class="pcp-form-row"><label>Title</label>' );
        $out->addHTML( '<input type="text" name="title" maxlength="200" value="' . $h( $title ) . '" placeholder="e.g. depressive episode after job loss">' );
        $out->addHTML( '</div>' );

        // Body
        $out->addHTML( '<div class="pcp-form-row"><label>Description (optional)</label>' );
        $out->addHTML( '<textarea name="body" rows="6" maxlength="20000">' . $h( $body ) . '</textarea>' );
        $out->addHTML( '</div>' );

        // Image (single; virus-scanned)
        $out->addHTML( '<div class="pcp-form-row"><label>Image (optional, JPEG/PNG/GIF/WebP; virus-scanned)</label>' );
        $out->addHTML( '<input type="file" name="image_file" accept="image/jpeg,image/png,image/gif,image/webp">' );
        $out->addHTML( '<input type="text" name="image_caption" placeholder="Caption (optional)">' );
        $out->addHTML( '</div>' );

        // Visibility
        $visLabels = [ 0 => 'private', 1 => 'public (attributed)', 2 => 'public (username only)', 3 => 'public (anonymous)' ];
        $out->addHTML( '<div class="pcp-form-row"><label>Visibility</label>' );
        $out->addHTML( '<select name="visibility">' );
        foreach ( $visLabels as $k => $lab ) {
            $sel = $vis === $k ? ' selected' : '';
            $out->addHTML( '<option value="' . (int)$k . '"' . $sel . '>' . $h( $lab ) . '</option>' );
        }
        $out->addHTML( '</select></div>' );

        $out->addHTML( '<div class="pcp-form-row">' );
        $out->addHTML( '<button type="submit" class="pcp-btn">' . ( $isEdit ? 'Save' : 'Create episode' ) . '</button> ' );
        $out->addHTML( '<a class="pcp-btn" href="' . $h( $this->getPageTitle()->getLocalURL() ) . '">Cancel</a>' );
        $out->addHTML( '</div></form>' );

        // Inline JS: populate subtype datalist when type changes.
        $out->addHTML( '<script>(function(){var m=' . $subtypesJson . ';var t=document.querySelector(".pcp-ep-type");var dl=document.getElementById("pcp-ep-subtypes");function refresh(){dl.innerHTML="";var arr=m[t.value]||[];arr.forEach(function(s){var o=document.createElement("option");o.value=s;dl.appendChild(o);});}if(t){t.addEventListener("change",refresh);refresh();}})();</script>' );
    }

    private function saveEpisode( $store, int $profileId, $request ): int {
        $isEdit = (int)$request->getVal( 'event_id', 0 );
        $struct = null;
        $rawDate = $request->getVal( 'event_date', '' );
        if ( $rawDate ) {
            $j = json_decode( (string)$rawDate, true );
            if ( is_array( $j ) ) $struct = $j;
        }
        $fields = [
            'episode_type'    => trim( (string)$request->getVal( 'episode_type', '' ) ),
            'episode_subtype' => trim( (string)$request->getVal( 'episode_subtype', '' ) ),
            'severity'        => $request->getVal( 'severity', '' ),
            'date_struct'     => $struct,
            'title'           => trim( (string)$request->getVal( 'title', '' ) ),
            'body'            => trim( (string)$request->getVal( 'body', '' ) ),
            'visibility'      => (int)$request->getVal( 'visibility', 0 ),
        ];
        if ( $isEdit ) {
            $store->updateEpisode( $isEdit, $fields );
            $eventId = $isEdit;
        } else {
            $eventId = $store->addEpisode( $profileId, $fields );
        }
        $this->maybeAcceptImageUpload( $store, $eventId, $profileId, $request );
        return $eventId;
    }

    private function renderEventForm( $out, $user, int $profileId, ?\stdClass $event ) {
        $h = function ( $s ) { return htmlspecialchars( (string)$s ); };
        $isEdit = $event !== null;
        $action = $this->getPageTitle()->getLocalURL();
        $token  = $h( $user->getEditToken() );

        $type = $isEdit ? (int)$event->le_type : LifeStoryStore::TYPE_STORY;
        $title = $isEdit ? (string)$event->le_title : '';
        $body  = $isEdit ? (string)( $event->le_body ?? '' ) : '';
        $tags  = $isEdit ? (string)( $event->le_tags ?? '' ) : '';
        $vis   = $isEdit ? (int)$event->le_visibility : 0;

        $out->addHTML( '<form method="post" action="' . $h( $action ) . '" enctype="multipart/form-data" class="pcp-life-form">' );
        $out->addHTML( '<input type="hidden" name="title" value="' . $h( $this->getPageTitle()->getPrefixedDBkey() ) . '">' );
        $out->addHTML( '<input type="hidden" name="wpEditToken" value="' . $token . '">' );
        $out->addHTML( '<input type="hidden" name="action" value="save_event">' );
        if ( $isEdit ) {
            $out->addHTML( '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">' );
        }

        $out->addHTML( '<fieldset class="pcp-prof-section"><legend>' . ( $isEdit ? 'Edit event' : 'New event' ) . '</legend>' );

        // ----- Date widget (point / range / possibility) -----
        $initialStruct = $isEdit ? LifeStoryStore::eventStruct( $event ) : null;
        $out->addHTML( '<div class="pcp-life-form-row pcp-life-form-date">' );
        $out->addHTML( '<label>Date</label>' );
        $out->addHTML( \MediaWiki\Extension\Pharmacopedia\DatePicker::renderWidget( 'event_date', $initialStruct ) );
        $out->addHTML( '</div>' );

        // ----- Type / Title -----
        $out->addHTML( '<div class="pcp-life-form-row"><label>Type <select name="event_type">' );
        foreach ( [ 0=>'Story', 1=>'Image-primary', 2=>'Keyframe (personality snapshot)' ] as $tk => $tl ) {
            $sel = $type === $tk ? ' selected' : '';
            $out->addHTML( '<option value="' . $tk . '"' . $sel . '>' . $h( $tl ) . '</option>' );
        }
        $out->addHTML( '</select></label></div>' );

        $out->addHTML( '<div class="pcp-life-form-row"><label>Title <input type="text" name="title_text" required maxlength="200" value="' . $h( $title ) . '"></label></div>' );
        $out->addHTML( '<div class="pcp-life-form-row"><label>Body / story<br><textarea name="body" rows="6" maxlength="20000">' . $h( $body ) . '</textarea></label></div>' );

        // ----- Tags / Visibility -----
        $out->addHTML( '<div class="pcp-life-form-row"><label>Tags (comma-separated) <input type="text" name="tags" value="' . $h( $tags ) . '" placeholder="work, relationships, mental-health"></label></div>' );
        $out->addHTML( '<div class="pcp-life-form-row"><label>Visibility <select name="visibility">' );
        foreach ( [ 0=>'Private (default)', 1=>'Public (default attribution)', 2=>'Public (username)', 3=>'Public (no byline)' ] as $vk => $vl ) {
            $sel = $vis === $vk ? ' selected' : '';
            $out->addHTML( '<option value="' . $vk . '"' . $sel . '>' . $h( $vl ) . '</option>' );
        }
        $out->addHTML( '</select></label></div>' );

        $out->addHTML( '</fieldset>' );

        // ----- Image upload -----
        $out->addHTML( '<fieldset class="pcp-prof-section"><legend>Attach image (optional)</legend>' );
        $out->addHTML( '<div class="pcp-life-form-row">' );
        $out->addHTML( '<input type="file" name="image_file" accept="image/jpeg,image/png,image/gif,image/webp"> ' );
        $out->addHTML( '<label>Caption <input type="text" name="image_caption" maxlength="500"></label>' );
        $out->addHTML( '</div>' );
        $out->addHTML( '<p class="pcp-prof-help"><small>Max 10 MB per image, 500 MB per user. JPEG / PNG / GIF / WebP only. Stored privately; only visible per this event\'s visibility setting.</small></p>' );

        if ( $isEdit ) {
            $images = ( new LifeStoryStore() )->getImagesForEvent( (int)$event->le_id );
            if ( $images ) {
                $out->addHTML( '<div class="pcp-life-existing-images"><strong>Existing images:</strong><ul>' );
                foreach ( $images as $im ) {
                    $url = htmlspecialchars( SpecialPage::getTitleFor( 'LifeImage',
                        (int)$event->le_id . '/' . (int)$im->li_id )->getLocalURL() );
                    $delUrl = htmlspecialchars( $action );
                    $out->addHTML( '<li><a href="' . $url . '">' . $h( $im->li_orig_name ) . '</a> '
                        . '(' . round( $im->li_size_bytes / 1024 ) . ' KB) '
                        . '<form method="post" action="' . $delUrl . '" style="display:inline">'
                        . '<input type="hidden" name="title" value="' . $h( $this->getPageTitle()->getPrefixedDBkey() ) . '">'
                        . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
                        . '<input type="hidden" name="action" value="delete_image">'
                        . '<input type="hidden" name="image_id" value="' . (int)$im->li_id . '">'
                        . '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">'
                        . '<button type="submit" class="pcp-life-img-del" onclick="return confirm(\'Delete this image?\')">delete</button>'
                        . '</form></li>' );
                }
                $out->addHTML( '</ul></div>' );
            }
        }
        $out->addHTML( '</fieldset>' );

        // ----- Keyframe traits -----
        $out->addHTML( '<fieldset class="pcp-prof-section pcp-life-kf-fs"><legend>Keyframe traits (only used when type = keyframe)</legend>' );
        $out->addHTML( '<p class="pcp-prof-help"><small>Each row is one trait. Use built-in namespaces (ocean / pid5bf / catq / raadsr) for the named assessments, or "custom" with a label and min/max for your own scales. Mark as estimated if this is a retrospective guess.</small></p>' );
        $existing = $isEdit ? ( new LifeStoryStore() )->getTraitsForEvent( (int)$event->le_id ) : [];
        $maxRows = max( 6, count( $existing ) + 2 );
        $out->addHTML( '<table class="pcp-life-kf-table"><thead><tr>'
            . '<th>namespace</th><th>key</th><th>label</th><th>value</th><th>min</th><th>max</th><th>estimated</th>'
            . '</tr></thead><tbody>' );
        for ( $i = 0; $i < $maxRows; $i++ ) {
            $t = $existing[ $i ] ?? null;
            $ns    = $t ? $h( $t->lt_namespace ) : '';
            $key   = $t ? $h( $t->lt_key ) : '';
            $label = $t ? $h( $t->lt_label ?? '' ) : '';
            $val   = $t ? $h( rtrim( rtrim( (string)$t->lt_value_num, '0' ), '.' ) ) : '';
            $min   = $t && $t->lt_min !== null ? $h( rtrim( rtrim( (string)$t->lt_min, '0' ), '.' ) ) : '';
            $max   = $t && $t->lt_max !== null ? $h( rtrim( rtrim( (string)$t->lt_max, '0' ), '.' ) ) : '';
            $est   = $t && (int)$t->lt_estimated ? ' checked' : '';
            $out->addHTML(
                '<tr>'
                . '<td><input type="text" name="kf[' . $i . '][namespace]" value="' . $ns . '" placeholder="custom" maxlength="16"></td>'
                . '<td><input type="text" name="kf[' . $i . '][key]" value="' . $key . '" placeholder="anxiety" maxlength="64"></td>'
                . '<td><input type="text" name="kf[' . $i . '][label]" value="' . $label . '" maxlength="128"></td>'
                . '<td><input type="number" name="kf[' . $i . '][value]" value="' . $val . '" step="any"></td>'
                . '<td><input type="number" name="kf[' . $i . '][min]" value="' . $min . '" step="any"></td>'
                . '<td><input type="number" name="kf[' . $i . '][max]" value="' . $max . '" step="any"></td>'
                . '<td><input type="checkbox" name="kf[' . $i . '][estimated]" value="1"' . $est . '></td>'
                . '</tr>'
            );
        }
        $out->addHTML( '</tbody></table>' );
        $out->addHTML( '</fieldset>' );

        // ----- Submit -----
        $out->addHTML( '<div class="pcp-life-form-actions">' );
        $out->addHTML( '<button type="submit" class="pcp-btn pcp-btn-primary">' . ( $isEdit ? 'Save changes' : 'Add event' ) . '</button> ' );
        $out->addHTML( '<a href="' . $h( $this->getPageTitle()->getLocalURL() ) . '" class="pcp-btn">Cancel</a>' );
        $out->addHTML( '</div></form>' );

        // ----- Delete (edit mode only) -----
        if ( $isEdit ) {
            $out->addHTML( '<form method="post" action="' . $h( $action ) . '" class="pcp-life-delete-form">'
                . '<input type="hidden" name="title" value="' . $h( $this->getPageTitle()->getPrefixedDBkey() ) . '">'
                . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
                . '<input type="hidden" name="action" value="delete_event">'
                . '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">'
                . '<button type="submit" class="pcp-btn pcp-btn-danger" onclick="return confirm(\'Delete this event and its images?\')">Delete event</button>'
                . '</form>' );
        }
    }

    // ===== Save handler =====

    private function saveEvent( $store, int $profileId, $request ): int {
        // Date (from pcp-date-input widget)
        $dateJson = (string)$request->getVal( 'event_date', '' );
        $struct = \MediaWiki\Extension\Pharmacopedia\DatePicker::parseSubmitted( $dateJson );
        $dateIso     = $struct ? \MediaWiki\Extension\Pharmacopedia\DatePicker::sortKeyIso( $struct ) : null;
        $dispText    = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatForDisplay( $struct );
        $precision   = self::precisionFromStruct( $struct );
        $structJson  = $struct ? json_encode( $struct, JSON_UNESCAPED_UNICODE ) : null;

        $fields = [
            'date_iso'       => $dateIso,
            'date_precision' => $precision,
            'date_display'   => $dispText !== '' ? $dispText : null,
            'date_struct'    => $structJson,
            'type'           => (int)$request->getVal( 'event_type', 0 ),
            'title'          => trim( (string)$request->getVal( 'title_text', '' ) ),
            'body'           => trim( (string)$request->getVal( 'body', '' ) ) ?: null,
            'visibility'     => (int)$request->getVal( 'visibility', 0 ),
            'tags'           => trim( (string)$request->getVal( 'tags', '' ) ) ?: null,
        ];

        $eventId = (int)$request->getVal( 'event_id', 0 );
        if ( $eventId > 0 ) {
            $existing = $store->getEvent( $eventId );
            if ( !$existing || (int)$existing->le_profile_id !== $profileId ) {
                throw new \RuntimeException( 'Event not found or not yours.' );
            }
            $store->updateEvent( $eventId, $fields );
        } else {
            $eventId = $store->addEvent( $profileId, $fields );
        }

        // Keyframe traits
        $kf = $request->getArray( 'kf' ) ?: [];
        $traits = [];
        foreach ( $kf as $row ) {
            if ( !is_array( $row ) ) continue;
            $ns  = trim( (string)( $row['namespace'] ?? '' ) );
            $key = trim( (string)( $row['key'] ?? '' ) );
            $val = trim( (string)( $row['value'] ?? '' ) );
            if ( $ns === '' || $key === '' || $val === '' ) continue;
            if ( !is_numeric( $val ) ) continue;
            $traits[] = [
                'namespace' => $ns,
                'key'       => $key,
                'label'     => trim( (string)( $row['label'] ?? '' ) ) ?: null,
                'value'     => (float)$val,
                'min'       => is_numeric( $row['min'] ?? '' ) ? (float)$row['min'] : null,
                'max'       => is_numeric( $row['max'] ?? '' ) ? (float)$row['max'] : null,
                'estimated' => !empty( $row['estimated'] ),
            ];
        }
        $store->setTraits( $eventId, $traits );

        // Image upload
        $this->maybeAcceptImageUpload( $store, $profileId, $eventId );

        return $eventId;
    }

    private function maybeAcceptImageUpload( $store, int $profileId, int $eventId ): void {
        $upload = $this->getRequest()->getUpload( 'image_file' );
        if ( !$upload || !$upload->exists() || $upload->getSize() <= 0 ) return;
        $tmp = (string)$upload->getTempName();
        $orig = (string)$upload->getName() ?: 'image';
        $size = (int)$upload->getSize();
        if ( $tmp === '' || !is_uploaded_file( $tmp ) ) {
            throw new \RuntimeException( 'Invalid upload.' );
        }
        if ( $size > LifeStoryStore::MAX_IMAGE_BYTES_PER_FILE ) {
            throw new \RuntimeException( 'Image exceeds 10 MB limit.' );
        }
        $finfo = new \finfo( FILEINFO_MIME_TYPE );
        $mime = (string)$finfo->file( $tmp );
        $allowed = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' ];
        if ( !isset( $allowed[ $mime ] ) ) {
            throw new \RuntimeException( 'Unsupported image type: ' . $mime );
        }
        $ext = $allowed[ $mime ];

        // Confirm the file actually parses as an image (catches polyglots /
        // truncated/corrupted uploads that finfo MIME-sniffing would miss).
        $info = @getimagesize( $tmp );
        if ( $info === false || empty( $info[0] ) || empty( $info[1] ) ) {
            throw new \RuntimeException( 'File is not a parseable image.' );
        }

        // Antivirus scan (no-op if scanner unavailable; throws on hit).
        AntivirusHelper::scan( $tmp );

        // Per-user cap
        $totalBytes = $store->totalImageBytesForProfile( $profileId );
        if ( $totalBytes + $size > LifeStoryStore::MAX_IMAGE_BYTES_PER_USER ) {
            throw new \RuntimeException( 'Per-user 500 MB image quota exceeded.' );
        }

        $dir = LifeStoryStore::eventDirFor( $profileId, $eventId );
        if ( !is_dir( $dir ) && !@mkdir( $dir, 0750, true ) ) {
            throw new \RuntimeException( 'Could not create storage dir.' );
        }
        $hash = substr( hash( 'sha256', $orig . microtime() . random_bytes( 8 ) ), 0, 24 );
        $path = $dir . '/' . $hash . '.' . $ext;
        if ( !@move_uploaded_file( $tmp, $path ) ) {
            throw new \RuntimeException( 'Could not save uploaded image.' );
        }
        @chmod( $path, 0640 );

        $store->addImage( $eventId, [
            'file_path'  => $path,
            'orig_name'  => $orig,
            'mime'       => $mime,
            'size_bytes' => $size,
            'caption'    => trim( (string)$this->getRequest()->getVal( 'image_caption', '' ) ) ?: null,
        ] );
    }

    private function banner( string $title, string $body, bool $error = false ): string {
        $cls = $error ? 'pcp-banner pcp-banner-error' : 'pcp-banner';
        return '<div class="' . $cls . '" style="margin-bottom:1em;">' .
            '<span class="pcp-banner__title">' . htmlspecialchars( $title ) . '</span>' .
            '<span class="pcp-banner__body">' . htmlspecialchars( $body ) . '</span></div>';
    }

    private static function precisionFromStruct( ?array $struct ): int {
        if ( !$struct ) return LifeStoryStore::DP_UNKNOWN;
        if ( ( $struct['kind'] ?? '' ) === 'point' && !empty( $struct['point']['parsed']['precision'] ) ) {
            $map = [
                'day'        => LifeStoryStore::DP_DAY,
                'month'      => LifeStoryStore::DP_MONTH,
                'year'       => LifeStoryStore::DP_YEAR,
                'season'     => LifeStoryStore::DP_MONTH,
                'decade'     => LifeStoryStore::DP_DECADE,
                'approx-age' => LifeStoryStore::DP_APPROX_AGE,
            ];
            return $map[ $struct['point']['parsed']['precision'] ] ?? LifeStoryStore::DP_DAY;
        }
        return LifeStoryStore::DP_DAY;
    }

        public function doesWrites() { return true; }
    protected function getGroupName() { return 'users'; }
}
