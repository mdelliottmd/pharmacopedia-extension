/*!
 * Trait-trajectory line graph using vis.Graph2d (shipped in vis-timeline bundle).
 * Reads window.PCP_LIFE_GRAPH_DATA = { groups: [...], items: [...] } and renders
 * smooth lines into #pcp-life-graph-mount.
 */
( function () {
    'use strict';
    function init() {
        var mount = document.getElementById( 'pcp-life-graph-mount' );
        var data  = window.PCP_LIFE_GRAPH_DATA;
        if ( !mount || !data || typeof vis === 'undefined' ) return;
        if ( !data.items || !data.items.length ) {
            mount.innerHTML = '<p class="pcp-life-graph-empty">No trait trajectories yet — take an assessment (or add 2+ keyframes with the same name) to see a line.</p>';
            return;
        }
        var itemsDS  = new vis.DataSet( data.items  );
        var groupsDS = new vis.DataSet( data.groups );

        var options = {
            interpolation: { enabled: true, parametrization: 'centripetal' },
            drawPoints: { size: 6, style: 'circle' },
            graphHeight: '320px',
            shaded: false,
            showMajorLabels: true,
            showMinorLabels: true,
            zoomKey: 'ctrlKey',
            legend: { enabled: true, icons: true, left: { position: 'top-left' } }
        };

        var graph = new vis.Graph2d( mount, itemsDS, groupsDS, options );

        var fitBtn = document.querySelector( '.pcp-life-graph-fit' );
        if ( fitBtn ) fitBtn.addEventListener( 'click', function () { graph.fit( { animation: true } ); } );

        // Auto-fit on init.
        setTimeout( function () { graph.fit( { animation: false } ); }, 80 );
    }
    if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', init );
    else init();
}() );
