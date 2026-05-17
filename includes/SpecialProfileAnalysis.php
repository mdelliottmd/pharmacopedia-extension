<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Sysop-only analysis dashboard for user profiles + experience reports.
 * Cross-table aggregates over pcp_user_profiles, pcp_profile_fields,
 * pcp_profile_diagnoses, pcp_user_meds, pcp_experience_reports.
 *
 * CSV export per section via ?section=X&format=csv.
 */
class SpecialProfileAnalysis extends SpecialPage {

    public function __construct() {
        parent::__construct( 'ProfileAnalysis', 'pharmacopedia-profile-view-others-full' );
    }

    public function execute( $par ) {
        $this->checkPermissions();
        $request = $this->getRequest();
        $section = $request->getVal( 'section', '' );
        $format  = $request->getVal( 'format', '' );

        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

        // CSV export branch
        if ( $format === 'csv' && $section !== '' ) {
            $this->emitCsv( $dbr, $section );
            return;
        }

        $this->setHeaders();
        $out = $this->getOutput();
        $out->setPageTitle( 'Profile analysis' );
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        $out->addHTML(
            '<div class="pcp-banner"><span class="pcp-banner__title">Sysop analysis</span>'
            . '<span class="pcp-banner__body"><strong>Identifiable data shown.</strong> '
            . 'Cross-table aggregates over profile, diagnoses, manually-added meds, and experience reports. '
            . 'Use the &laquo; CSV &raquo; link beside each section heading to download the underlying rows. '
            . '<br><a href="' . htmlspecialchars( \MediaWiki\SpecialPage\SpecialPage::getTitleFor( "ProfileFilter" )->getLocalURL() ) . '">&rarr; Cross-filter UI</a></span></div>'
        );

        $this->renderOverview( $out, $dbr );
        $this->renderDemographics( $out, $dbr );
        $this->renderOcean( $out, $dbr );
        $this->renderDiagnoses( $out, $dbr );
        $this->renderMedUsage( $out, $dbr );
        $this->renderAssessmentStats( $out, $dbr, \MediaWiki\Extension\Pharmacopedia\Assessments\Pid5bf::class );
        $this->renderAssessmentStats( $out, $dbr, \MediaWiki\Extension\Pharmacopedia\Assessments\Raadsr::class );
        $this->renderAssessmentStats( $out, $dbr, \MediaWiki\Extension\Pharmacopedia\Assessments\Catq::class );
        $this->renderAllProfiles( $out, $dbr );
    }

    // --- helpers ---

    private function csvLink( string $section ): string {
        $url = $this->getPageTitle()->getLocalURL( [ 'section' => $section, 'format' => 'csv' ] );
        return ' <a class="pcp-pa-csv" href="' . htmlspecialchars( $url ) . '">[CSV]</a>';
    }

    private function emitCsv( $dbr, string $section ) {
        $rows = [];
        $headers = [];
        switch ( $section ) {
            case 'overview':
                $headers = [ 'metric', 'value' ];
                $rows = $this->dataOverview( $dbr );
                break;
            case 'demographics':
                $headers = [ 'field', 'value', 'count' ];
                $rows = $this->dataDemographicsBreakdown( $dbr );
                break;
            case 'ocean':
                $headers = [ 'trait', 'n', 'mean', 'min', 'max' ];
                $rows = $this->dataOceanStats( $dbr );
                break;
            case 'diagnoses':
                $headers = [ 'system', 'description', 'code', 'count' ];
                $rows = $this->dataDiagnosesFrequency( $dbr );
                break;
            case 'medusage':
                $headers = [ 'medicine', 'reports', 'mean_efficacy', 'mean_burden' ];
                $rows = $this->dataMedUsage( $dbr );
                break;
            case 'pid5bf':
            case 'raadsr':
            case 'catq':
                $clsMap = [
                    'pid5bf' => \MediaWiki\Extension\Pharmacopedia\Assessments\Pid5bf::class,
                    'raadsr' => \MediaWiki\Extension\Pharmacopedia\Assessments\Raadsr::class,
                    'catq'   => \MediaWiki\Extension\Pharmacopedia\Assessments\Catq::class,
                ];
                $cls = $clsMap[ $section ];
                $headers = [ 'metric', 'n', 'mean', 'min', 'max' ];
                $rows = $this->dataAssessmentStats( $dbr, $cls );
                break;
            case 'profiles':
                $headers = [ 'user_id', 'username', 'public_alias', 'show_default', 'fields', 'diagnoses', 'manual_meds', 'experience_reports', 'created' ];
                $rows = $this->dataAllProfiles( $dbr );
                break;
            default:
                $headers = [ 'error' ];
                $rows = [ [ 'unknown section: ' . $section ] ];
        }
        $response = $this->getRequest()->response();
        $response->header( 'Content-Type: text/csv; charset=utf-8' );
        $response->header( "Content-Disposition: attachment; filename=\"pharmacopedia-{$section}.csv\"" );
        $fp = fopen( 'php://output', 'w' );
        fputcsv( $fp, $headers );
        foreach ( $rows as $r ) { fputcsv( $fp, CsvHelper::safeRow( $r ) ); }
        fclose( $fp );
        $this->getOutput()->disable();
    }

    // --- data + render: Overview ---

    private function dataOverview( $dbr ): array {
        $count = function ( $table, $where = [] ) use ( $dbr ) {
            return (int)$dbr->selectField( $table, 'COUNT(*)', $where, __METHOD__ );
        };
        return [
            [ 'total_profiles',         $count( 'pcp_user_profiles' ) ],
            [ 'fields_filled',          $count( 'pcp_profile_fields' ) ],
            [ 'diagnoses_logged',       $count( 'pcp_profile_diagnoses' ) ],
            [ 'manual_meds_logged',     $count( 'pcp_user_meds' ) ],
            [ 'experience_reports',     $count( 'pcp_experience_reports' ) ],
            [ 'approved_xr',            $count( 'pcp_experience_reports', [ 'xr_status' => 1 ] ) ],
            [ 'pending_xr',             $count( 'pcp_experience_reports', [ 'xr_status' => 0 ] ) ],
            [ 'effect_reports',         $count( 'pcp_effect_reports' ) ],
            [ 'likert_reports',         $count( 'pcp_likert_reports' ) ],
            [ 'votes',                  $count( 'pcp_votes' ) ],
            [ 'comments',               $count( 'pcp_comments' ) ],
            [ 'literature_entries',     $count( 'pcp_literature' ) ],
        ];
    }

    private function renderOverview( $out, $dbr ) {
        $out->addHTML( '<h2>Overview' . $this->csvLink( 'overview' ) . '</h2>' );
        $out->addHTML( '<table class="pcp-pa-table"><tbody>' );
        foreach ( $this->dataOverview( $dbr ) as [ $k, $v ] ) {
            $out->addHTML( '<tr><th>' . htmlspecialchars( $k ) . '</th><td>' . (int)$v . '</td></tr>' );
        }
        $out->addHTML( '</tbody></table>' );
    }

    // --- data + render: Demographics breakdown ---

    private function dataDemographicsBreakdown( $dbr ): array {
        $out = [];
        $res = $dbr->select( 'pcp_profile_fields',
            [ 'pf_namespace','pf_key','pf_value_text','pf_value_num','n' => 'COUNT(*)' ],
            [ 'pf_namespace' => 'demographics' ],
            __METHOD__,
            [ 'GROUP BY' => 'pf_key, pf_value_text', 'ORDER BY' => 'pf_key, pf_value_text' ]
        );
        // First pass: collect non-birthday rows; aggregate birthday into exact-age counts
        $ageCounts = [];
        foreach ( $res as $r ) {
            $key = (string)$r->pf_key;
            $val = $r->pf_value_text !== null && $r->pf_value_text !== '' ? $r->pf_value_text : ( $r->pf_value_num !== null ? $r->pf_value_num : '' );
            if ( $key === 'birthday' ) {
                $age = SpecialMyProfile::computeAge( (string)$val );
                if ( $age === null ) continue;
                $ageCounts[ $age ] = ( $ageCounts[ $age ] ?? 0 ) + (int)$r->n;
                continue;
            }
            $out[] = [ $key, (string)$val, (int)$r->n ];
        }
        // Emit exact ages ascending
        ksort( $ageCounts );
        foreach ( $ageCounts as $age => $n ) {
            $out[] = [ 'age', (string)$age, (int)$n ];
        }
        usort( $out, function ( $a, $b ) {
            if ( $a[0] === $b[0] ) {
                // numeric-aware when key is 'age'
                if ( $a[0] === 'age' ) return ( (int)$a[1] ) <=> ( (int)$b[1] );
                return strcmp( $a[1], $b[1] );
            }
            return strcmp( $a[0], $b[0] );
        } );
        return $out;
    }

    private function renderDemographics( $out, $dbr ) {
        $rows = $this->dataDemographicsBreakdown( $dbr );
        $out->addHTML( '<h2>Demographics breakdown' . $this->csvLink( 'demographics' ) . '</h2>' );
        if ( !$rows ) { $out->addHTML( '<p><em>No demographic data yet.</em></p>' ); return; }
        // Group by field for display
        $byKey = [];
        foreach ( $rows as [ $k, $v, $n ] ) {
            $byKey[ $k ][] = [ $v, $n ];
        }
        foreach ( $byKey as $k => $vals ) {
            $out->addHTML( '<h3>' . htmlspecialchars( $k ) . '</h3>' );
            $out->addHTML( '<table class="pcp-pa-table"><tbody>' );
            foreach ( $vals as [ $v, $n ] ) {
                $out->addHTML( '<tr><th>' . htmlspecialchars( (string)$v ) . '</th><td>' . (int)$n . '</td></tr>' );
            }
            $out->addHTML( '</tbody></table>' );
        }
    }

    // --- data + render: OCEAN stats ---

    private function dataOceanStats( $dbr ): array {
        $traits = [ 'O','C','E','A','N' ];
        $out = [];
        foreach ( $traits as $t ) {
            $row = $dbr->selectRow( 'pcp_profile_fields',
                [
                    'n'    => 'COUNT(*)',
                    'avg'  => 'AVG(pf_value_num)',
                    'min'  => 'MIN(pf_value_num)',
                    'max'  => 'MAX(pf_value_num)',
                ],
                [ 'pf_namespace' => 'ocean', 'pf_key' => $t, 'pf_value_num IS NOT NULL' ],
                __METHOD__
            );
            $n = (int)( $row->n ?? 0 );
            $out[] = [ $t, $n, $n > 0 ? round( (float)$row->avg, 1 ) : null,
                       $n > 0 ? (int)$row->min : null, $n > 0 ? (int)$row->max : null ];
        }
        return $out;
    }

    private function renderOcean( $out, $dbr ) {
        $rows = $this->dataOceanStats( $dbr );
        $out->addHTML( '<h2>OCEAN distribution' . $this->csvLink( 'ocean' ) . '</h2>' );
        $out->addHTML( '<table class="pcp-pa-table"><thead><tr><th>trait</th><th>n</th><th>mean</th><th>min</th><th>max</th></tr></thead><tbody>' );
        foreach ( $rows as [ $t, $n, $avg, $min, $max ] ) {
            $out->addHTML( '<tr><th>' . $t . '</th><td>' . $n . '</td><td>' .
                ( $avg !== null ? $avg : '—' ) . '</td><td>' .
                ( $min !== null ? $min : '—' ) . '</td><td>' .
                ( $max !== null ? $max : '—' ) . '</td></tr>' );
        }
        $out->addHTML( '</tbody></table>' );
    }

    // --- data + render: Diagnosis frequency ---

    private function dataDiagnosesFrequency( $dbr ): array {
        $res = $dbr->select( 'pcp_profile_diagnoses',
            [ 'pd_system','pd_description','pd_code','n' => 'COUNT(DISTINCT pd_profile_id)' ],
            [],
            __METHOD__,
            [ 'GROUP BY' => 'pd_system, pd_description, pd_code', 'ORDER BY' => 'n DESC, pd_description' ]
        );
        $out = [];
        foreach ( $res as $r ) {
            $out[] = [ (string)$r->pd_system, (string)$r->pd_description, (string)( $r->pd_code ?? '' ), (int)$r->n ];
        }
        return $out;
    }

    private function renderDiagnoses( $out, $dbr ) {
        $rows = $this->dataDiagnosesFrequency( $dbr );
        $out->addHTML( '<h2>Diagnosis frequency' . $this->csvLink( 'diagnoses' ) . '</h2>' );
        if ( !$rows ) { $out->addHTML( '<p><em>No diagnoses logged yet.</em></p>' ); return; }
        $out->addHTML( '<table class="pcp-pa-table"><thead><tr><th>system</th><th>description</th><th>code</th><th>n (distinct users)</th></tr></thead><tbody>' );
        foreach ( $rows as [ $sys, $desc, $code, $n ] ) {
            $out->addHTML( '<tr><th>' . htmlspecialchars( $sys ) . '</th><td>' . htmlspecialchars( $desc ) . '</td><td><code>' . htmlspecialchars( $code ) . '</code></td><td>' . $n . '</td></tr>' );
        }
        $out->addHTML( '</tbody></table>' );
    }

    // --- data + render: Medicine usage ---

    private function dataMedUsage( $dbr ): array {
        // Combine experience_reports (page-linked) + user_meds (page-linked or free-text)
        $out = [];
        // XR side
        $xr = $dbr->newSelectQueryBuilder()
            ->select( [
                'name' => 'p.page_title',
                'n'    => 'COUNT(*)',
                'eff_n'  => 'SUM(CASE WHEN xr.xr_efficacy IS NOT NULL THEN 1 ELSE 0 END)',
                'eff_sum'=> 'SUM(xr.xr_efficacy)',
                'bur_n'  => 'SUM(CASE WHEN xr.xr_burden IS NOT NULL THEN 1 ELSE 0 END)',
                'bur_sum'=> 'SUM(xr.xr_burden)',
            ] )
            ->from( 'pcp_experience_reports', 'xr' )
            ->join( 'page', 'p', 'p.page_id = xr.xr_page_id' )
            ->where( [ 'xr.xr_status' => 1 ] )
            ->groupBy( 'p.page_title' )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        foreach ( $xr as $r ) {
            $name = str_replace( '_', ' ', (string)$r->name );
            $effMean = (int)$r->eff_n > 0 ? round( (float)$r->eff_sum / (int)$r->eff_n, 2 ) : null;
            $burMean = (int)$r->bur_n > 0 ? round( (float)$r->bur_sum / (int)$r->bur_n, 2 ) : null;
            $out[] = [ $name, (int)$r->n, $effMean === null ? '' : $effMean, $burMean === null ? '' : $burMean ];
        }
        // Sort by n desc
        usort( $out, function ( $a, $b ) { return $b[1] <=> $a[1]; } );
        return $out;
    }

    private function renderMedUsage( $out, $dbr ) {
        $rows = $this->dataMedUsage( $dbr );
        $out->addHTML( '<h2>Medicine usage (approved experience reports)' . $this->csvLink( 'medusage' ) . '</h2>' );
        if ( !$rows ) { $out->addHTML( '<p><em>No approved experience reports yet.</em></p>' ); return; }
        $out->addHTML( '<table class="pcp-pa-table"><thead><tr><th>medicine</th><th>n reports</th><th>mean efficacy</th><th>mean burden</th></tr></thead><tbody>' );
        foreach ( $rows as [ $name, $n, $eff, $bur ] ) {
            $out->addHTML( '<tr><th>' . htmlspecialchars( $name ) . '</th><td>' . $n . '</td><td>' . htmlspecialchars( (string)$eff ) . '</td><td>' . htmlspecialchars( (string)$bur ) . '</td></tr>' );
        }
        $out->addHTML( '</tbody></table>' );
    }

    // --- data + render: All profiles (sysop-identifiable view) ---

    private function dataAllProfiles( $dbr ): array {
        $userFactory = MediaWikiServices::getInstance()->getUserFactory();
        $res = $dbr->newSelectQueryBuilder()
            ->select( [
                'p.prof_id', 'p.prof_user_id', 'p.prof_voter_hash', 'p.prof_public_alias',
                'p.prof_show_default', 'p.prof_created',
                'n_fields'    => '(SELECT COUNT(*) FROM ' . $dbr->tableName( 'pcp_profile_fields' )    . ' WHERE pf_profile_id = p.prof_id)',
                'n_diagnoses' => '(SELECT COUNT(*) FROM ' . $dbr->tableName( 'pcp_profile_diagnoses' ) . ' WHERE pd_profile_id = p.prof_id)',
                'n_meds'      => '(SELECT COUNT(*) FROM ' . $dbr->tableName( 'pcp_user_meds' )         . ' WHERE um_profile_id = p.prof_id)',
                'n_xr'        => '(SELECT COUNT(*) FROM ' . $dbr->tableName( 'pcp_experience_reports' ) . ' WHERE xr_voter_hash = p.prof_voter_hash)',
            ] )
            ->from( 'pcp_user_profiles', 'p' )
            ->orderBy( 'p.prof_created DESC' )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $res as $r ) {
            $user = $userFactory->newFromId( (int)$r->prof_user_id );
            $username = $user ? $user->getName() : '?';
            $showLabels = [ 0=>'anonymous',1=>'alias',2=>'username',3=>'always_anon' ];
            $out[] = [
                (int)$r->prof_user_id,
                $username,
                (string)( $r->prof_public_alias ?? '' ),
                $showLabels[ (int)$r->prof_show_default ] ?? '?',
                (int)$r->n_fields,
                (int)$r->n_diagnoses,
                (int)$r->n_meds,
                (int)$r->n_xr,
                (string)$r->prof_created,
            ];
        }
        return $out;
    }

    private function renderAllProfiles( $out, $dbr ) {
        $rows = $this->dataAllProfiles( $dbr );
        $out->addHTML( '<h2>All profiles' . $this->csvLink( 'profiles' ) . '</h2>' );
        if ( !$rows ) { $out->addHTML( '<p><em>No profiles yet.</em></p>' ); return; }
        $out->addHTML( '<table class="pcp-pa-table"><thead><tr>'
            . '<th>uid</th><th>username</th><th>alias</th><th>show</th>'
            . '<th>fields</th><th>dx</th><th>meds</th><th>xr</th><th>created</th>'
            . '</tr></thead><tbody>' );
        foreach ( $rows as [ $uid, $name, $alias, $show, $f, $d, $m, $x, $ts ] ) {
            $url = htmlspecialchars( SpecialPage::getTitleFor( 'UserProfile', $name )->getLocalURL() );
            $out->addHTML(
                '<tr><td>' . $uid . '</td><td><a href="' . $url . '">' . htmlspecialchars( $name ) . '</a></td>' .
                '<td>' . htmlspecialchars( $alias ) . '</td>' .
                '<td>' . htmlspecialchars( $show ) . '</td>' .
                '<td>' . $f . '</td><td>' . $d . '</td><td>' . $m . '</td><td>' . $x . '</td>' .
                '<td>' . htmlspecialchars( $ts ) . '</td></tr>'
            );
        }
        $out->addHTML( '</tbody></table>' );
    }

    public function doesWrites() { return false; }
    protected function getGroupName() { return 'pharmacopedia'; }

    // --- data + render: Assessment stats (PID-5-BF / RAADS-R / CAT-Q) ---

    private function dataAssessmentStats( $dbr, string $cls ): array {
        $out = [];
        $metrics = [];
        foreach ( $cls::SUBSCALES as $k => $def ) {
            $metrics[] = [ 'subscale_' . $k, $def['label'] ];
        }
        $metrics[] = [ 'total', 'Total' ];
        foreach ( $metrics as [ $key, $label ] ) {
            $row = $dbr->selectRow( 'pcp_profile_fields',
                [
                    'n'   => 'COUNT(*)',
                    'avg' => 'AVG(pf_value_num)',
                    'min' => 'MIN(pf_value_num)',
                    'max' => 'MAX(pf_value_num)',
                ],
                [
                    'pf_namespace' => $cls::KEY,
                    'pf_key'       => $key,
                    'pf_value_num IS NOT NULL',
                ],
                __METHOD__
            );
            $n = (int)( $row->n ?? 0 );
            $out[] = [
                $label,
                $n,
                $n > 0 ? round( (float)$row->avg, 2 ) : null,
                $n > 0 ? round( (float)$row->min, 2 ) : null,
                $n > 0 ? round( (float)$row->max, 2 ) : null,
            ];
        }
        return $out;
    }

    private function renderAssessmentStats( $out, $dbr, string $cls ) {
        $rows = $this->dataAssessmentStats( $dbr, $cls );
        $out->addHTML( '<h2>' . htmlspecialchars( $cls::FULL_NAME )
            . ' <small>(' . htmlspecialchars( $cls::NAME ) . ')</small>'
            . $this->csvLink( $cls::KEY ) . '</h2>' );
        $hasAny = false;
        foreach ( $rows as $r ) { if ( (int)$r[1] > 0 ) { $hasAny = true; break; } }
        if ( !$hasAny ) {
            $out->addHTML( '<p><em>No completions yet.</em></p>' );
            return;
        }
        $out->addHTML( '<table class="pcp-pa-table"><thead><tr><th>metric</th><th>n</th><th>mean</th><th>min</th><th>max</th></tr></thead><tbody>' );
        foreach ( $rows as [ $label, $n, $avg, $min, $max ] ) {
            $out->addHTML( '<tr><th>' . htmlspecialchars( $label ) . '</th>'
                . '<td>' . (int)$n . '</td>'
                . '<td>' . ( $avg === null ? '—' : htmlspecialchars( (string)$avg ) ) . '</td>'
                . '<td>' . ( $min === null ? '—' : htmlspecialchars( (string)$min ) ) . '</td>'
                . '<td>' . ( $max === null ? '—' : htmlspecialchars( (string)$max ) ) . '</td>'
                . '</tr>' );
        }
        $out->addHTML( '</tbody></table>' );
    }

}
