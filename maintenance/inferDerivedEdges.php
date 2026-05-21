<?php
/**
 * Phase 1 PGx inference engine v1.
 *
 * Materializes derived medicine ↔ medicine edges from substrate × inhibitor
 * (and substrate × inducer) cross-products that share an enzyme or transporter.
 *
 * Output edges:
 *   pi_left_type / pi_right_type = 'medicine' (the two interacting meds)
 *   pi_relationship = 'pk_raises_via_<E>' / 'pk_lowers_via_<E>'
 *                     (outcome-named: exposure raised vs lowered;
 *                      prodrug substrates invert the mechanism->outcome map)
 *   pi_intensity    = floor(substrate.intensity * inhibitor.intensity / 100)
 *   pi_evidence     = 'derived'
 *   pi_mechanism    = "{Inhibitor} inhibits {Enzyme} (intensity {N});
 *                      {Substrate} is a {substrate_relationship} of {Enzyme}
 *                      (intensity {N})."
 *   pi_kinetics     = propagated from the inhibitor row (so mechanism-based
 *                      kinetic decay survives into the derived edge)
 *
 * Gating: derived_intensity must be ≥25 to materialize. Edges below the
 * gate are skipped.
 *
 * Idempotency: mark-and-sweep. Every existing derived row is captured
 * before regeneration; rows not re-emitted this run get deleted (including
 * their backing votable_element + any user reports — fresh-start each run).
 * Curated rows (evidence != 'derived') are never touched.
 *
 * Usage:
 *   sudo -u www-data php maintenance/run.php \
 *     extensions/Pharmacopedia/maintenance/inferDerivedEdges.php \
 *     --username=MDElliottMD [--dry-run] [--verbose]
 */

$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\Pharmacopedia\InteractionStore;
use MediaWiki\Extension\Pharmacopedia\IngestionLog;

class InferDerivedEdges extends Maintenance {

    /** Relationship buckets — what counts as a substrate / inhibitor / inducer edge. */
    private const SUBSTRATE_RELS = [ 'substrate_major', 'substrate_minor',
                                     'prodrug_activated_by',
                                     'substrate' /* transporter form */ ];
    private const INHIBITOR_RELS = [ 'inhibitor_strong', 'inhibitor_moderate',
                                     'inhibitor_weak', 'inhibitor' /* transporter */ ];
    private const INDUCER_RELS   = [ 'inducer_strong', 'inducer_moderate',
                                     'inducer_weak',   'inducer' /* transporter */ ];

    /** Gate: derived intensity below this is too weak to materialize. */
    private const GATE = 25;

    /** Endpoint types whose rows feed the cross-product. */
    private const PIVOT_TYPES = [ 'enzyme', 'transporter' ];

    public function __construct() {
        parent::__construct();
        $this->addOption( 'username', 'User to attribute derived rows to', true, true );
        $this->addOption( 'dry-run',  'Preview without writing',           false, false );
        $this->addOption( 'verbose',  'Print every pair considered',       false, false );
        $this->requireExtension( 'Pharmacopedia' );
    }

    public function execute() {
        $services = MediaWikiServices::getInstance();
        $user = $services->getUserFactory()->newFromName( $this->getOption( 'username' ) );
        if ( !$user || !$user->isRegistered() ) {
            $this->fatalError( "User not found: " . $this->getOption( 'username' ) );
        }
        $userId = (int)$user->getId();
        $dryRun  = $this->hasOption( 'dry-run' );
        $verbose = $this->hasOption( 'verbose' );

        $dbr = $services->getConnectionProvider()->getReplicaDatabase();
        $store = new InteractionStore();

        // 1) Snapshot every existing derived row keyed by canonical 5-tuple,
        //    so we can mark-and-sweep stale ones at the end.
        $beforeKeys = [];   // key => $row
        $before = $dbr->select( 'pcp_interactions', '*',
            [ 'pi_evidence' => 'derived' ], __METHOD__ );
        foreach ( $before as $r ) {
            $beforeKeys[ self::tupleKey( $r ) ] = $r;
        }
        $this->output( "Existing derived rows: " . count( $beforeKeys ) . "\n" );

        // 2) Enumerate every (enzyme|transporter) endpoint present in the table.
        //    Either side could carry it (canonicalization usually puts enzyme on
        //    left, but we look at both for safety).
        $pivots = [];
        foreach ( self::PIVOT_TYPES as $pt ) {
            $rows = $dbr->select( 'pcp_interactions',
                [ 'side_type' => 'pi_left_type', 'side_slug' => 'pi_left_slug' ],
                [ 'pi_left_type' => $pt ], __METHOD__, [ 'DISTINCT' ] );
            foreach ( $rows as $r ) $pivots[ $r->side_type . '|' . $r->side_slug ] = [ $r->side_type, $r->side_slug ];
            $rows = $dbr->select( 'pcp_interactions',
                [ 'side_type' => 'pi_right_type', 'side_slug' => 'pi_right_slug' ],
                [ 'pi_right_type' => $pt ], __METHOD__, [ 'DISTINCT' ] );
            foreach ( $rows as $r ) $pivots[ $r->side_type . '|' . $r->side_slug ] = [ $r->side_type, $r->side_slug ];
        }
        $this->output( "Pivot endpoints (enzymes + transporters): " . count( $pivots ) . "\n\n" );

        $emittedKeys = [];
        $stats = [ 'considered' => 0, 'gated_low' => 0, 'gated_self' => 0,
                   'gated_curated' => 0, 'inserted' => 0, 'updated' => 0,
                   'unchanged' => 0, 'deleted_stale' => 0 ];

        // Provenance: open the audit-log row so each inserted derived edge
        // can stamp pi_ingestion_id. Closed at end with finishRun().
        $logId = $dryRun ? 0
            : IngestionLog::startRun( 'derived', 'inference-v1-2026-05-18' );

        foreach ( $pivots as $pivot ) {
            [ $pType, $pSlug ] = $pivot;
            $endpointRows = $store->listForEndpoint( $pType, $pSlug );
            $substrates = []; $inhibitors = []; $inducers = [];
            foreach ( $endpointRows as $r ) {
                $rel = (string)$r->pi_relationship;
                [ $medType, $medSlug ] = self::otherSide( $r, $pType, $pSlug );
                if ( $medType !== InteractionStore::TYPE_MEDICINE ) continue; // only med-med PK derived
                $entry = [ 'row' => $r, 'med_slug' => $medSlug, 'rel' => $rel ];
                if ( in_array( $rel, self::SUBSTRATE_RELS, true ) )       $substrates[] = $entry;
                elseif ( in_array( $rel, self::INHIBITOR_RELS, true ) )   $inhibitors[] = $entry;
                elseif ( in_array( $rel, self::INDUCER_RELS,   true ) )   $inducers[]   = $entry;
            }
            if ( !$substrates ) continue;

            // Outcome-aware derived relationship. The code names the
            // clinical OUTCOME (exposure raised vs lowered), not the
            // mechanism, because a prodrug inverts the mechanism->outcome
            // mapping: for a normal substrate, enzyme inhibition raises
            // exposure; for a prodrug the enzyme MAKES the active drug, so
            // inhibition LOWERS active exposure. Mechanism (inhibit/induce)
            // stays in the mechanism prose.
            $sources = [
                [ $inhibitors, 'inhibits', $substrates, true  ],   // isInhibitor
                [ $inducers,   'induces',  $substrates, false ],
            ];
            foreach ( $sources as [ $modulators, $verb, $subList, $isInhibitor ] ) {
                foreach ( $subList as $s ) {
                    $isProdrug = ( ( $s['rel'] ?? '' ) === 'prodrug_activated_by' );
                    foreach ( $modulators as $m ) {
                        $stats['considered']++;
                        if ( strcasecmp( $s['med_slug'], $m['med_slug'] ) === 0 ) {
                            $stats['gated_self']++;
                            if ( $verbose ) $this->output( "    self-pair skipped: {$s['med_slug']}\n" );
                            continue;
                        }
                        $sI = (int)( $s['row']->pi_intensity ?? 0 );
                        $mI = (int)( $m['row']->pi_intensity ?? 0 );
                        if ( $sI <= 0 || $mI <= 0 ) { $stats['gated_low']++; continue; }
                        $derivedI = (int)floor( $sI * $mI / 100 );
                        if ( $derivedI < self::GATE ) {
                            $stats['gated_low']++;
                            if ( $verbose ) $this->output( sprintf(
                                "    GATED %s × %s via %s: derived=%d (<%d)\n",
                                $s['med_slug'], $m['med_slug'], $pSlug, $derivedI, self::GATE ) );
                            continue;
                        }

                        // raises = inhibitor XOR prodrug. (normal+inhibitor,
                        // prodrug+inducer -> raised; normal+inducer,
                        // prodrug+inhibitor -> lowered.)
                        $raises = ( $isInhibitor !== $isProdrug );
                        $derivedRel = ( $raises ? 'pk_raises_via_' : 'pk_lowers_via_' )
                            . $pSlug;
                        $outcomeWord = $raises ? 'raised' : 'lowered';
                        $mech = sprintf(
                            "%s %s %s (%s, intensity %d); %s is a %s of %s (intensity %d). "
                            . "Derived: %s exposure %s.",
                            $m['med_slug'], $verb, $pSlug, $m['rel'], $mI,
                            $s['med_slug'], $s['rel'], $pSlug, $sI,
                            $s['med_slug'], $outcomeWord
                        );
                        $opts = [
                            'relationship' => $derivedRel,
                            'intensity'    => $derivedI,
                            'evidence'     => 'derived',
                            'mechanism'    => $mech,
                            'kinetics'     => $m['row']->pi_kinetics
                                ? (string)$m['row']->pi_kinetics : null,
                            'ingestion_id' => $logId,
                        ];

                        // Compute canonical tuple key for tracking.
                        $pair = InteractionStore::normalizePair(
                            InteractionStore::TYPE_MEDICINE, $s['med_slug'],
                            InteractionStore::TYPE_MEDICINE, $m['med_slug'] );
                        if ( !$pair ) continue;
                        [ $nlt, $nls, $nrt, $nrs ] = $pair;
                        $key = self::tupleKeyParts( $nlt, $nls, $nrt, $nrs, $derivedRel );

                        // Curated row protection: if a non-derived row exists for this
                        // exact 5-tuple, don't clobber it.
                        $clash = $dbr->selectRow( 'pcp_interactions', '*',
                            [ 'pi_left_type' => $nlt, 'pi_left_slug' => $nls,
                              'pi_right_type' => $nrt, 'pi_right_slug' => $nrs,
                              'pi_relationship' => $derivedRel ], __METHOD__ );
                        if ( $clash && (string)$clash->pi_evidence !== 'derived' ) {
                            $stats['gated_curated']++;
                            if ( $verbose ) $this->output( "    curated row protected: $key\n" );
                            continue;
                        }

                        $label = sprintf( "%s <-> %s via %s (intensity %d)",
                            $s['med_slug'], $m['med_slug'], $pSlug, $derivedI );
                        if ( $dryRun ) {
                            $tag = isset( $beforeKeys[$key] ) ? "(would-update)" : "(WOULD-INSERT)";
                            $this->output( "  $tag $label\n" );
                            $emittedKeys[$key] = true;
                            continue;
                        }

                        $row = $store->getOrCreate(
                            InteractionStore::TYPE_MEDICINE, $s['med_slug'],
                            InteractionStore::TYPE_MEDICINE, $m['med_slug'],
                            $userId, $opts );
                        if ( !$row ) continue;
                        $emittedKeys[$key] = true;

                        if ( !isset( $beforeKeys[$key] ) ) {
                            $stats['inserted']++;
                            $this->output( "  + INSERTED  $label\n" );
                        } else {
                            // Compare metadata to classify upsert.
                            $prev = $beforeKeys[$key];
                            $changed = false;
                            foreach ( [ 'pi_intensity', 'pi_evidence',
                                        'pi_mechanism', 'pi_kinetics' ] as $col ) {
                                if ( (string)( $prev->{$col} ?? '' )
                                  !== (string)( $row->{$col} ?? '' ) ) { $changed = true; break; }
                            }
                            if ( $changed ) { $stats['updated']++;   $this->output( "  ~ UPDATED   $label\n" ); }
                            else            { $stats['unchanged']++; if ( $verbose ) $this->output( "    unchanged $label\n" ); }
                            // Force-stamp pi_ingestion_id on the upsert path
                            // so derived rows track the LATEST inference run
                            // rather than the first computed (per designer-
                            // claude 2026-05-19 "last computed" semantic for
                            // materialized derived edges).
                            if ( (int)( $row->pi_ingestion_id ?? 0 ) !== $logId ) {
                                MediaWiki\MediaWikiServices::getInstance()
                                    ->getConnectionProvider()->getPrimaryDatabase()
                                    ->update( 'pcp_interactions',
                                        [ 'pi_ingestion_id' => $logId ],
                                        [ 'pi_id' => (int)$row->pi_id ], __METHOD__ );
                            }
                        }
                    }
                }
            }
        }

        // 3) Sweep stale: anything in beforeKeys not in emittedKeys.
        $stale = array_diff_key( $beforeKeys, $emittedKeys );
        foreach ( $stale as $key => $prev ) {
            if ( $dryRun ) {
                $this->output( "  - WOULD-DELETE stale: $key\n" );
                continue;
            }
            $store->deleteInteraction( (int)$prev->pi_element_id );
            $stats['deleted_stale']++;
            $this->output( "  - DELETED stale: $key\n" );
        }

        $this->output( "\nSummary:\n" );
        foreach ( $stats as $k => $v ) $this->output( sprintf( "  %-15s %d\n", $k, $v ) );

        if ( !$dryRun ) {
            $note = sprintf( "Inference v1: %d inserted, %d updated, %d unchanged, "
                . "%d stale-deleted; %d considered, %d gated-low, %d gated-self, "
                . "%d curated-protected.",
                $stats['inserted'], $stats['updated'], $stats['unchanged'],
                $stats['deleted_stale'], $stats['considered'], $stats['gated_low'],
                $stats['gated_self'], $stats['gated_curated'] );
            IngestionLog::finishRun(
                $logId,
                $stats['inserted'], $stats['updated'] + $stats['deleted_stale'],
                $note
            );
            $this->output( "Logged as pcp_ingestion_log.il_id=$logId\n" );
        }
    }

    /** Pull the non-pivot side of a pcp_interactions row. */
    private static function otherSide( $r, string $pivotType, string $pivotSlug ): array {
        if ( (string)$r->pi_left_type === $pivotType
             && (string)$r->pi_left_slug === $pivotSlug ) {
            return [ (string)$r->pi_right_type, (string)$r->pi_right_slug ];
        }
        return [ (string)$r->pi_left_type, (string)$r->pi_left_slug ];
    }

    private static function tupleKey( $r ): string {
        return self::tupleKeyParts(
            (string)$r->pi_left_type, (string)$r->pi_left_slug,
            (string)$r->pi_right_type, (string)$r->pi_right_slug,
            (string)$r->pi_relationship );
    }
    private static function tupleKeyParts( string $lt, string $ls, string $rt, string $rs, string $rel ): string {
        return "$lt:$ls|$rt:$rs|$rel";
    }
}

$maintClass = InferDerivedEdges::class;
require_once RUN_MAINTENANCE_IF_MAIN;
