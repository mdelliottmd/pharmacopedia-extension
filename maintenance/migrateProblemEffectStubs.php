<?php
/**
 * Migrate pcp_problem + pcp_effects to NS_PROBLEM (3008) + NS_EFFECT
 * (3010) wiki pages. For each non-retired row whose p_page_id /
 * e_page_id is NULL: build the stub wikitext, save the page via
 * WikiPageFactory + Author MDElliottMD, then UPDATE the row with the
 * returned page_id.
 *
 * Idempotent: any row already linked to an existing page is skipped.
 * Re-runnable: if the page was deleted, the row is re-linked on next
 * run; if the page exists but the link was lost, the link is repaired.
 */

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "CLI only\n" );
    exit( 1 );
}
require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

class MigrateProblemEffectStubs extends Maintenance {
    public function __construct() {
        parent::__construct();
        $this->requireExtension( 'Pharmacopedia' );
        $this->addDescription( 'Create NS_PROBLEM + NS_EFFECT stub pages '
            . 'for every non-retired row in pcp_problem and pcp_effects; '
            . 'backfill p_page_id / e_page_id.' );
        $this->addOption( 'dry-run',
            'Report what would be created, but do not write.', false, false );
    }

    public function execute() {
        $dry = $this->hasOption( 'dry-run' );
        $services = MediaWikiServices::getInstance();
        $dbw = $services->getConnectionProvider()->getPrimaryDatabase();
        $wpf = $services->getWikiPageFactory();
        $userFactory = $services->getUserFactory();
        $author = $userFactory->newFromName( 'MDElliottMD' );
        if ( !$author || !$author->isRegistered() ) {
            $this->fatalError( 'MDElliottMD user not found' );
        }
        $stats = [
            'problem_created' => 0, 'problem_linked' => 0,
            'problem_skipped' => 0, 'problem_already' => 0,
            'effect_created'  => 0, 'effect_linked' => 0,
            'effect_skipped'  => 0, 'effect_already' => 0,
        ];

        if ( !defined( 'NS_PROBLEM' ) || !defined( 'NS_EFFECT' ) ) {
            $this->fatalError( 'NS_PROBLEM / NS_EFFECT not defined; '
                . 'install LocalSettings namespace block first.' );
        }

        // ----- Problems -----
        $rows = $dbw->newSelectQueryBuilder()
            ->select( [ 'p_id', 'p_name', 'p_slug', 'p_description', 'p_page_id' ] )
            ->from( 'pcp_problem' )
            ->where( [ 'p_retired' => 0 ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        foreach ( $rows as $r ) {
            $name = (string)$r->p_name;
            $slug = (string)$r->p_slug;
            $desc = (string)( $r->p_description ?? '' );
            $title = Title::makeTitleSafe( NS_PROBLEM, $name );
            if ( !$title ) {
                $this->output( "  [skip:p:$slug] invalid title from p_name '$name'\n" );
                $stats['problem_skipped']++;
                continue;
            }
            if ( $r->p_page_id && (int)$r->p_page_id > 0 ) {
                $existing = Title::newFromID( (int)$r->p_page_id );
                if ( $existing && $existing->exists() ) {
                    $stats['problem_already']++;
                    continue;
                }
            }
            if ( $title->exists() ) {
                $pageId = $title->getArticleID();
                if ( !$dry ) {
                    $dbw->newUpdateQueryBuilder()
                        ->update( 'pcp_problem' )
                        ->set( [ 'p_page_id' => $pageId ] )
                        ->where( [ 'p_id' => (int)$r->p_id ] )
                        ->caller( __METHOD__ )
                        ->execute();
                }
                $this->output( "  [link:p:$slug] -> page_id=$pageId (existing page)\n" );
                $stats['problem_linked']++;
                continue;
            }
            $wikitext = self::problemStubWikitext( $name, $slug, $desc );
            if ( $dry ) {
                $this->output( "  [dry:p:$slug] would create '" . $title->getPrefixedText() . "'\n" );
                $stats['problem_created']++;
                continue;
            }
            $page = $wpf->newFromTitle( $title );
            $updater = $page->newPageUpdater( $author );
            $updater->setContent( SlotRecord::MAIN,
                new \MediaWiki\Content\WikitextContent( $wikitext ) );
            $rev = $updater->saveRevision(
                CommentStoreComment::newUnsavedComment(
                    'Auto-create stub from pcp_problem migration' ),
                EDIT_NEW | EDIT_INTERNAL
            );
            if ( !$rev ) {
                $this->output( "  [fail:p:$slug] saveRevision returned null\n" );
                $stats['problem_skipped']++;
                continue;
            }
            $pageId = $title->getArticleID();
            $dbw->newUpdateQueryBuilder()
                ->update( 'pcp_problem' )
                ->set( [ 'p_page_id' => $pageId ] )
                ->where( [ 'p_id' => (int)$r->p_id ] )
                ->caller( __METHOD__ )
                ->execute();
            $this->output( "  [new:p:$slug] -> page_id=$pageId\n" );
            $stats['problem_created']++;
        }

        // ----- Effects -----
        $rows = $dbw->newSelectQueryBuilder()
            ->select( [ 'e_id', 'e_name', 'e_slug', 'e_description', 'e_page_id' ] )
            ->from( 'pcp_effects' )
            ->where( [ 'e_retired' => 0 ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        foreach ( $rows as $r ) {
            $name = (string)$r->e_name;
            $slug = (string)$r->e_slug;
            $desc = (string)( $r->e_description ?? '' );
            $title = Title::makeTitleSafe( NS_EFFECT, $name );
            if ( !$title ) {
                $this->output( "  [skip:e:$slug] invalid title from e_name '$name'\n" );
                $stats['effect_skipped']++;
                continue;
            }
            if ( $r->e_page_id && (int)$r->e_page_id > 0 ) {
                $existing = Title::newFromID( (int)$r->e_page_id );
                if ( $existing && $existing->exists() ) {
                    $stats['effect_already']++;
                    continue;
                }
            }
            if ( $title->exists() ) {
                $pageId = $title->getArticleID();
                if ( !$dry ) {
                    $dbw->newUpdateQueryBuilder()
                        ->update( 'pcp_effects' )
                        ->set( [ 'e_page_id' => $pageId ] )
                        ->where( [ 'e_id' => (int)$r->e_id ] )
                        ->caller( __METHOD__ )
                        ->execute();
                }
                $this->output( "  [link:e:$slug] -> page_id=$pageId (existing page)\n" );
                $stats['effect_linked']++;
                continue;
            }
            $wikitext = self::effectStubWikitext( $name, $slug, $desc );
            if ( $dry ) {
                $this->output( "  [dry:e:$slug] would create '" . $title->getPrefixedText() . "'\n" );
                $stats['effect_created']++;
                continue;
            }
            $page = $wpf->newFromTitle( $title );
            $updater = $page->newPageUpdater( $author );
            $updater->setContent( SlotRecord::MAIN,
                new \MediaWiki\Content\WikitextContent( $wikitext ) );
            $rev = $updater->saveRevision(
                CommentStoreComment::newUnsavedComment(
                    'Auto-create stub from pcp_effects migration' ),
                EDIT_NEW | EDIT_INTERNAL
            );
            if ( !$rev ) {
                $this->output( "  [fail:e:$slug] saveRevision returned null\n" );
                $stats['effect_skipped']++;
                continue;
            }
            $pageId = $title->getArticleID();
            $dbw->newUpdateQueryBuilder()
                ->update( 'pcp_effects' )
                ->set( [ 'e_page_id' => $pageId ] )
                ->where( [ 'e_id' => (int)$r->e_id ] )
                ->caller( __METHOD__ )
                ->execute();
            $this->output( "  [new:e:$slug] -> page_id=$pageId\n" );
            $stats['effect_created']++;
        }

        $this->output( "\n=== migration summary ===\n" );
        foreach ( $stats as $k => $v ) {
            $this->output( sprintf( "  %-22s %d\n", $k, $v ) );
        }
        if ( $dry ) { $this->output( "\nDRY RUN; nothing written.\n" ); }
    }

    private static function problemStubWikitext( string $name, string $slug, string $desc ): string {
        $body  = "''Stub.'' A short clinical description of '''"
            . wfEscapeWikiText( $name ) . "''' goes here.\n\n";
        if ( $desc !== '' ) {
            $body .= wfEscapeWikiText( $desc ) . "\n\n";
        }
        $body .= "== Medicines used for " . wfEscapeWikiText( $name ) . " ==\n\n";
        $body .= "<problemMedicines slug=\"" . htmlspecialchars( $slug, ENT_QUOTES ) . "\" />\n\n";
        $body .= "[[Category:Problems]]\n[[Category:Problem stubs]]\n";
        return $body;
    }

    private static function effectStubWikitext( string $name, string $slug, string $desc ): string {
        $body  = "''Stub.'' A short clinical description of '''"
            . wfEscapeWikiText( $name ) . "''' goes here.\n\n";
        if ( $desc !== '' ) {
            $body .= wfEscapeWikiText( $desc ) . "\n\n";
        }
        $body .= "== Medicines that may cause " . wfEscapeWikiText( $name ) . " ==\n\n";
        $body .= "<effectMedicines slug=\"" . htmlspecialchars( $slug, ENT_QUOTES ) . "\" />\n\n";
        $body .= "[[Category:Effects]]\n[[Category:Effect stubs]]\n";
        return $body;
    }
}

$maintClass = MigrateProblemEffectStubs::class;
require_once RUN_MAINTENANCE_IF_MAIN;
