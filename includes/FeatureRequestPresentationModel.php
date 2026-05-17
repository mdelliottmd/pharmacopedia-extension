<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * Echo presentation model for "pharmacopedia-fr-status" notifications.
 * Parent class lives in Echo; this file is only loaded when Echo is active.
 */
class FeatureRequestPresentationModel extends \MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel {

    public function getIconType() {
        return 'site';
    }

    public function getPrimaryLink() {
        $id = (int)$this->event->getExtraParam( 'fr-id' );
        return [
            'url'   => \SpecialPage::getTitleFor( 'FeatureRequests', (string)$id )->getFullURL(),
            'label' => $this->msg( 'pharmacopedia-fr-notif-link' )->text(),
        ];
    }

    public function getHeaderMessage() {
        $msg = $this->msg( 'pharmacopedia-fr-notif-header' );
        $agent = $this->getAgentForOutput();
        $msg->params( $agent ? $agent->getName() : 'A sysop' );
        $msg->params( (string)$this->event->getExtraParam( 'fr-title' ) );
        $msg->params( $this->statusLabel( (string)$this->event->getExtraParam( 'fr-new-status' ) ) );
        return $msg;
    }

    public function getBodyMessage() {
        $msg = $this->msg( 'pharmacopedia-fr-notif-body' );
        $msg->params( $this->statusLabel( (string)$this->event->getExtraParam( 'fr-old-status' ) ) );
        $msg->params( $this->statusLabel( (string)$this->event->getExtraParam( 'fr-new-status' ) ) );
        return $msg;
    }

    private function statusLabel( string $key ): string {
        $labels = FeatureRequestStore::STATUSES;
        return $labels[ $key ] ?? $key;
    }
}
