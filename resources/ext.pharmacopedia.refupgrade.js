/*!
 * Bulk linker for free-text refs on Special:MyRefLinks.
 */
( function () {
    'use strict';
    var api = new mw.Api();

    function init() {
        var $list = $( '.pcp-reflinks-list' );
        if ( !$list.length ) return;
        load( $list );
    }

    function load( $list ) {
        $list.html( '<p class="pcp-loading">Scanning...</p>' );
        api.get( { action: 'pharmacopediarefupgrade', op: 'candidates' } ).done( function ( resp ) {
            renderCandidates( $list, resp.candidates || [] );
        } ).fail( function ( e ) {
            $list.html( '<p class="pcp-reflinks-error">Error: ' + e + '</p>' );
        } );
    }

    function renderCandidates( $list, cands ) {
        $list.empty();
        if ( !cands.length ) {
            $list.append( '<p class="pcp-reflinks-empty">No unmatched free-text refs with available matches. ' +
                'Either everything is already linked, or no new catalog entries match your free-text refs yet.</p>' );
            return;
        }
        $list.append( '<p class="pcp-reflinks-count"><strong>' + cands.length + '</strong> free-text references found with potential matches.</p>' );
        cands.forEach( function ( c ) {
            $list.append( renderOne( c ) );
        } );
    }

    function renderOne( c ) {
        var $card = $( '<div class="pcp-reflinks-card">' );
        var refIdsStr = c.ref_ids.join( ',' );
        $card.append(
            $( '<div class="pcp-reflinks-card-head">' ).append(
                $( '<strong>' ).text( '"' + c.text + '"' ),
                $( '<span class="pcp-reflinks-meta">' ).text(
                    ' (' + c.ref_ids.length + ' ref' + ( c.ref_ids.length === 1 ? '' : 's' ) +
                    ' in ' + c.event_ids.length + ' event' + ( c.event_ids.length === 1 ? '' : 's' ) + ')'
                )
            )
        );
        var $matches = $( '<div class="pcp-reflinks-matches">' );
        c.matches.forEach( function ( m ) {
            $matches.append(
                $( '<button type="button" class="pcp-btn pcp-reflinks-match-btn">' )
                    .text( m.label + ' [' + m.type + ']' )
                    .data( { refIds: c.ref_ids, newType: m.type, newId: m.id, text: c.text, matchLabel: m.label } )
                    .on( 'click', function () { applyOne( $card, $( this ).data() ); } )
            );
        } );
        // Dismiss all refs for this text
        $matches.append(
            $( '<button type="button" class="pcp-btn pcp-btn-danger pcp-reflinks-dismiss-btn">' )
                .text( 'Dismiss' )
                .data( { refIds: c.ref_ids } )
                .on( 'click', function () { dismissAll( $card, $( this ).data() ); } )
        );
        $card.append( $matches );
        return $card;
    }

    function applyOne( $card, data ) {
        $card.find( 'button' ).prop( 'disabled', true );
        var pending = data.refIds.length;
        var failed = 0;
        data.refIds.forEach( function ( rid ) {
            api.postWithToken( 'csrf', {
                action: 'pharmacopediarefupgrade',
                op: 'apply',
                ref_id: rid,
                new_type: data.newType,
                new_ref_id: data.newId
            } ).always( function ( resp ) {
                if ( !resp || !resp.success ) failed++;
                if ( --pending === 0 ) {
                    if ( failed ) {
                        $card.append( '<p class="pcp-reflinks-error">' + failed + ' of ' + data.refIds.length + ' updates failed.</p>' );
                        $card.find( 'button' ).prop( 'disabled', false );
                    } else {
                        $card.html( '<p class="pcp-reflinks-done">✓ Linked "' + data.text + '" to ' + data.matchLabel + ' on ' + data.refIds.length + ' refs.</p>' );
                    }
                }
            } );
        } );
    }

    function dismissAll( $card, data ) {
        if ( !confirm( 'Dismiss all ' + data.refIds.length + ' refs with this text? They will stop appearing as suggestions but stay in your timeline as free text.' ) ) return;
        $card.find( 'button' ).prop( 'disabled', true );
        var pending = data.refIds.length;
        data.refIds.forEach( function ( rid ) {
            api.postWithToken( 'csrf', {
                action: 'pharmacopediarefupgrade',
                op: 'dismiss',
                ref_id: rid
            } ).always( function () {
                if ( --pending === 0 ) {
                    $card.html( '<p class="pcp-reflinks-done">✓ Dismissed.</p>' );
                }
            } );
        } );
    }

    $( init );
}() );
