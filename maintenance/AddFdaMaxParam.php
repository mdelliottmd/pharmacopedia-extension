<?php
/**
 * Insert an empty `| fda_max = ` line into the MedTemplate call on every
 * Medicines-category page that doesn't already have one. Inserts immediately
 * after the `| preparations = ...` line.
 *
 * Usage:
 *   php run.php /var/www/mediawiki/extensions/Pharmacopedia/maintenance/AddFdaMaxParam.php \
 *       --username=MDElliottMD [--dry-run]
 */

$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

class AddFdaMaxParam extends Maintenance {

    public function __construct() {
        parent::__construct();
        $this->addOption( 'username', 'User to attribute the edit to', true, true );
        $this->addOption( 'dry-run', 'Preview without saving', false, false );
        $this->requireExtension( 'Pharmacopedia' );
    }

    public function execute() {
        $userName = $this->getOption( 'username' );
        $dryRun = $this->hasOption( 'dry-run' );
        $services = MediaWikiServices::getInstance();
        $user = $services->getUserFactory()->newFromName( $userName );
        if ( !$user || !$user->isRegistered() ) {
            $this->fatalError( "User '$userName' not found." );
        }

        $dbr = $services->getConnectionProvider()->getReplicaDatabase();
        $rows = $dbr->newSelectQueryBuilder()
            ->select( [ 'page_namespace', 'page_title' ] )
            ->from( 'page' )
            ->join( 'categorylinks', null, 'cl_from=page_id' )
            ->join( 'linktarget', null, 'cl_target_id=lt_id' )
            ->where( [
                'lt_title' => 'Medicines',
                'lt_namespace' => NS_CATEGORY,
                'page_namespace' => NS_MAIN,
            ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();

        $titleFactory = $services->getTitleFactory();
        $wikiPageFactory = $services->getWikiPageFactory();
        $contentHandler = $services->getContentHandlerFactory()
            ->getContentHandler( CONTENT_MODEL_WIKITEXT );

        $changed = 0;
        $skipped = 0;
        foreach ( $rows as $row ) {
            $title = $titleFactory->makeTitle( $row->page_namespace, $row->page_title );
            $page = $wikiPageFactory->newFromTitle( $title );
            $content = $page->getContent();
            if ( !$content ) { continue; }
            $wt = method_exists( $content, 'getText' ) ? $content->getText() : (string)$content;

            if ( preg_match( '/^\|\s*fda_max\s*=/m', $wt ) ) {
                $this->output( "  {$title->getPrefixedText()}: already has fda_max\n" );
                $skipped++;
                continue;
            }
            // Insert immediately after the `| preparations = ...` line
            $newWt = preg_replace(
                '/(^\|\s*preparations\s*=[^\n]*\n)/m',
                "\$1| fda_max           = \n",
                $wt,
                1, $count
            );
            if ( $count !== 1 ) {
                $this->output( "  {$title->getPrefixedText()}: no preparations line found — skipped\n" );
                $skipped++;
                continue;
            }

            $this->output( "  {$title->getPrefixedText()}: + fda_max" .
                ( $dryRun ? ' (dry-run)' : '' ) . "\n" );
            $changed++;
            if ( $dryRun ) { continue; }

            $newContent = $contentHandler->unserializeContent( $newWt );
            $updater = $page->newPageUpdater( $user );
            $updater->setContent( SlotRecord::MAIN, $newContent );
            $summary = CommentStoreComment::newUnsavedComment(
                'Added empty fda_max parameter to MedTemplate call'
            );
            $updater->saveRevision( $summary, EDIT_UPDATE );
            $status = $updater->getStatus();
            if ( !$status || !$status->isOK() ) {
                $this->output( "    SAVE FAILED\n" );
                continue;
            }
            if ( class_exists( 'FlaggedRevs' ) ) {
                try {
                    $newRev = $updater->getNewRevision();
                    if ( $newRev ) {
                        FlaggedRevs::autoReviewEdit( $page, $user, $newRev, null, true, true );
                    }
                } catch ( \Throwable $e ) {}
            }
        }
        $this->output( "\nDone. $changed updated, $skipped skipped" .
            ( $dryRun ? ' (dry-run — nothing saved)' : '' ) . ".\n" );
    }
}

$maintClass = AddFdaMaxParam::class;
require_once RUN_MAINTENANCE_IF_MAIN;
