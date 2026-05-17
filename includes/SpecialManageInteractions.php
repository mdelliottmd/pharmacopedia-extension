<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class SpecialManageInteractions extends SpecialPage {
    private const PER_PAGE = 50;

    public function __construct() {
        parent::__construct( 'ManageInteractions', 'pharmacopedia-verify-review' );
    }

    public function doesWrites() { return true; }

    public function execute( $par ) {
        $this->setHeaders();
        $this->checkPermissions();
        $req = $this->getRequest();
        $out = $this->getOutput();
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );
        $out->setPageTitle( 'Manage interactions' );

        $store = new InteractionStore();

        // POST: delete an interaction
        if ( $req->wasPosted() ) {
            if ( !$this->getUser()->matchEditToken( $req->getVal( 'wpEditToken' ) ) ) {
                $out->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ], 'Invalid token.' ) );
            } else {
                $op = $req->getVal( 'op', '' );
                if ( $op === 'delete' ) {
                    $elementId = (int)$req->getVal( 'element_id', 0 );
                    if ( $elementId > 0 ) {
                        $store->deleteInteraction( $elementId );
                    }
                }
            }
            // PRG -- redirect back preserving filter query
            $params = [];
            foreach ( [ 'q', 'type', 'severe', 'offset' ] as $k ) {
                $v = $req->getVal( $k, '' );
                if ( $v !== '' ) { $params[ $k ] = $v; }
            }
            $out->redirect( $this->getPageTitle()->getLocalURL( $params ) );
            return;
        }

        // GET: render filter form, stats, table.
        $q       = trim( (string)$req->getVal( 'q', '' ) );
        $type    = (string)$req->getVal( 'type', '' );   // '', 'med-med', 'med-cat', 'cat-cat'
        $severe  = (bool)$req->getVal( 'severe', 0 );
        $offset  = max( 0, (int)$req->getVal( 'offset', 0 ) );

        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $cond = [];
        if ( $q !== '' ) {
            $like = $dbr->buildLike( $dbr->anyString(), $q, $dbr->anyString() );
            $cond[] = $dbr->makeList( [
                "pi_left_slug  $like",
                "pi_right_slug $like",
            ], LIST_OR );
        }
        if ( $type === 'med-med' ) {
            $cond['pi_left_type']  = 'medicine';
            $cond['pi_right_type'] = 'medicine';
        } elseif ( $type === 'med-cat' ) {
            $cond[] = $dbr->makeList( [
                $dbr->makeList( [ 'pi_left_type' => 'medicine', 'pi_right_type' => 'category' ], LIST_AND ),
                $dbr->makeList( [ 'pi_left_type' => 'category', 'pi_right_type' => 'medicine' ], LIST_AND ),
            ], LIST_OR );
        } elseif ( $type === 'cat-cat' ) {
            $cond['pi_left_type']  = 'category';
            $cond['pi_right_type'] = 'category';
        }

        $rows = $dbr->select(
            'pcp_interactions', '*',
            $cond,
            __METHOD__,
            [ 'ORDER BY' => 'pi_id ASC', 'LIMIT' => self::PER_PAGE, 'OFFSET' => $offset ]
        );

        $totalAll = (int)$dbr->selectField( 'pcp_interactions', 'COUNT(*)', [], __METHOD__ );
        $totalFiltered = (int)$dbr->selectField( 'pcp_interactions', 'COUNT(*)', $cond, __METHOD__ );

        // Tally type-pair counts
        $byTypePair = $dbr->select(
            'pcp_interactions',
            [ 'pi_left_type', 'pi_right_type', 'c' => 'COUNT(*)' ],
            [],
            __METHOD__,
            [ 'GROUP BY' => 'pi_left_type, pi_right_type' ]
        );
        $tally = [ 'med-med' => 0, 'med-cat' => 0, 'cat-cat' => 0 ];
        foreach ( $byTypePair as $r ) {
            $key = ( $r->pi_left_type === 'medicine' && $r->pi_right_type === 'medicine' ) ? 'med-med'
                 : ( ( $r->pi_left_type === 'category' && $r->pi_right_type === 'category' ) ? 'cat-cat' : 'med-cat' );
            $tally[ $key ] += (int)$r->c;
        }

        $html  = '<p>Centralized view of every interaction edge. Filter, inspect aggregates, and delete edges site-wide.</p>';

        // Stats header
        $html .= '<p><strong>Total:</strong> ' . $totalAll . '  ';
        $html .= ' &nbsp; <strong>Medicine &harr; Medicine:</strong> ' . $tally['med-med'];
        $html .= ' &nbsp; <strong>Medicine &harr; Category:</strong> ' . $tally['med-cat'];
        $html .= ' &nbsp; <strong>Category &harr; Category:</strong> ' . $tally['cat-cat'];
        $html .= '</p>';

        // Filter form
        $base = $this->getPageTitle()->getLocalURL();
        $html .= Html::openElement( 'form', [ 'method' => 'GET', 'action' => $base, 'style' => 'margin: 0.6em 0;' ] );
        $html .= '<label>Search: <input type="text" name="q" value="' . htmlspecialchars( $q ) . '" placeholder="endpoint name fragment" style="width:18em"></label> ';
        $html .= '<label>Type pair: <select name="type">';
        foreach ( [ '' => 'Any', 'med-med' => 'Medicine ↔ Medicine', 'med-cat' => 'Medicine ↔ Category', 'cat-cat' => 'Category ↔ Category' ] as $val => $lbl ) {
            $sel = ( $type === $val ) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars( $val ) . '"' . $sel . '>' . htmlspecialchars( $lbl ) . '</option>';
        }
        $html .= '</select></label> ';
        $html .= '<label><input type="checkbox" name="severe" value="1"' . ( $severe ? ' checked' : '' ) . '> Severe only</label> ';
        $html .= Html::submitButton( 'Filter', [ 'class' => 'mw-ui-button' ] );
        if ( $q !== '' || $type !== '' || $severe ) {
            $html .= ' <a href="' . htmlspecialchars( $base ) . '">clear</a>';
        }
        $html .= Html::closeElement( 'form' );

        // List
        $html .= '<p><strong>' . $totalFiltered . '</strong> match' . ( $totalFiltered === 1 ? '' : 'es' ) .
                 ' (showing ' . ( $offset + 1 ) . '&ndash;' . min( $offset + self::PER_PAGE, $totalFiltered ) . ')</p>';

        $html .= '<table class="wikitable" style="width:100%;"><thead><tr>' .
                 '<th>#</th>' .
                 '<th>Left</th><th>Right</th>' .
                 '<th>n (user / prov)</th>' .
                 '<th>vmean</th>' .
                 '<th>Status</th>' .
                 '<th>Created</th>' .
                 '<th>Action</th>' .
                 '</tr></thead><tbody>';

        $token = $this->getUser()->getEditToken();
        $shown = 0;
        foreach ( $rows as $r ) {
            $eid = (int)$r->pi_element_id;
            $userAgg = $store->getAggregates( $eid, InteractionStore::PERSPECTIVE_USER );
            $provAgg = $store->getAggregates( $eid, InteractionStore::PERSPECTIVE_PROVIDER );
            $pooled  = $store->getAggregates( $eid );

            // Severe filter (applied client-side after fetching, since severity
            // is derived per-row from the aggregates).
            $isSevere = $pooled['severe'] || $userAgg['severe'] || $provAgg['severe'];
            if ( $severe && !$isSevere ) { continue; }

            $leftHtml  = self::endpointHtml( $r->pi_left_type, $r->pi_left_slug );
            $rightHtml = self::endpointHtml( $r->pi_right_type, $r->pi_right_slug );

            $vmFmt = $pooled['valence_mean'] !== null
                ? sprintf( '%+.2f', (float)$pooled['valence_mean'] ) : '—';
            $status = $isSevere ? '<span style="color:#b91c1c; font-weight:bold;">SEVERE</span>' : '';

            $created = '';
            if ( $r->pi_created_user_id ) {
                $u = MediaWikiServices::getInstance()->getUserFactory()->newFromId( (int)$r->pi_created_user_id );
                $name = $u ? $u->getName() : ( '#' . (int)$r->pi_created_user_id );
                $created = '<a href="' . htmlspecialchars( Title::makeTitle( NS_USER, $name )->getLocalURL() ) . '">' .
                           htmlspecialchars( $name ) . '</a>';
            }
            $ts = $r->pi_created;
            if ( $ts ) {
                $created .= '<br><small>' . substr( $ts, 0, 4 ) . '-' . substr( $ts, 4, 2 ) . '-' . substr( $ts, 6, 2 ) .
                            ' ' . substr( $ts, 8, 2 ) . ':' . substr( $ts, 10, 2 ) . '</small>';
            }

            $deleteForm = Html::openElement( 'form', [
                'method' => 'POST',
                'action' => $base,
                'style' => 'display:inline; margin:0;',
                'onsubmit' => "return confirm('Permanently delete this interaction and all its reports? Cannot be undone.');",
            ] );
            $deleteForm .= Html::hidden( 'wpEditToken', $token );
            $deleteForm .= Html::hidden( 'op', 'delete' );
            $deleteForm .= Html::hidden( 'element_id', $eid );
            foreach ( [ 'q', 'type', 'severe', 'offset' ] as $k ) {
                $v = $req->getVal( $k, '' );
                if ( $v !== '' ) { $deleteForm .= Html::hidden( $k, $v ); }
            }
            $deleteForm .= Html::submitButton( 'Delete', [ 'class' => 'mw-ui-button mw-ui-destructive' ] );
            $deleteForm .= Html::closeElement( 'form' );

            $rowStyle = $isSevere ? ' style="background: rgba(185,28,28,0.08);"' : '';
            $html .= '<tr' . $rowStyle . '>';
            $html .= '<td>' . (int)$r->pi_id . '</td>';
            $html .= '<td>' . $leftHtml . '</td>';
            $html .= '<td>' . $rightHtml . '</td>';
            $html .= '<td>' . (int)$userAgg['n'] . ' / ' . (int)$provAgg['n'] . '</td>';
            $html .= '<td style="font-variant-numeric: tabular-nums;">' . htmlspecialchars( $vmFmt ) . '</td>';
            $html .= '<td>' . $status . '</td>';
            $html .= '<td>' . $created . '</td>';
            $html .= '<td>' . $deleteForm . '</td>';
            $html .= '</tr>';
            $shown++;
        }

        $html .= '</tbody></table>';

        // Pagination
        if ( $offset > 0 ) {
            $prev = $this->getPageTitle()->getLocalURL( array_filter( [
                'q' => $q ?: null, 'type' => $type ?: null,
                'severe' => $severe ? '1' : null,
                'offset' => max( 0, $offset - self::PER_PAGE ) ?: null,
            ] ) );
            $html .= '<a href="' . htmlspecialchars( $prev ) . '">&larr; Previous</a> ';
        }
        if ( $offset + self::PER_PAGE < $totalFiltered ) {
            $next = $this->getPageTitle()->getLocalURL( array_filter( [
                'q' => $q ?: null, 'type' => $type ?: null,
                'severe' => $severe ? '1' : null,
                'offset' => $offset + self::PER_PAGE,
            ] ) );
            $html .= '<a href="' . htmlspecialchars( $next ) . '">Next &rarr;</a>';
        }

        // Orphan detection (interactions where an endpoint page no longer exists)
        $html .= '<h3 style="margin-top:2em;">Orphan check</h3>';
        $html .= '<p>Interactions where one or both endpoints point at a non-existent wiki page.</p>';
        $orphans = self::findOrphans( $dbr );
        if ( !$orphans ) {
            $html .= '<p><em>No orphans.</em></p>';
        } else {
            $html .= '<table class="wikitable"><thead><tr><th>#</th><th>Left</th><th>Right</th><th>Missing side</th><th>Action</th></tr></thead><tbody>';
            foreach ( $orphans as $o ) {
                $html .= '<tr>';
                $html .= '<td>' . (int)$o['row']->pi_id . '</td>';
                $html .= '<td>' . self::endpointHtml( $o['row']->pi_left_type,  $o['row']->pi_left_slug )  . '</td>';
                $html .= '<td>' . self::endpointHtml( $o['row']->pi_right_type, $o['row']->pi_right_slug ) . '</td>';
                $html .= '<td>' . implode( ', ', $o['missing'] ) . '</td>';
                $df = Html::openElement( 'form', [
                    'method' => 'POST', 'action' => $base, 'style' => 'display:inline;',
                    'onsubmit' => "return confirm('Delete this orphan interaction?');",
                ] );
                $df .= Html::hidden( 'wpEditToken', $token );
                $df .= Html::hidden( 'op', 'delete' );
                $df .= Html::hidden( 'element_id', (int)$o['row']->pi_element_id );
                $df .= Html::submitButton( 'Delete', [ 'class' => 'mw-ui-button mw-ui-destructive' ] );
                $df .= Html::closeElement( 'form' );
                $html .= '<td>' . $df . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }

        $out->addHTML( $html );
    }

    private static function endpointHtml( $type, $slug ) {
        $name = str_replace( '_', ' ', $slug );
        if ( $type === InteractionStore::TYPE_CATEGORY ) {
            $t = Title::makeTitle( NS_CATEGORY, $slug );
            $u = $t ? $t->getLocalURL() : '#';
            return '<span style="color:#b45309;">cat</span> <a href="' . htmlspecialchars( $u ) . '">' .
                   htmlspecialchars( $name ) . '</a>';
        }
        $t = Title::newFromText( $name );
        $u = $t ? $t->getLocalURL() : '#';
        $exists = $t && $t->exists();
        $linkStyle = $exists ? '' : ' style="color:#b91c1c;"';
        return '<span style="color:#0d9488;">med</span> <a href="' . htmlspecialchars( $u ) . '"' . $linkStyle . '>' .
               htmlspecialchars( $name ) . '</a>' . ( $exists ? '' : ' <small>(missing)</small>' );
    }

    private static function findOrphans( $dbr ) {
        $orphans = [];
        $rows = $dbr->select( 'pcp_interactions', '*', [], __METHOD__ );
        foreach ( $rows as $r ) {
            $missing = [];
            foreach ( [ 'left', 'right' ] as $side ) {
                $tCol = 'pi_' . $side . '_type';
                $sCol = 'pi_' . $side . '_slug';
                $ns = ( $r->$tCol === InteractionStore::TYPE_CATEGORY ) ? NS_CATEGORY : NS_MAIN;
                $t = Title::makeTitleSafe( $ns, $r->$sCol );
                if ( !$t || !$t->exists() ) {
                    $missing[] = $side . ' (' . $r->$tCol . ':' . $r->$sCol . ')';
                }
            }
            if ( $missing ) {
                $orphans[] = [ 'row' => $r, 'missing' => $missing ];
            }
        }
        return $orphans;
    }
}
