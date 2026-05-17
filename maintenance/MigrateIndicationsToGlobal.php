<?php
/**
 * Scan every Category:Medicines page, find each <indication slug="..." title="..." ...>
 * block, create / look up a matching pcp_indications row, rewrite the tag in-place to
 * <indication ref="..."> while preserving body + author attribute.
 *
 * Usage:
 *   php run.php /var/www/mediawiki/extensions/Pharmacopedia/maintenance/MigrateIndicationsToGlobal.php \
 *       --username=MDElliottMD [--dry-run]
 */

$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Extension\Pharmacopedia\GlobalIndicationStore;

class MigrateIndicationsToGlobal extends Maintenance {

    public function __construct() {
        parent::__construct();
        $this->addOption( 'username', 'User to attribute new global indications to', true, true );
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
        $pageRows = $dbr->newSelectQueryBuilder()
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

        $store = new GlobalIndicationStore();

        // Phase 1: collect unique (slug, title) pairs, normalized
        $byNormalized = [];
        foreach ( $pageRows as $row ) {
            $title = $titleFactory->makeTitle( $row->page_namespace, $row->page_title );
            $page = $wikiPageFactory->newFromTitle( $title );
            $content = $page->getContent();
            if ( !$content ) { continue; }
            $wt = method_exists( $content, 'getText' ) ? $content->getText() : (string)$content;

            preg_match_all(
                '/<indication\b([^>]*?)(?:\/\s*>|>[\s\S]*?<\/indication\s*>)/i',
                $wt, $matches );
            foreach ( $matches[1] as $attrs ) {
                if ( preg_match( '/\bref\s*=\s*"/i', $attrs ) ) { continue; }
                $slug = ''; $tTitle = '';
                if ( preg_match( '/\bslug\s*=\s*"([^"]*)"/i', $attrs, $m ) )  { $slug = $m[1]; }
                if ( preg_match( '/\btitle\s*=\s*"([^"]*)"/i', $attrs, $m ) ) { $tTitle = $m[1]; }
                if ( $tTitle === '' ) { $tTitle = $slug; }
                if ( $tTitle === '' ) { continue; }
                $norm = self::canonical( $tTitle );
                if ( !isset( $byNormalized[ $norm ] ) ) {
                    $byNormalized[ $norm ] = [
                        'title' => $tTitle, 'slug' => $slug, 'count' => 0,
                        'alt' => [],
                    ];
                }
                $byNormalized[ $norm ]['count']++;
                $byNormalized[ $norm ]['alt'][ $tTitle ] = true;
            }
        }

        $this->output( "Found " . count( $byNormalized ) . " unique indications across " . $pageRows->numRows() . " pages.\n" );
        if ( $dryRun ) {
            foreach ( $byNormalized as $norm => $info ) {
                $this->output( "  $norm  →  title='{$info['title']}', count={$info['count']}\n" );
            }
            $this->output( "\n(dry-run — no rows created, no pages saved)\n" );
            return;
        }

        // Phase 2: create / lookup global rows
        $slugMap = [];
        foreach ( $byNormalized as $norm => $info ) {
            $aliases = array_keys( array_diff_key( $info['alt'], [ $info['title'] => true ] ) );
            $useSlug = $info['slug'] !== '' ? $info['slug'] : GlobalIndicationStore::normalizeSlug( $info['title'] );
            $id = $store->create(
                $useSlug, $info['title'],
                /* description */ '',
                /* aliases */ implode( ', ', $aliases ),
                $user->getId()
            );
            if ( !$id ) {
                $this->output( "  SKIP: could not create '{$info['title']}'\n" );
                continue;
            }
            $row = $store->getById( $id );
            $slugMap[ $norm ] = $row->i_slug;
            $this->output( "  ✓ '{$info['title']}' → ref=\"{$row->i_slug}\"\n" );
        }

        // Phase 3: rewrite each page
        foreach ( $pageRows as $row ) {
            $title = $titleFactory->makeTitle( $row->page_namespace, $row->page_title );
            $page = $wikiPageFactory->newFromTitle( $title );
            $content = $page->getContent();
            if ( !$content ) { continue; }
            $wt = method_exists( $content, 'getText' ) ? $content->getText() : (string)$content;

            $changed = 0;
            $newWt = preg_replace_callback(
                '/<indication\b([^>]*?)((?:\/\s*>)|(?:>[\s\S]*?<\/indication\s*>))/i',
                function ( $m ) use ( $slugMap, &$changed ) {
                    $attrs = $m[1];
                    $rest = $m[2];
                    if ( preg_match( '/\bref\s*=\s*"/i', $attrs ) ) { return $m[0]; }
                    $slug = ''; $tTitle = ''; $author = '';
                    if ( preg_match( '/\bslug\s*=\s*"([^"]*)"/i',   $attrs, $sm ) ) { $slug = $sm[1]; }
                    if ( preg_match( '/\btitle\s*=\s*"([^"]*)"/i',  $attrs, $lm ) ) { $tTitle = $lm[1]; }
                    if ( preg_match( '/\bauthor\s*=\s*"([^"]*)"/i', $attrs, $am ) ) { $author = $am[1]; }
                    if ( $tTitle === '' ) { $tTitle = $slug; }
                    $norm = MigrateIndicationsToGlobal::canonical( $tTitle );
                    if ( !isset( $slugMap[ $norm ] ) ) { return $m[0]; }
                    $newRef = $slugMap[ $norm ];
                    $newAttrs = ' ref="' . $newRef . '"';
                    if ( $author !== '' ) { $newAttrs .= ' author="' . $author . '"'; }
                    $changed++;
                    return '<indication' . $newAttrs . $rest;
                },
                $wt
            );
            if ( $changed === 0 ) {
                $this->output( "  {$title->getPrefixedText()}: nothing to migrate\n" );
                continue;
            }
            $this->output( "  {$title->getPrefixedText()}: $changed indication tag(s) rewritten\n" );

            $newContent = $contentHandler->unserializeContent( $newWt );
            $updater = $page->newPageUpdater( $user );
            $updater->setContent( SlotRecord::MAIN, $newContent );
            $summary = CommentStoreComment::newUnsavedComment(
                "Migrated $changed indication tag(s) to global ref="
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

        $this->output( "\nMigration complete.\n" );
    }

    public static function canonical( $s ) {
        $s = strtolower( trim( $s ) );
        $s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
        return preg_replace( '/\s+/', ' ', trim( $s ) );
    }
}

$maintClass = MigrateIndicationsToGlobal::class;
require_once RUN_MAINTENANCE_IF_MAIN;
