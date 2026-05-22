/* ext.pharmacopedia.appearance: the collapsible Appearance rail.
   A custom right-edge rail (designer-claude Option B, 2026-05-21):
   collapsed by default to a quiet tab, expands to a panel that docks
   below the topbar and pushes the content left. One control, Text
   size, drives --type-scale on :root. Self-contained vanilla JS:
   injects its own markup, persists open/closed + text size per
   browser. Styling: the pcp-rail-* block in ext.pharmacopedia.css. */
( function () {
    'use strict';
    var doc = document;
    var root = doc.documentElement;

    function readLS( k ) {
        try { return localStorage.getItem( k ); } catch ( e ) { return null; }
    }
    function writeLS( k, v ) {
        try { localStorage.setItem( k, v ); } catch ( e ) {}
    }

    // Text size: restore early (documentElement always exists, even
    // if this runs in <head>).
    var savedScale = readLS( 'pcp-type' );
    if ( savedScale === '0.92' || savedScale === '1' || savedScale === '1.12' ) {
        root.style.setProperty( '--type-scale', savedScale );
    }

    function build() {
        if ( !doc.body || doc.getElementById( 'pcp-rail-panel' ) ) {
            return;
        }
        doc.body.setAttribute(
            'data-rail', readLS( 'pcp-rail' ) === 'open' ? 'open' : 'closed'
        );

        var tab = doc.createElement( 'button' );
        tab.type = 'button';
        tab.id = 'pcp-rail-tab';
        tab.className = 'pcp-rail-tab';
        tab.setAttribute( 'aria-label', 'Open the appearance panel' );
        tab.innerHTML = '<span class="pcp-rail-tab-chev"></span>' +
            '<span class="pcp-rail-tab-label">Appearance</span>';

        var panel = doc.createElement( 'div' );
        panel.id = 'pcp-rail-panel';
        panel.className = 'pcp-rail-panel';
        panel.setAttribute( 'role', 'region' );
        panel.setAttribute( 'aria-label', 'Appearance' );
        panel.innerHTML =
            '<div class="pcp-rail-head">' +
                '<span class="pcp-rail-title">Appearance</span>' +
                '<button type="button" class="pcp-rail-collapse" ' +
                    'aria-label="Collapse the appearance panel">' +
                    '<span class="pcp-rail-collapse-chev"></span>' +
                '</button>' +
            '</div>' +
            '<div class="pcp-rail-body">' +
                '<div class="pcp-rail-group">' +
                    '<div class="pcp-rail-label">Text size</div>' +
                    '<div class="pcp-rail-seg" role="group" aria-label="Text size">' +
                        '<button type="button" class="pcp-rail-seg-btn" data-scale="0.92">Small</button>' +
                        '<button type="button" class="pcp-rail-seg-btn" data-scale="1">Standard</button>' +
                        '<button type="button" class="pcp-rail-seg-btn" data-scale="1.12">Large</button>' +
                    '</div>' +
                '</div>' +
                '<p class="pcp-rail-note">Saved to this browser. Applies to ' +
                    'article text; the line length adjusts with it.</p>' +
            '</div>';

        doc.body.appendChild( tab );
        doc.body.appendChild( panel );

        function setRail( open ) {
            doc.body.setAttribute( 'data-rail', open ? 'open' : 'closed' );
            writeLS( 'pcp-rail', open ? 'open' : 'closed' );
        }
        tab.addEventListener( 'click', function () { setRail( true ); } );
        panel.querySelector( '.pcp-rail-collapse' ).addEventListener(
            'click', function () { setRail( false ); }
        );

        var segBtns = panel.querySelectorAll( '.pcp-rail-seg-btn' );
        var current = ( savedScale === '0.92' || savedScale === '1.12' )
            ? savedScale : '1';
        function paintSeg() {
            for ( var i = 0; i < segBtns.length; i++ ) {
                segBtns[ i ].classList.toggle(
                    'pcp-on',
                    segBtns[ i ].getAttribute( 'data-scale' ) === current
                );
            }
        }
        paintSeg();
        for ( var j = 0; j < segBtns.length; j++ ) {
            segBtns[ j ].addEventListener( 'click', function () {
                current = this.getAttribute( 'data-scale' );
                root.style.setProperty( '--type-scale', current );
                writeLS( 'pcp-type', current );
                paintSeg();
            } );
        }
    }

    if ( doc.readyState === 'loading' ) {
        doc.addEventListener( 'DOMContentLoaded', build );
    } else {
        build();
    }
}() );
