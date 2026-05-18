/**
 * pcp-date-input — reusable date/time/timezone widget with Point/Range/Possibility modes.
 *
 * Drop-in markup:
 *   <div class="pcp-date-input" data-name="event_date"></div>
 *   <div class="pcp-date-input" data-name="event_date"
 *        data-initial='{"kind":"point","point":{...}}'></div>
 *
 * On init each widget injects a hidden <input name="<data-name>"> that holds the
 * serialized JSON. Submit the form normally; server receives the JSON string.
 *
 * Public API:
 *   window.PCPDatePicker.init( element ) — manually initialize one
 */
( function ( root ) {
    'use strict';

    var DEMO_BIRTH_YEAR = 1990; // used by "around age N" — overridable via window.PCPDatePickerBirthYear

    // ====================================================================
    //  FUZZY DATE PARSER
    // ====================================================================
    var MONTHS = [ 'jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec',
                   'january','february','march','april','may','june','july','august','september','october','november','december' ];
    var SEASONS = {
        spring: { month: 4, day: 1, label: 'Spring' },
        summer: { month: 7, day: 1, label: 'Summer' },
        fall:   { month: 10, day: 1, label: 'Fall' },
        autumn: { month: 10, day: 1, label: 'Autumn' },
        winter: { month: 1, day: 1, label: 'Winter' }
    };
    function parseFuzzy( text ) {
        text = ( text || '' ).trim();
        if ( !text ) return null;
        var m;
        m = text.match( /^(around|approx(?:imately)?|circa|~)?\s*age\s+(\d{1,3})/i );
        if ( m ) {
            var age = parseInt( m[ 2 ], 10 );
            var birth = root.PCPDatePickerBirthYear || DEMO_BIRTH_YEAR;
            var yr = birth + age;
            return { kind: 'fuzzy', precision: 'approx-age', display: 'around age ' + age,
                     year: yr, month: 1, day: 1, iso: yr + '-01-01' };
        }

        // Age-relative shorthand: "11yo", "6mo", "3y2m", "11 years old",
        // "6 weeks 3 days old". Resolves against window.PCPDatePickerBirthday
        // (YYYY-MM-DD) when set, else falls back to PCPDatePickerBirthYear with
        // a Jan-1 anchor. Returns a fuzzy result whose iso is the start of the
        // implied range; iso_end carries the inclusive end.
        // Units: y/yr/yrs/year/years/yo, mo/mos/month/months, w/wk/wks/week/weeks, d/day/days.
        // Bare "m" is intentionally NOT accepted (ambiguous with minutes); use "mo".
        var AGE_UNIT_RE = '(?:yo|years?|yrs?|y|months?|mos?|weeks?|wks?|w|days?|d)';
        var ageFullRe = new RegExp(
            '^\\d+\\s*' + AGE_UNIT_RE +
            '(?:\\s*(?:and\\s+)?\\d+\\s*' + AGE_UNIT_RE + ')*' +
            '(?:\\s+old)?$', 'i'
        );
        if ( ageFullRe.test( text ) ) {
            var tokenRe = new RegExp( '(\\d+)\\s*' + AGE_UNIT_RE, 'gi' );
            var tok, years = 0, months = 0, days = 0;
            var sawYear = false, sawMonth = false, sawWeek = false, sawDay = false;
            var bareParts = [];
            while ( ( tok = tokenRe.exec( text ) ) !== null ) {
                var n = parseInt( tok[ 1 ], 10 );
                var unit = tok[ 0 ].replace( /^\d+\s*/, '' ).toLowerCase();
                if ( /^(yo|years?|yrs?|y)$/.test( unit ) ) {
                    years += n; sawYear = true;
                    bareParts.push( n + ' year'  + ( n === 1 ? '' : 's' ) );
                } else if ( /^(months?|mos?)$/.test( unit ) ) {
                    months += n; sawMonth = true;
                    bareParts.push( n + ' month' + ( n === 1 ? '' : 's' ) );
                } else if ( /^(weeks?|wks?|w)$/.test( unit ) ) {
                    days += n * 7; sawWeek = true;
                    bareParts.push( n + ' week'  + ( n === 1 ? '' : 's' ) );
                } else if ( /^(days?|d)$/.test( unit ) ) {
                    days += n; sawDay = true;
                    bareParts.push( n + ' day'   + ( n === 1 ? '' : 's' ) );
                }
            }
            var precUnit = sawDay ? 'day' : sawWeek ? 'week' : sawMonth ? 'month' : sawYear ? 'year' : null;
            if ( precUnit ) {
                var bdStr = root.PCPDatePickerBirthday || null;
                var startD;
                if ( bdStr && /^\d{4}-\d{2}-\d{2}$/.test( bdStr ) ) {
                    var bp = bdStr.split( '-' );
                    startD = new Date( +bp[ 0 ], +bp[ 1 ] - 1, +bp[ 2 ] );
                } else {
                    var by = root.PCPDatePickerBirthYear || DEMO_BIRTH_YEAR;
                    startD = new Date( by, 0, 1 );
                }
                startD.setFullYear( startD.getFullYear() + years );
                startD.setMonth( startD.getMonth() + months );
                startD.setDate( startD.getDate() + days );
                var endD = new Date( startD );
                if ( precUnit === 'year' )       endD.setFullYear( endD.getFullYear() + 1 );
                else if ( precUnit === 'month' ) endD.setMonth( endD.getMonth() + 1 );
                else if ( precUnit === 'week' )  endD.setDate( endD.getDate() + 7 );
                else                              endD.setDate( endD.getDate() + 1 );
                endD.setDate( endD.getDate() - 1 );
                var fmt = function ( d ) {
                    return d.getFullYear() + '-' +
                           String( d.getMonth() + 1 ).padStart( 2, '0' ) + '-' +
                           String( d.getDate() ).padStart( 2, '0' );
                };
                return {
                    kind: 'fuzzy',
                    precision: 'age-' + precUnit,
                    display: 'when ' + bareParts.join( ' ' ) + ' old',
                    year: startD.getFullYear(),
                    month: startD.getMonth() + 1,
                    day: startD.getDate(),
                    iso: fmt( startD ),
                    iso_end: fmt( endD )
                };
            }
        }
        m = text.match( /^(spring|summer|fall|autumn|winter)\s+(\d{4})/i );
        if ( m ) {
            var s = SEASONS[ m[ 1 ].toLowerCase() ];
            var year = parseInt( m[ 2 ], 10 );
            return { kind: 'fuzzy', precision: 'season', display: s.label + ' ' + year,
                     year: year, month: s.month, day: s.day,
                     iso: year + '-' + String( s.month ).padStart( 2, '0' ) + '-' + String( s.day ).padStart( 2, '0' ) };
        }
        m = text.match( /^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/ );
        if ( m ) {
            return { kind: 'point', precision: 'day', display: null,
                     year: +m[ 1 ], month: +m[ 2 ], day: +m[ 3 ],
                     iso: m[ 1 ] + '-' + String( +m[ 2 ] ).padStart( 2, '0' ) + '-' + String( +m[ 3 ] ).padStart( 2, '0' ) };
        }
        m = text.match( /^(\d{4})-(\d{1,2})$/ );
        if ( m ) {
            return { kind: 'point', precision: 'month', display: null,
                     year: +m[ 1 ], month: +m[ 2 ], day: 1,
                     iso: m[ 1 ] + '-' + String( +m[ 2 ] ).padStart( 2, '0' ) + '-01' };
        }
        m = text.match( /^(\d{4})$/ );
        if ( m ) {
            return { kind: 'point', precision: 'year', display: null,
                     year: +m[ 1 ], month: 1, day: 1, iso: m[ 1 ] + '-01-01' };
        }
        m = text.match( /^(\d{4})s$/ );
        if ( m ) {
            var y = +m[ 1 ];
            return { kind: 'fuzzy', precision: 'decade', display: y + 's',
                     year: y, month: 1, day: 1, iso: y + '-01-01' };
        }
        m = text.match( /^(\d{1,2})?\s*([a-z]+)\s*(\d{1,2})?\s*(\d{4})$/i );
        if ( m ) {
            var mIdx = MONTHS.indexOf( m[ 2 ].toLowerCase() );
            if ( mIdx !== -1 ) {
                var mon = ( mIdx % 12 ) + 1;
                var day = m[ 1 ] ? +m[ 1 ] : ( m[ 3 ] ? +m[ 3 ] : null );
                var yr2 = +m[ 4 ];
                if ( day ) {
                    return { kind: 'point', precision: 'day', display: null,
                             year: yr2, month: mon, day: day,
                             iso: yr2 + '-' + String( mon ).padStart( 2, '0' ) + '-' + String( day ).padStart( 2, '0' ) };
                }
                return { kind: 'point', precision: 'month', display: null,
                         year: yr2, month: mon, day: 1,
                         iso: yr2 + '-' + String( mon ).padStart( 2, '0' ) + '-01' };
            }
        }
        return null;
    }

    // ====================================================================
    //  TIME PARSER
    // ====================================================================
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

    // ====================================================================
    //  TIMEZONES
    // ====================================================================
    function offsetMinutes( zone, date ) {
        try {
            var fmt = new Intl.DateTimeFormat( 'en-US', { timeZone: zone, timeZoneName: 'longOffset' } );
            var parts = fmt.formatToParts( date );
            var off = ( parts.find( function ( p ) { return p.type === 'timeZoneName'; } ) || {} ).value || 'GMT+0:00';
            var m = off.match( /GMT([+-])(\d{1,2}):?(\d{2})?/ );
            if ( !m ) return 0;
            return ( m[ 1 ] === '-' ? -1 : 1 ) * ( parseInt( m[ 2 ], 10 ) * 60 + parseInt( m[ 3 ] || '0', 10 ) );
        } catch ( e ) { return 0; }
    }
    function formatOffset( min ) {
        if ( min === 0 ) return 'UTC';
        var sign = min < 0 ? '-' : '+';
        var abs = Math.abs( min );
        var h = Math.floor( abs / 60 );
        var m = abs % 60;
        return 'UTC ' + sign + h + ( m ? ':' + String( m ).padStart( 2, '0' ) : '' );
    }
    var tzPreferred = {
        '0,0':       'UTC',
        '-300,-240': 'America/New_York', '-360,-300': 'America/Chicago',
        '-420,-360': 'America/Denver',   '-480,-420': 'America/Los_Angeles',
        '-420,-420': 'America/Phoenix',  '-540,-480': 'America/Anchorage',
        '-600,-600': 'Pacific/Honolulu', '-240,-180': 'America/Halifax',
        '-210,-150': 'America/St_Johns',
        '0,60':      'Europe/London',    '60,120':    'Europe/Berlin',
        '120,180':   'Europe/Athens',    '180,180':   'Europe/Moscow',
        '210,210':   'Asia/Tehran',      '240,240':   'Asia/Dubai',
        '270,270':   'Asia/Kabul',       '300,300':   'Asia/Karachi',
        '330,330':   'Asia/Kolkata',     '345,345':   'Asia/Kathmandu',
        '360,360':   'Asia/Dhaka',       '420,420':   'Asia/Bangkok',
        '480,480':   'Asia/Shanghai',    '540,540':   'Asia/Tokyo',
        '570,570':   'Australia/Adelaide',
        '660,600':   'Australia/Sydney', '600,600':   'Australia/Brisbane',
        '780,720':   'Pacific/Auckland'
    };
    var _tzCache = null;
    function getTzEntries() {
        if ( _tzCache ) return _tzCache;
        var zones;
        try { zones = Intl.supportedValuesOf( 'timeZone' ); }
        catch ( e ) { zones = [ 'UTC','America/Los_Angeles','America/New_York','Europe/London','Asia/Tokyo' ]; }
        var userTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        var winter = new Date( 2024, 0, 15 ), summer = new Date( 2024, 6, 15 ), today = new Date();
        var groups = {};
        zones.forEach( function ( z ) {
            var w = offsetMinutes( z, winter ), s = offsetMinutes( z, summer );
            var key = w + ',' + s;
            if ( !groups[ key ] ) groups[ key ] = { zones: [] };
            groups[ key ].zones.push( z );
        } );
        var entries = [];
        Object.keys( groups ).forEach( function ( key ) {
            var g = groups[ key ];
            var pref = tzPreferred[ key ];
            var ex = ( pref && g.zones.indexOf( pref ) !== -1 ) ? pref : g.zones.slice().sort()[ 0 ];
            var curOff = offsetMinutes( ex, today );
            entries.push( {
                value: ex,
                label: ex.split( '/' ).pop().replace( /_/g, ' ' ) + ' (' + formatOffset( curOff ) + ')',
                sortKey: curOff,
                userMatched: g.zones.indexOf( userTz ) !== -1
            } );
        } );
        entries.sort( function ( a, b ) { return ( a.sortKey - b.sortKey ) || a.label.localeCompare( b.label ); } );
        _tzCache = entries;
        return entries;
    }

    // ====================================================================
    //  WIDGET FACTORY — one per .pcp-date-input element
    // ====================================================================
    var _widgetCounter = 0;

    function initWidget( rootEl ) {
        if ( rootEl.dataset.pcpdtInited === '1' ) return;
        rootEl.dataset.pcpdtInited = '1';

        var widgetId = 'pcpdt' + ( ++_widgetCounter );
        var hiddenName = rootEl.dataset.name || ( 'date_input_' + widgetId );
        var lockMode = rootEl.dataset.lockMode || null; // 'point' | 'range' | 'possibility'

        var initial = null;
        if ( rootEl.dataset.initial ) {
            try { initial = JSON.parse( rootEl.dataset.initial ); } catch ( e ) { initial = null; }
        }

        var startingMode = lockMode || ( initial && initial.kind ? initial.kind : 'point' );
        var state = {
            mode: startingMode,
            possibilityCount: ( initial && initial.kind === 'possibility' && initial.options )
                ? Math.max( 2, initial.options.length ) : 2,
            initial: initial,
            fieldState: {}, // prefix → { view: {year,month}, selected: 'YYYY-MM-DD'|null }
            rangeStart: null, rangeEnd: null,
            optModes: [],   // i → 'date' | 'range'
            optRange: {}    // prefix (e.g. 'opt0') → { start, end }
        };

        // Build top-level structure: hidden input + mode toggle + fields container
        rootEl.innerHTML =
            '<input type="hidden" class="pcp-dt-hidden" name="' + escapeHtml( hiddenName ) + '">' +
            '<div class="pcp-dt-mode-toggle">' +
                '<button type="button" data-mode="point">point</button>' +
                '<button type="button" data-mode="range">range</button>' +
                '<button type="button" data-mode="possibility">possibility</button>' +
            '</div>' +
            '<div class="pcp-dt-fields"></div>';

        var hiddenEl   = rootEl.querySelector( '.pcp-dt-hidden' );
        var toggleEl   = rootEl.querySelector( '.pcp-dt-mode-toggle' );
        var fieldsEl   = rootEl.querySelector( '.pcp-dt-fields' );

        if ( lockMode ) {
            toggleEl.style.display = 'none';
        }
        toggleEl.querySelectorAll( 'button' ).forEach( function ( b ) {
            b.addEventListener( 'click', function () {
                if ( lockMode ) return; // ignore mode changes when locked
                state.mode = b.dataset.mode;
                if ( state.mode === 'possibility' ) {
                    state.possibilityCount = 2;
                    state.optModes = [];
                    state.optRange = {};
                }
                renderMode();
            } );
        } );

        function setActiveToggle() {
            toggleEl.querySelectorAll( 'button' ).forEach( function ( b ) {
                b.classList.toggle( 'active', b.dataset.mode === state.mode );
            } );
        }

        function fieldHtmlInner( prefix ) {
            return '' +
                '<div class="pcp-dt-row">' +
                    '<div class="pcp-dt-col pcp-dt-col-date">' +
                        '<label>Date</label>' +
                        '<input type="text" class="pcp-dt-text" data-prefix="' + prefix + '" autocomplete="off"' +
                            ' placeholder="summer 2008 · 2015-03-04 · around age 14">' +
                        '<div class="pcp-dt-cal" data-prefix="' + prefix + '"></div>' +
                    '</div>' +
                    '<div class="pcp-dt-col pcp-dt-col-time">' +
                        '<label>Time</label>' +
                        '<input type="text" class="pcp-dt-time" data-prefix="' + prefix + '" autocomplete="off"' +
                            ' placeholder="4p · 153021 · noon30">' +
                        '<div class="pcp-dt-time-prev" data-prefix="' + prefix + '"></div>' +
                    '</div>' +
                    '<div class="pcp-dt-col pcp-dt-col-tz">' +
                        '<label>Timezone</label>' +
                        '<select class="pcp-dt-tz" data-prefix="' + prefix + '"></select>' +
                    '</div>' +
                '</div>';
        }

        function fieldHtml( prefix, label, removable ) {
            return '' +
                '<div class="pcp-dt-field" data-prefix="' + prefix + '">' +
                    '<div class="pcp-dt-field-head">' +
                        '<span class="pcp-dt-field-label">' + escapeHtml( label ) + '</span>' +
                        ( removable ? '<button type="button" class="pcp-dt-rm" data-prefix="' + prefix + '">remove</button>' : '' ) +
                    '</div>' +
                    '<div class="pcp-dt-row">' +
                        '<div class="pcp-dt-col pcp-dt-col-date">' +
                            '<label>Date</label>' +
                            '<input type="text" class="pcp-dt-text" data-prefix="' + prefix + '" autocomplete="off"' +
                                ' placeholder="summer 2008 · 2015-03-04 · around age 14">' +
                            '<div class="pcp-dt-cal" data-prefix="' + prefix + '"></div>' +
                        '</div>' +
                        '<div class="pcp-dt-col pcp-dt-col-time">' +
                            '<label>Time</label>' +
                            '<input type="text" class="pcp-dt-time" data-prefix="' + prefix + '" autocomplete="off"' +
                                ' placeholder="4p · 153021 · noon30">' +
                            '<div class="pcp-dt-time-prev" data-prefix="' + prefix + '"></div>' +
                        '</div>' +
                        '<div class="pcp-dt-col pcp-dt-col-tz">' +
                            '<label>Timezone</label>' +
                            '<select class="pcp-dt-tz" data-prefix="' + prefix + '"></select>' +
                        '</div>' +
                    '</div>' +
                '</div>';
        }

        function populateTzSelect( sel ) {
            getTzEntries().forEach( function ( e ) {
                var opt = document.createElement( 'option' );
                opt.value = e.value; opt.textContent = e.label;
                if ( e.userMatched ) opt.selected = true;
                sel.appendChild( opt );
            } );
        }

        function wireField( prefix, initialField ) {
            state.fieldState[ prefix ] = {
                view: { year: new Date().getFullYear(), month: new Date().getMonth() + 1 },
                selected: null
            };
            var textEl = rootEl.querySelector( '.pcp-dt-text[data-prefix="' + prefix + '"]' );
            var calEl  = rootEl.querySelector( '.pcp-dt-cal[data-prefix="'  + prefix + '"]' );
            var timeEl = rootEl.querySelector( '.pcp-dt-time[data-prefix="' + prefix + '"]' );
            var tzEl   = rootEl.querySelector( '.pcp-dt-tz[data-prefix="'   + prefix + '"]' );
            populateTzSelect( tzEl );

            // Pre-fill from initialField if provided
            if ( initialField ) {
                if ( initialField.raw_text )  textEl.value = initialField.raw_text;
                if ( initialField.time && initialField.time.raw ) timeEl.value = initialField.time.raw;
                if ( initialField.timezone ) {
                    var opt = Array.prototype.find.call( tzEl.options, function ( o ) { return o.value === initialField.timezone; } );
                    if ( opt ) opt.selected = true;
                }
                if ( initialField.parsed && initialField.parsed.year ) {
                    state.fieldState[ prefix ].view  = { year: initialField.parsed.year, month: initialField.parsed.month || 1 };
                    state.fieldState[ prefix ].selected = initialField.parsed.iso || null;
                }
            }

            function renderCal() {
                var st = state.fieldState[ prefix ];
                var y = st.view.year, mo = st.view.month;
                var firstDow = new Date( y, mo - 1, 1 ).getDay();
                var daysInMonth = new Date( y, mo, 0 ).getDate();
                var daysInPrev  = new Date( y, mo - 1, 0 ).getDate();
                var monthName = new Date( y, mo - 1, 1 ).toLocaleString( 'en', { month: 'long' } );
                var h = '';
                h += '<div class="pcp-dt-cal-head">' +
                       '<button type="button" data-d="-1">‹</button>' +
                       '<span class="pcp-dt-cal-title">' + monthName + ' ' + y + '</span>' +
                       '<button type="button" data-d="1">›</button>' +
                     '</div><div class="pcp-dt-cal-grid">';
                [ 'S','M','T','W','T','F','S' ].forEach( function ( d ) { h += '<div class="pcp-dt-cal-dow">' + d + '</div>'; } );
                var todayIso = new Date().toISOString().slice( 0, 10 );
                for ( var i = firstDow - 1; i >= 0; i-- ) {
                    var d0 = daysInPrev - i;
                    h += '<div class="pcp-dt-cal-cell other-month" data-d="' + y + '-' + String( mo - 1 ).padStart( 2, '0' ) + '-' + String( d0 ).padStart( 2, '0' ) + '">' + d0 + '</div>';
                }
                for ( var d = 1; d <= daysInMonth; d++ ) {
                    var iso = y + '-' + String( mo ).padStart( 2, '0' ) + '-' + String( d ).padStart( 2, '0' );
                    var cls = [ 'pcp-dt-cal-cell' ];
                    if ( iso === todayIso ) cls.push( 'today' );
                    if ( iso === st.selected ) cls.push( 'selected' );
                    h += '<div class="' + cls.join( ' ' ) + '" data-d="' + iso + '">' + d + '</div>';
                }
                var cellsSoFar = firstDow + daysInMonth;
                var tail = ( 7 - ( cellsSoFar % 7 ) ) % 7;
                for ( var dN = 1; dN <= tail; dN++ ) {
                    h += '<div class="pcp-dt-cal-cell other-month" data-d="' + y + '-' + String( mo + 1 ).padStart( 2, '0' ) + '-' + String( dN ).padStart( 2, '0' ) + '">' + dN + '</div>';
                }
                h += '</div><div class="pcp-dt-cal-quick">' +
                     '<button type="button" data-q="today">Today</button>' +
                     '<button type="button" data-q="thisyear">Jan 1, ' + y + '</button>' +
                     '<button type="button" data-q="clear">Clear</button>' +
                     '</div>';
                calEl.innerHTML = h;
                calEl.querySelector( '.pcp-dt-cal-title' ).addEventListener( 'click', function () {
                    var y2 = prompt( 'Jump to year:', st.view.year );
                    if ( y2 && /^\d{4}$/.test( y2 ) ) { st.view.year = +y2; renderCal(); }
                } );
                calEl.querySelectorAll( '.pcp-dt-cal-head button' ).forEach( function ( b ) {
                    b.addEventListener( 'click', function () {
                        st.view.month += +b.dataset.d;
                        if ( st.view.month < 1 ) { st.view.month = 12; st.view.year--; }
                        if ( st.view.month > 12 ) { st.view.month = 1; st.view.year++; }
                        renderCal();
                    } );
                } );
                calEl.querySelectorAll( '.pcp-dt-cal-cell' ).forEach( function ( c ) {
                    c.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } );
                    c.addEventListener( 'click', function () {
                        var iso = c.dataset.d;
                        st.selected = iso;
                        textEl.value = iso;
                        textEl.dispatchEvent( new Event( 'input', { bubbles: true } ) );
                    } );
                } );
                calEl.querySelectorAll( '.pcp-dt-cal-quick button' ).forEach( function ( b ) {
                    b.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } );
                    b.addEventListener( 'click', function () {
                        if ( b.dataset.q === 'today' )    { var t = new Date().toISOString().slice( 0, 10 ); textEl.value = t; st.selected = t; }
                        if ( b.dataset.q === 'thisyear' ) { textEl.value = String( st.view.year ); st.selected = null; }
                        if ( b.dataset.q === 'clear' )    { textEl.value = ''; st.selected = null; }
                        textEl.dispatchEvent( new Event( 'input', { bubbles: true } ) );
                    } );
                } );
            }

            textEl.addEventListener( 'focus', function () { calEl.classList.add( 'open' ); renderCal(); } );
            textEl.addEventListener( 'blur',  function () { setTimeout( function () { calEl.classList.remove( 'open' ); }, 200 ); } );
            textEl.addEventListener( 'input', function () {
                var p = parseFuzzy( textEl.value );
                if ( p && p.year && p.month ) {
                    state.fieldState[ prefix ].view = { year: p.year, month: p.month };
                    state.fieldState[ prefix ].selected = p.iso;
                    renderCal();
                }
                refresh();
            } );
            timeEl.addEventListener( 'input', refresh );
            tzEl.addEventListener( 'change', refresh );
        }

        function readField( prefix ) {
            var textEl = rootEl.querySelector( '.pcp-dt-text[data-prefix="' + prefix + '"]' );
            var timeEl = rootEl.querySelector( '.pcp-dt-time[data-prefix="' + prefix + '"]' );
            var tzEl   = rootEl.querySelector( '.pcp-dt-tz[data-prefix="'   + prefix + '"]' );
            var prevEl = rootEl.querySelector( '.pcp-dt-time-prev[data-prefix="' + prefix + '"]' );
            if ( !textEl ) return null;
            var parsed = parseFuzzy( textEl.value );
            var pTime  = parseTime( timeEl.value );
            var tStr   = pTime && !pTime.error ? formatTime( pTime ) : null;
            if ( !timeEl.value.trim() )           { prevEl.textContent = ''; prevEl.classList.remove( 'bad' ); }
            else if ( pTime && pTime.error )      { prevEl.textContent = '? not recognized'; prevEl.classList.add( 'bad' ); }
            else if ( tStr )                      { prevEl.textContent = '→ ' + tStr; prevEl.classList.remove( 'bad' ); }
            return {
                raw_text:  textEl.value || null,
                parsed:    parsed,
                time:      pTime ? { raw: pTime.raw, parsed: tStr, error: pTime.error || false } : null,
                timezone:  tStr ? tzEl.value : null,
                effective_iso: parsed ? parsed.iso + ( tStr ? 'T' + tStr : '' ) : null
            };
        }

        // ---- Range mode: single-input parser ----
        function buildSideField( text, tzValue ) {
            if ( !text || !text.trim() ) return null;
            var dateText = text.trim(), timeText = '';
            var atIdx = dateText.indexOf( '@' );
            if ( atIdx !== -1 ) {
                timeText = dateText.slice( atIdx + 1 ).trim();
                dateText = dateText.slice( 0, atIdx ).trim();
            }
            // Strip filler words: "summer of 2008" → "summer 2008", "in the year 2020" → "2020"
            dateText = dateText.replace( /\b(the|of|in|year)\b/gi, ' ' ).replace( /\s+/g, ' ' ).trim();
            var parsed = parseFuzzy( dateText );
            var pTime  = timeText ? parseTime( timeText ) : null;
            var tStr   = pTime && !pTime.error ? formatTime( pTime ) : null;
            return {
                raw_text:      dateText || null,
                parsed:        parsed,
                time:          pTime ? { raw: pTime.raw, parsed: tStr, error: pTime.error || false } : null,
                timezone:      tStr ? tzValue : null,
                effective_iso: parsed ? parsed.iso + ( tStr ? 'T' + tStr : '' ) : null
            };
        }
        function parseRangeText( text, tzValue ) {
            var t = ( text || '' ).trim();
            if ( !t ) return { start: null, end: null };
            // Range separators, tried in order (longer / more-specific first).
            // " to " is included by user request; may collide with time phrases
            // like "5 to 4pm" if both halves of an intended range omit dates.
            var seps = [
                /\s+through\s+/i, /\s+thru\s+/i, /\s+until\s+/i, /\s+till\s+/i,
                /\s+to\s+/i,
                /\s*—\s*/, /\s*–\s*/, /\s+-\s+/,
                /(?<=\d)-(?=[A-Za-z])/,
                /\s*\.\.+\s*/
            ];
            for ( var i = 0; i < seps.length; i++ ) {
                var m = t.match( seps[ i ] );
                if ( m ) {
                    var idx = m.index;
                    var left  = t.slice( 0, idx );
                    var right = t.slice( idx + m[ 0 ].length );
                    return {
                        start: buildSideField( left, tzValue ),
                        end:   buildSideField( right, tzValue )
                    };
                }
            }
            // No separator → treat entire input as start, end unset
            return { start: buildSideField( t, tzValue ), end: null };
        }

        // ---- Range field (used by top-level Range and by Range options inside Possibility) ----
        function rangeFieldHtmlFor( prefix, initStart, initEnd ) {
            var combinedText = '';
            var initTz = '';
            if ( initStart || initEnd ) {
                var l = ( initStart && initStart.raw_text ) ? initStart.raw_text : '';
                if ( initStart && initStart.time && initStart.time.raw ) l += ' @ ' + initStart.time.raw;
                var r = ( initEnd && initEnd.raw_text ) ? initEnd.raw_text : '';
                if ( initEnd && initEnd.time && initEnd.time.raw ) r += ' @ ' + initEnd.time.raw;
                if ( l && r ) combinedText = l + ' till ' + r;
                else if ( l )  combinedText = l;
                else if ( r )  combinedText = 'till ' + r;
                initTz = ( initStart && initStart.timezone ) || ( initEnd && initEnd.timezone ) || '';
            }
            return '<div class="pcp-dt-row">' +
                '<div class="pcp-dt-col pcp-dt-col-range">' +
                    '<label>From … through …</label>' +
                    '<input type="text" class="pcp-dt-range-text" data-prefix="' + prefix + '" autocomplete="off"' +
                        ' placeholder="summer of 2008 till mar 4 2026 @ 164530"' +
                        ' value="' + escapeHtml( combinedText ) + '"' +
                        ' data-inittz="' + escapeHtml( initTz ) + '">' +
                    '<div class="pcp-dt-range-preview" data-prefix="' + prefix + '"></div>' +
                '</div>' +
                '<div class="pcp-dt-col pcp-dt-col-tz">' +
                    '<label>Timezone</label>' +
                    '<select class="pcp-dt-range-tz" data-prefix="' + prefix + '"></select>' +
                '</div>' +
            '</div>';
        }
        function wireRangeFieldFor( prefix ) {
            var textEl = fieldsEl.querySelector( '.pcp-dt-range-text[data-prefix="' + prefix + '"]' );
            var prevEl = fieldsEl.querySelector( '.pcp-dt-range-preview[data-prefix="' + prefix + '"]' );
            var tzEl   = fieldsEl.querySelector( '.pcp-dt-range-tz[data-prefix="' + prefix + '"]' );
            populateTzSelect( tzEl );
            var initTz = textEl.dataset.inittz || '';
            if ( initTz ) {
                Array.prototype.forEach.call( tzEl.options, function ( o ) {
                    if ( o.value === initTz ) o.selected = true;
                } );
            }
            function update() {
                var result = parseRangeText( textEl.value, tzEl.value );
                state.optRange[ prefix ] = { start: result.start, end: result.end };
                function describe( label, side ) {
                    if ( !side ) return '';
                    if ( side.parsed ) {
                        var d = side.parsed.display || side.parsed.iso;
                        if ( side.time && side.time.parsed ) d += ' ' + side.time.parsed;
                        return label + ': ' + d;
                    }
                    if ( side.raw_text ) return '? ' + label + ': "' + side.raw_text + '" not recognized';
                    return '';
                }
                var parts = [];
                var ds = describe( 'start', result.start ); if ( ds ) parts.push( ds );
                var de = describe( 'end',   result.end );   if ( de ) parts.push( de );
                prevEl.textContent = parts.length ? '→ ' + parts.join( '   ·   ' ) : '';
                prevEl.classList.toggle( 'bad', /\?/.test( prevEl.textContent ) );
                refresh();
            }
            textEl.addEventListener( 'input', update );
            tzEl.addEventListener( 'change', update );
            update();
        }

        function build() {
            if ( state.mode === 'point' )  return { kind: 'point', point: readField( 'p1' ) };
            if ( state.mode === 'range' )  return { kind: 'range', start: state.rangeStart || null, end: state.rangeEnd || null };
            var opts = [];
            for ( var bi = 0; bi < state.possibilityCount; bi++ ) {
                var bp = 'opt' + bi;
                var bmode = state.optModes[ bi ] || 'date';
                if ( bmode === 'range' ) {
                    var rd = state.optRange[ bp ] || { start: null, end: null };
                    opts.push( { kind: 'range', start: rd.start || null, end: rd.end || null } );
                } else {
                    var f = readField( bp );
                    opts.push( { kind: 'point', point: f } );
                }
            }
            return { kind: 'possibility', options: opts };
        }

        function refresh() {
            hiddenEl.value = JSON.stringify( build() );
        }

        function renderMode() {
            setActiveToggle();
            fieldsEl.innerHTML = '';

            // Pull initial subfield data
            var initPoint = state.initial && state.initial.kind === 'point' ? state.initial.point : null;
            var initStart = state.initial && state.initial.kind === 'range' ? state.initial.start : null;
            var initEnd   = state.initial && state.initial.kind === 'range' ? state.initial.end   : null;
            var initOpts  = state.initial && state.initial.kind === 'possibility' && state.initial.options
                ? state.initial.options : [];

            if ( state.mode === 'point' ) {
                fieldsEl.insertAdjacentHTML( 'beforeend', fieldHtml( 'p1', 'Date', false ) );
                wireField( 'p1', initPoint );
            } else if ( state.mode === 'range' ) {
                // Build the combined text from any pre-filled start/end
                var combinedText = '';
                if ( initStart || initEnd ) {
                    var l = ( initStart && initStart.raw_text ) ? initStart.raw_text : '';
                    if ( initStart && initStart.time && initStart.time.raw ) l += ' @ ' + initStart.time.raw;
                    var r = ( initEnd && initEnd.raw_text ) ? initEnd.raw_text : '';
                    if ( initEnd && initEnd.time && initEnd.time.raw ) r += ' @ ' + initEnd.time.raw;
                    if ( l && r ) combinedText = l + ' till ' + r;
                    else if ( l )  combinedText = l;
                    else if ( r )  combinedText = 'till ' + r;
                }
                var initTz = ( initStart && initStart.timezone ) || ( initEnd && initEnd.timezone ) || '';
                fieldsEl.insertAdjacentHTML( 'beforeend',
                    '<div class="pcp-dt-field">' +
                        '<div class="pcp-dt-field-head"><span class="pcp-dt-field-label">Date range</span></div>' +
                        '<div class="pcp-dt-row">' +
                            '<div class="pcp-dt-col pcp-dt-col-range">' +
                                '<label>From … through …</label>' +
                                '<input type="text" class="pcp-dt-range-text" autocomplete="off"' +
                                    ' placeholder="summer of 2008 till mar 4 2026 @ 164530"' +
                                    ' value="' + escapeHtml( combinedText ) + '">' +
                                '<div class="pcp-dt-range-preview"></div>' +
                            '</div>' +
                            '<div class="pcp-dt-col pcp-dt-col-tz">' +
                                '<label>Timezone</label>' +
                                '<select class="pcp-dt-range-tz"></select>' +
                            '</div>' +
                        '</div>' +
                    '</div>'
                );
                var rangeTextEl = fieldsEl.querySelector( '.pcp-dt-range-text' );
                var rangePrevEl = fieldsEl.querySelector( '.pcp-dt-range-preview' );
                var rangeTzEl   = fieldsEl.querySelector( '.pcp-dt-range-tz' );
                populateTzSelect( rangeTzEl );
                if ( initTz ) {
                    Array.prototype.forEach.call( rangeTzEl.options, function ( o ) {
                        if ( o.value === initTz ) o.selected = true;
                    } );
                }
                function updateRangePreview() {
                    var result = parseRangeText( rangeTextEl.value, rangeTzEl.value );
                    state.rangeStart = result.start;
                    state.rangeEnd   = result.end;
                    function describe( label, side ) {
                        if ( !side ) return '';
                        if ( side.parsed ) {
                            var d = side.parsed.display || side.parsed.iso;
                            if ( side.time && side.time.parsed ) d += ' ' + side.time.parsed;
                            return label + ': ' + d;
                        }
                        if ( side.raw_text ) return '? ' + label + ': "' + side.raw_text + '" not recognized';
                        return '';
                    }
                    var parts = [];
                    var ds = describe( 'start', result.start ); if ( ds ) parts.push( ds );
                    var de = describe( 'end',   result.end );   if ( de ) parts.push( de );
                    rangePrevEl.textContent = parts.length ? '→ ' + parts.join( '   ·   ' ) : '';
                    rangePrevEl.classList.toggle( 'bad', /\?/.test( rangePrevEl.textContent ) );
                    refresh();
                }
                rangeTextEl.addEventListener( 'input', updateRangePreview );
                rangeTzEl.addEventListener( 'change', updateRangePreview );
                updateRangePreview();
            } else {
                // Seed optModes from initial options if not already set
                if ( !state.optModes.length && initOpts.length ) {
                    state.possibilityCount = Math.max( 2, initOpts.length );
                    for ( var oi = 0; oi < state.possibilityCount; oi++ ) {
                        var io = initOpts[ oi ];
                        if ( io && io.kind === 'range' ) {
                            state.optModes[ oi ] = 'range';
                            state.optRange[ 'opt' + oi ] = { start: io.start || null, end: io.end || null };
                        } else {
                            state.optModes[ oi ] = 'date';
                        }
                    }
                }
                while ( state.optModes.length < state.possibilityCount ) state.optModes.push( 'date' );

                function renderOption( idx ) {
                    var prefix = 'opt' + idx;
                    var mode = state.optModes[ idx ] || 'date';
                    var removable = idx >= 2;
                    var init = initOpts[ idx ] || null;
                    var initPoint = ( init && init.kind === 'point' ) ? init.point : ( init && !init.kind ? init : null );
                    var initStart = ( init && init.kind === 'range' ) ? init.start : null;
                    var initEnd   = ( init && init.kind === 'range' ) ? init.end   : null;

                    var bodyHtml = ( mode === 'range' )
                        ? rangeFieldHtmlFor( prefix, initStart, initEnd )
                        : fieldHtmlInner( prefix );

                    fieldsEl.insertAdjacentHTML( 'beforeend',
                        '<div class="pcp-dt-field" data-opt-idx="' + idx + '">' +
                            '<div class="pcp-dt-field-head">' +
                                '<span class="pcp-dt-opt-leftgroup">' +
                                    '<span class="pcp-dt-field-label">Option ' + ( idx + 1 ) + '</span>' +
                                    '<span class="pcp-dt-opt-mode">' +
                                        '<button type="button" data-omode="date"  data-opt-idx="' + idx + '"' + ( mode === 'date'  ? ' class="active"' : '' ) + '>date</button>' +
                                        '<button type="button" data-omode="range" data-opt-idx="' + idx + '"' + ( mode === 'range' ? ' class="active"' : '' ) + '>range</button>' +
                                    '</span>' +
                                '</span>' +
                                ( removable ? '<button type="button" class="pcp-dt-rm" data-opt-idx="' + idx + '">remove</button>' : '' ) +
                            '</div>' +
                            bodyHtml +
                        '</div>'
                    );
                    if ( mode === 'range' ) {
                        wireRangeFieldFor( prefix );
                    } else {
                        wireField( prefix, initPoint );
                    }
                }

                for ( var i = 0; i < state.possibilityCount; i++ ) renderOption( i );

                // Per-option mode toggle: preserve other options' data, convert toggled option's data sensibly
                fieldsEl.querySelectorAll( '.pcp-dt-opt-mode button' ).forEach( function ( b ) {
                    b.addEventListener( 'click', function () {
                        var idx = parseInt( b.dataset.optIdx, 10 );
                        var newMode = b.dataset.omode;
                        if ( state.optModes[ idx ] === newMode ) return;
                        var snapshot = build(); // { kind:'possibility', options:[...] }
                        var old = snapshot.options[ idx ];
                        if ( newMode === 'range' && old && old.kind === 'point' ) {
                            snapshot.options[ idx ] = { kind: 'range', start: old.point, end: null };
                        } else if ( newMode === 'date' && old && old.kind === 'range' ) {
                            snapshot.options[ idx ] = { kind: 'point', point: old.start || old.end || null };
                        }
                        state.optModes[ idx ] = newMode;
                        delete state.optRange[ 'opt' + idx ];
                        delete state.fieldState[ 'opt' + idx ];
                        state.initial = snapshot;
                        renderMode();
                    } );
                } );

                // Remove option: snapshot first so survivors keep their data
                fieldsEl.querySelectorAll( '.pcp-dt-rm' ).forEach( function ( b ) {
                    b.addEventListener( 'click', function () {
                        var idx = parseInt( b.dataset.optIdx, 10 );
                        var snapshot = build();
                        snapshot.options.splice( idx, 1 );
                        state.possibilityCount = Math.max( 2, state.possibilityCount - 1 );
                        state.optModes.splice( idx, 1 );
                        delete state.optRange[ 'opt' + idx ];
                        delete state.fieldState[ 'opt' + idx ];
                        // Re-key fieldState / optRange so survivors line up with their new indices
                        var newFs = {}, newOr = {};
                        for ( var k = 0; k < state.possibilityCount; k++ ) {
                            // Shift original index >= idx down by one
                            var srcIdx = k >= idx ? k + 1 : k;
                            if ( state.fieldState[ 'opt' + srcIdx ] ) newFs[ 'opt' + k ] = state.fieldState[ 'opt' + srcIdx ];
                            if ( state.optRange[ 'opt' + srcIdx ] )   newOr[ 'opt' + k ] = state.optRange[ 'opt' + srcIdx ];
                        }
                        state.fieldState = newFs;
                        state.optRange = newOr;
                        state.initial = snapshot;
                        renderMode();
                    } );
                } );

                // Add option: snapshot before re-render so existing options keep their typed data
                var addBtn = document.createElement( 'button' );
                addBtn.type = 'button';
                addBtn.className = 'pcp-dt-add';
                addBtn.textContent = '+ add another option';
                addBtn.addEventListener( 'click', function () {
                    var snapshot = build();
                    state.possibilityCount++;
                    state.optModes.push( 'date' );
                    state.initial = snapshot;
                    renderMode();
                } );
                fieldsEl.appendChild( addBtn );
            }

            // Drop initial after first render so user input replaces it
            state.initial = null;
            refresh();
        }

        renderMode();
    }

    function escapeHtml( s ) {
        return String( s ).replace( /[&<>"']/g, function ( c ) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
        } );
    }

    function autoInit() {
        document.querySelectorAll( '.pcp-date-input' ).forEach( initWidget );
    }
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', autoInit );
    } else {
        autoInit();
    }

    root.PCPDatePicker = { init: initWidget, autoInit: autoInit };

} )( window );
