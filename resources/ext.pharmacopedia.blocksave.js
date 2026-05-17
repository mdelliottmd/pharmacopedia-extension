/**
 * pcp-block-save — inline AJAX auto-save for editable form blocks.
 *
 * Drop-in markup:
 *   <div data-pcp-save-block="ocean">
 *     ...inputs...
 *   </div>
 *
 * Behavior:
 *   - On any input change inside the block, an "auto-save" timer is set
 *     for AUTOSAVE_DELAY_MS after the last change.
 *   - When the timer fires, the block POSTs to Special:SaveProfileBlock.
 *   - A small status chip floats top-right of the block during the save
 *     and briefly after ("✓ saved"), then fades. A second chip sits at
 *     the bottom of the block for the same status feedback.
 *   - Chips are also clickable — clicking forces an immediate save
 *     (cancels the debounce window).
 *   - Race-safe: if the user keeps typing while a save is in flight,
 *     the in-flight save still records the snapshot it sent, and any
 *     newer changes mark the block dirty again and trigger another
 *     debounced save when the response returns.
 *
 * Public API:
 *   window.PCPBlockSave.init( element )     — wire one block manually
 *   window.PCPBlockSave.autoInit()          — find + wire all blocks
 */
( function ( root ) {
    'use strict';

    var AUTOSAVE_DELAY_MS = 800;       // wait this long after last change before auto-saving
    var SAVED_FADE_MS     = 1200;      // hide the "✓ saved" chip after this long

    function getEditToken() {
        var el = document.querySelector( 'input[name="wpEditToken"]' );
        return el ? el.value : '';
    }

    function getSaveUrl() {
        if ( root.mw && root.mw.util && root.mw.util.getUrl ) {
            return root.mw.util.getUrl( 'Special:SaveProfileBlock' );
        }
        return '/index.php/Special:SaveProfileBlock';
    }

    function serializeBlock( blockEl ) {
        var parts = [];
        blockEl.querySelectorAll( 'input, select, textarea' ).forEach( function ( el ) {
            if ( el.type === 'submit' || el.type === 'button' ) return;
            if ( !el.name ) return;
            // Add-row inputs are excluded from dirty-detection — they require
            // an explicit "+ Add" button click to commit, not autosave.
            if ( el.name.indexOf( 'dx_new[' ) === 0 ) return;
            if ( el.name.indexOf( 'um_new[' ) === 0 ) return;
            if ( el.type === 'checkbox' || el.type === 'radio' ) {
                parts.push( el.name + '=' + ( el.checked ? el.value : '' ) );
            } else {
                parts.push( el.name + '=' + ( el.value || '' ) );
            }
        } );
        return parts.join( '|' );
    }

    function collectInputs( blockEl, formData ) {
        blockEl.querySelectorAll( 'input, select, textarea' ).forEach( function ( el ) {
            if ( el.type === 'submit' || el.type === 'button' ) return;
            if ( !el.name ) return;
            if ( el.type === 'checkbox' || el.type === 'radio' ) {
                if ( el.checked ) formData.append( el.name, el.value );
            } else {
                formData.append( el.name, el.value );
            }
        } );
    }

    function attach( blockEl ) {
        if ( blockEl.dataset.pcpBsInit === '1' ) return;
        blockEl.dataset.pcpBsInit = '1';

        var blockName = blockEl.dataset.pcpSaveBlock;
        if ( !blockName ) return;

        var initial = serializeBlock( blockEl );
        var isDirty = false;
        var saveTimer = null;
        var saveInFlight = false;

        // Some blocks have an "add a row" pattern (dx_new[], um_new[]) where
        // a successful save creates a new server-side row that the live form
        // doesn't yet know about — leaving the new-row slot filled and the
        // next autosave would re-create it. For those, reload the page after
        // a successful save so the new row appears in the existing list and
        // a fresh add-slot renders.
        function blockNeedsReloadAfterSave() {
            var newInputs = blockEl.querySelectorAll(
                'input[name^="dx_new["][name$="[description]"], ' +
                'input[name^="um_new["][name$="[med_name]"]'
            );
            for ( var i = 0; i < newInputs.length; i++ ) {
                if ( ( newInputs[ i ].value || '' ).trim() !== '' ) return true;
            }
            return false;
        }

        function mkChip( extraClass ) {
            var c = document.createElement( 'button' );
            c.type = 'button';
            c.className = 'pcp-block-save-chip' + ( extraClass ? ' ' + extraClass : '' );
            c.textContent = 'auto-save';
            c.title = 'Click to save immediately';
            return c;
        }

        var chipTop = mkChip();
        chipTop.style.display = 'none';
        blockEl.appendChild( chipTop );

        var bottomWrap = document.createElement( 'div' );
        bottomWrap.className = 'pcp-block-save-chip-bottom-wrap';
        bottomWrap.style.display = 'none';
        var chipBottom = mkChip( 'pcp-block-save-chip-bottom' );
        bottomWrap.appendChild( chipBottom );
        blockEl.appendChild( bottomWrap );

        var chips = [ chipTop, chipBottom ];

        function setText( text ) {
            chips.forEach( function ( c ) { c.textContent = text; } );
        }
        function setClass( cls ) {
            chips.forEach( function ( c ) {
                c.classList.remove( 'saving', 'saved', 'error' );
                if ( cls ) c.classList.add( cls );
            } );
        }
        function reset() {
            setClass( '' );
            setText( 'auto-save' );
        }
        function show( visible ) {
            chipTop.style.display = visible ? 'inline-block' : 'none';
            bottomWrap.style.display = visible ? 'block' : 'none';
        }

        function scheduleAutosave() {
            if ( saveTimer ) clearTimeout( saveTimer );
            saveTimer = setTimeout( function () {
                saveTimer = null;
                if ( isDirty && !saveInFlight ) doSave();
            }, AUTOSAVE_DELAY_MS );
        }

        function checkDirty() {
            var current = serializeBlock( blockEl );
            var dirty = current !== initial;
            if ( dirty !== isDirty ) {
                isDirty = dirty;
                show( dirty );
                if ( dirty ) {
                    setClass( '' );
                    setText( 'pending…' );
                } else {
                    reset();
                }
            }
            if ( dirty ) scheduleAutosave();
        }

        blockEl.addEventListener( 'input', checkDirty );
        blockEl.addEventListener( 'change', checkDirty );

        function doSave( e, force ) {
            if ( e && e.preventDefault ) e.preventDefault();
            if ( saveTimer ) { clearTimeout( saveTimer ); saveTimer = null; }
            if ( !isDirty && !force ) return;
            if ( saveInFlight ) return;
            if ( force ) isDirty = true;  // force path bypasses dirty gate

            var sentState = serializeBlock( blockEl );
            saveInFlight = true;
            setClass( 'saving' );
            setText( 'saving…' );

            var formData = new FormData();
            formData.append( 'block', blockName );
            formData.append( 'wpEditToken', getEditToken() );
            collectInputs( blockEl, formData );

            fetch( getSaveUrl(), {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            } )
            .then( function ( r ) {
                return r.json().catch( function () { return { ok: false, error: 'bad response' }; } );
            } )
            .then( function ( data ) {
                saveInFlight = false;
                if ( data && data.ok ) {
                    initial = sentState;
                    if ( blockNeedsReloadAfterSave() ) {
                        setClass( 'saved' );
                        setText( '✓ added — refreshing…' );
                        setTimeout( function () { if ( window.pcpSaveScroll ) window.pcpSaveScroll(); window.location.reload(); }, 400 );
                        return;
                    }
                    var current = serializeBlock( blockEl );
                    if ( current !== initial ) {
                        // user typed during save; mark dirty + reschedule
                        isDirty = true;
                        show( true );
                        setClass( '' );
                        setText( 'pending…' );
                        scheduleAutosave();
                    } else {
                        isDirty = false;
                        setClass( 'saved' );
                        setText( '✓ saved' );
                        setTimeout( function () {
                            if ( !isDirty && !saveInFlight ) {
                                show( false );
                                reset();
                            }
                        }, SAVED_FADE_MS );
                    }
                } else {
                    setClass( 'error' );
                    setText( '✗ ' + ( data && data.error ? data.error : 'failed — click to retry' ) );
                }
            } )
            .catch( function () {
                saveInFlight = false;
                setClass( 'error' );
                setText( '✗ network — click to retry' );
            } );
        }

        blockEl.addEventListener( 'pcp-force-save', function () { doSave( null, true ); } );
        chipTop.addEventListener( 'click', doSave );
        chipBottom.addEventListener( 'click', doSave );
    }

    function autoInit() {
        document.querySelectorAll( '[data-pcp-save-block]' ).forEach( attach );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', autoInit );
    } else {
        autoInit();
    }

    root.PCPBlockSave = { init: attach, autoInit: autoInit };
} )( window );
