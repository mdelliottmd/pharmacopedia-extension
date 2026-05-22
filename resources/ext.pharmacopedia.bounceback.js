/*!
 * Pharmacopedia "bounce-back" helper.
 *
 * Keeps the reader's place across a page-refreshing action - a save,
 * a delete, any POST-then-reload.
 *
 *   CAPTURE  Every form submit stores the current scroll position in
 *            sessionStorage, keyed by the page path. A submit that
 *            navigates to a different path leaves a key that never
 *            matches, so it is harmless.
 *   RESTORE  On the next load of that same path the scroll position
 *            is reapplied - briefly re-asserted so a late layout
 *            shift (a view pane expanding) still lands right - then
 *            cleared. One-shot.
 *
 * Bespoke post-action scroll wins over bounce-back: restore stands
 * down when the URL carries ?saved= (the save flows that scroll to
 * the just-saved record) or when window.pcpBounceBackSuppress is set.
 *
 * window.PCPBounceBack.snapshot() lets a JS-driven reload capture the
 * position explicitly; .restore() forces an early restore.
 */
( function () {
    'use strict';

    var TTL_MS      = 60000;
    var REASSERT_MS = 700;
    var restored    = false;

    function storageKey() {
        return 'pcp-bounceback:' + location.pathname;
    }

    function snapshot() {
        try {
            sessionStorage.setItem( storageKey(), JSON.stringify( {
                y: window.scrollY || window.pageYOffset || 0,
                t: Date.now()
            } ) );
        } catch ( e ) {}
    }

    function clearKey() {
        try { sessionStorage.removeItem( storageKey() ); } catch ( e ) {}
    }

    // Capture on every form submit - save, delete, any page-refreshing
    // form. Keyed by path, so a submit that leaves the page is inert.
    document.addEventListener( 'submit', function () {
        snapshot();
    }, true );

    function restore() {
        if ( restored ) {
            return;
        }
        restored = true;

        // A page that does its own post-action scroll owns the scroll;
        // stand down. That covers a URL #fragment (an anchor redirect,
        // e.g. an added effect's #effect-<slug>), the ?saved= flows that
        // scroll to the saved record, and the explicit opt-out flag.
        if ( window.pcpBounceBackSuppress || location.hash || /[?&]saved=/.test( location.search ) ) {
            clearKey();
            return;
        }

        var raw;
        try { raw = sessionStorage.getItem( storageKey() ); } catch ( e ) { return; }
        if ( !raw ) {
            return;
        }
        clearKey();

        var snap;
        try { snap = JSON.parse( raw ); } catch ( e ) { return; }
        if ( !snap || typeof snap.y !== 'number' ) {
            return;
        }
        if ( snap.t && ( Date.now() - snap.t ) > TTL_MS ) {
            return;
        }

        var targetY  = snap.y;
        var deadline = Date.now() + REASSERT_MS;
        var released = false;
        function release() { released = true; }
        [ 'wheel', 'touchstart', 'keydown', 'mousedown' ].forEach( function ( ev ) {
            window.addEventListener( ev, release, { passive: true, once: true } );
        } );
        function tick() {
            if ( released ) {
                return;
            }
            window.scrollTo( 0, targetY );
            if ( Date.now() < deadline ) {
                window.requestAnimationFrame( tick );
            }
        }
        tick();
    }

    window.PCPBounceBack = { snapshot: snapshot, restore: restore };

    // Automatic restore for pages that do not call restore() themselves.
    if ( document.readyState === 'complete' ) {
        setTimeout( restore, 60 );
    } else {
        window.addEventListener( 'load', function () {
            setTimeout( restore, 60 );
        } );
    }
}() );
