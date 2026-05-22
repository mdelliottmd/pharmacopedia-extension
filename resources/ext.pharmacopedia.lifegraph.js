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
    var hiddenGids = {};

    var H_DEFAULT = 360;
    var ML = 44, MR = 16, MT = 12, MB = 28; // margins inside the SVG viewport

    function colorOf( group ) {
        var style = group && group.style ? String( group.style ) : '';
        var m = style.match( /stroke:\s*([^;]+)/ );
        return m ? m[1].trim() : '#c4b5fd';
    }

    function escapeHtml( s ) { return escapeXml( s ); }

    function escapeXml( s ) {
        return String( s ).replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' ).replace( /'/g, '&apos;' );
    }

    // valenceColor: maps a valence value to an RGB colour. Lifted from
    // ext.pharmacopedia.observation.js verbatim, because each module IIFE
    // is self-contained, the trait dots are filled by their valence.
    function valenceColor( v ) {
        var n = parseFloat( v );
        if ( isNaN( n ) ) n = 0;
        // Anchors: purple #5d3b8e at 0, deep green #15803d at +100, deep red #991b1b at -100.
        var pR = 93, pG = 59, pB = 142;
        var gR = 21, gG = 128, gB = 61;
        var rR = 153, rG = 27, rB = 27;
        var t, r, g, b;
        if ( n >= 0 ) {
            t = Math.min( 1, n / 100 );
            r = Math.round( pR + ( gR - pR ) * t );
            g = Math.round( pG + ( gG - pG ) * t );
            b = Math.round( pB + ( gB - pB ) * t );
        } else {
            t = Math.min( 1, -n / 100 );
            r = Math.round( pR + ( rR - pR ) * t );
            g = Math.round( pG + ( rG - pG ) * t );
            b = Math.round( pB + ( rB - pB ) * t );
        }
        return 'rgb(' + r + ',' + g + ',' + b + ')';
    }

    function render( mount, data ) {
        var W = mount.clientWidth || 900;
        var H = H_DEFAULT;
        var PW = Math.max( 100, W - ML - MR );
        var PH = H - MT - MB;

        var items = data.items || [];
        if ( !items.length ) {
            mount.innerHTML = '<p class="pcp-life-graph-empty">No trait trajectories yet. Take an assessment, or add 2+ observations with the same trait name.</p>';
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
            // pcp-yaxis-aligned: with swimlanes removed, .vis-panel.vis-left collapses
            // to zero width, breaking the dynamic ML computation. We force a fixed 52px
            // both here AND in CSS on .vis-panel.vis-left so the two plot areas share
            // an identical left edge. 52 leaves room for "100%" Y-axis labels (4 chars,
            // text-anchor=end at ML-6, so label extends from ~X=14 to X=46, with safe
            // breathing room at the SVG edge).
            ML = 40;
            // pcp-mr-aligned: vis-timeline's content area extends to the full right
            // edge (no vis-panel.vis-right). Match it by zeroing MR in overlay mode,
            // otherwise the trait line's right end drifts left of where the time-axis
            // year labels sit.
            MR = 0;
            PW = Math.max( 100, W - ML - MR );
            // In overlay mode the timeline shows the time axis. Suppress our own bottom labels.
            MB = 8;
            PH = H - MT - MB;
        }

        // Y axis: dynamic. Scan items for out-of-range values; extend the
        // axis to fit while keeping 0 and 100 as visible anchors. Pick a
        // step that yields ~4-7 readable tick labels.
        var dataMin = 0, dataMax = 100;
        items.forEach( function ( it ) {
            if ( typeof it.y === 'number' && !isNaN( it.y ) ) {
                if ( it.y < dataMin ) dataMin = it.y;
                if ( it.y > dataMax ) dataMax = it.y;
            }
        } );
        function pickYStep( range ) {
            if ( range <= 100 )  return 25;
            if ( range <= 200 )  return 50;
            if ( range <= 500 )  return 100;
            if ( range <= 1000 ) return 250;
            return Math.ceil( range / 6 / 250 ) * 250;
        }
        var yStep = pickYStep( dataMax - dataMin );
        var yMin = Math.floor( dataMin / yStep ) * yStep;
        var yMax = Math.ceil(  dataMax / yStep ) * yStep;
        // Always keep [0, 100] inside the visible range as anchors.
        if ( yMin > 0 )   yMin = 0;
        if ( yMax < 100 ) yMax = 100;
        var yRange = ( yMax - yMin ) || 100;
        var yExtended = ( yMin < 0 || yMax > 100 );

        function xPx( t ) { return ML + ( ( t - tMin ) / tRange ) * PW; }
        function yPx( v ) { return MT + PH - ( ( v - yMin ) / yRange ) * PH; }

        var parts = [];
        parts.push( '<svg class="pcp-trait-svg" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet" width="100%" height="' + H + '">' );
        parts.push( '<defs><clipPath id="pcp-trait-clip"><rect x="' + ML + '" y="' + MT + '" width="' + PW + '" height="' + PH + '"/></clipPath></defs>' );

        // Y gridlines + labels — step-sized to keep ~5 ticks visible.
        // 0 and 100 get an extra "anchor" class when the axis is extended,
        // so the user always sees the "normal" 0-100 range marked.
        for ( var v = yMin; v <= yMax + 0.0001; v += yStep ) {
            var py = yPx( v );
            var anchorCls = ( yExtended && ( v === 0 || v === 100 ) ) ? ' pcp-trait-grid-anchor' : '';
            parts.push( '<line class="pcp-trait-grid' + anchorCls + '" x1="' + ML + '" y1="' + py + '" x2="' + ( W - MR ) + '" y2="' + py + '"/>' );
            // Format label: integer + %, no trailing .0
            var lbl = ( Math.round( v * 10 ) / 10 );
            parts.push( '<text class="pcp-trait-axis-label' + anchorCls + '" x="' + ( ML - 6 ) + '" y="' + ( py + 3 ) + '" text-anchor="end">' + lbl + '%</text>' );
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
            if ( hiddenGids[ g.id ] ) return;
            var color = colorOf( g );
            var pts = items.filter( function ( it ) { return it.group === g.id; } );
            pts.sort( function ( a, b ) { return new Date( a.x ) - new Date( b.x ); } );
            if ( !pts.length ) return;
            var coords = pts.map( function ( p ) {
                return xPx( new Date( p.x ).getTime() ) + ',' + yPx( p.y );
            } ).join( ' ' );
            parts.push( '<polyline class="pcp-trait-line" clip-path="url(#pcp-trait-clip)" data-gid="' + escapeXml( g.id ) + '" points="' + coords + '" style="stroke:' + escapeXml( color ) + ';"><title>' + escapeXml( g.content || g.id ) + '</title></polyline>' );
            pts.forEach( function ( p ) {
                var cx = xPx( new Date( p.x ).getTime() );
                var cy = yPx( p.y );
                var rawY = ( p.rawY !== undefined && p.rawY !== null ) ? p.rawY : p.y;
                var dotFill = valenceColor( p.valence ) || color;
                var title = escapeXml( ( g.content || g.id ) + ' · ' + ( typeof rawY === 'number' ? rawY.toFixed( 1 ) : rawY ) + ' (' + Math.round( p.y ) + '%) · ' + String( p.x ).slice( 0, 10 ) );
                parts.push( '<circle class="pcp-trait-dot" clip-path="url(#pcp-trait-clip)" data-gid="' + escapeXml( g.id ) + '" cx="' + cx + '" cy="' + cy + '" r="3.5" style="fill:' + escapeXml( dotFill ) + ';"><title>' + title + '</title></circle>' );
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
        var totalCount = data.groups ? data.groups.length : 0;
        // pcp-life-graph-legend-preview: 4 preview chips + "+N more" inline beside the disclosure
        var labelSpan = document.createElement( 'span' );
        labelSpan.className = 'pcp-life-graph-legend-label';
        labelSpan.textContent = 'Trait series (' + totalCount + ')';
        summary.appendChild( labelSpan );
        var previewWrap = document.createElement( 'span' );
        previewWrap.className = 'pcp-life-graph-legend-preview';
        var previewN = Math.min( 4, totalCount );
        for ( var i = 0; i < previewN; i++ ) {
            var pg = data.groups[i];
            var pcolor = ( pg.style ? pg.style.match( /stroke:\s*([^;]+)/ ) : null );
            pcolor = pcolor ? pcolor[1].trim() : '#c4b5fd';
            var pchip = document.createElement( 'span' );
            pchip.className = 'pcp-life-graph-legend-preview-chip';
            pchip.innerHTML = '<span class="pcp-life-graph-legend-preview-swatch" style="background:' + pcolor + '"></span>' +
                '<span class="pcp-life-graph-legend-preview-text">' + escapeHtml( pg.content ) + '</span>';
            previewWrap.appendChild( pchip );
        }
        if ( totalCount > previewN ) {
            var more = document.createElement( 'span' );
            more.className = 'pcp-life-graph-legend-preview-more';
            more.textContent = '+' + ( totalCount - previewN ) + ' more';
            previewWrap.appendChild( more );
        }
        summary.appendChild( previewWrap );
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
            if ( !e.target || e.target.tagName !== 'INPUT' ) return;
            var gid = e.target.getAttribute( 'data-gid' );
            if ( gid === null ) return;
            if ( e.target.checked ) { delete hiddenGids[ gid ]; }
            else { hiddenGids[ gid ] = true; }
            var m = document.getElementById( 'pcp-life-graph-mount' );
            if ( m && window.PCP_LIFE_GRAPH_DATA ) render( m, window.PCP_LIFE_GRAPH_DATA );
        } );
    }


    function init() {
        var mount = document.getElementById( 'pcp-life-graph-mount' );
        var data = window.PCP_LIFE_GRAPH_DATA;
        if ( !mount || !data ) return;
        render( mount, data );
        bindHover( mount );
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


    // pcp-trait-hover-binding: highlight the full series on hover.
    // Delegated to the mount; matches lines + dots by data-gid.
    function bindHover( mount ) {
        if ( !mount ) return;
        mount.addEventListener( 'mouseover', function ( e ) {
            var el = e.target;
            var gid = el.getAttribute && el.getAttribute( 'data-gid' );
            if ( !gid ) return;
            mount.querySelectorAll( '[data-gid="' + gid + '"]' )
                .forEach( function ( n ) { n.classList.add( 'is-active' ); } );
        } );
        mount.addEventListener( 'mouseout', function ( e ) {
            var el = e.target;
            var gid = el.getAttribute && el.getAttribute( 'data-gid' );
            if ( !gid ) return;
            mount.querySelectorAll( '[data-gid="' + gid + '"]' )
                .forEach( function ( n ) { n.classList.remove( 'is-active' ); } );
        } );
    }

}() );
