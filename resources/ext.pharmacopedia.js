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
                    var gated = cur.experienced !== 1;
                    $widget.find( '.pcp-effect-valence-row' )
                        .toggleClass( 'pcp-disabled', gated );
                    // Native disabled also drops a gated slider from tab
                    // order, so a keyboard user cannot arrow it out of sync
                    // with the (rejected) save.
                    $vsl.prop( 'disabled', gated );
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
                    var gatedP = curP.frequency === null || curP.frequency === -1;
                    $widget.find( '.pcp-effect-valence-row' )
                        .toggleClass( 'pcp-disabled', gatedP );
                    $vsl2.prop( 'disabled', gatedP );
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
                    var $del = $( this );
                    if ( $del.data( 'pcpConfirmed' ) !== true ) {
                        window.PCPConfirmDelete( mw.msg( 'pharmacopedia-confirm-delete' ), function () {
                            $del.data( 'pcpConfirmed', true );
                            $del.trigger( 'click' );
                        } );
                        return;
                    }
                    $del.removeData( 'pcpConfirmed' );
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
            // A loose .pcp-effect card may only be absorbed into a <ul> that
            // is ITSELF an effect list (its <li>s hold .pcp-effect cards).
            // Grabbing the nearest <ul> of any kind yanks loose cards into an
            // unrelated list (e.g. the Interactions notes) and out of the
            // Effects section entirely.
            function isEffectUl() {
                return $( this ).children( 'li' ).children( '.pcp-effect' ).length > 0;
            }
            $( '.pcp-effect' ).each( function () {
                var $card = $( this );
                // Already inside an <li>? Skip.
                if ( $card.closest( 'li' ).length ) { return; }
                // Nearest sibling effect-<ul>, preferring one before the card.
                var $ul = $card.prevAll( 'ul' ).filter( isEffectUl ).first();
                if ( !$ul.length ) {
                    $ul = $card.nextAll( 'ul' ).filter( isEffectUl ).first();
                }
                if ( !$ul.length ) {
                    // No effect list near this card. Happens when effects are
                    // added as loose <effect/> tags with no leading "* "
                    // bullet (e.g. via Special:SuggestEffect). Synthesize a
                    // <ul> in place so the card still gets sorted/bucketed
                    // instead of being dropped or misfiled. Later loose cards
                    // absorb into this same synthesized <ul>.
                    $ul = $( '<ul></ul>' ).insertBefore( $card );
                }
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
            // Nothing has provider ratings yet. Bucketing would file every
            // effect under a collapsed "Not yet rated" disclosure and hide the
            // whole section. Leave the plain (sorted) list visible instead.
            if ( nRated === 0 ) { return; }

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
                    // h3 sits under the h2 Effects section heading; was h4
                    // (WCAG 1.3.1 outline skip, a11y-claude baseline 2026-05-22).
                    $( '<div class="pcp-effect-bucket pcp-effect-bucket-common"></div>' )
                        .append( $( '<h3 class="pcp-effect-bucket-heading"></h3>' )
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
                // Open by default so a freshly-added (always-unrated) effect
                // is visible immediately rather than hidden in a closed box.
                $container.append( mkCollapsible( 'Not yet rated', buckets.unrated, 'pcp-effect-bucket-unrated', true ) );
            }

            $ul.replaceWith( $container );
        }

        // runEffectSort rewrites the DOM (wraps loose cards, replaces <ul>s
        // with bucket containers) and is NOT safe to run twice; a second
        // pass would re-bucket the bucket lists into nested containers. It is
        // scheduled from two triggers (setTimeout + window load) as a belt-
        // and-suspenders against either firing too early; this guard makes
        // whichever fires first the only pass that does work.
        var effectSortRan = false;
        function runEffectSort() {
            if ( effectSortRan ) { return; }
            effectSortRan = true;
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
                    var $r = $card.find( '.pcp-rating' );
                    var s5 = ( d.mean ).toFixed( 1 );
                    $r.find( '.pcp-stars-clip' ).attr( 'width', ( d.mean * 24 ).toFixed( 2 ) );
                    $r.find( '.pcp-rating-num' ).text( s5 );
                    $r.find( '.pcp-rating-n' ).text( 'n=' + d.n );
                    $r.attr( 'aria-label', 'Efficacy rated ' + s5 +
                        ' out of 5, from ' + d.n + ' rating' + ( d.n === 1 ? '' : 's' ) );
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
                    var $r = $card.find( '.pcp-rating' );
                    var s5 = ( d.mean ).toFixed( 1 );
                    $r.find( '.pcp-stars-clip' ).attr( 'width', ( d.mean * 24 ).toFixed( 2 ) );
                    $r.find( '.pcp-rating-num' ).text( s5 );
                    $r.find( '.pcp-rating-n' ).text( 'n=' + d.n );
                    $r.attr( 'aria-label', 'Efficacy rated ' + s5 +
                        ' out of 5, from ' + d.n + ' rating' + ( d.n === 1 ? '' : 's' ) );
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
            if ( $b.data( 'pcpConfirmed' ) !== true ) {
                window.PCPConfirmDelete( 'Delete this ' + type + '? This cannot be undone (but a sysop can restore via page history).', function () {
                    $b.data( 'pcpConfirmed', true );
                    $b.trigger( 'click' );
                } );
                return;
            }
            $b.removeData( 'pcpConfirmed' );
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
            if ( $btn.data( 'pcpConfirmed' ) !== true ) {
                window.PCPConfirmDelete( 'Delete the note from ' + authorName + '? The rating itself will be preserved.', function () {
                    $btn.data( 'pcpConfirmed', true );
                    $btn.trigger( 'click' );
                } );
                return;
            }
            $btn.removeData( 'pcpConfirmed' );
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
            var $delBtn = $( this );
            if ( $delBtn.data( 'pcpConfirmed' ) !== true ) {
                window.PCPConfirmDelete( 'Delete this pending submission?', function () {
                    $delBtn.data( 'pcpConfirmed', true );
                    $delBtn.trigger( 'click' );
                } );
                return;
            }
            $delBtn.removeData( 'pcpConfirmed' );
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
$( document ).on( 'click', '.pcp-vis-toggle', function () {
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

        /* ===== Height items (std vs metric) =====
         * On unit-radio change, show the matching .pcp-pid-height-group
         * (data-height-unit) and hide the other. Std has feet+inches; metric
         * has cm. Initial sync mirrors the rendered defaults (std checked). */
        $( document ).on( 'change', '.pcp-pid-height input[type=radio][name^="t_unit["]', function () {
            var $item = $( this ).closest( '.pcp-pid-height' );
            var picked = this.value;
            $item.find( '.pcp-pid-height-group' ).each( function () {
                this.hidden = ( this.getAttribute( 'data-height-unit' ) !== picked );
            } );
        } );
        $( '.pcp-pid-height' ).each( function () {
            var $item = $( this );
            var picked = $item.find( 'input[type=radio][name^="t_unit["]:checked' ).val() || 'std';
            $item.find( '.pcp-pid-height-group' ).each( function () {
                this.hidden = ( this.getAttribute( 'data-height-unit' ) !== picked );
            } );
        } );
        /* ===== End Height items ===== */

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

        /* ===== Generic chip-picker widget v2 (designer-claude 2026-05-20) =====
         * 4 visual states (default / custom / primary / browser-fill).
         * Browse-on-focus opens the picklist. Explicit "+ Add ... as
         * custom" suggestion row when allowCustom + no exact match.
         * Browser-fill chips promote to default on any interaction.
         * Already-selected suggestions render disabled with ✓.
         *
         * Markup contract (server-emitted shell unchanged from v1):
         *   <span class="pcp-chip-picker"
         *         data-source="..." data-multi="..." data-allow-custom="..."
         *         data-allow-primary="..." data-browser-fill="...">
         *     <span class="pcp-chip-list"></span>
         *     <input class="pcp-chip-input">
         *     <span class="pcp-chip-suggest"></span>
         *     <input type="hidden" class="pcp-chip-value" value="[...]">
         *   </span>
         *
         * Hidden-value JSON shape (per item):
         *   { code, label, primary?, custom?, suggested? }
         * The `suggested` flag is purely transient (browser-fill render
         * state). It is persisted across page loads so a re-load still
         * marks the chip as unconfirmed; it is stripped on user
         * interaction (click body, mark primary, retype).
         */
        function pcpChipLookup( datasetKey, code ) {
            var DS = ( window.PCP_DATASETS || {} )[ datasetKey ] || [];
            for ( var i = 0; i < DS.length; i++ ) {
                if ( DS[ i ].code === code ) return DS[ i ];
            }
            return null;
        }
        function pcpChipDataset( $picker ) {
            var key = $picker.attr( 'data-source' );
            if ( !key ) return [];
            return ( window.PCP_DATASETS || {} )[ key ] || [];
        }
        function pcpChipReadArr( $picker ) {
            var raw = $picker.find( '.pcp-chip-value' ).val();
            if ( !raw || raw === '' ) return [];
            try { return JSON.parse( raw ) || []; } catch ( e ) { return []; }
        }
        function pcpChipSearch( datasetKey, query ) {
            var DS = ( window.PCP_DATASETS || {} )[ datasetKey ] || [];
            var q = String( query || '' ).toLowerCase().trim();
            if ( q === '' ) return DS.slice( 0 );
            var hits = [];
            for ( var i = 0; i < DS.length; i++ ) {
                var d = DS[ i ];
                var hay = ( d.code + ' ' + d.label + ' ' +
                            ( d.native || '' ) + ' ' +
                            ( d.alts || [] ).join( ' ' ) ).toLowerCase();
                if ( hay.indexOf( q ) !== -1 ) hits.push( d );
                if ( hits.length >= 40 ) break;
            }
            return hits;
        }

        function pcpChipRenderChip( item, idx, allowPrim, dataset ) {
            var label = item.label || item.code || '';
            if ( !label && dataset ) {
                var lk = pcpChipLookup( dataset, item.code );
                if ( lk ) label = lk.label;
            }
            var nativeBit = '';
            if ( dataset && item.code ) {
                var lk2 = pcpChipLookup( dataset, item.code );
                if ( lk2 && lk2.native && lk2.native !== lk2.label ) {
                    nativeBit = ' · ' + lk2.native;
                }
            }
            var classes = [ 'pcp-chip' ];
            if ( item.custom )    classes.push( 'pcp-chip-custom' );
            if ( item.primary )   classes.push( 'pcp-chip-primary' );
            if ( item.suggested ) classes.push( 'pcp-chip-browserfill' );

            var html = '<span class="' + classes.join( ' ' ) +
                       '" data-idx="' + idx + '">';

            // Marker glyph (left of label): ✦ for primary, ◌ for browserfill
            if ( item.primary ) {
                html += '<span class="pcp-chip-marker" aria-hidden="true">✦</span>';
                if ( allowPrim ) {
                    html += '<button type="button" class="pcp-chip-prim" ' +
                            'aria-label="Unmark primary" data-idx="' + idx + '"></button>';
                }
            } else if ( item.suggested ) {
                html += '<span class="pcp-chip-marker" aria-hidden="true">◌</span>';
            } else if ( allowPrim ) {
                // No marker rendered, but expose a click target for marking primary
                html += '<button type="button" class="pcp-chip-prim" ' +
                        'aria-label="Mark primary" data-idx="' + idx + '"></button>';
            }

            html += '<span class="pcp-chip-label">' +
                    $( '<div>' ).text( label ).html() +
                    '<small>' + $( '<div>' ).text( nativeBit ).html() + '</small>' +
                    '</span>';
            html += '<button type="button" class="pcp-chip-remove" ' +
                    'data-idx="' + idx + '" aria-label="Remove">×</button>';
            html += '</span>';
            return html;
        }

        function pcpChipRenderList( $picker ) {
            var $list = $picker.find( '.pcp-chip-list' );
            var allowPrim = $picker.attr( 'data-allow-primary' ) === '1';
            var dataset = $picker.attr( 'data-source' );
            var arr = pcpChipReadArr( $picker );

            $list.empty();
            arr.forEach( function ( item, idx ) {
                $list.append( pcpChipRenderChip( item, idx, allowPrim, dataset ) );
            } );

            // Hide input when single-value picker has its value
            var multi = $picker.attr( 'data-multi' ) === '1';
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
            var multi = $picker.attr( 'data-multi' ) === '1';
            var arr = pcpChipReadArr( $picker );
            var key = item.code || item.label;
            for ( var i = 0; i < arr.length; i++ ) {
                if ( ( arr[ i ].code || arr[ i ].label ) === key ) return false;
            }
            if ( !multi ) arr = [];
            if ( $picker.attr( 'data-allow-primary' ) === '1' && arr.length === 0 ) {
                item.primary = true;
            }
            arr.push( item );
            pcpChipWriteHidden( $picker, arr );
            pcpChipRenderList( $picker );
            return true;
        }

        function pcpChipRemoveAt( $picker, idx ) {
            var arr = pcpChipReadArr( $picker );
            if ( idx < 0 || idx >= arr.length ) return;
            var wasPrimary = arr[ idx ].primary;
            arr.splice( idx, 1 );
            if ( wasPrimary && arr.length > 0 &&
                 $picker.attr( 'data-allow-primary' ) === '1' ) {
                arr[ 0 ].primary = true;
                if ( arr[ 0 ].suggested ) delete arr[ 0 ].suggested;
            }
            pcpChipWriteHidden( $picker, arr );
            pcpChipRenderList( $picker );
        }

        function pcpChipTogglePrimary( $picker, idx ) {
            var arr = pcpChipReadArr( $picker );
            if ( idx < 0 || idx >= arr.length ) return;
            for ( var i = 0; i < arr.length; i++ ) arr[ i ].primary = false;
            arr[ idx ].primary = true;
            if ( arr[ idx ].suggested ) delete arr[ idx ].suggested;
            pcpChipWriteHidden( $picker, arr );
            pcpChipRenderList( $picker );
        }

        function pcpChipPromoteFromBrowserFill( $picker, idx ) {
            var arr = pcpChipReadArr( $picker );
            if ( idx < 0 || idx >= arr.length ) return;
            if ( !arr[ idx ].suggested ) return;
            delete arr[ idx ].suggested;
            pcpChipWriteHidden( $picker, arr );
            pcpChipRenderList( $picker );
        }

        /* Render the suggestion panel. Modes:
         *   - No typed query: show full list (small) or top-15 + show-all (large)
         *   - Typed query: filter; if no exact match and allowCustom,
         *     append separator + "+ Add 'X' as custom" row
         *   - Already-chipped entries: rendered .pcp-chip-sug-selected
         */
        function pcpChipShowSuggest( $picker, query ) {
            var dataset = $picker.attr( 'data-source' );
            var allowCustom = $picker.attr( 'data-allow-custom' ) === '1';
            var $sug = $picker.find( '.pcp-chip-suggest' );
            $sug.empty();

            if ( !dataset || dataset === '' ) {
                $sug.removeClass( 'pcp-chip-suggest-open' );
                return;
            }

            var q = String( query || '' ).trim();
            var DS = pcpChipDataset( $picker );
            var expanded = $picker.attr( 'data-suggest-expanded' ) === '1';
            var existing = pcpChipReadArr( $picker );
            var existingCodes = {};
            existing.forEach( function ( it ) {
                if ( it.code ) existingCodes[ it.code ] = true;
            } );

            var hits;
            var truncated = false;
            if ( q === '' ) {
                // No query: full or top-15
                if ( !expanded && DS.length > 30 ) {
                    hits = DS.slice( 0, 15 );
                    truncated = true;
                } else {
                    hits = DS.slice( 0 );
                }
            } else {
                hits = pcpChipSearch( dataset, q );
            }

            var exact = false;
            var qLow = q.toLowerCase();
            for ( var k = 0; k < hits.length; k++ ) {
                var hL = String( hits[ k ].label || '' ).toLowerCase();
                if ( hL === qLow || String( hits[ k ].code || '' ).toLowerCase() === qLow ) {
                    exact = true;
                    break;
                }
            }

            var $section = $( '<div class="pcp-chip-sug-section"></div>' );
            if ( hits.length === 0 && q === '' ) {
                $section.append( '<div class="pcp-chip-sug-row pcp-chip-sug-empty" style="color:var(--pcp-chip-lichen);font-style:italic;">No options</div>' );
            }
            hits.forEach( function ( h ) {
                var nativeBit = '';
                if ( h.native && h.native !== h.label ) nativeBit = ' · ' + h.native;
                var selectedCls = existingCodes[ h.code ] ? ' pcp-chip-sug-selected' : '';
                var $row = $(
                    '<div class="pcp-chip-sug-row' + selectedCls +
                    '" data-code="' + h.code + '">' +
                    $( '<div>' ).text( h.label ).html() +
                    ( nativeBit
                        ? '<small>' + $( '<div>' ).text( nativeBit ).html() + '</small>'
                        : '' ) +
                    '</div>'
                );
                $section.append( $row );
            } );
            $sug.append( $section );

            // Show-all footer for large lists when not expanded
            if ( truncated ) {
                var $more = $( '<div class="pcp-chip-sug-row pcp-chip-sug-showall">' +
                    'Show all ' + DS.length + ' options</div>' );
                $sug.append( $more );
            }

            // Explicit "+ Add 'X' as custom" row
            if ( q !== '' && !exact && allowCustom ) {
                $sug.append( '<div class="pcp-chip-sug-separator"></div>' );
                var safeQ = $( '<div>' ).text( q ).html();
                $sug.append(
                    '<div class="pcp-chip-sug-row pcp-chip-sug-addcustom" ' +
                    'data-custom="' + safeQ + '">' +
                    '+ Add &ldquo;' + safeQ + '&rdquo; as custom</div>'
                );
            }

            $sug.addClass( 'pcp-chip-suggest-open' );
        }

        function pcpChipBrowserFillIfEmpty( $picker ) {
            var bf = $picker.attr( 'data-browser-fill' );
            if ( !bf || bf === '0' || bf === '' ) return;
            var $hidden = $picker.find( '.pcp-chip-value' );
            if ( $hidden.val() && $hidden.val() !== '' ) return;
            try {
                if ( bf === 'country' ) {
                    var loc = navigator.language || ( navigator.languages || [] )[ 0 ] || '';
                    var parts = String( loc ).split( '-' );
                    if ( parts.length >= 2 ) {
                        var cc = parts[ 1 ].toUpperCase();
                        var hit = pcpChipLookup( 'countries', cc );
                        if ( hit ) {
                            pcpChipAdd( $picker, {
                                code: hit.code, label: hit.label, suggested: true
                            } );
                        }
                    }
                } else if ( bf === 'language' ) {
                    var langs = navigator.languages || [ navigator.language || '' ];
                    var added = 0;
                    for ( var i = 0; i < langs.length; i++ ) {
                        var lc = String( langs[ i ] ).split( '-' )[ 0 ].toLowerCase();
                        var hit2 = pcpChipLookup( 'languages', lc );
                        if ( hit2 ) {
                            pcpChipAdd( $picker, {
                                code: hit2.code, label: hit2.label, suggested: true
                            } );
                            added++;
                            if ( added >= 2 ) break;
                        }
                    }
                } else if ( bf === 'timezone' ) {
                    var tz = '';
                    try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone; }
                    catch ( e ) { tz = ''; }
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

        // Show suggestions on input change
        $( document ).on( 'input', '.pcp-chip-input', function () {
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            // Typing collapses any prior expanded state
            $picker.removeAttr( 'data-suggest-expanded' );
            pcpChipShowSuggest( $picker, $( this ).val() );
        } );

        // Browse-on-focus: open panel before any keystroke
        $( document ).on( 'focus', '.pcp-chip-input', function () {
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            pcpChipShowSuggest( $picker, $( this ).val() );
        } );

        // Close on blur (200ms grace for click)
        $( document ).on( 'blur', '.pcp-chip-input', function () {
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            setTimeout( function () {
                $picker.find( '.pcp-chip-suggest' ).removeClass( 'pcp-chip-suggest-open' );
                $picker.removeAttr( 'data-suggest-expanded' );
            }, 200 );
        } );

        // Click on "Show all N options" footer expands the panel
        $( document ).on( 'click', '.pcp-chip-sug-showall', function ( e ) {
            e.preventDefault();
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            $picker.attr( 'data-suggest-expanded', '1' );
            pcpChipShowSuggest( $picker, $picker.find( '.pcp-chip-input' ).val() );
            // Keep focus on input so blur handler doesn't fire
            $picker.find( '.pcp-chip-input' ).focus();
        } );

        // Click on "+ Add X as custom" row
        $( document ).on( 'click', '.pcp-chip-sug-addcustom', function () {
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            var q = String( $( this ).attr( 'data-custom' ) || '' ).trim();
            if ( !q ) return;
            // Decode any HTML entities back to plain text for the chip
            var plain = $( '<div>' ).html( q ).text();
            pcpChipAdd( $picker, { code: plain, label: plain, custom: true } );
            $picker.find( '.pcp-chip-input' ).val( '' );
            $picker.find( '.pcp-chip-suggest' )
                .empty().removeClass( 'pcp-chip-suggest-open' );
        } );

        // Click on a regular suggestion row (data-code present, not addcustom/showall/selected)
        $( document ).on( 'click', '.pcp-chip-sug-row', function ( e ) {
            // Skip non-data-code rows; their own handlers will fire
            if ( $( this ).is( '.pcp-chip-sug-addcustom, .pcp-chip-sug-showall' ) ) return;
            if ( $( this ).hasClass( 'pcp-chip-sug-selected' ) ) return;  // no-op
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            var code = $( this ).attr( 'data-code' );
            if ( !code ) return;
            var dataset = $picker.attr( 'data-source' );
            var hit = pcpChipLookup( dataset, code );
            if ( !hit ) return;
            pcpChipAdd( $picker, { code: hit.code, label: hit.label } );
            $picker.find( '.pcp-chip-input' ).val( '' );
            $picker.find( '.pcp-chip-suggest' )
                .empty().removeClass( 'pcp-chip-suggest-open' );
        } );

        // Enter in input: prefer active keyboard-nav row, fall back to first
        // suggestion, fall back to add-custom row
        $( document ).on( 'keydown', '.pcp-chip-input', function ( e ) {
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            if ( e.which !== 13 ) return;  // only Enter handled here
            e.preventDefault();
            var $sug = $picker.find( '.pcp-chip-suggest' );
            var $active = $sug.find( '.pcp-chip-sug-row.pcp-chip-sug-active' ).first();
            if ( $active.length ) {
                $active.trigger( 'click' );
                return;
            }
            var $firstSelectable = $sug.find(
                '.pcp-chip-sug-row:not(.pcp-chip-sug-selected):not(.pcp-chip-sug-showall):not(.pcp-chip-sug-addcustom)'
            ).first();
            if ( $firstSelectable.length ) {
                $firstSelectable.trigger( 'click' );
                return;
            }
            var $addCustom = $sug.find( '.pcp-chip-sug-addcustom' ).first();
            if ( $addCustom.length ) {
                $addCustom.trigger( 'click' );
                return;
            }
            // Last-resort: pure custom add from typed string
            if ( $picker.attr( 'data-allow-custom' ) === '1' ) {
                var q = String( $( this ).val() || '' ).trim();
                if ( q ) {
                    pcpChipAdd( $picker, { code: q, label: q, custom: true } );
                    $( this ).val( '' );
                    $sug.empty().removeClass( 'pcp-chip-suggest-open' );
                }
            }
        } );

        // Click on × removes
        $( document ).on( 'click', '.pcp-chip-remove', function ( e ) {
            e.preventDefault();
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            pcpChipRemoveAt( $picker, parseInt( $( this ).attr( 'data-idx' ), 10 ) );
        } );

        // Click on primary toggle (also promotes from browser-fill)
        $( document ).on( 'click', '.pcp-chip-prim', function ( e ) {
            e.preventDefault();
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            pcpChipTogglePrimary( $picker, parseInt( $( this ).attr( 'data-idx' ), 10 ) );
        } );

        // Click on chip BODY (not on its buttons) promotes browser-fill chip
        $( document ).on( 'click', '.pcp-chip.pcp-chip-browserfill', function ( e ) {
            // If the click was on a button inside the chip, the more-specific
            // handlers above already fired; skip here.
            if ( $( e.target ).closest( '.pcp-chip-remove, .pcp-chip-prim' ).length ) {
                return;
            }
            var $picker = $( this ).closest( '.pcp-chip-picker' );
            pcpChipPromoteFromBrowserFill(
                $picker,
                parseInt( $( this ).attr( 'data-idx' ), 10 )
            );
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


        /* ===== Collapsible profile sections =====
         * Always starts CLOSED on every page load (per Mark, 2026-05-20).
         * Click-to-toggle still works within the current page view;
         * the previous localStorage-restore behavior was removed so
         * users get a fresh-collapsed state on each reload. */
        ( function () {
            // Force collapsed state on load regardless of any leftover
            // server-side or stored state.
            $( '.pcp-prof-section' ).each( function () {
                var $sec = $( this );
                var $leg = $sec.find( '> legend' ).first();
                if ( !$leg.length ) return;
                $sec.addClass( 'is-collapsed' );
                $leg.attr( 'aria-expanded', 'false' );
            } );
            // Clear any prior localStorage state from the v1 scheme so it
            // doesn't accumulate forever in users' browsers.
            try { localStorage.removeItem( 'pcp-prof-section-collapsed-v1' ); } catch ( e ) {}

            $( document ).on( 'click', '.pcp-prof-section > legend', function () {
                var $sec = $( this ).closest( '.pcp-prof-section' );
                $sec.toggleClass( 'is-collapsed' );
                $( this ).attr( 'aria-expanded',
                    $sec.hasClass( 'is-collapsed' ) ? 'false' : 'true' );
            } );

            /* ===== Assessment-picker bounce-back =====
             * Clicking "Add" on an assessment tile submits the form and the
             * server redirects back with ?saved=1#pcp-assessments. We want the
             * post-reload page to land on, expand to, and flash the SPECIFIC
             * assessment block just added, not just the section heading.
             * Mirrors the formal-testing card flash (sessionStorage handoff). */
            var PICK_KEY = 'pcp-assessment-scroll-to';
            // CAPTURE: stash the picked key the instant Add is clicked.
            $( document ).on( 'click', 'button[name="pcp_pick_add"]', function () {
                var k = this.getAttribute( 'data-pcp-pick-key' )
                    || this.value || '';
                if ( k ) {
                    try { sessionStorage.setItem( PICK_KEY, k ); } catch ( e ) {}
                }
            } );
            // home-claude 2026-05-22: sidebar link "My Assessments"
            // -> Special:MyProfile#pcp-assessments should OPEN the
            // section, not just scroll to it. On load and hashchange,
            // if location.hash names a .pcp-prof-section id, expand
            // it (same toggle as the legend click).
            function pcpOpenHashSection() {
                var h = location.hash;
                if ( !h || h.length < 2 ) { return; }
                var el = document.getElementById(
                    decodeURIComponent( h.slice( 1 ) )
                );
                if ( !el || !el.classList.contains( 'pcp-prof-section' ) ) {
                    return;
                }
                el.classList.remove( 'is-collapsed' );
                $( el ).find( '> legend' ).first()
                    .attr( 'aria-expanded', 'true' );
            }
            pcpOpenHashSection();
            $( window ).on( 'hashchange', pcpOpenHashSection );

            // RESTORE: on the next load, expand the section, scroll, flash.
            ( function restorePickedAssessment() {
                var key;
                try { key = sessionStorage.getItem( PICK_KEY ); } catch ( e ) { return; }
                if ( !key ) { return; }
                try { sessionStorage.removeItem( PICK_KEY ); } catch ( e ) {}
                var block = document.getElementById( 'pcp-assessment-' + key );
                if ( !block ) { return; }
                // Un-collapse the containing profile section so the block shows.
                var $sec = $( block ).closest( '.pcp-prof-section' );
                if ( $sec.length ) {
                    $sec.removeClass( 'is-collapsed' );
                    $sec.find( '> legend' ).first().attr( 'aria-expanded', 'true' );
                }
                // Bounce-back's scroll-restore stands down when a URL #hash is
                // present, so it will not fight this. Wait for layout, then
                // scroll to and flash the specific block. The flash is the
                // same purple glow used for the formal-test card flash
                // (.pcp-ft-card-flash); applied inline so no extra CSS is
                // needed.
                requestAnimationFrame( function () {
                    requestAnimationFrame( function () {
                        block.scrollIntoView( { behavior: 'smooth', block: 'center' } );
                        block.style.transition = 'box-shadow 1.5s ease-out';
                        block.style.boxShadow =
                            '0 0 0 2px #c4b5fd, 0 0 16px rgba(196, 181, 253, 0.55)';
                        setTimeout( function () {
                            block.style.boxShadow = '';
                        }, 1800 );
                        setTimeout( function () {
                            block.style.transition = '';
                        }, 3400 );
                    } );
                } );
            }() );

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
        if ( $wrap.attr( 'data-open-ended' ) === '1' ) {
            var maxOpts = parseInt( $wrap.attr( 'data-max-options' ) || '10', 10 );
            var curOpts = ( payload.options || [] ).length;
            if ( curOpts < maxOpts ) {
                var $addRow = $( '<div class="pcp-vote-choice-add-option">' ).append(
                    $( '<input type="text" class="pcp-vote-choice-add-input" maxlength="120" placeholder="+ Add an option" autocomplete="off">' ),
                    $( '<button type="button" class="pcp-btn pcp-btn-sm pcp-vote-choice-add-btn">Add</button>' ),
                    $( '<span class="pcp-vote-choice-add-meta">' ).text( curOpts + ' / ' + maxOpts + ' options' )
                );
                $pick.append( $addRow );
                var doAdd = function () {
                    var $in = $wrap.find( '.pcp-vote-choice-add-input' );
                    var label = $.trim( $in.val() );
                    if ( !label ) return;
                    $in.prop( 'disabled', true );
                    api.postWithToken( 'csrf', {
                        action: 'pharmacopediavote',
                        element_id: $wrap.data( 'element-id' ),
                        add_option: label
                    } ).done( function ( r ) {
                        var d = r && r.pharmacopediavote || {};
                        var newOpts = d.options || payload.options;
                        var newH    = d.options_h || $wrap.attr( 'data-options-h' );
                        // Update local payload + element
                        payload.options = newOpts;
                        payload.tally   = d.tally || payload.tally || {};
                        $wrap.attr( 'data-payload', JSON.stringify( payload ) );
                        $wrap.attr( 'data-options-h', newH );
                        $in.val( '' );
                        renderPicker( $wrap );
                    } ).fail( function ( e, data ) {
                        var msg = data && data.error ? data.error.info : ( 'Add failed: ' + e );
                        mw.notify( msg, { type: 'warn' } );
                        $in.prop( 'disabled', false ).focus();
                    } );
                };
                $addRow.find( '.pcp-vote-choice-add-btn' ).on( 'click', doAdd );
                $addRow.find( '.pcp-vote-choice-add-input' ).on( 'keydown', function ( e ) {
                    if ( e.key === 'Enter' ) { e.preventDefault(); doAdd(); }
                } );
            }
        }
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


/* ===== Granular PGx interaction voting: vote-panel wiring =====
   Binds every .pcp-pgx-vote panel emitted by InteractionTag::
   renderPgxVotePanel(). Five curation dimensions POST to
   action=pcp-interaction-flag; the experience block POSTs to the
   existing action=pharmacopediainteractionreport. Queued 2026-05-19. */
$( function () {
    var $panels = $( '.pcp-pgx-vote' );
    if ( !$panels.length ) { return; }
    var pgxApi = new mw.Api();
    var pgxLoggedIn = !!mw.config.get( 'wgUserName' );

    function pgxNeedLogin() {
        mw.notify( mw.msg( 'pharmacopedia-login-required' ), { type: 'warn' } );
    }

    function pgxStatus( $panel, msg ) {
        var $s = $panel.find( '.pcp-pgx-vote-status' );
        if ( !msg ) { $s.attr( 'hidden', 'hidden' ).text( '' ); return; }
        $s.text( msg ).removeAttr( 'hidden' );
    }

    function pgxFail( $panel, code, data ) {
        pgxStatus( $panel, '' );
        var msg = ( data && data.error && data.error.info ) ||
                  ( 'Save failed: ' + ( code || 'unknown' ) );
        mw.notify( msg, { type: 'error' } );
    }

    // Fill the per-dimension tally spans from a getFlagAggregates() map.
    function pgxApplyAggs( $panel, aggs ) {
        if ( !aggs ) { return; }
        $panel.find( '.pcp-pgx-vote-dim' ).each( function () {
            var $dim = $( this );
            var type = $dim.attr( 'data-flag-type' );
            if ( !type ) { return; }
            var $agg = $dim.find( '.pcp-pgx-vote-agg' );
            var e = aggs[ type ];
            if ( !e || !e.n ) { $agg.attr( 'hidden', 'hidden' ).text( '' ); return; }
            var txt = ( e.mean !== null && e.mean !== undefined )
                ? ( e.n + ' rated, mean ' + Number( e.mean ).toFixed( 1 ) )
                : ( e.n + ' flagged' );
            $agg.text( txt ).removeAttr( 'hidden' );
        } );
    }

    // Submit or clear one flag dimension.
    function pgxFlag( $panel, type, value, clear ) {
        var payload = {
            action: 'pcp-interaction-flag',
            elementid: parseInt( $panel.attr( 'data-element-id' ), 10 ),
            type: type,
            formatversion: 2,
            format: 'json'
        };
        if ( clear ) { payload.clear = 1; } else { payload.value = value; }
        pgxStatus( $panel, 'Saving...' );
        pgxApi.postWithToken( 'csrf', payload ).done( function ( resp ) {
            var d = resp && resp[ 'pcp-interaction-flag' ];
            if ( !d || !d.ok ) { pgxStatus( $panel, '' ); mw.notify( 'Save failed.', { type: 'error' } ); return; }
            pgxApplyAggs( $panel, d.aggregates );
            pgxStatus( $panel, 'Saved.' );
        } ).fail( function ( code, data ) { pgxFail( $panel, code, data ); } );
    }

    $panels.each( function () {
        var $panel = $( this );
        if ( $panel.data( 'pcp-pgx-bound' ) ) { return; }
        $panel.data( 'pcp-pgx-bound', 1 );

        // a11y: name the controls that markup alone leaves bare.
        $panel.find( '.pcp-pgx-vote-scale, .pcp-pgx-vote-opts' ).each( function () {
            var $g = $( this );
            var q = $g.closest( '.pcp-pgx-vote-dim' ).find( '.pcp-pgx-vote-q' ).first().text();
            $g.attr( { role: 'group', 'aria-label': q } );
        } );
        $panel.find( '.pcp-pgx-vote-vslider' ).attr( 'aria-label', 'Outcome, -100 worst to +100 best' );
        $panel.find( '.pcp-pgx-vote-btn, .pcp-pgx-vote-expbtn' ).each( function () {
            var $b = $( this );
            if ( !$b.attr( 'aria-label' ) ) { $b.attr( 'aria-label', $b.text() + ' of 5' ); }
        } );
        $panel.find( '.pcp-pgx-vote-flag' ).attr( 'aria-pressed', 'false' );

        // Scale + option dimensions: single-select; re-click clears.
        $panel.find( '.pcp-pgx-vote-btn, .pcp-pgx-vote-opt' ).on( 'click', function ( e ) {
            e.preventDefault();
            if ( !pgxLoggedIn ) { pgxNeedLogin(); return; }
            var $b = $( this );
            var $dim = $b.closest( '.pcp-pgx-vote-dim' );
            var type = $dim.attr( 'data-flag-type' );
            var value = parseInt( $b.attr( 'data-flag-value' ), 10 );
            var wasActive = $b.hasClass( 'pcp-active' );
            $dim.find( '.pcp-pgx-vote-btn, .pcp-pgx-vote-opt' ).removeClass( 'pcp-active' );
            if ( wasActive ) {
                pgxFlag( $panel, type, value, true );
            } else {
                $b.addClass( 'pcp-active' );
                pgxFlag( $panel, type, value, false );
            }
        } );

        // Single-flag dimensions: toggle.
        $panel.find( '.pcp-pgx-vote-flag' ).on( 'click', function ( e ) {
            e.preventDefault();
            if ( !pgxLoggedIn ) { pgxNeedLogin(); return; }
            var $b = $( this );
            var $dim = $b.closest( '.pcp-pgx-vote-dim' );
            var nowActive = !$b.hasClass( 'pcp-active' );
            $b.toggleClass( 'pcp-active', nowActive )
              .attr( 'aria-pressed', nowActive ? 'true' : 'false' );
            pgxFlag( $panel, $dim.attr( 'data-flag-type' ), 1, !nowActive );
        } );

        // Personal experience: reuses pcp_interaction_reports.
        var expState = { exp: null, val: 0 };
        var $valRow = $panel.find( '.pcp-pgx-vote-valrow' );

        function expSubmit() {
            pgxStatus( $panel, 'Saving...' );
            pgxApi.postWithToken( 'csrf', {
                action: 'pharmacopediainteractionreport',
                element_id: parseInt( $panel.attr( 'data-element-id' ), 10 ),
                perspective: 1,
                experience: expState.exp === null ? '' : expState.exp,
                valence: expState.val,
                format: 'json'
            } ).done( function ( resp ) {
                var d = resp && resp.pharmacopediainteractionreport;
                if ( !d || !d.ok ) { pgxStatus( $panel, '' ); mw.notify( 'Save failed.', { type: 'error' } ); return; }
                pgxStatus( $panel, 'Saved.' );
            } ).fail( function ( code, data ) { pgxFail( $panel, code, data ); } );
        }

        $panel.find( '.pcp-pgx-vote-expbtn' ).on( 'click', function ( e ) {
            e.preventDefault();
            if ( !pgxLoggedIn ) { pgxNeedLogin(); return; }
            var $b = $( this );
            var v = parseInt( $b.attr( 'data-experience' ), 10 );
            if ( expState.exp === v ) {
                expState.exp = null;
                $b.removeClass( 'pcp-active' );
                $valRow.addClass( 'pcp-disabled' );
            } else {
                expState.exp = v;
                $panel.find( '.pcp-pgx-vote-expbtn' ).removeClass( 'pcp-active' );
                $b.addClass( 'pcp-active' );
                $valRow.removeClass( 'pcp-disabled' );
            }
            expSubmit();
        } );

        $panel.find( '.pcp-pgx-vote-vslider' ).on( 'change', function () {
            if ( !pgxLoggedIn ) { pgxNeedLogin(); return; }
            if ( expState.exp === null ) {
                mw.notify( 'Pick an experience level first.', { type: 'info' } );
                return;
            }
            var v = parseInt( $( this ).val(), 10 );
            expState.val = isNaN( v ) ? 0 : v;
            expSubmit();
        } );
    } );
} );

/* pcp-effect-valence-tint: the Rate-panel valence slider's colour
   tracks its value - purple at 0, fading to red at -100 and green at
   +100 - the same valenceColor scale the keyframe valence slider uses.
   Native <input type="range"> + accent-color, so the whole slider
   tints. Self-contained; appended by interface-claude (slider visuals).
   */
( function () {
    'use strict';
    function pcpValenceColor( v ) {
        var n = parseFloat( v );
        if ( isNaN( n ) ) { n = 0; }
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
    function tint( el ) {
        el.style.accentColor = pcpValenceColor( el.value );
    }
    document.addEventListener( 'input', function ( e ) {
        var el = e.target;
        if ( el && el.classList &&
             ( el.classList.contains( 'pcp-effect-vslider' ) ||
               el.classList.contains( 'pcp-ix-vslider' ) ) ) {
            tint( el );
        }
    }, true );
    function tintAll() {
        var sl = document.querySelectorAll( '.pcp-effect-vslider, .pcp-ix-vslider' );
        for ( var i = 0; i < sl.length; i++ ) { tint( sl[ i ] ); }
    }
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', tintAll );
    } else {
        tintAll();
    }
}() );

/* ===== .pcp-rate widget — mouseover/keyboard/touch rate input
   on the 0-5 scale (designer-claude handoff 2026-05-22). One
   component, on problem cards and COMMON USES rows. Hover →
   stars preview-fill to the cursor, click commits via
   action=pharmacopedialikert; keyboard arrows step 0.1; touch
   press-and-drag, lift commits. Aggregate is read from
   data-agg (0-5). ===== */
$( function () {
    if ( !window.mw || !mw.Api ) { return; }
    var rateApi = new mw.Api();
    function clamp( v ) { return Math.max( 0, Math.min( 5, v ) ); }
    function init( w ) {
        var stars = w.querySelector( '.pcp-rate-stars' );
        if ( !stars ) { return; }
        var fill = w.querySelector( '.pcp-rate-fill' );
        var num = w.querySelector( '.pcp-rate-num' );
        var agg = parseFloat( w.getAttribute( 'data-agg' ) ) || 0;
        function show( v ) {
            v = clamp( v );
            fill.style.width = ( v / 5 * 100 ).toFixed( 2 ) + '%';
            num.textContent = v.toFixed( 1 );
            w.setAttribute( 'aria-valuenow', v.toFixed( 2 ) );
            w.setAttribute( 'aria-valuetext', v.toFixed( 1 ) + ' out of 5' );
        }
        function rest() { w.classList.remove( 'is-rating' ); show( agg ); }
        function posToVal( clientX ) {
            var r = stars.getBoundingClientRect();
            return clamp( ( clientX - r.left ) / r.width * 5 );
        }
        function commit( v ) {
            v = clamp( v );
            agg = v;
            w.classList.remove( 'is-rating' );
            w.classList.add( 'is-committed' );
            show( v );
            setTimeout( function () { w.classList.remove( 'is-committed' ); }, 850 );
            var eid = parseInt( w.getAttribute( 'data-element-id' ), 10 );
            if ( !eid ) { return; }
            rateApi.postWithToken( 'csrf', {
                action: 'pharmacopedialikert',
                element_id: eid,
                value: v,
                format: 'json'
            } ).done( function ( resp ) {
                var d = resp && resp.pharmacopedialikert;
                if ( d && d.n != null && d.n > 0 && d.mean != null ) {
                    agg = parseFloat( d.mean );
                    w.setAttribute( 'data-agg', agg.toFixed( 2 ) );
                    w.setAttribute( 'data-agg-n', d.n );
                    show( agg );
                }
            } ).fail( function ( code ) {
                mw.notify( 'Rating failed (' + code + ')', { type: 'error' } );
            } );
        }
        // Expose helpers for the hold-to-expand layer (interface-claude 2026-05-24).
        w._pcpRateShow = show;
        w._pcpRateRest = rest;
        w._pcpRateCommit = commit;
        w._pcpRatePosToVal = posToVal;
        w._pcpRateGetAgg = function () { return agg; };
        w.addEventListener( 'mousemove', function ( e ) {
            if ( w._pcpRateHoldActive ) { return; }
            w.classList.add( 'is-rating' );
            show( posToVal( e.clientX ) );
        } );
        w.addEventListener( 'mouseleave', function () { if ( w._pcpRateHoldActive ) { return; } rest(); } );
        w.addEventListener( 'click', function ( e ) {
            if ( w._pcpRateSuppressNextClick ) { w._pcpRateSuppressNextClick = false; return; }
            if ( w._pcpRateHoldActive ) { return; }
            commit( posToVal( e.clientX ) );
        } );
        w.addEventListener( 'keydown', function ( e ) {
            var cur = parseFloat( w.getAttribute( 'aria-valuenow' ) );
            if ( isNaN( cur ) ) { cur = agg; }
            var d = 0;
            if ( e.key === 'ArrowRight' || e.key === 'ArrowUp' ) { d = 0.1; }
            else if ( e.key === 'ArrowLeft' || e.key === 'ArrowDown' ) { d = -0.1; }
            else if ( e.key === 'Enter' || e.key === ' ' ) {
                e.preventDefault(); commit( cur ); return;
            } else { return; }
            e.preventDefault();
            w.classList.add( 'is-rating' );
            show( cur + d );
        } );
        w.addEventListener( 'touchstart', function ( e ) {
            if ( w._pcpRateHoldActive ) { return; }
            w.classList.add( 'is-rating' );
            show( posToVal( e.touches[ 0 ].clientX ) );
        }, { passive: true } );
        w.addEventListener( 'touchmove', function ( e ) {
            if ( w._pcpRateHoldActive ) { return; }
            e.preventDefault();
            w.classList.add( 'is-rating' );
            show( posToVal( e.touches[ 0 ].clientX ) );
        } );
        w.addEventListener( 'touchend', function ( e ) {
            if ( w._pcpRateHoldActive ) { return; }
            var t = e.changedTouches[ 0 ];
            commit( posToVal( t ? t.clientX : 0 ) );
        } );
    }
    document.querySelectorAll( '.pcp-rate' ).forEach( init );
} );

/* ===== Hold-to-expand layer for Problems-section .pcp-rate
   (designer-claude 2026-05-24). Scope: .pcp-rate inside
   .pcp-problem only; CommonUsesTag rail unchanged.

   Additive: existing mouseover/keyboard/touch tap-to-commit
   continues to work. The hold gesture adds an expanded
   drag-to-rate affordance for precise input on small targets.
   When the hold-expand layer is driving the widget, the
   existing init's pointer-derived handlers short-circuit on
   the per-widget _pcpRateHoldActive / _pcpRateSuppressNextClick
   flags (set just above in the existing init).
   ===== */
$( function () {
    if ( !window.PointerEvent ) { return; }
    var HOLD_MS = 300;
    var CANCEL_PX = 12;
    var MIN_DELTA = 0.2;     // 0.2 out of 5 ≈ 4pt out of 100 (spec: "moved >1 from aggregate")
    var FLASH_MS = 600;
    var COLLAPSE_MS = 160;
    var TARGET_PX = 483;
    var SCALE_MIN = 1.5;
    var SCALE_MAX = 3.0;

    // Voted marker: position the hollow-star mark at the user's vote position
    // and flip button label. Called after a successful commit and on page-load.
    function applyVotedState( w, yourVal, aggVal ) {
        var mark = w.querySelector( '.pcp-rate-your-mark' );
        if ( mark ) { mark.style.left = ( yourVal / 5 * 100 ).toFixed( 2 ) + '%'; }
        w.setAttribute( 'data-voted', '1' );
        w.setAttribute( 'aria-label',
            'Your rating: ' + yourVal.toFixed( 1 ) + ' out of 5. Average: ' + aggVal.toFixed( 1 ) + '.' );
        // Flip button label to Re-rate.
        var eidAttr = w.getAttribute( 'data-element-id' );
        if ( eidAttr ) {
            var btn = document.querySelector( '.pcp-rate-btn[data-for="' + eidAttr + '"]' );
            if ( btn ) { btn.textContent = 'Re-rate'; }
        }
    }

    function attach( w ) {
        // scope: all .pcp-rate widgets (empire-wide hold-to-expand, locked 2026-05-26)
        if ( w.getAttribute( 'data-holdable' ) === '1' ) { return; }
        w.setAttribute( 'data-holdable', '1' );

        var holdTimer = null;
        var pressing = false;
        var expanded = false;
        var startX = 0, startY = 0;
        var aggAtHold = 0;
        var row = null;
        var maxTravelSq = 0;
        var activePointerId = null;
        // Drag-perf cache + rAF throttle (designer-claude 2026-05-26 perf fix).
        // dragLeft / dragWidth are captured at expand time so pointermove
        // never forces a reflow via getBoundingClientRect. latestX + rafPending
        // throttle DOM writes to once per animation frame.
        var dragLeft = 0;
        var dragWidth = 0;
        var latestX = 0;
        var rafPending = false;
        function applyFill() {
            rafPending = false;
            if ( !expanded || !w._pcpRateShow ) { return; }
            var pct = Math.max( 0, Math.min( 1, ( latestX - dragLeft ) / Math.max( dragWidth, 1 ) ) );
            w._pcpRateShow( pct * 5 );
        }

        function enterExpanded() {
            holdTimer = null;
            if ( !pressing ) { return; }
            w.classList.remove( 'pcp-rate-pressing' );
            w.classList.add( 'pcp-rate-expanded', 'pcp-rate-live' );
            var rect = w.getBoundingClientRect();
            var natural = rect.width;
            var scale = Math.max( SCALE_MIN, Math.min( SCALE_MAX, TARGET_PX / natural ) );
            // Viewport-overflow nudge (designer-claude 2026-05-26): on narrow
            // viewports the expanded widget would clip on the right; translateX
            // by enough to keep the right edge 8px inside the viewport, floored
            // at -15px so the shift is always visible when it triggers.
            var expandedW = natural * scale;
            var centerX = rect.left + natural / 2;
            var rightEdge = centerX + expandedW / 2;
            var overflow = rightEdge - ( window.innerWidth - 8 );
            var tx = overflow > 0 ? -Math.max( 15, Math.ceil( overflow ) ) : 0;
            w.style.transform = 'translateX(' + tx + 'px) scale(' + scale.toFixed( 3 ) + ')';
            // Cache the .pcp-rate-stars bounds AFTER the transform so dragLeft /
            // dragWidth reflect the scaled + translated position. pointermove
            // uses these and never re-queries getBoundingClientRect.
            var starsEl = w.querySelector( '.pcp-rate-stars' );
            if ( starsEl ) {
                var sr = starsEl.getBoundingClientRect();
                dragLeft = sr.left;
                dragWidth = sr.width;
            }
            aggAtHold = w._pcpRateGetAgg ? w._pcpRateGetAgg() : ( parseFloat( w.getAttribute( 'data-agg' ) ) || 0 );
            w.setAttribute( 'aria-expanded', 'true' );
            row = w.closest( '.pcp-problem' );
            if ( row ) { row.classList.add( 'pcp-row-problem-rating-expanded' ); }
            // Capture the pointer so move/up route to us even if the thumb leaves the widget.
            if ( activePointerId !== null ) {
                try { w.setPointerCapture( activePointerId ); } catch ( err ) {}
            }
            expanded = true;
            w._pcpRateHoldActive = true;
        }

        function cancelPress() {
            if ( holdTimer ) { clearTimeout( holdTimer ); holdTimer = null; }
            w.classList.remove( 'pcp-rate-pressing' );
            pressing = false;
            activePointerId = null;
        }

        function exitExpanded( commitVal ) {
            w.style.transform = '';
            w.classList.remove( 'pcp-rate-expanded', 'pcp-rate-live' );
            w.classList.add( 'pcp-rate-collapse-fast' );
            w.setAttribute( 'aria-expanded', 'false' );
            if ( row ) { row.classList.remove( 'pcp-row-problem-rating-expanded' ); row = null; }
            setTimeout( function () { w.classList.remove( 'pcp-rate-collapse-fast' ); }, COLLAPSE_MS );

            var committed = false;
            if ( commitVal !== null && commitVal !== undefined &&
                 maxTravelSq >= CANCEL_PX * CANCEL_PX &&
                 Math.abs( commitVal - aggAtHold ) >= MIN_DELTA ) {
                committed = true;
                w.classList.add( 'pcp-rate-committed-flash' );
                setTimeout( function () { w.classList.remove( 'pcp-rate-committed-flash' ); }, FLASH_MS );
                if ( w._pcpRateCommit ) { w._pcpRateCommit( commitVal ); }
                // Update voted split view + persist to localStorage (MVP: avg is optimistic).
                var curAgg = w._pcpRateGetAgg ? w._pcpRateGetAgg() : ( parseFloat( w.getAttribute( 'data-agg' ) ) || 0 );
                applyVotedState( w, commitVal, curAgg );
                try {
                    var lsKey = 'pcp_rating_' + w.getAttribute( 'data-element-id' );
                    localStorage.setItem( lsKey, commitVal.toFixed( 2 ) );
                } catch ( lsErr ) {}
            } else if ( w._pcpRateRest ) {
                w._pcpRateRest();
            }

            if ( activePointerId !== null ) {
                try { w.releasePointerCapture( activePointerId ); } catch ( err ) {}
                activePointerId = null;
            }
            expanded = false;
            pressing = false;
            // Suppress the synthetic click that follows the pointerup so the
            // existing click handler does not double-commit (or cancel-commit).
            w._pcpRateSuppressNextClick = true;
            // Clear hold-active on next tick so existing pointer-derived
            // handlers (mousemove etc.) stop short-circuiting.
            setTimeout( function () { w._pcpRateHoldActive = false; }, 0 );
            return committed;
        }

        w.addEventListener( 'pointerdown', function ( e ) {
            if ( e.pointerType === 'mouse' && e.button !== 0 ) { return; }
            if ( expanded ) {
                // Widget expanded via Rate button; capture pointer so drag tracks correctly.
                activePointerId = e.pointerId;
                startX = e.clientX;
                startY = e.clientY;
                try { w.setPointerCapture( e.pointerId ); } catch ( err2 ) {}
                return;
            }
            if ( !w._pcpRateShow ) { return; }  // existing init not yet attached
            startX = e.clientX;
            startY = e.clientY;
            maxTravelSq = 0;
            activePointerId = e.pointerId;
            w.classList.add( 'pcp-rate-pressing' );
            pressing = true;
            holdTimer = setTimeout( enterExpanded, HOLD_MS );
        } );

        w.addEventListener( 'pointermove', function ( e ) {
            if ( expanded ) {
                w.classList.add( 'pcp-rate-live' );
                latestX = e.clientX;
                if ( !rafPending ) {
                    rafPending = true;
                    requestAnimationFrame( applyFill );
                }
                var edx = e.clientX - startX, edy = e.clientY - startY;
                var esq = edx * edx + edy * edy;
                if ( esq > maxTravelSq ) { maxTravelSq = esq; }
                e.preventDefault();
                return;
            }
            if ( !pressing ) { return; }
            var dx = e.clientX - startX, dy = e.clientY - startY;
            if ( dx * dx + dy * dy > CANCEL_PX * CANCEL_PX ) {
                cancelPress();
            }
        } );

        w.addEventListener( 'pointerup', function ( e ) {
            if ( expanded ) {
                // Use cached bounds (designer-claude 2026-05-26 perf) so commit
                // value derives from the same math the drag preview used.
                var pct = Math.max( 0, Math.min( 1, ( e.clientX - dragLeft ) / Math.max( dragWidth, 1 ) ) );
                exitExpanded( pct * 5 );
            } else if ( pressing ) {
                cancelPress();
                // Short tap: defer to the existing click handler for commit-at-cursor.
            }
        } );

        w.addEventListener( 'pointercancel', function () {
            if ( expanded ) { exitExpanded( null ); }
            else { cancelPress(); }
        } );

        // touchmove preventDefault while expanded (designer-claude 2026-05-26 fix #2):
        // PointerEvent.preventDefault() does not block the underlying touch's scroll
        // intent on mobile Safari; we have to explicitly preventDefault the touchmove
        // when expanded so the browser does not steal the gesture and fire pointercancel.
        // Registered with { passive: false } to make preventDefault effective.
        w.addEventListener( 'touchmove', function ( e ) {
            if ( expanded ) { e.preventDefault(); }
        }, { passive: false } );

        w.addEventListener( 'keydown', function ( e ) {
            if ( !expanded ) { return; }
            if ( e.key === 'Escape' ) {
                e.preventDefault();
                e.stopPropagation();
                exitExpanded( null );
                return;
            }
            if ( e.key === 'Enter' || e.key === ' ' ) {
                e.preventDefault();
                e.stopPropagation();
                var v = parseFloat( w.getAttribute( 'aria-valuenow' ) );
                if ( isNaN( v ) ) { v = w._pcpRateGetAgg ? w._pcpRateGetAgg() : 0; }
                maxTravelSq = CANCEL_PX * CANCEL_PX + 1;
                exitExpanded( v );
                return;
            }
            var step = 0;
            if ( e.key === 'ArrowRight' || e.key === 'ArrowUp' ) { step = 0.2; }
            else if ( e.key === 'ArrowLeft' || e.key === 'ArrowDown' ) { step = -0.2; }
            else { return; }
            e.preventDefault();
            e.stopPropagation();
            var cur = parseFloat( w.getAttribute( 'aria-valuenow' ) );
            if ( isNaN( cur ) ) { cur = w._pcpRateGetAgg ? w._pcpRateGetAgg() : 0; }
            var newVal = Math.max( 0, Math.min( 5, cur + step ) );
            if ( w._pcpRateShow ) {
                w._pcpRateShow( newVal );
                w.classList.add( 'pcp-rate-live' );
            }
        }, true );

        // If the page scrolls while we are pressing-but-not-yet-expanded,
        // cancel cleanly (touch users dragging vertically to scroll).
        var scrollCancel = function () { if ( pressing && !expanded ) { cancelPress(); } };
        window.addEventListener( 'scroll', scrollCancel, { passive: true, capture: true } );

        // Rate button: click triggers expand immediately (no 300ms hold).
        // Pre-seeds maxTravelSq past threshold so a click-and-release in the
        // expanded widget commits (value-delta gate still applies).
        function immediateExpand( clientX, clientY ) {
            if ( expanded || !w._pcpRateShow ) { return; }
            startX = clientX;
            startY = clientY;
            maxTravelSq = CANCEL_PX * CANCEL_PX + 1;
            pressing = true;
            enterExpanded();
        }

        var eid = w.getAttribute( 'data-element-id' );
        if ( eid ) {
            var rateBtn = document.querySelector( '.pcp-rate-btn[data-for="' + eid + '"]' );
            if ( rateBtn ) {
                rateBtn.addEventListener( 'click', function ( e ) {
                    e.stopPropagation();
                    immediateExpand( e.clientX, e.clientY );
                } );
            }
        }

        // Page-load voted state: prefer server-rendered data-user-rating on
        // .pcp-problem ancestor, fall back to localStorage (CommonUses + problems).
        ( function detectVotedOnLoad() {
            var aggAtLoad = parseFloat( w.getAttribute( 'data-agg' ) ) || 0;
            var problem = w.closest ? w.closest( '.pcp-problem' ) : null;
            if ( problem ) {
                var sv = parseFloat( problem.getAttribute( 'data-user-rating' ) );
                if ( !isNaN( sv ) && sv >= 0 ) {
                    applyVotedState( w, sv, aggAtLoad );
                    return;
                }
            }
            if ( eid ) {
                try {
                    var stored = localStorage.getItem( 'pcp_rating_' + eid );
                    if ( stored !== null ) {
                        var sv2 = parseFloat( stored );
                        if ( !isNaN( sv2 ) && sv2 >= 0 ) { applyVotedState( w, sv2, aggAtLoad ); }
                    }
                } catch ( lsErr2 ) {}
            }
        }() );
    }

    document.querySelectorAll( '.pcp-rate' ).forEach( attach );
} );

