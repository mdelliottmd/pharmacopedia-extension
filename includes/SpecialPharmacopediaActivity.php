<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\MediaWikiServices;

class SpecialPharmacopediaActivity extends SpecialPage {
    public function __construct() {
        parent::__construct( 'PharmacopediaActivity', 'pharmacopedia-activity-view' );
    }

    public function execute( $subPage ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->setPageTitle( 'Pharmacopedia activity' );
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        $db = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

        // Recent votes (last 30)
        $out->addWikiTextAsContent( '== Recent votes ==' );
        $rows = $db->select(
            [ 'v' => 'pcp_votes', 've' => 'pcp_votable_elements', 'p' => 'page' ],
            [ 'v.v_value', 'v.v_timestamp', 've.ve_label', 've.ve_slug', 'p.page_title' ],
            [],
            __METHOD__,
            [ 'ORDER BY' => 'v.v_timestamp DESC', 'LIMIT' => 30 ],
            [
                've' => [ 'JOIN', 'v.v_element_id = ve.ve_id' ],
                'p'  => [ 'LEFT JOIN', 've.ve_page_id = p.page_id' ],
            ]
        );
        $text = '';
        foreach ( $rows as $r ) {
            $arrow = (int)$r->v_value > 0 ? '▲' : '▼';
            $when = wfTimestamp( TS_RFC2822, $r->v_timestamp );
            $page = str_replace( '_', ' ', (string)$r->page_title );
            $label = $r->ve_label !== null && $r->ve_label !== '' ? $r->ve_label : $r->ve_slug;
            $text .= "* $arrow Anonymous voter on [[$page]]: ''" . htmlspecialchars( $label ) . "'' — <small>$when</small>\n";
        }
        $out->addWikiTextAsContent( $text ?: "''No votes yet.''" );

        // Recent effect reports
        $out->addWikiTextAsContent( "\n== Recent effect reports ==" );
        $rows = $db->select(
            [ 'er' => 'pcp_effect_reports', 've' => 'pcp_votable_elements', 'p' => 'page' ],
            [ 'er.er_experienced', 'er.er_valence', 'er.er_timestamp',
              've.ve_label', 've.ve_slug', 'p.page_title' ],
            [],
            __METHOD__,
            [ 'ORDER BY' => 'er.er_timestamp DESC', 'LIMIT' => 30 ],
            [
                've' => [ 'JOIN', 'er.er_element_id = ve.ve_id' ],
                'p'  => [ 'LEFT JOIN', 've.ve_page_id = p.page_id' ],
            ]
        );
        $text = '';
        foreach ( $rows as $r ) {
            $exp = (int)$r->er_experienced;
            $expLabel = $exp === 1 ? 'experienced' : ( $exp === 0 ? 'did not experience' : 'unsure' );
            $val = $r->er_valence !== null ? sprintf( ' (valence %+d)', (int)$r->er_valence ) : '';
            $when = wfTimestamp( TS_RFC2822, $r->er_timestamp );
            $page = str_replace( '_', ' ', (string)$r->page_title );
            $label = $r->ve_label !== null && $r->ve_label !== '' ? $r->ve_label : $r->ve_slug;
            $text .= "* Anonymous reporter $expLabel ''" . htmlspecialchars( $label ) . "'' on [[$page]]$val — <small>$when</small>\n";
        }
        $out->addWikiTextAsContent( $text ?: "''No effect reports yet.''" );

        // Recent comments
        $out->addWikiTextAsContent( "\n== Recent comments ==" );
        $rows = $db->select(
            [ 'c' => 'pcp_comments', 've' => 'pcp_votable_elements', 'p' => 'page' ],
            [ 'c.c_text', 'c.c_timestamp', 'c.c_deleted', 'c.c_display_name',
              've.ve_label', 've.ve_slug', 'p.page_title' ],
            [],
            __METHOD__,
            [ 'ORDER BY' => 'c.c_timestamp DESC', 'LIMIT' => 30 ],
            [
                've' => [ 'JOIN', 'c.c_element_id = ve.ve_id' ],
                'p'  => [ 'LEFT JOIN', 've.ve_page_id = p.page_id' ],
            ]
        );
        $text = '';
        foreach ( $rows as $r ) {
            if ( (int)$r->c_deleted > 0 ) { continue; }
            $when = wfTimestamp( TS_RFC2822, $r->c_timestamp );
            $page = str_replace( '_', ' ', (string)$r->page_title );
            $userDisp = !empty( $r->c_display_name )
                ? '[[User:' . $r->c_display_name . '|' . $r->c_display_name . ']]'
                : 'Anonymous';
            $preview = mb_substr( str_replace( "\n", ' ', (string)$r->c_text ), 0, 120 );
            $text .= "* $userDisp on [[$page]]: \"" . htmlspecialchars( $preview ) . "\" — <small>$when</small>\n";
        }
        $out->addWikiTextAsContent( $text ?: "''No comments yet.''" );

        // Recent literature submissions
        $out->addWikiTextAsContent( "\n== Recent literature ==" );
        $litStore = new LiteratureStore();
        $litRows = $litStore->listRecent( 30 );
        $text = '';
        foreach ( $litRows as $r ) {
            $when = wfTimestamp( TS_RFC2822, $r->l_submitted );
            $page = str_replace( '_', ' ', (string)$r->page_title );
            $user = $r->submitter_name ?: '(unknown)';
            $statusLabel = LiteratureStore::statusLabel( (int)$r->l_status );
            $statusIcon = (int)$r->l_status === LiteratureStore::STATUS_PENDING ? '⏳'
                : ( (int)$r->l_status === LiteratureStore::STATUS_APPROVED ? '✓' : '✗' );
            $titleSnip = mb_substr( (string)$r->l_title, 0, 100 );
            $text .= "* $statusIcon [[User:$user|$user]] on [[$page]]: \"" . htmlspecialchars( $titleSnip )
                  . "\" ''($statusLabel)'' — <small>$when</small>\n";
        }
        $out->addWikiTextAsContent( $text ?: "''No literature submissions yet.''" );
    }

    protected function getGroupName() { return 'wiki'; }
}
