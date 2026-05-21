/*!
 * Pharmacopedia visual life timeline (polished build).
 */
( function () {
    'use strict';

    // pcp-no-swimlanes: groups removed; items live in one stacked area.
    // Item.group is still set per-item as a TYPE TAG for filter checkbox logic
    // and per-type CSS classes, but vis-timeline does not render lanes.
    var INITIAL_VISIBLE = { episodes: true, events: true, observations: true, derived: false };

    function isoOrNull( v ) {
        return ( typeof v === 'string' && /^\d{4}-\d{2}-\d{2}/.test( v ) ) ? v : null;
    }
    function escapeHtml( s ) {
        return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }
    function tooltipHtml( e ) {
        var parts = [ '<strong>' + escapeHtml( e.title || '(untitled)' ) + '</strong>' ];
        if ( e.subtitle ) parts.push( '<em>' + escapeHtml( e.subtitle ) + '</em>' );
        parts.push( '<small>' + escapeHtml( e.dateLabel || e.start ) + '</small>' );
        if ( e.severity != null ) parts.push( 'severity: ' + e.severity );
        return parts.join( '<br>' );
    }
    function buildItem( e ) {
        var iso = isoOrNull( e.start ) || '1970-01-01';
        var endIso = isoOrNull( e.end );
        var item = {
            id: String( e.id ),
            content: e.title ? escapeHtml( e.title ) : '(untitled)',
            start: iso,
            group: e.group,
            type: endIso && endIso !== iso ? 'range' : 'box',
            className: 'pcp-vis-item-' + e.group + ( e.polarity === 0 ? ' pcp-vis-item-neg' : '' ),
            title: tooltipHtml( e )
        };
        if ( endIso && endIso !== iso ) item.end = endIso;
        return item;
    }

    function init() {
        var mount = document.getElementById( 'pcp-life-timeline-mount' );
        var data  = window.PCP_LIFE_TIMELINE_DATA;
        if ( !mount || !data || typeof vis === 'undefined' ) return;

        var allItems = ( data.events || [] ).map( buildItem );
        // Initial filter: hide items whose type is in the default-off set (keyframes, derived).
        var currentVisible = Object.assign( {}, INITIAL_VISIBLE );
        function visibleItems() {
            return allItems.filter( function ( i ) { return currentVisible[ i.group ] !== false; } );
        }
        var itemsDS  = new vis.DataSet( visibleItems() );
        // groupsDS intentionally omitted; vis-timeline renders one stacked area when groups aren't passed.

        var options = {
            stack: true,
            editable: false,
            selectable: true,
            zoomMin: 1000 * 60 * 60 * 24 * 7,
            zoomMax: 1000 * 60 * 60 * 24 * 365 * 120,
            margin: { item: 6, axis: 8 },
            orientation: { axis: 'bottom', item: 'top' },
            tooltip: { followMouse: true, overflowMethod: 'flip' },
            zoomKey: 'ctrlKey',
            horizontalScroll: true,           // shift+wheel pans
            verticalScroll: true,             // wheel inside groups area scrolls vertically
            height:          '460px',         // fixed height; internal scroll past this
            showCurrentTime: true,
            clickToUse: false
        };

        var timeline = new vis.Timeline( mount, itemsDS, options );
        // Expose globally for race-safe pickup by the chart module.
        window.pcpLifeTimeline = timeline;

        // pcp-overlay-dynamic: standalone overlays as children of mount,
        // but their top/height get computed at every render from vis-timeline's
        // actual panel bounding rects. That guarantees the labels sit at the
        // SAME Y as vis-timeline's bottom panel (where its own labels live),
        // and the grids span the EXACT same height as the center panel (the
        // plot data area).
        var labelOverlay = document.createElement( 'div' );
        labelOverlay.id = 'pcp-year-labels-overlay';
        labelOverlay.className = 'pcp-year-labels-overlay';
        mount.appendChild( labelOverlay );

        var gridsOverlay = document.createElement( 'div' );
        gridsOverlay.id = 'pcp-year-grids-overlay';
        gridsOverlay.className = 'pcp-year-grids-overlay';
        mount.appendChild( gridsOverlay );

        function renderYearLabels() {
            // Strip any leftover labels we injected into vis-timeline's own
            // axis from prior install passes.
            mount.querySelectorAll( '.vis-time-axis .pcp-year-label, .vis-foreground .pcp-year-grid' ).forEach( function ( el ) {
                el.remove();
            } );

            var bottomPanel = mount.querySelector( '.vis-panel.vis-bottom' );
            var centerPanel = mount.querySelector( '.vis-panel.vis-center' );
            if ( !centerPanel ) return;

            var mountRect  = mount.getBoundingClientRect();
            var cpRect     = centerPanel.getBoundingClientRect();
            // bottom panel may not exist if axis='none' or similar; fall back to just below the center panel.
            var bpRect     = bottomPanel ? bottomPanel.getBoundingClientRect() : null;

            // Position labelOverlay at the Y of the bottom panel (the time-axis area).
            var labelTop;
            var labelHeight;
            if ( bpRect && bpRect.height > 0 ) {
                labelTop    = bpRect.top - mountRect.top;
                labelHeight = bpRect.height;
            } else {
                // Fallback: position just below the center panel.
                labelTop    = ( cpRect.bottom - mountRect.top );
                labelHeight = 24;
            }
            labelOverlay.style.top    = labelTop + 'px';
            labelOverlay.style.height = labelHeight + 'px';
            labelOverlay.style.bottom = '';

            // Position gridsOverlay at the Y/height of the center panel (full plot area).
            gridsOverlay.style.top    = ( cpRect.top - mountRect.top ) + 'px';
            gridsOverlay.style.height = cpRect.height + 'px';
            gridsOverlay.style.bottom = '';

            // Time window
            var win = timeline.getWindow();
            var tMin = new Date( win.start ).getTime();
            var tMax = new Date( win.end ).getTime();
            var tRange = tMax - tMin;
            if ( !tRange || isNaN( tRange ) ) return;

            // X coord space: relative to mount (since overlays are children of mount).
            var plotLeft  = cpRect.left - mountRect.left;
            var plotWidth = cpRect.width;

            // Year step
            var yrStart = new Date( tMin ).getFullYear();
            var yrEnd   = new Date( tMax ).getFullYear();
            var yrSpan  = Math.max( 1, yrEnd - yrStart );
            var step = 1;
            if ( yrSpan > 80 ) step = 20;
            else if ( yrSpan > 40 ) step = 10;
            else if ( yrSpan > 20 ) step = 5;
            else if ( yrSpan > 10 ) step = 2;

            labelOverlay.innerHTML = '';
            gridsOverlay.innerHTML = '';

            for ( var yr = Math.ceil( yrStart / step ) * step; yr <= yrEnd; yr += step ) {
                var ts = new Date( yr, 0, 1 ).getTime();
                if ( ts < tMin || ts > tMax ) continue;
                var px = plotLeft + ( ( ts - tMin ) / tRange ) * plotWidth;

                // Year label
                var span = document.createElement( 'span' );
                span.className = 'pcp-year-label';
                span.style.left = px + 'px';
                span.textContent = String( yr );
                labelOverlay.appendChild( span );

                // Vertical grid line in gridsOverlay (full plot height)
                var grid = document.createElement( 'div' );
                grid.className = 'pcp-year-grid';
                grid.style.left = px + 'px';
                gridsOverlay.appendChild( grid );
            }
        }

        timeline.on( 'changed', renderYearLabels );
        timeline.on( 'rangechanged', renderYearLabels );
        requestAnimationFrame( function () {
            requestAnimationFrame( renderYearLabels );
        } );


        // Also notify via event (for modules that prefer event-based wiring).
        document.dispatchEvent( new CustomEvent( 'pcp-life-timeline-ready', { detail: { timeline: timeline } } ) );

        function fit( includeOptionalGroups ) {
            var ids;
            if ( includeOptionalGroups ) {
                ids = allItems.map( function ( i ) { return i.id; } );
            } else {
                ids = allItems
                    .filter( function ( i ) { return i.group !== 'derived' && i.group !== 'keyframes'; } )
                    .map( function ( i ) { return i.id; } );
            }
            if ( ids.length ) {
                timeline.focus( ids, { animation: true } );
            } else if ( allItems.length ) {
                timeline.fit( { animation: true } );
            } else {
                var now = Date.now();
                timeline.setWindow( now - 1000 * 60 * 60 * 24 * 365 * 20, now, { animation: false } );
            }
        }
        fit( false );

        // ===== Controls =====

        var fitBtn = document.querySelector( '.pcp-life-timeline-fit' );
        if ( fitBtn ) fitBtn.addEventListener( 'click', function () { fit( false ); } );
        var fitAllBtn = document.querySelector( '.pcp-life-timeline-fit-all' );
        if ( fitAllBtn ) fitAllBtn.addEventListener( 'click', function () { fit( true ); } );
        // pcp-keyboard-zoom: + zooms in, - zooms out, 0 = fit visible.
        // Ignored when focus is in any text input / textarea / contenteditable.
        document.addEventListener( 'keydown', function ( e ) {
            if ( e.metaKey || e.ctrlKey || e.altKey ) return;
            var t = e.target;
            if ( t && ( t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable ) ) return;
            if ( e.key === '+' || e.key === '=' ) {
                timeline.zoomIn( 0.4 );
                e.preventDefault();
            } else if ( e.key === '-' || e.key === '_' ) {
                timeline.zoomOut( 0.4 );
                e.preventDefault();
            } else if ( e.key === '0' ) {
                fit( false );
                e.preventDefault();
            }
        } );

        var zinBtn = document.querySelector( '.pcp-life-timeline-zoomin' );
        if ( zinBtn ) zinBtn.addEventListener( 'click', function () { timeline.zoomIn( 0.4 ); } );
        var zoutBtn = document.querySelector( '.pcp-life-timeline-zoomout' );
        if ( zoutBtn ) zoutBtn.addEventListener( 'click', function () { timeline.zoomOut( 0.4 ); } );

        // Group-visibility checkboxes: drive both the visual timeline AND the card list.
        function applyCardFilter( gid, on ) {
            var listView = document.querySelector( '.pcp-life-view[data-view="list"]' );
            if ( !listView ) return;
            listView.classList.toggle( 'pcp-hide-' + gid, !on );
        }
        // pcp-life-swatch-injected: add a color chip to each filter checkbox
        document.querySelectorAll( '.pcp-life-timeline-group-toggle' ).forEach( function ( cb ) {
            if ( cb.parentElement.querySelector( '.pcp-life-swatch' ) ) return;
            var sw = document.createElement( 'span' );
            sw.className = 'pcp-life-swatch pcp-life-swatch-' + cb.value;
            sw.setAttribute( 'aria-hidden', 'true' );
            cb.parentElement.insertBefore( sw, cb.nextSibling );
        } );

        document.querySelectorAll( '.pcp-life-timeline-group-toggle' ).forEach( function ( cb ) {
            // Initial state -> apply filter on card list.
            applyCardFilter( cb.value, cb.checked );
            cb.addEventListener( 'change', function () {
                // pcp-no-swimlanes-handler: filter by item-tag, not group visibility
                currentVisible[ cb.value ] = cb.checked;
                var nowVisible = visibleItems();
                itemsDS.clear();
                itemsDS.add( nowVisible );
                applyCardFilter( cb.value, cb.checked );
            } );
        } );

        // Delete-card confirmation.
        document.querySelectorAll( '.pcp-life-card-delete-form' ).forEach( function ( f ) {
            f.addEventListener( 'submit', function ( e ) {
                var msg = f.getAttribute( 'data-confirm' ) || 'Delete this event?';
                if ( !window.confirm( msg ) ) { e.preventDefault(); }
            } );
        } );

        // Visibility cycle on click.
        document.querySelectorAll( '.pcp-life-vis-toggle' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                var eid = parseInt( btn.getAttribute( 'data-event-id' ), 10 );
                var cur = parseInt( btn.getAttribute( 'data-vis' ) || '0', 10 );
                var next = ( cur + 1 ) % 4;
                var icons = { 0: '\ud83d\udd12', 1: '\ud83d\udc41', 2: '\ud83c\udd94', 3: '\ud83c\udfad' };
                var labels = { 0: 'private', 1: 'public-default', 2: 'public-username', 3: 'public-anonymous' };
                var tokenEl = document.querySelector( 'input[name="wpEditToken"]' );
                var token = tokenEl ? tokenEl.value : '';
                // Post back to the current Special page (avoid grabbing the search
                // form's hidden title input, which would route to Special:Search).
                var url = mw.util.getUrl( mw.config.get( 'wgPageName' ) );
                btn.disabled = true;
                var form = new FormData();
                form.append( 'action', 'set_visibility' );
                form.append( 'event_id', String( eid ) );
                form.append( 'visibility', String( next ) );
                form.append( 'wpEditToken', token );
                form.append( 'ajax', '1' );
                fetch( url, { method: 'POST', body: form, credentials: 'same-origin' } )
                    .then( function ( r ) { return r.json(); } )
                    .then( function ( j ) {
                        if ( j && j.ok ) {
                            btn.setAttribute( 'data-vis', String( next ) );
                            btn.textContent = icons[ next ] || '?';
                            btn.setAttribute( 'title', ( labels[ next ] || '?' ) + ' (click to cycle)' );
                        }
                    } )
                    .catch( function () {} )
                    .finally( function () { btn.disabled = false; } );
            } );
        } );

        // ===== Interaction handlers =====

        // Click an item -> route to edit URL.
        timeline.on( 'select', function ( props ) {
            if ( !props.items.length ) return;
            var id = props.items[0];
            var ev = ( data.events || [] ).find( function ( e ) { return String( e.id ) === String( id ); } );
            if ( ev && ev.editUrl ) window.location.href = ev.editUrl;
        } );

        // Click empty -> prefill quick-add observation with that date.
        timeline.on( 'click', function ( props ) {
            if ( props.what !== 'background' && props.what !== 'group-label' ) return;
            if ( props.item ) return;
            var d = props.time;
            if ( !( d instanceof Date ) ) return;
            var iso = d.toISOString().slice( 0, 10 );
            var $in = document.querySelector( '.pcp-obs-input' );
            if ( !$in ) return;
            $in.value = $in.value
                ? ( $in.value + ' on ' + iso )
                : ( 'on ' + iso + ' ' );
            $in.focus();
            // Trigger input event so the live preview re-runs.
            $in.dispatchEvent( new Event( 'input', { bubbles: true } ) );
            // Scroll to the quick-add box for clarity.
            $in.scrollIntoView( { behavior: 'smooth', block: 'center' } );
        } );

        // Tab toggle (visual / list).
        document.querySelectorAll( '.pcp-life-view-toggle' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                var which = btn.getAttribute( 'data-view' );
                var wasActive = btn.classList.contains( 'active' );
                // Deactivate ALL toggles + panes first.
                document.querySelectorAll( '.pcp-life-view-toggle' ).forEach( function ( b ) { b.classList.remove( 'active' ); } );
                document.querySelectorAll( '.pcp-life-view' ).forEach( function ( v ) { v.classList.remove( 'active' ); } );
                // If we just clicked the already-active one, leave everything deactivated (toggled off).
                if ( wasActive ) return;
                // Otherwise activate the clicked one + its pane.
                btn.classList.add( 'active' );
                var pane = document.querySelector( '.pcp-life-view[data-view="' + which + '"]' );
                if ( pane ) pane.classList.add( 'active' );
                if ( which === 'visual' && timeline && typeof timeline.redraw === 'function' ) {
                    // Let the max-height transition reach a usable size before vis-timeline measures.
                    setTimeout( function () { timeline.redraw(); }, 360 );
                }
            } );
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
