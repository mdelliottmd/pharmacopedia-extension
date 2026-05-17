<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;

class SpecialManageEffects extends SpecialPage {

    public function __construct() {
        parent::__construct( 'ManageEffects', 'pharmacopedia-verify-review' );
    }

    public function doesWrites() { return true; }

    public function execute( $par ) {
        $this->setHeaders();
        $this->checkPermissions();
        $req = $this->getRequest();
        $out = $this->getOutput();

        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );
        $store = new GlobalEffectStore();

        // POST handlers
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
                    if ( $name !== '' ) {
                        $store->create( $slug, $name, $desc, $alias, $this->getUser()->getId() );
                    }
                } elseif ( $op === 'edit' ) {
                    $id = (int)$req->getVal( 'id' );
                    $store->update( $id, [
                        'e_name'        => trim( (string)$req->getText( 'name', '' ) ),
                        'e_description' => trim( (string)$req->getText( 'description', '' ) ),
                        'e_aliases'     => trim( (string)$req->getText( 'aliases', '' ) ),
                    ] );
                } elseif ( $op === 'retire' ) {
                    $id = (int)$req->getVal( 'id' );
                    $mergeRaw = trim( (string)$req->getText( 'merge_into', '' ) );
                    $mergeInto = null;
                    if ( $mergeRaw !== '' ) {
                        $target = $store->getBySlug( $mergeRaw );
                        if ( $target ) { $mergeInto = (int)$target->e_id; }
                    }
                    $store->retire( $id, $mergeInto );
                } elseif ( $op === 'unretire' ) {
                    $id = (int)$req->getVal( 'id' );
                    $store->unretire( $id );
                }
            }
            // PRG: redirect to GET
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

    private function renderList( GlobalEffectStore $store, $req ) {
        $out = $this->getOutput();
        $includeRetired = (bool)$req->getVal( 'show_retired' );
        $offset = max( 0, (int)$req->getVal( 'offset', 0 ) );
        $limit  = 100;
        $rows   = $store->listAll( $offset, $limit, $includeRetired );
        $total  = $store->countAll( $includeRetired );

        $out->setPageTitle( 'Manage effects' );

        $html  = '<p>Global effect library. ' .
            'Edit names, descriptions, and search aliases; retire to soft-delete; ' .
            'retire-and-merge to fold a duplicate into its canonical entry.</p>';

        // Filter toggle
        $toggleUrl = $this->getPageTitle()->getLocalURL( [
            'show_retired' => $includeRetired ? '0' : '1',
        ] );
        $html .= '<p><a href="' . htmlspecialchars( $toggleUrl ) . '">' .
            ( $includeRetired ? 'Hide retired' : 'Show retired' ) . '</a>' .
            ' &nbsp; &nbsp; <strong>' . (int)$total . '</strong> total ' .
            ( $includeRetired ? '(including retired)' : 'active' ) . '</p>';

        // Create form
        $token = $this->getUser()->getEditToken();
        $html .= '<details class="pcp-mfx-create"><summary>+ Create new global effect</summary>';
        $html .= Html::openElement( 'form', [ 'method' => 'POST',
            'action' => $this->getPageTitle()->getLocalURL() ] );
        $html .= Html::hidden( 'wpEditToken', $token );
        $html .= Html::hidden( 'op', 'create' );
        $html .= '<p><label>Slug (optional; auto-generated from name if blank): ' .
            '<input type="text" name="slug" style="width:14em"></label></p>';
        $html .= '<p><label>Name (required): <input type="text" name="name" required style="width:24em"></label></p>';
        $html .= '<p><label>Description:<br><textarea name="description" rows="3" style="width:60em"></textarea></label></p>';
        $html .= '<p><label>Aliases (comma-separated): <input type="text" name="aliases" style="width:40em"></label></p>';
        $html .= Html::submitButton( 'Create', [ 'class' => 'mw-ui-button mw-ui-progressive' ] );
        $html .= Html::closeElement( 'form' );
        $html .= '</details>';

        // List table
        $html .= '<table class="wikitable sortable" style="width:100%; margin-top:1em;">';
        $html .= '<thead><tr><th>Name</th><th>Slug</th><th>Aliases</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        $count = 0;
        foreach ( $rows as $r ) {
            $count++;
            $editUrl = $this->getPageTitle()->getLocalURL( [ 'edit' => $r->e_id ] );
            $retired = (int)$r->e_retired === 1;
            $status = $retired
                ? ( $r->e_merged_into
                    ? 'Retired → merged into #' . (int)$r->e_merged_into
                    : 'Retired' )
                : 'Active';
            $html .= '<tr' . ( $retired ? ' style="opacity:0.55"' : '' ) . '>';
            $html .= '<td>' . htmlspecialchars( $r->e_name ) . '</td>';
            $html .= '<td><code>' . htmlspecialchars( $r->e_slug ) . '</code></td>';
            $html .= '<td>' . htmlspecialchars( (string)$r->e_aliases ) . '</td>';
            $html .= '<td>' . htmlspecialchars( mb_strimwidth( (string)$r->e_description, 0, 120, '…' ) ) . '</td>';
            $html .= '<td>' . htmlspecialchars( $status ) . '</td>';
            $html .= '<td><a href="' . htmlspecialchars( $editUrl ) . '">Edit</a></td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        if ( $offset > 0 ) {
            $prevUrl = $this->getPageTitle()->getLocalURL( [
                'offset' => max( 0, $offset - $limit ),
                'show_retired' => $includeRetired ? '1' : '0',
            ] );
            $html .= '<a href="' . htmlspecialchars( $prevUrl ) . '">← Previous</a> ';
        }
        if ( $count >= $limit ) {
            $nextUrl = $this->getPageTitle()->getLocalURL( [
                'offset' => $offset + $limit,
                'show_retired' => $includeRetired ? '1' : '0',
            ] );
            $html .= '<a href="' . htmlspecialchars( $nextUrl ) . '">Next →</a>';
        }

        $out->addHTML( $html );
    }

    private function renderEditForm( $id, GlobalEffectStore $store ) {
        $out = $this->getOutput();
        $row = $store->getById( $id );
        if ( !$row ) {
            $out->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ], 'Effect not found.' ) );
            return;
        }
        $out->setPageTitle( 'Edit effect — ' . $row->e_name );
        $token = $this->getUser()->getEditToken();

        // (Usage scan removed — see Special:WhatLinksHere via slug if needed.)

        $html  = '<p><a href="' . htmlspecialchars( $this->getPageTitle()->getLocalURL() ) . '">← Back to all effects</a></p>';

        $html .= Html::openElement( 'form', [ 'method' => 'POST',
            'action' => $this->getPageTitle()->getLocalURL() ] );
        $html .= Html::hidden( 'wpEditToken', $token );
        $html .= Html::hidden( 'op', 'edit' );
        $html .= Html::hidden( 'id', (int)$row->e_id );
        $html .= '<p><strong>Slug</strong> (immutable): <code>' . htmlspecialchars( $row->e_slug ) . '</code></p>';
        $html .= '<p><label>Name:<br><input type="text" name="name" value="' .
            htmlspecialchars( $row->e_name ) . '" required style="width:24em"></label></p>';
        $html .= '<p><label>Description:<br><textarea name="description" rows="4" style="width:60em">' .
            htmlspecialchars( (string)$row->e_description ) . '</textarea></label></p>';
        $html .= '<p><label>Aliases (comma-separated):<br><input type="text" name="aliases" value="' .
            htmlspecialchars( (string)$row->e_aliases ) . '" style="width:60em"></label></p>';
        $html .= Html::submitButton( 'Save', [ 'class' => 'mw-ui-button mw-ui-progressive' ] );
        $html .= Html::closeElement( 'form' );

        // Retire / merge form
        $html .= '<hr>';
        if ( (int)$row->e_retired === 1 ) {
            $html .= '<p><em>Currently retired' .
                ( $row->e_merged_into ? ' (merged into #' . (int)$row->e_merged_into . ')' : '' ) .
                '.</em></p>';
            $html .= Html::openElement( 'form', [ 'method' => 'POST',
                'action' => $this->getPageTitle()->getLocalURL() ] );
            $html .= Html::hidden( 'wpEditToken', $token );
            $html .= Html::hidden( 'op', 'unretire' );
            $html .= Html::hidden( 'id', (int)$row->e_id );
            $html .= Html::submitButton( 'Un-retire', [] );
            $html .= Html::closeElement( 'form' );
        } else {
            $html .= '<h3>Retire this effect</h3>';
            $html .= '<p>Marks the effect as retired. Optionally specify the slug of another effect to merge into — any <code>&lt;effect ref="' .
                htmlspecialchars( $row->e_slug ) .
                '"&gt;</code> tags will auto-resolve to the target.</p>';
            $html .= Html::openElement( 'form', [ 'method' => 'POST',
                'action' => $this->getPageTitle()->getLocalURL() ] );
            $html .= Html::hidden( 'wpEditToken', $token );
            $html .= Html::hidden( 'op', 'retire' );
            $html .= Html::hidden( 'id', (int)$row->e_id );
            $html .= '<p><label>Merge into (slug, optional): <input type="text" name="merge_into" style="width:24em"></label></p>';
            $html .= Html::submitButton( 'Retire', [ 'class' => 'mw-ui-button mw-ui-destructive' ] );
            $html .= Html::closeElement( 'form' );
        }

        $out->addHTML( $html );
    }
}
