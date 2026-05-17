<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;

/**
 * Special:ManageProblems — sysop curation UI for the Problems repository.
 * Edit names/descriptions/aliases/categories, retire (soft-delete), retire-
 * and-merge (fold duplicates into canonical entries), unretire. Faceted by
 * category.
 */
class SpecialManageProblems extends SpecialPage {

    public function __construct() {
        parent::__construct( 'ManageProblems', 'pharmacopedia-verify-review' );
    }

    public function doesWrites() { return true; }

    public function execute( $par ) {
        $this->setHeaders();
        $this->checkPermissions();
        $req = $this->getRequest();
        $out = $this->getOutput();

        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );
        $store = new ProblemStore();

        if ( $req->wasPosted() ) {
            if ( !$this->getUser()->matchEditToken( $req->getVal( 'wpEditToken' ) ) ) {
                $out->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ], 'Invalid token.' ) );
            } else {
                $op = $req->getVal( 'op', '' );
                if ( $op === 'create' ) {
                    $slug = trim( (string)$req->getText( 'slug', '' ) );
                    $name = trim( (string)$req->getText( 'name', '' ) );
                    $desc = trim( (string)$req->getText( 'description', '' ) );
                    $alias = trim( (string)$req->getText( 'aliases', '' ) );
                    $cat = trim( (string)$req->getText( 'category', '' ) );
                    if ( $name !== '' ) {
                        $store->create( $slug, $name, $desc, $alias,
                            $this->getUser()->getId(),
                            $cat !== '' ? $cat : null );
                    }
                } elseif ( $op === 'edit' ) {
                    $id = (int)$req->getVal( 'id' );
                    $cat = trim( (string)$req->getText( 'category', '' ) );
                    $store->update( $id, [
                        'p_name'        => trim( (string)$req->getText( 'name', '' ) ),
                        'p_description' => trim( (string)$req->getText( 'description', '' ) ),
                        'p_category'    => $cat !== '' ? $cat : null,
                        'aliases'       => trim( (string)$req->getText( 'aliases', '' ) ),
                    ] );
                } elseif ( $op === 'retire' ) {
                    $id = (int)$req->getVal( 'id' );
                    $mergeRaw = trim( (string)$req->getText( 'merge_into', '' ) );
                    $mergeInto = null;
                    if ( $mergeRaw !== '' ) {
                        $target = $store->getBySlug( $mergeRaw );
                        if ( $target ) { $mergeInto = (int)$target->p_id; }
                    }
                    $store->retire( $id, $mergeInto );
                } elseif ( $op === 'unretire' ) {
                    $id = (int)$req->getVal( 'id' );
                    $store->unretire( $id );
                }
            }
            $out->redirect( $this->getPageTitle()->getLocalURL() );
            return;
        }

        $editId = (int)$req->getVal( 'edit', 0 );
        if ( $editId > 0 ) {
            $this->renderEditForm( $editId, $store );
            return;
        }
        $this->renderList( $store, $req );
    }

    private function renderList( ProblemStore $store, $req ) {
        $out = $this->getOutput();
        $includeRetired = (bool)$req->getVal( 'show_retired' );
        $catFilter = trim( (string)$req->getVal( 'cat', '' ) );
        $offset = max( 0, (int)$req->getVal( 'offset', 0 ) );
        $limit  = 100;
        $rows   = $store->listAll( $offset, $limit, $includeRetired,
            $catFilter !== '' ? $catFilter : null );
        $total  = $store->countAll( $includeRetired );

        $out->setPageTitle( 'Manage Problems' );

        $html  = '<p>Problems repository — what medicines are used FOR. ' .
                 'Edit names/descriptions/aliases/categories; retire to soft-delete; ' .
                 'retire-and-merge to fold a duplicate into its canonical entry.</p>';

        // Category facet bar
        $cats = $store->listCategories();
        $html .= '<p>';
        $allUrl = $this->getPageTitle()->getLocalURL( [
            'show_retired' => $includeRetired ? '1' : '0',
        ] );
        $allCls = $catFilter === '' ? ' style="font-weight:bold"' : '';
        $html .= '<a href="' . htmlspecialchars( $allUrl ) . '"' . $allCls . '>all</a>';
        foreach ( $cats as $c => $n ) {
            $url = $this->getPageTitle()->getLocalURL( [
                'cat' => $c, 'show_retired' => $includeRetired ? '1' : '0',
            ] );
            $cls = $c === $catFilter ? ' style="font-weight:bold"' : '';
            $html .= ' · <a href="' . htmlspecialchars( $url ) . '"' . $cls . '>' .
                htmlspecialchars( $c ) . ' (' . (int)$n . ')</a>';
        }
        $html .= '</p>';

        // Show-retired toggle + total
        $toggleUrl = $this->getPageTitle()->getLocalURL( [
            'show_retired' => $includeRetired ? '0' : '1',
            'cat' => $catFilter,
        ] );
        $html .= '<p><a href="' . htmlspecialchars( $toggleUrl ) . '">' .
            ( $includeRetired ? 'Hide retired' : 'Show retired' ) . '</a>' .
            ' &nbsp; &nbsp; <strong>' . (int)$total . '</strong> total ' .
            ( $includeRetired ? '(including retired)' : 'active' ) . '</p>';

        $token = $this->getUser()->getEditToken();
        $html .= '<details class="pcp-mfx-create"><summary>+ Create new Problem</summary>';
        $html .= Html::openElement( 'form', [ 'method' => 'POST', 'action' => $this->getPageTitle()->getLocalURL() ] );
        $html .= Html::hidden( 'wpEditToken', $token );
        $html .= Html::hidden( 'op', 'create' );
        $html .= '<p><label>Slug (optional; auto-generated from name if blank): <input type="text" name="slug" style="width:14em"></label></p>';
        $html .= '<p><label>Name (required): <input type="text" name="name" required style="width:24em"></label></p>';
        $html .= '<p><label>Category: <select name="category" style="width:14em">';
        $html .= '<option value="">— uncategorized —</option>';
        foreach ( $cats as $c => $n ) {
            $html .= '<option value="' . htmlspecialchars( $c ) . '">' . htmlspecialchars( $c ) . '</option>';
        }
        $html .= '</select></label></p>';
        $html .= '<p><label>Description:<br><textarea name="description" rows="3" style="width:60em"></textarea></label></p>';
        $html .= '<p><label>Aliases (comma-separated): <input type="text" name="aliases" style="width:40em"></label></p>';
        $html .= Html::submitButton( 'Create', [ 'class' => 'mw-ui-button mw-ui-progressive' ] );
        $html .= Html::closeElement( 'form' );
        $html .= '</details>';

        $html .= '<table class="wikitable sortable" style="width:100%; margin-top:1em;">';
        $html .= '<thead><tr><th>Name</th><th>Slug</th><th>Category</th><th>Aliases</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        $count = 0;
        foreach ( $rows as $r ) {
            $count++;
            $editUrl = $this->getPageTitle()->getLocalURL( [ 'edit' => $r->p_id ] );
            $retired = (int)$r->p_retired === 1;
            $status = $retired
                ? ( $r->p_merged_into ? 'Retired → merged into #' . (int)$r->p_merged_into : 'Retired' )
                : 'Active';
            $aliases = $store->getAliases( (int)$r->p_id );
            $html .= '<tr' . ( $retired ? ' style="opacity:0.55"' : '' ) . '>';
            $html .= '<td>' . htmlspecialchars( (string)$r->p_name ) . '</td>';
            $html .= '<td><code>' . htmlspecialchars( (string)$r->p_slug ) . '</code></td>';
            $html .= '<td>' . htmlspecialchars( (string)$r->p_category ?? '' ) . '</td>';
            $html .= '<td>' . htmlspecialchars( implode( ', ', $aliases ) ) . '</td>';
            $html .= '<td>' . htmlspecialchars( mb_strimwidth( (string)$r->p_description, 0, 120, '…' ) ) . '</td>';
            $html .= '<td>' . htmlspecialchars( $status ) . '</td>';
            $html .= '<td><a href="' . htmlspecialchars( $editUrl ) . '">Edit</a></td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        if ( $offset > 0 ) {
            $prevUrl = $this->getPageTitle()->getLocalURL( [
                'offset' => max( 0, $offset - $limit ),
                'show_retired' => $includeRetired ? '1' : '0',
                'cat' => $catFilter,
            ] );
            $html .= '<a href="' . htmlspecialchars( $prevUrl ) . '">← Previous</a> ';
        }
        if ( $count >= $limit ) {
            $nextUrl = $this->getPageTitle()->getLocalURL( [
                'offset' => $offset + $limit,
                'show_retired' => $includeRetired ? '1' : '0',
                'cat' => $catFilter,
            ] );
            $html .= '<a href="' . htmlspecialchars( $nextUrl ) . '">Next →</a>';
        }
        $out->addHTML( $html );
    }

    private function renderEditForm( $id, ProblemStore $store ) {
        $out = $this->getOutput();
        $row = $store->getById( $id );
        if ( !$row ) {
            $out->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ], 'Problem not found.' ) );
            return;
        }
        $out->setPageTitle( 'Edit Problem — ' . $row->p_name );
        $token = $this->getUser()->getEditToken();
        $aliases = $store->getAliases( (int)$row->p_id );
        $aliasStr = implode( ', ', $aliases );
        $cats = $store->listCategories();
        $currentCat = (string)$row->p_category;

        $html  = '<p><a href="' . htmlspecialchars( $this->getPageTitle()->getLocalURL() ) . '">← Back to all Problems</a></p>';
        $html .= Html::openElement( 'form', [ 'method' => 'POST', 'action' => $this->getPageTitle()->getLocalURL() ] );
        $html .= Html::hidden( 'wpEditToken', $token );
        $html .= Html::hidden( 'op', 'edit' );
        $html .= Html::hidden( 'id', (int)$row->p_id );
        $html .= '<p><strong>Slug</strong> (immutable): <code>' . htmlspecialchars( (string)$row->p_slug ) . '</code></p>';
        $html .= '<p><label>Name:<br><input type="text" name="name" value="' . htmlspecialchars( (string)$row->p_name ) . '" required style="width:24em"></label></p>';
        $html .= '<p><label>Category:<br><select name="category" style="width:14em">';
        $html .= '<option value=""' . ( $currentCat === '' ? ' selected' : '' ) . '>— uncategorized —</option>';
        foreach ( $cats as $c => $n ) {
            $sel = $c === $currentCat ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars( $c ) . '"' . $sel . '>' . htmlspecialchars( $c ) . '</option>';
        }
        $html .= '</select></label></p>';
        $html .= '<p><label>Description:<br><textarea name="description" rows="4" style="width:60em">' . htmlspecialchars( (string)$row->p_description ) . '</textarea></label></p>';
        $html .= '<p><label>Aliases (comma-separated):<br><input type="text" name="aliases" value="' . htmlspecialchars( $aliasStr ) . '" style="width:60em"></label></p>';
        $html .= Html::submitButton( 'Save', [ 'class' => 'mw-ui-button mw-ui-progressive' ] );
        $html .= Html::closeElement( 'form' );

        $html .= '<hr>';
        if ( (int)$row->p_retired === 1 ) {
            $html .= '<p><em>Currently retired' .
                ( $row->p_merged_into ? ' (merged into #' . (int)$row->p_merged_into . ')' : '' ) . '.</em></p>';
            $html .= Html::openElement( 'form', [ 'method' => 'POST', 'action' => $this->getPageTitle()->getLocalURL() ] );
            $html .= Html::hidden( 'wpEditToken', $token );
            $html .= Html::hidden( 'op', 'unretire' );
            $html .= Html::hidden( 'id', (int)$row->p_id );
            $html .= Html::submitButton( 'Un-retire', [] );
            $html .= Html::closeElement( 'form' );
        } else {
            $html .= '<h3>Retire this Problem</h3>';
            $html .= '<p>Marks the Problem as retired. Optionally specify the slug of another Problem to merge into ' .
                '— any <code>&lt;problem ref="this-slug"&gt;</code> tag will then resolve to the merge target.</p>';
            $html .= Html::openElement( 'form', [ 'method' => 'POST', 'action' => $this->getPageTitle()->getLocalURL() ] );
            $html .= Html::hidden( 'wpEditToken', $token );
            $html .= Html::hidden( 'op', 'retire' );
            $html .= Html::hidden( 'id', (int)$row->p_id );
            $html .= '<p><label>Merge into (slug, optional): <input type="text" name="merge_into" style="width:24em"></label></p>';
            $html .= Html::submitButton( 'Retire', [ 'class' => 'mw-ui-button mw-ui-destructive' ] );
            $html .= Html::closeElement( 'form' );
        }
        $out->addHTML( $html );
    }
}
