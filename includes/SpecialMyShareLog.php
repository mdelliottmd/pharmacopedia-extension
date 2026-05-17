<?php
namespace MediaWiki\Extension\Pharmacopedia;

use SpecialPage;
use Html;
use MediaWiki\MediaWikiServices;

/**
 * Owner-facing audit log: who has viewed this user's shared content, when,
 * which namespace/key, and via which rule.
 *
 * Reads pcp_visibility_view_log filtered to the current user as the owner.
 * Latest 200 events by default. Anonymous viewers show as their IP (masked).
 */
class SpecialMyShareLog extends SpecialPage {

    public function __construct() {
        parent::__construct( 'MyShareLog' );
    }

    public function execute( $sub ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $out->addWikiTextAsInterface( $this->msg( 'pharmacopedia-login-required' )->plain() );
            return;
        }
        $out->setPageTitle( 'Who has viewed my shared content' );
        $out->addModuleStyles( [ 'ext.pharmacopedia.share' ] );

        $ownerId = (int)$user->getId();
        $limit = max( 10, min( 500, (int)$this->getRequest()->getVal( 'limit', 200 ) ) );

        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $rows = $dbr->newSelectQueryBuilder()
            ->select( [
                'vl.vl_id', 'vl.vl_viewed_at', 'vl.vl_viewer_id',
                'vl.vl_viewer_ip', 'vl.vl_namespace', 'vl.vl_key', 'vl.vl_rule_id',
                'u.user_name'
            ] )
            ->from( 'pcp_visibility_view_log', 'vl' )
            ->leftJoin( 'user', 'u', 'u.user_id = vl.vl_viewer_id' )
            ->where( [ 'vl.vl_owner_id' => $ownerId ] )
            ->orderBy( 'vl.vl_viewed_at', 'DESC' )
            ->limit( $limit )
            ->caller( __METHOD__ )
            ->fetchResultSet();

        $count = 0;
        foreach ( $rows as $r ) $count++;
        if ( $count === 0 ) {
            $out->addHTML( Html::element( 'p', [],
                'Nobody has viewed your shared content yet. (This log records views by people other than yourself.)' ) );
            return;
        }

        $out->addHTML( Html::element( 'p', [],
            'Showing the last ' . $count . ' view(s) of your shared content.' ) );

        $rows->rewind();
        $html = '<table class="pcp-sharelog wikitable">';
        $html .= '<thead><tr>';
        foreach ( [ 'When', 'Viewer', 'Namespace', 'Key', 'Rule' ] as $h ) {
            $html .= '<th>' . htmlspecialchars( $h ) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $when = $this->formatTimestamp( (string)$r->vl_viewed_at );
            if ( !empty( $r->user_name ) ) {
                $viewer = htmlspecialchars( (string)$r->user_name );
            } elseif ( !empty( $r->vl_viewer_ip ) ) {
                $ip = inet_ntop( $r->vl_viewer_ip );
                $viewer = '<em>anon ' . htmlspecialchars( $this->maskIp( (string)$ip ) ) . '</em>';
            } else {
                $viewer = '<em>anon</em>';
            }
            $ns  = htmlspecialchars( (string)$r->vl_namespace );
            $key = $r->vl_key !== null ? htmlspecialchars( (string)$r->vl_key ) : '<em>(all)</em>';
            $rule = $r->vl_rule_id !== null ? ( '#' . (int)$r->vl_rule_id ) : '<em>legacy/owner</em>';
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars( $when ) . '</td>';
            $html .= '<td>' . $viewer . '</td>';
            $html .= '<td>' . $ns . '</td>';
            $html .= '<td>' . $key . '</td>';
            $html .= '<td>' . $rule . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $out->addHTML( $html );
    }

    private function formatTimestamp( string $mwTs ): string {
        if ( strlen( $mwTs ) !== 14 ) return $mwTs;
        return substr( $mwTs, 0, 4 ) . '-' . substr( $mwTs, 4, 2 ) . '-' . substr( $mwTs, 6, 2 ) .
               ' ' . substr( $mwTs, 8, 2 ) . ':' . substr( $mwTs, 10, 2 );
    }

    /**
     * Mask trailing octet/quad to limit PII; show enough to distinguish repeat visits
     * but not enough to fingerprint precisely.
     */
    private function maskIp( string $ip ): string {
        if ( strpos( $ip, '.' ) !== false ) {
            $parts = explode( '.', $ip );
            if ( count( $parts ) === 4 ) { $parts[3] = 'x'; return implode( '.', $parts ); }
        } elseif ( strpos( $ip, ':' ) !== false ) {
            $parts = explode( ':', $ip );
            $kept = array_slice( $parts, 0, 4 );
            return implode( ':', $kept ) . '::x';
        }
        return $ip;
    }

    protected function getGroupName() { return 'users'; }
}
