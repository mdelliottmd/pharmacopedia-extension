/**
 * ext.pharmacopedia.editor.js
 * PCP wikitext editor enhancements.
 *
 * Feature 1 -- Smart paste: pasting a bare PMID (7-8 digits) or DOI
 *   (10.NNNN/...) auto-fetches metadata from PubMed or CrossRef and
 *   inserts a formatted <ref> tag at the cursor position.
 *
 * Feature 2 -- House-rules linter: intercepts save and warns on
 *   banned terminology (drug/medication/antipsychotic/stimulant) and
 *   em-dashes in prose, with a "Save anyway" override path.
 *
 * Feature 3 -- Quick-ref stub: Ctrl+Alt+R inserts a cite-journal
 *   stub with the cursor placed ready to fill in the author field.
 *
 * Loaded only on action=edit|submit pages via Hooks.php.
 */
( function () {
    'use strict';

    // -----------------------------------------------------------------------
    // Patterns
    // -----------------------------------------------------------------------
    var PMID_RE    = /^\s*(\d{7,8})\s*$/;
    var DOI_RE     = /^\s*(10\.\d{4,}\/\S+)\s*$/i;
    var EM_DASH_RE = /—|&mdash;/g;

    // Each entry: [ regex (g flag, reset before use), plain-text suggestion ]
    var BANNED_TERMS = [
        [ /\bdrugs?\b/gi,          'medicine / medicines' ],
        [ /\bmedications?\b/gi,    'medicine / medicines' ],
        [ /\bantipsychotics?\b/gi, 'neuroleptic / neuroleptics' ],
        [ /\bstimulants?\b/gi,     'psychostimulant / psychostimulants' ]
    ];

    var JOURNAL_STUB   = '<ref>{{cite journal|author=|title=|journal=|year=|volume=|pages=|pmid=}}</ref>';
    var JOURNAL_CURSOR = JOURNAL_STUB.indexOf( 'author=' ) + 7; // cursor after "author="

    // -----------------------------------------------------------------------
    // Utility: insert text at textarea cursor
    // -----------------------------------------------------------------------
    function insertAtCursor( ta, text, selectOffset ) {
        var start = ta.selectionStart;
        var end   = ta.selectionEnd;
        ta.value  = ta.value.slice( 0, start ) + text + ta.value.slice( end );
        var cur   = start + ( selectOffset !== undefined ? selectOffset : text.length );
        ta.selectionStart = ta.selectionEnd = cur;
        ta.focus();
        ta.dispatchEvent( new Event( 'input' ) );
    }

    // -----------------------------------------------------------------------
    // Utility: status toast (dark theme, top-right)
    // -----------------------------------------------------------------------
    var _toastEl    = null;
    var _toastTimer = null;

    function toast( msg, duration ) {
        if ( !_toastEl ) {
            _toastEl = document.createElement( 'div' );
            _toastEl.id = 'pcp-editor-toast';
            _toastEl.style.cssText = [
                'position:fixed', 'bottom:20px', 'right:20px',
                'background:#0d0d1a', 'color:#ffe08a',
                'padding:10px 18px', 'border-radius:6px',
                'font-size:13px', 'line-height:1.5',
                'z-index:9999', 'box-shadow:0 2px 16px rgba(0,0,0,.7)',
                'border:1px solid #2a2a4a', 'display:none',
                'max-width:360px', 'word-break:break-word',
                'transition:opacity .2s'
            ].join( ';' );
            document.body.appendChild( _toastEl );
        }
        _toastEl.textContent = msg;
        _toastEl.style.display = 'block';
        clearTimeout( _toastTimer );
        _toastTimer = setTimeout( function () {
            _toastEl.style.display = 'none';
        }, duration || 3500 );
    }

    // -----------------------------------------------------------------------
    // Feature 1: Smart paste
    // -----------------------------------------------------------------------

    function formatPmidRef( data, pmid ) {
        var rec = data.result && data.result[ pmid ];
        if ( !rec ) return null;

        var authors = ( rec.authors || [] ).slice( 0, 3 )
            .map( function ( a ) { return a.name; } ).join( ', ' );
        if ( ( rec.authors || [] ).length > 3 ) authors += ' et al.';

        var title   = ( rec.title            || '' ).replace( /\.$/, '' );
        var journal = rec.fulljournalname     || rec.source || '';
        var year    = ( rec.pubdate          || '' ).slice( 0, 4 );
        var vol     = rec.volume             || '';
        var issue   = rec.issue ? '(' + rec.issue + ')' : '';
        var pages   = rec.pages             || '';

        var parts = [];
        if ( authors ) parts.push( authors );
        if ( title )   parts.push( title );
        var loc = [ journal, vol + issue, pages, year ].filter( Boolean ).join( ' ' );
        if ( loc )     parts.push( loc );
        parts.push( 'PMID:' + pmid );
        return '<ref>' + parts.join( '. ' ) + '.</ref>';
    }

    function formatDoiRef( data, doi ) {
        var msg = data.message;
        if ( !msg ) return null;

        var authors = ( msg.author || [] ).slice( 0, 3 ).map( function ( a ) {
            return ( a.family || '' )
                + ( a.given ? ' ' + a.given.charAt( 0 ) + '.' : '' );
        } ).join( ', ' );
        if ( ( msg.author || [] ).length > 3 ) authors += ' et al.';

        var title   = ( ( msg.title              || [] )[ 0 ] || '' ).replace( /<[^>]+>/g, '' );
        var journal = ( ( msg[ 'container-title' ] || [] )[ 0 ] || '' );
        var year    = '';
        if ( msg.issued && msg.issued[ 'date-parts' ] && msg.issued[ 'date-parts' ][ 0 ] ) {
            year = String( msg.issued[ 'date-parts' ][ 0 ][ 0 ] || '' );
        }
        var vol  = msg.volume || '';
        var iss  = msg.issue  ? '(' + msg.issue + ')' : '';
        var page = msg.page   || '';

        var parts = [];
        if ( authors ) parts.push( authors );
        if ( title )   parts.push( title );
        var loc = [ journal, vol + iss, page, year ].filter( Boolean ).join( ' ' );
        if ( loc )     parts.push( loc );
        parts.push( 'DOI:' + doi );
        return '<ref>' + parts.join( '. ' ) + '.</ref>';
    }

    function fetchPmid( ta, pmid ) {
        toast( 'Fetching PMID ' + pmid + '...' );
        fetch( 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi'
            + '?db=pubmed&id=' + encodeURIComponent( pmid ) + '&retmode=json' )
            .then( function ( r ) { return r.json(); } )
            .then( function ( data ) {
                var ref = formatPmidRef( data, pmid );
                if ( ref ) {
                    insertAtCursor( ta, ref );
                    toast( 'PMID ' + pmid + ' inserted as ref.' );
                } else {
                    insertAtCursor( ta, pmid );
                    toast( 'PMID ' + pmid + ' not found. Pasted as-is.' );
                }
            } )
            .catch( function () {
                insertAtCursor( ta, pmid );
                toast( 'PMID lookup failed. Pasted as-is.' );
            } );
    }

    function fetchDoi( ta, doi ) {
        toast( 'Fetching DOI ' + doi + '...' );
        fetch( 'https://api.crossref.org/works/' + encodeURIComponent( doi ) )
            .then( function ( r ) { return r.json(); } )
            .then( function ( data ) {
                var ref = formatDoiRef( data, doi );
                if ( ref ) {
                    insertAtCursor( ta, ref );
                    toast( 'DOI inserted as ref.' );
                } else {
                    insertAtCursor( ta, doi );
                    toast( 'DOI not found. Pasted as-is.' );
                }
            } )
            .catch( function () {
                insertAtCursor( ta, doi );
                toast( 'DOI lookup failed. Pasted as-is.' );
            } );
    }

    function initSmartPaste( ta ) {
        ta.addEventListener( 'paste', function ( e ) {
            var text = ( e.clipboardData || window.clipboardData ).getData( 'text' );
            if ( !text ) return;
            var pmidM = PMID_RE.exec( text );
            var doiM  = DOI_RE.exec( text );
            if ( pmidM ) {
                e.preventDefault();
                fetchPmid( ta, pmidM[ 1 ] );
            } else if ( doiM ) {
                e.preventDefault();
                fetchDoi( ta, doiM[ 1 ] );
            }
        } );
    }

    // -----------------------------------------------------------------------
    // Feature 2: House-rules linter
    // -----------------------------------------------------------------------

    // Strip verbatim blocks before scanning for banned terms
    function stripVerbatim( text ) {
        return text
            .replace( /<ref[^>]*>[\s\S]*?<\/ref>/gi, '' )
            .replace( /<!--[\s\S]*?-->/g, '' )
            .replace( /\{\{\s*(?:quote|blockquote)[^}]*\}\}/gi, '' );
    }

    function runLinter( text ) {
        var prose = stripVerbatim( text );
        var warnings = [];

        BANNED_TERMS.forEach( function ( pair ) {
            var re  = pair[ 0 ];
            var sug = pair[ 1 ];
            re.lastIndex = 0;
            var m = re.exec( prose );
            if ( m ) {
                warnings.push( '“' + m[ 0 ] + '” found. Prefer: ' + sug );
            }
        } );

        EM_DASH_RE.lastIndex = 0;
        if ( EM_DASH_RE.test( prose ) ) {
            warnings.push( 'Em-dash in prose (PCP house rule: avoid in article content)' );
        }

        return warnings;
    }

    function buildLinterPanel( warnings, onProceed, onDismiss ) {
        var panel = document.createElement( 'div' );
        panel.id  = 'pcp-linter-panel';
        panel.style.cssText = [
            'background:#180900', 'color:#ffd080',
            'border:1px solid #8a3800', 'border-radius:6px',
            'padding:12px 16px', 'margin:8px 0 10px',
            'font-size:13px', 'line-height:1.5'
        ].join( ';' );

        var heading = document.createElement( 'strong' );
        heading.style.display = 'block';
        heading.style.marginBottom = '6px';
        heading.textContent = 'House-rules check: '
            + warnings.length + ( warnings.length === 1 ? ' issue' : ' issues' );
        panel.appendChild( heading );

        var ul = document.createElement( 'ul' );
        ul.style.cssText = 'margin:0 0 10px 18px; padding:0;';
        warnings.forEach( function ( w ) {
            var li = document.createElement( 'li' );
            li.textContent = w;
            ul.appendChild( li );
        } );
        panel.appendChild( ul );

        function btn( label, bg, fg, border ) {
            var b = document.createElement( 'button' );
            b.textContent = label;
            b.type = 'button';
            b.style.cssText = [
                'background:' + bg, 'color:' + fg,
                'border:1px solid ' + border, 'border-radius:4px',
                'padding:4px 14px', 'cursor:pointer',
                'font-size:12px', 'margin-right:8px'
            ].join( ';' );
            return b;
        }

        var proceedBtn = btn( 'Save anyway', '#2a1000', '#ffd080', '#8a3800' );
        proceedBtn.addEventListener( 'click', function () {
            panel.remove();
            onProceed();
        } );
        panel.appendChild( proceedBtn );

        var dismissBtn = btn( 'Go back and edit', '#0d0d1a', '#aaa', '#333' );
        dismissBtn.addEventListener( 'click', function () {
            panel.remove();
            if ( onDismiss ) onDismiss();
        } );
        panel.appendChild( dismissBtn );

        return panel;
    }

    function initLinter( ta, form ) {
        var bypassed = false;

        form.addEventListener( 'submit', function ( e ) {
            if ( bypassed ) return;

            var warnings = runLinter( ta.value );
            if ( warnings.length === 0 ) return;

            e.preventDefault();

            // Remove any pre-existing panel
            var old = document.getElementById( 'pcp-linter-panel' );
            if ( old ) old.remove();

            var panel = buildLinterPanel( warnings, function () {
                // "Save anyway": set flag, re-trigger save
                bypassed = true;
                var saveBtn = form.querySelector( 'input[name="wpSave"]' );
                if ( saveBtn ) {
                    saveBtn.click();
                } else {
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                }
            }, null );

            // Insert panel before the edit buttons row
            var anchor = document.getElementById( 'editpage-copywarn' )
                || document.querySelector( '.editButtons' )
                || form;
            anchor.parentNode.insertBefore( panel, anchor );

            // Scroll panel into view
            panel.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
        } );
    }

    // -----------------------------------------------------------------------
    // Feature 3: Quick-ref stub (Ctrl+Alt+R)
    // -----------------------------------------------------------------------

    function initQuickRef( ta ) {
        ta.addEventListener( 'keydown', function ( e ) {
            if ( e.ctrlKey && e.altKey && !e.shiftKey && !e.metaKey
                && ( e.key === 'r' || e.key === 'R' ) ) {
                e.preventDefault();
                insertAtCursor( ta, JOURNAL_STUB, JOURNAL_CURSOR );
                toast( 'Cite-journal stub inserted. Fill in the fields.' );
            }
        } );
    }

    // -----------------------------------------------------------------------
    // Init
    // -----------------------------------------------------------------------

    function init() {
        var ta   = document.getElementById( 'wpTextbox1' );
        var form = document.getElementById( 'editform' );
        if ( !ta || !form ) return;

        initSmartPaste( ta );
        initLinter( ta, form );
        initQuickRef( ta );

        // One-time hint on first focus
        ta.addEventListener( 'focus', function () {
            setTimeout( function () {
                toast(
                    'PCP editor: paste PMID or DOI for auto-ref. Ctrl+Alt+R = cite-journal stub.',
                    5000
                );
            }, 500 );
        }, { once: true } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

}() );
