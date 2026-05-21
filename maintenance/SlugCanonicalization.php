<?php
/**
 * Audit slug hygiene across pcp_interactions ahead of Phase 4 (herbal
 * medicines sprint). Coordinated with interface-claude 2026-05-20:
 * doing this on a clean baseline beats weaving canonicalization into
 * the herbal ingest pipeline.
 *
 * For each (pi_left_type, pi_left_slug) and (pi_right_type, pi_right_slug),
 * compute the canonical form per type:
 *
 *   medicine / category : first-letter cap, spaces -> underscores
 *   enzyme / transporter: UPPERCASE
 *   phenotype           : lowercase
 *   variant             : case-preserved (allows HLA star-allele etc.)
 *
 * Plus universal hygiene rules:
 *   - No en-dashes (U+2013) in identifiers — the documented papercut
 *     from Serotonin–Norepinephrine_Reuptake_Inhibitors_(SNRIs). Strip
 *     to hyphen-minus.
 *   - No em-dashes (U+2014) in identifiers — same treatment.
 *   - No double underscores or leading/trailing whitespace.
 *
 * Idempotent. Read-only by default; --fix bulk-updates non-canonical
 * slugs to their canonical form with collision detection. Same flag
 * pattern as the other audits (--fix, --verbose, --json=).
 *
 * Usage:
 *   sudo -u www-data php maintenance/run.php \
 *     extensions/Pharmacopedia/maintenance/SlugCanonicalization.php \
 *     [--fix] [--verbose] [--json=/tmp/slug_canonicalization_report.json]
 */
$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\Pharmacopedia\InteractionStore;

class SlugCanonicalization extends Maintenance {

    public function __construct() {
        parent::__construct();
        $this->addOption( 'fix', 'Apply canonical-form rewrites', false, false );
        $this->addOption( 'verbose', 'Print every row touched', false, false );
        $this->addOption( 'json', 'Write structured report to path', false, true );
        $this->requireExtension( 'Pharmacopedia' );
    }

    public function execute() {
        $fix = $this->hasOption( 'fix' );
        $verbose = $this->hasOption( 'verbose' );
        $services = MediaWikiServices::getInstance();
        $dbr = $services->getConnectionProvider()->getReplicaDatabase();
        $dbw = $services->getConnectionProvider()->getPrimaryDatabase();

        // Walk every distinct (type, slug) pair.
        $seen = [];   // "type|slug" => [ "side|pi_id", ... ]
        $rows = $dbr->select( 'pcp_interactions',
            [ 'pi_id', 'pi_left_type', 'pi_left_slug',
                       'pi_right_type', 'pi_right_slug' ],
            [], __METHOD__ );
        foreach ( $rows as $r ) {
            $lkey = (string)$r->pi_left_type . '|' . (string)$r->pi_left_slug;
            $rkey = (string)$r->pi_right_type . '|' . (string)$r->pi_right_slug;
            if ( !isset( $seen[$lkey] ) ) $seen[$lkey] = [];
            $seen[$lkey][] = 'left|' . (int)$r->pi_id;
            if ( !isset( $seen[$rkey] ) ) $seen[$rkey] = [];
            $seen[$rkey][] = 'right|' . (int)$r->pi_id;
        }
        $this->output( "Distinct (type, slug) pairs in pcp_interactions: "
            . count( $seen ) . "\n\n" );

        // Classify each into canonical / non-canonical buckets.
        $bucketsByKind = [
            'en_dash'    => [],
            'em_dash'    => [],
            'whitespace' => [],
            'double_underscore' => [],
            'wrong_case'  => [],
        ];
        $fixPlan = [];   // [ type, oldSlug, newSlug, refs[] ]
        $canonical = 0;
        foreach ( $seen as $key => $refs ) {
            [ $type, $slug ] = explode( '|', $key, 2 );
            $canonForm = self::canonicalize( $type, $slug );
            if ( $canonForm === $slug ) { $canonical++; continue; }
            // Classify which rule(s) tripped.
            $kinds = self::classifyDelta( $slug, $canonForm );
            foreach ( $kinds as $k ) $bucketsByKind[$k][] = [ $type, $slug, $canonForm ];
            $fixPlan[] = [ $type, $slug, $canonForm, $refs ];
        }

        $this->output( "=== SLUG HYGIENE REPORT ===\n" );
        $this->output( sprintf( "  canonical                %3d\n", $canonical ) );
        foreach ( $bucketsByKind as $kind => $list ) {
            if ( $list ) $this->output( sprintf( "  %-24s %3d\n", $kind, count( $list ) ) );
        }
        $this->output( sprintf( "  TOTAL non-canonical      %3d\n\n", count( $fixPlan ) ) );

        if ( $fixPlan ) {
            $this->output( "--- NON-CANONICAL SLUGS ---\n" );
            foreach ( $fixPlan as [ $type, $old, $new, $refs ] ) {
                $n = count( $refs );
                $this->output( sprintf( "  %s:%-50s -> %s  (%d row%s)\n",
                    $type, $old, $new, $n, $n === 1 ? '' : 's' ) );
            }
            $this->output( "\n" );
        }

        // Apply fixes.
        $applied = 0;
        $collisions = 0;
        if ( $fix && $fixPlan ) {
            $this->output( "--- APPLYING --fix updates ---\n" );
            foreach ( $fixPlan as [ $type, $old, $new, $refs ] ) {
                foreach ( $refs as $ref ) {
                    [ $side, $piIdStr ] = explode( '|', $ref, 2 );
                    $piId = (int)$piIdStr;
                    $row = $dbr->selectRow( 'pcp_interactions', '*',
                        [ 'pi_id' => $piId ], __METHOD__ );
                    if ( !$row ) continue;
                    $col = $side === 'left' ? 'pi_left_slug' : 'pi_right_slug';
                    // Collision check: would the rewritten 5-tuple clash with
                    // an existing canonical row?
                    $clashConds = [
                        'pi_left_type'    => $row->pi_left_type,
                        'pi_left_slug'    => $side === 'left' ? $new : $row->pi_left_slug,
                        'pi_right_type'   => $row->pi_right_type,
                        'pi_right_slug'   => $side === 'right' ? $new : $row->pi_right_slug,
                        'pi_relationship' => $row->pi_relationship,
                    ];
                    $clash = $dbr->selectRow( 'pcp_interactions', 'pi_id',
                        $clashConds, __METHOD__ );
                    if ( $clash && (int)$clash->pi_id !== $piId ) {
                        $this->output( sprintf(
                            "  COLLISION pi_id=%d  %s:%s -> %s  clashes with pi_id=%d; deleting duplicate\n",
                            $piId, $type, $old, $new, (int)$clash->pi_id ) );
                        ( new InteractionStore() )->deleteInteraction(
                            (int)$row->pi_element_id );
                        $collisions++;
                        continue;
                    }
                    $dbw->update( 'pcp_interactions',
                        [ $col => $new ],
                        [ 'pi_id' => $piId ], __METHOD__ );
                    $applied++;
                    if ( $verbose ) {
                        $this->output( sprintf( "  pi_id=%-5d  %s: %s -> %s\n",
                            $piId, $col, $old, $new ) );
                    }
                }
            }
            $this->output( sprintf( "\nApplied: %d row updates (%d collisions resolved by delete)\n",
                $applied, $collisions ) );
        } elseif ( $fixPlan ) {
            $this->output( "(Run with --fix to canonicalize.)\n" );
        }

        $jsonOut = $this->getOption( 'json' );
        if ( $jsonOut ) {
            $payload = [
                'date' => date( 'Y-m-d H:i:s' ),
                'distinct_pairs' => count( $seen ),
                'canonical'      => $canonical,
                'non_canonical'  => count( $fixPlan ),
                'by_kind'        => array_map( 'count', array_filter(
                    $bucketsByKind, fn( $l ) => !empty( $l ) ) ),
                'fix_plan'       => array_map( function ( $f ) {
                    return [ 'type' => $f[0], 'old' => $f[1], 'new' => $f[2],
                             'refs' => $f[3] ];
                }, $fixPlan ),
                'applied'        => $applied,
                'collisions'     => $collisions,
            ];
            @file_put_contents( $jsonOut, json_encode( $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            @chmod( $jsonOut, 0644 );
            $this->output( "\nJSON report written to $jsonOut\n" );
        }
    }

    /**
     * Compute the canonical form of a slug for a given endpoint type.
     * Combines InteractionStore::normalizeSlug rules + universal hygiene
     * (en-dash / em-dash purge, whitespace cleanup, double-underscore
     * collapse).
     */
    public static function canonicalize( string $type, string $slug ): string {
        // Universal hygiene first.
        $s = trim( $slug );
        // Replace en-dash + em-dash with hyphen-minus.
        $s = str_replace( [ "\xe2\x80\x93", "\xe2\x80\x94" ], '-', $s );
        // Collapse whitespace to single underscore.
        $s = preg_replace( '/\s+/u', '_', $s );
        // Collapse double underscores.
        $s = preg_replace( '/__+/', '_', $s );
        if ( $s === '' ) return '';
        // Type-aware casing.
        switch ( $type ) {
            case 'enzyme':
            case 'transporter':
                return strtoupper( $s );
            case 'phenotype':
                return strtolower( $s );
            case 'variant':
                return $s; // case-preserved
            case 'medicine':
            case 'category':
            default:
                return mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 );
        }
    }

    /** Return one or more delta-kind labels comparing original to canonical. */
    public static function classifyDelta( string $orig, string $canon ): array {
        $kinds = [];
        if ( strpos( $orig, "\xe2\x80\x93" ) !== false ) $kinds[] = 'en_dash';
        if ( strpos( $orig, "\xe2\x80\x94" ) !== false ) $kinds[] = 'em_dash';
        if ( trim( $orig ) !== $orig
             || preg_match( '/\s/u', $orig ) && strpos( $orig, '_' ) !== false
                 && trim( $orig ) === $orig ) {
            $kinds[] = 'whitespace';
        }
        if ( strpos( $orig, '__' ) !== false ) $kinds[] = 'double_underscore';
        if ( !$kinds ) $kinds[] = 'wrong_case';
        return $kinds;
    }
}
$maintClass = SlugCanonicalization::class;
require_once RUN_MAINTENANCE_IF_MAIN;
