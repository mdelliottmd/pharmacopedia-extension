/*!
 * Back-compat sync: when the new PCPDatePicker / PCPTimePicker widgets update
 * their inner hidden inputs, mirror the value into the legacy storage hidden
 * (.pcp-smoking-quit, .pcp-chronotype-bed, .pcp-chronotype-wake) and re-run
 * the parent widget's aggregator.
 */
( function () {
    'use strict';

    function syncSmokingQuit( pickerDiv ) {
        var $hidden = $( pickerDiv ).find( 'input[type="hidden"]' );
        if ( !$hidden.length ) return;
        var raw = $hidden.val();
        var iso = '';
        if ( raw ) {
            try {
                var d = JSON.parse( raw );
                if ( d && d.point && d.point.parsed && /^\d{4}-\d{2}-\d{2}$/.test( d.point.parsed.iso || '' ) ) {
                    iso = d.point.parsed.iso;
                }
            } catch ( e ) {}
        }
        var widget = $( pickerDiv ).closest( '.pcp-smoking-widget' );
        var legacy = widget.find( '.pcp-smoking-quit' );
        legacy.val( iso ).trigger( 'change' );
    }

    function syncChronotype( pickerDiv ) {
        var widget = $( pickerDiv ).closest( '.pcp-chronotype-widget' );
        if ( !widget.length ) return;
        // PCPTimePicker writes its formatted HH:MM:SS into its inner hidden.
        var bedHidden = widget.find( '.pcp-chronotype-bed-picker input[type="hidden"]' ).val() || '';
        var wakHidden = widget.find( '.pcp-chronotype-wake-picker input[type="hidden"]' ).val() || '';
        // Strip seconds for the back-compat (HH:MM) storage shape.
        var bed = bedHidden.length >= 5 ? bedHidden.slice( 0, 5 ) : '';
        var wak = wakHidden.length >= 5 ? wakHidden.slice( 0, 5 ) : '';
        widget.find( '.pcp-chronotype-bed' ).val( bed );
        widget.find( '.pcp-chronotype-wake' ).val( wak );
        // The outer .pcp-chronotype-hidden is what blocksave reads; update it with the aggregated JSON.
        var json = ( bed || wak ) ? JSON.stringify( { bedtime: bed, waketime: wak } ) : '';
        widget.find( '.pcp-chronotype-hidden' ).val( json ).trigger( 'change' );
    }

    $( document ).on( 'change', '.pcp-smoking-quit-picker input[type="hidden"]', function () {
        syncSmokingQuit( $( this ).closest( '.pcp-smoking-quit-picker' )[0] );
    } );

    $( document ).on( 'change', '.pcp-chronotype-bed-picker input[type="hidden"], .pcp-chronotype-wake-picker input[type="hidden"]', function () {
        syncChronotype( $( this ).closest( '.pcp-time-input' )[0] );
    } );

    // Phase 6: privacy-mode toggle (creates/revokes a *-wide 'private' rule)
    function syncPrivacyMode() {
        var api = new mw.Api();
        api.get( {
            action: 'pharmacopediavisrules',
            op: 'list',
            namespace: '*'
        } ).done( function ( resp ) {
            var existing = null;
            $.each( resp.rules || [], function ( i, r ) {
                if ( !r.revoked && r.type === 'private' && r.namespace === '*' && !r.key ) {
                    existing = r; return false;
                }
            } );
            $( '.pcp-privacy-mode' ).prop( 'checked', !!existing );
        } );
    }
    $( document ).on( 'change', '.pcp-privacy-mode', function () {
        var on = this.checked;
        var api = new mw.Api();
        api.get( {
            action: 'pharmacopediavisrules',
            op: 'list',
            namespace: '*'
        } ).done( function ( resp ) {
            var existing = null;
            $.each( resp.rules || [], function ( i, r ) {
                if ( !r.revoked && r.type === 'private' && r.namespace === '*' && !r.key ) {
                    existing = r; return false;
                }
            } );
            if ( on && !existing ) {
                api.postWithToken( 'csrf', {
                    action: 'pharmacopediavisrules',
                    op: 'create',
                    type: 'private',
                    namespace: '*',
                    payload: '{}'
                } );
            } else if ( !on && existing ) {
                api.postWithToken( 'csrf', {
                    action: 'pharmacopediavisrules',
                    op: 'revoke',
                    rule_id: existing.id
                } );
            }
        } );
    } );
    $( syncPrivacyMode );

}() );
