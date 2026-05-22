/*!
 * Pharmacopedia share dialog: per-record visibility rule management.
 * Tabs: People (users-type rules), Link (link_token rules), Cohorts (cohort rules).
 *
 * Trigger: any element with class .pcp-share-trigger and data-ns / data-key attrs.
 * Also drives Special:MyCohorts (cohort CRUD).
 */
( function () {
    'use strict';

    var api = new mw.Api();

    // ===== Share dialog =====

    var $modal = null;
    var currentNs = '';
    var currentKey = null;

    function openShareDialog( ns, key, label ) {
        currentNs = ns;
        currentKey = key;
        closeShareDialog();
        $modal = $( '<div class="pcp-share-modal-backdrop">' ).append(
            $( '<div class="pcp-share-modal">' ).append(
                $( '<div class="pcp-share-header">' ).append(
                    $( '<h2>' ).text( 'Share ' + ( label || ns ) ),
                    $( '<button type="button" class="pcp-share-close" aria-label="Close">&times;</button>' )
                ),
                $( '<div class="pcp-share-tabs">' ).append(
                    $( '<button type="button" class="pcp-share-tab active" data-tab="people">People</button>' ),
                    $( '<button type="button" class="pcp-share-tab" data-tab="link">Link</button>' ),
                    $( '<button type="button" class="pcp-share-tab" data-tab="cohorts">Cohorts</button>' )
                ),
                $( '<div class="pcp-share-body">' ).append(
                    renderPeopleTab(),
                    renderLinkTab(),
                    renderCohortsTab()
                )
            )
        );
        $( document.body ).append( $modal );
        if ( window.PCPDatePicker && window.PCPDatePicker.autoInit ) {
            window.PCPDatePicker.autoInit();
        }
        loadRules();
        $modal.find( '.pcp-share-people-input' ).trigger( 'focus' );
    }

    function closeShareDialog() {
        if ( $modal ) { $modal.remove(); $modal = null; }
    }

    function renderPeopleTab() {
        return $( '<div class="pcp-share-pane active" data-pane="people">' ).append(
            $( '<p class="pcp-share-help">Share with specific people by username. They will see this when logged in.</p>' ),
            $( '<div class="pcp-share-rule-opts">' ).append(
                $( '<label>Optional expires (when added users lose access): </label>' ),
                $( '<div class="pcp-date-input pcp-share-people-expires" data-name="_people_expires" data-lock-mode="point"></div>' )
            ),
            $( '<div class="pcp-share-people-search">' ).append(
                $( '<input type="text" class="pcp-share-people-input" placeholder="Type a username..." autocomplete="off" data-1p-ignore="true" data-lpignore="true" data-bwignore="true" data-form-type="other">' ),
                $( '<div class="pcp-share-people-suggest"></div>' )
            ),
            $( '<div class="pcp-share-people-current"><p class="pcp-loading">Loading...</p></div>' ),
            $( '<hr>' ),
            $( '<div class="pcp-share-reciprocal-toggle">' ).append(
                $( '<label>' ).append(
                    $( '<input type="checkbox" class="pcp-share-reciprocal-cb">' ),
                    $( '<span><strong>Auto-share back:</strong> anyone who has shared this same thing with me can see mine too</span>' )
                ),
                $( '<p class="pcp-share-reciprocal-note">Creates a reciprocal rule, symmetric trust without naming individuals.</p>' )
            )
        );
    }

    function renderLinkTab() {
        return $( '<div class="pcp-share-pane" data-pane="link">' ).append(
            $( '<p class="pcp-share-help">Generate a private link. Anyone with the link can see this until you revoke.</p>' ),
            $( '<div class="pcp-share-link-create">' ).append(
                $( '<label>Optional max uses: </label>' ),
                $( '<input type="number" class="pcp-share-link-maxuses" min="1" placeholder="unlimited">' ),
                $( '<label>Optional expires (date/time, optional): </label>' ),
                $( '<div class="pcp-date-input pcp-share-link-expires-picker" data-name="link_expires" data-lock-mode="point"></div>' ),
                $( '<button type="button" class="pcp-btn pcp-share-link-create-btn">Generate link</button>' )
            ),
            $( '<div class="pcp-share-link-current"><p class="pcp-loading">Loading...</p></div>' )
        );
    }

    function renderCohortsTab() {
        return $( '<div class="pcp-share-pane" data-pane="cohorts">' ).append(
            $( '<p class="pcp-share-help">Share with a whole cohort at once. Manage cohorts at <a href="' +
                mw.util.getUrl( 'Special:MyCohorts' ) + '">Special:MyCohorts</a>.</p>' ),
            $( '<div class="pcp-share-rule-opts">' ).append(
                $( '<label>Optional expires: </label>' ),
                $( '<div class="pcp-date-input pcp-share-cohort-expires" data-name="_cohort_expires" data-lock-mode="point"></div>' )
            ),
            $( '<div class="pcp-share-cohort-pick">' ).append(
                $( '<select class="pcp-share-cohort-select"><option value="">Loading cohorts...</option></select>' ),
                $( '<button type="button" class="pcp-btn pcp-share-cohort-add-btn">Share with this cohort</button>' )
            ),
            $( '<div class="pcp-share-cohort-current"><p class="pcp-loading">Loading...</p></div>' )
        );
    }

    function switchTab( tab ) {
        $modal.find( '.pcp-share-tab' ).removeClass( 'active' );
        $modal.find( '.pcp-share-tab[data-tab="' + tab + '"]' ).addClass( 'active' );
        $modal.find( '.pcp-share-pane' ).removeClass( 'active' );
        $modal.find( '.pcp-share-pane[data-pane="' + tab + '"]' ).addClass( 'active' );
        if ( tab === 'cohorts' ) loadOwnedCohorts();
    }

    function loadRules() {
        api.get( {
            action: 'pharmacopediavisrules',
            op: 'list',
            namespace: currentNs,
            key: currentKey || ''
        } ).done( function ( resp ) {
            renderRules( resp.rules || [] );
        } ).fail( function ( e ) {
            $modal.find( '.pcp-share-body .pcp-loading' ).text( 'Error: ' + e );
        } );
    }

    function renderRules( rules ) {
        var $people = $modal.find( '.pcp-share-people-current' ).empty();
        var $link   = $modal.find( '.pcp-share-link-current' ).empty();
        var $cohort = $modal.find( '.pcp-share-cohort-current' ).empty();
        var hasPeople = false, hasLink = false, hasCohort = false;
        $.each( rules, function ( i, r ) {
            if ( r.revoked ) return;
            if ( r.type === 'users' ) {
                hasPeople = true;
                $people.append( renderUsersRule( r ) );
            } else if ( r.type === 'link_token' ) {
                hasLink = true;
                var ownerName = mw.config.get( 'wgUserName' ) || '';
                var url = location.protocol + '//' + location.host +
                    location.pathname +
                    '?user=' + encodeURIComponent( ownerName ) +
                    '&pcpshare=' + encodeURIComponent( r.payload.token || '' );
                $link.append(
                    $( '<div class="pcp-share-rule">' ).append(
                        $( '<input type="text" readonly class="pcp-share-link-url">' ).val( url ),
                        $( '<button type="button" class="pcp-btn pcp-share-copy">Copy</button>' ).on( 'click', function () {
                            this.previousSibling.select(); document.execCommand( 'copy' );
                            $( this ).text( 'Copied!' ).delay( 1500 ).queue( function () { $( this ).text( 'Copy' ).dequeue(); } );
                        } ),
                        $( '<span class="pcp-share-meta">' ).text(
                            ( r.payload.uses_remaining != null ? ( r.payload.uses_remaining + ' uses left ' ) : 'unlimited uses ' ) +
                            ( r.expires ? ' expires ' + r.expires : '' )
                        ),
                        revokeButton( r.id )
                    )
                );
            } else if ( r.type === 'cohort' ) {
                hasCohort = true;
                $cohort.append( renderRuleRow( r, 'Cohort: ' + ( r.payload.cohort_name || ( '#' + r.payload.cohort_id ) ) ) );
            }
        } );
        // Sync the reciprocal checkbox to whether a reciprocal-type rule exists at scope.
        var reciprocal_active = false;
        $.each( rules, function ( i, r ) {
            if ( !r.revoked && r.type === 'reciprocal' &&
                 r.namespace === currentNs &&
                 ( r.key || null ) === ( currentKey || null ) ) {
                reciprocal_active = true; return false;
            }
        } );
        $modal.find( '.pcp-share-reciprocal-cb' ).prop( 'checked', reciprocal_active );
        if ( !hasPeople ) $people.append( $( '<p class="pcp-share-empty">Not shared with any specific people yet.</p>' ) );
        if ( !hasLink )   $link.append(   $( '<p class="pcp-share-empty">No active share links.</p>' ) );
        if ( !hasCohort ) $cohort.append( $( '<p class="pcp-share-empty">Not shared with any cohorts yet.</p>' ) );
    }

    function renderUsersRule( r ) {
        var $row = $( '<div class="pcp-share-rule pcp-share-users-rule">' );
        var $chips = $( '<div class="pcp-share-user-chips">' );
        var users = r.payload.usernames || [];
        if ( !users.length ) {
            $chips.append( $( '<em>' ).text( '(no users)' ) );
        }
        $.each( users, function ( i, u ) {
            $chips.append(
                $( '<span class="pcp-share-user-chip">' ).append(
                    $( '<span class="pcp-share-user-name">' ).text( u.name ),
                    $( '<button type="button" class="pcp-share-user-x" title="Stop sharing with this person">' ).html( '&times;' )
                        .on( 'click', function () { removeUserFromRule( r, u.id ); } )
                )
            );
        } );
        $row.append( $chips, revokeButton( r.id ).attr( 'title', 'Revoke all users at once' ).text( 'Revoke all' ) );
        return $row;
    }

    function removeUserFromRule( r, userId ) {
        var remaining = $.grep( r.payload.user_ids || [], function ( id ) { return id !== userId; } );
        if ( !remaining.length ) {
            // No users left, revoke the whole rule.
            api.postWithToken( 'csrf', {
                action: 'pharmacopediavisrules',
                op: 'revoke',
                rule_id: r.id
            } ).done( loadRules );
        } else {
            api.postWithToken( 'csrf', {
                action: 'pharmacopediavisrules',
                op: 'update',
                rule_id: r.id,
                payload: JSON.stringify( { user_ids: remaining } )
            } ).done( loadRules );
        }
    }

    function renderRuleRow( r, label ) {
        return $( '<div class="pcp-share-rule">' ).append(
            $( '<span class="pcp-share-rule-label">' ).text( label ),
            revokeButton( r.id )
        );
    }

    function revokeButton( ruleId ) {
        return $( '<button type="button" class="pcp-btn pcp-btn-danger pcp-share-revoke">Revoke</button>' ).on( 'click', function () {
            api.postWithToken( 'csrf', {
                action: 'pharmacopediavisrules',
                op: 'revoke',
                rule_id: ruleId
            } ).done( loadRules );
        } );
    }

    // ===== People tab: autocomplete + add =====

    var suggestTimer = null;
    function bindPeopleSearch() {
        // Keyboard nav: down/up through suggestions, Enter picks, Esc closes them.
        $modal.on( 'keydown', '.pcp-share-people-input', function ( e ) {
            var $sug = $modal.find( '.pcp-share-people-suggest' );
            var $items = $sug.find( '.pcp-share-suggest-item' );
            if ( !$items.length ) return;
            var $active = $items.filter( '.pcp-share-suggest-active' );
            var idx = $active.length ? $items.index( $active ) : -1;
            if ( e.key === 'ArrowDown' ) {
                e.preventDefault();
                idx = ( idx + 1 ) % $items.length;
                $items.removeClass( 'pcp-share-suggest-active' ).eq( idx ).addClass( 'pcp-share-suggest-active' )[0].scrollIntoView( { block: 'nearest' } );
            } else if ( e.key === 'ArrowUp' ) {
                e.preventDefault();
                idx = ( idx <= 0 ? $items.length - 1 : idx - 1 );
                $items.removeClass( 'pcp-share-suggest-active' ).eq( idx ).addClass( 'pcp-share-suggest-active' )[0].scrollIntoView( { block: 'nearest' } );
            } else if ( e.key === 'Enter' ) {
                if ( $active.length ) {
                    e.preventDefault();
                    $active.trigger( 'click' );
                }
            } else if ( e.key === 'Escape' ) {
                if ( $items.length ) {
                    e.preventDefault();
                    e.stopPropagation();
                    $sug.empty();
                }
            }
        } );
        $modal.on( 'input', '.pcp-share-people-input', function () {
            var $in = $( this );
            var term = $in.val().trim();
            clearTimeout( suggestTimer );
            var $sug = $modal.find( '.pcp-share-people-suggest' ).empty();
            if ( term.length < 2 ) return;
            suggestTimer = setTimeout( function () {
                api.get( { action: 'pharmacopediausersearch', term: term, limit: 8 } ).done( function ( resp ) {
                    $.each( resp.users || [], function ( i, u ) {
                        $sug.append(
                            $( '<div class="pcp-share-suggest-item">' )
                                .text( u.name + ( u.real_name ? ' (' + u.real_name + ')' : '' ) )
                                .on( 'click', function () { addUserShare( u ); } )
                        );
                    } );
                } );
            }, 220 );
        } );
    }

    function addUserShare( u ) {
        // Find existing users-type rule at this scope (if any) and append; otherwise create.
        api.get( {
            action: 'pharmacopediavisrules',
            op: 'list',
            namespace: currentNs,
            key: currentKey || ''
        } ).done( function ( resp ) {
            var existing = null;
            $.each( resp.rules || [], function ( i, r ) {
                if ( !r.revoked && r.type === 'users' &&
                     r.namespace === currentNs &&
                     ( r.key || null ) === ( currentKey || null ) ) {
                    existing = r;
                    return false;
                }
            } );
            if ( existing ) {
                var ids = ( existing.payload.user_ids || [] ).slice();
                if ( ids.indexOf( u.id ) === -1 ) ids.push( u.id );
                api.postWithToken( 'csrf', {
                    action: 'pharmacopediavisrules',
                    op: 'update',
                    rule_id: existing.id,
                    payload: JSON.stringify( { user_ids: ids } )
                } ).done( afterShareWrite );
            } else {
                api.postWithToken( 'csrf', {
                    action: 'pharmacopediavisrules',
                    op: 'create',
                    type: 'users',
                    namespace: currentNs,
                    key: currentKey || '',
                    payload: JSON.stringify( { user_ids: [ u.id ] } ),
                    expires: readPickerExpires( '.pcp-share-people-expires' )
                } ).done( afterShareWrite );
            }
        } );
    }

    function afterShareWrite() {
        $modal.find( '.pcp-share-people-input' ).val( '' );
        $modal.find( '.pcp-share-people-suggest' ).empty();
        loadRules();
    }

    // ===== Link tab =====

    function bindLinkCreate() {
        $modal.on( 'click', '.pcp-share-link-create-btn', function () {
            var maxUses = $modal.find( '.pcp-share-link-maxuses' ).val();
            // Read the hidden input maintained by PCPDatePicker.
            // It holds JSON like { kind: 'point', point: { parsed: { iso: 'YYYY-MM-DD', ... }, time: { parsed: 'HH:MM:SS' }, ... } }
            var ts = '';
            var raw = $modal.find( '.pcp-share-link-expires-picker input[type=hidden]' ).val();
            if ( raw ) {
                try {
                    var d = JSON.parse( raw );
                    var pt = d && d.point;
                    var iso = pt && pt.parsed && pt.parsed.iso;
                    if ( iso && /^\d{4}-\d{2}-\d{2}$/.test( iso ) ) {
                        var time = pt.time && pt.time.parsed; // HH:MM:SS
                        var t = time && /^\d{2}:\d{2}:\d{2}$/.test( time ) ?
                            time.replace( /:/g, '' ) : '235959';
                        ts = iso.replace( /-/g, '' ) + t;
                    }
                } catch ( e ) { /* malformed, treat as no expiry */ }
            }
            api.postWithToken( 'csrf', {
                action: 'pharmacopediavisrules',
                op: 'newtoken',
                namespace: currentNs,
                key: currentKey || '',
                max_uses: maxUses,
                expires: ts
            } ).done( function ( resp ) {
                var token = resp && resp.token;
                if ( token ) {
                    var ownerName = mw.config.get( 'wgUserName' ) || '';
                    var url = location.protocol + '//' + location.host + location.pathname +
                        '?user=' + encodeURIComponent( ownerName ) +
                        '&pcpshare=' + encodeURIComponent( token );
                    // Copy via temporary textarea so we can do it pre-render of rules.
                    var ta = document.createElement( 'textarea' );
                    ta.value = url; document.body.appendChild( ta );
                    ta.select(); try { document.execCommand( 'copy' ); } catch ( e ) {}
                    document.body.removeChild( ta );
                    var $toast = $( '<div class="pcp-share-toast">Link copied to clipboard!</div>' );
                    $modal.append( $toast );
                    setTimeout( function () { $toast.fadeOut( 400, function () { $toast.remove(); } ); }, 1400 );
                }
                $modal.find( '.pcp-share-link-maxuses' ).val( '' );
                // Reset the picker by clearing its hidden input and re-initing the visible widget.
                var $picker = $modal.find( '.pcp-share-link-expires-picker' );
                $picker.find( 'input[type=hidden]' ).val( '' ).trigger( 'change' );
                $picker.empty().removeAttr( 'data-initial' );
                if ( window.PCPDatePicker && window.PCPDatePicker.init ) {
                    window.PCPDatePicker.init( $picker[0] );
                }
                loadRules();
            } );
        } );
    }

    // ===== Cohort tab =====

    function loadOwnedCohorts() {
        api.get( { action: 'pharmacopediacohorts', op: 'list' } ).done( function ( resp ) {
            var $sel = $modal.find( '.pcp-share-cohort-select' ).empty();
            if ( !resp.cohorts || !resp.cohorts.length ) {
                $sel.append( $( '<option value="">No cohorts yet — create one at Special:MyCohorts</option>' ) );
                return;
            }
            $sel.append( $( '<option value="">Pick a cohort...</option>' ) );
            $.each( resp.cohorts, function ( i, c ) {
                $sel.append( $( '<option>' ).val( c.id ).text( c.name + ' (' + c.member_count + ' members)' ) );
            } );
        } );
    }

    function bindCohortAdd() {
        $modal.on( 'click', '.pcp-share-cohort-add-btn', function () {
            var cid = $modal.find( '.pcp-share-cohort-select' ).val();
            if ( !cid ) return;
            api.postWithToken( 'csrf', {
                action: 'pharmacopediavisrules',
                op: 'create',
                type: 'cohort',
                namespace: currentNs,
                key: currentKey || '',
                payload: JSON.stringify( { cohort_id: parseInt( cid, 10 ) } ),
                expires: readPickerExpires( '.pcp-share-cohort-expires' )
            } ).done( loadRules );
        } );
    }


    function bindReciprocal() {
        $modal.on( 'change', '.pcp-share-reciprocal-cb', function () {
            var on = this.checked;
            api.get( {
                action: 'pharmacopediavisrules',
                op: 'list',
                namespace: currentNs,
                key: currentKey || ''
            } ).done( function ( resp ) {
                var existing = null;
                $.each( resp.rules || [], function ( i, r ) {
                    if ( !r.revoked && r.type === 'reciprocal' &&
                         r.namespace === currentNs &&
                         ( r.key || null ) === ( currentKey || null ) ) {
                        existing = r;
                        return false;
                    }
                } );
                if ( on && !existing ) {
                    api.postWithToken( 'csrf', {
                        action: 'pharmacopediavisrules',
                        op: 'create',
                        type: 'reciprocal',
                        namespace: currentNs,
                        key: currentKey || '',
                        payload: '{}'
                    } ).done( loadRules );
                } else if ( !on && existing ) {
                    api.postWithToken( 'csrf', {
                        action: 'pharmacopediavisrules',
                        op: 'revoke',
                        rule_id: existing.id
                    } ).done( loadRules );
                }
            } );
        } );
    }

    function readPickerExpires( selector ) {
        var raw = $modal.find( selector + ' input[type=hidden]' ).val();
        if ( !raw ) return '';
        try {
            var d = JSON.parse( raw );
            var pt = d && d.point;
            var iso = pt && pt.parsed && pt.parsed.iso;
            if ( !iso || !/^\d{4}-\d{2}-\d{2}$/.test( iso ) ) return '';
            var time = pt.time && pt.time.parsed;
            var t = ( time && /^\d{2}:\d{2}:\d{2}$/.test( time ) ) ?
                time.replace( /:/g, '' ) : '235959';
            return iso.replace( /-/g, '' ) + t;
        } catch ( e ) { return ''; }
    }

    // ===== Trigger binding =====

    $( document ).on( 'click', '.pcp-share-trigger', function ( e ) {
        e.preventDefault();
        var $t = $( this );
        openShareDialog( $t.data( 'ns' ), $t.data( 'key' ) || null, $t.data( 'label' ) || $t.data( 'ns' ) );
        // wire one-shot per-open handlers
        bindPeopleSearch();
        bindLinkCreate();
        bindCohortAdd();
        bindReciprocal();
    } );

    $( document ).on( 'click', '.pcp-share-close, .pcp-share-modal-backdrop', function ( e ) {
        if ( e.target === e.currentTarget ) closeShareDialog();
    } );
    $( document ).on( 'click', '.pcp-share-tab', function () {
        switchTab( $( this ).data( 'tab' ) );
    } );
    $( document ).on( 'keydown', function ( e ) {
        if ( e.key === 'Escape' && $modal ) closeShareDialog();
    } );

    // ===== Special:MyCohorts page logic =====

    function initMyCohorts() {
        if ( !$( '.pcp-mycohorts' ).length ) return;
        loadCohortList();
        $( document ).on( 'click', '.pcp-cohort-create-btn', function () {
            var name = $( '.pcp-cohort-new-name' ).val().trim();
            var desc = $( '.pcp-cohort-new-desc' ).val().trim();
            if ( !name ) return;
            api.postWithToken( 'csrf', {
                action: 'pharmacopediacohorts',
                op: 'create',
                name: name,
                description: desc
            } ).done( function () {
                $( '.pcp-cohort-new-name, .pcp-cohort-new-desc' ).val( '' );
                loadCohortList();
            } );
        } );
    }

    function loadCohortList() {
        var $list = $( '.pcp-cohort-list' );
        if ( !$list.length ) return;
        $list.html( '<p class="pcp-loading">Loading...</p>' );
        api.get( { action: 'pharmacopediacohorts', op: 'list' } ).done( function ( resp ) {
            $list.empty();
            if ( !resp.cohorts || !resp.cohorts.length ) {
                $list.append( $( '<p class="pcp-share-empty">No cohorts yet. Create one above.</p>' ) );
                return;
            }
            $.each( resp.cohorts, function ( i, c ) {
                var $c = $( '<div class="pcp-cohort-item">' ).attr( 'data-cid', c.id ).append(
                    $( '<div class="pcp-cohort-head">' ).append(
                        $( '<strong>' ).text( c.name ),
                        $( '<span class="pcp-cohort-count">' ).text( ' (' + c.member_count + ' members)' ),
                        $( '<button type="button" class="pcp-btn pcp-btn-danger pcp-cohort-delete">Delete</button>' ).on( 'click', function () {
                            var $del = $( this );
                            if ( $del.data( 'pcpConfirmed' ) !== true ) {
                                window.PCPConfirmDelete( 'Delete cohort "' + c.name + '"? This removes it from any share rules.', function () {
                                    $del.data( 'pcpConfirmed', true );
                                    $del.trigger( 'click' );
                                } );
                                return;
                            }
                            $del.removeData( 'pcpConfirmed' );
                            api.postWithToken( 'csrf', {
                                action: 'pharmacopediacohorts',
                                op: 'delete',
                                cohort_id: c.id
                            } ).done( loadCohortList );
                        } )
                    ),
                    c.description ? $( '<p class="pcp-cohort-desc">' ).text( c.description ) : null,
                    $( '<div class="pcp-cohort-members">' ),
                    $( '<div class="pcp-cohort-add-member">' ).append(
                        $( '<input type="text" class="pcp-cohort-add-name" placeholder="Add member by username">' ),
                        $( '<button type="button" class="pcp-btn pcp-cohort-add-btn">Add</button>' ).on( 'click', function () {
                            var u = $c.find( '.pcp-cohort-add-name' ).val().trim();
                            if ( !u ) return;
                            api.postWithToken( 'csrf', {
                                action: 'pharmacopediacohorts',
                                op: 'addmember',
                                cohort_id: c.id,
                                username: u
                            } ).done( function () {
                                $c.find( '.pcp-cohort-add-name' ).val( '' );
                                loadCohortMembers( c.id );
                            } ).fail( function ( e ) { alert( 'Could not add: ' + e ); } );
                        } )
                    )
                );
                $list.append( $c );
                loadCohortMembers( c.id );
            } );
        } );
    }

    function loadCohortMembers( cid ) {
        api.get( { action: 'pharmacopediacohorts', op: 'members', cohort_id: cid } ).done( function ( resp ) {
            var $m = $( '.pcp-cohort-item[data-cid="' + cid + '"] .pcp-cohort-members' ).empty();
            if ( !resp.members || !resp.members.length ) {
                $m.append( $( '<p class="pcp-share-empty">No members yet.</p>' ) );
                return;
            }
            $.each( resp.members, function ( i, mem ) {
                $m.append(
                    $( '<div class="pcp-cohort-member">' ).append(
                        $( '<span>' ).text( mem.name ),
                        $( '<button type="button" class="pcp-btn pcp-btn-danger">Remove</button>' ).on( 'click', function () {
                            api.postWithToken( 'csrf', {
                                action: 'pharmacopediacohorts',
                                op: 'removemember',
                                cohort_id: cid,
                                user_id: mem.user_id
                            } ).done( function () { loadCohortMembers( cid ); } );
                        } )
                    )
                );
            } );
        } );
    }

    $( initMyCohorts );

}() );
