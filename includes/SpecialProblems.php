<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;

/**
 * Special:Problems — browse the Problems repository.
 * Faceted by category with quick-jump nav, alphabetical within each category.
 * Client-side search filters across name, slug, and aliases. When all results
 * are filtered out, surfaces a "+ Add '<typed>' as a new Problem" link.
 */
class SpecialProblems extends SpecialPage {

    public function __construct() {
        parent::__construct( 'Problems' );
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );
        $out->setPageTitle( 'Problems' );

        $store = new ProblemStore();
        $total = $store->countAll();
        $cats  = $store->listCategories();

        $allRows = iterator_to_array( $store->listAll( 0, 5000, false, null ) );

        $intro  = '<p>The Pharmacopedia <strong>Problems</strong> repository — every diagnosis, ' .
                  'symptom, functional state, or lab target a medicine is used to address. ' .
                  '<strong>' . (int)$total . '</strong> Problems across <strong>' . count( $cats ) . '</strong> categories. ' .
                  'See [[Pharmacopedia:Problems repository]] for how to contribute and curate.</p>';
        $intro = $out->parseAsContent( $intro );

        $search = '<div class="pcp-pb-search" style="margin:1em 0;">' .
                  '<input type="search" id="pcp-pb-search" placeholder="Search Problems by name, slug, or alias…" ' .
                  'style="width:100%; padding:0.5em; font-size:1.05em; box-sizing:border-box;">' .
                  '<p style="opacity:0.65; font-size:0.85em; margin:0.4em 0 0 0;">Type to filter in place. ' .
                  'Use <code>cat:psychiatric</code>-style prefixes to limit by category.</p>' .
                  '</div>';

        // Hidden no-results panel (revealed by JS when all items are filtered out)
        $suggestUrl = SpecialPage::getTitleFor( 'SuggestProblem' )->getLocalURL();
        $noResults = '<div id="pcp-pb-no-results" hidden ' .
                     'style="margin:1em 0; padding:1em; border:1px solid #7c3aed; background:#1a1a1a; color:#fff;">' .
                     '<p style="margin:0 0 0.5em 0;">No matches for ' .
                     '"<span id="pcp-pb-no-q" style="color:#c4b5fd;"></span>".</p>' .
                     '<p style="margin:0;"><a id="pcp-pb-add-link" href="' . htmlspecialchars( $suggestUrl ) . '" ' .
                     'style="color:#c4b5fd; font-weight:bold;">+ Add as a new Problem</a></p>' .
                     '</div>';

        $jumpLinks = [];
        foreach ( $cats as $c => $n ) {
            $jumpLinks[] = '<a href="#cat-' . htmlspecialchars( $c ) . '">' .
                htmlspecialchars( $c ) . '</a> <span style="opacity:0.5;">(' . (int)$n . ')</span>';
        }
        $uncat = array_filter( $allRows, fn( $r ) => (string)$r->p_category === '' );
        if ( $uncat ) {
            $jumpLinks[] = '<a href="#cat-uncategorized">uncategorized</a> ' .
                '<span style="opacity:0.5;">(' . count( $uncat ) . ')</span>';
        }
        $jump = '<nav class="pcp-pb-jump" style="margin:0.8em 0; line-height:1.8;">' .
                '<span style="opacity:0.65;">Jump to: </span>' .
                implode( ' &middot; ', $jumpLinks ) .
                '</nav>';

        $byCat = [];
        foreach ( $allRows as $r ) {
            $byCat[ (string)$r->p_category ?: '' ][] = $r;
        }

        $aliasByPid = [];
        foreach ( $allRows as $r ) {
            $aliasByPid[ (int)$r->p_id ] = $store->getAliases( (int)$r->p_id );
        }

        $grid = '<div class="pcp-pb-grid">';
        $catKeys = array_keys( $cats );
        sort( $catKeys );
        if ( isset( $byCat[''] ) ) { $catKeys[] = ''; }
        foreach ( $catKeys as $c ) {
            if ( !isset( $byCat[ $c ] ) ) { continue; }
            $rows = $byCat[ $c ];
            $label = $c === '' ? 'uncategorized' : $c;
            $anchor = $c === '' ? 'cat-uncategorized' : 'cat-' . $c;
            $grid .= '<section id="' . htmlspecialchars( $anchor ) . '" class="pcp-pb-cat" data-cat="' . htmlspecialchars( $c ) . '">';
            $grid .= '<h2>' . htmlspecialchars( $label ) . ' ' .
                '<span style="opacity:0.55; font-size:0.7em; font-weight:normal;">(' . count( $rows ) . ')</span></h2>';
            $grid .= '<ul class="pcp-pb-list" style="columns:2; column-gap:2em; list-style-position:inside; padding-left:0;">';
            usort( $rows, fn( $a, $b ) => strcasecmp( (string)$a->p_name, (string)$b->p_name ) );
            foreach ( $rows as $r ) {
                $url = SpecialPage::getTitleFor( 'Problem', (string)$r->p_slug )->getLocalURL();
                $aliases = $aliasByPid[ (int)$r->p_id ] ?? [];
                $aliasStr = $aliases ? ' <span class="pcp-pb-aliases" style="opacity:0.55; font-size:0.85em;">(' .
                    htmlspecialchars( implode( ', ', $aliases ) ) . ')</span>' : '';
                $grid .= '<li class="pcp-pb-item" ' .
                    'data-search="' . htmlspecialchars( strtolower(
                        (string)$r->p_name . ' ' . (string)$r->p_slug . ' ' . implode( ' ', $aliases )
                    ) ) . '" ' .
                    'data-cat="' . htmlspecialchars( $c ) . '">' .
                    '<a href="' . $url . '">' . htmlspecialchars( (string)$r->p_name ) . '</a>' .
                    $aliasStr .
                    '</li>';
            }
            $grid .= '</ul>';
            $grid .= '</section>';
        }
        $grid .= '</div>';

        $js = <<<'JS'
<script>
(function(){
  var inp = document.getElementById('pcp-pb-search');
  if (!inp) return;
  var items = document.querySelectorAll('.pcp-pb-item');
  var sections = document.querySelectorAll('.pcp-pb-cat');
  var noRes = document.getElementById('pcp-pb-no-results');
  var noQ   = document.getElementById('pcp-pb-no-q');
  var addLink = document.getElementById('pcp-pb-add-link');
  var addBaseUrl = addLink ? addLink.getAttribute('href') : '';

  inp.addEventListener('input', function(){
    var qRaw = inp.value.trim();
    var q = qRaw.toLowerCase();
    var catFilter = null;
    var m = q.match(/^cat:(\S+)\s*(.*)$/);
    if (m) { catFilter = m[1]; q = m[2].trim(); }
    var totalVisible = 0;
    items.forEach(function(it){
      var s = it.getAttribute('data-search') || '';
      var c = it.getAttribute('data-cat') || '';
      var matchText = !q || s.indexOf(q) !== -1;
      var matchCat  = !catFilter || c === catFilter;
      var show = matchText && matchCat;
      it.style.display = show ? '' : 'none';
      if (show) totalVisible++;
    });
    sections.forEach(function(sec){
      var anyVisible = false;
      sec.querySelectorAll('.pcp-pb-item').forEach(function(it){
        if (it.style.display !== 'none') anyVisible = true;
      });
      sec.style.display = anyVisible ? '' : 'none';
    });
    if (noRes) {
      if (totalVisible === 0 && qRaw.length >= 2) {
        noQ.textContent = qRaw;
        addLink.textContent = '+ Add "' + qRaw + '" as a new Problem';
        addLink.href = addBaseUrl + '?prefill=' + encodeURIComponent(qRaw);
        noRes.hidden = false;
      } else {
        noRes.hidden = true;
      }
    }
  });
})();
</script>
JS;

        $out->addHTML( $intro . $search . $noResults . $jump . $grid . $js );
    }

    protected function getGroupName() { return 'pharmacopedia'; }
}
