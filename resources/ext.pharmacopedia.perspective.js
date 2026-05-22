/*
 * Perspective subsystem: client enhancement for Special:Perspective.
 * The AMAAS-PCP-OR observer form is CSP-clean (no inline JS); this module
 * adds the per-slider value readout and the live progress indicator.
 * Plain DOM, no dependencies. designer-claude handoff 2026-05-20.
 */
( function () {
    'use strict';

    function init() {
        var form = document.querySelector( '.pcp-amaas-or-form' );
        if ( !form ) {
            return;
        }
        var countEl = form.querySelector( '.pcp-perspective-progress-count' );
        var barEl = form.querySelector( '.pcp-perspective-progress-bar' );
        var progress = form.querySelector( '.pcp-perspective-progress' );
        var records = [];

        function refresh() {
            var n = 0;
            records.forEach( function ( r ) {
                if ( r.touched || r.idk ) {
                    n++;
                }
            } );
            var total = records.length;
            if ( progress && progress.getAttribute( 'data-total' ) ) {
                total = parseInt( progress.getAttribute( 'data-total' ), 10 ) || total;
            }
            if ( countEl ) {
                countEl.textContent = n + ' of ' + total + ' answered';
            }
            if ( barEl ) {
                barEl.style.setProperty( '--pcp-progress',
                    ( total ? Math.round( ( n / total ) * 100 ) : 0 ) + '%' );
            }
        }

        Array.prototype.forEach.call(
            form.querySelectorAll( '.pcp-perspective-item' ),
            function ( item ) {
                var rec = { touched: false, idk: false };
                records.push( rec );
                var slider = item.querySelector( '.pcp-perspective-slider' );
                var out = item.querySelector( '.pcp-perspective-out' );
                var idk = item.querySelector( '.pcp-perspective-idk input' );
                if ( slider ) {
                    slider.addEventListener( 'input', function () {
                        if ( out ) {
                            out.textContent = slider.value;
                        }
                        rec.touched = true;
                        refresh();
                    } );
                }
                if ( idk ) {
                    idk.addEventListener( 'change', function () {
                        rec.idk = idk.checked;
                        refresh();
                    } );
                }
            }
        );

        refresh();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );


/*
 * Perspective subsystem, surface 3 confirms: on Special:MyPerspectives
 * "Consent to publish" and "Delete" each require a confirm step,
 * never single-click. designer-claude handoff 2026-05-20.
 */
( function () {
    'use strict';
    document.addEventListener( 'submit', function ( e ) {
        var form = e.target;
        if ( !form || !form.classList ||
            !form.classList.contains( 'pcp-perspectives-inline-form' ) ) {
            return;
        }
        var field = form.querySelector( 'input[name="do"]' );
        var op = field ? field.value : '';
        var msg;
        if ( op === 'consent' ) {
            msg = 'Publish this perspective? It becomes visible to others ' +
                'per your profile visibility settings. You can withdraw ' +
                'consent later, which returns it to private.';
        } else if ( op === 'delete' ) {
            msg = 'Delete this perspective? This permanently removes it ' +
                'and cannot be undone.';
        } else {
            return;
        }
        if ( op === 'delete' ) {
            if ( form.dataset.pcpConfirmed === '1' ) {
                delete form.dataset.pcpConfirmed;
                return;
            }
            e.preventDefault();
            window.PCPConfirmDelete( msg, function () {
                form.dataset.pcpConfirmed = '1';
                form.requestSubmit();
            } );
            return;
        }
        if ( !window.confirm( msg ) ) {
            e.preventDefault();
        }
    } );
}() );
