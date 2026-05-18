( function () {
    'use strict';
    $( function () {
        var api = new mw.Api();
        var loggedIn = !!mw.config.get( 'wgUserName' );
        var currentUserId = mw.config.get( 'wgUserId' ) || 0;
        var userGroups = mw.config.get( 'wgUserGroups' ) || [];
        var isSysop = userGroups.indexOf( 'sysop' ) >= 0;
        var canProvider = isSysop
            || userGroups.indexOf( 'admin' ) >= 0
            || userGroups.indexOf( 'provider' ) >= 0;

        // ===== <vote> =====
        $( '.pcp-vote' ).each( function () {
            var $widget = $( this );
            var elementId = $widget.data( 'element-id' );
            if ( !elementId ) { return; }
            $widget.find( '.pcp-vote-btn' ).on( 'click', function ( e ) {
                e.preventDefault();
                var $btn = $( this );
                var value = parseInt( $btn.data( 'value' ), 10 );
                if ( $btn.hasClass( 'pcp-vote-active' ) ) { value = 0; }
                if ( !loggedIn ) {
                    // Optimistic UI: toggle locally, do not persist.
                    var wasActive = $btn.hasClass( 'pcp-vote-active' );
                    $widget.find( '.pcp-vote-btn' ).removeClass( 'pcp-vote-active' );
                    if ( !wasActive ) { $btn.addClass( 'pcp-vote-active' ); }
                    mw.notify( mw.msg( 'pharmacopedia-login-required' ), { type: 'warn', autoHide: false } );
                    return;
                }
                api.postWithToken( 'csrf', {
                    action: 'pharmacopediavote', element_id: elementId, value: value, format: 'json'
                } ).done( function ( resp ) {
                    var d = resp.pharmacopediavote;
                    if ( !d ) { return; }
                    var $score = $widget.find( '.pcp-vote-score' );
                    var newScore = d.score;
                    var display = newScore > 0 ? ( '+' + newScore ) : ( '' + newScore );
                    var signCls = newScore > 0 ? 'pcp-vote-score-pos'
                                 : ( newScore < 0 ? 'pcp-vote-score-neg' : 'pcp-vote-score-zero' );
                    $score.text( display )
                        .attr( 'data-up', d.upvotes ).attr( 'data-down', d.downvotes )
                        .removeClass( 'pcp-vote-score-pos pcp-vote-score-neg pcp-vote-score-zero' )
                        .addClass( signCls );
                    $widget.find( '.pcp-vote-up' ).toggleClass( 'pcp-vote-active', d.user_vote === 1 );
                    $widget.find( '.pcp-vote-down' ).toggleClass( 'pcp-vote-active', d.user_vote === -1 );
                } ).fail( function ( code ) {
                    mw.notify( mw.msg( 'pharmacopedia-vote-failed' ) + ' (' + code + ')', { type: 'error' } );
                } );
            } );
        } );

        // ===== <effect> with perspectives + frequency =====
        $( '.pcp-effect' ).each( function () {
            var $widget = $( this );
            var elementId = $widget.data( 'element-id' );
            if ( !elementId ) { return; }
            if ( canProvider ) { $widget.attr( 'data-provider-eligible', '1' ); }

            // state[perspective] holds the user's pending/saved selection per perspective
            // patient: experienced (0/1/2/null), valence
            // provider: frequency (0/5/33/66/95/null), valence
            function readAttr( name ) {
                var v = $widget.attr( name );
                return ( v === undefined || v === '' ) ? null : parseInt( v, 10 );
            }
            var state = {
                perspective: 1,
                1: {
                    experienced: readAttr( 'data-user-patient-experienced' ),
                    valence:     readAttr( 'data-user-patient-valence' )
                },
                2: {
                    frequency:   readAttr( 'data-user-provider-frequency' ),
                    valence:     readAttr( 'data-user-provider-valence' )
                }
            };

            function refreshButtons() {
                if ( state.perspective === 1 ) {
                    var cur = state[1];
                    $widget.find( '.pcp-effect-btn' ).each( function () {
                        var $b = $( this );
                        $b.toggleClass( 'pcp-active',
                            cur.experienced !== null && parseInt( $b.data( 'experienced' ), 10 ) === cur.experienced );
                    } );
                    var $vsl = $widget.find( '.pcp-effect-vslider' );
                    if ( $vsl.length ) {
                        var v1 = cur.valence !== null ? cur.valence : 0;
                        $vsl.val( v1 );
                        $vsl.next( 'output' ).val( ( v1 >= 0 ? '+' : '' ) + v1 );
                    }
                    $widget.find( '.pcp-effect-valence-row' )
                        .toggleClass( 'pcp-disabled', cur.experienced !== 1 );
                } else {
                    var curP = state[2];
                    // Slider reflects current frequency (or 50 if unset/Don't know).
                    var $fsl = $widget.find( '.pcp-effect-fslider' );
                    if ( $fsl.length ) {
                        var sliderVal = ( curP.frequency !== null && curP.frequency >= 0 ) ? curP.frequency : 50;
                        $fsl.val( sliderVal );
                        $fsl.next( 'output' ).val( sliderVal + '%' );
                        $widget.find( '.pcp-effect-fslider-wrap' )
                            .toggleClass( 'pcp-fslider-dk', curP.frequency === -1 )
                            .toggleClass( 'pcp-fslider-unset', curP.frequency === null );
                    }
                    $widget.find( '.pcp-effect-fbtn-dk' )
                        .toggleClass( 'pcp-active', curP.frequency === -1 );
                    var $vsl2 = $widget.find( '.pcp-effect-vslider' );
                    if ( $vsl2.length ) {
                        var v2 = curP.valence !== null ? curP.valence : 0;
                        $vsl2.val( v2 );
                        $vsl2.next( 'output' ).val( ( v2 >= 0 ? '+' : '' ) + v2 );
                    }
                    // Valence disabled if frequency is 0 or unset
                    $widget.find( '.pcp-effect-valence-row' )
                        .toggleClass( 'pcp-disabled', curP.frequency === null || curP.frequency === -1 );
                }
            }

            function updatePatientAgg( d ) {
                var $row = $widget.find( '.pcp-agg-patient' );
                if ( !$row.length ) { return; }
                $row.attr( 'data-n', d.n ).attr( 'data-yes', d.yes ).attr( 'data-vmean', d.valence_mean === null ? '' : d.valence_mean );
                var icon = '<span class="pcp-agg-icon">👤</span> ';
                if ( d.n > 0 ) {
                    var pct = Math.round( ( d.yes / d.n ) * 100 );
                    var vMean = d.valence_mean === null ? '-'
                        : ( d.valence_mean >= 0 ? '+' : '' ) + d.valence_mean.toFixed( 1 );
                    $row.html( icon +
                        '<span class="pcp-effect-pct">' + pct + '% reported</span>' +
                        ' <span class="pcp-effect-vmean">avg ' + vMean + '</span>' +
                        ' <span class="pcp-effect-n">(n=' + d.n + ')</span>'
                    );
                } else {
                    $row.html( icon + '<span class="pcp-effect-noreports">no reports yet</span>' );
                }
            }
            function updateProviderAgg( d ) {
                var $row = $widget.find( '.pcp-agg-provider' );
                if ( !$row.length ) { return; }
                $row.attr( 'data-n', d.n ).attr( 'data-fmean', d.frequency_mean === null ? '' : d.frequency_mean ).attr( 'data-vmean', d.valence_mean === null ? '' : d.valence_mean );
                var icon = '<span class="pcp-agg-icon">⚕️</span> ';
                if ( d.n > 0 ) {
                    var fFmt = d.frequency_mean === null ? '-' : '~' + d.frequency_mean + '%';
                    var vMean = d.valence_mean === null ? '-'
                        : ( d.valence_mean >= 0 ? '+' : '' ) + d.valence_mean.toFixed( 1 );
                    $row.html( icon +
                        '<span class="pcp-effect-fmean">avg ' + fFmt + '</span>' +
                        ' <span class="pcp-effect-vmean">avg ' + vMean + '</span>' +
                        ' <span class="pcp-effect-n">(n=' + d.n + ')</span>'
                    );
                } else {
                    $row.html( icon + '<span class="pcp-effect-noreports">no reports yet</span>' );
                }
            }

            function submit() {
                if ( !loggedIn ) {
                    // Optimistic UI: state has already been updated locally; refresh
                    // the visible buttons so the user sees their preview, then prompt.
                    refreshButtons();
                    mw.notify( mw.msg( 'pharmacopedia-login-required' ), { type: 'warn', autoHide: false } );
                    return;
                }
                if ( state.perspective === 2 && !canProvider ) {
                    mw.notify( mw.msg( 'pharmacopedia-permission-denied' ), { type: 'error' } );
                    return;
                }
                var cur = state[ state.perspective ];
                var payload = {
                    action: 'pharmacopediaeffect',
                    element_id: elementId,
                    perspective: state.perspective,
                    valence: cur.valence === null ? '' : cur.valence,
                    format: 'json'
                };
                if ( state.perspective === 1 ) {
                    payload.experienced = cur.experienced === null ? '' : cur.experienced;
                } else {
                    payload.frequency = cur.frequency === null ? '' : cur.frequency;
                }
                api.postWithToken( 'csrf', payload ).done( function ( resp ) {
                    var d = resp.pharmacopediaeffect;
                    if ( !d ) { return; }
                    state[1].experienced = d.user_patient_experienced;
                    state[1].valence     = d.user_patient_valence;
                    state[2].frequency   = d.user_provider_frequency;
                    state[2].valence     = d.user_provider_valence;
                    updatePatientAgg( d.patient );
                    updateProviderAgg( d.provider );
                    refreshButtons();
                } ).fail( function ( code ) {
                    mw.notify( mw.msg( 'pharmacopedia-effect-failed' ) + ' (' + code + ')', { type: 'error' } );
                } );
            }

            // Patient yes/no/unsure
            $widget.find( '.pcp-effect-btn' ).on( 'click', function ( e ) {
                e.preventDefault();
                var v = parseInt( $( this ).data( 'experienced' ), 10 );
                var cur = state[1];
                if ( cur.experienced === v ) { cur.experienced = null; cur.valence = null; }
                else { cur.experienced = v; if ( v !== 1 ) { cur.valence = null; } }
                submit();
            } );

            // Provider frequency, slider (continuous) + Don't-know button (-1)
            $widget.find( '.pcp-effect-fbtn' ).on( 'click', function ( e ) {
                e.preventDefault();
                var v = parseInt( $( this ).data( 'frequency' ), 10 );
                var cur = state[2];
                if ( cur.frequency === v ) { cur.frequency = null; cur.valence = null; }
                else { cur.frequency = v; if ( v === -1 ) { cur.valence = null; } }
                submit();
            } );
            // Slider commits on `change` (release), not on every pixel of drag.
            $widget.find( '.pcp-effect-fslider' ).on( 'change', function () {
                var v = parseInt( $( this ).val(), 10 );
                if ( isNaN( v ) ) v = 0;
                if ( v < 0 ) v = 0; if ( v > 100 ) v = 100;
                state[2].frequency = v;
                submit();
            } );

            // Valence slider (shared between perspectives). Commits on `change` (release).
            $widget.find( '.pcp-effect-vslider' ).on( 'change', function () {
                var cur = state[ state.perspective ];
                var needsExposure = state.perspective === 1
                    ? cur.experienced === 1
                    : ( cur.frequency !== null && cur.frequency !== -1 );
                if ( !needsExposure ) {
                    mw.notify( state.perspective === 1
                        ? 'Mark "Yes" first to rate.'
                        : 'Pick a frequency first to rate.', { type: 'info' } );
                    return;
                }
                var v = parseInt( $( this ).val(), 10 );
                if ( isNaN( v ) ) v = 0;
                if ( v < -100 ) v = -100; if ( v > 100 ) v = 100;
                cur.valence = v;
                submit();
            } );

            // Perspective radio
            $widget.find( '.pcp-effect-perspective-row input[type="radio"]' ).on( 'change', function () {
                state.perspective = parseInt( $( this ).val(), 10 );
                $widget.attr( 'data-current-perspective', state.perspective );
                refreshButtons();
            } );

            refreshButtons();
        } );

        // ===== <discuss> (unchanged) =====
        function personalizeDiscuss( $widget ) {
            $widget.find( '> .pcp-discuss-newform' ).toggle( loggedIn );
            $widget.find( '> .pcp-discuss-loginnotice' ).toggle( !loggedIn );
            $widget.find( '.pcp-c' ).each( function () {
                var $c = $( this );
                var isOwn = $c.attr( 'data-author-mine' ) === '1';
                $c.find( '> .pcp-c-actions .pcp-c-reply' ).toggle( loggedIn );
                $c.find( '> .pcp-c-actions .pcp-c-edit' ).toggle( isOwn || isSysop );
                $c.find( '> .pcp-c-actions .pcp-c-delete' ).toggle( isOwn || isSysop );
            } );
        }
        function bindDiscussWidget( $widget ) {
            var elementId = $widget.data( 'element-id' );
            if ( !elementId ) { return; }
            personalizeDiscuss( $widget );
            function submitOp( op, params, $btn ) {
                if ( !loggedIn ) {
                    mw.notify( mw.msg( 'pharmacopedia-login-required' ), { type: 'warn' } );
                    return;
                }
                params = $.extend( { action: 'pharmacopediacomment', op: op, element_id: elementId, format: 'json' }, params );
                if ( $btn ) { $btn.prop( 'disabled', true ); }
                api.postWithToken( 'csrf', params ).done( function ( resp ) {
                    var d = resp.pharmacopediacomment;
                    if ( !d || !d.html ) { return; }
                    var $new = $( d.html );
                    $widget.replaceWith( $new );
                    bindDiscussWidget( $new );
                } ).fail( function ( code ) {
                    mw.notify( mw.msg( 'pharmacopedia-comment-failed' ) + ' (' + code + ')', { type: 'error' } );
                } ).always( function () {
                    if ( $btn ) { $btn.prop( 'disabled', false ); }
                } );
            }
            $widget.find( '> .pcp-discuss-newform .pcp-discuss-submit' ).on( 'click', function () {
                var showName = $widget.find( '> .pcp-discuss-newform .pcp-discuss-showname' ).is( ':checked' ) ? 1 : 0;
                var $form = $( this ).closest( '.pcp-discuss-newform' );
                var text = $form.find( '.pcp-discuss-input' ).val().trim();
                if ( text === '' ) { return; }
                submitOp( 'add', { text: text }, $( this ) );
            } );
            $widget.find( '.pcp-c' ).each( function () {
                var $c = $( this );
                var commentId = $c.data( 'comment-id' );
                $c.find( '> .pcp-c-actions .pcp-c-reply' ).on( 'click', function () {
                    $c.find( '> .pcp-c-replyform, > .pcp-c-editform' ).remove();
                    var $form = $( '<div class="pcp-c-replyform">' +
                        '<textarea class="pcp-c-replyinput" rows="2" placeholder="Reply…"></textarea>' +
                        '<button type="button" class="pcp-c-replysubmit">Reply</button></div>' );
                    $c.append( $form );
                    $form.find( '.pcp-c-replyinput' ).focus();
                    $form.find( '.pcp-c-replysubmit' ).on( 'click', function () {
                        var text = $form.find( '.pcp-c-replyinput' ).val().trim();
                        if ( text === '' ) { return; }
                        submitOp( 'add', { text: text, parent_id: commentId }, $( this ) );
                    } );
                } );
                $c.find( '> .pcp-c-actions .pcp-c-edit' ).on( 'click', function () {
                    $c.find( '> .pcp-c-replyform, > .pcp-c-editform' ).remove();
                    var oldText = $c.find( '> .pcp-c-body' ).text();
                    var $form = $( '<div class="pcp-c-editform">' +
                        '<textarea class="pcp-c-editinput" rows="3"></textarea>' +
                        '<button type="button" class="pcp-c-editsubmit">Save</button></div>' );
                    $form.find( '.pcp-c-editinput' ).val( oldText ).focus();
                    $c.append( $form );
                    $form.find( '.pcp-c-editsubmit' ).on( 'click', function () {
                        var text = $form.find( '.pcp-c-editinput' ).val().trim();
                        if ( text === '' ) { return; }
                        submitOp( 'edit', { comment_id: commentId, text: text }, $( this ) );
                    } );
                } );
                $c.find( '> .pcp-c-actions .pcp-c-delete' ).on( 'click', function () {
                    if ( !window.confirm( mw.msg( 'pharmacopedia-confirm-delete' ) ) ) { return; }
                    submitOp( 'delete', { comment_id: commentId }, $( this ) );
                } );
            } );
        }

        // ===== Auto-sort <effect> lists by provider prevalence (desc) =====
        // For each <ul> whose direct <li> children ALL contain a .pcp-effect card,
        // sort the <li>s by the provider aggregate's data-fmean, descending.
        // Cards with no provider data sink to the bottom (treated as -1).
        function pcpEffectSortKey( li ) {
            var $p = $( li ).find( '.pcp-agg-provider' );
            if ( !$p.length ) { return -1; }
            var n = parseInt( $p.attr( 'data-n' ) || '0', 10 );
            if ( !n ) { return -1; }
            var v = parseFloat( $p.attr( 'data-fmean' ) );
            return isNaN( v ) ? -1 : v;
        }
        // Before sorting, absorb any .pcp-effect cards that ended up outside
        // their preceding <ul> (happens with multi-line <effect>body</effect> tags).
        function absorbOrphanEffects() {
            $( '.pcp-effect' ).each( function () {
                var $card = $( this );
                // Already inside an <li>? Skip.
                if ( $card.closest( 'li' ).length ) { return; }
                // Find the closest preceding <ul> in the same DOM region
                var $ul = $card.prevAll( 'ul' ).first();
                if ( !$ul.length ) { $ul = $card.parent().find( 'ul' ).first(); }
                if ( !$ul.length ) { return; }
                // Detach the card (and any orphan text/closing fragments
                // immediately after it that belong to its tag body)
                $card.wrap( '<li></li>' ).parent().appendTo( $ul );
            } );
        }
        // Merge runs of adjacent <ul>s that both contain .pcp-effect cards
        // (multi-line <effect> wikitext makes MW emit split lists).
        function mergeAdjacentEffectUls() {
            $( 'ul' ).each( function () {
                var $ul = $( this );
                if ( !$ul.find( '.pcp-effect' ).length ) { return; }
                var $next = $ul.next();
                while ( $next.length && $next.is( 'ul' ) && $next.find( '.pcp-effect' ).length ) {
                    $next.children( 'li' ).appendTo( $ul );
                    var $toRemove = $next;
                    $next = $next.next();
                    $toRemove.remove();
                }
            } );
        }
        // ===== Bucket sorted <effect> lists into Common / Uncommon / Rare / Unrated =====
        // Thresholds operate on the provider frequency aggregate (data-fmean, 0-100):
        //   Common    : fmean > 20
        //   Uncommon  : 5 <= fmean <= 20
        //   Rare      : 0 <= fmean < 5   (includes "never observed" = 0)
        //   Unrated   : no provider data (n=0)
        // Common is always visible. Uncommon / Rare / Unrated are <details> collapsed by default.
        // Unrated only renders when non-empty. If Common is empty but Uncommon/Rare have items,
        // the Common section still renders with a "No effects here yet" placeholder.
        function pcpBucketKey( li ) {
            var $p = $( li ).find( '.pcp-agg-provider' );
            if ( !$p.length ) { return 'unrated'; }
            var n = parseInt( $p.attr( 'data-n' ) || '0', 10 );
            if ( !n ) { return 'unrated'; }
            var v = parseFloat( $p.attr( 'data-fmean' ) );
            if ( isNaN( v ) ) { return 'unrated'; }
            if ( v > 20 ) { return 'common'; }
            if ( v > 5 ) { return 'uncommon'; }
            // Rare band (fmean <= 5). Split off "Rare but Severe" when provider valence is strongly negative.
            var vm = parseFloat( $p.attr( 'data-vmean' ) );
            if ( !isNaN( vm ) && vm <= -2.5 ) { return 'rareSevere'; }
            return 'rare';
        }
        function pcpBucketEffectUl( $ul ) {
            var $lis = $ul.children( 'li' );
            if ( !$lis.length ) { return; }
            var buckets = { common: [], uncommon: [], rare: [], rareSevere: [], unrated: [] };
            $lis.each( function () {
                buckets[ pcpBucketKey( this ) ].push( this );
            } );
            var nRated = buckets.common.length + buckets.uncommon.length + buckets.rare.length + buckets.rareSevere.length;
            var nTotal = nRated + buckets.unrated.length;
            if ( !nTotal ) { return; }

            function mkUl( items, klass ) {
                var $u = $( '<ul class="pcp-effect-bucket-list ' + klass + '"></ul>' );
                if ( items.length ) {
                    $u.append( items );
                } else {
                    $u.append( '<li class="pcp-effect-bucket-empty"><em>No effects here yet.</em></li>' );
                }
                return $u;
            }
            function mkCollapsible( label, items, klass, openByDefault ) {
                var openAttr = openByDefault ? ' open' : '';
                return $( '<details class="pcp-effect-bucket ' + klass + '"' + openAttr + '></details>' )
                    .append( '<summary class="pcp-effect-bucket-heading">' +
                        label + ' <span class="pcp-effect-bucket-count">(' + items.length + ')</span>' +
                        '</summary>' )
                    .append( mkUl( items, klass + '-list' ) );
            }

            var $container = $( '<div class="pcp-effect-buckets"></div>' );

            if ( nRated > 0 ) {
                $container.append(
                    $( '<div class="pcp-effect-bucket pcp-effect-bucket-common"></div>' )
                        .append( $( '<h4 class="pcp-effect-bucket-heading"></h4>' )
                            .text( 'Common ' )
                            .append( '<span class="pcp-effect-bucket-count">(' + buckets.common.length + ')</span>' ) )
                        .append( mkUl( buckets.common, 'pcp-effect-bucket-common-list' ) )
                );
                if ( buckets.uncommon.length ) {
                    $container.append( mkCollapsible( 'Uncommon', buckets.uncommon, 'pcp-effect-bucket-uncommon' ) );
                }
                if ( buckets.rare.length ) {
                    $container.append( mkCollapsible( 'Rare', buckets.rare, 'pcp-effect-bucket-rare' ) );
                }
                if ( buckets.rareSevere.length ) {
                    $container.append( mkCollapsible( 'Rare but Severe', buckets.rareSevere, 'pcp-effect-bucket-rare-severe', true ) );
                }
            }
            if ( buckets.unrated.length ) {
                $container.append( mkCollapsible( 'Not yet rated', buckets.unrated, 'pcp-effect-bucket-unrated' ) );
            }

            $ul.replaceWith( $container );
        }

        function runEffectSort() {
            mergeAdjacentEffectUls();
            absorbOrphanEffects();
            var totalSorted = 0;
            var toBucket = [];
            $( 'ul' ).each( function () {
                var $ul = $( this );
                var $lis = $ul.children( 'li' );
                if ( !$lis.length ) { return; }
                var allEffects = true;
                $lis.each( function () {
                    if ( !$( this ).children( '.pcp-effect' ).length ) {
                        allEffects = false;
                        return false;
                    }
                } );
                if ( !allEffects ) { return; }
                $ul.addClass( 'pcp-effect-sort' );
                if ( $lis.length >= 2 ) {
                    var arr = $lis.toArray();
                    arr.sort( function ( a, b ) {
                        return pcpEffectSortKey( b ) - pcpEffectSortKey( a );
                    } );
                    $( arr ).appendTo( $ul );
                    totalSorted += arr.length;
                }
                toBucket.push( $ul );
            } );
            for ( var i = 0; i < toBucket.length; i++ ) {
                pcpBucketEffectUl( toBucket[ i ] );
            }
            if ( window.console && window.console.log ) {
                window.console.log( '[pharmacopedia] effect sort: reordered ' + totalSorted +
                    ' items, bucketed ' + toBucket.length + ' list(s)' );
            }
        }
        // Run after a tiny defer so we don't fight any concurrent init,
        // then again on window.load as a belt-and-suspenders pass.
        setTimeout( runEffectSort, 0 );
        $( window ).on( 'load', runEffectSort );

        $( '.pcp-discuss' ).each( function () { bindDiscussWidget( $( this ) ); } );

        // ===== VerifyProvider webcam photo capture =====
        var $camStart = $( '#pcp-vp-cam-start' );
        if ( $camStart.length ) {
            var $video = $( '#pcp-vp-cam-video' );
            var $canvas = $( '#pcp-vp-cam-canvas' );
            var $controls = $( '#pcp-vp-cam-controls' );
            var $snap = $( '#pcp-vp-cam-snap' );
            var $retake = $( '#pcp-vp-cam-retake' );
            var $preview = $( '#pcp-vp-cam-preview' );
            var $status = $( '#pcp-vp-cam-status' );
            var $hidden = $( 'input[name="wpphoto_data"]' );
            var stream = null;
            function stopStream() {
                if ( stream ) { stream.getTracks().forEach( function ( t ) { t.stop(); } ); stream = null; }
            }
            $camStart.on( 'click', function () {
                if ( !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia ) {
                    $status.text( 'Camera not supported in this browser.' );
                    return;
                }
                $status.text( 'Requesting camera permission…' );
                navigator.mediaDevices.getUserMedia( { video: { facingMode: 'user' }, audio: false } )
                    .then( function ( s ) {
                        stream = s;
                        $video[0].srcObject = s;
                        $video.show();
                        $controls.show();
                        $camStart.hide();
                        $status.text( 'Position your face in frame, then click Capture.' );
                    } )
                    .catch( function ( err ) {
                        $status.text( 'Camera permission denied or unavailable: ' + err.message );
                    } );
            } );
            $snap.on( 'click', function () {
                var v = $video[0];
                var c = $canvas[0];
                c.width = v.videoWidth || 640;
                c.height = v.videoHeight || 480;
                c.getContext( '2d' ).drawImage( v, 0, 0, c.width, c.height );
                var dataUrl = c.toDataURL( 'image/jpeg', 0.85 );
                $hidden.val( dataUrl );
                $preview.attr( 'src', dataUrl ).show();
                $video.hide();
                $snap.hide();
                $retake.show();
                stopStream();
                $status.text( 'Photo captured. Click Retake to redo, or submit the form when ready.' );
            } );
            $retake.on( 'click', function () {
                $hidden.val( '' );
                $preview.hide().attr( 'src', '' );
                $retake.hide();
                $snap.show();
                $camStart.show().click();
            } );
            $( window ).on( 'beforeunload', stopStream );
        }


        // ===== <problem> Likert 0-5 efficacy ratings =====
        // Problem efficacy likert: DK button + 0–100 slider.
        $( document ).on( 'click', '.pcp-likert-btn-dk', function ( e ) {
            e.preventDefault();
            var $b = $( this );
            var $card = $b.closest( '.pcp-problem' );
            if ( !$card.length ) return;
            var elementId = parseInt( $card.attr( 'data-element-id' ), 10 );
            if ( !elementId ) return;
            // Toggle: if already DK, clear; else set DK (-1)
            var curRating = $card.attr( 'data-user-rating' );
            var newVal = ( curRating === '-1' ) ? '' : -1;
            api.postWithToken( 'csrf', {
                action: 'pharmacopedialikert',
                element_id: elementId,
                value: newVal,
                format: 'json'
            } ).done( function ( resp ) {
                var d = resp.pharmacopedialikert;
                if ( !d ) return;
                $card.attr( 'data-user-rating', d.user_value === null ? '' : d.user_value );
                $card.attr( 'data-likert-n', d.n );
                $card.attr( 'data-likert-mean', d.n > 0 ? d.mean : '' );
                // DK active visuals
                $card.find( '.pcp-likert-btn-dk' ).toggleClass( 'pcp-likert-active', d.user_value === -1 );
                $card.find( '.pcp-likert-slider-wrap' ).toggleClass( 'pcp-likert-dk-on', d.user_value === -1 );
                // Update aggregate text
                if ( d.n > 0 ) {
                    var $agg = $card.find( '.pcp-problem-agg' );
                    $agg.html( $agg.html().replace( /[\d.]+/, Number( d.mean ).toFixed( 1 ) ) );
                }
            } ).fail( function ( code ) {
                mw.notify( 'Save failed (' + code + ')', { type: 'error' } );
            } );
        } );
        // Slider commits on change (release)
        $( document ).on( 'change', '.pcp-likert-slider', function () {
            var $sl = $( this );
            var $card = $sl.closest( '.pcp-problem' );
            if ( !$card.length ) return;
            var elementId = parseInt( $card.attr( 'data-element-id' ), 10 );
            if ( !elementId ) return;
            var v = parseInt( $sl.val(), 10 );
            if ( isNaN( v ) ) v = 0;
            if ( v < 0 ) v = 0; if ( v > 100 ) v = 100;
            api.postWithToken( 'csrf', {
                action: 'pharmacopedialikert',
                element_id: elementId,
                value: v,
                format: 'json'
            } ).done( function ( resp ) {
                var d = resp.pharmacopedialikert;
                if ( !d ) return;
                $card.attr( 'data-user-rating', d.user_value === null ? '' : d.user_value );
                $card.attr( 'data-likert-n', d.n );
                $card.attr( 'data-likert-mean', d.n > 0 ? d.mean : '' );
                $card.find( '.pcp-likert-btn-dk' ).removeClass( 'pcp-likert-active' );
                $card.find( '.pcp-likert-slider-wrap' ).removeClass( 'pcp-likert-dk-on' );
                if ( d.n > 0 ) {
                    var $agg = $card.find( '.pcp-problem-agg' );
                    var mFmt = Number( d.mean ).toFixed( 1 );
                    // Replace the first number in the agg text with the new mean
                    $agg.html( $agg.html().replace( /[\d.]+/, mFmt ) );
                }
            } ).fail( function ( code ) {
                mw.notify( 'Save failed (' + code + ')', { type: 'error' } );
            } );
        } );



        // ===== Element delete buttons =====
        // Reveal × button on each element card if current user is sysop or the author.
        var canDeleteAny = isSysop || userGroups.indexOf( 'admin' ) >= 0;
        $( '.pcp-del-btn' ).each( function () {
            var $b = $( this );
            var btnAuthor = $b.attr( 'data-author' ) || '';
            var currentUser = mw.config.get( 'wgUserName' ) || '';
            if ( canDeleteAny || ( currentUser !== '' && currentUser === btnAuthor ) ) {
                $b.addClass( 'pcp-del-btn-visible' );
            }
        } );
        $( document ).on( 'click', '.pcp-del-btn.pcp-del-btn-visible', function ( e ) {
            e.preventDefault();
            var $b = $( this );
            var type = $b.attr( 'data-type' );
            var slug = $b.attr( 'data-slug' );
            if ( !window.confirm( 'Delete this ' + type + '? This cannot be undone (but a sysop can restore via page history).' ) ) {
                return;
            }
            $b.prop( 'disabled', true );
            $.ajax( {
                method: 'POST',
                url: mw.util.getUrl( 'Special:DeletePharmaElement' ),
                data: {
                    target: mw.config.get( 'wgPageName' ).replace( /_/g, ' ' ),
                    type: type,
                    slug: slug,
                    wpEditToken: mw.user.tokens.get( 'csrfToken' ),
                    format: 'json'
                },
                dataType: 'json'
            } ).done( function ( resp ) {
                if ( resp && resp.ok ) {
                    mw.notify( 'Deleted.', { type: 'success' } );
                    setTimeout( function () { window.location.reload(); }, 400 );
                } else {
                    mw.notify( ( resp && resp.error ) || 'Delete failed.', { type: 'error' } );
                    $b.prop( 'disabled', false );
                }
            } ).fail( function ( xhr ) {
                var msg = 'Delete failed (HTTP ' + xhr.status + ').';
                try { var j = JSON.parse( xhr.responseText ); if ( j && j.error ) { msg = j.error + ' (HTTP ' + xhr.status + ')'; } } catch ( e ) {
                    if ( xhr.responseText ) {
                        var snip = xhr.responseText.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, 200 );
                        if ( snip ) { msg += ' Server: ' + snip; }
                    }
                }
                mw.notify( msg, { type: 'error' } );
                console.error( 'Pharmacopedia delete failed:', xhr.status, xhr.responseText );
                $b.prop( 'disabled', false );
            } );
        } );


        // ===== Suggest Titration modal =====
        function openTitrationModal() {
            var pageName = mw.config.get( 'wgPageName' );
            $( '.pcp-st-modal' ).remove();
            var $overlay = $(
                '<div class="pcp-st-modal">' +
                  '<div class="pcp-st-modal-box">' +
                    '<button type="button" class="pcp-st-modal-close" aria-label="Close">×</button>' +
                    '<h3>Add a titration strategy</h3>' +
                    '<p class="pcp-st-modal-sub">Your suggestion will be reviewed before going live.</p>' +
                    '<div class="pcp-st-modal-err" style="display:none;"></div>' +
                    '<label>Title<br><input type="text" class="pcp-st-modal-title" maxlength="200" placeholder="e.g. Slow start (elderly)"></label>' +
                    '<label>Body (wikitext allowed)<br><textarea class="pcp-st-modal-body" rows="8" placeholder="Starting dose: ...\nEscalation: ...\nMonitoring: ...\nBest suited for: ...\n\nCite sources with &lt;ref&gt;...&lt;/ref&gt;"></textarea></label>' +
                    '<div class="pcp-st-modal-actions">' +
                      '<button type="button" class="pcp-st-modal-cancel">Cancel</button>' +
                      '<button type="button" class="pcp-st-modal-submit mw-ui-button mw-ui-progressive">Submit for review</button>' +
                    '</div>' +
                  '</div>' +
                '</div>'
            );
            $( 'body' ).append( $overlay );
            $overlay.find( '.pcp-st-modal-title' ).focus();
            function close() { $overlay.remove(); }
            $overlay.on( 'click', function ( e ) { if ( e.target === $overlay[0] ) { close(); } } );
            $overlay.find( '.pcp-st-modal-close, .pcp-st-modal-cancel' ).on( 'click', close );
            $overlay.find( '.pcp-st-modal-submit' ).on( 'click', function () {
                if ( !loggedIn ) {
                    mw.notify( mw.msg( 'pharmacopedia-login-required' ), { type: 'warn' } );
                    return;
                }
                var $btn = $( this );
                var titleVal = $overlay.find( '.pcp-st-modal-title' ).val().trim();
                var bodyVal  = $overlay.find( '.pcp-st-modal-body' ).val().trim();
                var $err = $overlay.find( '.pcp-st-modal-err' );
                $err.hide().text( '' );
                if ( titleVal === '' ) { $err.text( 'Title is required.' ).show(); return; }
                if ( bodyVal  === '' ) { $err.text( 'Body is required.' ).show(); return; }
                $btn.prop( 'disabled', true ).text( 'Submitting…' );
                $.ajax( {
                    method: 'POST',
                    url: mw.util.getUrl( 'Special:SuggestTitration' ),
                    data: {
                        target: pageName.replace( /_/g, ' ' ),
                        titration_title: titleVal,
                        titration_body: bodyVal,
                        wpEditToken: mw.user.tokens.get( 'csrfToken' ),
                        format: 'json'
                    },
                    dataType: 'json'
                } ).done( function ( resp ) {
                    if ( resp && resp.ok ) {
                        mw.notify( 'Submitted, a reviewer will approve it shortly.', { type: 'success' } );
                        close();
                        setTimeout( function () { window.location.reload(); }, 600 );
                    } else {
                        $err.text( ( resp && resp.error ) || 'Submission failed.' ).show();
                        $btn.prop( 'disabled', false ).text( 'Submit for review' );
                    }
                } ).fail( function ( xhr ) {
                    var msg = 'Submission failed.';
                    try { var j = JSON.parse( xhr.responseText ); if ( j && j.error ) { msg = j.error; } } catch ( e ) {}
                    $err.text( msg ).show();
                    $btn.prop( 'disabled', false ).text( 'Submit for review' );
                } );
            } );
            $( document ).on( 'keydown.pcpst', function ( e ) {
                if ( e.key === 'Escape' ) { close(); $( document ).off( 'keydown.pcpst' ); }
            } );
        }

        // ===== Suggest Effect modal (search-first, two-stage) =====
        function openEffectModal() {
            var pageName = mw.config.get( 'wgPageName' );
            $( '.pcp-se-modal' ).remove();
            var $overlay = $(
                '<div class="pcp-st-modal pcp-se-modal" data-pcp-se2-stage="search">' +
                  '<div class="pcp-st-modal-box">' +
                    '<button type="button" class="pcp-st-modal-close" aria-label="Close">×</button>' +
                    '<h3>Add an effect</h3>' +
                    '<p class="pcp-st-modal-sub">Search the effects library, or create a new one if it isn\'t there.</p>' +
                    '<div class="pcp-st-modal-err" style="display:none;"></div>' +
                    '<div class="pcp-se2-search-stage">' +
                      '<label>Search<br><input type="text" class="pcp-se2-search" placeholder="Start typing, e.g. hyperkalemia, headache..." autocomplete="off"></label>' +
                      '<div class="pcp-se2-results"></div>' +
                      '<p style="font-size:0.9em; margin-top:0.6em;"><a href="#" class="pcp-se2-newlink">+ Create a new effect</a></p>' +
                    '</div>' +
                    '<div class="pcp-se2-create-stage" style="display:none;">' +
                      '<p><a href="#" class="pcp-se2-back">← back to search</a></p>' +
                      '<label>Name<br><input type="text" class="pcp-se2-name" placeholder="e.g. Hyperkalemia"></label>' +
                      '<label>Aliases (optional, comma-separated, helps future search)<br><input type="text" class="pcp-se2-aliases" placeholder="e.g. elevated potassium, high K+"></label>' +
                      '<label>Page-specific note (optional)<br><textarea class="pcp-se2-desc" rows="3" placeholder="If this effect on this specific medicine has notable context."></textarea></label>' +
                    '</div>' +
                    '<div class="pcp-st-modal-actions">' +
                      '<button type="button" class="pcp-st-modal-cancel">Cancel</button>' +
                      '<button type="button" class="pcp-se2-submit mw-ui-button mw-ui-progressive" disabled>Add to page</button>' +
                    '</div>' +
                  '</div>' +
                '</div>'
            );
            $( 'body' ).append( $overlay );
            $overlay.find( '.pcp-se2-search' ).focus();
            var selectedRef = null;
            function close() { $overlay.remove(); }
            $overlay.on( 'click', function ( e ) { if ( e.target === $overlay[0] ) { close(); } } );
            $overlay.find( '.pcp-st-modal-close, .pcp-st-modal-cancel' ).on( 'click', close );

            function clearModalErr() {
                $overlay.find( '.pcp-st-modal-err' ).hide().text( '' );
            }
            function setStage( stage ) {
                $overlay.attr( 'data-pcp-se2-stage', stage );
                if ( stage === 'search' ) {
                    $overlay.find( '.pcp-se2-search-stage' ).show();
                    $overlay.find( '.pcp-se2-create-stage' ).hide();
                } else {
                    $overlay.find( '.pcp-se2-search-stage' ).hide();
                    $overlay.find( '.pcp-se2-create-stage' ).show();
                }
                clearModalErr();
                updateSubmitState();
            }
            function updateSubmitState() {
                var stage = $overlay.attr( 'data-pcp-se2-stage' );
                var enabled;
                if ( stage === 'search' ) { enabled = !!selectedRef; }
                else { enabled = $overlay.find( '.pcp-se2-name' ).val().trim() !== ''; }
                $overlay.find( '.pcp-se2-submit' ).prop( 'disabled', !enabled );
            }

            function renderResults( matches, q ) {
                var $r = $overlay.find( '.pcp-se2-results' );
                if ( !matches.length ) {
                    $r.html( '<p class="pcp-se2-noresults">No matches' + ( q ? ' for "' + $('<div>').text(q).html() + '"' : '' ) + '. <a href="#" class="pcp-se2-newlink">Create new →</a></p>' );
                    return;
                }
                var html = '<ul class="pcp-se2-list">';
                matches.forEach( function ( m ) {
                    html += '<li data-slug="' + $('<div>').text(m.slug).html() + '">' +
                              '<button type="button" class="pcp-se2-pick">Use</button>' +
                              '<strong>' + $('<div>').text(m.name).html() + '</strong>';
                    if ( m.description ) {
                        var desc = String(m.description).replace(/<[^>]+>/g,'');
                        if ( desc.length > 120 ) { desc = desc.slice(0,117) + '…'; }
                        html += ' <span class="pcp-se2-desc-preview">' + $('<div>').text(desc).html() + '</span>';
                    }
                    if ( m.aliases ) {
                        html += ' <span class="pcp-se2-aliases-preview">(' + $('<div>').text(m.aliases).html() + ')</span>';
                    }
                    html += '</li>';
                } );
                html += '</ul>';
                $r.html( html );
            }

            var searchTimer = null;
            function runSearch() {
                var q = $overlay.find( '.pcp-se2-search' ).val();
                $.ajax( {
                    method: 'GET',
                    url: mw.util.wikiScript( 'api' ),
                    data: { action: 'pharmacopediaeffectslookup', q: q, format: 'json' },
                    dataType: 'json'
                } ).done( function ( resp ) {
                    var d = resp.pharmacopediaeffectslookup;
                    renderResults( ( d && d.matches ) || [], q );
                } );
            }
            $overlay.on( 'input', '.pcp-se2-search', function () {
                selectedRef = null;
                clearModalErr();
                updateSubmitState();
                clearTimeout( searchTimer );
                searchTimer = setTimeout( runSearch, 180 );
            } );
            runSearch();

            $overlay.on( 'click', '.pcp-se2-pick', function () {
                var $li = $( this ).closest( 'li' );
                $overlay.find( '.pcp-se2-list li' ).removeClass( 'pcp-se2-selected' );
                $li.addClass( 'pcp-se2-selected' );
                selectedRef = $li.attr( 'data-slug' );
                clearModalErr();
                updateSubmitState();
            } );

            $overlay.on( 'click', '.pcp-se2-newlink', function ( e ) {
                e.preventDefault();
                var seed = $overlay.find( '.pcp-se2-search' ).val();
                if ( seed ) { $overlay.find( '.pcp-se2-name' ).val( seed ); }
                setStage( 'create' );
                $overlay.find( '.pcp-se2-name' ).focus();
            } );
            $overlay.on( 'click', '.pcp-se2-back', function ( e ) {
                e.preventDefault();
                setStage( 'search' );
            } );
            $overlay.on( 'input', '.pcp-se2-name', function () { clearModalErr(); updateSubmitState(); } );

            $overlay.find( '.pcp-se2-submit' ).on( 'click', function () {
                if ( !loggedIn ) { mw.notify( mw.msg( 'pharmacopedia-login-required' ), { type: 'warn' } ); return; }
                var $btn = $( this );
                var stage = $overlay.attr( 'data-pcp-se2-stage' );
                var data = {
                    target: pageName.replace( /_/g, ' ' ),
                    wpEditToken: mw.user.tokens.get( 'csrfToken' ),
                    format: 'json'
                };
                if ( stage === 'search' ) {
                    if ( !selectedRef ) { return; }
                    data.effect_ref = selectedRef;
                } else {
                    var nameVal = $overlay.find( '.pcp-se2-name' ).val().trim();
                    if ( nameVal === '' ) { return; }
                    data.effect_label = nameVal;
                    data.effect_aliases = $overlay.find( '.pcp-se2-aliases' ).val().trim();
                    data.effect_description = $overlay.find( '.pcp-se2-desc' ).val().trim();
                }
                $btn.prop( 'disabled', true ).text( 'Submitting…' );
                $.ajax( {
                    method: 'POST',
                    url: mw.util.getUrl( 'Special:SuggestEffect' ),
                    data: data,
                    dataType: 'json'
                } ).done( function ( resp ) {
                    if ( resp && resp.ok ) {
                        mw.notify( 'Added.', { type: 'success' } );
                        close();
                        setTimeout( function () { window.location.reload(); }, 400 );
                    } else {
                        var $err = $overlay.find( '.pcp-st-modal-err' );
                        $err.text( ( resp && resp.error ) || 'Failed.' ).show();
                        $btn.prop( 'disabled', false ).text( 'Add to page' );
                        updateSubmitState();
                    }
                } ).fail( function ( xhr ) {
                    var msg = 'Failed.';
                    try { var j = JSON.parse( xhr.responseText ); if ( j && j.error ) { msg = j.error; } } catch ( e ) {}
                    var $err = $overlay.find( '.pcp-st-modal-err' );
                    $err.text( msg ).show();
                    $btn.prop( 'disabled', false ).text( 'Add to page' );
                    updateSubmitState();
                } );
            } );

            $( document ).on( 'keydown.pcpse', function ( e ) {
                if ( e.key === 'Escape' ) { close(); $( document ).off( 'keydown.pcpse' ); }
            } );
        }
        $( document ).on( 'click', '.pcp-effect-suggest, .pcp-effect-suggest-wrap a', function ( e ) {
            e.preventDefault();
            openEffectModal();
        } );

        $( document ).on( 'click', '.pcp-titration-suggest, .pcp-titration-suggest-wrap a', function ( e ) {
            e.preventDefault();
            openTitrationModal();
        } );



        // ===== Pharmacopedia Interactions modal =====
        function openInteractionModal( pageType, pageSlug, pageName ) {
            $( '.pcp-ix-modal' ).remove();
            var $overlay = $(
                '<div class="pcp-st-modal pcp-ix-modal">' +
                  '<div class="pcp-st-modal-box">' +
                    '<button type="button" class="pcp-st-modal-close" aria-label="Close">×</button>' +
                    '<h3>Add an interaction with ' + ( pageType === 'category' ? 'Category:' : '' ) +
                        $( '<div>' ).text( pageName ).html() + '</h3>' +
                    '<p class="pcp-st-modal-sub">Search for the other medicine or category. Both sides must already be wiki pages. Categories appear here only if tagged with <code>[[Category:MedCategory]]</code>.</p>' +
                    '<div class="pcp-st-modal-err" style="display:none;"></div>' +
                    '<label>Search<br><input type="text" class="pcp-ix-search" placeholder="Start typing a medicine or category name…" autocomplete="off"></label>' +
                    '<div class="pcp-ix-results"></div>' +
                    '<div class="pcp-st-modal-actions">' +
                      '<button type="button" class="pcp-st-modal-cancel">Cancel</button>' +
                      '<button type="button" class="pcp-ix-submit mw-ui-button mw-ui-progressive" disabled>Add interaction</button>' +
                    '</div>' +
                  '</div>' +
                '</div>'
            );
            $( 'body' ).append( $overlay );
            $overlay.find( '.pcp-ix-search' ).focus();
            var selected = null;
            function close() { $overlay.remove(); $( document ).off( 'keydown.pcpix' ); }
            $overlay.on( 'click', function ( e ) { if ( e.target === $overlay[0] ) { close(); } } );
            $overlay.find( '.pcp-st-modal-close, .pcp-st-modal-cancel' ).on( 'click', close );

            function clearErr() { $overlay.find( '.pcp-st-modal-err' ).hide().text( '' ); }
            function updateSubmit() {
                $overlay.find( '.pcp-ix-submit' ).prop( 'disabled', !selected );
            }

            function renderResults( matches, q ) {
                var $r = $overlay.find( '.pcp-ix-results' );
                if ( !matches.length ) {
                    if ( !q ) {
                        $r.html( '<p class="pcp-ix-hint">Type to search.</p>' );
                    } else {
                        $r.html( '<p class="pcp-ix-noresults">No medicine or category page matches "' +
                            $( '<div>' ).text( q ).html() + '".</p>' );
                    }
                    return;
                }
                var meds = [], cats = [];
                matches.forEach( function ( m ) {
                    ( m.type === 'category' ? cats : meds ).push( m );
                } );
                var html = '';
                function block( label, list, typeKey ) {
                    if ( !list.length ) { return ''; }
                    var out = '<h4 class="pcp-ix-group-h">' + label + '</h4><ul class="pcp-ix-list">';
                    list.forEach( function ( m ) {
                        out += '<li data-type="' + typeKey + '" data-slug="' + $( '<div>' ).text( m.slug ).html() + '">' +
                            '<button type="button" class="pcp-ix-pick">Use</button>' +
                            '<span class="pcp-ix-type-chip pcp-ix-type-' + typeKey + '">' +
                                ( typeKey === 'category' ? 'category' : 'medicine' ) +
                            '</span>' +
                            '<strong>' + $( '<div>' ).text( m.name ).html() + '</strong>' +
                            '</li>';
                    } );
                    out += '</ul>';
                    return out;
                }
                html += block( 'Medicines',  meds, 'medicine' );
                html += block( 'Categories', cats, 'category' );
                $r.html( html );
            }

            var searchTimer = null;
            function runSearch() {
                var q = $overlay.find( '.pcp-ix-search' ).val();
                $.ajax( {
                    method: 'GET',
                    url: mw.util.wikiScript( 'api' ),
                    data: {
                        action: 'pharmacopediainteractionsearch',
                        q: q,
                        exclude_type: pageType,
                        exclude_slug: pageSlug,
                        format: 'json'
                    },
                    dataType: 'json'
                } ).done( function ( resp ) {
                    var d = resp.pharmacopediainteractionsearch;
                    renderResults( ( d && d.matches ) || [], q );
                } );
            }
            $overlay.on( 'input', '.pcp-ix-search', function () {
                selected = null;
                clearErr();
                updateSubmit();
                clearTimeout( searchTimer );
                searchTimer = setTimeout( runSearch, 180 );
            } );
            renderResults( [], '' );

            $overlay.on( 'click', '.pcp-ix-pick', function () {
                var $li = $( this ).closest( 'li' );
                $overlay.find( '.pcp-ix-list li' ).removeClass( 'pcp-ix-selected' );
                $li.addClass( 'pcp-ix-selected' );
                selected = { type: $li.attr( 'data-type' ), slug: $li.attr( 'data-slug' ) };
                clearErr();
                updateSubmit();
            } );

            $overlay.find( '.pcp-ix-submit' ).on( 'click', function () {
                if ( !loggedIn ) { mw.notify( mw.msg( 'pharmacopedia-login-required' ), { type: 'warn' } ); return; }
                if ( !selected ) { return; }
                var $btn = $( this );
                $btn.prop( 'disabled', true ).text( 'Adding…' );
                api.postWithToken( 'csrf', {
                    action: 'pharmacopediainteractionadd',
                    left_type:  pageType,   left_slug:  pageSlug,
                    right_type: selected.type, right_slug: selected.slug,
                    format: 'json'
                } ).done( function ( resp ) {
                    var d = resp && resp.pharmacopediainteractionadd;
                    if ( d && d.ok ) {
                        mw.notify( d.was_new ? 'Interaction added.' : 'That interaction already existed; reloading.', { type: 'success' } );
                        close();
                        setTimeout( function () { window.location.reload(); }, 350 );
                    } else {
                        // We got HTTP 200 but no d.ok. Surface whatever the server actually sent.
                        var msg = 'Unexpected response.';
                        if ( resp && resp.error && resp.error.info ) {
                            msg = resp.error.info;
                        } else if ( resp ) {
                            try { msg = 'Unexpected response: ' + JSON.stringify( resp ).slice( 0, 280 ); }
                            catch ( e ) { msg = 'Unexpected response (non-serializable).'; }
                        }
                        $overlay.find( '.pcp-st-modal-err' ).text( msg ).show();
                        $btn.prop( 'disabled', false ).text( 'Add interaction' );
                        if ( window.console ) { window.console.error( '[pcp interaction add] unexpected resp', resp ); }
                    }
                } ).fail( function ( code, data ) {
                    var msg = 'Add failed: ' + ( code || 'unknown' );
                    if ( data ) {
                        if ( data.error && data.error.info ) { msg = data.error.info; }
                        else if ( typeof data === 'string' && data.length ) { msg += '. ' + data.slice( 0, 240 ); }
                    }
                    $overlay.find( '.pcp-st-modal-err' ).text( msg ).show();
                    $btn.prop( 'disabled', false ).text( 'Add interaction' );
                    if ( window.console ) { window.console.error( '[pcp interaction add] failed', code, data ); }
                } );
            } );

            $( document ).on( 'keydown.pcpix', function ( e ) {
                if ( e.key === 'Escape' ) { close(); }
            } );
        }
        $( document ).on( 'click', '.pcp-interaction-add', function ( e ) {
            e.preventDefault();
            if ( !loggedIn ) {
                mw.notify( mw.msg( 'pharmacopedia-login-required' ), { type: 'warn' } );
                return;
            }
            var $b = $( this );
            openInteractionModal( $b.data( 'page-type' ), $b.data( 'page-slug' ), $b.data( 'page-name' ) );
        } );



        // ===== Pharmacopedia Interaction rate widget =====
        function pcpIxBindRow( $row ) {
            var $rate = $row.find( '> .pcp-interaction-rate' );
            if ( !$rate.length || $rate.data( 'pcp-bound' ) ) { return; }
            $rate.data( 'pcp-bound', 1 );

            var elementId = parseInt( $row.attr( 'data-element-id' ), 10 );
            var canProvider = $rate.attr( 'data-can-provider' ) === '1';
            var isLoggedIn  = $rate.attr( 'data-logged-in' ) === '1';

            // Hide the provider radio entirely if the user doesn't have rights.
            if ( !canProvider ) { $rate.find( '.pcp-ix-persp-provider' ).hide(); }

            // State: per-perspective experience/valence (note kept in textarea DOM)
            function readSeed( n ) {
                var v = $rate.attr( 'data-user-' + n );
                if ( v === undefined || v === '' ) { return null; }
                return parseInt( v, 10 );
            }
            var state = {
                perspective: 1,
                1: { exp: readSeed( '1-exp' ), val: readSeed( '1-val' ) },
                2: { exp: readSeed( '2-exp' ), val: readSeed( '2-val' ) }
            };

            function pcpIxRefreshNote() {
                var n = $rate.attr( 'data-user-' + state.perspective + '-note' );
                $rate.find( '.pcp-ix-note-input' ).val( n || '' );
                $rate.find( '.pcp-ix-note-status' ).text( '' );
            }
            function refreshActive() {
                var cur = state[ state.perspective ];
                pcpIxRefreshNote();
                $rate.find( '.pcp-ix-expbtn' ).each( function () {
                    var $b = $( this );
                    $b.toggleClass( 'pcp-active',
                        cur.exp !== null && parseInt( $b.attr( 'data-experience' ), 10 ) === cur.exp );
                } );
                var $ixVsl = $rate.find( '.pcp-ix-vslider' );
                if ( $ixVsl.length ) {
                    var ivv = cur.val !== null ? cur.val : 0;
                    $ixVsl.val( ivv );
                    $ixVsl.next( 'output' ).val( ( ivv >= 0 ? '+' : '' ) + ivv );
                }
                // Outcome row disabled if no experience picked
                $rate.find( '.pcp-interaction-valrow' )
                    .toggleClass( 'pcp-disabled', cur.exp === null || cur.exp < 1 );
            }

            function updateAggLine( $agg, agg ) {
                var $iconBlock = $agg.find( '.pcp-interaction-agg-icon' ).get( 0 );
                var icon = $iconBlock ? $iconBlock.outerHTML : '';
                $agg.attr( 'data-n', agg.n );
                if ( agg.valence_mean !== null && agg.valence_mean !== undefined ) {
                    $agg.attr( 'data-vmean', agg.valence_mean );
                } else { $agg.removeAttr( 'data-vmean' ); }
                if ( agg.experience_mean !== null && agg.experience_mean !== undefined ) {
                    $agg.attr( 'data-emean', agg.experience_mean );
                } else { $agg.removeAttr( 'data-emean' ); }

                if ( agg.n === 0 ) {
                    $agg.html( icon + '<span class="pcp-interaction-agg-empty">no reports yet</span>' );
                } else {
                    var expFmt = agg.experience_mean !== null ? Number( agg.experience_mean ).toFixed( 1 ) : '-';
                    var vFmt = agg.valence_mean !== null
                        ? ( ( agg.valence_mean >= 0 ? '+' : '' ) + Number( agg.valence_mean ).toFixed( 1 ) )
                        : '-';
                    $agg.html( icon +
                        '<span class="pcp-interaction-agg-exp" title="experience: 1=a little, 5=extensive">exp ' + expFmt + '/5</span> ' +
                        '<span class="pcp-interaction-agg-val" title="outcome: -100 worst, +100 best">outcome ' + vFmt + '</span> ' +
                        '<span class="pcp-interaction-agg-n">(n=' + agg.n + ')</span>'
                    );
                }
            }

            function applyAggregates( resp ) {
                if ( !resp ) { return; }
                if ( resp.user_agg )     { updateAggLine( $row.find( '.pcp-interaction-agg-user' ),     resp.user_agg ); }
                if ( resp.provider_agg ) { updateAggLine( $row.find( '.pcp-interaction-agg-provider' ), resp.provider_agg ); }
                // Update severity styling
                var anySevere = ( resp.pooled && resp.pooled.severe ) ||
                                ( resp.user_agg && resp.user_agg.severe ) ||
                                ( resp.provider_agg && resp.provider_agg.severe );
                $row.toggleClass( 'pcp-interaction-severe', !!anySevere );
                if ( anySevere && !$row.find( '.pcp-interaction-severe-tag' ).length ) {
                    $row.find( '.pcp-interaction-head' ).append(
                        ' <span class="pcp-interaction-severe-tag">severe</span>'
                    );
                } else if ( !anySevere ) {
                    $row.find( '.pcp-interaction-severe-tag' ).remove();
                }
            }

            function submit( extraNote ) {
                if ( !isLoggedIn ) {
                    mw.notify( mw.msg( 'pharmacopedia-login-required' ), { type: 'warn' } );
                    return;
                }
                var cur = state[ state.perspective ];
                var payload = {
                    action: 'pharmacopediainteractionreport',
                    element_id: elementId,
                    perspective: state.perspective,
                    experience: cur.exp === null ? '' : cur.exp,
                    valence:    cur.val === null ? '' : cur.val,
                    format: 'json'
                };
                if ( extraNote !== undefined ) { payload.note = extraNote; }
                return api.postWithToken( 'csrf', payload ).done( function ( resp ) {
                    var d = resp && resp.pharmacopediainteractionreport;
                    if ( !d || !d.ok ) { mw.notify( 'Save failed.', { type: 'error' } ); return; }
                    applyAggregates( d );
                } ).fail( function ( code, data ) {
                    var msg = 'Save failed: ' + ( code || 'unknown' );
                    if ( data && data.error && data.error.info ) { msg = data.error.info; }
                    mw.notify( msg, { type: 'error' } );
                } );
            }

            // Perspective radio
            $rate.find( 'input[type="radio"]' ).on( 'change', function () {
                state.perspective = parseInt( $( this ).val(), 10 );
                refreshActive();
            } );

            // Experience buttons
            $rate.find( '.pcp-ix-expbtn' ).on( 'click', function ( e ) {
                e.preventDefault();
                if ( !isLoggedIn ) {
                    mw.notify( mw.msg( 'pharmacopedia-login-required' ), { type: 'warn' } );
                    return;
                }
                var v = parseInt( $( this ).attr( 'data-experience' ), 10 );
                var cur = state[ state.perspective ];
                cur.exp = ( cur.exp === v ) ? null : v;
                if ( cur.exp === null ) { cur.val = null; }
                refreshActive();
                submit();
            } );

            // Valence slider (commits on `change`, one save per release)
            $rate.find( '.pcp-ix-vslider' ).on( 'change', function () {
                if ( !isLoggedIn ) {
                    mw.notify( mw.msg( 'pharmacopedia-login-required' ), { type: 'warn' } );
                    return;
                }
                var cur = state[ state.perspective ];
                if ( cur.exp === null || cur.exp < 1 ) {
                    mw.notify( 'Pick an experience level first.', { type: 'info' } );
                    return;
                }
                var v = parseInt( $( this ).val(), 10 );
                if ( isNaN( v ) ) v = 0;
                if ( v < -100 ) v = -100; if ( v > 100 ) v = 100;
                cur.val = v;
                refreshActive();
                submit();
            } );

            // Note toggle
            $rate.on( 'click', '.pcp-ix-note-toggle', function ( e ) {
                e.preventDefault();
                var $n = $rate.find( '.pcp-ix-note' );
                $n.attr( 'hidden', $n.attr( 'hidden' ) ? null : 'hidden' );
                if ( !$n.attr( 'hidden' ) ) { $n.find( 'textarea' ).focus(); }
            } );
            $rate.find( '.pcp-ix-note-save' ).on( 'click', function () {
                var note = $rate.find( '.pcp-ix-note-input' ).val();
                var $status = $rate.find( '.pcp-ix-note-status' ).text( 'Saving…' );
                var promise = submit( note || '' );
                if ( promise && promise.done ) {
                    promise.done( function () {
                        $status.text( 'Saved.' );
                        $rate.attr( 'data-user-' + state.perspective + '-note', note || '' );
                    } ).fail( function () { $status.text( 'Save failed.' ); } );
                }
            } );

            refreshActive();
        }
        $( '.pcp-interaction-row' ).each( function () { pcpIxBindRow( $( this ) ); } );



        // ===== Pharmacopedia Interaction delete handlers =====
        // Visibility: row × shown only for sysop/admin. Note × shown for sysop/admin OR for the note's author.
        var isModForIx = isSysop || ( userGroups.indexOf( 'admin' ) >= 0 );
        $( '.pcp-ix-del-row' ).each( function () {
            if ( isModForIx ) { $( this ).addClass( 'pcp-ix-del-visible' ); }
        } );
        $( '.pcp-ix-del-note' ).each( function () {
            var authorId = parseInt( $( this ).closest( '.pcp-interaction-note' ).attr( 'data-author-id' ), 10 );
            if ( isModForIx || ( currentUserId && authorId === currentUserId ) ) {
                $( this ).addClass( 'pcp-ix-del-visible' );
            }
        } );

        function pcpIxOpenDeleteModal( $btn, $row, elementId, label ) {
            $( '.pcp-ix-delconfirm' ).remove();
            var $overlay = $(
                '<div class="pcp-st-modal pcp-ix-delconfirm">' +
                  '<div class="pcp-st-modal-box pcp-ix-delconfirm-box">' +
                    '<button type="button" class="pcp-st-modal-close" aria-label="Close">×</button>' +
                    '<div class="pcp-ix-delconfirm-icon" aria-hidden="true">⚠</div>' +
                    '<h3 class="pcp-ix-delconfirm-title">Warning</h3>' +
                    '<p class="pcp-ix-delconfirm-body">This removes the interaction on both sides for the whole site, and cannot easily be undone.</p>' +
                    '<p class="pcp-ix-delconfirm-counter">Counterparty: <strong></strong></p>' +
                    '<p class="pcp-ix-delconfirm-tread">Tread with care.</p>' +
                    '<div class="pcp-st-modal-actions">' +
                      '<button type="button" class="pcp-st-modal-cancel">Cancel</button>' +
                      '<button type="button" class="pcp-ix-delconfirm-go">Delete interaction</button>' +
                    '</div>' +
                  '</div>' +
                '</div>'
            );
            $overlay.find( '.pcp-ix-delconfirm-counter strong' ).text( label );
            $( 'body' ).append( $overlay );
            $overlay.find( '.pcp-ix-delconfirm-go' ).focus();

            function close() { $overlay.remove(); $( document ).off( 'keydown.pcpixdel' ); }
            $overlay.on( 'click', function ( e ) { if ( e.target === $overlay[0] ) { close(); } } );
            $overlay.find( '.pcp-st-modal-close, .pcp-st-modal-cancel' ).on( 'click', close );
            $( document ).on( 'keydown.pcpixdel', function ( e ) { if ( e.key === 'Escape' ) { close(); } } );

            $overlay.find( '.pcp-ix-delconfirm-go' ).on( 'click', function () {
                var $go = $( this ).prop( 'disabled', true ).text( 'Deleting…' );
                $btn.prop( 'disabled', true );
                api.postWithToken( 'csrf', {
                    action: 'pharmacopediainteractiondelete',
                    op: 'interaction',
                    element_id: elementId,
                    format: 'json'
                } ).done( function ( resp ) {
                    var d = resp && resp.pharmacopediainteractiondelete;
                    if ( d && d.ok ) {
                        mw.notify( 'Interaction deleted.', { type: 'success' } );
                        close();
                        $row.fadeOut( 200, function () { $( this ).remove(); } );
                    } else {
                        mw.notify( 'Delete failed.', { type: 'error' } );
                        $go.prop( 'disabled', false ).text( 'Delete interaction' );
                        $btn.prop( 'disabled', false );
                    }
                } ).fail( function ( code, data ) {
                    var msg = 'Delete failed: ' + ( code || 'unknown' );
                    if ( data && data.error && data.error.info ) { msg = data.error.info; }
                    mw.notify( msg, { type: 'error' } );
                    $go.prop( 'disabled', false ).text( 'Delete interaction' );
                    $btn.prop( 'disabled', false );
                } );
            } );
        }
        $( document ).on( 'click', '.pcp-ix-del-row.pcp-ix-del-visible', function ( e ) {
            e.preventDefault();
            var $btn = $( this );
            var elementId = parseInt( $btn.attr( 'data-element-id' ), 10 );
            var $row = $btn.closest( '.pcp-interaction-row' );
            var label = $row.find( '> .pcp-interaction-head .pcp-interaction-other' ).first().text() || 'this interaction';
            pcpIxOpenDeleteModal( $btn, $row, elementId, label );
        } );

        $( document ).on( 'click', '.pcp-ix-del-note.pcp-ix-del-visible', function ( e ) {
            e.preventDefault();
            var $btn = $( this );
            var $note = $btn.closest( '.pcp-interaction-note' );
            var elementId = parseInt( $note.attr( 'data-element-id' ), 10 );
            var authorId  = parseInt( $note.attr( 'data-author-id' ), 10 );
            var authorName = $note.attr( 'data-author-name' ) || 'this user';
            var perspective = parseInt( $note.attr( 'data-perspective' ), 10 );
            if ( !window.confirm( 'Delete the note from ' + authorName + '? The rating itself will be preserved.' ) ) {
                return;
            }
            $btn.prop( 'disabled', true );
            api.postWithToken( 'csrf', {
                action: 'pharmacopediainteractiondelete',
                op: 'note',
                element_id: elementId,
                target_user_id: authorId,
                perspective: perspective,
                format: 'json'
            } ).done( function ( resp ) {
                var d = resp && resp.pharmacopediainteractiondelete;
                if ( d && d.ok ) {
                    mw.notify( 'Note deleted.', { type: 'success' } );
                    var $list = $note.closest( '.pcp-interaction-notes-list' );
                    $note.fadeOut( 180, function () {
                        $( this ).remove();
                        // Recount and either update the (N) chip or remove the block entirely.
                        var n = $list.children( '.pcp-interaction-note' ).length;
                        if ( !n ) {
                            $list.closest( '.pcp-interaction-notes' ).remove();
                        } else {
                            $list.closest( '.pcp-interaction-notes' )
                                 .find( '.pcp-interaction-notes-count' ).text( '(' + n + ')' );
                        }
                    } );
                } else {
                    mw.notify( 'Delete failed.', { type: 'error' } );
                    $btn.prop( 'disabled', false );
                }
            } ).fail( function ( code, data ) {
                var msg = 'Delete failed: ' + ( code || 'unknown' );
                if ( data && data.error && data.error.info ) { msg = data.error.info; }
                mw.notify( msg, { type: 'error' } );
                $btn.prop( 'disabled', false );
            } );
        } );



        // ===== Unified row action toggle =====
        // Toggles a folded panel below the row head. Used by Problem/Effect/Titration/
        // Anecdote/Interaction once each is converted to the compact row layout.
        $( document ).on( 'click', '.pcp-row-action-toggle', function ( e ) {
            e.preventDefault();
            var $btn = $( this );
            var target = $btn.attr( 'data-target' );
            var $row = $btn.closest( '.pcp-row' );
            var $panel = $row.find( '> .pcp-row-' + target + '-panel' );
            if ( !$panel.length ) { return; }
            var nowHidden = $panel.attr( 'hidden' );
            if ( nowHidden ) {
                $panel.removeAttr( 'hidden' );
                $btn.addClass( 'pcp-row-action-open' ).attr( 'aria-expanded', 'true' );
            } else {
                $panel.attr( 'hidden', 'hidden' );
                $btn.removeClass( 'pcp-row-action-open' ).attr( 'aria-expanded', 'false' );
            }
        } );



        // ===== Problem sort =====
        // Sort each contiguous group of .pcp-row-problem siblings by their
        // likert mean (descending). n=0 rows sink to the bottom.
        function pcpSortProblems() {
            var groups = new Map();
            $( '.pcp-row-problem' ).each( function () {
                var p = this.parentNode;
                if ( !p ) { return; }
                if ( !groups.has( p ) ) { groups.set( p, [] ); }
                groups.get( p ).push( this );
            } );
            groups.forEach( function ( items, parent ) {
                if ( items.length < 2 ) { return; }
                // Use placeholders to preserve each row's original DOM slot.
                var placeholders = items.map( function ( el ) {
                    var ph = document.createComment( 'pcp-prob-slot' );
                    parent.insertBefore( ph, el );
                    el.remove();
                    return ph;
                } );
                var sorted = items.slice().sort( function ( a, b ) {
                    var aN = parseInt( a.getAttribute( 'data-likert-n' ) || '0', 10 );
                    var bN = parseInt( b.getAttribute( 'data-likert-n' ) || '0', 10 );
                    if ( !aN && bN ) { return 1; }
                    if ( aN && !bN ) { return -1; }
                    if ( !aN && !bN ) {
                        var aT = ( a.querySelector( '.pcp-row-title' ) || {} ).textContent || '';
                        var bT = ( b.querySelector( '.pcp-row-title' ) || {} ).textContent || '';
                        return aT.localeCompare( bT );
                    }
                    var aMean = parseFloat( a.getAttribute( 'data-likert-mean' ) || '0' );
                    var bMean = parseFloat( b.getAttribute( 'data-likert-mean' ) || '0' );
                    if ( bMean !== aMean ) { return bMean - aMean; }
                    var aT2 = ( a.querySelector( '.pcp-row-title' ) || {} ).textContent || '';
                    var bT2 = ( b.querySelector( '.pcp-row-title' ) || {} ).textContent || '';
                    return aT2.localeCompare( bT2 );
                } );
                for ( var i = 0; i < placeholders.length; i++ ) {
                    parent.replaceChild( sorted[ i ], placeholders[ i ] );
                }
            } );
        }
        pcpSortProblems();
        $( window ).on( 'load', pcpSortProblems );



        // ===== Titration sort =====
        // Sort each contiguous group of .pcp-titration siblings by their vote
        // score (descending). Ties broken alphabetically by title.
        function pcpSortTitrations() {
            var groups = new Map();
            $( '.pcp-titration' ).each( function () {
                var p = this.parentNode;
                if ( !p ) { return; }
                if ( !groups.has( p ) ) { groups.set( p, [] ); }
                groups.get( p ).push( this );
            } );
            groups.forEach( function ( items, parent ) {
                if ( items.length < 2 ) { return; }
                var placeholders = items.map( function ( el ) {
                    var ph = document.createComment( 'pcp-tit-slot' );
                    parent.insertBefore( ph, el );
                    el.remove();
                    return ph;
                } );
                var sorted = items.slice().sort( function ( a, b ) {
                    var aS = parseInt( a.getAttribute( 'data-vote-score' ) || '0', 10 );
                    var bS = parseInt( b.getAttribute( 'data-vote-score' ) || '0', 10 );
                    if ( aS !== bS ) { return bS - aS; }
                    var aT = ( a.querySelector( '.pcp-titration-title' ) || {} ).textContent || '';
                    var bT = ( b.querySelector( '.pcp-titration-title' ) || {} ).textContent || '';
                    return aT.localeCompare( bT );
                } );
                for ( var i = 0; i < placeholders.length; i++ ) {
                    parent.replaceChild( sorted[ i ], placeholders[ i ] );
                }
            } );
        }
        pcpSortTitrations();
        $( window ).on( 'load', pcpSortTitrations );



        // ===== Anecdote sort =====
        // Sort each contiguous group of .pcp-anecdote siblings by their vote
        // score (descending). Ties broken alphabetically by displayed title (badge+author).
        function pcpSortAnecdotes() {
            var groups = new Map();
            $( '.pcp-anecdote' ).each( function () {
                var p = this.parentNode;
                if ( !p ) { return; }
                if ( !groups.has( p ) ) { groups.set( p, [] ); }
                groups.get( p ).push( this );
            } );
            groups.forEach( function ( items, parent ) {
                if ( items.length < 2 ) { return; }
                var placeholders = items.map( function ( el ) {
                    var ph = document.createComment( 'pcp-anec-slot' );
                    parent.insertBefore( ph, el );
                    el.remove();
                    return ph;
                } );
                var sorted = items.slice().sort( function ( a, b ) {
                    var aS = parseInt( a.getAttribute( 'data-vote-score' ) || '0', 10 );
                    var bS = parseInt( b.getAttribute( 'data-vote-score' ) || '0', 10 );
                    if ( aS !== bS ) { return bS - aS; }
                    var aT = ( a.querySelector( '.pcp-row-title' ) || {} ).textContent || '';
                    var bT = ( b.querySelector( '.pcp-row-title' ) || {} ).textContent || '';
                    return aT.localeCompare( bT );
                } );
                for ( var i = 0; i < placeholders.length; i++ ) {
                    parent.replaceChild( sorted[ i ], placeholders[ i ] );
                }
            } );
        }
        pcpSortAnecdotes();
        $( window ).on( 'load', pcpSortAnecdotes );



        // ===== Experience section gate =====
        // Phase B: just the Yes/No gate behaviour. "Yes" reveals the (empty) form
        // mount that a later phase fills; "No" collapses with a reopen link.
        $( '.pcp-experience' ).each( function () {
            var $xp     = $( this );
            var $gate   = $xp.find( '.pcp-xp-gate' );
            var $mount  = $xp.find( '.pcp-xp-form-mount' );
            var $reopen = $xp.find( '.pcp-xp-reopen' );
            if ( !$gate.length ) { return; }

            function openForm() {
                $gate.hide();
                $reopen.attr( 'hidden', 'hidden' );
                $mount.removeAttr( 'hidden' );
            }
            $xp.find( '.pcp-xp-gate-yes' ).on( 'click', function () { openForm(); } );
            $xp.find( '.pcp-xp-gate-no' ).on( 'click', function () {
                $gate.hide();
                $mount.attr( 'hidden', 'hidden' );
                $reopen.removeAttr( 'hidden' );
            } );
            $reopen.on( 'click', function ( e ) { e.preventDefault(); openForm(); } );
        } );



        // ===== Experience form =====
        function pcpXpEsc( s ) { return $( '<div>' ).text( s == null ? '' : String( s ) ).html(); }

        function pcpXpBindForm( $form ) {
            if ( $form.data( 'pcp-bound' ) ) { return; }
            $form.data( 'pcp-bound', 1 );

            var $xp = $form.closest( '.pcp-experience' );
            var pageId = $xp.attr( 'data-page-id' );

            var state = { indications: [], effects: [] };
            $form.data( 'xpState', state );

            // ----- perspective toggle -----
            $form.find( 'input[name^="pcp-xp-persp-"]' ).on( 'change', function () {
                $form.attr( 'data-perspective', $( this ).val() );
                syncStopReasonVisibility();
            } );

            // ----- scale buttons (efficacy / burden) -----
            $form.find( '.pcp-xp-btnrow' ).on( 'click', '.pcp-xp-scale-btn', function () {
                var $b = $( this );
                var $row = $b.closest( '.pcp-xp-btnrow' );
                if ( $b.hasClass( 'pcp-active' ) ) {
                    $b.removeClass( 'pcp-active' );
                } else {
                    $row.find( '.pcp-xp-scale-btn' ).removeClass( 'pcp-active' );
                    $b.addClass( 'pcp-active' );
                }
            } );

            // ----- stop-reason conditional visibility -----
            function syncStopReasonVisibility() {
                var persp = parseInt( $form.attr( 'data-perspective' ), 10 ) || 1;
                var cur = parseInt( $form.find( 'input[name^="pcp-xp-current-"]:checked' ).val(), 10 ) || 0;
                var show = ( persp === 1 && cur === 2 );
                $form.find( '.pcp-xp-stopreason' ).attr( 'hidden', show ? null : 'hidden' );
            }
            $form.find( 'input[name^="pcp-xp-current-"]' ).on( 'change', syncStopReasonVisibility );

            // ----- generic inline picker (indications + effects) -----
            function openPicker( $field, lookupAction, lookupKey, onPick ) {
                var $addBtn = $field.find( '.pcp-xp-add' );
                if ( $field.find( '.pcp-xp-picker' ).length ) { return; }
                var $picker = $(
                    '<div class="pcp-xp-picker">' +
                      '<input type="text" class="pcp-xp-picker-input" placeholder="Search…" autocomplete="off">' +
                      '<div class="pcp-xp-picker-results"></div>' +
                      '<a href="#" class="pcp-xp-picker-cancel">cancel</a>' +
                    '</div>'
                );
                $addBtn.after( $picker ).hide();
                var $input = $picker.find( '.pcp-xp-picker-input' );
                var $results = $picker.find( '.pcp-xp-picker-results' );
                $input.trigger( 'focus' );
                function close() { $picker.remove(); $addBtn.show(); }
                $picker.find( '.pcp-xp-picker-cancel' ).on( 'click', function ( e ) { e.preventDefault(); close(); } );

                var timer = null;
                $input.on( 'input', function () {
                    clearTimeout( timer );
                    var q = $input.val();
                    timer = setTimeout( function () {
                        $.ajax( {
                            method: 'GET',
                            url: mw.util.wikiScript( 'api' ),
                            data: { action: lookupAction, q: q, format: 'json' },
                            dataType: 'json'
                        } ).done( function ( resp ) {
                            var d = resp[ lookupKey ];
                            var matches = ( d && d.matches ) || [];
                            var html = '';
                            matches.forEach( function ( m ) {
                                html += '<div class="pcp-xp-picker-result" data-slug="' + pcpXpEsc( m.slug ) +
                                    '" data-label="' + pcpXpEsc( m.name ) + '">' + pcpXpEsc( m.name ) + '</div>';
                            } );
                            var qt = q.trim();
                            if ( qt ) {
                                html += '<div class="pcp-xp-picker-result pcp-xp-picker-new" data-new="' +
                                    pcpXpEsc( qt ) + '">+ Create &quot;' + pcpXpEsc( qt ) + '&quot;</div>';
                            }
                            $results.html( html || '<div class="pcp-xp-picker-empty">Type to search…</div>' );
                        } );
                    }, 180 );
                } );
                $results.on( 'click', '.pcp-xp-picker-result', function () {
                    var $r = $( this );
                    var pick = $r.hasClass( 'pcp-xp-picker-new' )
                        ? { new_name: $r.attr( 'data-new' ) }
                        : { slug: $r.attr( 'data-slug' ), label: $r.attr( 'data-label' ) };
                    onPick( pick );
                    close();
                } );
            }

            var $indField = $form.find( '.pcp-xp-indication-field' );
            var $indChips = $indField.find( '.pcp-xp-indication-chips' );
            var $effField = $form.find( '.pcp-xp-effect-field' );
            var $effChips = $effField.find( '.pcp-xp-effect-chips' );

            // ----- chip builders (shared by the pickers AND by edit-mode prefill) -----
            function addIndicationChip( entry ) {
                if ( state.indications.indexOf( entry ) < 0 ) { state.indications.push( entry ); }
                var label = entry.label || entry.new_name || entry.ref || '?';
                var isNew = !!entry.new_name;
                var stars = '';
                for ( var s = 1; s <= 5; s++ ) {
                    stars += '<span class="pcp-xp-star" data-star="' + s + '">☆</span>';
                }
                var $chip = $(
                    '<div class="pcp-xp-indication-chip">' +
                      '<div class="pcp-xp-effect-chip-head">' +
                        '<span class="pcp-xp-chip-label">' + pcpXpEsc( label ) +
                        ( isNew ? ' <em>(new)</em>' : '' ) + '</span>' +
                        '<a href="#" class="pcp-xp-chip-x" aria-label="Remove">×</a>' +
                      '</div>' +
                      '<div class="pcp-xp-chip-rating-row">' +
                        '<span class="pcp-xp-chip-rowlabel">How well did it work?</span>' +
                        '<span class="pcp-xp-stars">' + stars + '</span>' +
                        '<button type="button" class="pcp-xp-rate-zero">Didn’t work</button>' +
                        '<button type="button" class="pcp-xp-rate-dk">Don’t know</button>' +
                      '</div>' +
                    '</div>'
                );
                var $stars = $chip.find( '.pcp-xp-star' );
                var $zero  = $chip.find( '.pcp-xp-rate-zero' );
                var $dk    = $chip.find( '.pcp-xp-rate-dk' );
                function paint( rating ) {
                    entry.rating = rating;
                    $zero.toggleClass( 'pcp-active', rating === 0 );
                    $dk.toggleClass( 'pcp-active', rating === -1 );
                    $stars.each( function () {
                        var sv = parseInt( $( this ).attr( 'data-star' ), 10 );
                        var filled = ( rating !== null && rating > 0 && sv <= rating );
                        $( this ).text( filled ? '★' : '☆' )
                                 .toggleClass( 'pcp-active', filled );
                    } );
                }
                $stars.on( 'click', function () {
                    var sv = parseInt( $( this ).attr( 'data-star' ), 10 );
                    paint( entry.rating === sv ? null : sv );
                } );
                $stars.on( 'mouseenter', function () {
                    var sv = parseInt( $( this ).attr( 'data-star' ), 10 );
                    $stars.each( function () {
                        var s2 = parseInt( $( this ).attr( 'data-star' ), 10 );
                        $( this ).text( s2 <= sv ? '★' : '☆' );
                    } );
                } );
                $chip.find( '.pcp-xp-stars' ).on( 'mouseleave', function () {
                    paint( entry.rating == null ? null : entry.rating );
                } );
                $zero.on( 'click', function () { paint( entry.rating === 0 ? null : 0 ); } );
                $dk.on( 'click', function () { paint( entry.rating === -1 ? null : -1 ); } );
                $chip.find( '.pcp-xp-chip-x' ).on( 'click', function ( e ) {
                    e.preventDefault();
                    var i = state.indications.indexOf( entry );
                    if ( i >= 0 ) { state.indications.splice( i, 1 ); }
                    $chip.remove();
                } );
                $indChips.append( $chip );
                paint( entry.rating == null ? null : entry.rating );
            }

            function addEffectChip( entry ) {
                if ( state.effects.indexOf( entry ) < 0 ) { state.effects.push( entry ); }
                var label = entry.label || entry.new_name || entry.slug || '?';
                var isNew = !!entry.new_name;
                var freqs = [
                    { v: 0, l: '0' }, { v: 5, l: '<10%' }, { v: 20, l: '~20%' },
                    { v: 33, l: '~33%' }, { v: 50, l: '~50%' }, { v: 66, l: '~66%' },
                    { v: 80, l: '~80%' }, { v: 95, l: '90+%' }, { v: -1, l: 'Don’t know' }
                ];
                var fbtns = '';
                freqs.forEach( function ( f ) {
                    fbtns += '<button type="button" class="pcp-xp-chip-fbtn" data-f="' + f.v + '">' +
                             pcpXpEsc( f.l ) + '</button>';
                } );
                var vbtns = '';
                for ( var v = -3; v <= 3; v++ ) {
                    var lbl = v > 0 ? ( '+' + v ) : ( '' + v );
                    vbtns += '<button type="button" class="pcp-xp-chip-vbtn" data-v="' + v + '">' + lbl + '</button>';
                }
                var $chip = $(
                    '<div class="pcp-xp-effect-chip">' +
                      '<div class="pcp-xp-effect-chip-head">' +
                        '<span class="pcp-xp-chip-label">' + pcpXpEsc( label ) +
                        ( isNew ? ' <em>(new)</em>' : '' ) + '</span>' +
                        '<a href="#" class="pcp-xp-chip-x" aria-label="Remove">×</a>' +
                      '</div>' +
                      '<div class="pcp-xp-chip-freq-row">' +
                        '<span class="pcp-xp-chip-rowlabel">How often do you see it?</span>' +
                        fbtns +
                      '</div>' +
                      '<div class="pcp-xp-chip-val-row">' +
                        '<span class="pcp-xp-chip-rowlabel pcp-xp-chip-rowlabel-personal">How was it?</span>' +
                        '<span class="pcp-xp-chip-rowlabel pcp-xp-chip-rowlabel-clinical">How is it?</span>' +
                        vbtns +
                      '</div>' +
                    '</div>'
                );
                $chip.find( '.pcp-xp-chip-fbtn' ).on( 'click', function () {
                    var $fb = $( this );
                    var fv = parseInt( $fb.attr( 'data-f' ), 10 );
                    if ( $fb.hasClass( 'pcp-active' ) ) {
                        $fb.removeClass( 'pcp-active' ); entry.frequency = null;
                    } else {
                        $chip.find( '.pcp-xp-chip-fbtn' ).removeClass( 'pcp-active' );
                        $fb.addClass( 'pcp-active' ); entry.frequency = fv;
                    }
                } );
                $chip.find( '.pcp-xp-chip-vbtn' ).on( 'click', function () {
                    var $vb = $( this );
                    var val = parseInt( $vb.attr( 'data-v' ), 10 );
                    if ( $vb.hasClass( 'pcp-active' ) ) {
                        $vb.removeClass( 'pcp-active' ); entry.valence = null;
                    } else {
                        $chip.find( '.pcp-xp-chip-vbtn' ).removeClass( 'pcp-active' );
                        $vb.addClass( 'pcp-active' ); entry.valence = val;
                    }
                } );
                $chip.find( '.pcp-xp-chip-x' ).on( 'click', function ( e ) {
                    e.preventDefault();
                    var i = state.effects.indexOf( entry );
                    if ( i >= 0 ) { state.effects.splice( i, 1 ); }
                    $chip.remove();
                } );
                $effChips.append( $chip );
                // Pre-activate buttons from the entry (edit-mode prefill).
                if ( entry.frequency != null ) {
                    $chip.find( '.pcp-xp-chip-fbtn[data-f="' + entry.frequency + '"]' ).addClass( 'pcp-active' );
                }
                if ( entry.valence != null ) {
                    $chip.find( '.pcp-xp-chip-vbtn[data-v="' + entry.valence + '"]' ).addClass( 'pcp-active' );
                }
            }

            // ----- pickers feed the chip builders -----
            $indField.find( '.pcp-xp-add-indication' ).on( 'click', function () {
                openPicker( $indField, 'pharmacopediaindicationslookup', 'pharmacopediaindicationslookup', function ( pick ) {
                    var entry = pick.new_name
                        ? { new_name: pick.new_name, label: pick.new_name, rating: null }
                        : { ref: pick.slug, label: pick.label, rating: null };
                    addIndicationChip( entry );
                } );
            } );
            $effField.find( '.pcp-xp-add-effect' ).on( 'click', function () {
                openPicker( $effField, 'pharmacopediaeffectslookup', 'pharmacopediaeffectslookup', function ( pick ) {
                    var entry = pick.new_name
                        ? { new_name: pick.new_name, label: pick.new_name, valence: null, frequency: null }
                        : { slug: pick.slug, label: pick.label, valence: null, frequency: null };
                    addEffectChip( entry );
                } );
            } );

            // ----- submit -----
            $form.find( '.pcp-xp-submit' ).on( 'click', function () {
                var $btn = $( this );
                var $status = $form.find( '.pcp-xp-form-status' );
                var perspective = parseInt( $form.attr( 'data-perspective' ), 10 ) || 1;
                var current = $form.find( 'input[name^="pcp-xp-current-"]:checked' ).val();
                var efficacy = $form.find( '.pcp-xp-efficacy-slider' ).val();
                var burden = $form.find( '.pcp-xp-burden-slider' ).val();

                if ( !current ) { $status.text( 'Please answer the "currently taking it" question.' ); return; }
                if ( efficacy === undefined || efficacy === '' ) { $status.text( 'Please rate effectiveness.' ); return; }
                if ( burden === undefined || burden === '' ) { $status.text( 'Please rate side-effect burden.' ); return; }

                var payload = {
                    indications: state.indications,
                    effects: state.effects,
                    anecdote: $form.find( '.pcp-xp-anecdote' ).val() || ''
                };
                $status.text( 'Submitting…' );
                $btn.prop( 'disabled', true );
                api.postWithToken( 'csrf', {
                    action: 'pharmacopediaexperiencesubmit',
                    page_id: pageId,
                    perspective: perspective,
                    current: current,
                    duration_value: $form.find( '.pcp-xp-duration-value' ).val() || '',
                    duration_unit: $form.find( '.pcp-xp-duration-unit' ).val() || '',
                    dose_mg: $form.find( '.pcp-xp-dose-mg' ).val() || '',
                    patient_count: $form.find( '.pcp-xp-patient-count' ).val() || '',
                    efficacy: efficacy,
                    burden: burden,
                    route: $form.find( '.pcp-xp-route' ).val() || '',
                    schedule: $form.find( '.pcp-xp-schedule' ).val() || '',
                    stop_reasons: ( function () {
                        var arr = [];
                        $form.find( '.pcp-xp-sr-row' ).each( function () {
                            var $r = $( this );
                            var $cb = $r.find( '.pcp-xp-sr-toggle' );
                            if ( !$cb.is( ':checked' ) ) return;
                            var entry = { code: $cb.val() };
                            var $sl = $r.find( '.pcp-xp-sr-slider' );
                            if ( $sl.length ) {
                                var sv = parseInt( $sl.val(), 10 );
                                if ( !isNaN( sv ) ) entry.severity = sv;
                            }
                            arr.push( entry );
                        } );
                        return JSON.stringify( arr );
                    } )(),
                    patient_count_max: $form.find( '.pcp-xp-patient-count-max' ).val() || '',
                    payload: JSON.stringify( payload ),
                    format: 'json'
                } ).done( function ( resp ) {
                    var d = resp && resp.pharmacopediaexperiencesubmit;
                    if ( d && d.ok ) {
                        $form.replaceWith(
                            '<div class="pcp-xp-myreport"><div class="pcp-xp-myreport-head">' +
                            '<strong>Thanks, your experience was submitted.</strong> ' +
                            '<span class="pcp-xp-status pcp-xp-status-pending">⏳ awaiting review</span>' +
                            '</div><div class="pcp-xp-myreport-body">' +
                            'A moderator will review it shortly. It becomes part of the public ' +
                            'aggregate once approved.</div></div>'
                        );
                    } else {
                        $status.text( 'Submission failed.' );
                        $btn.prop( 'disabled', false );
                    }
                } ).fail( function ( code, data ) {
                    var msg = 'Submission failed: ' + ( code || 'unknown' );
                    if ( data && data.error && data.error.info ) { msg = data.error.info; }
                    $status.text( msg );
                    $btn.prop( 'disabled', false );
                } );
            } );

            // ----- edit-mode prefill -----
            function applyPrefill( pf ) {
                if ( pf.perspective ) {
                    $form.attr( 'data-perspective', pf.perspective );
                    $form.find( 'input[name^="pcp-xp-persp-"][value="' + pf.perspective + '"]' ).prop( 'checked', true );
                }
                if ( pf.current ) {
                    $form.find( 'input[name^="pcp-xp-current-"][value="' + pf.current + '"]' ).prop( 'checked', true );
                }
                if ( pf.duration_value !== '' && pf.duration_value != null ) {
                    $form.find( '.pcp-xp-duration-value' ).val( pf.duration_value );
                }
                if ( pf.duration_unit ) {
                    $form.find( '.pcp-xp-duration-unit' ).val( pf.duration_unit );
                }
                if ( pf.dose_mg !== '' && pf.dose_mg != null ) {
                    $form.find( '.pcp-xp-dose-mg' ).val( pf.dose_mg );
                }
                if ( pf.patient_count !== '' && pf.patient_count != null ) {
                    $form.find( '.pcp-xp-patient-count' ).val( pf.patient_count );
                }
                if ( pf.efficacy != null ) {
                    var $effSl = $form.find( '.pcp-xp-efficacy-slider' );
                    $effSl.val( pf.efficacy );
                    $effSl.next( 'output' ).val( pf.efficacy );
                }
                if ( pf.burden != null ) {
                    var $burSl = $form.find( '.pcp-xp-burden-slider' );
                    $burSl.val( pf.burden );
                    $burSl.next( 'output' ).val( pf.burden );
                }
                if ( pf.patient_count_max !== '' && pf.patient_count_max != null ) {
                    $form.find( '.pcp-xp-patient-count-max' ).val( pf.patient_count_max );
                }
                if ( pf.stop_reasons != null && pf.stop_reasons !== '' ) {
                    try {
                        var srArr = JSON.parse( pf.stop_reasons );
                        if ( Array.isArray( srArr ) ) {
                            srArr.forEach( function ( e ) {
                                if ( !e || !e.code ) return;
                                var $r = $form.find( '.pcp-xp-sr-row[data-code="' + e.code + '"]' );
                                if ( !$r.length ) return;
                                $r.find( '.pcp-xp-sr-toggle' ).prop( 'checked', true );
                                $r.find( '.pcp-xp-sr-sev' ).attr( 'hidden', null );
                                if ( e.severity != null ) {
                                    var $sl = $r.find( '.pcp-xp-sr-slider' );
                                    $sl.val( e.severity );
                                    $sl.next( 'output' ).val( e.severity );
                                }
                            } );
                        }
                    } catch ( err ) {}
                }
                if ( false ) {
                    $form.find( 'input[name^="pcp-xp-stop-"][value="' + pf.stop_reason + '"]' ).prop( 'checked', true );
                }
                ( pf.indications || [] ).forEach( function ( e ) { addIndicationChip( e ); } );
                ( pf.effects || [] ).forEach( function ( e ) { addEffectChip( e ); } );
                if ( pf.anecdote ) { $form.find( '.pcp-xp-anecdote' ).val( pf.anecdote ); }
            }
            var prefillRaw = $form.attr( 'data-prefill' );
            if ( prefillRaw ) {
                try { applyPrefill( JSON.parse( prefillRaw ) ); } catch ( e ) {}
            }

            syncStopReasonVisibility();
        }
        $( '.pcp-experience .pcp-xp-form' ).each( function () { pcpXpBindForm( $( this ) ); } );

        // Edit button -> reveal the pre-filled edit form for that report.
        $( document ).on( 'click', '.pcp-xp-edit-btn', function ( e ) {
            e.preventDefault();
            var $report = $( this ).closest( '.pcp-xp-myreport' );
            var $mount = $report.nextAll( '.pcp-xp-edit-mount' ).first();
            if ( !$mount.length ) { return; }
            $report.hide();
            $mount.removeAttr( 'hidden' );
        } );

        // ---------------- Relevant Literature ----------------
        var LIT_MAX_BYTES = 23289856;     // 22.22 MB
        var LIT_WARN_BYTES = 10485760;    // 10 MB soft warning

        function litSetStatus( $form, msg, type ) {
            var $s = $form.find( '.pcp-lit-status' );
            $s.text( msg || '' );
            $s.removeClass( 'pcp-lit-status-ok pcp-lit-status-err' );
            if ( type === 'ok' )  { $s.addClass( 'pcp-lit-status-ok' ); }
            if ( type === 'err' ) { $s.addClass( 'pcp-lit-status-err' ); }
        }

        // File-picker info + size guardrails
        $( document ).on( 'change', '.pcp-lit-file', function () {
            var $form = $( this ).closest( '.pcp-lit-form' );
            var $info = $form.find( '.pcp-lit-fileinfo' );
            var file = this.files && this.files[0];
            if ( !file ) { $info.text( '' ); return; }
            if ( file.size > LIT_MAX_BYTES ) {
                $info.text( 'File too large (max 22.22 MB).' );
                this.value = '';
                return;
            }
            var kb = Math.round( file.size / 1024 );
            $info.text( file.name + ' (' + ( kb > 1024 ? ( ( kb / 1024 ).toFixed( 1 ) + ' MB' ) : ( kb + ' KB' ) ) + ')' );
            if ( file.size > LIT_WARN_BYTES ) {
                if ( !window.confirm( 'That file is larger than 10 MB. Are you sure?' ) ) {
                    this.value = '';
                    $info.text( '' );
                }
            }
        } );

        $( document ).on( 'click', '.pcp-lit-submit', function ( e ) {
            e.preventDefault();
            var $btn = $( this );
            var $form = $btn.closest( '.pcp-lit-form' );
            var $wrap = $btn.closest( '.pcp-literature' );
            var pageId = parseInt( $wrap.attr( 'data-page-id' ), 10 );
            if ( !pageId ) { return; }

            var title   = $.trim( $form.find( '.pcp-lit-title' ).val() );
            var authors = $.trim( $form.find( '.pcp-lit-authors' ).val() );
            var etAl    = $form.find( '.pcp-lit-etal-cb' ).is( ':checked' );
            var year    = $.trim( $form.find( '.pcp-lit-year' ).val() );
            var doi     = $.trim( $form.find( '.pcp-lit-doi' ).val() );
            var pmid    = $.trim( $form.find( '.pcp-lit-pmid' ).val() );
            var url     = $.trim( $form.find( '.pcp-lit-url' ).val() );
            var $file   = $form.find( '.pcp-lit-file' );
            var file    = ( $file.length && $file[0].files ) ? $file[0].files[0] : null;

            if ( !title ) { litSetStatus( $form, 'Title is required.', 'err' ); return; }
            if ( !url && !file ) {
                litSetStatus( $form, 'Provide a URL, a PDF upload, or both.', 'err' );
                return;
            }

            litSetStatus( $form, 'Submitting…', '' );
            $btn.prop( 'disabled', true );

            var fd = new FormData();
            fd.append( 'action', 'pharmacopedialiteraturesubmit' );
            fd.append( 'format', 'json' );
            fd.append( 'page_id', pageId );
            fd.append( 'title', title );
            if ( authors ) { fd.append( 'authors', authors ); }
            if ( etAl )    { fd.append( 'et_al', 1 ); }
            if ( year )    { fd.append( 'year', year ); }
            if ( doi )     { fd.append( 'doi', doi ); }
            if ( pmid )    { fd.append( 'pmid', pmid ); }
            if ( url )     { fd.append( 'url', url ); }
            if ( file )    { fd.append( 'file', file ); }

            api.getToken( 'csrf' ).then( function ( token ) {
                fd.append( 'token', token );
                return $.ajax( {
                    url: mw.util.wikiScript( 'api' ),
                    method: 'POST',
                    data: fd,
                    contentType: false,
                    processData: false
                } );
            } ).then( function ( resp ) {
                if ( resp && resp.error ) {
                    litSetStatus( $form, resp.error.info || 'Submission failed.', 'err' );
                } else {
                    litSetStatus( $form, 'Submitted! It will appear once an admin approves it.', 'ok' );
                    // Reset
                    $form.find( 'input[type=text], input[type=url], input[type=number]' ).val( '' );
                    $form.find( 'input[type=checkbox]' ).prop( 'checked', false );
                    if ( $file.length ) { $file.val( '' ); $form.find( '.pcp-lit-fileinfo' ).text( '' ); }
                    setTimeout( function () { location.reload(); }, 800 );
                }
            }, function ( jq, textStatus, err ) {
                var msg = 'Submission failed.';
                try {
                    var j = jq && jq.responseJSON;
                    if ( j && j.error && j.error.info ) { msg = j.error.info; }
                } catch ( e ) {}
                litSetStatus( $form, msg, 'err' );
            } ).always( function () {
                $btn.prop( 'disabled', false );
            } );
        } );

        // Delete own pending entry
        $( document ).on( 'click', '.pcp-lit-delete-own', function ( e ) {
            e.preventDefault();
            var id = parseInt( $( this ).attr( 'data-id' ), 10 );
            if ( !id ) { return; }
            if ( !window.confirm( 'Delete this pending submission?' ) ) { return; }
            api.postWithToken( 'csrf', {
                action: 'pharmacopedialiteraturedelete',
                format: 'json',
                id: id
            } ).then( function () {
                location.reload();
            }, function ( code, data ) {
                var info = ( data && data.error && data.error.info ) || 'Delete failed.';
                mw.notify( info, { type: 'error' } );
            } );
        } );

    
/* ===== Special:MyProfile visibility toggle ===== */
$( '.pcp-vis-toggle' ).on( 'click', function () {
    var $btn = $( this );
    var v = parseInt( $btn.attr( 'data-vis' ), 10 ) || 0;
    v = ( v + 1 ) % 4;
    $btn.attr( 'data-vis', v );
    var icons = [ '🔒', '👁', '🆔', '🎭' ];
    var classes = [ 'pcp-vis-private', 'pcp-vis-default', 'pcp-vis-username', 'pcp-vis-anonymous' ];
    $btn.text( icons[ v ] );
    $btn.removeClass( 'pcp-vis-private pcp-vis-default pcp-vis-username pcp-vis-anonymous' );
    $btn.addClass( classes[ v ] );
    var titles = [ 'Private, sysop only', 'Public, your default attribution', 'Public, show real username', 'Public, anonymous' ];
    $btn.attr( 'title', titles[ v ] );
    // Update the hidden form input next to it
    $btn.siblings( '.pcp-vis-hidden' ).val( v );
} );
/* ===== End MyProfile toggle ===== */

    
        /* ===== Diagnosis autocomplete (with Problems repo cross-reference) ===== */
        var dxDebounce = null;
        function attachDxAutocomplete( $input ) {
            var $row = $input.closest( '.pcp-dx-row' );

            function buildDropdown( v, dxMatches, pbResp ) {
                $row.find( '.pcp-dx-suggest' ).remove();
                var pbMatches = ( pbResp && pbResp.matches ) || [];
                var hasAny = ( dxMatches && dxMatches.length ) || pbMatches.length;
                var $ul = $( '<div class="pcp-dx-suggest"></div>' );

                if ( hasAny ) {
                    // Dx-abbrev section
                    if ( dxMatches && dxMatches.length ) {
                        $ul.append( $( '<div class="pcp-dx-suggest-header"></div>' )
                            .text( 'Diagnoses (DSM / ICD / somatic)' ) );
                        dxMatches.forEach( function ( m ) {
                            var $i = $( '<div class="pcp-dx-suggest-item"></div>' )
                                .text( m.canonical )
                                .append( $( '<span class="meta"></span>' ).text(
                                    m.system + ( m.code ? ' · ' + m.code : '' )
                                ) );
                            $i.on( 'mousedown', function ( e ) {
                                e.preventDefault();
                                $input.val( m.canonical );
                                $row.find( '.pcp-dx-code-input' ).val( m.code || '' );
                                $row.find( '.pcp-dx-sys-input' ).val( m.system );
                                $row.find( '.pcp-dx-suggest' ).remove();
                            } );
                            $ul.append( $i );
                        } );
                    }
                    // Problems section
                    if ( pbMatches.length ) {
                        $ul.append( $( '<div class="pcp-dx-suggest-header"></div>' )
                            .text( 'Also in Problems repository' ) );
                        pbMatches.forEach( function ( p ) {
                            var $i = $( '<div class="pcp-dx-suggest-item pcp-dx-suggest-problem"></div>' )
                                .text( p.name )
                                .append( $( '<span class="meta"></span>' ).text(
                                    p.category || 'uncategorized'
                                ) );
                            $i.on( 'mousedown', function ( e ) {
                                e.preventDefault();
                                $input.val( p.name );
                                $row.find( '.pcp-dx-suggest' ).remove();
                            } );
                            $ul.append( $i );
                        } );
                    }
                }
                // "Suggest as new Problem" fallback when no exact slug match
                if ( pbResp && !pbResp.exact_slug_match && v.length >= 2 ) {
                    var sgUrl = mw.util.getUrl( 'Special:SuggestProblem' ) +
                        '?prefill=' + encodeURIComponent( v );
                    $ul.append( $( '<a class="pcp-dx-suggest-add-problem"></a>' )
                        .attr( 'href', sgUrl )
                        .attr( 'target', '_blank' )
                        .text( '+ Suggest "' + v + '" as a new Problem' ) );
                }

                if ( !$ul.children().length ) return;
                var pos = $input.position();
                $ul.css( {
                    top: pos.top + $input.outerHeight() + 'px',
                    left: pos.left + 'px',
                    width: $input.outerWidth() + 'px'
                } );
                $row.css( 'position', 'relative' ).append( $ul );
            }

            $input.on( 'input', function () {
                var v = $input.val();
                if ( dxDebounce ) clearTimeout( dxDebounce );
                $row.find( '.pcp-dx-suggest' ).remove();
                if ( !v || v.length < 1 ) return;
                dxDebounce = setTimeout( function () {
                    $.when(
                        api.get( { action: 'pharmacopediadxsearch', q: v, format: 'json' } ),
                        api.get( { action: 'pharmacopediaproblemsearch', q: v, format: 'json' } )
                    ).done( function ( dxResp, pbResp ) {
                        // mw.Api inside $.when: each arg is [data, textStatus, jqXHR]
                        var dxData = dxResp && dxResp[0] && dxResp[0].pharmacopediadxsearch;
                        var pbData = pbResp && pbResp[0] && pbResp[0].pharmacopediaproblemsearch;
                        buildDropdown( v, dxData ? dxData.matches : [], pbData );
                    } );
                }, 150 );
            } );
            $input.on( 'blur', function () {
                setTimeout( function () { $row.find( '.pcp-dx-suggest' ).remove(); }, 200 );
            } );
        }
        $( '.pcp-dx-desc-input' ).each( function () { attachDxAutocomplete( $( this ) ); } );
        /* ===== End diagnosis autocomplete ===== */

    
        /* ===== Med name autocomplete (Category:Medicines) ===== */
        var medDebounce = null;
        function attachMedAutocomplete( $input ) {
            var $row = $input.closest( '.pcp-med-row' );
            $input.on( 'input', function () {
                var v = $input.val();
                if ( medDebounce ) clearTimeout( medDebounce );
                $row.find( '.pcp-med-suggest' ).remove();
                if ( !v || v.length < 1 ) return;
                medDebounce = setTimeout( function () {
                    api.get( { action: 'pharmacopediamedsearch', q: v, format: 'json' } )
                        .done( function ( resp ) {
                            var d = resp && resp.pharmacopediamedsearch;
                            if ( !d || !d.matches || d.matches.length === 0 ) return;
                            var $ul = $( '<div class="pcp-med-suggest"></div>' );
                            d.matches.forEach( function ( m ) {
                                var $i = $( '<div class="pcp-med-suggest-item"></div>' ).text( m.title );
                                $i.on( 'mousedown', function ( e ) {
                                    e.preventDefault();
                                    $input.val( m.title );
                                    $row.find( '.pcp-med-pageid' ).val( m.page_id );
                                    $row.find( '.pcp-med-suggest' ).remove();
                                } );
                                $ul.append( $i );
                            } );
                            var pos = $input.position();
                            $ul.css( {
                                top: pos.top + $input.outerHeight() + 'px',
                                left: pos.left + 'px',
                                width: $input.outerWidth() + 'px'
                            } );
                            $row.css( 'position', 'relative' ).append( $ul );
                        } );
                }, 150 );
            } );
            $input.on( 'blur', function () {
                setTimeout( function () { $row.find( '.pcp-med-suggest' ).remove(); }, 200 );
            } );
        }
        $( '.pcp-med-name-input' ).each( function () { attachMedAutocomplete( $( this ) ); } );
        /* ===== End med autocomplete ===== */

    
        /* ===== PID-5-BF sliders + Not sure ===== */
        $( document ).on( 'input change', '.pcp-pid-slider', function () {
            var $item = $( this ).closest( '.pcp-pid-item' );
            var v = parseFloat( this.value );
            if ( isNaN( v ) ) v = 1.5;
            $item.find( '.pcp-pid-out' ).text( v.toFixed( 2 ) );
        } );
        $( document ).on( 'change', '.pcp-pid-unsure input[type=checkbox]', function () {
            var $cb = $( this );
            var $item = $cb.closest( '.pcp-pid-item' );
            var $slider = $item.find( '.pcp-pid-slider' );
            if ( $cb.prop( 'checked' ) ) {
                $slider.prop( 'disabled', true );
                $item.addClass( 'pcp-unsure' );
            } else {
                $slider.prop( 'disabled', false );
                $item.removeClass( 'pcp-unsure' );
            }
        } );
        /* ===== End PID-5-BF sliders ===== */

        /* ===== BFI-10 personality test scoring ===== */
        // Compute fires on either the explicit button (legacy) or any
        // BFI-10 slider change. Both bindings call the same logic.
        function pcpBfi10Compute() {
            var $any = $( '.pcp-bfi10-item' ).first();
            if ( !$any.length ) return;
            return _pcpBfi10ComputeInternal.call( $any.closest( '.pcp-bfi10' ).find( '.pcp-bfi10-compute' )[0] || $any[0] );
        }
        $( document ).on( 'input change', 'input.pcp-bfi10-slider', function () {
            var $wrap = $( this ).closest( '.pcp-bfi10' );
            _pcpBfi10ComputeInternal.call( $wrap.find( '.pcp-bfi10-compute' )[0] || $wrap[0] );
        } );
        function _pcpBfi10ComputeInternal() {
            var $self = $( this );
            var $wrap = $self.hasClass( 'pcp-bfi10' ) ? $self : $self.closest( '.pcp-bfi10' );
            if ( !$wrap.length ) $wrap = $( '.pcp-bfi10' ).first();
            var $status = $wrap.find( '.pcp-bfi10-status' );
            var sums = { O: 0, C: 0, E: 0, A: 0, N: 0 };
            var counts = { O: 0, C: 0, E: 0, A: 0, N: 0 };
            var missing = 0;
            $wrap.find( '.pcp-bfi10-item' ).each( function () {
                var $it = $( this );
                var dim = $it.attr( 'data-dim' );
                var reverse = $it.attr( 'data-reverse' ) === '1';
                var $slider = $it.find( 'input[type=range].pcp-bfi10-slider' );
                if ( $slider.length === 0 ) { return; }
                var v = parseInt( $slider.val(), 10 );
                if ( isNaN( v ) ) { return; }
                if ( reverse ) { v = 100 - v; }
                sums[ dim ] += v;
                counts[ dim ] += 1;
            } );
            // No "missing" concept with sliders, every slider has a default value
            var results = {};
            [ 'O','C','E','A','N' ].forEach( function ( d ) {
                results[ d ] = counts[ d ] > 0 ? Math.round( sums[ d ] / counts[ d ] ) : 50;
            } );
            // Fill the sliders
            $( '.pcp-prof-field[data-key="ocean:O"] input[type=range]' ).val( results.O ).trigger( 'input' );
            $( '.pcp-prof-field[data-key="ocean:C"] input[type=range]' ).val( results.C ).trigger( 'input' );
            $( '.pcp-prof-field[data-key="ocean:E"] input[type=range]' ).val( results.E ).trigger( 'input' );
            $( '.pcp-prof-field[data-key="ocean:A"] input[type=range]' ).val( results.A ).trigger( 'input' );
            $( '.pcp-prof-field[data-key="ocean:N"] input[type=range]' ).val( results.N ).trigger( 'input' );
            // Force the output element next to each slider to update
            [ 'O','C','E','A','N' ].forEach( function ( d ) {
                var $r = $( '.pcp-prof-field[data-key="ocean:' + d + '"] input[type=range]' );
                $r.next( 'output' ).val( results[ d ] );
            } );
            $status.html( 'Scores computed: ' +
                'O=' + results.O + ', C=' + results.C +
                ', E=' + results.E + ', A=' + results.A +
                ', N=' + results.N + '. (auto-saved)' );
        }
        /* ===== End BFI-10 ===== */

        /* ===== Units toggle + conversion (height / weight) ===== */
        function pcpSyncUnitsWidget( widgetEl ) {
            var $w = $( widgetEl );
            var kind  = $w.attr( 'data-kind' );
            var units = $w.attr( 'data-units' );
            var $hidden = $w.find( 'input.pcp-units-hidden' );
            if ( kind === 'height' ) {
                if ( units === 'us' ) {
                    var ft  = parseFloat( $w.find( '.pcp-units-ft' ).val() );
                    var ins = parseFloat( $w.find( '.pcp-units-in' ).val() );
                    if ( isNaN( ft ) ) ft = 0;
                    if ( isNaN( ins ) ) ins = 0;
                    if ( ft === 0 && ins === 0 ) {
                        $hidden.val( '' );
                        $w.find( '.pcp-units-cm' ).val( '' );
                    } else {
                        var cm = ( ft * 12 + ins ) * 2.54;
                        $hidden.val( cm.toFixed( 2 ) );
                        $w.find( '.pcp-units-cm' ).val( cm.toFixed( 2 ) );
                    }
                } else {
                    var cmV = parseFloat( $w.find( '.pcp-units-cm' ).val() );
                    if ( isNaN( cmV ) ) {
                        $hidden.val( '' );
                        $w.find( '.pcp-units-ft' ).val( '' );
                        $w.find( '.pcp-units-in' ).val( '' );
                    } else {
                        $hidden.val( cmV.toFixed( 2 ) );
                        var totalIn = cmV / 2.54;
                        var fOut = Math.floor( totalIn / 12 );
                        var iOut = ( totalIn - fOut * 12 );
                        $w.find( '.pcp-units-ft' ).val( fOut );
                        $w.find( '.pcp-units-in' ).val( iOut.toFixed( 2 ) );
                    }
                }
            } else if ( kind === 'weight' ) {
                if ( units === 'us' ) {
                    var lb = parseFloat( $w.find( '.pcp-units-lb' ).val() );
                    if ( isNaN( lb ) ) {
                        $hidden.val( '' );
                        $w.find( '.pcp-units-kg' ).val( '' );
                    } else {
                        var kg = lb / 2.20462;
                        $hidden.val( kg.toFixed( 2 ) );
                        $w.find( '.pcp-units-kg' ).val( kg.toFixed( 2 ) );
                    }
                } else {
                    var kgV = parseFloat( $w.find( '.pcp-units-kg' ).val() );
                    if ( isNaN( kgV ) ) {
                        $hidden.val( '' );
                        $w.find( '.pcp-units-lb' ).val( '' );
                    } else {
                        $hidden.val( kgV.toFixed( 2 ) );
                        $w.find( '.pcp-units-lb' ).val( ( kgV * 2.20462 ).toFixed( 2 ) );
                    }
                }
            }
            $hidden.trigger( 'change' );
        }

        // Sync on any visible-input change inside a units widget
        $( document ).on( 'input change', '.pcp-units-widget input:not(.pcp-units-hidden)', function () {
            pcpSyncUnitsWidget( $( this ).closest( '.pcp-units-widget' )[ 0 ] );
        } );

        // Switching the units preference toggles all widgets in sync.
        // We first compute current values from the visible (active) side into the
        // hidden field, then swap which side is visible, then re-populate the
        // newly-visible side from the canonical hidden value.
        $( document ).on( 'change', '.pcp-units-select', function () {
            var newUnits = $( this ).val() === 'us' ? 'us' : 'metric';
            $( '.pcp-units-widget' ).each( function () {
                pcpSyncUnitsWidget( this );           // capture current input → hidden
                $( this ).attr( 'data-units', newUnits );
                pcpSyncUnitsWidget( this );           // re-populate newly visible side from hidden
            } );
        } );
        /* ===== End units toggle ===== */

        /* ===== Generic chip-picker widget =====
         * Markup:
         *   <div class="pcp-chip-picker"
         *        data-source="countries|languages|genders|pronouns|..."
         *        data-multi="0|1"
         *        data-allow-custom="0|1"
         *        data-allow-primary="0|1"
         *        data-browser-fill="country|language|0">
         *     <div class="pcp-chip-list"></div>
         *     <input type="text" class="pcp-chip-input" placeholder="Type to add...">
         *     <div class="pcp-chip-suggest"></div>
         *     <input type="hidden" class="pcp-chip-value" name="..." value='[...]'>
         *   </div>
         *
         * Stored hidden value: JSON array.
         *   - For dataset-backed pickers: [{code, label, primary?, custom?}, ...]
         *   - Single-value pickers store a 1-element array.
         *   - Empty selection stores empty string '' (so save handler deletes the field).
         */
        function pcpChipLookup( datasetKey, code ) {
            var DS = ( window.PCP_DATASETS || {} )[ datasetKey ] || [];
            for ( var i = 0; i < DS.length; i++ ) {
                if ( DS[ i ].code === code ) return DS[ i ];
            }
            return null;
        }
        function pcpChipSearch( datasetKey, query ) {
            var DS = ( window.PCP_DATASETS || {} )[ datasetKey ] || [];
            var q = String( query || '' ).toLowerCase().trim();
            if ( q === '' ) return DS.slice( 0, 20 );
            var hits = [];
            for ( var i = 0; i < DS.length; i++ ) {
                var d = DS[ i ];
                var hay = ( d.code + ' ' + d.label + ' ' + ( d.native || '' ) + ' ' + ( d.alts || [] ).join( ' ' ) ).toLowerCase();
                if ( hay.indexOf( q ) !== -1 ) hits.push( d );
                if ( hits.length >= 20 ) break;
            }
            return hits;
        }
        function pcpChipRenderList( $picker ) {
            var $list = $picker.find( '.pcp-chip-list' );
            var $hidden = $picker.find( '.pcp-chip-value' );
            var multi = $picker.attr( 'data-multi' ) === '1';
            var allowPrim = $picker.attr( 'data-allow-primary' ) === '1';
            var dataset = $picker.attr( 'data-source' );
            var raw = $hidden.val();
            var arr = [];
            if ( raw && raw !== '' ) {
                try { arr = JSON.parse( raw ) || []; } catch ( e ) { arr = []; }
            }
            $list.empty();
            arr.forEach( function ( item, idx ) {
                var label = item.label || item.code || '';
                if ( !label && dataset ) {
                    var lk = pcpChipLookup( dataset, item.code );
                    if ( lk ) label = lk.label;
                }
                var nativeBit = '';
                if ( dataset && item.code ) {
                    var lk2 = pcpChipLookup( dataset, item.code );
                    if ( lk2 && lk2.native && lk2.native !== lk2.label ) nativeBit = ' · ' + lk2.native;
                }
                var customCls = item.custom ? ' pcp-chip-custom' : '';
                var primCls   = item.primary ? ' pcp-chip-primary' : '';
                var primMark  = '';
                if ( allowPrim ) {
                    primMark = '<button type="button" class="pcp-chip-prim" title="' +
                        ( item.primary ? 'Primary' : 'Mark as primary' ) + '" data-idx="' + idx + '">' +
                        ( item.primary ? '★' : '☆' ) + '</button>';
                }
                var $chip = $(
                    '<span class="pcp-chip' + customCls + primCls + '" data-idx="' + idx + '">' +
                    primMark +
                    '<span class="pcp-chip-label">' + $( '<div>' ).text( label ).html() +
                    '<small>' + $( '<div>' ).text( nativeBit ).html() + '</small></span>' +
                    '<button type="button" class="pcp-chip-remove" data-idx="' + idx + '" title="Remove">×</button>' +
                    '</span>'
                );
                $list.append( $chip );
            } );
            // If single-value and we have one, hide the input
            var $input = $picker.find( '.pcp-chip-input' );
            if ( !multi && arr.length >= 1 ) {
                $input.hide();
            } else {
                $input.show();
            }
        }
        function pcpChipWriteHidden( $picker, arr ) {
            var $hidden = $picker.find( '.pcp-chip-value' );
            if ( !arr || arr.length === 0 ) {
                $hidden.val( '' );
            } else {
                $hidden.val( JSON.stringify( arr ) );
            }
            $hidden.trigger( 'change' );
        }
        function pcpChipAdd( $picker, item ) {
            var $hidden = $picker.find( '.pcp-chip-value' );
            var multi = $picker.attr( 'data-multi' ) === '1';
            var arr = [];
            try { arr = JSON.parse( $hidden.val() || '[]' ); } catch ( e ) { arr = []; }
            // Dedup by code (or by label for custom)
            var key = item.code || item.label;
            for ( var i = 0; i < arr.length; i++ ) {
                if ( ( arr[ i ].code || arr[ i ].label ) === key ) return false;
            }
            if ( !multi ) arr = [];
            // If no primary yet and allow-primary, mark first as primary
            if ( $picker.attr( 'data-allow-primary' ) === '1' && arr.length === 0 ) {
                item.primary = true;
            }
            arr.push( item );
            pcpChipWriteHidden( $picker, arr );
            pcpChipRenderList( $picker );
            return true;
        }
        function pcpChipRemoveAt( $picker, idx ) {
            var $hidden = $picker.find( '.pcp-chip-value' );
            var arr = [];
            try { arr = JSON.parse( $hidden.val() || '[]' ); } catch ( e ) { arr = []; }
            if ( idx < 0 || idx >= arr.length ) return;
            var wasPrimary = arr[ idx ].primary;
            arr.splice( idx, 1 );
            if ( wasPrimary && arr.length > 0 && $picker.attr( 'data-allow-primary' ) === '1' ) {
                arr[ 0 ].primary = true;
            }
            pcpChipWriteHidden( $picker, arr );
            pcpChipRenderList( $picker );
        }
        function pcpChipTogglePrimary( $picker, idx ) {
            var $hidden = $picker.find( '.pcp-chip-value' );
            var arr = [];
            try { arr = JSON.parse( $hidden.val() || '[]' ); } catch ( e ) { arr = []; }
            if ( idx < 0 || idx >= arr.length ) return;
            for ( var i = 0; i < arr.length; i++ ) arr[ i ].primary = false;
            arr[ idx ].primary = true;
            pcpChipWriteHidden( $picker, arr );
            pcpChipRenderList( $picker );
        }
        function pcpChipShowSuggest( $picker, query ) {
            var dataset = $picker.attr( 'data-source' );
            var $sug = $picker.find( '.pcp-chip-suggest' );
            $sug.empty();
            if ( !dataset || dataset === '' ) { $sug.hide(); return; }
            var hits = pcpChipSearch( dataset, query );
            if ( !hits.length ) { $sug.hide(); return; }
            hits.forEach( function ( h ) {
                var nativeBit = ( h.native && h.native !== h.label ) ? ' · ' + h.native : '';
                var $row = $( '<div class="pcp-chip-sug-row" data-code="' + h.code + '">' +
                    $( '<div>' ).text( h.label + nativeBit ).html() + '</div>' );
                $sug.append( $row );
            } );
            $sug.show();
        }
        function pcpChipBrowserFillIfEmpty( $picker ) {
            var bf = $picker.attr( 'data-browser-fill' );
            if ( !bf || bf === '0' || bf === '' ) return;
            var $hidden = $picker.find( '.pcp-chip-value' );
            if ( $hidden.val() && $hidden.val() !== '' ) return;  // already has a value
            try {
                if ( bf === 'country' ) {
                    var loc = navigator.language || ( navigator.languages || [] )[ 0 ] || '';
                    var parts = String( loc ).split( '-' );
                    if ( parts.length >= 2 ) {
                        var cc = parts[ 1 ].toUpperCase();
                        var hit = pcpChipLookup( 'countries', cc );
                        if ( hit ) {
                            pcpChipAdd( $picker, { code: hit.code, label: hit.label, suggested: true } );
                        }
                    }
                } else if ( bf === 'language' ) {
                    var langs = navigator.languages || [ navigator.language || '' ];
                    var added = 0;
                    for ( var i = 0; i < langs.length; i++ ) {
                        var lc = String( langs[ i ] ).split( '-' )[ 0 ].toLowerCase();
                        var hit2 = pcpChipLookup( 'languages', lc );
                        if ( hit2 ) {
                            pcpChipAdd( $picker, { code: hit2.code, label: hit2.label, suggested: true } );
                            added++;
                            if ( added >= 2 ) break;   // primary + secondary suggestion max
                        }
                    }
                } else if ( bf === 'timezone' ) {
                    var tz = '';
                    try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch ( e ) { tz = ''; }
                    if ( tz ) $hidden.val( tz );
                }
            } catch ( e ) {}
        }
        function pcpChipInit( picker ) {
            var $picker = $( picker );
            pcpChipRenderList( $picker );
            pcpChipBrowserFillIfEmpty( $picker );
            pcpChipRenderList( $picker );
        }

        $( '.pcp-chip-picker' ).each( function () { pcpChipInit( this ); } );

        $( document ).on( 'input', '.pcp-chip-input', function () {
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            pcpChipShowSuggest( $picker, $( this ).val() );
        } );
        $( document ).on( 'focus', '.pcp-chip-input', function () {
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            pcpChipShowSuggest( $picker, $( this ).val() );
        } );
        $( document ).on( 'blur', '.pcp-chip-input', function () {
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            setTimeout( function () { $picker.find( '.pcp-chip-suggest' ).hide(); }, 200 );
        } );
        $( document ).on( 'click', '.pcp-chip-sug-row', function () {
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            var code = $( this ).attr( 'data-code' );
            var dataset = $picker.attr( 'data-source' );
            var hit = pcpChipLookup( dataset, code );
            if ( !hit ) return;
            pcpChipAdd( $picker, { code: hit.code, label: hit.label } );
            $picker.find( '.pcp-chip-input' ).val( '' );
            $picker.find( '.pcp-chip-suggest' ).hide();
        } );
        $( document ).on( 'keydown', '.pcp-chip-input', function ( e ) {
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            if ( e.which === 13 ) {       // Enter
                e.preventDefault();
                var q = String( $( this ).val() || '' ).trim();
                if ( !q ) return;
                var dataset = $picker.attr( 'data-source' );
                var $sug = $picker.find( '.pcp-chip-suggest' );
                var $first = $sug.find( '.pcp-chip-sug-row' ).first();
                if ( $first.length ) {
                    var code = $first.attr( 'data-code' );
                    var hit = pcpChipLookup( dataset, code );
                    if ( hit ) pcpChipAdd( $picker, { code: hit.code, label: hit.label } );
                } else if ( $picker.attr( 'data-allow-custom' ) === '1' ) {
                    pcpChipAdd( $picker, { code: q, label: q, custom: true } );
                }
                $( this ).val( '' );
                $sug.hide();
            }
        } );
        $( document ).on( 'click', '.pcp-chip-remove', function ( e ) {
            e.preventDefault();
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            pcpChipRemoveAt( $picker, parseInt( $( this ).attr( 'data-idx' ), 10 ) );
        } );
        $( document ).on( 'click', '.pcp-chip-prim', function ( e ) {
            e.preventDefault();
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            pcpChipTogglePrimary( $picker, parseInt( $( this ).attr( 'data-idx' ), 10 ) );
        } );
        /* ===== End chip-picker ===== */

        /* ===== Structured widgets: smoking, alcohol, political, chronotype ===== */
        function pcpSyncSmoking( widget ) {
            var $w = $( widget );
            var status = $w.find( '.pcp-smoking-status' ).val() || '';
            var cigs   = parseFloat( $w.find( '.pcp-smoking-cigs' ).val() );
            var years  = parseFloat( $w.find( '.pcp-smoking-years' ).val() );
            var quit   = String( $w.find( '.pcp-smoking-quit' ).val() || '' );
            var obj = { status: status };
            if ( !isNaN( cigs )  && cigs  > 0 ) obj.cigs_per_day = cigs;
            if ( !isNaN( years ) && years > 0 ) obj.years_smoked = years;
            if ( status === 'former' && quit ) obj.quit_date = quit;
            // pack-years = (cigs/day / 20) * years
            if ( !isNaN( cigs ) && !isNaN( years ) && cigs > 0 && years > 0 ) {
                obj.pack_years = +( ( cigs / 20 ) * years ).toFixed( 1 );
                $w.find( '.pcp-smoking-packyears' ).text( 'Pack-years: ' + obj.pack_years );
            } else {
                $w.find( '.pcp-smoking-packyears' ).text( '' );
            }
            // Toggle conditional fields
            $w.attr( 'data-status', status );
            $w.find( '.pcp-smoking-hidden' ).val( status === '' ? '' : JSON.stringify( obj ) );
            $w.find( '.pcp-smoking-hidden' ).trigger( 'change' );
        }
        $( document ).on( 'input change', '.pcp-smoking-widget input:not(.pcp-smoking-hidden), .pcp-smoking-widget select', function () {
            pcpSyncSmoking( $( this ).closest( '.pcp-smoking-widget' )[ 0 ] );
        } );
        $( '.pcp-smoking-widget' ).each( function () { pcpSyncSmoking( this ); } );

        function pcpSyncAlcohol( widget ) {
            var $w = $( widget );
            var drinks  = parseFloat( $w.find( '.pcp-alc-week' ).val() );
            var typical = $w.find( '.pcp-alc-typical' ).val() || '';
            var maxOcc  = parseFloat( $w.find( '.pcp-alc-max' ).val() );
            var obj = {};
            if ( !isNaN( drinks ) && drinks >= 0 ) obj.drinks_per_week = drinks;
            if ( typical ) obj.typical_drink = typical;
            if ( !isNaN( maxOcc ) && maxOcc > 0 ) obj.max_one_occasion = maxOcc;
            var empty = Object.keys( obj ).length === 0;
            $w.find( '.pcp-alc-hidden' ).val( empty ? '' : JSON.stringify( obj ) );
            $w.find( '.pcp-alc-hidden' ).trigger( 'change' );
        }
        $( document ).on( 'input change', '.pcp-alc-widget input:not(.pcp-alc-hidden), .pcp-alc-widget select', function () {
            pcpSyncAlcohol( $( this ).closest( '.pcp-alc-widget' )[ 0 ] );
        } );
        $( '.pcp-alc-widget' ).each( function () { pcpSyncAlcohol( this ); } );

        // Auto-detect timezone on first visit if empty
        $( '.pcp-prof-field[data-key="demographics:time_zone"] input[type=text]' ).each( function () {
            if ( !$( this ).val() ) {
                try {
                    var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                    if ( tz ) $( this ).val( tz );
                } catch ( e ) {}
            }
        } );
        /* ===== End structured widgets ===== */

        /* ===== Enneagram items → 9 type sliders ===== */
        $( '.pcp-enn-compute' ).on( 'click', function () {
            var $btn = $( this );
            var sums = {}, counts = {};
            for ( var k = 1; k <= 9; k++ ) { sums[ k ] = 0; counts[ k ] = 0; }
            $( '.pcp-enn-item' ).each( function () {
                var $it = $( this );
                if ( $it.hasClass( 'pcp-unsure' ) ) return;
                var type = parseInt( $it.attr( 'data-type' ), 10 );
                if ( isNaN( type ) ) return;
                var $slider = $it.find( 'input.pcp-enn-item-slider' );
                if ( $slider.length === 0 ) return;
                var v = parseFloat( $slider.val() );
                if ( isNaN( v ) ) return;
                sums[ type ] += v;
                counts[ type ] += 1;
            } );
            var msg = [];
            for ( var t = 1; t <= 9; t++ ) {
                if ( counts[ t ] === 0 ) continue;
                var mean = sums[ t ] / counts[ t ];               // 1..5
                var typeScore = ( mean - 1 ) * 25;                // 0..100
                typeScore = Math.max( 0, Math.min( 100, typeScore ) );
                var $tslider = $( '.pcp-enn-type-row[data-type="' + t + '"] input.pcp-enn-type-slider' );
                if ( $tslider.length ) {
                    $tslider.val( typeScore.toFixed( 1 ) );
                    $tslider.next( 'output' ).val( typeScore.toFixed( 1 ) );
                }
                msg.push( 'T' + t + '=' + typeScore.toFixed( 1 ) );
            }
            $btn.after( '<div class="pcp-enn-compute-status" style="margin-top:8px; padding:8px 12px; background:rgba(124,58,237,0.12); border-left:3px solid #7c3aed; border-radius:3px;">Computed from items: ' + msg.join( ', ' ) + '. Click <strong>Save</strong> (or the floating block-save chip) to persist.</div>' );
            // remove any older status block
            $btn.siblings( '.pcp-enn-compute-status' ).not( ':last' ).remove();
        } );
        /* ===== End Enneagram compute ===== */


        /* ===== Click any slider's output number to type a precise value ===== */
        // Bind on 'output' (delegated). Inside the handler, find the paired
        // range via several fallbacks. Console-logs are intentional so we
        // can diagnose if the user reports it doesn't work.
        $( document ).on( 'click', 'output', function ( e ) {
            try { console.log( '[pcp-precise] click on output', this ); } catch (z) {}
            var $out = $( this );
            if ( $out.data( 'pcp-precise-editing' ) ) return;

            // Find paired range input, try in order:
            //   (1) immediate previous element sibling
            //   (2) any previous element sibling
            //   (3) any range input in the same parent
            //   (4) any range input in the same grandparent (slider rows)
            var $range = $out.prev( 'input[type=range]' );
            if ( !$range.length ) $range = $out.prevAll( 'input[type=range]' ).first();
            if ( !$range.length ) $range = $out.parent().find( 'input[type=range]' ).first();
            if ( !$range.length ) $range = $out.parent().parent().find( 'input[type=range]' ).first();
            try { console.log( '[pcp-precise] found range?', $range.length, $range[ 0 ] ); } catch (z) {}
            if ( !$range.length ) return;
            if ( $range.prop( 'disabled' ) ) return;

            $out.data( 'pcp-precise-editing', true );

            var min  = $range.attr( 'min' );
            var max  = $range.attr( 'max' );
            var step = $range.attr( 'step' ) || 'any';
            var cur  = $range.val();

            var $input = $( '<input type="number" class="pcp-slider-precise-input">' )
                .attr( { min: min, max: max, step: step } )
                .val( cur );

            // Auto-size width: longest of (min, max) including sign + decimal places from step, plus 2ch breathing room
            var maxAbs = Math.max( Math.abs( parseFloat( min ) || 0 ), Math.abs( parseFloat( max ) || 0 ) );
            var widthStr = String( Math.floor( maxAbs ) );
            if ( ( parseFloat( min ) || 0 ) < 0 ) widthStr = '-' + widthStr;
            var stepStr = String( step );
            var decDigits = stepStr.indexOf( '.' ) !== -1 ? ( stepStr.length - stepStr.indexOf( '.' ) - 1 ) : 0;
            if ( decDigits > 0 ) widthStr += '.' + new Array( decDigits + 1 ).join( '0' );
            $input.css( 'width', ( widthStr.length + 1 ) + 'ch' );

            $out.hide().after( $input );
            setTimeout( function () { try { $input[ 0 ].focus(); $input[ 0 ].select(); } catch (z) {} }, 0 );

            function commit( cancel ) {
                if ( $input.data( 'pcp-committed' ) ) return;
                $input.data( 'pcp-committed', true );
                if ( !cancel ) {
                    var v = parseFloat( $input.val() );
                    if ( !isNaN( v ) ) {
                        if ( min !== undefined && v < parseFloat( min ) ) v = parseFloat( min );
                        if ( max !== undefined && v > parseFloat( max ) ) v = parseFloat( max );
                        $range.val( v );
                        $range.trigger( 'input' );
                        $range.trigger( 'change' );
                    }
                }
                $input.remove();
                $out.show().data( 'pcp-precise-editing', false );
            }

            $input.on( 'blur', function () { commit( false ); } );
            $input.on( 'keydown', function ( ev ) {
                if ( ev.which === 13 ) { ev.preventDefault(); commit( false ); }
                if ( ev.which === 27 ) { ev.preventDefault(); commit( true ); }
            } );
        } );
        /* ===== End slider-precise input ===== */


        /* ===== Restore scroll position across page reloads triggered by profile actions ===== */
        function pcpSaveScroll() {
            try {
                sessionStorage.setItem( 'pcp-restore-scroll', String( window.scrollY ) );
            } catch ( e ) {}
        }
        // Save scroll before any form submit that's a profile action (delete buttons).
        $( document ).on( 'click', 'button[name="dx_delete"], button[name="um_delete"], button[name="xp_delete"]', pcpSaveScroll );
        // Also expose globally so blocksave.js (which triggers window.location.reload) can call it.
        window.pcpSaveScroll = pcpSaveScroll;

        // On load, restore the saved Y once and clear it.
        try {
            var pcpY = sessionStorage.getItem( 'pcp-restore-scroll' );
            if ( pcpY !== null ) {
                sessionStorage.removeItem( 'pcp-restore-scroll' );
                // Defer so the browser's own scroll-restore (if any) doesn't fight us.
                setTimeout( function () { window.scrollTo( 0, parseInt( pcpY, 10 ) || 0 ); }, 0 );
            }
        } catch ( e ) {}
        /* ===== End scroll-restore ===== */


        /* ===== Explicit "+ Add" buttons for dx / med add-rows ===== */
        // The add-row inputs (name^=dx_new[ / um_new[) are excluded from
        // autosave's dirty check. Clicking + Add fires a custom event on
        // the parent data-pcp-save-block, which blocksave force-saves.
        $( document ).on( 'click', '.pcp-add-commit', function ( e ) {
            e.preventDefault();
            var blockEl = $( this ).closest( '[data-pcp-save-block]' )[ 0 ];
            if ( !blockEl ) return;
            // Validate: don't fire if the description / med_name is empty
            var kind = $( this ).attr( 'data-add-kind' );
            var sel = ( kind === 'med' )
                ? 'input[name^="um_new["][name$="[med_name]"]'
                : 'input[name^="dx_new["][name$="[description]"]';
            var $field = $( blockEl ).find( sel );
            if ( !$field.length || ( $field.val() || '' ).trim() === '' ) {
                $field.focus();
                return;
            }
            blockEl.dispatchEvent( new CustomEvent( 'pcp-force-save', { bubbles: false } ) );
        } );
        /* ===== End add-commit ===== */

        /* ===== Generic keyboard navigation for autocomplete dropdowns ===== */
        /* Covers: diagnosis (.pcp-dx-*), medicine (.pcp-med-*), chip pickers (.pcp-chip-*).
           ArrowDown/Up: move highlight (wraps); Enter: select highlighted item;
           Escape: hide panel. Triggers both 'mousedown' (used by dx/med) and 'click'
           (used by chip) so each dropdown's existing select handler fires. */
        var PCP_DD_CFG = [
            { input: '.pcp-dx-desc-input',
              panel: function ( $i ) { return $i.closest( '.pcp-dx-row' ).find( '.pcp-dx-suggest' ); },
              items: '.pcp-dx-suggest-item, .pcp-dx-suggest-add-problem' },
            { input: '.pcp-med-name-input',
              panel: function ( $i ) { return $i.closest( '.pcp-med-row' ).find( '.pcp-med-suggest' ); },
              items: '.pcp-med-suggest-item' },
            { input: '.pcp-chip-input',
              panel: function ( $i ) { return $i.closest( '.pcp-chip-picker' ).find( '.pcp-chip-suggest' ); },
              items: '.pcp-chip-sug-row' }
        ];
        $( document ).on( 'keydown',
            '.pcp-dx-desc-input, .pcp-med-name-input, .pcp-chip-input',
            function ( e ) {
                var k = e.which;
                if ( k !== 38 && k !== 40 && k !== 13 && k !== 27 ) return;
                var $input = $( this );
                var cfg = null;
                for ( var i = 0; i < PCP_DD_CFG.length; i++ ) {
                    if ( $input.is( PCP_DD_CFG[ i ].input ) ) { cfg = PCP_DD_CFG[ i ]; break; }
                }
                if ( !cfg ) return;
                var $panel = cfg.panel( $input );
                if ( !$panel.length || !$panel.is( ':visible' ) ) return;
                var $items = $panel.find( cfg.items ).filter( ':visible' );
                if ( !$items.length ) return;
                var $active = $items.filter( '.pcp-suggest-active' );
                var idx = $active.length ? $items.index( $active ) : -1;
                if ( k === 40 ) {                                       // ArrowDown
                    e.preventDefault();
                    idx = ( idx + 1 ) % $items.length;
                } else if ( k === 38 ) {                                // ArrowUp
                    e.preventDefault();
                    idx = idx <= 0 ? $items.length - 1 : idx - 1;
                } else if ( k === 13 ) {                                // Enter
                    if ( !$active.length ) return;
                    e.preventDefault();
                    $active.trigger( 'mousedown' ).trigger( 'click' );
                    return;
                } else if ( k === 27 ) {                                // Escape
                    e.preventDefault();
                    $panel.hide();
                    return;
                }
                $items.removeClass( 'pcp-suggest-active' );
                var $new = $items.eq( idx ).addClass( 'pcp-suggest-active' );
                var el = $new[ 0 ];
                if ( el && el.scrollIntoView ) {
                    el.scrollIntoView( { block: 'nearest' } );
                }
            }
        );
        /* ===== End dropdown keyboard nav ===== */


        // Stop-reason chips: show per-chip severity slider when toggled on
        $( document ).on( 'change', '.pcp-xp-sr-toggle', function () {
            var $row = $( this ).closest( '.pcp-xp-sr-row' );
            $row.find( '.pcp-xp-sr-sev' ).attr( 'hidden', this.checked ? null : 'hidden' );
        } );


        /* ===== Report share chip ===== */
        $( document ).on( 'click', '.pcp-share-chip', function ( e ) {
            e.preventDefault();
            var $b = $( this );
            var url = $b.attr( 'data-share-url' );
            var key = $b.attr( 'data-assessment-key' );
            if ( !url ) return;
            // Check visibility — inline-assessment pattern (tv[K]) OR block-save (v[K][_vis]).
            // OCEAN has no per-test vis; treat as visible (assume per-trait individual chips).
            var visVal = 1;
            var $visInline = $( 'select[name="tv[' + key + ']"]' );
            var $visBlock  = $( 'select[name="v[' + key + '][_vis]"]' );
            if ( $visInline.length )      { visVal = parseInt( $visInline.val(), 10 ); }
            else if ( $visBlock.length )  { visVal = parseInt( $visBlock.val(), 10 ); }
            if ( isNaN( visVal ) ) visVal = 1;
            // Copy to clipboard
            function showToast( msg, isWarn ) {
                var $t = $( '<div class="pcp-share-toast' + ( isWarn ? ' pcp-share-toast-warn' : '' ) + '"></div>' ).text( msg );
                $( 'body' ).append( $t );
                setTimeout( function () { $t.fadeOut( 400, function () { $t.remove(); } ); }, 2400 );
            }
            var copyPromise;
            if ( navigator.clipboard && navigator.clipboard.writeText ) {
                copyPromise = navigator.clipboard.writeText( url );
            } else {
                // Fallback for non-secure contexts
                var $ta = $( '<textarea>' ).val( url ).css( { position: 'fixed', top: '-9999px' } ).appendTo( 'body' );
                $ta[ 0 ].select();
                try { document.execCommand( 'copy' ); } catch ( z ) {}
                $ta.remove();
                copyPromise = $.Deferred().resolve().promise();
            }
            copyPromise.then( function () {
                if ( visVal === 0 ) {
                    showToast( 'Link copied (but currently private; set visibility to Public via the eye chip first, or anyone with the link will see "not shared")', true );
                } else {
                    showToast( 'Share link copied to clipboard' );
                }
            }, function () {
                showToast( 'Could not copy to clipboard', true );
            } );
        } );
        /* ===== End share chip ===== */


        /* ===== Collapsible profile sections ===== */
        ( function () {
            var STORAGE_KEY = 'pcp-prof-section-collapsed-v1';
            var state = {};
            try { state = JSON.parse( localStorage.getItem( STORAGE_KEY ) || '{}' ); } catch ( e ) { state = {}; }
            // Default state: collapsed (added server-side via .is-collapsed class).
            // Honour an 'expanded' override per legend stored in localStorage.
            $( '.pcp-prof-section' ).each( function () {
                var $sec = $( this );
                var $leg = $sec.find( '> legend' ).first();
                if ( !$leg.length ) return;
                var key = $leg.text().trim();
                if ( state[ key ] === 'expanded' ) {
                    $sec.removeClass( 'is-collapsed' );
                }
                $leg.attr( 'aria-expanded', $sec.hasClass( 'is-collapsed' ) ? 'false' : 'true' );
            } );
            $( document ).on( 'click', '.pcp-prof-section > legend', function () {
                var $sec = $( this ).closest( '.pcp-prof-section' );
                var key = $( this ).text().trim();
                $sec.toggleClass( 'is-collapsed' );
                var collapsed = $sec.hasClass( 'is-collapsed' );
                $( this ).attr( 'aria-expanded', collapsed ? 'false' : 'true' );
                state[ key ] = collapsed ? 'collapsed' : 'expanded';
                try { localStorage.setItem( STORAGE_KEY, JSON.stringify( state ) ); } catch ( e ) {}
            } );
        
    // ===== Choice / multi voting (added with type=single|multi) =====
    $( '.pcp-vote-choice' ).each( function () {
        var $wrap = $( this );
        var $sum  = $wrap.find( '.pcp-vote-choice-summary' );
        var $pick = $wrap.find( '.pcp-vote-choice-picker' );
        $sum.on( 'click keydown', function ( e ) {
            if ( e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ' ) return;
            e.preventDefault();
            if ( !$pick.prop( 'hidden' ) ) { $pick.prop( 'hidden', true ); return; }
            renderPicker( $wrap );
            $pick.prop( 'hidden', false );
        } );
    } );

    function renderPicker( $wrap ) {
        var payload = JSON.parse( $wrap.attr( 'data-payload' ) || '{}' );
        var elementId = $wrap.data( 'element-id' );
        var optionsH = $wrap.attr( 'data-options-h' );
        var type = $wrap.attr( 'data-vote-type' );
        var input = type === 'single' ? 'radio' : 'checkbox';
        var name  = 'pcp-vote-' + elementId;
        var total = payload.total || 0;
        var $pick = $wrap.find( '.pcp-vote-choice-picker' );
        $pick.empty();
        ( payload.options || [] ).forEach( function ( label, i ) {
            var hasTally = payload.tally != null;
            var count = hasTally ? ( ( payload.tally && payload.tally[ i ] ) || 0 ) : null;
            var pct   = ( hasTally && total > 0 ) ? Math.round( count * 100 / total ) : 0;
            var checked = ( payload.user || [] ).indexOf( i ) !== -1 ? ' checked' : '';
            var $row = $( '<label class="pcp-vote-choice-row">' );
            $row.append(
                '<input type="' + input + '" name="' + name + '" value="' + i + '"' + checked + '>',
                $( '<span class="pcp-vote-choice-label">' ).text( label )
            );
            if ( hasTally ) {
                $row.append(
                    $( '<span class="pcp-vote-choice-bar">' ).append(
                        $( '<span class="pcp-vote-choice-bar-fill">' ).css( 'width', pct + '%' )
                    ),
                    $( '<span class="pcp-vote-choice-count">' ).text( count )
                );
            }
            $pick.append( $row );
        } );
        var $submit = $( '<button type="button" class="pcp-btn pcp-vote-choice-submit">Save vote</button>' );
        var $clear  = $( '<button type="button" class="pcp-btn pcp-vote-choice-clear">Clear</button>' );
        $pick.append( $( '<div class="pcp-vote-choice-actions">' ).append( $submit, ' ', $clear ) );

        $submit.on( 'click', function () { submitChoices( $wrap, optionsH ); } );
        $clear.on( 'click', function () { submitChoices( $wrap, optionsH, [] ); } );
    }

    function submitChoices( $wrap, optionsH, override ) {
        if ( !mw.user.isAnon() === false || mw.user.isAnon() ) {
            mw.notify( mw.msg( 'pharmacopedia-login-required' ), { type: 'warn' } );
            return;
        }
        var elementId = $wrap.data( 'element-id' );
        var $pick = $wrap.find( '.pcp-vote-choice-picker' );
        var picks = override !== undefined ? override : $pick.find( 'input:checked' ).map( function () { return this.value; } ).get();
        var csv = picks.join( ',' );
        var api = new mw.Api();
        api.postWithToken( 'csrf', {
            action: 'pharmacopediavote',
            element_id: elementId,
            choices:    csv,
            options_h:  optionsH
        } ).done( function ( r ) {
            // Update payload in place.
            var payload = JSON.parse( $wrap.attr( 'data-payload' ) || '{}' );
            payload.tally = r && r.pharmacopediavote && r.pharmacopediavote.tally || {};
            payload.user  = r && r.pharmacopediavote && r.pharmacopediavote.user_choices || [];
            if ( payload.tally != null ) {
                payload.total = 0;
                $.each( payload.tally, function ( k, v ) { payload.total += v; } );
            } else {
                payload.total = null;
            }
            $wrap.attr( 'data-payload', JSON.stringify( payload ) );
            // Update summary chip.
            var label = '';
            if ( payload.user.length ) {
                label = ' · you: ' + ( payload.options[ payload.user[0] ] || '?' ) + ( payload.user.length > 1 ? ' +' + ( payload.user.length - 1 ) : '' );
            }
            $wrap.find( '.pcp-vote-choice-total' ).text( payload.total != null ? payload.total : '–' );
            var $u = $wrap.find( '.pcp-vote-choice-user' );
            if ( label ) {
                if ( !$u.length ) {
                    $u = $( '<span class="pcp-vote-choice-user">' );
                    $wrap.find( '.pcp-vote-choice-summary' ).append( $u );
                }
                $u.text( label );
            } else {
                $u.remove();
            }
            renderPicker( $wrap );
        } ).fail( function ( e ) {
            mw.notify( 'Vote failed: ' + e, { type: 'error' } );
        } );
    }

}() );

        /* ===== End collapsible sections ===== */

    } );
}() );

/* ===== Feature-request review console: inline edits ===== */
( function () {
    'use strict';
    $( function () {
        var $review = $( '.pcp-fr-review' );
        if ( !$review.length ) return;

        var api = new mw.Api();
        var debounceTimers = {};

        function flashSaved( $row, ok, errMsg ) {
            var $mark = $row.find( '.pcp-fr-rrow-savedmark' );
            $mark.removeClass( 'is-saved is-error' )
                .addClass( ok ? 'is-saved' : 'is-error' );
            if ( errMsg ) $mark.attr( 'title', errMsg );
            setTimeout( function () { $mark.removeClass( 'is-saved is-error' ); }, ok ? 1200 : 2500 );
        }

        function submitField( $row, field, value ) {
            var id = parseInt( $row.attr( 'data-fr-id' ), 10 );
            if ( !id ) return;
            api.postWithToken( 'csrf', {
                action: 'pharmacopediafrupdate',
                id: id,
                field: field,
                value: String( value ),
                format: 'json'
            } ).done( function ( resp ) {
                if ( resp && resp.pharmacopediafrupdate && resp.pharmacopediafrupdate.ok ) {
                    flashSaved( $row, true );
                } else {
                    flashSaved( $row, false, 'Save failed' );
                }
            } ).fail( function ( code, info ) {
                var msg = ( info && info.error && info.error.info ) ? info.error.info : ( code || 'error' );
                flashSaved( $row, false, msg );
            } );
        }

        // Status + priority: save on change immediately
        $review.on( 'change', '.pcp-fr-rrow-status, .pcp-fr-rrow-prio', function () {
            var $sel = $( this );
            var $row = $sel.closest( '.pcp-fr-rrow' );
            var field = $sel.attr( 'data-field' );
            submitField( $row, field, $sel.val() );
        } );

        // Sysop notes: debounced save on input (1s after typing stops)
        $review.on( 'input', '.pcp-fr-rrow-notes', function () {
            var $ta = $( this );
            var $row = $ta.closest( '.pcp-fr-rrow' );
            var key = $row.attr( 'data-fr-id' ) + ':notes';
            if ( debounceTimers[ key ] ) clearTimeout( debounceTimers[ key ] );
            debounceTimers[ key ] = setTimeout( function () {
                submitField( $row, 'sysop_notes', $ta.val() );
            }, 900 );
        } );

        // Toggle expand/collapse
        $review.on( 'click', '.pcp-fr-rrow-toggle', function ( e ) {
            e.preventDefault();
            var $row = $( this ).closest( '.pcp-fr-rrow' );
            var $detail = $row.find( '.pcp-fr-rrow-detail' );
            var expanded = !$detail.prop( 'hidden' );
            if ( expanded ) {
                $detail.prop( 'hidden', true );
                $( this ).attr( 'aria-expanded', 'false' );
            } else {
                $detail.prop( 'hidden', false );
                $( this ).attr( 'aria-expanded', 'true' );
            }
        } );

        // Scroll to focused row if ?id=N was used
        var $focus = $review.find( '.pcp-fr-rrow[data-expanded="1"]' ).first();
        if ( $focus.length ) {
            $focus[0].scrollIntoView( { behavior: 'smooth', block: 'start' } );
        }
    } );
}() );
/* ===== End feature-request review console ===== */

/* ===== Share-profile button: copy URL to clipboard ===== */
( function () {
    'use strict';
    $( function () {
        $( document ).on( 'click', '.pcp-profile-share-btn', function () {
            var $btn = $( this );
            var url = $btn.attr( 'data-share-url' ) || '';
            if ( !url ) return;
            var oldText = $btn.text();
            function flashCopied() {
                $btn.addClass( 'is-copied' ).text( 'Copied!' );
                setTimeout( function () {
                    $btn.removeClass( 'is-copied' ).text( oldText );
                }, 1400 );
            }
            if ( navigator.clipboard && navigator.clipboard.writeText ) {
                navigator.clipboard.writeText( url ).then( flashCopied, function () {
                    // Fallback below.
                    fallbackCopy();
                } );
            } else {
                fallbackCopy();
            }
            function fallbackCopy() {
                var ta = document.createElement( 'textarea' );
                ta.value = url;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild( ta );
                ta.select();
                try { document.execCommand( 'copy' ); flashCopied(); }
                catch ( e ) { window.prompt( 'Copy your profile URL:', url ); }
                document.body.removeChild( ta );
            }
        } );
    } );
}() );
/* ===== End share-profile button ===== */
