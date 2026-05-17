<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

class EffectSummaryTag {
    public static function render( $input, array $args, $parser, $frame ) {
        $slug = isset( $args['slug'] ) ? trim( (string)$args['slug'] ) : '';
        if ( $slug === '' ) {
            return '<span class="pcp-error">&lt;effectsummary&gt;: slug required</span>';
        }
        $sort  = $args['sort'] ?? 'valence';
        $limit = isset( $args['limit'] ) ? max( 1, (int)$args['limit'] ) : 50;

        $db = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $res = $db->select(
            [ 've' => 'pcp_votable_elements', 'p' => 'page', 'er' => 'pcp_effect_reports' ],
            [
                'page_title' => 'p.page_title',
                've_id'      => 've.ve_id',
                // Patient aggregates (perspective=1)
                'p_n'    => "SUM(CASE WHEN er.er_perspective = 1 THEN 1 ELSE 0 END)",
                'p_yes'  => "SUM(CASE WHEN er.er_perspective = 1 AND er.er_experienced = 1 THEN 1 ELSE 0 END)",
                'p_vsum' => "SUM(CASE WHEN er.er_perspective = 1 THEN er.er_valence ELSE NULL END)",
                'p_vn'   => "SUM(CASE WHEN er.er_perspective = 1 AND er.er_valence IS NOT NULL THEN 1 ELSE 0 END)",
                // Provider aggregates (perspective=2)
                'pr_n'    => "SUM(CASE WHEN er.er_perspective = 2 THEN 1 ELSE 0 END)",
                'pr_fsum' => "SUM(CASE WHEN er.er_perspective = 2 THEN er.er_frequency_pct ELSE NULL END)",
                'pr_fn'   => "SUM(CASE WHEN er.er_perspective = 2 AND er.er_frequency_pct IS NOT NULL THEN 1 ELSE 0 END)",
                'pr_vsum' => "SUM(CASE WHEN er.er_perspective = 2 THEN er.er_valence ELSE NULL END)",
                'pr_vn'   => "SUM(CASE WHEN er.er_perspective = 2 AND er.er_valence IS NOT NULL THEN 1 ELSE 0 END)",
            ],
            [ 've.ve_slug' => $slug, 've.ve_type' => 'effect' ],
            __METHOD__,
            [ 'GROUP BY' => 've.ve_id', 'LIMIT' => $limit ],
            [
                'p'  => [ 'LEFT JOIN', 've.ve_page_id = p.page_id' ],
                'er' => [ 'LEFT JOIN', 'er.er_element_id = ve.ve_id' ],
            ]
        );
        $rows = [];
        foreach ( $res as $row ) { $rows[] = $row; }

        usort( $rows, function( $a, $b ) use ( $sort ) {
            if ( $sort === 'reported' ) {
                $ap = ((int)$a->p_n) > 0 ? $a->p_yes / $a->p_n : 0;
                $bp = ((int)$b->p_n) > 0 ? $b->p_yes / $b->p_n : 0;
                return $bp <=> $ap;
            }
            if ( $sort === 'frequency' ) {
                $af = ((int)$a->pr_fn) > 0 ? $a->pr_fsum / $a->pr_fn : -1;
                $bf = ((int)$b->pr_fn) > 0 ? $b->pr_fsum / $b->pr_fn : -1;
                return $bf <=> $af;
            }
            // Default: patient valence ascending (most negative first)
            $av = ((int)$a->p_vn) > 0 ? $a->p_vsum / $a->p_vn : 999;
            $bv = ((int)$b->p_vn) > 0 ? $b->p_vsum / $b->p_vn : 999;
            return $av <=> $bv;
        } );

        if ( !$rows ) {
            return '<div class="pcp-effect-summary"><em>No medicines have "' . htmlspecialchars( $slug ) . '" reported yet.</em></div>';
        }

        $out  = "{| class=\"wikitable\" style=\"font-size:90%;\"\n";
        $out .= "|+ '''" . htmlspecialchars( $slug ) . "''' across medicines\n";
        $out .= "! Medicine !! 👤 Patient !! ⚕️ Provider\n";
        foreach ( $rows as $r ) {
            $title = str_replace( '_', ' ', $r->page_title );

            $pN = (int)$r->p_n;
            $pPct = $pN > 0 ? (int)round( $r->p_yes / $pN * 100 ) : null;
            $pVMean = ((int)$r->p_vn) > 0 ? $r->p_vsum / $r->p_vn : null;

            $prN = (int)$r->pr_n;
            $prFMean = ((int)$r->pr_fn) > 0 ? (int)round( $r->pr_fsum / $r->pr_fn ) : null;
            $prVMean = ((int)$r->pr_vn) > 0 ? $r->pr_vsum / $r->pr_vn : null;

            if ( $pN > 0 ) {
                $patientCell = sprintf( '%d%% reported • %s (n=%d)',
                    $pPct,
                    $pVMean !== null ? sprintf( '%+.1f', $pVMean ) : '—',
                    $pN );
            } else {
                $patientCell = '—';
            }

            if ( $prN > 0 ) {
                $providerCell = sprintf( 'avg ~%s%% • %s (n=%d)',
                    $prFMean !== null ? $prFMean : '?',
                    $prVMean !== null ? sprintf( '%+.1f', $prVMean ) : '—',
                    $prN );
            } else {
                $providerCell = '—';
            }

            $out .= "|-\n| [[" . $title . "]] || " . $patientCell . " || " . $providerCell . "\n";
        }
        $out .= "|}\n";
        return $parser->recursiveTagParse( (string)$out, $frame );
    }
}
