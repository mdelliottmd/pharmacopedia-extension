<?php
/**
 * Dump the pgx-relevant interaction data for a given medicine, structured
 * exactly the way the renderer should consume it. Useful for:
 *   - Validating the data shape before interface-claude wires up rendering
 *   - Smoke-testing a medicine's data after ingest
 *   - Providing a stable JSON view that a future API endpoint can mirror
 *
 * Output: nested JSON. Top-level keys:
 *   medicine: { slug, display, page_exists }
 *   tiers:    grouped by evidence (fda_box, cpic_strong, ..., derived) in
 *             priority order; each tier is a list of edges.
 *   edges:    flat list of every edge (same content, no grouping).
 *   counts:   {by_tier, by_relationship, by_other_type}
 *   kinetics: aggregated kinetic-class observations (for the "persists ~4-6
 *             weeks after stopping" UX hint).
 *
 * Each edge has:
 *   pi_id, element_id (for vote/comment linkage),
 *   other:        { type, slug, display, page_url }
 *   relationship, intensity, evidence, kinetics, mechanism,
 *   tier_rank:    number (for sorting; higher = stronger)
 *
 * Usage:
 *   sudo -u www-data php maintenance/run.php extensions/Pharmacopedia/maintenance/DumpPgxForRender.php \
 *     --medicine=Codeine [--pretty] [--out=/tmp/codeine_render.json]
 */
$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\Pharmacopedia\InteractionStore;
use MediaWiki\Extension\Pharmacopedia\KineticsHelper;

class DumpPgxForRender extends Maintenance {

    /** Visual tier ranking (matches the rendering priority the order specified). */
    private const TIER_RANK = [
        'fda_box'       => 100,
        'cpic_A'        => 95,
        'cpic_strong'   => 90,
        'fda_label'     => 85,
        'cpic_B'        => 75,
        'cpic_moderate' => 70,
        'cpic_C'        => 50,
        'cpic_optional' => 45,
        'cpic_D'        => 30,
        'dpwg'          => 60,
        'primary'       => 50,
        'theoretical'   => 20,
        'derived'       => 25,
        'ema_hmpc'      => 70,
        'who_monograph' => 65,
        'usp_hmc'       => 60,
        'msk_about'     => 45,
    ];

    /** Tier order for rendering (strongest first). */
    private const TIER_ORDER = [
        'fda_box', 'cpic_A', 'cpic_strong',
        'fda_label', 'cpic_B', 'cpic_moderate',
        'ema_hmpc', 'who_monograph',
        'dpwg', 'usp_hmc',
        'cpic_C', 'cpic_optional', 'msk_about', 'cpic_D',
        'primary', 'theoretical',
        'derived',
    ];

    /**
     * Spectrum registry: known phenotype-bearing genes and how their
     * phenotype tiers map onto an axis. Continuous-axis genes carry
     * activity-score bounds per tier; categorical-axis genes do not.
     *
     * Designer-claude 2026-05-19: axis.type ('continuous' vs 'categorical')
     * tells the renderer whether to draw a gradient strip or discrete chips.
     */
    private const SPECTRUM_REGISTRY = [
        'CYP2D6' => [
            'axis_type'  => 'continuous',
            'axis_label' => 'Activity score',
            'axis_low'   => 0.0,
            'axis_high'  => 2.25,
            'tiers' => [
                'pm' => [ 'label' => 'PM', 'axis_low' => 0.0,  'axis_high' => 0.0  ],
                'im' => [ 'label' => 'IM', 'axis_low' => 0.0,  'axis_high' => 1.25 ],
                'nm' => [ 'label' => 'NM', 'axis_low' => 1.25, 'axis_high' => 2.25 ],
                'um' => [ 'label' => 'UM', 'axis_low' => 2.25, 'axis_high' => null ],
            ],
        ],
        'CYP2C19' => [
            'axis_type'  => 'continuous',
            'axis_label' => 'Activity score',
            'axis_low'   => 0.0,
            'axis_high'  => 3.5,
            'tiers' => [
                'pm'  => [ 'label' => 'PM',  'axis_low' => 0.0,  'axis_high' => 0.0  ],
                'lpm' => [ 'label' => 'Likely PM',  'axis_low' => 0.0, 'axis_high' => 0.5 ],
                'im'  => [ 'label' => 'IM',  'axis_low' => 0.5,  'axis_high' => 1.25 ],
                'lim' => [ 'label' => 'Likely IM',  'axis_low' => 0.5, 'axis_high' => 1.25 ],
                'nm'  => [ 'label' => 'NM',  'axis_low' => 1.25, 'axis_high' => 2.25 ],
                'rm'  => [ 'label' => 'RM',  'axis_low' => 2.25, 'axis_high' => 3.0  ],
                'um'  => [ 'label' => 'UM',  'axis_low' => 3.0,  'axis_high' => null ],
            ],
        ],
        'CYP2C9' => [
            'axis_type'  => 'continuous',
            'axis_label' => 'Activity score',
            'axis_low'   => 0.0,
            'axis_high'  => 2.5,
            'tiers' => [
                'pm' => [ 'label' => 'PM', 'axis_low' => 0.0,  'axis_high' => 1.0  ],
                'im' => [ 'label' => 'IM', 'axis_low' => 1.0,  'axis_high' => 1.5  ],
                'nm' => [ 'label' => 'NM', 'axis_low' => 1.5,  'axis_high' => 2.5  ],
            ],
        ],
        'CYP3A4' => [
            'axis_type'  => 'categorical',
            'axis_label' => 'Metabolizer status',
            'tiers' => [
                'pm' => [ 'label' => 'PM' ],
                'im' => [ 'label' => 'IM' ],
                'nm' => [ 'label' => 'NM' ],
            ],
        ],
        'CYP3A5' => [
            'axis_type'  => 'categorical',
            'axis_label' => 'Expression',
            'tiers' => [
                'pm'  => [ 'label' => 'PM' ],
                'im'  => [ 'label' => 'IM' ],
                'pim' => [ 'label' => 'Possible IM' ],
                'nm'  => [ 'label' => 'NM' ],
            ],
        ],
        'TPMT' => [
            'axis_type'  => 'categorical',
            'axis_label' => 'Activity',
            'tiers' => [
                'pm'  => [ 'label' => 'PM' ],
                'im'  => [ 'label' => 'IM' ],
                'pim' => [ 'label' => 'Possible IM' ],
                'nm'  => [ 'label' => 'NM' ],
            ],
        ],
        'NUDT15' => [
            'axis_type'  => 'categorical',
            'axis_label' => 'Activity',
            'tiers' => [
                'pm'  => [ 'label' => 'PM' ],
                'im'  => [ 'label' => 'IM' ],
                'pim' => [ 'label' => 'Possible IM' ],
                'nm'  => [ 'label' => 'NM' ],
            ],
        ],
        'DPYD' => [
            'axis_type'  => 'categorical',
            'axis_label' => 'Activity',
            'tiers' => [
                'pm' => [ 'label' => 'PM' ],
                'im' => [ 'label' => 'IM' ],
                'nm' => [ 'label' => 'NM' ],
            ],
        ],
        'UGT1A1' => [
            'axis_type'  => 'categorical',
            'axis_label' => 'Activity',
            'tiers' => [
                'pm' => [ 'label' => 'PM' ],
                'im' => [ 'label' => 'IM' ],
                'nm' => [ 'label' => 'NM' ],
            ],
        ],
        'SLCO1B1' => [
            'axis_type'  => 'categorical',
            'axis_label' => 'Function',
            'tiers' => [
                'pf'  => [ 'label' => 'Poor Function' ],
                'df'  => [ 'label' => 'Decreased Function' ],
                'nf'  => [ 'label' => 'Normal Function' ],
                'if'  => [ 'label' => 'Increased Function' ],
                'ppf' => [ 'label' => 'Possible Poor Function' ],
                'pdf' => [ 'label' => 'Possible Decreased Function' ],
                'pif' => [ 'label' => 'Possible Increased Function' ],
            ],
        ],
        'MT-RNR1' => [
            'axis_type'  => 'categorical',
            'axis_label' => 'Aminoglycoside-induced hearing-loss risk',
            'tiers' => [
                'risk'      => [ 'label' => 'Increased risk' ],
                'normal'    => [ 'label' => 'Normal risk' ],
                'uncertain' => [ 'label' => 'Uncertain risk' ],
            ],
        ],
        'G6PD' => [
            'axis_type'  => 'categorical',
            'axis_label' => 'Activity',
            'tiers' => [
                'def'       => [ 'label' => 'Deficient' ],
                'def_cnsha' => [ 'label' => 'Deficient with CNSHA' ],
                'var'       => [ 'label' => 'Variable' ],
                'nm'        => [ 'label' => 'Normal' ],
            ],
        ],
        'CFTR' => [
            'axis_type'  => 'categorical',
            'axis_label' => 'Ivacaftor response',
            'tiers' => [
                'ivacaftor_r'  => [ 'label' => 'Responsive' ],
                'ivacaftor_nr' => [ 'label' => 'Non-responsive' ],
            ],
        ],
        'RYR1' => [
            'axis_type'  => 'categorical',
            'axis_label' => 'Malignant-hyperthermia susceptibility',
            'tiers' => [
                'mh_susc'   => [ 'label' => 'Susceptible' ],
                'uncertain' => [ 'label' => 'Uncertain' ],
            ],
        ],
        'CACNA1S' => [
            'axis_type'  => 'categorical',
            'axis_label' => 'Malignant-hyperthermia susceptibility',
            'tiers' => [
                'mh_susc'   => [ 'label' => 'Susceptible' ],
                'uncertain' => [ 'label' => 'Uncertain' ],
            ],
        ],
    ];

    public function __construct() {
        parent::__construct();
        $this->addOption( 'medicine', 'Medicine slug or display name', true, true );
        $this->addOption( 'pretty', 'Pretty-print JSON', false, false );
        $this->addOption( 'out', 'Write JSON to file path', false, true );
        $this->requireExtension( 'Pharmacopedia' );
    }

    public function execute() {
        $medInput = trim( (string)$this->getOption( 'medicine' ) );
        if ( $medInput === '' ) $this->fatalError( "--medicine required" );
        $slug = InteractionStore::normalizeSlug( $medInput, 'medicine' );

        $services = MediaWikiServices::getInstance();
        $dbr = $services->getConnectionProvider()->getReplicaDatabase();
        $store = new InteractionStore();

        // Resolve the wiki page (might not exist; pgx data still surfaces).
        $title = $services->getTitleFactory()->newFromText( str_replace( '_', ' ', $slug ) );
        $pageExists = $title && $title->exists();
        $pageUrl = $title ? $title->getLocalURL() : null;

        $rows = $store->listForEndpoint( 'medicine', $slug );

        $edges = [];
        $byTier = [];
        $relCount = [];
        $otherTypeCount = [];
        $kineticsObs = [];
        foreach ( $rows as $r ) {
            // Determine which side is "the other"
            if ( $r->pi_left_type === 'medicine' && $r->pi_left_slug === $slug ) {
                $oT = (string)$r->pi_right_type; $oS = (string)$r->pi_right_slug;
            } else {
                $oT = (string)$r->pi_left_type; $oS = (string)$r->pi_left_slug;
            }
            $ev = $r->pi_evidence === null ? null : (string)$r->pi_evidence;
            $rel = (string)$r->pi_relationship;
            $kin = $r->pi_kinetics === null ? null : (string)$r->pi_kinetics;

            $otherDisp = self::displayForOther( $oT, $oS );
            $otherTitle = $services->getTitleFactory()->newFromText( $otherDisp );
            $otherUrl = $otherTitle ? $otherTitle->getLocalURL() : null;

            $edge = [
                'pi_id'        => (int)$r->pi_id,
                'element_id'   => (int)$r->pi_element_id,
                'other' => [
                    'type'    => $oT,
                    'slug'    => $oS,
                    'display' => $otherDisp,
                    'page_url' => $otherUrl,
                ],
                'relationship' => $rel,
                'intensity'    => $r->pi_intensity === null ? null : (int)$r->pi_intensity,
                'evidence'     => $ev,
                'kinetics'     => $kin,
                'kinetics_hint' => KineticsHelper::getHint( $kin ),
                'mechanism'    => $r->pi_mechanism === null ? null : (string)$r->pi_mechanism,
                'tier_rank'    => self::TIER_RANK[ (string)$ev ] ?? 0,
            ];
            $edges[] = $edge;

            $tier = $ev ?? '(no_evidence)';
            $byTier[$tier][] = $edge;
            $relCount[$rel] = ( $relCount[$rel] ?? 0 ) + 1;
            $otherTypeCount[$oT] = ( $otherTypeCount[$oT] ?? 0 ) + 1;
            if ( $kin ) $kineticsObs[$kin] = ( $kineticsObs[$kin] ?? 0 ) + 1;
        }

        // Sort edges within each tier: intensity desc, then other.slug.
        foreach ( $byTier as $tier => &$list ) {
            usort( $list, function ( $a, $b ) {
                $i = ( $b['intensity'] ?? 0 ) <=> ( $a['intensity'] ?? 0 );
                if ( $i !== 0 ) return $i;
                return strcmp( $a['other']['slug'], $b['other']['slug'] );
            } );
        }
        unset( $list );

        // Re-order $byTier per TIER_ORDER, dropping empty tiers.
        $tiers = [];
        foreach ( self::TIER_ORDER as $t ) {
            if ( !empty( $byTier[$t] ) ) $tiers[$t] = $byTier[$t];
        }
        foreach ( $byTier as $t => $list ) {
            if ( !isset( $tiers[$t] ) && $list ) $tiers[$t] = $list;
        }

        // Sort flat edges by tier rank desc, then intensity desc.
        usort( $edges, function ( $a, $b ) {
            $r = ( $b['tier_rank'] ?? 0 ) <=> ( $a['tier_rank'] ?? 0 );
            if ( $r !== 0 ) return $r;
            return ( $b['intensity'] ?? 0 ) <=> ( $a['intensity'] ?? 0 );
        } );

        $spectrums = self::buildSpectrums( $edges );

        // Map-type fields are cast to (object) so they encode as a JSON
        // object even when empty. A bare empty PHP array json_encodes to
        // [] (array); a consumer expecting an object then breaks. Only
        // edges_flat stays a genuine list.
        $out = [
            'medicine' => [
                'slug'        => $slug,
                'display'     => str_replace( '_', ' ', $slug ),
                'page_exists' => $pageExists,
                'page_url'    => $pageUrl,
            ],
            'edge_count' => count( $edges ),
            'tiers' => (object)$tiers,
            'counts' => [
                'by_tier'      => (object)self::countTiers( $tiers ),
                'by_relationship' => (object)self::sortByCount( $relCount ),
                'by_other_type' => (object)self::sortByCount( $otherTypeCount ),
            ],
            'kinetics' => (object)self::sortByCount( $kineticsObs ),
            'phenotype_spectrums' => (object)$spectrums,
            'edges_flat' => $edges,
        ];

        $flags = $this->hasOption( 'pretty' )
            ? ( JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            : ( JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $json = json_encode( $out, $flags );

        $outPath = $this->getOption( 'out' );
        if ( $outPath ) {
            file_put_contents( $outPath, $json );
            @chmod( $outPath, 0644 );
            $this->output( "Wrote " . strlen( $json ) . " bytes to $outPath\n" );
            $this->output( "Summary: " . $out['edge_count'] . " edges across "
                . count( $tiers ) . " tiers (" . implode( ', ',
                array_map( fn( $t, $l ) => "$t:" . count( $l ), array_keys( $tiers ), $tiers ) )
                . ")\n" );
        } else {
            $this->output( $json . "\n" );
        }
    }

    /** Build the "namespace:slug" display string for an endpoint. */
    private static function displayForOther( string $type, string $slug ): string {
        $name = str_replace( '_', ' ', $slug );
        switch ( $type ) {
            case 'category':    return 'Category:' . $name;
            case 'enzyme':      return 'Enzyme:' . $name;
            case 'transporter': return 'Transporter:' . $name;
            case 'phenotype':   return 'Phenotype:' . $name;
            case 'variant':     return 'Variant:' . $name;
            default:            return $name;
        }
    }

    private static function countTiers( array $tiers ): array {
        $out = [];
        foreach ( $tiers as $t => $list ) $out[$t] = count( $list );
        return $out;
    }

    private static function sortByCount( array $m ): array {
        arsort( $m );
        return $m;
    }

    /**
     * Group phenotype edges by their gene-of-origin, then render them as
     * spectrum widgets the renderer can drive directly. Only emits a
     * spectrum block for genes the medicine actually has phenotype edges
     * in (codeine -> CYP2D6 only; amitriptyline -> CYP2D6 + CYP2C19).
     *
     * Schema:
     *   { GENE_SYMBOL: { axis: {type, label, [low, high]},
     *                    tiers: [ { slug, label, [axis_low, axis_high],
     *                               edges: [...] }, ... ] } }
     *
     * Designer-claude 2026-05-19 request.
     */
    private static function buildSpectrums( array $edges ): array {
        // Bucket phenotype edges by gene-prefix match against the registry.
        $byGene = [];
        foreach ( $edges as $e ) {
            if ( ( $e['other']['type'] ?? '' ) !== 'phenotype' ) continue;
            $slug = (string)( $e['other']['slug'] ?? '' );
            foreach ( self::SPECTRUM_REGISTRY as $gene => $spec ) {
                $prefix = strtolower( $gene ) . '_';
                if ( strncasecmp( $slug, $prefix, strlen( $prefix ) ) === 0 ) {
                    $byGene[$gene][] = $e;
                    break;
                }
            }
        }
        if ( !$byGene ) return [];

        $out = [];
        foreach ( $byGene as $gene => $geneEdges ) {
            $spec = self::SPECTRUM_REGISTRY[$gene];
            $axis = [
                'type'  => $spec['axis_type'],
                'label' => $spec['axis_label'],
            ];
            if ( $spec['axis_type'] === 'continuous' ) {
                $axis['low']  = $spec['axis_low'];
                $axis['high'] = $spec['axis_high'];
            }

            $tiers = [];
            foreach ( $spec['tiers'] as $tierKey => $tierMeta ) {
                $fullSlug = strtolower( $gene ) . '_' . $tierKey;
                $tierEdges = array_values( array_filter( $geneEdges,
                    function ( $e ) use ( $fullSlug ) {
                        return strcasecmp( (string)( $e['other']['slug'] ?? '' ), $fullSlug ) === 0;
                    } ) );
                $tier = [
                    'slug'  => $fullSlug,
                    'label' => $tierMeta['label'],
                    'edges' => $tierEdges,
                ];
                if ( $spec['axis_type'] === 'continuous' ) {
                    $tier['axis_low']  = $tierMeta['axis_low']  ?? null;
                    $tier['axis_high'] = $tierMeta['axis_high'] ?? null;
                }
                $tiers[] = $tier;
            }
            $out[$gene] = [ 'axis' => $axis, 'tiers' => $tiers ];
        }
        return $out;
    }
}
$maintClass = DumpPgxForRender::class;
require_once RUN_MAINTENANCE_IF_MAIN;
