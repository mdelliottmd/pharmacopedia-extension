/*!
 * Quick-add observation widget.
 *
 * Markup:
 *   <div class="pcp-obs-quickadd">
 *     <textarea class="pcp-obs-input" placeholder="..."></textarea>
 *     <div class="pcp-obs-preview"></div>
 *     <button class="pcp-obs-submit">Add to timeline</button>
 *   </div>
 *
 * Or as a modal trigger anywhere:
 *   <a class="pcp-obs-trigger" href="#">Add observation</a>
 *
 * Live-previews the parse on every input pause, submits on click.
 */
( function () {
    'use strict';

    var api = new mw.Api();

    function attachQuickAdd( $root ) {
        if ( $root.data( 'pcpobsInited' ) ) return;
        $root.data( 'pcpobsInited', true );

        var $in = $root.find( '.pcp-obs-input' );
        var $pv = $root.find( '.pcp-obs-preview' );
        var $bt = $root.find( '.pcp-obs-submit' );
        var timer = null;

        $in.on( 'input', function () {
            clearTimeout( timer );
            var text = $in.val().trim();
            if ( !text ) { $pv.empty(); return; }
            timer = setTimeout( function () { runPreview( text, $pv ); }, 250 );
        } );

        $bt.on( 'click', function () {
            var text = $in.val().trim();
            if ( !text ) return;
            $bt.prop( 'disabled', true ).text( 'Saving...' );
            api.postWithToken( 'csrf', {
                action: 'pharmacopediaobservation',
                op:     'submit',
                text:   text
            } ).done( function ( resp ) {
                $bt.text( 'Added!' );
                $in.val( '' );
                $pv.empty();
                setTimeout( function () {
                    $bt.text( 'Add to timeline' ).prop( 'disabled', false );
                    // If we're on the life-story page, reload to show the new event.
                    if ( location.pathname.indexOf( 'MyLifeStory' ) !== -1 ||
                         location.pathname.indexOf( 'LifeStory' ) !== -1 ) {
                        location.reload();
                    }
                }, 600 );
            } ).fail( function ( e ) {
                $bt.text( 'Error: ' + e ).prop( 'disabled', false );
            } );
        } );
    }

    function runPreview( text, $pv ) {
        $pv.html( '<em class="pcp-obs-pv-loading">Parsing...</em>' );
        api.get( {
            action: 'pharmacopediaobservation',
            op:     'preview',
            text:   text
        } ).done( function ( resp ) {
            renderPreview( resp.parsed || {}, $pv );
        } ).fail( function ( e ) {
            $pv.html( '<em class="pcp-obs-pv-error">Parse error: ' + e + '</em>' );
        } );
    }

    function renderPreview( p, $pv ) {
        var $box = $( '<div class="pcp-obs-pv">' );

        // Polarity badge
        var polLabel = p.polarity === 0 ? '✕ did NOT' :
                       p.polarity === 1 ? '✓ did' :
                       '? unspecified';
        var polClass = p.polarity === 0 ? 'pcp-obs-pv-neg' :
                       p.polarity === 1 ? 'pcp-obs-pv-pos' : 'pcp-obs-pv-unknown';
        $box.append( $( '<span class="pcp-obs-pv-badge ' + polClass + '">' ).text( polLabel ) );

        // Subject
        if ( p.subject_text ) {
            $box.append( $( '<span class="pcp-obs-pv-subject">' ).text( p.subject_text ) );
        }

        // Refs
        $.each( p.refs || [], function ( i, r ) {
            if ( i === 0 ) return; // subject already shown
            var prefix = r.role === 'cause' ? 'from' : ( r.role === 'context' ? 'with' : r.role );
            var cls = 'pcp-obs-pv-ref ' + ( r.matched ? 'matched' : 'unmatched' );
            $box.append(
                $( '<span class="' + cls + '">' ).append(
                    $( '<span class="pcp-obs-pv-ref-role">' ).text( prefix + ' ' ),
                    $( '<span class="pcp-obs-pv-ref-label">' ).text( r.label ),
                    $( '<span class="pcp-obs-pv-ref-type">' ).text( ' [' + r.type + ']' )
                )
            );
        } );

        // Date
        if ( p.date_text ) {
            $box.append( $( '<span class="pcp-obs-pv-date">' ).text( '· ' + p.date_text ) );
        }

        // Warnings
        if ( p.warnings && p.warnings.length ) {
            var $w = $( '<div class="pcp-obs-pv-warnings">' );
            $.each( p.warnings, function ( i, w ) {
                $w.append( $( '<div>' ).text( '⚠ ' + w ) );
            } );
            $box.append( $w );
        }

        // Confidence chip
        $box.append( $( '<span class="pcp-obs-pv-conf pcp-obs-pv-conf-' + ( p.confidence || 'low' ) + '">' )
            .text( p.confidence + ' confidence' ) );

        $pv.empty().append( $box );
    }

    // ===== Modal launcher (for chip on other pages) =====

    var $modal = null;

    function openModal() {
        if ( $modal ) closeModal();
        $modal = $( '<div class="pcp-share-modal-backdrop">' ).append(
            $( '<div class="pcp-share-modal">' ).append(
                $( '<div class="pcp-share-header">' ).append(
                    $( '<h2>' ).text( 'Add an observation' ),
                    $( '<button type="button" class="pcp-share-close" aria-label="Close">&times;</button>' )
                ),
                $( '<div class="pcp-share-body">' ).append(
                    $( '<div class="pcp-obs-quickadd">' ).append(
                        $( '<textarea class="pcp-obs-input" rows="3" placeholder="anxiety from bupropion in jan 2020"></textarea>' ),
                        $( '<div class="pcp-obs-preview"></div>' ),
                        $( '<button type="button" class="pcp-btn pcp-obs-submit">Add to timeline</button>' )
                    )
                )
            )
        );
        $( document.body ).append( $modal );
        attachQuickAdd( $modal.find( '.pcp-obs-quickadd' ) );
        $modal.find( '.pcp-obs-input' ).trigger( 'focus' );
    }
    function closeModal() {
        if ( $modal ) { $modal.remove(); $modal = null; }
    }

    $( document ).on( 'click', '.pcp-obs-trigger', function ( e ) {
        e.preventDefault();
        openModal();
    } );
    $( document ).on( 'click', '.pcp-share-close, .pcp-share-modal-backdrop', function ( e ) {
        if ( e.target === e.currentTarget && $modal ) closeModal();
    } );
    $( document ).on( 'keydown', function ( e ) {
        if ( e.key === 'Escape' && $modal ) closeModal();
    } );

    // Auto-init any inline quick-add boxes on the page.
    $( function () {
        $( '.pcp-obs-quickadd' ).each( function () { attachQuickAdd( $( this ) ); } );
    } );

}() );
