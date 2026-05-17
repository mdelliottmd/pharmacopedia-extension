/*!
 * Pharmacopedia TimePicker — a standalone time-only widget extracted from
 * PCPDatePicker. Accepts fuzzy input ("4p", "noon30", "quarter to 5",
 * "15:30") and stores HH:MM:SS in a hidden input.
 *
 * Usage (markup):
 *   <div class="pcp-time-input" data-name="bedtime" data-initial="22:00:00"></div>
 *
 * Auto-inits on DOMContentLoaded for any .pcp-time-input on the page.
 *
 * Public API:
 *   window.PCPTimePicker.init( element )   // init one widget manually
 *   window.PCPTimePicker.autoInit()        // scan + init all .pcp-time-input
 *   window.PCPTimePicker.parseTime( str )  // returns { h, m, s } or { error: true }
 *   window.PCPTimePicker.formatTime( t )   // returns 'HH:MM:SS' from { h, m, s }
 */
( function ( root ) {
    'use strict';

    // ===== Named-time + word-to-number dictionaries (verbatim from DatePicker) =====

    var _namedTime = {
        'noon': 12, 'midi': 12, 'mediodia': 12, 'mediodía': 12,
        'lunchtime': 12, 'lunch': 12, 'mittag': 12, 'mezzogiorno': 12,
        'midnight': 0, 'minuit': 0, 'medianoche': 0, 'mitternacht': 0, 'mezzanotte': 0,
        'madrugada': 4,
        'dawn': 6, 'sunrise': 6, 'aurora': 6,
        'breakfast': 8, 'matin': 8, 'desayuno': 8,
        'morning': 9, 'mañana': 9, 'manana': 9,
        'siesta': 14,
        'afternoon': 15, 'tarde': 15, 'apresmidi': 15,
        'dusk': 18, 'sunset': 18, 'crepusculo': 18, 'crepúsculo': 18,
        'evening': 19, 'dinner': 19, 'soir': 19, 'cena': 19,
        'night': 21, 'noche': 21, 'nuit': 21, 'nacht': 21,
        'bedtime': 22
    };
    var _namedKeys = Object.keys( _namedTime ).sort( function ( a, b ) { return b.length - a.length; } );
    var _numWords = {
        zero: 0, one: 1, two: 2, three: 3, four: 4, five: 5,
        six: 6, seven: 7, eight: 8, nine: 9, ten: 10,
        eleven: 11, twelve: 12, thirteen: 13, fourteen: 14, fifteen: 15,
        sixteen: 16, seventeen: 17, eighteen: 18, nineteen: 19, twenty: 20,
        twentyone: 21, twentytwo: 22, twentythree: 23, twentyfour: 24, twentyfive: 25,
        twentysix: 26, twentyseven: 27, twentyeight: 28, twentynine: 29,
        thirty: 30, thirtyfive: 35, forty: 40, fourty: 40, fortyfive: 45, fourtyfive: 45,
        fifty: 50, fiftyfive: 55,
        quarter: 15, half: 30
    };

    function _wordToNum( w ) {
        w = ( w || '' ).toLowerCase().replace( /[-_\s]+/g, '' );
        if ( /^\d+$/.test( w ) ) return parseInt( w, 10 );
        return Object.prototype.hasOwnProperty.call( _numWords, w ) ? _numWords[ w ] : null;
    }

    function _amPmAdjust( h, mn, ampm ) {
        if ( ampm ) {
            if ( h < 1 || h > 12 ) return -1;
            if ( ampm[ 0 ] === 'p' ) return ( h === 12 ? 12 : h + 12 );
            return ( h === 12 ? 0 : h );
        }
        if ( h >= 13 && h <= 23 ) return h;
        var bv = h * 100 + mn;
        if ( bv <= 430 ) return ( h === 12 ? 12 : h + 12 );
        return ( h === 12 ? 0 : h );
    }

    function parseTimePhrase( s ) {
        var m;
        m = s.match( /^(\d{1,2}|[a-z-]+) ?o'?clock(?: ?(am|pm|a|p))?$/ );
        if ( m ) {
            var h0 = _wordToNum( m[ 1 ] );
            if ( h0 === null || h0 < 0 || h0 > 23 ) return null;
            var h = _amPmAdjust( h0, 0, m[ 2 ] );
            if ( h < 0 || h > 23 ) return { error: true, raw: s };
            return { h: h, m: 0, s: 0, raw: s };
        }
        m = s.match( /^(quarter|half|\d{1,2}|[a-z-]+)(?: minutes?)? (?:past|after) (\d{1,2}|[a-z-]+)(?: ?(am|pm|a|p))?$/ );
        if ( m ) {
            var mn = _wordToNum( m[ 1 ] );
            var h0b = _wordToNum( m[ 2 ] );
            if ( mn === null || h0b === null || mn > 59 || h0b < 0 || h0b > 23 ) return null;
            var hb = _amPmAdjust( h0b, mn, m[ 3 ] );
            if ( hb < 0 || hb > 23 ) return { error: true, raw: s };
            return { h: hb, m: mn, s: 0, raw: s };
        }
        m = s.match( /^(quarter|\d{1,2}|[a-z-]+)(?: minutes?)? (?:to|till|until|of|before) (\d{1,2}|[a-z-]+)(?: ?(am|pm|a|p))?$/ );
        if ( m ) {
            var mins = _wordToNum( m[ 1 ] );
            var refH0 = _wordToNum( m[ 2 ] );
            if ( mins === null || refH0 === null || mins > 59 || refH0 < 0 || refH0 > 23 ) return null;
            var refH = _amPmAdjust( refH0, 0, m[ 3 ] );
            if ( refH < 0 || refH > 23 ) return { error: true, raw: s };
            var hOut = refH - 1; if ( hOut < 0 ) hOut = 23;
            return { h: hOut, m: 60 - mins, s: 0, raw: s };
        }
        m = s.match( /^(\d{1,2})(?::(\d{1,2}))?(?::(\d{1,2}))? (?:in the (morning|afternoon|evening)|at night)$/ );
        if ( m ) {
            var hp = parseInt( m[ 1 ], 10 );
            var mp = m[ 2 ] ? parseInt( m[ 2 ], 10 ) : 0;
            var sp = m[ 3 ] ? parseInt( m[ 3 ], 10 ) : 0;
            var period = m[ 4 ] || 'night';
            if ( hp < 1 || hp > 12 || mp > 59 || sp > 59 ) return { error: true, raw: s };
            if ( period === 'morning' ) { if ( hp === 12 ) hp = 0; }
            else                        { if ( hp !== 12 ) hp += 12; }
            return { h: hp, m: mp, s: sp, raw: s };
        }
        return null;
    }

    function parseTime( s ) {
        s = ( s || '' ).trim().toLowerCase();
        if ( !s ) return null;
        s = s.replace( /\./g, '' );
        s = s.replace( /^\(?(la|el|the|at|le|al|il|der|das|die)\)?\s+/, '' );
        var phr = parseTimePhrase( s.replace( /\s+/g, ' ' ) );
        if ( phr ) return phr;
        s = s.replace( /\s+/g, '' );
        for ( var i = 0; i < _namedKeys.length; i++ ) {
            var k = _namedKeys[ i ];
            if ( !s.startsWith( k ) ) continue;
            var rest = s.slice( k.length );
            var m2 = rest.match( /^(\d{0,4})(am|pm|a|p)?$/ );
            if ( !m2 ) continue;
            var baseHour = _namedTime[ k ];
            var digits = m2[ 1 ] || '';
            var mn = 0, sec = 0;
            if ( digits.length === 1 || digits.length === 2 ) mn = parseInt( digits, 10 );
            else if ( digits.length === 3 ) { mn = parseInt( digits.slice( 0, 1 ), 10 ); sec = parseInt( digits.slice( 1 ), 10 ); }
            else if ( digits.length === 4 ) { mn = parseInt( digits.slice( 0, 2 ), 10 ); sec = parseInt( digits.slice( 2 ), 10 ); }
            if ( m2[ 2 ] ) return { error: true, raw: s };
            if ( mn > 59 || sec > 59 ) return { error: true, raw: s };
            return { h: baseHour, m: mn, s: sec, raw: s };
        }
        var m = s.match( /^(\d{1,6})(?::(\d{1,2}))?(?::(\d{1,2}))?(am|pm|a|p)?$/ );
        if ( !m ) return { error: true, raw: s };
        var ampm = m[ 4 ];
        var hadColon = m[ 2 ] !== undefined;
        var h, mn2, sec2;
        if ( hadColon ) {
            h = parseInt( m[ 1 ], 10 ); mn2 = parseInt( m[ 2 ], 10 ); sec2 = m[ 3 ] ? parseInt( m[ 3 ], 10 ) : 0;
        } else {
            var d = m[ 1 ];
            if ( d.length <= 2 )      { h = parseInt( d, 10 ); mn2 = 0; sec2 = 0; }
            else if ( d.length === 3 ){ h = parseInt( d.slice( 0, 1 ), 10 ); mn2 = parseInt( d.slice( 1 ), 10 ); sec2 = 0; }
            else if ( d.length === 4 ){ h = parseInt( d.slice( 0, 2 ), 10 ); mn2 = parseInt( d.slice( 2 ), 10 ); sec2 = 0; }
            else if ( d.length === 5 ){ h = parseInt( d.slice( 0, 1 ), 10 ); mn2 = parseInt( d.slice( 1, 3 ), 10 ); sec2 = parseInt( d.slice( 3 ), 10 ); }
            else                       { h = parseInt( d.slice( 0, 2 ), 10 ); mn2 = parseInt( d.slice( 2, 4 ), 10 ); sec2 = parseInt( d.slice( 4 ), 10 ); }
        }
        if ( ampm ) {
            if ( h < 1 || h > 12 ) return { error: true, raw: s };
            if ( ampm[ 0 ] === 'p' ) h = ( h === 12 ? 12 : h + 12 );
            else                     h = ( h === 12 ? 0 : h );
        } else if ( !hadColon ) {
            var bareVal = h * 100 + mn2;
            if ( h >= 13 && h <= 23 ) { /* keep */ }
            else if ( bareVal <= 430 ) { if ( h !== 12 ) h += 12; }
            else                        { if ( h === 12 ) h = 0; }
        }
        if ( h > 23 || mn2 > 59 || sec2 > 59 ) return { error: true, raw: s };
        return { h: h, m: mn2, s: sec2, raw: s };
    }

    function formatTime( t ) {
        if ( !t || t.error ) return '';
        return String( t.h ).padStart( 2, '0' ) + ':' + String( t.m ).padStart( 2, '0' ) + ':' + String( t.s ).padStart( 2, '0' );
    }

    // ===== Widget =====

    var _widgetCounter = 0;

    function initTimePicker( rootEl ) {
        if ( !rootEl || rootEl.dataset.pcptInited === '1' ) return;
        rootEl.dataset.pcptInited = '1';

        var widgetId   = 'pcpt' + ( ++_widgetCounter );
        var hiddenName = rootEl.dataset.name || ( 'time_input_' + widgetId );
        var initial    = rootEl.dataset.initial || '';

        rootEl.innerHTML =
            '<input type="hidden" class="pcp-time-hidden">' +
            '<input type="text" class="pcp-time-text" placeholder="4p · 14:30 · noon30 · quarter to 5" autocomplete="off">' +
            '<div class="pcp-time-preview"></div>';

        var hiddenEl = rootEl.querySelector( '.pcp-time-hidden' );
        var textEl   = rootEl.querySelector( '.pcp-time-text' );
        var prevEl   = rootEl.querySelector( '.pcp-time-preview' );
        hiddenEl.name = hiddenName;

        if ( initial ) {
            // initial is a stored HH:MM:SS string; show it raw, and pre-fill hidden.
            textEl.value = initial.replace( /:00$/, '' );
            hiddenEl.value = initial;
            var iso = initial.match( /^(\d{2}):(\d{2})(?::(\d{2}))?$/ );
            if ( iso ) {
                prevEl.textContent = '→ ' + iso[ 1 ] + ':' + iso[ 2 ] + ':' + ( iso[ 3 ] || '00' );
            }
        }

        function refresh() {
            var raw = textEl.value;
            if ( !raw.trim() ) {
                prevEl.textContent = '';
                prevEl.classList.remove( 'bad' );
                hiddenEl.value = '';
                hiddenEl.dispatchEvent( new Event( 'change', { bubbles: true } ) );
                return;
            }
            var p = parseTime( raw );
            if ( !p || p.error ) {
                prevEl.textContent = '? not recognized';
                prevEl.classList.add( 'bad' );
                hiddenEl.value = '';
            } else {
                var formatted = formatTime( p );
                prevEl.textContent = '→ ' + formatted;
                prevEl.classList.remove( 'bad' );
                hiddenEl.value = formatted;
            }
            hiddenEl.dispatchEvent( new Event( 'change', { bubbles: true } ) );
        }

        textEl.addEventListener( 'input', refresh );
        textEl.addEventListener( 'blur', refresh );
    }

    function autoInit() {
        var els = document.querySelectorAll( '.pcp-time-input' );
        for ( var i = 0; i < els.length; i++ ) initTimePicker( els[ i ] );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', autoInit );
    } else {
        autoInit();
    }

    root.PCPTimePicker = {
        init:        initTimePicker,
        autoInit:    autoInit,
        parseTime:   parseTime,
        formatTime:  formatTime
    };

}( window ) );
