<?php
/**
 * Codeine pharmacogenomics sandbox seed (Phase 1 demo).
 *
 * Writes the canonical CPIC-2021 codeine edges into pcp_interactions so the
 * <pharmaInteractions/> renderer has real data to display while the full
 * CPIC API ingest is built. Source-of-record: Crews KR et al. Clin Pharmacol
 * Ther 2021;110(4):888-896 (PMID 33387367), plus FDA boxed-warning context.
 *
 * Edges seeded (11):
 *   Codeine <-> CYP2D6_UM / NM / IM / PM    (phenotype dosing recs)
 *   Codeine <-> CYP2D6 / CYP3A4 / UGT2B7    (metabolic substrates)
 *   Fluoxetine / Paroxetine / Bupropion / Quinidine <-> CYP2D6  (strong inhibitors)
 *
 * Idempotent: re-running upserts metadata onto existing rows.
 *
 * Usage:
 *   sudo -u www-data php maintenance/run.php \
 *     extensions/Pharmacopedia/maintenance/seedCodeinePgx.php \
 *     --username=MDElliottMD [--dry-run]
 */

$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\Pharmacopedia\InteractionStore;
use MediaWiki\Extension\Pharmacopedia\IngestionLog;

class SeedCodeinePgx extends Maintenance {

    public function __construct() {
        parent::__construct();
        $this->addOption( 'username', 'User to attribute the seed rows to', true, true );
        $this->addOption( 'dry-run', 'Preview without writing', false, false );
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
        $userId = (int)$user->getId();

        $edges = $this->edges();
        $store = new InteractionStore();
        $dbr = $services->getConnectionProvider()->getReplicaDatabase();

        $inserted = 0;
        $updated  = 0;
        $unchanged = 0;
        $errors = [];

        // Provenance: open the audit-log row up-front so each insert can stamp
        // pi_ingestion_id on creation. Closed at end with finishRun().
        $logId = $dryRun ? 0
            : IngestionLog::startRun( 'sandbox_seed', 'codeine-pgx-v1-2026-05-18' );

        $this->output( "Seeding " . count( $edges ) . " codeine PGx edges"
            . ( $dryRun ? " (DRY-RUN)" : "" ) . "\n\n" );

        foreach ( $edges as $edge ) {
            [ $lType, $lSlug ] = $edge['left'];
            [ $rType, $rSlug ] = $edge['right'];
            $rel = $edge['relationship'];

            // Normalize then check for prior row at this 5-tuple (used to
            // distinguish insert vs update for the audit log).
            $pair = InteractionStore::normalizePair( $lType, $lSlug, $rType, $rSlug );
            if ( !$pair ) {
                $errors[] = "Invalid pair: $lType:$lSlug <-> $rType:$rSlug";
                continue;
            }
            [ $nlt, $nls, $nrt, $nrs ] = $pair;
            $pre = $dbr->selectRow( 'pcp_interactions', '*', [
                'pi_left_type'   => $nlt, 'pi_left_slug' => $nls,
                'pi_right_type'  => $nrt, 'pi_right_slug' => $nrs,
                'pi_relationship' => $rel,
            ], __METHOD__ );

            $label = InteractionStore::pairLabel( $nlt, $nls, $nrt, $nrs, $rel );

            if ( $dryRun ) {
                $tag = $pre ? "(exists)" : "(NEW)";
                $this->output( sprintf(
                    "  %-9s %s\n             relationship=%s intensity=%s evidence=%s\n",
                    $tag, $label, $rel,
                    $edge['intensity'] ?? '-', $edge['evidence'] ?? '-' ) );
                continue;
            }

            $opts = [
                'relationship' => $rel,
                'intensity'    => $edge['intensity']  ?? null,
                'evidence'     => $edge['evidence']   ?? null,
                'mechanism'    => $edge['mechanism']  ?? null,
                'kinetics'     => $edge['kinetics']   ?? null,
                'ingestion_id' => $logId,
            ];
            $row = $store->getOrCreate( $lType, $lSlug, $rType, $rSlug, $userId, $opts );
            if ( !$row ) {
                $errors[] = "getOrCreate failed: $label";
                continue;
            }
            if ( !$pre ) {
                $inserted++;
                $this->output( "  + INSERTED  $label\n" );
            } else {
                $changed = false;
                foreach ( [ 'pi_intensity', 'pi_evidence', 'pi_mechanism', 'pi_kinetics' ] as $col ) {
                    if ( (string)( $pre->{$col} ?? '' ) !== (string)( $row->{$col} ?? '' ) ) {
                        $changed = true; break;
                    }
                }
                if ( $changed ) {
                    $updated++;
                    $this->output( "  ~ UPDATED   $label\n" );
                } else {
                    $unchanged++;
                    $this->output( "    unchanged $label\n" );
                }
            }
        }

        $this->output( "\nSummary: $inserted inserted, $updated updated, $unchanged unchanged, "
            . count( $errors ) . " errors.\n" );
        foreach ( $errors as $e ) $this->output( "  ERROR: $e\n" );

        if ( !$dryRun ) {
            $note = "codeine sandbox; "
                . "$inserted ins / $updated upd / $unchanged unchanged. "
                . "Sources: Crews PMID 33387367 (CPIC 2021); FDA codeine boxed warning (2013/2017).";
            IngestionLog::finishRun(
                $logId, $inserted, $updated,
                $note . ( $errors ? " errors: " . implode( '; ', $errors ) : "" )
            );
            $this->output( "Logged as pcp_ingestion_log.il_id=$logId\n" );
        }
    }

    /**
     * The 11 canonical codeine edges. Order matters only for the seed run's
     * output legibility, not for storage (normalizePair canonicalizes left/right).
     */
    private function edges(): array {
        $TM = InteractionStore::TYPE_MEDICINE;
        $TE = InteractionStore::TYPE_ENZYME;
        $TP = InteractionStore::TYPE_PHENOTYPE;
        $CITE_CREWS = "Crews KR et al, Clin Pharmacol Ther 2021;110(4):888-96 (PMID 33387367)";

        return [
            // ---- CYP2D6 phenotype dosing recommendations (CPIC 2021) ----
            [
                'left'  => [ $TM, 'Codeine' ],
                'right' => [ $TP, 'cyp2d6_um' ],
                'relationship' => 'avoid',
                'intensity'    => 90,
                'evidence'     => 'fda_box',
                'mechanism'    => "Avoid codeine. Rapid morphine conversion risks severe toxicity "
                                . "(respiratory depression, death documented in nursing infants of UM mothers "
                                . "and UM children post-tonsillectomy). FDA Boxed Warning; CPIC Strong. $CITE_CREWS.",
                'kinetics'     => null,
            ],
            [
                'left'  => [ $TM, 'Codeine' ],
                'right' => [ $TP, 'cyp2d6_nm' ],
                'relationship' => 'normal_dose',
                'intensity'    => 10,
                'evidence'     => 'cpic_strong',
                'mechanism'    => "Use label-recommended dosing. $CITE_CREWS.",
                'kinetics'     => null,
            ],
            [
                'left'  => [ $TM, 'Codeine' ],
                'right' => [ $TP, 'cyp2d6_im' ],
                'relationship' => 'monitor',
                'intensity'    => 25,
                'evidence'     => 'cpic_moderate',
                'mechanism'    => "Use label-recommended dosing. If response inadequate and tramadol is "
                                . "also unsuitable, consider a non-CYP2D6-dependent opioid (morphine, "
                                . "oxymorphone, hydromorphone). $CITE_CREWS.",
                'kinetics'     => null,
            ],
            [
                'left'  => [ $TM, 'Codeine' ],
                'right' => [ $TP, 'cyp2d6_pm' ],
                'relationship' => 'avoid',
                'intensity'    => 85,
                'evidence'     => 'cpic_strong',
                'mechanism'    => "Avoid codeine. Inadequate analgesia (cannot generate morphine). "
                                . "Do not default to tramadol (also CYP2D6-dependent). $CITE_CREWS.",
                'kinetics'     => null,
            ],
            // ---- Codeine metabolic substrate edges ----
            [
                'left'  => [ $TM, 'Codeine' ],
                'right' => [ $TE, 'CYP2D6' ],
                'relationship' => 'prodrug_activated_by',
                'intensity'    => 100,
                'evidence'     => 'primary',
                'mechanism'    => "O-demethylation to morphine, the active analgesic "
                                . "(5-15% of an oral codeine dose). Defines analgesic response.",
                'kinetics'     => 'reversible_competitive',
            ],
            [
                'left'  => [ $TM, 'Codeine' ],
                'right' => [ $TE, 'CYP3A4' ],
                'relationship' => 'substrate_minor',
                'intensity'    => 25,
                'evidence'     => 'primary',
                'mechanism'    => "N-demethylation to norcodeine; inactive pathway.",
                'kinetics'     => null,
            ],
            [
                'left'  => [ $TM, 'Codeine' ],
                'right' => [ $TE, 'UGT2B7' ],
                'relationship' => 'substrate_minor',
                'intensity'    => 30,
                'evidence'     => 'primary',
                'mechanism'    => "Glucuronidation to codeine-6-glucuronide; small analgesic contribution.",
                'kinetics'     => null,
            ],
            // ---- Strong CYP2D6 inhibitor inputs (inference-engine source data) ----
            [
                'left'  => [ $TM, 'Fluoxetine' ],
                'right' => [ $TE, 'CYP2D6' ],
                'relationship' => 'inhibitor_strong',
                'intensity'    => 85,
                'evidence'     => 'primary',
                'mechanism'    => "Chronic fluoxetine dosing substantially inhibits CYP2D6 "
                                . "via norfluoxetine accumulation; phenocopies an IM/PM.",
                'kinetics'     => 'mechanism_based',
            ],
            [
                'left'  => [ $TM, 'Paroxetine' ],
                'right' => [ $TE, 'CYP2D6' ],
                'relationship' => 'inhibitor_strong',
                'intensity'    => 90,
                'evidence'     => 'primary',
                'mechanism'    => "Strong CYP2D6 inhibitor; clinically converts NM to phenocopy PM "
                                . "at therapeutic doses.",
                'kinetics'     => 'mechanism_based',
            ],
            [
                'left'  => [ $TM, 'Bupropion' ],
                'right' => [ $TE, 'CYP2D6' ],
                'relationship' => 'inhibitor_strong',
                'intensity'    => 85,
                'evidence'     => 'primary',
                'mechanism'    => "Strong CYP2D6 inhibitor at therapeutic doses; "
                                . "reduces morphine generation from codeine.",
                'kinetics'     => 'reversible_competitive',
            ],
            [
                'left'  => [ $TM, 'Quinidine' ],
                'right' => [ $TE, 'CYP2D6' ],
                'relationship' => 'inhibitor_strong',
                'intensity'    => 95,
                'evidence'     => 'primary',
                'mechanism'    => "Classical experimental CYP2D6 probe inhibitor; near-complete "
                                . "blockade at typical antiarrhythmic doses.",
                'kinetics'     => 'reversible_competitive',
            ],
        ];
    }
}

$maintClass = SeedCodeinePgx::class;
require_once RUN_MAINTENANCE_IF_MAIN;
