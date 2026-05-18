/*!
 * Trait-trajectory chart, hand-rolled SVG.
 * Reads window.PCP_LIFE_GRAPH_DATA = { groups: [...], items: [...] }.
 * Items already have y normalized to 0-100 (raw value preserved on item.rawY).
 *
 * Replaces the prior vis.Graph2d implementation. Themed via CSS classes
 * defined alongside; no external charting library required.
 */
( function () {
    'use strict';
    var timelineRef = null;

    var H_DEFAULT = 360;
    var ML = 44, MR = 16, MT = 12, MB = 28; // margins inside the SVG viewport

    function colorOf( group ) {
        var style = group && group.style ? String( group.style ) : '';
        var m = style.match( /stroke:\s*([^;]+)/ );
        return m ? m[1].trim() : '#c4b5fd';
    }

    function escapeXml( s ) {
        return String( s ).replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' ).replace( /'/g, '&apos;' );
    }

    function render( mount, data ) {
        var W = mount.clientWidth || 900;
        var H = H_DEFAULT;
        var PW = Math.max( 100, W - ML - MR );
        var PH = H - MT - MB;

        var items = data.items || [];
        if ( !items.length ) {
            mount.innerHTML = '<p class="pcp-life-graph-empty">No keyframe trajectories yet. Take an assessment, or add 2+ keyframes with the same name.</p>';
            return;
        }

        // Time range: if a timeline is wired up, mirror its current window.
        // Otherwise, fall back to the data range so the chart still renders.
        var tMin, tMax;
        if ( timelineRef && typeof timelineRef.getWindow === 'function' ) {
            var win = timelineRef.getWindow();
            tMin = new Date( win.start ).getTime();
            tMax = new Date( win.end ).getTime();
        } else {
            var times = items.map( function ( it ) { return new Date( it.x ).getTime(); } )
                .filter( function ( t ) { return !isNaN( t ); } );
            if ( !times.length ) {
                mount.innerHTML = '<p class="pcp-life-graph-empty">No valid dates in data.</p>';
                return;
            }
            tMin = Math.min.apply( null, times );
            tMax = Math.max.apply( null, times );
            var tPad = Math.max( 86400000, ( tMax - tMin ) * 0.04 );
            tMin -= tPad; tMax += tPad;
        }
        var tRange = ( tMax - tMin ) || 1;

        // In overlay mode, also try to match the chart's left margin to the
        // timeline's left-panel width so the y-axis sits on the same vertical
        // line as the timeline's plot-area edge.
        if ( timelineRef ) {
            var leftPanel = document.querySelector( '#pcp-life-timeline-mount .vis-panel.vis-left' );
            if ( leftPanel ) {
                var lpRect = leftPanel.getBoundingClientRect();
                ML = Math.round( lpRect.width );
                PW = Math.max( 100, W - ML - MR );
            }
            // In overlay mode the timeline shows the time axis. Suppress our own bottom labels.
            MB = 8;
            PH = H - MT - MB;
        }

        // Y axis: items already 0-100; use a fixed 0-100 range.
        var yMin = 0, yMax = 100;
        var yRange = yMax - yMin;

        function xPx( t ) { return ML + ( ( t - tMin ) / tRange ) * PW; }
        function yPx( v ) { return MT + PH - ( ( v - yMin ) / yRange ) * PH; }

        var parts = [];
        parts.push( '<svg class="pcp-trait-svg" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet" width="100%" height="' + H + '">' );

        // Y gridlines + labels (every 25 between 0 and 100).
        for ( var v = 0; v <= 100; v += 25 ) {
            var py = yPx( v );
            parts.push( '<line class="pcp-trait-grid" x1="' + ML + '" y1="' + py + '" x2="' + ( W - MR ) + '" y2="' + py + '"/>' );
            parts.push( '<text class="pcp-trait-axis-label" x="' + ( ML - 6 ) + '" y="' + ( py + 3 ) + '" text-anchor="end">' + v + '%</text>' );
        }

        // X axis: only draw our own year labels in standalone mode.
        // In overlay mode (chart positioned on top of timeline), the timeline's
        // top x-axis is authoritative.
        var inOverlayMode = mount.classList && mount.classList.contains( 'pcp-life-graph-overlay' );
        if ( !inOverlayMode ) {
            var yrStart = new Date( tMin ).getFullYear();
            var yrEnd = new Date( tMax ).getFullYear();
            var yrSpan = Math.max( 1, yrEnd - yrStart );
            var step = 1;
            if ( yrSpan > 50 ) step = 10;
            else if ( yrSpan > 25 ) step = 5;
            else if ( yrSpan > 12 ) step = 2;
            var yr;
            for ( yr = Math.ceil( yrStart / step ) * step; yr <= yrEnd; yr += step ) {
                var ts = new Date( yr, 0, 1 ).getTime();
                if ( ts < tMin || ts > tMax ) continue;
                var px = xPx( ts );
                parts.push( '<line class="pcp-trait-grid-v" x1="' + px + '" y1="' + MT + '" x2="' + px + '" y2="' + ( H - MB ) + '"/>' );
                parts.push( '<text class="pcp-trait-axis-label" x="' + px + '" y="' + ( H - 8 ) + '" text-anchor="middle">' + yr + '</text>' );
            }
        }

        // Plot border (subtle).
        parts.push( '<line class="pcp-trait-axis" x1="' + ML + '" y1="' + MT + '" x2="' + ML + '" y2="' + ( H - MB ) + '"/>' );
        parts.push( '<line class="pcp-trait-axis" x1="' + ML + '" y1="' + ( H - MB ) + '" x2="' + ( W - MR ) + '" y2="' + ( H - MB ) + '"/>' );

        // One polyline + dots per group.
        ( data.groups || [] ).forEach( function ( g ) {
            var color = colorOf( g );
            var pts = items.filter( function ( it ) { return it.group === g.id; } );
            pts.sort( function ( a, b ) { return new Date( a.x ) - new Date( b.x ); } );
            if ( !pts.length ) return;
            var coords = pts.map( function ( p ) {
                return xPx( new Date( p.x ).getTime() ) + ',' + yPx( p.y );
            } ).join( ' ' );
            parts.push( '<polyline class="pcp-trait-line" data-gid="' + escapeXml( g.id ) + '" points="' + coords + '" style="stroke:' + escapeXml( color ) + ';"/>' );
            pts.forEach( function ( p ) {
                var cx = xPx( new Date( p.x ).getTime() );
                var cy = yPx( p.y );
                var rawY = ( p.rawY !== undefined && p.rawY !== null ) ? p.rawY : p.y;
                var title = escapeXml( ( g.content || g.id ) + ' · ' + ( typeof rawY === 'number' ? rawY.toFixed( 1 ) : rawY ) + ' (' + Math.round( p.y ) + '%) · ' + String( p.x ).slice( 0, 10 ) );
                parts.push( '<circle class="pcp-trait-dot" data-gid="' + escapeXml( g.id ) + '" cx="' + cx + '" cy="' + cy + '" r="3.5" style="fill:' + escapeXml( color ) + ';"><title>' + title + '</title></circle>' );
            } );
        } );

        parts.push( '</svg>' );
        mount.innerHTML = parts.join( '' );
    }

    function buildLegend( data ) {
        var legend = document.querySelector( '.pcp-life-graph-legend' );
        if ( !legend ) return;
        legend.innerHTML = '';
        var details = document.createElement( 'details' );
        details.className = 'pcp-life-graph-legend-details';
        var summary = document.createElement( 'summary' );
        summary.textContent = 'Trait series (' + ( data.groups ? data.groups.length : 0 ) + ')';
        details.appendChild( summary );
        var chipWrap = document.createElement( 'div' );
        chipWrap.className = 'pcp-life-graph-legend-chips';
        details.appendChild( chipWrap );
        legend.appendChild( details );
        data.groups.forEach( function ( g ) {
            var color = ( g.style ? g.style.match( /stroke:\s*([^;]+)/ ) : null );
            color = color ? color[1].trim() : '#c4b5fd';
            var chip = document.createElement( 'label' );
            chip.className = 'pcp-life-graph-legend-chip';
            chip.innerHTML = '<input type="checkbox" checked data-gid="' + g.id + '">' +
                '<span class="pcp-life-graph-legend-swatch" style="background:' + color + '"></span>' +
                '<span class="pcp-life-graph-legend-text">' + g.content + '</span>';
            chipWrap.appendChild( chip );
        } );
        legend.addEventListener( 'change', function ( e ) {
            if ( e.target.tagName !== 'INPUT' || !graph ) return;
            var gid = e.target.getAttribute( 'data-gid' );
            var grp = graph.groupsData.get( gid );
            grp.visible = e.target.checked;
            graph.groupsData.update( grp );
        } );
    }


    function init() {
        var mount = document.getElementById( 'pcp-life-graph-mount' );
        var data = window.PCP_LIFE_GRAPH_DATA;
        if ( !mount || !data ) return;
        render( mount, data );
        buildLegend( data );

        // Re-render on window resize (debounced).
        var resizeT = null;
        window.addEventListener( 'resize', function () {
            if ( resizeT ) clearTimeout( resizeT );
            resizeT = setTimeout( function () { render( mount, data ); }, 200 );
        } );

        // When the timeline reports ready, capture its instance, re-render in
        // overlay mode (synced x-range), and re-render on every range change.
        function bindTimeline( tl ) {
            if ( !tl || timelineRef ) return;
            timelineRef = tl;
            render( mount, data );
            timelineRef.on( 'rangechange', function () {
                render( mount, data );
            } );
        }
        // Race-safe pickup: grab the timeline if it's already published.
        if ( window.pcpLifeTimeline ) {
            bindTimeline( window.pcpLifeTimeline );
        }
        document.addEventListener( 'pcp-life-timeline-ready', function ( e ) {
            if ( !e.detail || !e.detail.timeline ) return;
            bindTimeline( e.detail.timeline );
        } );

        var fit = document.querySelector( '.pcp-life-graph-fit' );
        if ( fit ) fit.addEventListener( 'click', function () { render( mount, data ); } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
