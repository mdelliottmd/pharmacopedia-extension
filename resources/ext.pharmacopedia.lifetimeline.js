/*!
 * Pharmacopedia visual life timeline (polished build).
 */
( function () {
    'use strict';

    var GROUPS = [
        { id: 'episodes',     content: 'Episodes',     order: 1, visible: true  },
        { id: 'events',       content: 'Events',       order: 2, visible: true  },
        { id: 'observations', content: 'Observations', order: 3, visible: true  },
        { id: 'keyframes',    content: 'Keyframes',    order: 4, visible: true  },
        { id: 'derived',      content: 'Derived',      order: 5, visible: false }   // hidden by default
    ];

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
        var itemsDS  = new vis.DataSet( allItems );
        var groupsDS = new vis.DataSet( GROUPS.map( function ( g ) {
            return { id: g.id, content: g.content, order: g.order, visible: g.visible };
        } ) );

        var options = {
            stack: true,
            editable: false,
            selectable: true,
            zoomMin: 1000 * 60 * 60 * 24 * 7,
            zoomMax: 1000 * 60 * 60 * 24 * 365 * 120,
            margin: { item: 6, axis: 8 },
            orientation: { axis: 'top', item: 'top' },
            tooltip: { followMouse: true, overflowMethod: 'flip' },
            zoomKey: 'ctrlKey',
            horizontalScroll: true,           // shift+wheel pans
            verticalScroll: true,             // wheel inside groups area scrolls vertically
            height:          '520px',         // fixed height; internal scroll past this
            showCurrentTime: true,
            clickToUse: false
        };

        var timeline = new vis.Timeline( mount, itemsDS, groupsDS, options );

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
        var zinBtn = document.querySelector( '.pcp-life-timeline-zoomin' );
        if ( zinBtn ) zinBtn.addEventListener( 'click', function () { timeline.zoomIn( 0.4 ); } );
        var zoutBtn = document.querySelector( '.pcp-life-timeline-zoomout' );
        if ( zoutBtn ) zoutBtn.addEventListener( 'click', function () { timeline.zoomOut( 0.4 ); } );

        // Group-visibility checkboxes.
        document.querySelectorAll( '.pcp-life-timeline-group-toggle' ).forEach( function ( cb ) {
            cb.addEventListener( 'change', function () {
                groupsDS.update( { id: cb.value, visible: cb.checked } );
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
                document.querySelectorAll( '.pcp-life-view-toggle' ).forEach( function ( b ) { b.classList.remove( 'active' ); } );
                btn.classList.add( 'active' );
                document.querySelectorAll( '.pcp-life-view' ).forEach( function ( v ) { v.classList.remove( 'active' ); } );
                var pane = document.querySelector( '.pcp-life-view[data-view="' + which + '"]' );
                if ( pane ) pane.classList.add( 'active' );
                if ( which === 'visual' ) timeline.redraw();
            } );
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
