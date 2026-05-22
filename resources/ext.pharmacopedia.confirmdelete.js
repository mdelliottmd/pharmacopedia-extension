/*!
 * Pharmacopedia confirm-delete: a styled red warning prompt that
 * stands in for window.confirm() on destructive actions.
 *
 * Two ways to wire a delete:
 *   - FORM:   <form class="js-pcp-confirm-delete"> - the whole form
 *             is a delete; its submit is intercepted.
 *   - BUTTON: <button class="js-pcp-confirm-delete"> / a link - one
 *             delete control among others in a shared form; its
 *             click is intercepted and the form is submitted with
 *             that button as the submitter on confirm.
 * A control inside an already-marked form is left to the form
 * handler, so the two never both fire.
 *
 * Message: the control's data-pcp-confirm, else (for a form) the
 * form's data-confirm. Scroll is snapshotted (PCPBounceBack) before
 * submit so the reader lands back in place after the reload.
 *
 * window.PCPConfirmDelete( message, onConfirm ) is exposed for code
 * that triggers a delete from JavaScript rather than a form submit.
 *
 * Enter / Return confirms (the Delete button is focused on open);
 * Escape, the Cancel button, the close control and a backdrop click
 * all cancel. Reuses the .pcp-st-modal / .pcp-ix-delconfirm styling.
 */
( function () {
    'use strict';

    var modalOpen = false;

    function buildModal( message, onConfirm ) {
        if ( modalOpen ) {
            return;
        }
        modalOpen = true;

        var overlay = document.createElement( 'div' );
        overlay.className = 'pcp-st-modal pcp-ix-delconfirm';
        overlay.innerHTML =
            '<div class="pcp-st-modal-box pcp-ix-delconfirm-box" role="alertdialog"' +
                ' aria-modal="true" aria-label="Confirm deletion">' +
                '<button type="button" class="pcp-st-modal-close" aria-label="Cancel">×</button>' +
                '<div class="pcp-ix-delconfirm-icon" aria-hidden="true">⚠</div>' +
                '<h3 class="pcp-ix-delconfirm-title">Warning</h3>' +
                '<p class="pcp-ix-delconfirm-body"></p>' +
                '<div class="pcp-st-modal-actions">' +
                    '<button type="button" class="pcp-st-modal-cancel">Cancel</button>' +
                    '<button type="button" class="pcp-ix-delconfirm-go">Delete</button>' +
                '</div>' +
            '</div>';
        overlay.querySelector( '.pcp-ix-delconfirm-body' ).textContent =
            message || 'Delete this item? This cannot be undone.';

        var prevFocus = document.activeElement;

        function teardown() {
            window.removeEventListener( 'keydown', onKey, true );
            if ( overlay.parentNode ) {
                overlay.parentNode.removeChild( overlay );
            }
            modalOpen = false;
        }
        function cancel() {
            teardown();
            if ( prevFocus && typeof prevFocus.focus === 'function' ) {
                try { prevFocus.focus(); } catch ( e ) {}
            }
        }
        function accept() {
            teardown();
            onConfirm();
        }
        function onKey( e ) {
            if ( e.key === 'Escape' ) {
                e.preventDefault();
                cancel();
            }
        }

        overlay.addEventListener( 'click', function ( e ) {
            if ( e.target === overlay ) {
                cancel();
            }
        } );
        overlay.querySelector( '.pcp-st-modal-close' ).addEventListener( 'click', cancel );
        overlay.querySelector( '.pcp-st-modal-cancel' ).addEventListener( 'click', cancel );
        overlay.querySelector( '.pcp-ix-delconfirm-go' ).addEventListener( 'click', accept );
        window.addEventListener( 'keydown', onKey, true );

        document.body.appendChild( overlay );
        // Focus the Delete button so Enter / Return confirms at once.
        overlay.querySelector( '.pcp-ix-delconfirm-go' ).focus();
    }

    function snapshotScroll() {
        if ( window.PCPBounceBack && window.PCPBounceBack.snapshot ) {
            window.PCPBounceBack.snapshot();
        }
    }

    // Intercept the submit of any js-pcp-confirm-delete form.
    document.addEventListener( 'submit', function ( e ) {
        var form = e.target;
        if ( !form || !form.classList ||
             !form.classList.contains( 'js-pcp-confirm-delete' ) ) {
            return;
        }
        e.preventDefault();
        var msg = ( e.submitter && e.submitter.getAttribute( 'data-pcp-confirm' ) ) ||
                  form.getAttribute( 'data-confirm' ) ||
                  form.getAttribute( 'data-pcp-confirm' ) || '';
        buildModal( msg, function () {
            snapshotScroll();
            form.submit();
        } );
    }, true );

    // Intercept a click on a standalone js-pcp-confirm-delete control
    // (button or link). A control inside an already-marked form is
    // left to the submit handler above.
    document.addEventListener( 'click', function ( e ) {
        var el = e.target;
        if ( !el || !el.closest ) {
            return;
        }
        el = el.closest( '.js-pcp-confirm-delete' );
        if ( !el || el.tagName === 'FORM' ) {
            return;
        }
        var form = el.form || el.closest( 'form' );
        if ( form && form.classList.contains( 'js-pcp-confirm-delete' ) ) {
            return;
        }
        e.preventDefault();
        var msg = el.getAttribute( 'data-pcp-confirm' ) ||
                  el.getAttribute( 'data-confirm' ) || '';
        buildModal( msg, function () {
            snapshotScroll();
            if ( form && typeof form.requestSubmit === 'function' ) {
                form.requestSubmit( el.tagName === 'BUTTON' ? el : undefined );
            } else if ( form ) {
                form.submit();
            } else if ( el.tagName === 'A' && el.href ) {
                window.location.href = el.href;
            }
        } );
    }, true );

    // JS API for delete actions that are not plain form submits.
    window.PCPConfirmDelete = function ( message, onConfirm ) {
        buildModal( message, function () {
            if ( typeof onConfirm === 'function' ) {
                onConfirm();
            }
        } );
    };
}() );
