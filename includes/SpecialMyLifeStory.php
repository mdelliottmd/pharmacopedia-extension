<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Special:MyLifeStory – owner-edit timeline.
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
        // Page-root quick-add + add-event/add-episode chips: only on the bare
        // /MyLifeStory page, not on subpages (edit-observation, edit-episode,
        // add, add-episode, edit/N) NOR on the ?edit_event=N query-param edit
        // route, where the inline edit form is the focus.
        $_isEditView = ( trim( (string)$par ) !== '' )
            || ( (int)$this->getRequest()->getVal( 'edit_event', 0 ) > 0 );
        if ( !$_isEditView ) {
            $urlEvt = htmlspecialchars( $this->getPageTitle( 'add' )->getLocalURL() );
            $urlEpi = htmlspecialchars( $this->getPageTitle( 'add-episode' )->getLocalURL() );
            $out->addHTML( '<div class="pcp-obs-quickadd">' .
                '<h3>Quick add: log an entry</h3>' .
                '<div class="pcp-life-quickadd-typepicker" role="radiogroup" aria-label="Entry type">' .
                    '<button type="button" data-type="observation" class="active" role="radio" aria-checked="true">Observation</button>' .
                    '<button type="button" data-type="event" role="radio" aria-checked="false">Event</button>' .
                    '<button type="button" data-type="episode" role="radio" aria-checked="false">Episode</button>' .
                    '<button type="button" data-type="story" role="radio" aria-checked="false">Story</button>' .
                '</div>' .
                '<input type="hidden" name="entry_type" class="pcp-life-quickadd-typepicker-value" value="observation">' .
                '<textarea class="pcp-obs-input" rows="2" placeholder="e.g. anxiety from bupropion in jan 2020; or no insomnia while on melatonin in summer 2023"></textarea>' .
                '<div class="pcp-obs-preview"></div>' .
                '<button type="button" class="pcp-btn pcp-obs-submit">Add to timeline</button>' .
                '<div class="pcp-life-add-chips">' .
                    '<a class="pcp-life-add-chip" href="' . $urlEvt . '">+ Add event <span class="pcp-life-add-chip-sub">single moment</span></a>' .
                    '<a class="pcp-life-add-chip" href="' . $urlEpi . '">+ Add episode <span class="pcp-life-add-chip-sub">span of time</span></a>' .
                '</div>' .
            '</div>' );
        }
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
            $action = $request->getVal( 'pcp_action', '' );
            if ( $action === 'duplicate_event' ) {
                $eid = (int)$request->getVal( 'event_id', 0 );
                $src = $store->getEvent( $eid );
                if ( $src && (int)$src->le_profile_id === $profileId ) {
                    $newId = $store->duplicateEvent( $eid, $profileId );
                    $type  = (int)$src->le_type;
                    if ( $type === LifeStoryStore::TYPE_EPISODE ) {
                        $out->redirect( $this->getPageTitle( 'edit-episode/' . $newId )->getLocalURL() );
                    } elseif ( $type === LifeStoryStore::TYPE_OBSERVATION ) {
                        $out->redirect( $this->getPageTitle( 'edit-observation/' . $newId )->getLocalURL() );
                    } else {
                        $out->redirect( $this->getPageTitle()->getLocalURL( [ 'edit_event' => $newId ] ) );
                    }
                    return;
                }
            }
            if ( $action === 'save_observation' ) {
                $eventId = $this->saveObservation( $store, $profileId, $request );
                $out->redirect( $this->getPageTitle()->getLocalURL( [ 'saved' => $eventId ] ) );
                return;
            }
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
            if ( $action === 'convert_type' ) {
                $eid = (int)$request->getVal( 'event_id', 0 );
                $newType = (int)$request->getVal( 'new_type', -1 );
                $event = $store->getEvent( $eid );
                if ( $event && (int)$event->le_profile_id === $profileId
                     && in_array( $newType, [ 0, 1, 3, 4 ], true ) ) {
                    $dbw = \MediaWiki\MediaWikiServices::getInstance()
                        ->getConnectionProvider()->getPrimaryDatabase();
                    $dbw->newUpdateQueryBuilder()
                        ->update( 'pcp_life_events' )
                        ->set( [ 'le_type' => $newType, 'le_updated' => $dbw->timestamp() ] )
                        ->where( [ 'le_id' => $eid ] )
                        ->caller( __METHOD__ )
                        ->execute();
                    switch ( $newType ) {
                        case 4:
                            $out->redirect( $this->getPageTitle( 'edit-episode/' . $eid )->getLocalURL() ); break;
                        case 3:
                            $out->redirect( $this->getPageTitle( 'edit-observation/' . $eid )->getLocalURL() ); break;
                        default:
                            $out->redirect( $this->getPageTitle()->getLocalURL( [ 'edit_event' => $eid ] ) );
                    }
                    return;
                }
                $out->redirect( $this->getPageTitle()->getFullURL() );
                return;
            }
            if ( $action === 'set_visibility' ) {
                $eid = (int)$request->getVal( 'event_id', 0 );
                $vis = max( 0, min( 3, (int)$request->getVal( 'visibility', 0 ) ) );
                $event = $store->getEvent( $eid );
                if ( $event && (int)$event->le_profile_id === $profileId ) {
                    $dbw = \MediaWiki\MediaWikiServices::getInstance()
                        ->getConnectionProvider()->getPrimaryDatabase();
                    $dbw->newUpdateQueryBuilder()
                        ->update( 'pcp_life_events' )
                        ->set( [ 'le_visibility' => $vis, 'le_updated' => $dbw->timestamp() ] )
                        ->where( [ 'le_id' => $eid ] )
                        ->caller( __METHOD__ )
                        ->execute();
                }
                if ( $request->getVal( 'ajax' ) === '1' ) {
                    $out->disable();
                    header( 'Content-Type: application/json' );
                    echo json_encode( [ 'ok' => true, 'visibility' => $vis ] );
                    return;
                }
                $out->redirect( $this->getPageTitle()->getFullURL() );
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
            $savedId = (int)$request->getVal( 'saved' );
            if ( $savedId > 0 ) {
                $savedEvent = $store->getEvent( $savedId );
                $tmap = [ 0 => 'stories', 1 => 'events', 3 => 'observations', 4 => 'episodes' ];
                $savedGroup = $savedEvent ? ( $tmap[ (int)$savedEvent->le_type ] ?? 'events' ) : 'events';
                $out->addHTML(
                    '<script>document.addEventListener("DOMContentLoaded",function(){' .
                    'document.querySelectorAll(".pcp-life-view-toggle").forEach(function(b){b.classList.remove("active");});' .
                    'var lb=document.querySelector(\'.pcp-life-view-toggle[data-view="list"]\');' .
                    'if(lb)lb.classList.add("active");' .
                    'document.querySelectorAll(".pcp-life-view").forEach(function(v){v.classList.remove("active");});' .
                    'var lp=document.querySelector(\'.pcp-life-view[data-view="list"]\');' .
                    'if(lp)lp.classList.add("active");' .
                    'var fcb=document.querySelector(\'.pcp-life-timeline-group-toggle[value="' . $savedGroup . '"]\');' .
                    'if(fcb && !fcb.checked){fcb.checked=true;fcb.dispatchEvent(new Event("change",{bubbles:true}));}' .
                    'setTimeout(function(){' .
                    'var el=document.querySelector(\'.pcp-life-view[data-view="list"] .pcp-life-card[data-event-id="' . $savedId . '"]\');' .
                    'if(el){el.scrollIntoView({behavior:"smooth",block:"center"});' .
                    'el.classList.add("pcp-life-card-flash");' .
                    'setTimeout(function(){el.classList.remove("pcp-life-card-flash");},1800);}' .
                    '},120);' .
                    '});</script>'
                );
            }
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
        if ( preg_match( '#^edit-observation/(\d+)$#', $par, $om ) ) {
            $event = $store->getEvent( (int)$om[1] );
            if ( $event && (int)$event->le_profile_id === $profileId
                 && (int)$event->le_type === LifeStoryStore::TYPE_OBSERVATION ) {
                $this->renderObservationForm( $out, $user, $profileId, $event );
                return;
            }
        }
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

            // For 'custom', each trait may have its own min/max scale – normalize each to 0-1
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
        $palette = [ '#c4b5fd', '#86efac', '#fda4af', '#fdba74', '#7dd3fc', '#a78bfa', '#f0abfc', '#34d399', '#fb923c', '#60a5fa', '#facc15', '#f472b6' ];
        $groups = []; $items = []; $idx = 0;
        foreach ( $ts as $ns => $byKey ) {
            foreach ( $byKey as $key => $points ) {
                if ( !$points ) continue;
                // Compute observed (or provided) min/max for normalization.
                $vals = array_map( function ( $p ) { return (float)$p['value']; }, $points );
                $omin = min( $vals );
                $omax = max( $vals );
                $min  = $points[0]['min'] !== null ? (float)$points[0]['min'] : $omin;
                $max  = $points[0]['max'] !== null ? (float)$points[0]['max'] : $omax;
                if ( $max <= $min ) { $min = $omin - 1; $max = $omax + 1; }  // avoid div-by-0
                $gid = $ns . ':' . $key;
                $color = $palette[ $idx % count( $palette ) ];
                $idx++;
                $label = $points[0]['label'] ?? $key;
                $groups[] = [
                    'id'      => $gid,
                    'content' => $ns . ' / ' . $label,
                    'style'   => 'stroke: ' . $color . '; stroke-width: 2.5; fill: ' . $color . ';',
                    'options' => [
                        'drawPoints' => [ 'size' => 7, 'style' => 'circle' ],
                        'shaded'     => false,
                    ],
                ];
                foreach ( $points as $p ) {
                    if ( empty( $p['date'] ) || !isset( $p['value'] ) ) continue;
                    $rawV = (float)$p['value'];
                    $normY = max( 0, min( 100, ( ( $rawV - $min ) / ( $max - $min ) ) * 100 ) );
                    $items[] = [
                        'x'       => (string)$p['date'],
                        'y'       => $normY,
                        'group'   => $gid,
                        'label'   => $rawV . ' (range ' . $min . '..' . $max . ')',
                        'valence' => isset( $p['valence'] ) ? $p['valence'] : null,
                        'tvs'     => isset( $p['traitvstate'] ) ? $p['traitvstate'] : null,
                    ];
                }
            }
        }
        // Compute tight y-axis range from actual data to avoid the -10/110 default padding.
        $yMin = INF; $yMax = -INF;
        foreach ( $items as $it ) {
            if ( isset( $it['y'] ) ) {
                $yMin = min( $yMin, (float)$it['y'] );
                $yMax = max( $yMax, (float)$it['y'] );
            }
        }
        if ( $yMin === INF ) { $yMin = 0; $yMax = 1; }
        $yRange = max( 1.0, $yMax - $yMin );
        $payload = [
            'groups' => $groups,
            'items'  => $items,
            'yRange' => [ 'min' => $yMin - $yRange * 0.08, 'max' => $yMax + $yRange * 0.08 ],
        ];
        $out->addHTML( '<script>window.PCP_LIFE_GRAPH_DATA = ' . json_encode( $payload, JSON_UNESCAPED_SLASHES ) . ';</script>' );
        // The mount + legend are emitted INSIDE the visual pane wrapper –
        // placed BEFORE the timeline mount in renderTimelineMount.
    }




    /** Count this profile's free-text refs that are not dismissed. */
    private function countUnmatchedRefs( int $profileId ): int {
        $dbr = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $row = $dbr->newSelectQueryBuilder()
            ->select( 'COUNT(*) AS n' )
            ->from( 'pcp_life_event_refs', 'r' )
            ->join( 'pcp_life_events', 'e', 'e.le_id = r.ler_event_id' )
            ->where( [
                'e.le_profile_id' => $profileId,
                'r.ler_ref_type'  => 'free',
            ] )
            ->andWhere( 'r.ler_role NOT LIKE ' . $dbr->addQuotes( '%:dismissed' ) )
            ->caller( __METHOD__ )
            ->fetchRow();
        return $row ? (int)$row->n : 0;
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
        // 'keyframes' filter dropped post-collapse: trait-bearing rows now
        // live in 'observations' (le_type=3). Any orphan legacy type=2 rows
        // remain visible (no filter -> currentVisible[gid] is undefined ->
        // !== false -> show).
        $groups = [
            'episodes'     => [ 'label' => 'Episodes',     'on' => true  ],
            'events'       => [ 'label' => 'Events',       'on' => true  ],
            'stories'      => [ 'label' => 'Stories',      'on' => true  ],
            'observations' => [ 'label' => 'Observations', 'on' => false ],
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
                . '<button type="button" class="pcp-life-view-toggle" data-view="visual">Visual timeline</button>'
                . '<button type="button" class="pcp-life-view-toggle" data-view="list">Card list</button>'
            . '</div>'
            . '<div class="pcp-life-timeline-controls">'
                . $togglesHtml
                . '<span class="pcp-life-toolbar-spacer"></span>'
                . '<span class="pcp-life-timeline-zoom-icon" aria-hidden="true"><svg viewBox="0 0 16 16" width="27" height="27"><circle cx="7" cy="7" r="4.5" fill="#000" stroke="#fff" stroke-width="0.9"/><line x1="10.2" y1="10.2" x2="14" y2="14" stroke="#fff" stroke-width="1.7" stroke-linecap="round"/></svg></span>'
                . '<button type="button" class="pcp-life-timeline-zoomout" title="Zoom out">&minus;</button>'
                . '<button type="button" class="pcp-life-timeline-zoomin" title="Zoom in">+</button>'
                . '<button type="button" class="pcp-life-timeline-fit" title="Fit visible groups">Fit visible</button>'
                . '<button type="button" class="pcp-life-timeline-fit-all" title="Fit everything (incl. derived/keyframes)">Fit everything</button>'
            . '</div>'
        );
        $out->addHTML(
            '<div class="pcp-life-view" data-view="visual">'
                . '<div class="pcp-life-graph-legend"></div>'
                . '<div class="pcp-life-overlay-wrap">'
                    . '<div id="pcp-life-timeline-mount"></div>'
                    . '<div id="pcp-life-graph-mount" class="pcp-life-graph-overlay"></div>'
                . '</div>'
            . '</div>'
        );
        $this->renderTraitGraph( $out, $store, $profileId );
        $out->addHTML( '<div class="pcp-life-view" data-view="list">' );
    }

    private function closeListView( $out ): void {
        $out->addHTML( '</div>' );
    }

    private function timelineItemForStored( $e, $linkPage ): array {
        $type = (int)$e->le_type;
        $group = [ 0=>'stories', 1=>'events', 3=>'observations', 4=>'episodes' ][ $type ] ?? 'events';
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
        if ( $type === 4 ) {
            $editUrl = $linkPage->getSubpage( 'edit-episode/' . (int)$e->le_id )->getLocalURL();
        } elseif ( $type === 3 ) {
            $editUrl = $linkPage->getSubpage( 'edit-observation/' . (int)$e->le_id )->getLocalURL();
        } else {
            $editUrl = $linkPage->getLocalURL( [ 'edit_event' => (int)$e->le_id ] );
        }
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
        $typeLabel = [ 0=>'story', 1=>'event', 2=>'keyframe', 3=>'observation', 4=>'episode' ][ (int)$event->le_type ] ?? 'story';
        $visIcon   = [ 0=>'🔒', 1=>'👁', 2=>'🆔', 3=>'🎭' ][ (int)$event->le_visibility ] ?? '🔒';
        $dateText  = $this->formatDate( $event );

        $token = $this->getUser()->getEditToken();
        $out = '<div class="pcp-life-card pcp-life-type-' . $h( $typeLabel ) . '" data-event-id="' . (int)$event->le_id . '">';
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
        // Clickable visibility cycle (JS-enhanced; falls back to button form post).
        $visLabels = [ 0 => 'private', 1 => 'public-default', 2 => 'public-username', 3 => 'public-anonymous' ];
        $visCur = (int)$event->le_visibility;
        $out .= '<button type="button" class="pcp-life-vis-toggle" data-event-id="' . (int)$event->le_id . '" data-vis="' . $visCur . '" title="' . $h( $visLabels[ $visCur ] ?? '?' ) . ' (click to cycle)">' . $visIcon . '</button>';
        if ( $editable ) {
            // Inline delete button (X). Submits a tiny form so it works without JS,
            // and JS adds a confirm() before submit.
            $delAction = $this->getPageTitle()->getLocalURL();
            $out .= ' <form method="post" action="' . $h( $delAction ) . '" class="pcp-life-card-delete-form" data-confirm="Delete this event? This cannot be undone.">'
                . '<input type="hidden" name="pcp_title" value="' . $h( $this->getPageTitle()->getPrefixedDBkey() ) . '">'
                . '<input type="hidden" name="wpEditToken" value="' . $h( $token ) . '">'
                . '<input type="hidden" name="pcp_action" value="delete_event">'
                . '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">'
                . '<button type="submit" class="pcp-life-card-delete" title="Delete this event">&times;</button>'
                . '</form>';
        }
        if ( $editable ) {
            if ( (int)$event->le_type === LifeStoryStore::TYPE_EPISODE ) {
                $editUrl = htmlspecialchars( $this->getPageTitle( 'edit-episode/' . (int)$event->le_id )->getLocalURL() );
            } elseif ( (int)$event->le_type === LifeStoryStore::TYPE_OBSERVATION ) {
                $editUrl = htmlspecialchars( $this->getPageTitle( 'edit-observation/' . (int)$event->le_id )->getLocalURL() );
            } elseif ( (int)$event->le_type === LifeStoryStore::TYPE_STORY
                       && !empty( $event->le_page_id ) ) {
                $storyTitle = \MediaWiki\Title\Title::newFromID( (int)$event->le_page_id );
                $editUrl = $storyTitle
                    ? htmlspecialchars( $storyTitle->getLocalURL( [ 'action' => 'edit' ] ) )
                    : htmlspecialchars( $this->getPageTitle()->getLocalURL( [ 'edit_event' => (int)$event->le_id ] ) );
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
        if ( (int)$event->le_type === LifeStoryStore::TYPE_STORY && !empty( $event->le_page_id ) ) {
            $storyTitle = \MediaWiki\Title\Title::newFromID( (int)$event->le_page_id );
            if ( $storyTitle ) {
                $out .= '<div class="pcp-life-story-link">'
                    . '<a href="' . htmlspecialchars( $storyTitle->getLocalURL() ) . '">'
                    . 'View full story →</a>'
                    . '</div>';
            }
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
        // Show trait readout on keyframe-type (legacy) AND observation-type cards.
        if ( in_array( (int)$event->le_type, [ LifeStoryStore::TYPE_KEYFRAME, LifeStoryStore::TYPE_OBSERVATION ], true ) ) {
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



    private function renderObservationForm( $out, $user, int $profileId, \stdClass $event ): void {
        $h = function ( $s ) { return htmlspecialchars( (string)$s ); };
        $store  = new LifeStoryStore();
        $struct = null;
        if ( $event->le_date_struct ) {
            $struct = json_decode( (string)$event->le_date_struct, true );
        }
        $structJson = $struct ? $h( json_encode( $struct ) ) : '';
        $rawText  = (string)( $event->le_raw_text ?? '' );
        $polarity = $event->le_polarity !== null ? (int)$event->le_polarity : null;
        $refs     = $store->getRefsForEvent( (int)$event->le_id );

        $out->setPageTitle( 'Edit observation' );
        $token  = $h( $user->getEditToken() );
        $action = $h( $this->getPageTitle()->getLocalURL() );
        $out->addModules( [ 'ext.pharmacopedia.datepicker', 'ext.pharmacopedia.observation' ] );

        $this->renderTypeSwitcher( $out, $event );
        $out->addHTML( '<form method="post" action="' . $action . '" class="pcp-obs-edit-form">' );
        $out->addHTML( '<input type="hidden" name="wpEditToken" value="' . $token . '">' );
        $out->addHTML( '<input type="hidden" name="pcp_action" value="save_observation">' );
        $out->addHTML( '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">' );

        // Raw text (with live re-parse preview).
        $out->addHTML( '<div class="pcp-form-row"><label>Observation (free text)</label>' );
        $out->addHTML( '<div class="pcp-obs-quickadd">' );
        $out->addHTML( '<textarea name="text" rows="3" class="pcp-obs-input" placeholder="(optional)">' . $h( $rawText ) . '</textarea>' );
        $out->addHTML( '<div class="pcp-obs-preview"></div>' );
        $out->addHTML( '</div></div>' );

        // Override polarity (3-state radio).
        $out->addHTML( '<div class="pcp-form-row"><label>Polarity override (optional)</label>' );
        foreach ( [ '' => 'auto (from text)', '1' => 'did experience', '0' => 'did NOT experience' ] as $val => $lab ) {
            $chk = ( ( $val === '' && $polarity === null ) || ( (string)$polarity === $val ) ) ? ' checked' : '';
            $out->addHTML( '<label class="pcp-obs-pol-radio"><input type="radio" name="polarity_override" value="' . $val . '"' . $chk . '> ' . $h( $lab ) . '</label> ' );
        }
        $out->addHTML( '</div>' );

        // Override date (optional, point picker).
        $out->addHTML( '<div class="pcp-form-row"><label>Date override (optional, point in time)</label>' );
        $out->addHTML( \MediaWiki\Extension\Pharmacopedia\DatePicker::renderWidget( 'date_struct_override', $struct ) );
        $out->addHTML( '<p class="pcp-form-help">Leave blank to use the date parsed from the text above.</p>' );
        $out->addHTML( '</div>' );

        // Current refs (read-only display).
        if ( $refs ) {
            $out->addHTML( '<div class="pcp-form-row"><label>Current linked references (regenerated from text on save)</label>' );
            $out->addHTML( '<div class="pcp-obs-edit-refs">' );
            foreach ( $refs as $r ) {
                $cls = 'pcp-obs-refchip ' . ( $r['matched'] ? 'matched' : 'unmatched' );
                $roleLabel = $r['role'] === 'subject' ? '' :
                             ( $r['role'] === 'cause' ? 'from ' :
                             ( $r['role'] === 'context' ? 'with ' : $r['role'] . ' ' ) );
                $out->addHTML( '<span class="' . $cls . '">' . $h( $roleLabel . $r['text'] )
                    . ' <small>[' . $h( $r['type'] ) . ']</small></span> ' );
            }
            $out->addHTML( '</div></div>' );
        }

        // ----- States and Traits (tile-based UI; replaces legacy spreadsheet) -----
        $this->renderStatesAndTraits( $out, $event, new LifeStoryStore(), $profileId );

        // Submit + cancel.
        $out->addHTML( '<div class="pcp-life-form-actions">' );
        $out->addHTML( '<button type="submit" class="pcp-btn pcp-btn-primary">Save changes</button> ' );
        $out->addHTML( '<a href="' . $h( $this->getPageTitle()->getLocalURL() ) . '" class="pcp-btn">Cancel</a>' );
        $out->addHTML( '</div></form>' );

        // Delete.
        $out->addHTML( '<form method="post" action="' . $action . '" class="pcp-life-delete-form">'
            . '<input type="hidden" name="pcp_title" value="' . $h( $this->getPageTitle()->getPrefixedDBkey() ) . '">'
            . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
            . '<input type="hidden" name="pcp_action" value="delete_event">'
            . '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">'
            . '<button type="submit" class="pcp-btn pcp-btn-danger" onclick="return confirm(\'Delete this observation?\')">Delete observation</button>'
            . '</form>' );
        $out->addHTML( '<form method="post" action="' . $action . '" class="pcp-life-dup-form">'
                . '<input type="hidden" name="pcp_title" value="' . $h( $this->getPageTitle()->getPrefixedDBkey() ) . '">'
                . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
                . '<input type="hidden" name="pcp_action" value="duplicate_event">'
                . '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">'
                . '<button type="submit" class="pcp-btn">Duplicate observation</button>'
                . '</form>' );
    }

    private function saveObservation( $store, int $profileId, $request ): int {
        $eventId = (int)$request->getVal( 'event_id', 0 );
        $text    = trim( (string)$request->getVal( 'text', '' ) );
        if ( $eventId <= 0 ) {
            throw new \RuntimeException( 'event_id required' );
        }
        $parser = new \MediaWiki\Extension\Pharmacopedia\ObservationParser();
        $parsed = $parser->parse( $text, $profileId );

        // Apply overrides.
        $polOver = (string)$request->getVal( 'polarity_override', '' );
        if ( $polOver !== '' ) $parsed['polarity'] = (int)$polOver;

        $dateOverJson = (string)$request->getVal( 'date_struct_override', '' );
        if ( $dateOverJson !== '' ) {
            $j = json_decode( $dateOverJson, true );
            if ( is_array( $j ) && in_array( $j['kind'] ?? '', [ 'point', 'range', 'possibility' ], true ) ) {
                $parsed['date_struct'] = $j;
            }
        }

        // Update the row in place (rather than insert+delete).
        $dbw = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getPrimaryDatabase();
        // Derive ISO + display from the struct via helpers that handle both point AND range.
        $iso = null;
        $struct = $parsed['date_struct'] ?? null;
        if ( is_array( $struct ) ) {
            $kind = (string)( $struct['kind'] ?? '' );
            if ( $kind === 'point' ) {
                $iso = $struct['point']['parsed']['iso'] ?? null;
            } elseif ( $kind === 'range' ) {
                $iso = $struct['from']['parsed']['iso'] ?? null;
            }
        }
        $disp = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatStructForCard( $struct );
        $dbw->newUpdateQueryBuilder()
            ->update( 'pcp_life_events' )
            ->set( [
                'le_raw_text'     => $text,
                'le_title'        => $this->observationTitle( $parsed ),
                'le_polarity'     => $parsed['polarity'] !== null ? (int)$parsed['polarity'] : null,
                'le_date_struct'  => $struct ? json_encode( $struct ) : null,
                'le_date_iso'     => $iso,
                'le_date_display' => (string)$disp,
                'le_updated'      => $dbw->timestamp(),
            ] )
            ->where( [ 'le_id' => $eventId, 'le_profile_id' => $profileId ] )
            ->caller( __METHOD__ )
            ->execute();

        // Replace refs from the new parse.
        $store->setEventRefs( $eventId, $parsed['refs'] );

        // Replace trait values from the kf[] form rows (normalized via KeyframeValueNormalizer).
        $kf = $request->getArray( 'kf' ) ?: [];
        $traits = [];
        foreach ( $kf as $row ) {
            if ( !is_array( $row ) ) continue;
            $ns  = trim( (string)( $row['namespace'] ?? '' ) );
            $key = trim( (string)( $row['key'] ?? '' ) );
            $val = trim( (string)( $row['value'] ?? '' ) );
            if ( $ns === '' || $key === '' || $val === '' ) continue;
            $_r = \MediaWiki\Extension\Pharmacopedia\KeyframeValueNormalizer::normalize( $val );
            if ( $_r['value'] === null ) continue;
            $traits[] = [
                'namespace' => $ns,
                'key'       => $key,
                'label'     => trim( (string)( $row['label'] ?? '' ) ) ?: null,
                'value'     => $_r['value'],
                'min'       => is_numeric( $row['min'] ?? '' ) ? (float)$row['min'] : null,
                'max'       => is_numeric( $row['max'] ?? '' ) ? (float)$row['max'] : null,
                'estimated' => !empty( $row['estimated'] ),
                'valence'   => isset( $row['valence'] ) && $row['valence'] !== '' ? (float)$row['valence'] : null,
                'valence_estimated' => !empty( $row['valence_estimated'] ),
                'traitvstate' => isset( $row['traitvstate'] ) && $row['traitvstate'] !== '' ? (float)$row['traitvstate'] : null,
                'traitvstate_estimated' => !empty( $row['traitvstate_estimated'] ),
            ];
        }
        $store->setTraits( $eventId, $traits );

        return $eventId;
    }

    private function observationTitle( array $parsed ): string {
        $parts = [];
        if ( $parsed['polarity'] === 0 ) $parts[] = 'no';
        if ( !empty( $parsed['subject_text'] ) ) $parts[] = $parsed['subject_text'];
        foreach ( $parsed['refs'] as $r ) {
            if ( $r['role'] === 'cause' )   $parts[] = 'from ' . $r['label'];
            if ( $r['role'] === 'context' ) $parts[] = 'with ' . $r['label'];
        }
        $title = trim( implode( ' ', $parts ) );
        return mb_substr( $title !== '' ? $title : (string)( $parsed['original_text'] ?? '' ), 0, 200 );
    }

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
        $subtypesJson = json_encode( $subtypesByType, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

        if ( $event ) { $this->renderTypeSwitcher( $out, $event ); }
        $out->addHTML( '<form method="post" enctype="multipart/form-data" action="' . $action . '" class="pcp-episode-form">' );
        $out->addHTML( '<input type="hidden" name="wpEditToken" value="' . $token . '">' );
        $out->addHTML( '<input type="hidden" name="pcp_action" value="save_episode">' );
        if ( $isEdit ) {
            $out->addHTML( '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">' );
        }

        // Type
        $out->addHTML( '<div class="pcp-form-row"><label>Type</label>' );
        $out->addHTML( '<select name="episode_type" class="pcp-ep-type">' );
        $out->addHTML( '<option value="">(pick one)</option>' );
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
        $out->addHTML( '<input type="text" name="pcp_title" maxlength="200" value="' . $h( $title ) . '" placeholder="e.g. depressive episode after job loss">' );
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

        // ----- States and Traits (any card type can carry these) -----
        $this->renderStatesAndTraits( $out, $event, new LifeStoryStore(), $profileId );

        $out->addHTML( '<div class="pcp-form-row">' );
        $out->addHTML( '<button type="submit" class="pcp-btn">' . ( $isEdit ? 'Save' : 'Create episode' ) . '</button> ' );
        $out->addHTML( '<a class="pcp-btn" href="' . $h( $this->getPageTitle()->getLocalURL() ) . '">Cancel</a>' );
        $out->addHTML( '</div></form>' );
        if ( $isEdit ) {
            $out->addHTML( '<form method="post" action="' . $action . '" class="pcp-life-delete-form">'
                . '<input type="hidden" name="pcp_title" value="' . $h( $this->getPageTitle()->getPrefixedDBkey() ) . '">'
                . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
                . '<input type="hidden" name="pcp_action" value="delete_event">'
                . '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">'
                . '<button type="submit" class="pcp-btn pcp-btn-danger" onclick="return confirm(\'Delete this episode?\')">Delete episode</button>'
                . '</form>' );
            $out->addHTML( '<form method="post" action="' . $action . '" class="pcp-life-dup-form">'
                . '<input type="hidden" name="pcp_title" value="' . $h( $this->getPageTitle()->getPrefixedDBkey() ) . '">'
                . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
                . '<input type="hidden" name="pcp_action" value="duplicate_event">'
                . '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">'
                . '<button type="submit" class="pcp-btn">Duplicate episode</button>'
                . '</form>' );
        }

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
            'title'           => trim( (string)$request->getVal( 'pcp_title', '' ) ),
            'body'            => trim( (string)$request->getVal( 'body', '' ) ),
            'visibility'      => (int)$request->getVal( 'visibility', 0 ),
        ];
        if ( $isEdit ) {
            $store->updateEpisode( $isEdit, $fields );
            $eventId = $isEdit;
        } else {
            $eventId = $store->addEpisode( $profileId, $fields );
        }
        // Persist trait values from the States and Traits tiles.
        $kf = $request->getArray( 'kf' ) ?: [];
        $traits = [];
        foreach ( $kf as $row ) {
            if ( !is_array( $row ) ) continue;
            $ns  = trim( (string)( $row['namespace'] ?? '' ) ) ?: 'custom';
            $key = trim( (string)( $row['key'] ?? '' ) );
            $val = trim( (string)( $row['value'] ?? '' ) );
            if ( $key === '' || $val === '' ) continue;
            $_r = \MediaWiki\Extension\Pharmacopedia\KeyframeValueNormalizer::normalize( $val );
            if ( $_r['value'] === null ) continue;
            $traits[] = [
                'namespace' => $ns,
                'key'       => $key,
                'label'     => trim( (string)( $row['label'] ?? '' ) ) ?: null,
                'value'     => $_r['value'],
                'min'       => is_numeric( $row['min'] ?? '' ) ? (float)$row['min'] : null,
                'max'       => is_numeric( $row['max'] ?? '' ) ? (float)$row['max'] : null,
                'estimated' => !empty( $row['estimated'] ),
                'valence'   => isset( $row['valence'] ) && $row['valence'] !== '' ? (float)$row['valence'] : null,
                'valence_estimated' => !empty( $row['valence_estimated'] ),
                'traitvstate' => isset( $row['traitvstate'] ) && $row['traitvstate'] !== '' ? (float)$row['traitvstate'] : null,
                'traitvstate_estimated' => !empty( $row['traitvstate_estimated'] ),
            ];
        }
        $store->setTraits( $eventId, $traits );

        $this->maybeAcceptImageUpload( $store, $eventId, $profileId, $request );
        return $eventId;
    }

    /**
     * Render the "States and Traits" tile-based fieldset. Replaces the old
     * spreadsheet-style "Trait values" / "Keyframe traits" fieldset on every
     * edit form. Posts the same kf[i][...] form fields the save handlers
     * already consume; the UI is a presentation layer on top.
     */
    private function renderStatesAndTraits( $out, ?\stdClass $event, $store, int $profileId ): void {
        $h = function ( $s ) { return htmlspecialchars( (string)$s ); };
        $traits = $event ? $store->getTraitsForEvent( (int)$event->le_id ) : [];
        $suggestions = $this->getTraitSuggestions( $profileId );

        $out->addHTML( '<fieldset class="pcp-prof-section pcp-states-traits"><legend>Attributes (traits/states)</legend>' );
        $out->addHTML( '<p class="pcp-prof-help"><small>Measurements at this point in time. Values accept numbers (40), grades (B+), fractions (4/10), percents (40%), and stars (3/5 stars) — all normalized to a 0-100 scale.</small></p>' );

        $nextIdx = count( $traits );
        $out->addHTML( '<div class="pcp-st-tiles" data-next-idx="' . $nextIdx . '">' );
        foreach ( $traits as $i => $t ) {
            $name  = (string)( $t->lt_label ?: $t->lt_key );
            $rawVal = rtrim( rtrim( (string)$t->lt_value_num, '0' ), '.' );
            $est = (int)$t->lt_estimated;
            $valence = isset( $t->lt_valence ) && $t->lt_valence !== null ? (float)$t->lt_valence : null;
            $valenceEst = !empty( $t->lt_valence_estimated );
            $tvs = isset( $t->lt_traitvstate ) && $t->lt_traitvstate !== null ? (float)$t->lt_traitvstate : null;
            $tvsEst = !empty( $t->lt_traitvstate_estimated );
            $valEstTilde = $est ? '~' : '';
            $valenceTilde = $valenceEst ? '~' : '';
            $tvsTilde = $tvsEst ? '~' : '';
            $valenceDisp = '';
            $valenceSign = 'zero';
            if ( $valence !== null ) {
                $valenceDisp = rtrim( rtrim( sprintf( '%+.2f', $valence ), '0' ), '.' );
                if ( $valence == 0.0 ) $valenceDisp = '0';
                $valenceSign = $valence > 0 ? 'pos' : ( $valence < 0 ? 'neg' : 'zero' );
            }
            $tvsDisp = '';
            $tvsSign = 'zero';
            if ( $tvs !== null ) {
                $tvsDisp = rtrim( rtrim( sprintf( '%+.2f', $tvs ), '0' ), '.' );
                if ( $tvs == 0.0 ) $tvsDisp = '0';
                $tvsSign = $tvs > 0 ? 'pos' : ( $tvs < 0 ? 'neg' : 'zero' );
            }
            $out->addHTML(
                '<div class="pcp-st-tile" data-idx="' . $i . '">'
                . '<span class="pcp-st-tile-display">'
                . '<span class="pcp-st-tile-name">' . $h( $name ) . '</span>'
                . '<span class="pcp-st-tile-eq"> = </span>'
                . '<span class="pcp-st-tile-value">' . $valEstTilde . $h( $rawVal ) . '</span>'
                . ( $valenceDisp !== '' ? ' <span class="pcp-st-tile-valence" data-sign="' . $valenceSign . '">valence ' . $valenceTilde . $h( $valenceDisp ) . '</span>' : '' )
                . ( $tvsDisp !== '' ? ' <span class="pcp-st-tile-tvs" data-sign="' . $tvsSign . '">tvs ' . $tvsTilde . $h( $tvsDisp ) . '</span>' : '' )
                . '</span>'
                . '<button type="button" class="pcp-st-tile-edit" title="Edit">✎</button>'
                . '<button type="button" class="pcp-st-tile-delete" title="Delete">×</button>'
                . '<input type="hidden" name="kf[' . $i . '][namespace]" value="' . $h( $t->lt_namespace ?: 'custom' ) . '">'
                . '<input type="hidden" name="kf[' . $i . '][key]" value="' . $h( (string)$t->lt_key ) . '">'
                . '<input type="hidden" name="kf[' . $i . '][label]" value="' . $h( $name ) . '">'
                . '<input type="hidden" name="kf[' . $i . '][value]" value="' . $h( $rawVal ) . '">'
                . '<input type="hidden" name="kf[' . $i . '][valence]" value="' . ( $valence !== null ? $h( (string)$valence ) : '' ) . '">'
                . '<input type="hidden" name="kf[' . $i . '][estimated]" value="' . ( $est ? '1' : '' ) . '">'
                . '<input type="hidden" name="kf[' . $i . '][valence_estimated]" value="' . ( $valenceEst ? '1' : '' ) . '">'
                . '<input type="hidden" name="kf[' . $i . '][traitvstate]" value="' . ( $tvs !== null ? $h( (string)$tvs ) : '' ) . '">'
                . '<input type="hidden" name="kf[' . $i . '][traitvstate_estimated]" value="' . ( $tvsEst ? '1' : '' ) . '">'
                . '</div>'
            );
        }
        $out->addHTML( '</div>' );

        $out->addHTML( '<button type="button" class="pcp-st-add-btn">+ Add state or trait</button>' );

        // Datalist for autocomplete (shared across all tiles on the page).
        $out->addHTML( '<datalist id="pcp-st-suggestions">' );
        foreach ( $suggestions as $s ) {
            $out->addHTML( '<option value="' . $h( $s ) . '">' );
        }
        $out->addHTML( '</datalist>' );

        $out->addHTML( '</fieldset>' );
    }

    /**
     * Trait-name autocomplete source: built-in starter list of common states +
     * traits, merged with this user's distinct lt_label / lt_key history.
     */
    private function getTraitSuggestions( int $profileId ): array {
        $builtin = [
            'agreeableness', 'alertness', 'anxiety', 'calm', 'conscientiousness',
            'depression', 'dread', 'energy', 'extraversion', 'focus', 'hunger',
            'irritability', 'joy', 'libido', 'loneliness', 'mood', 'motivation',
            'neuroticism', 'openness', 'optimism', 'pain', 'perfectionism',
            'resilience', 'restlessness', 'sadness', 'self-esteem', 'shyness',
            'sleep quality', 'stress',
        ];
        $dbr = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $res = $dbr->newSelectQueryBuilder()
            ->select( [ 'lt.lt_label', 'lt.lt_key' ] )
            ->distinct()
            ->from( 'pcp_life_traits', 'lt' )
            ->join( 'pcp_life_events', 'e', 'e.le_id = lt.lt_event_id' )
            ->where( [ 'e.le_profile_id' => $profileId ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $userNames = [];
        foreach ( $res as $row ) {
            $name = $row->lt_label ? (string)$row->lt_label : (string)$row->lt_key;
            if ( $name !== '' ) $userNames[] = $name;
        }
        $merged = array_unique( array_merge( $builtin, $userNames ) );
        sort( $merged, SORT_FLAG_CASE | SORT_STRING );
        return $merged;
    }

        /**
     * Render the "Card type" widget that appears above every edit form. Lets
     * the user convert any card to Story / Keyframe / Observation / Episode.
     * Image is not a category (handled implicitly: any card can have images).
     */
    private function renderTypeSwitcher( $out, \stdClass $event ): void {
        $h = function ( $s ) { return htmlspecialchars( (string)$s ); };
        $token  = $h( $this->getUser()->getEditToken() );
        $action = $h( $this->getPageTitle()->getLocalURL() );
        $current = (int)$event->le_type;
        // Slot 1 is now TYPE_EVENT (was legacy TYPE_IMAGE; rows migrated long ago).
        // Slot 2 is legacy TYPE_KEYFRAME (post-collapse rows migrated to type=3).
        if ( $current === 2 ) $current = 3;
        // 4-type vocabulary, post-collapse: Observation / Event / Episode / Story.
        // Order matters; this is the user-facing top-to-bottom in the dropdown.
        $types = [
            3 => 'Observation',
            1 => 'Event',
            4 => 'Episode',
            0 => 'Story',
        ];
        $out->addHTML( '<form method="post" action="' . $action . '" class="pcp-card-type-switch">' );
        $out->addHTML( '<input type="hidden" name="wpEditToken" value="' . $token . '">' );
        $out->addHTML( '<input type="hidden" name="pcp_action" value="convert_type">' );
        $out->addHTML( '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">' );
        $out->addHTML( '<label class="pcp-card-type-switch-label">Card type: <select name="new_type">' );
        foreach ( $types as $tk => $tl ) {
            $sel = $tk === $current ? ' selected' : '';
            $out->addHTML( '<option value="' . $tk . '"' . $sel . '>' . $h( $tl ) . '</option>' );
        }
        $out->addHTML( '</select></label> <button type="submit" class="pcp-btn pcp-btn-sm">Convert</button>' );
        $out->addHTML( '</form>' );
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

        if ( $isEdit ) { $this->renderTypeSwitcher( $out, $event ); }
        $out->addHTML( '<form method="post" action="' . $h( $action ) . '" enctype="multipart/form-data" class="pcp-life-form">' );
        $out->addHTML( '<input type="hidden" name="pcp_title" value="' . $h( $this->getPageTitle()->getPrefixedDBkey() ) . '">' );
        $out->addHTML( '<input type="hidden" name="wpEditToken" value="' . $token . '">' );
        $out->addHTML( '<input type="hidden" name="pcp_action" value="save_event">' );
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

        // (Type select removed; the Card-type Convert widget above handles all
        // cross-type changes. Story / Keyframe both render via this same form.)
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
                        . '<input type="hidden" name="pcp_title" value="' . $h( $this->getPageTitle()->getPrefixedDBkey() ) . '">'
                        . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
                        . '<input type="hidden" name="pcp_action" value="delete_image">'
                        . '<input type="hidden" name="image_id" value="' . (int)$im->li_id . '">'
                        . '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">'
                        . '<button type="submit" class="pcp-life-img-del" onclick="return confirm(\'Delete this image?\')">delete</button>'
                        . '</form></li>' );
                }
                $out->addHTML( '</ul></div>' );
            }
        }
        $out->addHTML( '</fieldset>' );

        // ----- States and Traits (tile-based UI; replaces legacy spreadsheet) -----
        $this->renderStatesAndTraits( $out, $event, new LifeStoryStore(), $profileId );

        // ----- Submit -----
        $out->addHTML( '<div class="pcp-life-form-actions">' );
        $out->addHTML( '<button type="submit" class="pcp-btn pcp-btn-primary">' . ( $isEdit ? 'Save changes' : 'Add event' ) . '</button> ' );
        $out->addHTML( '<a href="' . $h( $this->getPageTitle()->getLocalURL() ) . '" class="pcp-btn">Cancel</a>' );
        $out->addHTML( '</div></form>' );

        // ----- Delete (edit mode only) -----
        if ( $isEdit ) {
            $out->addHTML( '<form method="post" action="' . $h( $action ) . '" class="pcp-life-delete-form">'
                . '<input type="hidden" name="pcp_title" value="' . $h( $this->getPageTitle()->getPrefixedDBkey() ) . '">'
                . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
                . '<input type="hidden" name="pcp_action" value="delete_event">'
                . '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">'
                . '<button type="submit" class="pcp-btn pcp-btn-danger" onclick="return confirm(\'Delete this event and its images?\')">Delete event</button>'
                . '</form>' );
            $out->addHTML( '<form method="post" action="' . $h( $action ) . '" class="pcp-life-dup-form">'
                . '<input type="hidden" name="pcp_title" value="' . $h( $this->getPageTitle()->getPrefixedDBkey() ) . '">'
                . '<input type="hidden" name="wpEditToken" value="' . $token . '">'
                . '<input type="hidden" name="pcp_action" value="duplicate_event">'
                . '<input type="hidden" name="event_id" value="' . (int)$event->le_id . '">'
                . '<button type="submit" class="pcp-btn">Duplicate event</button>'
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

        // Fetch existing first so type is preserved across edits (no inline select anymore).
        $eventId = (int)$request->getVal( 'event_id', 0 );
        $existing = null;
        if ( $eventId > 0 ) {
            $existing = $store->getEvent( $eventId );
            if ( !$existing || (int)$existing->le_profile_id !== $profileId ) {
                throw new \RuntimeException( 'Event not found or not yours.' );
            }
        }

        $fields = [
            'date_iso'       => $dateIso,
            'date_precision' => $precision,
            'date_display'   => $dispText !== '' ? $dispText : null,
            'date_struct'    => $structJson,
            // New /add cards default to TYPE_EVENT (matches the "+ Add event" chip).
            // Stories are reached by converting an Event via the Card-type widget.
            'type'           => $existing ? (int)$existing->le_type : \MediaWiki\Extension\Pharmacopedia\LifeStoryStore::TYPE_EVENT,
            'title'          => trim( (string)$request->getVal( 'title_text', '' ) ),
            'body'           => trim( (string)$request->getVal( 'body', '' ) ) ?: null,
            'visibility'     => (int)$request->getVal( 'visibility', 0 ),
            'tags'           => trim( (string)$request->getVal( 'tags', '' ) ) ?: null,
        ];

        if ( $existing ) {
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
            $_r = \MediaWiki\Extension\Pharmacopedia\KeyframeValueNormalizer::normalize( $val );
            if ( $_r['value'] === null ) continue;
            $traits[] = [
                'namespace' => $ns,
                'key'       => $key,
                'label'     => trim( (string)( $row['label'] ?? '' ) ) ?: null,
                'value'     => $_r['value'],
                'min'       => is_numeric( $row['min'] ?? '' ) ? (float)$row['min'] : null,
                'max'       => is_numeric( $row['max'] ?? '' ) ? (float)$row['max'] : null,
                'estimated' => !empty( $row['estimated'] ),
                'valence'   => isset( $row['valence'] ) && $row['valence'] !== '' ? (float)$row['valence'] : null,
                'valence_estimated' => !empty( $row['valence_estimated'] ),
                'traitvstate' => isset( $row['traitvstate'] ) && $row['traitvstate'] !== '' ? (float)$row['traitvstate'] : null,
                'traitvstate_estimated' => !empty( $row['traitvstate_estimated'] ),
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

        // Antivirus scan (FAIL-CLOSED: rejects if scanner missing or errors).
        $avScan = \MediaWiki\Extension\Pharmacopedia\VirusScanner::scanFile( $tmp );
        if ( !$avScan['ok'] ) {
            throw new \RuntimeException( 'Upload rejected by antivirus: ' . $avScan['reason'] );
        }

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
