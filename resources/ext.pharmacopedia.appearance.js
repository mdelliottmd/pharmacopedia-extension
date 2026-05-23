/* ext.pharmacopedia.appearance: the collapsible Appearance rail.
   A custom right-edge rail (designer-claude Option B, 2026-05-21):
   collapsed by default to a quiet tab, expands to a panel that docks
   below the topbar and pushes the content left. Two controls:
     - Text size, drives --type-scale on :root (localStorage).
     - Skin, a 4-row swatch radiogroup (Automatic / Pharmaceutical /
       Plants / Fungi). A global per-browser override; the choice is
       a pcp-skin-override cookie that Hooks::onBeforePageDisplay
       reads server-side, so the override is applied before first
       paint with no flash. Picking a skin reloads, the reloaded page
       is server-rendered in the chosen skin.
   Self-contained vanilla JS: injects its own markup. Styling: the
   pcp-rail-* block in ext.pharmacopedia.css. */
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
    function readCookie( name ) {
        var m = doc.cookie.match(
            new RegExp( '(?:^|; )' + name.replace( /[-.]/g, '\\$&' ) + '=([^;]*)' )
        );
        return m ? decodeURIComponent( m[ 1 ] ) : null;
    }
    function writeCookie( name, value ) {
        doc.cookie = name + '=' + encodeURIComponent( value ) +
            '; path=/; max-age=31536000; SameSite=Lax';
    }

    // Text size: restore early (documentElement always exists, even
    // if this runs in <head>).
    var savedScale = readLS( 'pcp-type' );
    if ( savedScale === '0.92' || savedScale === '1' || savedScale === '1.12' ) {
        root.style.setProperty( '--type-scale', savedScale );
    }

    var SKIN_NAME = { pharma: 'Pharmaceutical', plants: 'Plants', fungi: 'Fungi' };

    function build() {
        if ( !doc.body || doc.getElementById( 'pcp-rail-panel' ) ) {
            return;
        }
        doc.body.setAttribute(
            'data-rail', readLS( 'pcp-rail' ) === 'open' ? 'open' : 'closed'
        );

        // S4: Option B replaced Vector's native Appearance dropdown
        // with this rail. Vector's dropdown lingers in the DOM as a
        // 0x0 but still-focusable phantom, with a duplicate
        // "Appearance" landmark. Drop it from the tab order and the
        // accessibility tree (the latter also removes the landmark).
        var vApp = doc.getElementById( 'vector-appearance' );
        if ( vApp ) {
            vApp.setAttribute( 'aria-hidden', 'true' );
            var vChk = doc.getElementById(
                'vector-appearance-dropdown-checkbox'
            );
            if ( vChk ) {
                vChk.tabIndex = -1;
            }
        }

        // WAVE "missing form label": a Vector-core search input
        // renders with no accessible name. Give any unlabelled
        // search input one.
        var pcpSearches = doc.querySelectorAll( 'input[type="search"]' );
        for ( var pcpSi = 0; pcpSi < pcpSearches.length; pcpSi++ ) {
            var pcpS = pcpSearches[ pcpSi ];
            var pcpLabelled = pcpS.getAttribute( 'aria-label' ) ||
                pcpS.getAttribute( 'aria-labelledby' ) ||
                ( pcpS.id && doc.querySelector(
                    'label[for="' + CSS.escape( pcpS.id ) + '"]' ) ) ||
                pcpS.closest( 'label' );
            if ( !pcpLabelled ) {
                pcpS.setAttribute( 'aria-label', 'Search Pharmacopedia' );
            }
        }

        var tab = doc.createElement( 'button' );
        tab.type = 'button';
        tab.id = 'pcp-rail-tab';
        tab.className = 'pcp-rail-tab';
        tab.setAttribute( 'aria-label', 'Open the appearance panel' );
        tab.innerHTML = '<span class="pcp-rail-tab-chev"></span>' +
            '<span class="pcp-rail-tab-label">Appearance</span>';

        // Skin: the per-browser override ('auto' defers to the resolver).
        var override = readCookie( 'pcp-skin-override' );
        if ( [ 'pharma', 'plants', 'fungi', 'auto' ].indexOf( override ) < 0 ) {
            override = 'auto';
        }
        // What the per-page origin resolver gives this page (set by
        // Hooks); the Automatic row reports it.
        var resolved = 'pharma';
        try {
            if ( window.mw && mw.config ) {
                var rs = mw.config.get( 'pcpResolvedSkin' );
                if ( rs === 'plants' || rs === 'fungi' || rs === 'pharma' ) {
                    resolved = rs;
                }
            }
        } catch ( e ) {}

        var skins = [
            [ 'auto', 'Automatic',
                'Follows the page · now ' + SKIN_NAME[ resolved ] ],
            [ 'pharma', 'Pharmaceutical', 'Clinical near-black and violet' ],
            [ 'plants', 'Plants', 'Warm loam, bark and bone' ],
            [ 'fungi', 'Fungi', 'Bruise indigo and spore buff' ]
        ];
        var rows = '';
        for ( var s = 0; s < skins.length; s++ ) {
            var on = skins[ s ][ 0 ] === override;
            rows += '<button type="button" class="pcp-skrow' +
                ( on ? ' pcp-on' : '' ) + '" role="radio" aria-checked="' +
                ( on ? 'true' : 'false' ) + '" tabindex="' +
                ( on ? '0' : '-1' ) + '" data-skin="' + skins[ s ][ 0 ] + '">' +
                '<span class="pcp-sksw pcp-sksw-' + skins[ s ][ 0 ] + '"></span>' +
                '<span class="pcp-skmeta">' +
                    '<span class="pcp-skname">' + skins[ s ][ 1 ] + '</span>' +
                    '<span class="pcp-skdesc">' + skins[ s ][ 2 ] + '</span>' +
                '</span>' +
                '<span class="pcp-skradio"></span></button>';
        }

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
                    '<div class="pcp-rail-seg" role="radiogroup" aria-label="Text size">' +
                        '<button type="button" class="pcp-rail-seg-btn" role="radio" data-scale="0.92">Small</button>' +
                        '<button type="button" class="pcp-rail-seg-btn" role="radio" data-scale="1">Standard</button>' +
                        '<button type="button" class="pcp-rail-seg-btn" role="radio" data-scale="1.12">Large</button>' +
                    '</div>' +
                    '<p class="pcp-rail-note">Saved to this browser. Applies to ' +
                        'article text; the line length adjusts with it.</p>' +
                '</div>' +
                '<div class="pcp-rail-group">' +
                    '<div class="pcp-rail-label">Skin</div>' +
                    '<div class="pcp-sklist" role="radiogroup" aria-label="Skin">' +
                        rows +
                    '</div>' +
                    '<p class="pcp-rail-note">Lock a skin and every page uses ' +
                        'it, until you choose Automatic again.</p>' +
                '</div>' +
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

        // Text size.
        var segBtns = panel.querySelectorAll( '.pcp-rail-seg-btn' );
        var current = ( savedScale === '0.92' || savedScale === '1.12' )
            ? savedScale : '1';
        function paintSeg() {
            for ( var i = 0; i < segBtns.length; i++ ) {
                var on = segBtns[ i ].getAttribute( 'data-scale' ) === current;
                segBtns[ i ].classList.toggle( 'pcp-on', on );
                segBtns[ i ].setAttribute( 'aria-checked', on ? 'true' : 'false' );
                segBtns[ i ].tabIndex = on ? 0 : -1;
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
        // M4: arrow keys move focus within the Text size radiogroup
        // (roving tabindex); Space / Enter fires the native button
        // click. Mirrors the Skin switch radiogroup below.
        panel.querySelector( '.pcp-rail-seg' ).addEventListener(
            'keydown', function ( e ) {
                var nav = e.key === 'ArrowDown' || e.key === 'ArrowUp' ||
                    e.key === 'ArrowRight' || e.key === 'ArrowLeft';
                if ( !nav ) {
                    return;
                }
                e.preventDefault();
                var idx = -1, i;
                for ( i = 0; i < segBtns.length; i++ ) {
                    if ( segBtns[ i ] === doc.activeElement ) {
                        idx = i;
                    }
                }
                if ( idx < 0 ) {
                    return;
                }
                var fwd = e.key === 'ArrowDown' || e.key === 'ArrowRight';
                var next = ( idx + ( fwd ? 1 : -1 ) + segBtns.length ) %
                    segBtns.length;
                for ( i = 0; i < segBtns.length; i++ ) {
                    segBtns[ i ].tabIndex = ( i === next ) ? 0 : -1;
                }
                segBtns[ next ].focus();
            }
        );

        // Skin switch. Picking a skin writes the pcp-skin-override
        // cookie and reloads; the server renders the reloaded page in
        // the chosen skin from byte one, so there is no flash.
        var skRows = panel.querySelectorAll( '.pcp-skrow' );
        function commitSkin( key ) {
            writeCookie( 'pcp-skin-override', key );
            location.reload();
        }
        for ( var k = 0; k < skRows.length; k++ ) {
            skRows[ k ].addEventListener( 'click', function () {
                commitSkin( this.getAttribute( 'data-skin' ) );
            } );
        }
        // Arrow keys move focus within the radiogroup (roving
        // tabindex); Space / Enter on a row fires its click and
        // commits, the native button behaviour.
        panel.querySelector( '.pcp-sklist' ).addEventListener(
            'keydown', function ( e ) {
                var nav = e.key === 'ArrowDown' || e.key === 'ArrowUp' ||
                    e.key === 'ArrowRight' || e.key === 'ArrowLeft';
                if ( !nav ) {
                    return;
                }
                e.preventDefault();
                var idx = -1, i;
                for ( i = 0; i < skRows.length; i++ ) {
                    if ( skRows[ i ] === doc.activeElement ) {
                        idx = i;
                    }
                }
                if ( idx < 0 ) {
                    return;
                }
                var fwd = e.key === 'ArrowDown' || e.key === 'ArrowRight';
                var next = ( idx + ( fwd ? 1 : -1 ) + skRows.length ) %
                    skRows.length;
                for ( i = 0; i < skRows.length; i++ ) {
                    skRows[ i ].tabIndex = ( i === next ) ? 0 : -1;
                }
                skRows[ next ].focus();
            }
        );
    }

    // Special:CreateAccount: the realtime username check renders a
    // VALID result (username available) as a Codex `warning` box -
    // HtmlformChecker maps valid -> 'warning'. It is a positive
    // state. Re-type the username field's warning message as a
    // success message so Codex renders it green with a check; the
    // negative state (username taken) is a Codex `error` and is
    // left as the red error treatment.
    function createAccountUsernameOk() {
        if ( !window.mw || !mw.config ||
            mw.config.get( 'wgCanonicalSpecialPageName' ) !== 'CreateAccount' ) {
            return;
        }
        var field = doc.getElementById( 'wpName2' );
        if ( !field ) {
            return;
        }
        var scope = field.closest( '.cdx-field' ) || field.parentElement;
        if ( !scope ) {
            return;
        }
        function retype() {
            var boxes = scope.querySelectorAll( '.cdx-message--warning' );
            for ( var i = 0; i < boxes.length; i++ ) {
                boxes[ i ].classList.remove( 'cdx-message--warning' );
                boxes[ i ].classList.add(
                    'cdx-message--success', 'pcp-username-available'
                );
            }
        }
        retype();
        try {
            new MutationObserver( retype ).observe( scope, {
                childList: true, subtree: true
            } );
        } catch ( e ) {}
    }

    function ready() {
        build();
        createAccountUsernameOk();
        pcpA11yNitpicks();
        pcpAppearanceFieldsetWatch();
    }

    // Clear WAVE "Accesskey" + "Redundant title text" alerts in one
    // universal pass: Vector skin sticks accesskey= on a dozen nav
    // items (none of them ours) and MediaWiki auto-adds title="X" on
    // wikilinks whose displayed text is also "X" (the footer
    // Pharmacopedia:Copyrights link is one). Both flagged by WAVE.
    function pcpA11yNitpicks() {
        var aks = doc.querySelectorAll( '[accesskey]' );
        for ( var i = 0; i < aks.length; i++ ) {
            aks[ i ].removeAttribute( 'accesskey' );
        }
        var as = doc.querySelectorAll( 'a[title]' );
        for ( var j = 0; j < as.length; j++ ) {
            var t = ( as[ j ].getAttribute( 'title' ) || '' ).trim();
            var x = ( as[ j ].textContent || '' ).trim();
            if ( t && t === x ) { as[ j ].removeAttribute( 'title' ); }
        }
        pcpEmptyLabelCleanup();
        pcpPositiveTabindexStrip();
        pcpProfileTitleHeading();
    }

    // Remove empty <label for="X"> when its target input already
    // has another accessible-name source. The Special:Search OOUI
    // widget renders one of these and WAVE flags it.
    function pcpEmptyLabelCleanup() {
        var labels = doc.querySelectorAll( 'label[for]' );
        for ( var i = 0; i < labels.length; i++ ) {
            var lab = labels[ i ];
            var txt = ( lab.textContent || '' ).trim();
            if ( txt ) { continue; }
            var forId = lab.getAttribute( 'for' );
            if ( !forId ) { continue; }
            var input = doc.getElementById( forId );
            if ( !input ) {
                // orphan label; remove it (it labels nothing).
                lab.parentNode && lab.parentNode.removeChild( lab );
                continue;
            }
            var named = input.getAttribute( 'aria-label' ) ||
                input.getAttribute( 'aria-labelledby' ) ||
                input.getAttribute( 'placeholder' ) ||
                input.getAttribute( 'title' );
            // OOUI widgets carry an ancestor with the rendered
            // label text in .oo-ui-labelElement-label; if present
            // and non-empty, the input has a name source already.
            if ( !named ) {
                var anc = input.closest( '.oo-ui-widget' );
                if ( anc ) {
                    var ooLab = anc.querySelector( '.oo-ui-labelElement-label' );
                    if ( ooLab && ( ooLab.textContent || '' ).trim() ) {
                        named = true;
                    }
                }
            }
            if ( named ) {
                lab.parentNode && lab.parentNode.removeChild( lab );
            }
        }
    }

    // Strip every positive tabindex value (set to 0). DOM order is
    // the right tab order; positive tabindex breaks the rest of
    // the tab ring. Six offenders ship on Special:CreateAccount;
    // pass is universal so anything else MW core adds is caught.
    function pcpPositiveTabindexStrip() {
        var els = doc.querySelectorAll( '[tabindex]' );
        for ( var i = 0; i < els.length; i++ ) {
            var v = parseInt( els[ i ].getAttribute( 'tabindex' ), 10 );
            if ( !isNaN( v ) && v > 0 ) {
                els[ i ].setAttribute( 'tabindex', '0' );
            }
        }
    }

    // SocialProfile renders the user's name on every user profile
    // page as <div id="profile-title">USERNAME</div>; it is the
    // visual page heading but lacks semantic heading markup. Add
    // role="heading" aria-level="2" (Vector's #firstHeading is
    // already aria-level 1; this slots beneath without disturbing
    // the outline). DOM untouched; AT now reads it as a heading.
    function pcpProfileTitleHeading() {
        var pt = doc.getElementById( 'profile-title' );
        if ( !pt || pt.getAttribute( 'role' ) === 'heading' ) { return; }
        pt.setAttribute( 'role', 'heading' );
        pt.setAttribute( 'aria-level', '2' );
    }

    // Wrap the 3 Vector Appearance picker radio groups (text size,
    // page width, color theme) in <fieldset> + <legend class=
    // "visuallyhidden"> for WCAG 1.3.1 / 3.3.2. The portlet ids
    // come from a11y-claude's xpath re-run; the renderer source
    // (skins.vector.clientPreferences/clientPreferences.js) confirms
    // the structure. Idempotent via the .pcp-a11y-fs-wrap marker class.
    function pcpAppearanceFieldsetWrap() {
        var portletIds = [
            'skin-client-prefs-vector-feature-custom-font-size',
            'skin-client-prefs-vector-feature-limited-width',
            'skin-client-prefs-skin-theme'
        ];
        var allDone = true;
        for ( var i = 0; i < portletIds.length; i++ ) {
            var portlet = doc.getElementById( portletIds[ i ] );
            if ( !portlet ) { allDone = false; continue; }
            if ( portlet.querySelector( 'fieldset.pcp-a11y-fs-wrap' ) ) { continue; }
            var ul = portlet.querySelector( 'ul' );
            if ( !ul ) { allDone = false; continue; }
            var heading = portlet.querySelector( '.vector-menu-heading' );
            var headingText = heading ? ( heading.textContent || '' ).trim() : '';
            if ( !headingText ) { headingText = 'Appearance setting'; }
            var fs = doc.createElement( 'fieldset' );
            fs.className = 'pcp-a11y-fs-wrap';
            var lg = doc.createElement( 'legend' );
            lg.className = 'visuallyhidden';
            lg.textContent = headingText;
            fs.appendChild( lg );
            ul.parentNode.insertBefore( fs, ul );
            fs.appendChild( ul );
        }
        return allDone;
    }

    // Vector renders the Appearance panel children lazily after
    // DOMContentLoaded; the container #vector-appearance is in the
    // initial HTML but the radio portlets show up later. Attempt the
    // wrap immediately, and if any portlet is missing, observe the
    // container for childList changes and re-attempt until all three
    // are wrapped, then disconnect.
    function pcpAppearanceFieldsetWatch() {
        if ( pcpAppearanceFieldsetWrap() ) { return; }
        var container = doc.getElementById( 'vector-appearance-pinned-container' ) ||
            doc.getElementById( 'vector-appearance' ) ||
            doc.body;
        if ( !container || typeof MutationObserver === 'undefined' ) { return; }
        try {
            var obs = new MutationObserver( function () {
                if ( pcpAppearanceFieldsetWrap() ) { obs.disconnect(); }
            } );
            obs.observe( container, { childList: true, subtree: true } );
            // safety: stop observing after 30s no matter what
            setTimeout( function () { try { obs.disconnect(); } catch ( e ) {} }, 30000 );
        } catch ( e ) { /* MutationObserver unavailable -- bail silently */ }
    }

    if ( doc.readyState === 'loading' ) {
        doc.addEventListener( 'DOMContentLoaded', ready );
    } else {
        ready();
    }
}() );
