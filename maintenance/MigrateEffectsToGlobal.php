<?php
/**
 * Walk every medicine page, find each <effect slug="..." label="..."> tag,
 * create / look up a matching pcp_effects row, and rewrite the tag in-place
 * to <effect ref="..."> while preserving the body.
 *
 * Usage:
 *   php run.php /var/www/mediawiki/extensions/Pharmacopedia/maintenance/MigrateEffectsToGlobal.php \
 *       --username=MDElliottMD [--dry-run]
 */

$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Extension\Pharmacopedia\GlobalEffectStore;

class MigrateEffectsToGlobal extends Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addOption( 'username', 'User to attribute new global effects to', true, true );
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

        $effectStore = new GlobalEffectStore();

        // Phase 1: collect all unique (slug, label) pairs across pages
        $byNormalized = [];  // normalizedLabel => [ slug, label, count ]
        foreach ( $pageRows as $row ) {
            $title = $titleFactory->makeTitle( $row->page_namespace, $row->page_title );
            $page = $wikiPageFactory->newFromTitle( $title );
            $content = $page->getContent();
            if ( !$content ) { continue; }
            $wt = method_exists( $content, 'getText' ) ? $content->getText() : (string)$content;
            preg_match_all(
                '/<effect\b([^>]*?)(?:\/\s*>|>[\s\S]*?<\/effect\s*>)/i',
                $wt, $matches );
            foreach ( $matches[1] as $attrs ) {
                if ( preg_match( '/\bref\s*=\s*"([^"]*)"/i', $attrs ) ) {
                    continue;  // already migrated
                }
                $slug = '';
                $label = '';
                if ( preg_match( '/\bslug\s*=\s*"([^"]*)"/i', $attrs, $m ) ) { $slug = $m[1]; }
                if ( preg_match( '/\blabel\s*=\s*"([^"]*)"/i', $attrs, $m ) ) { $label = $m[1]; }
                if ( $label === '' ) { $label = $slug; }
                if ( $label === '' ) { continue; }
                $norm = self::canonical( $label );
                if ( !isset( $byNormalized[ $norm ] ) ) {
                    $byNormalized[ $norm ] = [
                        'name' => $label, 'slug' => $slug, 'count' => 0,
                        'alternative_labels' => [],
                    ];
                }
                $byNormalized[ $norm ]['count']++;
                $byNormalized[ $norm ]['alternative_labels'][ $label ] = true;
            }
        }

        $this->output( "Found " . count( $byNormalized ) . " unique effects across " . $pageRows->numRows() . " pages.\n" );
        if ( $dryRun ) {
            foreach ( $byNormalized as $norm => $info ) {
                $this->output( "  $norm  →  name='{$info['name']}', count={$info['count']}\n" );
            }
            $this->output( "\n(dry-run — no rows created, no pages saved)\n" );
            return;
        }

        // Phase 2: create or find global effect entries
        $slugMap = [];  // normalizedLabel => canonical slug
        foreach ( $byNormalized as $norm => $info ) {
            $aliases = [];
            foreach ( $info['alternative_labels'] as $alt => $_ ) {
                if ( self::canonical( $alt ) !== $norm ) { continue; }
                $aliases[] = $alt;
            }
            // Use the first non-empty existing slug, else derive from name
            $useSlug = $info['slug'] !== '' ? $info['slug'] : GlobalEffectStore::normalizeSlug( $info['name'] );
            $id = $effectStore->create(
                $useSlug, $info['name'],
                /* description */ '',
                /* aliases */ implode( ', ', $aliases ),
                $user->getId()
            );
            if ( !$id ) {
                $this->output( "  SKIP: could not create '{$info['name']}'\n" );
                continue;
            }
            $row = $effectStore->getById( $id );
            $slugMap[ $norm ] = $row->e_slug;
            $this->output( "  ✓ '{$info['name']}' → ref=\"{$row->e_slug}\"\n" );
        }

        // Phase 3: rewrite each page's wikitext
        foreach ( $pageRows as $row ) {
            $title = $titleFactory->makeTitle( $row->page_namespace, $row->page_title );
            $page = $wikiPageFactory->newFromTitle( $title );
            $content = $page->getContent();
            if ( !$content ) { continue; }
            $wt = method_exists( $content, 'getText' ) ? $content->getText() : (string)$content;

            $changed = 0;
            $newWt = preg_replace_callback(
                '/<effect\b([^>]*?)((?:\/\s*>)|(?:>[\s\S]*?<\/effect\s*>))/i',
                function ( $m ) use ( $slugMap, &$changed ) {
                    $attrs = $m[1];
                    $rest = $m[2];
                    if ( preg_match( '/\bref\s*=\s*"/i', $attrs ) ) { return $m[0]; }
                    $slug = ''; $label = ''; $author = '';
                    if ( preg_match( '/\bslug\s*=\s*"([^"]*)"/i', $attrs, $sm ) ) { $slug = $sm[1]; }
                    if ( preg_match( '/\blabel\s*=\s*"([^"]*)"/i', $attrs, $lm ) ) { $label = $lm[1]; }
                    if ( preg_match( '/\bauthor\s*=\s*"([^"]*)"/i', $attrs, $am ) ) { $author = $am[1]; }
                    if ( $label === '' ) { $label = $slug; }
                    $norm = self::canonical( $label );
                    if ( !isset( $slugMap[ $norm ] ) ) { return $m[0]; }
                    $newRef = $slugMap[ $norm ];
                    $newAttrs = ' ref="' . $newRef . '"';
                    if ( $author !== '' ) { $newAttrs .= ' author="' . $author . '"'; }
                    $changed++;
                    return '<effect' . $newAttrs . $rest;
                },
                $wt
            );
            if ( $changed === 0 ) {
                $this->output( "  {$title->getPrefixedText()}: nothing to migrate\n" );
                continue;
            }
            $this->output( "  {$title->getPrefixedText()}: $changed effect tag(s) rewritten\n" );

            $newContent = $contentHandler->unserializeContent( $newWt );
            $updater = $page->newPageUpdater( $user );
            $updater->setContent( SlotRecord::MAIN, $newContent );
            $summary = CommentStoreComment::newUnsavedComment(
                "Migrated $changed effect tag(s) to global ref="
            );
            $updater->saveRevision( $summary, EDIT_UPDATE );
            $status = $updater->getStatus();
            if ( !$status || !$status->isOK() ) {
                $this->output( "    SAVE FAILED.\n" );
                continue;
            }
            // Auto-review since attribution is to a sysop
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

    private static function canonical( $s ) {
        $s = strtolower( trim( $s ) );
        $s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
        return preg_replace( '/\s+/', ' ', trim( $s ) );
    }
}

$maintClass = MigrateEffectsToGlobal::class;
require_once RUN_MAINTENANCE_IF_MAIN;
