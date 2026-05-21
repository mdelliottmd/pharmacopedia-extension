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

    function promptStoryTitle( bodyText, callback ) {
        var suggested = String( bodyText || '' ).trim().replace( /\s+/g, ' ' );
        if ( suggested.length > 60 ) suggested = suggested.slice( 0, 60 );
        var $overlay = $( '<div class="pcp-story-modal-overlay">' );
        var $panel   = $( '<div class="pcp-story-modal-panel" role="dialog" aria-modal="true">' );
        $panel.append( $( '<h3 class="pcp-story-modal-title">' ).text( 'Title your Story' ) );
        $panel.append( $( '<p class="pcp-story-modal-desc">' ).html(
            'This becomes the page name at <code>Story:&lt;title&gt;</code>. ' +
            'Default visibility is <strong>private</strong>; you can share later.'
        ) );
        var $input = $( '<input type="text" class="pcp-story-modal-input" maxlength="120" autocomplete="off">' ).val( suggested );
        $panel.append( $input );
        var $actions = $( '<div class="pcp-story-modal-actions">' );
        $actions.append( $( '<button type="button" class="pcp-btn pcp-btn-primary pcp-story-modal-save">' ).text( 'Save as Story' ) );
        $actions.append( $( '<button type="button" class="pcp-btn pcp-story-modal-cancel">' ).text( 'Cancel' ) );
        $panel.append( $actions );
        $overlay.append( $panel );
        $( 'body' ).append( $overlay );
        setTimeout( function () { $input.focus().select(); }, 0 );

        function cleanup( result ) {
            $overlay.remove();
            callback( result );
        }
        $panel.find( '.pcp-story-modal-save' ).on( 'click', function () {
            cleanup( $input.val().trim() );
        } );
        $panel.find( '.pcp-story-modal-cancel' ).on( 'click', function () {
            cleanup( null );
        } );
        $overlay.on( 'click', function ( e ) {
            if ( e.target === $overlay[ 0 ] ) cleanup( null );
        } );
        $input.on( 'keydown', function ( e ) {
            if ( e.key === 'Enter' ) { e.preventDefault(); cleanup( $input.val().trim() ); }
            else if ( e.key === 'Escape' ) { e.preventDefault(); cleanup( null ); }
        } );
    }

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

        function doSubmit( text, entryType, storyTitle ) {
            $bt.prop( 'disabled', true ).text( 'Saving...' );
            var payload = {
                action: 'pharmacopediaobservation',
                op:     'submit',
                text:   text,
                entry_type: entryType
            };
            if ( storyTitle ) payload.story_title = storyTitle;
            return api.postWithToken( 'csrf', payload );
        }

        $bt.on( 'click', function () {
            var text = $in.val().trim();
            if ( !text ) return;
            var entryType = $root.find( '.pcp-life-quickadd-typepicker-value' ).val() || 'observation';
            if ( entryType === 'story' ) {
                promptStoryTitle( text, function ( title ) {
                    if ( title === null ) return;
                    doSubmit( text, entryType, title ).done( function ( resp ) {
                $bt.text( 'Added!' );
                $in.val( '' );
                $pv.empty();
                var eid = ( resp && ( resp.event_id || ( resp.event_ids && resp.event_ids[0] ) ) ) || 0;
                setTimeout( function () {
                    $bt.text( 'Add to timeline' ).prop( 'disabled', false );
                    var here = location.pathname + location.search;
                    if ( here.indexOf( 'MyLifeStory' ) !== -1 || here.indexOf( 'LifeStory' ) !== -1 ) {
                        if ( eid && mw.util && mw.util.getUrl ) {
                            location.href = mw.util.getUrl( 'Special:MyLifeStory', { saved: eid } );
                        } else {
                            location.reload();
                        }
                    }
                }, 600 );
            } ).fail( function ( e ) {
                        $bt.text( 'Error: ' + e ).prop( 'disabled', false );
                    } );
                } );
                return;
            }
            doSubmit( text, entryType, '' ).done( function ( resp ) {
                $bt.text( 'Added!' );
                $in.val( '' );
                $pv.empty();
                var eid = ( resp && ( resp.event_id || ( resp.event_ids && resp.event_ids[0] ) ) ) || 0;
                setTimeout( function () {
                    $bt.text( 'Add to timeline' ).prop( 'disabled', false );
                    var here = location.pathname + location.search;
                    if ( here.indexOf( 'MyLifeStory' ) !== -1 || here.indexOf( 'LifeStory' ) !== -1 ) {
                        if ( eid && mw.util && mw.util.getUrl ) {
                            location.href = mw.util.getUrl( 'Special:MyLifeStory', { saved: eid } );
                        } else {
                            location.reload();
                        }
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

        // Refs (non-subject only)
        $.each( p.refs || [], function ( i, r ) {
            if ( ( r.role || '' ) === 'subject' ) return;
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
        var _disp = p.date_display || p.date_text;
        if ( _disp ) {
            $box.append( $( '<span class="pcp-obs-pv-date">' ).text( '· ' + _disp ) );
        }

        // Warnings (actionable: missing date, etc.) get the alert glyph.
        if ( p.warnings && p.warnings.length ) {
            var $w = $( '<div class="pcp-obs-pv-warnings">' );
            $.each( p.warnings, function ( i, w ) {
                $w.append( $( '<div>' ).text( '⚠ ' + w ) );
            } );
            $box.append( $w );
        }
        // Notes (informational: presumed title, new custom trait, etc.) render
        // without the alert glyph so unknown references feel accepted, not
        // flagged.
        if ( p.notes && p.notes.length ) {
            var $n = $( '<div class="pcp-obs-pv-notes">' );
            $.each( p.notes, function ( i, n ) {
                $n.append( $( '<div>' ).text( n ) );
            } );
            $box.append( $n );
        }

        // ===== Outcome plan: mirror what the API will actually create =====
        var subjectRefs = ( p.refs || [] ).filter( function ( r ) { return ( r.role || '' ) === 'subject'; } );
        var otherRefs   = ( p.refs || [] ).filter( function ( r ) { return ( r.role || '' ) !== 'subject'; } );
        if ( !subjectRefs.length ) {
            subjectRefs = [ { type: 'free', text: p.subject_text || '', label: p.subject_text || '', matched: false } ];
        }
        var kind     = ( p.date_struct && p.date_struct.kind ) || '';
        var dateText = p.date_display || p.date_text || '(no date)';
        var globalVal = ( p.numeric_value == null ) ? null : p.numeric_value;
        // Hard-override from the Quick Add type picker. If set, the preview's
        // plan-row must match what the API will actually create.
        var pickerType = ( $pv.closest( '.pcp-obs-quickadd' ).find( '.pcp-life-quickadd-typepicker-value' ).val() || '' ).toLowerCase();
        var forcedEvent   = pickerType === 'event';
        var forcedStory   = pickerType === 'story';
        var forcedEpisode = pickerType === 'episode';
        var forcedObs     = pickerType === 'observation';

        var $plan = $( '<div class="pcp-obs-pv-plan">' );
        $plan.append( $( '<div class="pcp-obs-pv-plan-head">' ).text( "What I'll create:" ) );

        var attribution = otherRefs.map( function ( r ) {
            var pre = r.role === 'cause' ? 'from' : ( r.role === 'context' ? 'with' : r.role );
            return pre + ' ' + r.label;
        } ).join( ', ' );

        subjectRefs.forEach( function ( s ) {
            var val   = ( s.value != null ) ? s.value : globalVal;
            var stype = s.type || '';
            var isTrait = ( stype === 'trait' || stype === 'trait_new' );
            var $row  = $( '<div class="pcp-obs-pv-plan-row">' );
            var $kind, descMain, kindCount = 1;

            // Effective routing: picker override beats parser inference.
            var willTraitKf = isTrait && val != null && ( kind === 'point' || kind === 'range' )
                && !forcedEvent && !forcedStory && !forcedEpisode && !forcedObs;
            var willEpisode = forcedEpisode || ( !forcedEvent && !forcedStory && !forcedObs && p.is_episode );
            var willEvent   = forcedEvent;
            var willStory   = forcedStory;
            if ( willTraitKf ) {
                kindCount = ( kind === 'range' ) ? 2 : 1;
                $kind = $( '<span class="pcp-obs-pv-plan-kind kf">' )
                    .text( '◆ Keyframe' + ( kindCount > 1 ? ' ×2' : '' ) );
                descMain = '“' + s.label + ' = ' + val + '”';
                if ( stype === 'trait_new' ) {
                    $row.append( $( '<span class="pcp-obs-pv-plan-tag new">' ).text( 'new trait' ) );
                }
            } else if ( willEpisode ) {
                $kind = $( '<span class="pcp-obs-pv-plan-kind ep">' ).text( '▪ Episode' );
                descMain = '“' + ( s.label || s.text ) + '”';
                if ( p.episode_type && !forcedEpisode ) {
                    descMain += ' (' + p.episode_type
                        + ( p.episode_subtype ? ' / ' + p.episode_subtype : '' )
                        + ')';
                }
            } else if ( willEvent ) {
                $kind = $( '<span class="pcp-obs-pv-plan-kind ev">' ).text( '◈ Event' );
                descMain = '“' + ( s.label || s.text ) + '”';
            } else if ( willStory ) {
                $kind = $( '<span class="pcp-obs-pv-plan-kind st">' ).text( '▯ Story' );
                descMain = '“' + ( s.label || s.text ) + '”';
            } else {
                $kind = $( '<span class="pcp-obs-pv-plan-kind obs">' ).text( '● Observation' );
                descMain = '“' + ( s.label || s.text ) + '”';
            }

            $row.prepend( $kind );
            $row.append( $( '<span class="pcp-obs-pv-plan-title">' ).text( ' ' + descMain ) );
            $row.append( $( '<span class="pcp-obs-pv-plan-date">' ).text( ' · ' + dateText ) );
            if ( attribution ) {
                $row.append( $( '<span class="pcp-obs-pv-plan-attr">' ).text( ' · ' + attribution ) );
            }
            if ( p.polarity === 0 ) {
                $row.append( $( '<span class="pcp-obs-pv-plan-pol neg">' ).text( ' · NEGATIVE' ) );
            }
            $plan.append( $row );
        } );
        $box.append( $plan );

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


/* ===== Preserve scroll position across card-delete reloads ===== */
( function () {
    'use strict';
    var KEY = 'pcp-life-scroll-restore';

    // Save scroll-Y when a card-delete form submits (after user confirms).
    document.addEventListener( 'submit', function ( e ) {
        var t = e.target;
        if ( !t || !t.classList ) return;
        if ( !t.classList.contains( 'pcp-life-card-delete-form' ) ) return;
        if ( e.defaultPrevented ) return;  // user cancelled the confirm dialog
        try { sessionStorage.setItem( KEY, String( window.scrollY ) ); } catch ( _ ) {}
    }, false );

    // On next page load, restore. Skip when ?saved=N is in URL (that path has
    // its own scroll target via the inline script in SpecialMyLifeStory.php).
    $( function () {
        var y;
        try { y = sessionStorage.getItem( KEY ); } catch ( _ ) {}
        if ( y === null || y === undefined ) return;
        try { sessionStorage.removeItem( KEY ); } catch ( _ ) {}
        if ( /[?&]saved=/.test( location.search ) ) return;
        var py = parseInt( y, 10 );
        if ( !isNaN( py ) ) {
            setTimeout( function () { window.scrollTo( 0, py ); }, 30 );
        }
    } );
}() );


/* ===== States and Traits tile UI (add / edit / delete) ===== */
( function () {
    'use strict';
    function buildAddForm( editIdx, name, val, valence, est, valenceEst, tvs, tvsEst ) {
        var safeName = ( name || '' ).replace( /"/g, '&quot;' );
        var initVal  = ( val === '' || val == null || isNaN( parseFloat( val ) ) ) ? 50 : parseFloat( val );
        // N/A default behaviour: only auto-N/A when EDITING an existing trait whose
        // stored valence is null. New tiles default to active-at-0.
        var isEditing = editIdx !== null && editIdx !== undefined && editIdx !== '';
        var valenceNA = false;
        var initValn = 0;
        if ( valence === '' || valence == null ) {
            if ( isEditing ) valenceNA = true;
        } else {
            initValn = parseFloat( valence );
            if ( isNaN( initValn ) ) { initValn = 0; valenceNA = isEditing; }
        }
        // trait/state: collapsed by default UNTIL the user clicks "Trait or State?".
        // Edits with a stored value start expanded; everything else starts collapsed.
        var tvsNA = ( tvs === '' || tvs == null );
        var initTvs = 0;
        if ( !tvsNA ) {
            initTvs = parseFloat( tvs );
            if ( isNaN( initTvs ) ) { initTvs = 0; tvsNA = true; }
        }
        var attr = editIdx !== null ? ' data-edit-idx="' + editIdx + '"' : '';
        return $(
            '<div class="pcp-st-add-form"' + attr + '>' +
              '<div class="pcp-st-add-row1">' +
                '<input type="text" class="pcp-st-add-name" list="pcp-st-suggestions" placeholder="trait or state, e.g. anxiety, focus, mood" maxlength="64" autocomplete="off" data-1p-ignore="true" data-lpignore="true" data-form-type="other" value="' + safeName + '">' +
              '</div>' +
              '<div class="pcp-st-slider-row">' +
                '<label class="pcp-st-slider-label">value</label>' +
                '<input type="range" class="pcp-st-slider-value" min="0" max="100" step="0.01" value="' + initVal + '">' +
                '<output class="pcp-st-slider-value-out">' + initVal + '</output>' +
                '<label class="pcp-st-row-est" title="estimated (or type ~N in the number to set)"><input type="checkbox" class="pcp-st-est-value"' + ( est ? ' checked' : '' ) + '> est</label>' +
                '<span class="pcp-st-row-na pcp-st-row-na-spacer" aria-hidden="true"><input type="checkbox" disabled> n/a</span>' +
              '</div>' +
              '<div class="pcp-st-slider-row' + ( valenceNA ? ' is-na' : '' ) + '">' +
                '<label class="pcp-st-slider-label">valence</label>' +
                '<input type="range" class="pcp-st-slider-valence" min="-100" max="100" step="0.01" value="' + initValn + '"' + ( valenceNA ? ' disabled' : '' ) + '>' +
                '<output class="pcp-st-slider-valence-out">' + ( valenceNA ? 'N/A' : ( initValn > 0 ? '+' : '' ) + initValn ) + '</output>' +
                '<label class="pcp-st-row-est" title="estimated (or type ~N in the number to set)"><input type="checkbox" class="pcp-st-est-valence"' + ( valenceEst ? ' checked' : '' ) + ( valenceNA ? ' disabled' : '' ) + '> est</label>' +
                '<label class="pcp-st-row-na" title="don\'t know / not applicable"><input type="checkbox" class="pcp-st-na-valence"' + ( valenceNA ? ' checked' : '' ) + '> n/a</label>' +
              '</div>' +
              '<div class="pcp-st-tvs-collapsed' + ( tvsNA ? '' : ' is-hidden' ) + '">' +
                '<button type="button" class="pcp-st-tvs-expand">Trait or State?</button>' +
              '</div>' +
              '<div class="pcp-st-slider-row pcp-st-row-tvs' + ( tvsNA ? ' is-hidden is-na' : '' ) + '">' +
                '<label class="pcp-st-slider-label" title="trait ↔ state: -100 = pure state, +100 = pure trait">trait/state</label>' +
                '<input type="range" class="pcp-st-slider-tvs" min="-100" max="100" step="0.01" value="' + initTvs + '"' + ( tvsNA ? ' disabled' : '' ) + '>' +
                '<output class="pcp-st-slider-tvs-out">' + ( tvsNA ? 'N/A' : ( initTvs > 0 ? '+' : '' ) + initTvs ) + '</output>' +
                '<label class="pcp-st-row-est" title="estimated"><input type="checkbox" class="pcp-st-est-tvs"' + ( tvsEst ? ' checked' : '' ) + ( tvsNA ? ' disabled' : '' ) + '> est</label>' +
                '<label class="pcp-st-row-na" title="don\'t know / not applicable"><input type="checkbox" class="pcp-st-na-tvs"' + ( tvsNA ? ' checked' : '' ) + '> n/a</label>' +
              '</div>' +
              '<div class="pcp-st-add-actions">' +
                '<button type="button" class="pcp-st-save">Save</button>' +
                '<button type="button" class="pcp-st-cancel">Cancel</button>' +
              '</div>' +
            '</div>'
        );
    }

    function keyFromName( name ) {
        var k = ( name || '' ).toLowerCase().replace( /[^a-z0-9_]+/g, '_' ).replace( /^_+|_+$/g, '' );
        return k || 'trait';
    }

    function escapeHtml( s ) {
        return ( s || '' ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }

    function buildTileHtml( idx, name, val, valence, est, valenceEst, tvs, tvsEst ) {
        var key = keyFromName( name );
        var valStr = ( est ? '~' : '' ) + val;
        var vTxt = fmtValenceText( valence );
        var vN = parseFloat( valence );
        var vSign = vN > 0 ? 'pos' : ( vN < 0 ? 'neg' : 'zero' );
        var vTag = vTxt !== '' ? ' <span class="pcp-st-tile-valence" data-sign="' + vSign + '">valence ' + ( valenceEst ? '~' : '' ) + vTxt + '</span>' : '';
        var tvsTxt = fmtValenceText( tvs );
        var tN = parseFloat( tvs );
        var tSign = tN > 0 ? 'pos' : ( tN < 0 ? 'neg' : 'zero' );
        var tTag = tvsTxt !== '' ? ' <span class="pcp-st-tile-tvs" data-sign="' + tSign + '">tvs ' + ( tvsEst ? '~' : '' ) + tvsTxt + '</span>' : '';
        return '<div class="pcp-st-tile" data-idx="' + idx + '">' +
            '<span class="pcp-st-tile-display">' +
              '<span class="pcp-st-tile-name">' + escapeHtml( name ) + '</span>' +
              '<span class="pcp-st-tile-eq"> = </span>' +
              '<span class="pcp-st-tile-value">' + escapeHtml( valStr ) + '</span>' +
              vTag + tTag +
            '</span>' +
            '<button type="button" class="pcp-st-tile-edit" title="Edit">✎</button>' +
            '<button type="button" class="pcp-st-tile-delete" title="Delete">×</button>' +
            '<input type="hidden" name="kf[' + idx + '][namespace]" value="custom">' +
            '<input type="hidden" name="kf[' + idx + '][key]" value="' + escapeHtml( key ) + '">' +
            '<input type="hidden" name="kf[' + idx + '][label]" value="' + escapeHtml( name ) + '">' +
            '<input type="hidden" name="kf[' + idx + '][value]" value="' + escapeHtml( val ) + '">' +
            '<input type="hidden" name="kf[' + idx + '][valence]" value="' + escapeHtml( ( valence == null || valence === '' ) ? '' : String( valence ) ) + '">' +
            '<input type="hidden" name="kf[' + idx + '][estimated]" value="' + ( est ? '1' : '' ) + '">' +
            '<input type="hidden" name="kf[' + idx + '][valence_estimated]" value="' + ( valenceEst ? '1' : '' ) + '">' +
            '<input type="hidden" name="kf[' + idx + '][traitvstate]" value="' + escapeHtml( ( tvs == null || tvs === '' ) ? '' : String( tvs ) ) + '">' +
            '<input type="hidden" name="kf[' + idx + '][traitvstate_estimated]" value="' + ( tvsEst ? '1' : '' ) + '">' +
        '</div>';
    }

    $( document ).on( 'click', '.pcp-st-tvs-expand', function () {
        var $form = $( this ).closest( '.pcp-st-add-form' );
        $form.find( '.pcp-st-tvs-collapsed' ).addClass( 'is-hidden' );
        var $row = $form.find( '.pcp-st-row-tvs' );
        $row.removeClass( 'is-hidden is-na' );
        $row.find( '.pcp-st-na-tvs' ).prop( 'checked', false );
        $row.find( '.pcp-st-slider-tvs' ).prop( 'disabled', false );
        $row.find( '.pcp-st-est-tvs' ).prop( 'disabled', false );
        $row.find( '.pcp-st-slider-tvs' ).trigger( 'input' ).focus();
    } );
    function morphNameToToken( $form, name ) {
        $form.find( '.pcp-st-add-name' ).remove();
        $form.find( '.pcp-st-add-name-token' ).remove();
        $form.find( '.pcp-st-add-name-hidden' ).remove();
        var $row1 = $form.find( '.pcp-st-add-row1' );
        var $tok = $( '<span class="pcp-st-add-name-token" tabindex="0"></span>' );
        $tok.append( '<span class="pcp-st-token-icon">◆</span> ' );
        $tok.append( $( '<span class="pcp-st-token-name"></span>' ).text( name ) );
        $tok.append( ' <button type="button" class="pcp-st-token-edit" title="Rename">✎</button>' );
        $row1.append( $tok );
        $row1.append( $( '<input type="hidden" class="pcp-st-add-name-hidden">' ).val( name ) );
    }
    $( document ).on( 'keydown', '.pcp-st-add-name', function ( e ) {
        if ( e.key !== 'Enter' ) return;
        e.preventDefault();
        var name = $( this ).val().trim();
        if ( !name ) return;
        var $form = $( this ).closest( '.pcp-st-add-form' );
        morphNameToToken( $form, name );
        $form.find( '.pcp-st-slider-value' ).focus();
    } );
    $( document ).on( 'click', '.pcp-st-token-edit', function ( e ) {
        e.stopPropagation();
        var $form = $( this ).closest( '.pcp-st-add-form' );
        var name = $form.find( '.pcp-st-add-name-hidden' ).val();
        $form.find( '.pcp-st-add-name-token' ).remove();
        $form.find( '.pcp-st-add-name-hidden' ).remove();
        var $input = $( '<input type="text" class="pcp-st-add-name" list="pcp-st-suggestions" placeholder="trait or state, e.g. anxiety, focus, mood" maxlength="64" autocomplete="off" data-1p-ignore="true" data-lpignore="true" data-form-type="other">' ).val( name );
        $form.find( '.pcp-st-add-row1' ).prepend( $input );
        $input.focus().select();
    } );
    $( document ).on( 'click', '.pcp-st-add-btn', function () {
        var $fs = $( this ).closest( '.pcp-states-traits' );
        $fs.find( '.pcp-st-add-form' ).remove();
        var $form = buildAddForm( null, '', '', '', false, false, '', false );
        $( this ).before( $form );
        $form.find( '.pcp-st-slider-valence' ).trigger( 'input' );
        $form.find( '.pcp-st-slider-tvs' ).trigger( 'input' );
        $form.find( '.pcp-st-add-name' ).focus();
    } );

    $( document ).on( 'click', '.pcp-st-cancel', function () {
        var $form = $( this ).closest( '.pcp-st-add-form' );
        var idx = $form.attr( 'data-edit-idx' );
        if ( idx ) {
            $form.prev( '.pcp-st-tile[data-idx="' + idx + '"]' ).show();
        }
        $form.remove();
    } );

    function fmtValenceText( v ) {
        if ( v === null || v === '' ) return '';
        var n = parseFloat( v );
        if ( isNaN( n ) ) return '';
        if ( n === 0 ) return '0';
        return ( n > 0 ? '+' : '' ) + ( Math.round( n * 100 ) / 100 );
    }
    $( document ).on( 'input', '.pcp-st-slider-value', function () {
        $( this ).siblings( '.pcp-st-slider-value-out' ).text( $( this ).val() );
    } );

    // Click-to-edit on the numeric readouts.
    function parseInlineNumeric( raw ) {
        raw = String( raw == null ? '' : raw ).trim();
        var estimated = false;
        if ( raw.charAt( 0 ) === '~' ) { estimated = true; raw = raw.slice( 1 ).trim(); }
        if ( raw.charAt( 0 ) === '+' ) { raw = raw.slice( 1 ).trim(); }
        if ( raw.charAt( raw.length - 1 ) === '%' ) { raw = raw.slice( 0, -1 ).trim(); }
        var v = parseFloat( raw );
        return { value: v, estimated: estimated };
    }

    $( document ).on( 'click', '.pcp-st-slider-value-out, .pcp-st-slider-valence-out', function ( e ) {
        var $out = $( this );
        if ( $out.find( 'input' ).length ) return;
        var $slider = $out.siblings( 'input[type="range"]' );
        if ( !$slider.length ) return;
        var isValence = $slider.hasClass( 'pcp-st-slider-valence' );
        var $row = $slider.closest( '.pcp-st-slider-row' );
        var $estBox = $row.find( isValence ? '.pcp-st-est-valence' : '.pcp-st-est-value' );
        var current = parseFloat( $slider.val() );
        var min = parseFloat( $slider.attr( 'min' ) );
        var max = parseFloat( $slider.attr( 'max' ) );
        var $input = $( '<input type="text" class="pcp-st-slider-inline-edit" autocomplete="off">' )
            .val( ( isValence && current > 0 ? '+' : '' ) + current );
        $out.empty().append( $input );
        $input[ 0 ].focus();
        $input[ 0 ].select();

        function commit() {
            var parsed = parseInlineNumeric( $input.val() );
            var v = parsed.value;
            if ( isNaN( v ) ) v = current;
            if ( v < min ) v = min;
            if ( v > max ) v = max;
            if ( parsed.estimated ) $estBox.prop( 'checked', true );
            $slider.val( v ).trigger( 'input' );
        }
        function cancel() {
            $slider.trigger( 'input' );
        }
        $input.on( 'blur', commit );
        $input.on( 'keydown', function ( ev ) {
            if ( ev.key === 'Enter' ) { ev.preventDefault(); $input[ 0 ].blur(); }
            else if ( ev.key === 'Escape' ) { ev.preventDefault(); cancel(); }
        } );
        e.stopPropagation();
    } );
    function valenceColor( v ) {
        var n = parseFloat( v );
        if ( isNaN( n ) ) n = 0;
        // Anchors: purple #5d3b8e at 0, deep green #15803d at +100, deep red #991b1b at -100.
        var pR = 93, pG = 59, pB = 142;
        var gR = 21, gG = 128, gB = 61;
        var rR = 153, rG = 27, rB = 27;
        var t, r, g, b;
        if ( n >= 0 ) {
            t = Math.min( 1, n / 100 );
            r = Math.round( pR + ( gR - pR ) * t );
            g = Math.round( pG + ( gG - pG ) * t );
            b = Math.round( pB + ( gB - pB ) * t );
        } else {
            t = Math.min( 1, -n / 100 );
            r = Math.round( pR + ( rR - pR ) * t );
            g = Math.round( pG + ( rG - pG ) * t );
            b = Math.round( pB + ( rB - pB ) * t );
        }
        return 'rgb(' + r + ',' + g + ',' + b + ')';
    }
    $( document ).on( 'input', '.pcp-st-slider-valence', function () {
        var v = parseFloat( $( this ).val() );
        $( this ).siblings( '.pcp-st-slider-valence-out' ).text( ( v > 0 ? '+' : '' ) + v );
        this.style.accentColor = valenceColor( v );
        var $row = $( this ).closest( '.pcp-st-slider-row' );
        $row.removeClass( 'is-na' );
        $row.find( '.pcp-st-na-valence' ).prop( 'checked', false );
        $row.find( '.pcp-st-est-valence' ).prop( 'disabled', false );
    } );
    function tvsColor( v ) {
        var n = parseFloat( v );
        if ( isNaN( n ) ) n = 0;
        // 0 = purple #5d3b8e, +100 = teal #0d9488 (trait), -100 = rose #e11d48 (state).
        var pR = 93,  pG = 59,  pB = 142;
        var tR = 13,  tG = 148, tB = 136;
        var rR = 225, rG = 29,  rB = 72;
        var t, r, g, b;
        if ( n >= 0 ) {
            t = Math.min( 1, n / 100 );
            r = Math.round( pR + ( tR - pR ) * t );
            g = Math.round( pG + ( tG - pG ) * t );
            b = Math.round( pB + ( tB - pB ) * t );
        } else {
            t = Math.min( 1, -n / 100 );
            r = Math.round( pR + ( rR - pR ) * t );
            g = Math.round( pG + ( rG - pG ) * t );
            b = Math.round( pB + ( rB - pB ) * t );
        }
        return 'rgb(' + r + ',' + g + ',' + b + ')';
    }
    $( document ).on( 'input', '.pcp-st-slider-tvs', function () {
        var v = parseFloat( $( this ).val() );
        $( this ).siblings( '.pcp-st-slider-tvs-out' ).text( ( v > 0 ? '+' : '' ) + v );
        this.style.accentColor = tvsColor( v );
        var $row = $( this ).closest( '.pcp-st-slider-row' );
        $row.removeClass( 'is-na' );
        $row.find( '.pcp-st-na-tvs' ).prop( 'checked', false );
        $row.find( '.pcp-st-est-tvs' ).prop( 'disabled', false );
    } );
    $( document ).on( 'change', '.pcp-st-na-tvs', function () {
        var $row = $( this ).closest( '.pcp-st-slider-row' );
        var on = $( this ).is( ':checked' );
        $row.toggleClass( 'is-na', on );
        $row.find( '.pcp-st-slider-tvs' ).prop( 'disabled', on );
        $row.find( '.pcp-st-est-tvs' ).prop( 'disabled', on ).prop( 'checked', on ? false : $row.find( '.pcp-st-est-tvs' ).prop( 'checked' ) );
        if ( on ) {
            $row.find( '.pcp-st-slider-tvs-out' ).text( 'N/A' );
        } else {
            $row.find( '.pcp-st-slider-tvs' ).trigger( 'input' );
        }
    } );
    $( document ).on( 'change', '.pcp-st-na-valence', function () {
        var $row = $( this ).closest( '.pcp-st-slider-row' );
        var on = $( this ).is( ':checked' );
        $row.toggleClass( 'is-na', on );
        $row.find( '.pcp-st-slider-valence' ).prop( 'disabled', on );
        $row.find( '.pcp-st-est-valence' ).prop( 'disabled', on ).prop( 'checked', on ? false : $row.find( '.pcp-st-est-valence' ).prop( 'checked' ) );
        if ( on ) {
            $row.find( '.pcp-st-slider-valence-out' ).text( 'N/A' );
        } else {
            $row.find( '.pcp-st-slider-valence' ).trigger( 'input' );
        }
    } );
    $( document ).on( 'click', '.pcp-st-save', function () {
        var $form = $( this ).closest( '.pcp-st-add-form' );
        var $fs = $form.closest( '.pcp-states-traits' );
        var $tiles = $fs.find( '.pcp-st-tiles' );
        var name = ( $form.find( '.pcp-st-add-name' ).val() || $form.find( '.pcp-st-add-name-hidden' ).val() || '' ).trim();
        var val  = $form.find( '.pcp-st-slider-value' ).val();
        var valence;
        if ( $form.find( '.pcp-st-na-valence' ).is( ':checked' ) ) {
            valence = '';
        } else {
            valence = $form.find( '.pcp-st-slider-valence' ).val();
        }
        var tvs;
        if ( $form.find( '.pcp-st-na-tvs' ).is( ':checked' ) ) {
            tvs = '';
        } else {
            tvs = $form.find( '.pcp-st-slider-tvs' ).val();
        }
        var est  = $form.find( '.pcp-st-est-value' ).is( ':checked' );
        var valenceEst = $form.find( '.pcp-st-est-valence' ).is( ':checked' );
        var tvsEst = $form.find( '.pcp-st-est-tvs' ).is( ':checked' );
        if ( !name ) return;
        var editIdx = $form.attr( 'data-edit-idx' );
        if ( editIdx !== undefined && editIdx !== '' ) {
            var $tile = $tiles.find( '.pcp-st-tile[data-idx="' + editIdx + '"]' );
            $tile.find( 'input[name="kf[' + editIdx + '][key]"]' ).val( keyFromName( name ) );
            $tile.find( 'input[name="kf[' + editIdx + '][label]"]' ).val( name );
            $tile.find( 'input[name="kf[' + editIdx + '][value]"]' ).val( val );
            $tile.find( 'input[name="kf[' + editIdx + '][valence]"]' ).val( valence );
            $tile.find( 'input[name="kf[' + editIdx + '][estimated]"]' ).val( est ? '1' : '' );
            $tile.find( 'input[name="kf[' + editIdx + '][valence_estimated]"]' ).val( valenceEst ? '1' : '' );
            $tile.find( 'input[name="kf[' + editIdx + '][traitvstate]"]' ).val( tvs );
            $tile.find( 'input[name="kf[' + editIdx + '][traitvstate_estimated]"]' ).val( tvsEst ? '1' : '' );
            $tile.find( '.pcp-st-tile-name' ).text( name );
            $tile.find( '.pcp-st-tile-value' ).text( ( est ? '~' : '' ) + val );
            $tile.find( '.pcp-st-tile-valence' ).remove();
            if ( valence !== '' && valence != null ) {
                var vN = parseFloat( valence );
                var vSign = vN > 0 ? 'pos' : ( vN < 0 ? 'neg' : 'zero' );
                var vDisp = vN > 0 ? '+' + vN : String( vN );
                $tile.find( '.pcp-st-tile-display' ).append( ' <span class="pcp-st-tile-valence" data-sign="' + vSign + '">valence ' + ( valenceEst ? '~' : '' ) + vDisp + '</span>' );
            }
            $tile.find( '.pcp-st-tile-tvs' ).remove();
            if ( tvs !== '' && tvs != null ) {
                var tN2 = parseFloat( tvs );
                var tSign2 = tN2 > 0 ? 'pos' : ( tN2 < 0 ? 'neg' : 'zero' );
                var tDisp = tN2 > 0 ? '+' + tN2 : String( tN2 );
                $tile.find( '.pcp-st-tile-display' ).append( ' <span class="pcp-st-tile-tvs" data-sign="' + tSign2 + '">tvs ' + ( tvsEst ? '~' : '' ) + tDisp + '</span>' );
            }
            $tile.show();
        } else {
            var idx = parseInt( $tiles.attr( 'data-next-idx' ), 10 );
            $tiles.append( buildTileHtml( idx, name, val, valence, est, valenceEst, tvs, tvsEst ) );
            $tiles.attr( 'data-next-idx', idx + 1 );
        }
        $form.remove();
    } );

    $( document ).on( 'click', '.pcp-st-tile-delete', function () {
        $( this ).closest( '.pcp-st-tile' ).remove();
    } );

    $( document ).on( 'click', '.pcp-st-tile-edit', function () {
        var $tile = $( this ).closest( '.pcp-st-tile' );
        var idx = $tile.attr( 'data-idx' );
        var name = $tile.find( 'input[name="kf[' + idx + '][label]"]' ).val();
        var val  = $tile.find( 'input[name="kf[' + idx + '][value]"]' ).val();
        var valence = $tile.find( 'input[name="kf[' + idx + '][valence]"]' ).val();
        var est  = $tile.find( 'input[name="kf[' + idx + '][estimated]"]' ).val() === '1';
        var valenceEst = $tile.find( 'input[name="kf[' + idx + '][valence_estimated]"]' ).val() === '1';
        var tvs = $tile.find( 'input[name="kf[' + idx + '][traitvstate]"]' ).val();
        var tvsEst = $tile.find( 'input[name="kf[' + idx + '][traitvstate_estimated]"]' ).val() === '1';
        $tile.hide().after( buildAddForm( idx, name, val, valence, est, valenceEst, tvs, tvsEst ) );
        var $form = $tile.next( '.pcp-st-add-form' );
        $form.find( '.pcp-st-slider-valence' ).trigger( 'input' );
        $form.find( '.pcp-st-slider-tvs' ).trigger( 'input' );
        $form.find( '.pcp-st-add-name' ).focus();
    } );
}() );


/* picker behavior — segmented Quick Add type picker */
( function () {
    'use strict';
    // Once per page-load: if the user types a keyword that matches a picker
    // type, auto-switch the picker to it. Once auto-switch fires OR the user
    // manually clicks a segment, the flag locks and no more auto-switching.
    var autoSwitchLocked = false;
    function activate( $btn ) {
        var $group = $btn.closest( '.pcp-life-quickadd-typepicker' );
        $group.find( 'button' ).removeClass( 'active' ).attr( 'aria-checked', 'false' );
        $btn.addClass( 'active' ).attr( 'aria-checked', 'true' );
        var v = $btn.attr( 'data-type' ) || 'observation';
        $group.siblings( '.pcp-life-quickadd-typepicker-value' ).val( v );
        var $input = $group.siblings( '.pcp-obs-input' );
        if ( $input.length ) $input.trigger( 'input' );
    }
    $( document ).on( 'click', '.pcp-life-quickadd-typepicker button', function () {
        autoSwitchLocked = true;
        activate( $( this ) );
    } );
    $( document ).on( 'input', '.pcp-obs-input', function () {
        if ( autoSwitchLocked ) return;
        var raw = String( $( this ).val() || '' ).toLowerCase();
        var m = raw.match( /\b(event|episode|story)\b/ );
        if ( !m ) return;
        var $picker = $( this ).siblings( '.pcp-life-quickadd-typepicker' );
        if ( !$picker.length ) $picker = $( this ).parent().find( '.pcp-life-quickadd-typepicker' );
        var $btn = $picker.find( 'button[data-type="' + m[1] + '"]' );
        if ( !$btn.length || $btn.hasClass( 'active' ) ) return;
        autoSwitchLocked = true;
        activate( $btn );
    } );
    $( document ).on( 'keydown', '.pcp-life-quickadd-typepicker button', function ( e ) {
        var $this = $( this );
        var $group = $this.closest( '.pcp-life-quickadd-typepicker' );
        var $buttons = $group.find( 'button' );
        var idx = $buttons.index( $this );
        if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
            e.preventDefault();
            var $next = $buttons.eq( ( idx + 1 ) % $buttons.length );
            $next.focus(); activate( $next );
        } else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
            e.preventDefault();
            var $prev = $buttons.eq( ( idx - 1 + $buttons.length ) % $buttons.length );
            $prev.focus(); activate( $prev );
        } else if ( e.key === 'Home' ) {
            e.preventDefault(); $buttons.first().focus(); activate( $buttons.first() );
        } else if ( e.key === 'End' ) {
            e.preventDefault(); $buttons.last().focus(); activate( $buttons.last() );
        }
    } );
}() );


/* ===== Per-filter checkbox persistence across reloads (Visual timeline) ===== */
( function () {
    'use strict';
    var KEY = 'pcp-life-timeline-filter-state';

    // Save state on any filter change. Uses native event so it survives
    // jQuery .trigger('change') AND raw user clicks.
    document.addEventListener( 'change', function ( e ) {
        var t = e.target;
        if ( !t || !t.classList || !t.classList.contains( 'pcp-life-timeline-group-toggle' ) ) return;
        var state = {};
        document.querySelectorAll( '.pcp-life-timeline-group-toggle' ).forEach( function ( cb ) {
            state[ cb.value ] = cb.checked;
        } );
        try { sessionStorage.setItem( KEY, JSON.stringify( state ) ); } catch ( _ ) {}
    }, true );  // capture phase so we run before stopPropagation could swallow it

    // Restore on page load. Use jQuery .ready so it works whether the module
    // loads before or after DOMContentLoaded. Skip when ?saved=N is set (that
    // flow has its own filter-auto-tick for the saved event's group).
    $( function () {
        if ( /[?&]saved=/.test( location.search ) ) return;
        var raw;
        try { raw = sessionStorage.getItem( KEY ); } catch ( _ ) {}
        if ( !raw ) return;
        var state;
        try { state = JSON.parse( raw ); } catch ( _ ) { return; }
        if ( !state || typeof state !== 'object' ) return;
        // Two-pass: set all .checked first, THEN dispatch native change events
        // so interface's filter handler runs once per checkbox with the
        // correct steady-state checked values.
        var toFire = [];
        document.querySelectorAll( '.pcp-life-timeline-group-toggle' ).forEach( function ( cb ) {
            if ( state.hasOwnProperty( cb.value ) && cb.checked !== state[ cb.value ] ) {
                cb.checked = state[ cb.value ];
                toFire.push( cb );
            }
        } );
        toFire.forEach( function ( cb ) {
            cb.dispatchEvent( new Event( 'change', { bubbles: true } ) );
        } );
    } );
}() );
