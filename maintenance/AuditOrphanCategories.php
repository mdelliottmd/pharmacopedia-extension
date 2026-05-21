<?php
/**
 * Audit category slugs referenced in pcp_interactions against the live
 * NS_CATEGORY page table.
 *
 * For every row where pi_left_type='category' or pi_right_type='category',
 * check that a page exists at NS_CATEGORY with that slug. Categorize each
 * unique slug as:
 *
 *   OK              — Category:<slug> exists, non-redirect
 *   REDIRECT        — Category:<slug> exists as redirect to another
 *                     NS_CATEGORY page; --fix updates pcp_interactions
 *                     rows to point at the canonical target
 *   CROSS_NS_REDIR  — Category:<slug> redirects somewhere outside
 *                     NS_CATEGORY; not auto-fixable, manual review
 *   MISSING         — No page at NS_CATEGORY:<slug> at all
 *
 * Idempotent. Read-only by default. With --fix:
 *   - REDIRECT rows: bulk UPDATE pcp_interactions
 *     SET pi_left_slug/pi_right_slug = redirect target
 *   - MISSING and CROSS_NS_REDIR are never auto-fixed
 *
 * Designed to run after taxonomy restructures (e.g. interface-claude's
 * Phase 2 retag 2026-05-20). Same output shape as ValidatePgxInvariants.
 *
 * Usage:
 *   sudo -u www-data php maintenance/run.php extensions/Pharmacopedia/maintenance/AuditOrphanCategories.php \
 *     [--fix] [--verbose] [--json=/tmp/orphan_cat_report.json]
 */
$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;

class AuditOrphanCategories extends Maintenance {

    public function __construct() {
        parent::__construct();
        $this->addOption( 'fix', 'Apply redirect-target updates to pcp_interactions', false, false );
        $this->addOption( 'verbose', 'Print every row touched', false, false );
        $this->addOption( 'json', 'Write structured report to this path', false, true );
        $this->requireExtension( 'Pharmacopedia' );
    }

    public function execute() {
        $fix = $this->hasOption( 'fix' );
        $verbose = $this->hasOption( 'verbose' );
        $services = MediaWikiServices::getInstance();
        $dbr = $services->getConnectionProvider()->getReplicaDatabase();
        $dbw = $services->getConnectionProvider()->getPrimaryDatabase();

        // 1) Collect every category-typed slug referenced in pcp_interactions.
        $slugToRows = [];   // slug => [ [pi_id, side], ... ]
        $rows = $dbr->select( 'pcp_interactions',
            [ 'pi_id', 'pi_left_type', 'pi_left_slug', 'pi_right_type', 'pi_right_slug' ],
            [ $dbr->makeList( [
                'pi_left_type'  => 'category',
                'pi_right_type' => 'category',
            ], LIST_OR ) ],
            __METHOD__ );
        foreach ( $rows as $r ) {
            if ( $r->pi_left_type === 'category' ) {
                $slug = (string)$r->pi_left_slug;
                $slugToRows[$slug][] = [ (int)$r->pi_id, 'left' ];
            }
            if ( $r->pi_right_type === 'category' ) {
                $slug = (string)$r->pi_right_slug;
                $slugToRows[$slug][] = [ (int)$r->pi_id, 'right' ];
            }
        }
        $uniqueSlugs = array_keys( $slugToRows );
        $totalRefs = array_sum( array_map( 'count', $slugToRows ) );
        $this->output( "Category slugs referenced by pcp_interactions: "
            . count( $uniqueSlugs ) . " unique across $totalRefs references\n\n" );

        // 2) For each slug, classify against NS_CATEGORY.
        $buckets = [
            'ok'             => [],
            'redirect'       => [],   // slug => target slug
            'cross_ns_redir' => [],
            'missing'        => [],
        ];
        foreach ( $uniqueSlugs as $slug ) {
            $page = $dbr->selectRow( 'page',
                [ 'page_id', 'page_is_redirect' ],
                [ 'page_namespace' => 14, 'page_title' => $slug ],
                __METHOD__ );
            if ( !$page ) {
                $buckets['missing'][] = $slug;
                continue;
            }
            if ( (int)$page->page_is_redirect === 0 ) {
                $buckets['ok'][] = $slug;
                continue;
            }
            // Redirect — resolve target.
            $rd = $dbr->selectRow( 'redirect',
                [ 'rd_namespace', 'rd_title' ],
                [ 'rd_from' => (int)$page->page_id ],
                __METHOD__ );
            if ( !$rd ) {
                // Redirect flag set but no redirect row — treat as missing.
                $buckets['missing'][] = $slug;
                continue;
            }
            if ( (int)$rd->rd_namespace !== 14 ) {
                $buckets['cross_ns_redir'][$slug] = [
                    'target_ns' => (int)$rd->rd_namespace,
                    'target_title' => (string)$rd->rd_title,
                ];
                continue;
            }
            $buckets['redirect'][$slug] = (string)$rd->rd_title;
        }

        // 3) Report.
        $this->output( "=== CATEGORY AUDIT ===\n" );
        $this->output( sprintf( "  ok              %3d slugs\n", count( $buckets['ok'] ) ) );
        $this->output( sprintf( "  redirect        %3d slugs  (auto-fixable)\n", count( $buckets['redirect'] ) ) );
        $this->output( sprintf( "  cross_ns_redir  %3d slugs  (manual review)\n", count( $buckets['cross_ns_redir'] ) ) );
        $this->output( sprintf( "  missing         %3d slugs  (no NS_CATEGORY page)\n", count( $buckets['missing'] ) ) );
        $this->output( "\n" );

        if ( $buckets['redirect'] ) {
            $this->output( "--- REDIRECTS (slug -> canonical target; --fix updates pcp_interactions) ---\n" );
            foreach ( $buckets['redirect'] as $slug => $target ) {
                $n = count( $slugToRows[$slug] );
                $this->output( sprintf( "  %-50s -> %s  (%d row%s)\n",
                    $slug, $target, $n, $n === 1 ? '' : 's' ) );
            }
            $this->output( "\n" );
        }
        if ( $buckets['cross_ns_redir'] ) {
            $this->output( "--- CROSS-NAMESPACE REDIRECTS (manual fix needed) ---\n" );
            foreach ( $buckets['cross_ns_redir'] as $slug => $info ) {
                $n = count( $slugToRows[$slug] );
                $this->output( sprintf( "  %-50s -> NS=%d:%s  (%d row%s)\n",
                    $slug, $info['target_ns'], $info['target_title'],
                    $n, $n === 1 ? '' : 's' ) );
            }
            $this->output( "\n" );
        }
        if ( $buckets['missing'] ) {
            $this->output( "--- MISSING (no Category:<slug> page at all) ---\n" );
            foreach ( $buckets['missing'] as $slug ) {
                $n = count( $slugToRows[$slug] );
                $piIds = array_slice( array_map( fn( $x ) => $x[0], $slugToRows[$slug] ), 0, 6 );
                $this->output( sprintf( "  %-50s  (%d row%s: pi_id %s%s)\n",
                    $slug, $n, $n === 1 ? '' : 's',
                    implode( ',', $piIds ),
                    $n > 6 ? '...' : '' ) );
            }
            $this->output( "\n" );
        }

        // 4) --fix: apply redirect target updates.
        $applied = 0;
        if ( $fix && $buckets['redirect'] ) {
            $this->output( "--- APPLYING --fix updates ---\n" );
            foreach ( $buckets['redirect'] as $oldSlug => $newSlug ) {
                $sides = $slugToRows[$oldSlug];
                foreach ( $sides as [ $piId, $side ] ) {
                    // Determine collision: would the rewritten 5-tuple conflict
                    // with an existing row? If so, skip and report.
                    $col = $side === 'left' ? 'pi_left_slug' : 'pi_right_slug';
                    $row = $dbr->selectRow( 'pcp_interactions', '*',
                        [ 'pi_id' => $piId ], __METHOD__ );
                    if ( !$row ) continue;
                    $clashConds = [
                        'pi_left_type'    => $row->pi_left_type,
                        'pi_left_slug'    => $side === 'left' ? $newSlug : $row->pi_left_slug,
                        'pi_right_type'   => $row->pi_right_type,
                        'pi_right_slug'   => $side === 'right' ? $newSlug : $row->pi_right_slug,
                        'pi_relationship' => $row->pi_relationship,
                    ];
                    $clash = $dbr->selectRow( 'pcp_interactions', 'pi_id',
                        $clashConds, __METHOD__ );
                    if ( $clash && (int)$clash->pi_id !== $piId ) {
                        $this->output( sprintf(
                            "  COLLISION: pi_id=%d $col=%s -> %s would clash with pi_id=%d; deleting duplicate\n",
                            $piId, $oldSlug, $newSlug, (int)$clash->pi_id ) );
                        // The canonical target row already exists. Delete the
                        // duplicate that references the old slug.
                        ( new \MediaWiki\Extension\Pharmacopedia\InteractionStore() )
                            ->deleteInteraction( (int)$row->pi_element_id );
                        continue;
                    }
                    $dbw->update( 'pcp_interactions',
                        [ $col => $newSlug ],
                        [ 'pi_id' => $piId ], __METHOD__ );
                    $applied++;
                    if ( $verbose ) {
                        $this->output( sprintf( "  pi_id=%d  %s: %s -> %s\n",
                            $piId, $col, $oldSlug, $newSlug ) );
                    }
                }
            }
            $this->output( "\nApplied: $applied row updates\n" );
        } elseif ( $buckets['redirect'] ) {
            $this->output( "(Run with --fix to apply the " . count( $buckets['redirect'] )
                . " redirect-target updates above.)\n" );
        }

        // 5) Optional JSON dump.
        $jsonOut = $this->getOption( 'json' );
        if ( $jsonOut ) {
            $payload = [
                'date' => date( 'Y-m-d H:i:s' ),
                'unique_slugs'   => count( $uniqueSlugs ),
                'total_refs'     => $totalRefs,
                'ok'             => $buckets['ok'],
                'redirect'       => $buckets['redirect'],
                'cross_ns_redir' => $buckets['cross_ns_redir'],
                'missing'        => $buckets['missing'],
                'applied'        => $applied,
            ];
            @file_put_contents( $jsonOut, json_encode( $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
            @chmod( $jsonOut, 0644 );
            $this->output( "\nJSON report written to $jsonOut\n" );
        }
    }
}
$maintClass = AuditOrphanCategories::class;
require_once RUN_MAINTENANCE_IF_MAIN;
