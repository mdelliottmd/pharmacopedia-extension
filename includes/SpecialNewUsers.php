<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;

/**
 * Special:NewUsers -- the 20 most recently registered accounts, newest first,
 * one per line (avatar + name + registration date).
 *
 * SocialProfile only ships a <newusers> parser tag (avatar grid, no special
 * page) in this version, so we query the same data source it uses but render
 * our own vertical list.
 */
class SpecialNewUsers extends SpecialPage {
    private const LIMIT = 20;

    public function __construct() {
        // No permission requirement -- restrict via $wgSpecialPageLockdown['NewUsers']
        // in LocalSettings if you want it logged-in-only, like Listusers et al.
        parent::__construct( 'NewUsers' );
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->setPageTitle( 'New users' );
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        $services = MediaWikiServices::getInstance();
        $dbr = $services->getConnectionProvider()->getReplicaDatabase();

        // Newest-first list of actor IDs. Prefer NewSignupPage's tracking table
        // if present (it stores an explicit registration date); fall back to the
        // core logging table's newusers entries otherwise.
        $actorIds = [];
        if ( $dbr->tableExists( 'user_register_track', __METHOD__ ) ) {
            $res = $dbr->select(
                'user_register_track',
                [ 'ur_actor', 'ur_date' ],
                [],
                __METHOD__,
                [ 'ORDER BY' => 'ur_date DESC', 'LIMIT' => self::LIMIT ]
            );
            foreach ( $res as $row ) {
                $actorIds[] = [ 'actor' => (int)$row->ur_actor, 'date' => $row->ur_date ];
            }
        } else {
            $res = $dbr->select(
                'logging',
                [ 'log_actor', 'log_timestamp' ],
                [ 'log_type' => 'newusers' ],
                __METHOD__,
                [ 'ORDER BY' => 'log_timestamp DESC', 'LIMIT' => self::LIMIT ]
            );
            foreach ( $res as $row ) {
                $actorIds[] = [ 'actor' => (int)$row->log_actor, 'date' => $row->log_timestamp ];
            }
        }

        $logUrl = \SpecialPage::getTitleFor( 'Log', 'newusers' )->getLocalURL();
        $html = '<p>The ' . self::LIMIT . ' most recently registered accounts, newest first. ' .
                '<a href="' . htmlspecialchars( $logUrl ) . '">Full account-creation log &rarr;</a></p>';

        if ( !$actorIds ) {
            $html .= '<p><em>No registered accounts found.</em></p>';
            $out->addHTML( $html );
            return;
        }

        $hasAvatar = class_exists( 'wAvatar' );
        $lang = $this->getLanguage();

        $html .= '<ul class="pcp-newusers-list">';
        foreach ( $actorIds as $entry ) {
            $user = \User::newFromActorId( $entry['actor'] );
            if ( !$user || !$user->getId() ) { continue; }

            $userPage = $user->getUserPage();
            $url = htmlspecialchars( $userPage->getLocalURL() );
            $name = htmlspecialchars( $user->getName() );

            $avatarHtml = '';
            if ( $hasAvatar ) {
                $avatar = new \wAvatar( $user->getId(), 's' );
                $avatarHtml = '<a href="' . $url . '" class="pcp-newuser-avatar">' .
                    $avatar->getAvatarURL( [ 'title' => $user->getName() ] ) . '</a>';
            }

            $dateHtml = '';
            if ( !empty( $entry['date'] ) ) {
                $ts = wfTimestamp( TS_MW, $entry['date'] );
                if ( $ts ) {
                    $dateHtml = '<span class="pcp-newuser-date">' .
                        htmlspecialchars( $lang->userDate( $ts, $this->getUser() ) ) .
                        '</span>';
                }
            }

            $html .= '<li class="pcp-newuser-row">' .
                $avatarHtml .
                '<a href="' . $url . '" class="pcp-newuser-name">' . $name . '</a>' .
                $dateHtml .
                '</li>';
        }
        $html .= '</ul>';

        $out->addHTML( $html );
    }

    protected function getGroupName() {
        return 'users';
    }
}
