<?php
namespace MediaWiki\Extension\Pharmacopedia;

use ExtensionRegistry;
use MediaWiki\MediaWikiServices;

/**
 * Echo notification trigger for feature-request status changes.
 * No-op when Echo is not loaded.
 */
class FeatureRequestNotifier {

    public const NOTIFICATION_TYPE = 'pharmacopedia-fr-status';

    public static function isEchoLoaded(): bool {
        return ExtensionRegistry::getInstance()->isLoaded( 'Echo' );
    }

    public static function onStatusChange( \stdClass $row, string $oldStatus, string $newStatus, ?int $actorUserId ): void {
        if ( !self::isEchoLoaded() ) return;
        if ( !class_exists( '\\EchoEvent' ) && !class_exists( '\\MediaWiki\\Extension\\Notifications\\Model\\Event' ) ) {
            return;
        }
        $submitterId = (int)$row->fr_user_id;
        if ( !$submitterId ) return;
        if ( $actorUserId !== null && (int)$actorUserId === $submitterId ) return;

        $titleObj = \SpecialPage::getTitleFor( 'FeatureRequests', (string)$row->fr_id );
        $params = [
            'type'  => self::NOTIFICATION_TYPE,
            'title' => $titleObj,
            'agent' => $actorUserId !== null
                ? MediaWikiServices::getInstance()->getUserFactory()->newFromId( $actorUserId )
                : null,
            'extra' => [
                'fr-id'         => (int)$row->fr_id,
                'fr-title'      => (string)$row->fr_title,
                'fr-old-status' => $oldStatus,
                'fr-new-status' => $newStatus,
                'fr-submitter'  => $submitterId,
            ],
        ];
        // MW 1.45+ Echo uses Notifications namespace
        if ( class_exists( '\\MediaWiki\\Extension\\Notifications\\Model\\Event' ) ) {
            \MediaWiki\Extension\Notifications\Model\Event::create( $params );
        } else {
            \EchoEvent::create( $params );
        }
    }

    public static function onBeforeCreateEchoEvent( array &$notifications, array &$notificationCategories, array &$icons ): void {
        $notificationCategories['pharmacopedia-fr'] = [
            'priority' => 3,
            'tooltip'  => 'echo-pref-tooltip-pharmacopedia-fr',
        ];
        $notifications[ self::NOTIFICATION_TYPE ] = [
            'category'            => 'pharmacopedia-fr',
            'group'               => 'positive',
            'section'             => 'alert',
            'presentation-model'  => FeatureRequestPresentationModel::class,
            'user-locators'       => [ [ self::class, 'locateSubmitter' ] ],
        ];
    }

    public static function locateSubmitter( $event ): array {
        $submitterId = (int)$event->getExtraParam( 'fr-submitter' );
        if ( !$submitterId ) return [];
        $u = MediaWikiServices::getInstance()->getUserFactory()->newFromId( $submitterId );
        if ( !$u ) return [];
        return [ $submitterId => $u ];
    }
}
