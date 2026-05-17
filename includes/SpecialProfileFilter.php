<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Sysop-only cross-filter UI over user profiles.
 *
 * Filter inputs (any subset; AND-combined):
 *   - age_min / age_max          (computed from demographics.birthday)
 *   - sex_at_birth               (exact)
 *   - gender_identity            (LIKE)
 *   - ethnicity                  (LIKE)
 *   - country                    (LIKE)
 *   - handedness                 (exact)
 *   - smoking_status             (exact)
 *   - O/C/E/A/N _min / _max      (numeric, 0-100, resolution 1)
 *   - dx                         (LIKE on pd_description or pd_code)
 *   - med                        (LIKE on page_title or um_med_name)
 *
 * Results: profile list, with optional CSV export and optional
 * group-by-medicine-efficacy aggregation across matched profiles' XRs.
 */
class SpecialProfileFilter extends SpecialPage {

    public function __construct() {
        parent::__construct( 'ProfileFilter', 'pharmacopedia-profile-view-others-full' );
    }

    public function execute( $par ) {
        $this->checkPermissions();
        $request = $this->getRequest();
        $format  = $request->getVal( 'format', '' );

        $dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        $filters = $this->parseFilters( $request );
        $profileIds = $this->applyFilters( $dbr, $filters );

        if ( $format === 'csv' ) {
            $this->emitCsv( $dbr, $profileIds );
            return;
        }

        $this->setHeaders();
        $out = $this->getOutput();
        $out->setPageTitle( 'Profile filter' );
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        $out->addHTML(
            '<div class="pcp-banner"><span class="pcp-banner__title">Sysop cross-filter</span>'
            . '<span class="pcp-banner__body">Filter profiles by demographics, OCEAN, diagnoses, and meds. '
            . 'All filters are AND-combined. Leave a field blank to ignore it. '
            . 'Age is computed from birthday.</span></div>'
        );

        $this->renderForm( $out, $filters );
        $this->renderResults( $out, $dbr, $profileIds, $filters );
    }

    // ----- Filter parsing -----

    private function parseFilters( $request ): array {
        $get = function ( $k ) use ( $request ) {
            $v = trim( (string)$request->getVal( $k, '' ) );
            return $v === '' ? null : $v;
        };
        $getInt = function ( $k ) use ( $request ) {
            $v = $request->getVal( $k, '' );
            if ( $v === '' || $v === null ) return null;
            return (int)$v;
        };
        $out = [
            'age_min'         => $getInt( 'age_min' ),
            'age_max'         => $getInt( 'age_max' ),
            'sex_at_birth'    => $get( 'sex_at_birth' ),
            'gender_identity' => $get( 'gender_identity' ),
            'ethnicity'       => $get( 'ethnicity' ),
            'country'         => $get( 'country' ),
            'handedness'      => $get( 'handedness' ),
            'smoking_status'  => $get( 'smoking_status' ),
            'O_min' => $getInt( 'O_min' ), 'O_max' => $getInt( 'O_max' ),
            'C_min' => $getInt( 'C_min' ), 'C_max' => $getInt( 'C_max' ),
            'E_min' => $getInt( 'E_min' ), 'E_max' => $getInt( 'E_max' ),
            'A_min' => $getInt( 'A_min' ), 'A_max' => $getInt( 'A_max' ),
            'N_min' => $getInt( 'N_min' ), 'N_max' => $getInt( 'N_max' ),
            'dx'   => $get( 'dx' ),
            'med'  => $get( 'med' ),
        ];
        // Assessment subscale + total min/max per test
        $assessClasses = [
            \MediaWiki\Extension\Pharmacopedia\Assessments\Pid5bf::class,
            \MediaWiki\Extension\Pharmacopedia\Assessments\Raadsr::class,
            \MediaWiki\Extension\Pharmacopedia\Assessments\Catq::class,
        ];
        foreach ( $assessClasses as $cls ) {
            $k = $cls::KEY;
            foreach ( $cls::SUBSCALES as $sk => $_def ) {
                $base = $k . '_' . $sk;
                $vmin = $request->getVal( $base . '_min', '' );
                $vmax = $request->getVal( $base . '_max', '' );
                $out[ $base . '_min' ] = $vmin === '' ? null : (float)$vmin;
                $out[ $base . '_max' ] = $vmax === '' ? null : (float)$vmax;
            }
            $vminT = $request->getVal( $k . '_total_min', '' );
            $vmaxT = $request->getVal( $k . '_total_max', '' );
            $out[ $k . '_total_min' ] = $vminT === '' ? null : (float)$vminT;
            $out[ $k . '_total_max' ] = $vmaxT === '' ? null : (float)$vmaxT;
        }
        return $out;
    }

    private function hasAnyFilter( array $f ): bool {
        foreach ( $f as $v ) {
            if ( $v !== null && $v !== '' ) return true;
        }
        return false;
    }

    // ----- Filter application -----

    /** Returns an array of prof_id matching all filters, or null = unfiltered (no filter applied). */
    private function applyFilters( $dbr, array $f ): ?array {
        if ( !$this->hasAnyFilter( $f ) ) return null;

        // Start with all profile ids
        $allIds = [];
        $res = $dbr->select( 'pcp_user_profiles', [ 'prof_id', 'prof_voter_hash' ], [], __METHOD__ );
        $hashByPid = [];
        foreach ( $res as $r ) {
            $allIds[] = (int)$r->prof_id;
            $hashByPid[ (int)$r->prof_id ] = (string)$r->prof_voter_hash;
        }
        $matched = array_fill_keys( $allIds, true );

        // ----- Age range (birthday) -----
        if ( $f['age_min'] !== null || $f['age_max'] !== null ) {
            $today = new \DateTimeImmutable( 'today' );
            $res = $dbr->select( 'pcp_profile_fields',
                [ 'pf_profile_id', 'pf_value_text' ],
                [ 'pf_namespace' => 'demographics', 'pf_key' => 'birthday' ],
                __METHOD__ );
            $birthdayByPid = [];
            foreach ( $res as $r ) {
                $birthdayByPid[ (int)$r->pf_profile_id ] = (string)$r->pf_value_text;
            }
            foreach ( $matched as $pid => $_ ) {
                $bd = $birthdayByPid[ $pid ] ?? null;
                $age = $bd ? SpecialMyProfile::computeAge( $bd ) : null;
                if ( $age === null ) { unset( $matched[ $pid ] ); continue; }
                if ( $f['age_min'] !== null && $age < $f['age_min'] ) unset( $matched[ $pid ] );
                if ( isset( $matched[ $pid ] ) && $f['age_max'] !== null && $age > $f['age_max'] ) {
                    unset( $matched[ $pid ] );
                }
            }
        }

        // ----- Exact demographic equality -----
        foreach ( [ 'sex_at_birth', 'handedness', 'smoking_status' ] as $key ) {
            if ( $f[ $key ] === null ) continue;
            $ids = $this->profilesWithFieldEquals( $dbr, 'demographics', $key, $f[ $key ] );
            $matched = array_intersect_key( $matched, array_fill_keys( $ids, true ) );
        }
        // ----- LIKE demographic -----
        foreach ( [ 'gender_identity', 'ethnicity', 'country' ] as $key ) {
            if ( $f[ $key ] === null ) continue;
            $ids = $this->profilesWithFieldLike( $dbr, 'demographics', $key, $f[ $key ] );
            $matched = array_intersect_key( $matched, array_fill_keys( $ids, true ) );
        }

        // ----- OCEAN ranges -----
        foreach ( [ 'O', 'C', 'E', 'A', 'N' ] as $t ) {
            if ( $f[ "{$t}_min" ] === null && $f[ "{$t}_max" ] === null ) continue;
            $ids = $this->profilesWithNumRange( $dbr, 'ocean', $t,
                $f[ "{$t}_min" ], $f[ "{$t}_max" ] );
            $matched = array_intersect_key( $matched, array_fill_keys( $ids, true ) );
        }

        // ----- Diagnosis LIKE (pd_description OR pd_code) -----
        if ( $f['dx'] !== null ) {
            $needle = mb_strtolower( $f['dx'] );
            $like = $dbr->buildLike( $dbr->anyString(), $needle, $dbr->anyString() );
            $res = $dbr->newSelectQueryBuilder()
                ->select( 'pd_profile_id' )
                ->distinct()
                ->from( 'pcp_profile_diagnoses' )
                ->where( $dbr->makeList( [
                    "LOWER(pd_description) $like",
                    "LOWER(pd_code) $like",
                ], $dbr::LIST_OR ) )
                ->caller( __METHOD__ )
                ->fetchResultSet();
            $ids = [];
            foreach ( $res as $r ) $ids[] = (int)$r->pd_profile_id;
            $matched = array_intersect_key( $matched, array_fill_keys( $ids, true ) );
        }

        // ----- Med LIKE (um_med_name, or pcp_user_meds → page.page_title, or experience_reports → page.page_title) -----
        if ( $f['med'] !== null ) {
            $needle = mb_strtolower( $f['med'] );
            $like = $dbr->buildLike( $dbr->anyString(), $needle, $dbr->anyString() );

            // Manually-added meds: free-text um_med_name
            $ids = [];
            $res = $dbr->select( 'pcp_user_meds', 'um_profile_id',
                [ "LOWER(um_med_name) $like" ], __METHOD__, [ 'DISTINCT' ] );
            foreach ( $res as $r ) $ids[ (int)$r->um_profile_id ] = true;

            // Manually-added meds: linked page
            $res = $dbr->newSelectQueryBuilder()
                ->select( 'um_profile_id' )
                ->distinct()
                ->from( 'pcp_user_meds', 'u' )
                ->join( 'page', 'p', 'p.page_id = u.um_page_id' )
                ->where( "REPLACE(LOWER(CONVERT(p.page_title USING utf8mb4)), '_', ' ') $like" )
                ->caller( __METHOD__ )
                ->fetchResultSet();
            foreach ( $res as $r ) $ids[ (int)$r->um_profile_id ] = true;

            // Experience-reports: linked page (resolve voter_hash → prof_id)
            $res = $dbr->newSelectQueryBuilder()
                ->select( [ 'prof_id' => 'up.prof_id' ] )
                ->distinct()
                ->from( 'pcp_experience_reports', 'xr' )
                ->join( 'page', 'p',   'p.page_id = xr.xr_page_id' )
                ->join( 'pcp_user_profiles', 'up', 'up.prof_voter_hash = xr.xr_voter_hash' )
                ->where( [
                    "REPLACE(LOWER(CONVERT(p.page_title USING utf8mb4)), '_', ' ') $like",
                ] )
                ->caller( __METHOD__ )
                ->fetchResultSet();
            foreach ( $res as $r ) $ids[ (int)$r->prof_id ] = true;

            $matched = array_intersect_key( $matched, $ids );
        }

        // ----- Assessment subscales + totals -----
        $assessClasses = [
            \MediaWiki\Extension\Pharmacopedia\Assessments\Pid5bf::class,
            \MediaWiki\Extension\Pharmacopedia\Assessments\Raadsr::class,
            \MediaWiki\Extension\Pharmacopedia\Assessments\Catq::class,
        ];
        foreach ( $assessClasses as $cls ) {
            $k = $cls::KEY;
            foreach ( $cls::SUBSCALES as $sk => $_def ) {
                $base = $k . '_' . $sk;
                $vmin = $f[ $base . '_min' ] ?? null;
                $vmax = $f[ $base . '_max' ] ?? null;
                if ( $vmin === null && $vmax === null ) continue;
                $ids = $this->profilesWithNumRange( $dbr, $k, 'subscale_' . $sk,
                    $vmin === null ? null : (int)$vmin,
                    $vmax === null ? null : (int)$vmax );
                $matched = array_intersect_key( $matched, array_fill_keys( $ids, true ) );
            }
            $vmin = $f[ $k . '_total_min' ] ?? null;
            $vmax = $f[ $k . '_total_max' ] ?? null;
            if ( $vmin !== null || $vmax !== null ) {
                $ids = $this->profilesWithNumRange( $dbr, $k, 'total',
                    $vmin === null ? null : (int)$vmin,
                    $vmax === null ? null : (int)$vmax );
                $matched = array_intersect_key( $matched, array_fill_keys( $ids, true ) );
            }
        }

        return array_keys( $matched );
    }

    private function profilesWithFieldEquals( $dbr, string $ns, string $key, string $val ): array {
        $res = $dbr->select( 'pcp_profile_fields', 'pf_profile_id',
            [ 'pf_namespace' => $ns, 'pf_key' => $key, 'pf_value_text' => $val ],
            __METHOD__, [ 'DISTINCT' ] );
        $ids = [];
        foreach ( $res as $r ) $ids[] = (int)$r->pf_profile_id;
        return $ids;
    }

    private function profilesWithFieldLike( $dbr, string $ns, string $key, string $val ): array {
        $needle = mb_strtolower( $val );
        $like = $dbr->buildLike( $dbr->anyString(), $needle, $dbr->anyString() );
        $res = $dbr->select( 'pcp_profile_fields', 'pf_profile_id',
            [ 'pf_namespace' => $ns, 'pf_key' => $key, "LOWER(pf_value_text) $like" ],
            __METHOD__, [ 'DISTINCT' ] );
        $ids = [];
        foreach ( $res as $r ) $ids[] = (int)$r->pf_profile_id;
        return $ids;
    }

    private function profilesWithNumRange( $dbr, string $ns, string $key, ?int $min, ?int $max ): array {
        $where = [ 'pf_namespace' => $ns, 'pf_key' => $key, 'pf_value_num IS NOT NULL' ];
        if ( $min !== null ) $where[] = 'pf_value_num >= ' . (int)$min;
        if ( $max !== null ) $where[] = 'pf_value_num <= ' . (int)$max;
        $res = $dbr->select( 'pcp_profile_fields', 'pf_profile_id', $where, __METHOD__, [ 'DISTINCT' ] );
        $ids = [];
        foreach ( $res as $r ) $ids[] = (int)$r->pf_profile_id;
        return $ids;
    }

    // ----- Form -----

    private function renderForm( $out, array $f ) {
        $h = function ( $s ) { return htmlspecialchars( (string)$s ); };
        $val = function ( $k ) use ( $f, $h ) {
            return $f[ $k ] === null ? '' : $h( $f[ $k ] );
        };
        $action = $this->getPageTitle()->getLocalURL();

        $html = '<form method="get" action="' . $action . '" class="pcp-pf-form">';
        // GET forms lose existing query params (incl. title=) — re-inject as hidden input
        $html .= '<input type="hidden" name="title" value="' . htmlspecialchars( $this->getPageTitle()->getPrefixedDBkey() ) . '">';

        // Demographics row
        $html .= '<fieldset class="pcp-prof-section"><legend>Demographics</legend>';
        $html .= '<div class="pcp-pf-grid">';
        $html .= '<label>Age min<input type="number" name="age_min" min="0" max="120" step="1" value="' . $val('age_min') . '"></label>';
        $html .= '<label>Age max<input type="number" name="age_max" min="0" max="120" step="1" value="' . $val('age_max') . '"></label>';
        $html .= '<label>Sex at birth<select name="sex_at_birth">';
        foreach ( [ '' => '—', 'female' => 'Female', 'male' => 'Male', 'intersex' => 'Intersex', 'prefer_not_say' => 'Prefer not to say' ] as $k => $lab ) {
            $sel = ( (string)( $f['sex_at_birth'] ?? '' ) === $k ) ? ' selected' : '';
            $html .= '<option value="' . $h( $k ) . '"' . $sel . '>' . $h( $lab ) . '</option>';
        }
        $html .= '</select></label>';
        $html .= '<label>Gender identity (LIKE)<input type="text" name="gender_identity" value="' . $val('gender_identity') . '"></label>';
        $html .= '<label>Ethnicity (LIKE)<input type="text" name="ethnicity" value="' . $val('ethnicity') . '"></label>';
        $html .= '<label>Country (LIKE)<input type="text" name="country" value="' . $val('country') . '"></label>';
        $html .= '<label>Handedness<select name="handedness">';
        foreach ( [ '' => '—', 'right' => 'Right', 'left' => 'Left', 'mixed' => 'Mixed' ] as $k => $lab ) {
            $sel = ( (string)( $f['handedness'] ?? '' ) === $k ) ? ' selected' : '';
            $html .= '<option value="' . $h( $k ) . '"' . $sel . '>' . $h( $lab ) . '</option>';
        }
        $html .= '</select></label>';
        $html .= '<label>Smoking<select name="smoking_status">';
        foreach ( [ '' => '—', 'never' => 'Never', 'former' => 'Former', 'current_light' => 'Light', 'current_heavy' => 'Heavy' ] as $k => $lab ) {
            $sel = ( (string)( $f['smoking_status'] ?? '' ) === $k ) ? ' selected' : '';
            $html .= '<option value="' . $h( $k ) . '"' . $sel . '>' . $h( $lab ) . '</option>';
        }
        $html .= '</select></label>';
        $html .= '</div></fieldset>';

        // OCEAN
        $html .= '<fieldset class="pcp-prof-section"><legend>OCEAN (0–100)</legend>';
        $html .= '<div class="pcp-pf-grid">';
        $labels = [ 'O' => 'Openness', 'C' => 'Conscientiousness', 'E' => 'Extraversion', 'A' => 'Agreeableness', 'N' => 'Neuroticism' ];
        foreach ( $labels as $t => $lab ) {
            $html .= '<label>' . $h( $lab ) . ' min<input type="number" name="' . $t . '_min" min="0" max="100" step="1" value="' . $val( $t . '_min' ) . '"></label>';
            $html .= '<label>' . $h( $lab ) . ' max<input type="number" name="' . $t . '_max" min="0" max="100" step="1" value="' . $val( $t . '_max' ) . '"></label>';
        }
        $html .= '</div></fieldset>';

        // Dx + Med
        $html .= '<fieldset class="pcp-prof-section"><legend>Diagnosis / Medicine</legend>';
        $html .= '<div class="pcp-pf-grid">';
        $html .= '<label>Diagnosis (LIKE description or code)<input type="text" name="dx" value="' . $val('dx') . '"></label>';
        $html .= '<label>Medicine (LIKE page title or free-text)<input type="text" name="med" value="' . $val('med') . '"></label>';
        $html .= '</div></fieldset>';

        // Assessments fieldset
        $assessClasses = [
            \MediaWiki\Extension\Pharmacopedia\Assessments\Pid5bf::class,
            \MediaWiki\Extension\Pharmacopedia\Assessments\Raadsr::class,
            \MediaWiki\Extension\Pharmacopedia\Assessments\Catq::class,
        ];
        foreach ( $assessClasses as $cls ) {
            $html .= '<fieldset class="pcp-prof-section"><legend>' . $h( $cls::NAME ) . ' filters</legend>';
            $html .= '<div class="pcp-pf-grid">';
            foreach ( $cls::SUBSCALES as $sk => $def ) {
                $base = $cls::KEY . '_' . $sk;
                $cur_min = $f[ $base . '_min' ] ?? null;
                $cur_max = $f[ $base . '_max' ] ?? null;
                $html .= '<label>' . $h( $def["label"] ) . ' min<input type="number" step="any" name="' . $base . '_min" value="' . ( $cur_min === null ? '' : $h( $cur_min ) ) . '"></label>';
                $html .= '<label>' . $h( $def["label"] ) . ' max<input type="number" step="any" name="' . $base . '_max" value="' . ( $cur_max === null ? '' : $h( $cur_max ) ) . '"></label>';
            }
            $cur_min = $f[ $cls::KEY . '_total_min' ] ?? null;
            $cur_max = $f[ $cls::KEY . '_total_max' ] ?? null;
            $html .= '<label>Total min<input type="number" step="any" name="' . $cls::KEY . '_total_min" value="' . ( $cur_min === null ? '' : $h( $cur_min ) ) . '"></label>';
            $html .= '<label>Total max<input type="number" step="any" name="' . $cls::KEY . '_total_max" value="' . ( $cur_max === null ? '' : $h( $cur_max ) ) . '"></label>';
            $html .= '</div></fieldset>';
        }

        $html .= '<div class="pcp-pf-actions">';
        $html .= '<button type="submit" class="pcp-btn pcp-btn-primary">Apply filters</button>';
        $html .= ' <a href="' . $action . '" class="pcp-btn">Reset</a>';
        $html .= '</div>';
        $html .= '</form>';

        $out->addHTML( $html );
    }

    // ----- Results -----

    private function renderResults( $out, $dbr, ?array $profileIds, array $f ) {
        $hasFilter = $this->hasAnyFilter( $f );
        if ( !$hasFilter ) {
            $out->addHTML( '<p><em>No filters applied. Enter one or more filters above to begin.</em></p>' );
            return;
        }
        $count = count( $profileIds ?? [] );
        $csvUrl = $this->getPageTitle()->getLocalURL( array_merge(
            array_filter( $f, function ( $v ) { return $v !== null && $v !== ''; } ),
            [ 'format' => 'csv' ]
        ) );
        $out->addHTML( '<h2>Matched profiles: ' . $count
            . ' <a class="pcp-pa-csv" href="' . htmlspecialchars( $csvUrl ) . '">[CSV]</a></h2>' );
        if ( $count === 0 ) {
            $out->addHTML( '<p><em>No profiles match these filters.</em></p>' );
            return;
        }
        $rows = $this->loadProfileRows( $dbr, $profileIds );
        $out->addHTML( '<table class="pcp-pa-table"><thead><tr>'
            . '<th>uid</th><th>username</th><th>age</th><th>sex</th><th>country</th>'
            . '<th>fields</th><th>dx</th><th>meds</th><th>xr</th>'
            . '</tr></thead><tbody>' );
        foreach ( $rows as $r ) {
            $url = htmlspecialchars( SpecialPage::getTitleFor( 'UserProfile', $r['username'] )->getLocalURL() );
            $out->addHTML(
                '<tr><td>' . (int)$r['uid'] . '</td>'
                . '<td><a href="' . $url . '">' . htmlspecialchars( $r['username'] ) . '</a></td>'
                . '<td>' . htmlspecialchars( (string)( $r['age'] ?? '—' ) ) . '</td>'
                . '<td>' . htmlspecialchars( (string)( $r['sex'] ?? '' ) ) . '</td>'
                . '<td>' . htmlspecialchars( (string)( $r['country'] ?? '' ) ) . '</td>'
                . '<td>' . (int)$r['n_fields'] . '</td>'
                . '<td>' . (int)$r['n_dx'] . '</td>'
                . '<td>' . (int)$r['n_meds'] . '</td>'
                . '<td>' . (int)$r['n_xr'] . '</td>'
                . '</tr>'
            );
        }
        $out->addHTML( '</tbody></table>' );

        // ----- Group-by: medicine efficacy across matched profiles -----
        $out->addHTML( '<h2>Medicine efficacy across matched profiles</h2>' );
        $hashes = array_column( $rows, 'voter_hash' );
        if ( !$hashes ) {
            $out->addHTML( '<p><em>No experience reports.</em></p>' );
            return;
        }
        $agg = $dbr->newSelectQueryBuilder()
            ->select( [
                'name'    => 'p.page_title',
                'n'       => 'COUNT(*)',
                'eff_n'   => 'SUM(CASE WHEN xr.xr_efficacy IS NOT NULL THEN 1 ELSE 0 END)',
                'eff_sum' => 'SUM(xr.xr_efficacy)',
                'bur_n'   => 'SUM(CASE WHEN xr.xr_burden IS NOT NULL THEN 1 ELSE 0 END)',
                'bur_sum' => 'SUM(xr.xr_burden)',
            ] )
            ->from( 'pcp_experience_reports', 'xr' )
            ->join( 'page', 'p', 'p.page_id = xr.xr_page_id' )
            ->where( [ 'xr.xr_status' => 1, 'xr.xr_voter_hash' => $hashes ] )
            ->groupBy( 'p.page_title' )
            ->orderBy( 'n', 'DESC' )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $aggRows = [];
        foreach ( $agg as $a ) $aggRows[] = $a;
        if ( !$aggRows ) {
            $out->addHTML( '<p><em>No approved experience reports from matched profiles.</em></p>' );
            return;
        }
        $out->addHTML( '<table class="pcp-pa-table"><thead><tr><th>medicine</th><th>n reports</th><th>mean efficacy</th><th>mean burden</th></tr></thead><tbody>' );
        foreach ( $aggRows as $a ) {
            $name = str_replace( '_', ' ', (string)$a->name );
            $effMean = (int)$a->eff_n > 0 ? round( (float)$a->eff_sum / (int)$a->eff_n, 2 ) : '—';
            $burMean = (int)$a->bur_n > 0 ? round( (float)$a->bur_sum / (int)$a->bur_n, 2 ) : '—';
            $out->addHTML( '<tr><th>' . htmlspecialchars( $name ) . '</th><td>' . (int)$a->n
                . '</td><td>' . htmlspecialchars( (string)$effMean ) . '</td><td>'
                . htmlspecialchars( (string)$burMean ) . '</td></tr>' );
        }
        $out->addHTML( '</tbody></table>' );
    }

    private function loadProfileRows( $dbr, array $profileIds ): array {
        if ( !$profileIds ) return [];
        $userFactory = MediaWikiServices::getInstance()->getUserFactory();
        $res = $dbr->newSelectQueryBuilder()
            ->select( [
                'p.prof_id', 'p.prof_user_id', 'p.prof_voter_hash',
                'n_fields' => '(SELECT COUNT(*) FROM ' . $dbr->tableName( 'pcp_profile_fields' )    . ' WHERE pf_profile_id = p.prof_id)',
                'n_dx'     => '(SELECT COUNT(*) FROM ' . $dbr->tableName( 'pcp_profile_diagnoses' ) . ' WHERE pd_profile_id = p.prof_id)',
                'n_meds'   => '(SELECT COUNT(*) FROM ' . $dbr->tableName( 'pcp_user_meds' )         . ' WHERE um_profile_id = p.prof_id)',
                'n_xr'     => '(SELECT COUNT(*) FROM ' . $dbr->tableName( 'pcp_experience_reports' ) . ' WHERE xr_voter_hash = p.prof_voter_hash)',
            ] )
            ->from( 'pcp_user_profiles', 'p' )
            ->where( [ 'p.prof_id' => $profileIds ] )
            ->orderBy( 'p.prof_id' )
            ->caller( __METHOD__ )
            ->fetchResultSet();

        // Bulk fetch demographic snippets
        $fields = $dbr->select( 'pcp_profile_fields',
            [ 'pf_profile_id', 'pf_key', 'pf_value_text' ],
            [
                'pf_profile_id' => $profileIds,
                'pf_namespace'  => 'demographics',
                'pf_key'        => [ 'birthday', 'sex_at_birth', 'country' ],
            ], __METHOD__ );
        $demoByPid = [];
        foreach ( $fields as $fr ) {
            $demoByPid[ (int)$fr->pf_profile_id ][ (string)$fr->pf_key ] = (string)$fr->pf_value_text;
        }

        $out = [];
        foreach ( $res as $r ) {
            $user = $userFactory->newFromId( (int)$r->prof_user_id );
            $pid = (int)$r->prof_id;
            $bd = $demoByPid[ $pid ]['birthday'] ?? null;
            $out[] = [
                'pid'        => $pid,
                'uid'        => (int)$r->prof_user_id,
                'username'   => $user ? $user->getName() : '?',
                'voter_hash' => (string)$r->prof_voter_hash,
                'age'        => $bd ? SpecialMyProfile::computeAge( $bd ) : null,
                'sex'        => $demoByPid[ $pid ]['sex_at_birth'] ?? null,
                'country'    => $demoByPid[ $pid ]['country'] ?? null,
                'n_fields'   => (int)$r->n_fields,
                'n_dx'       => (int)$r->n_dx,
                'n_meds'     => (int)$r->n_meds,
                'n_xr'       => (int)$r->n_xr,
            ];
        }
        return $out;
    }

    // ----- CSV -----

    private function emitCsv( $dbr, ?array $profileIds ) {
        $response = $this->getRequest()->response();
        $response->header( 'Content-Type: text/csv; charset=utf-8' );
        $response->header( 'Content-Disposition: attachment; filename="pharmacopedia-profilefilter.csv"' );
        $fp = fopen( 'php://output', 'w' );
        fputcsv( $fp, [ 'user_id', 'username', 'age', 'sex_at_birth', 'country', 'fields', 'diagnoses', 'manual_meds', 'experience_reports' ] );
        if ( $profileIds ) {
            foreach ( $this->loadProfileRows( $dbr, $profileIds ) as $r ) {
                fputcsv( $fp, CsvHelper::safeRow( [
                    $r['uid'], $r['username'], $r['age'] ?? '',
                    $r['sex'] ?? '', $r['country'] ?? '',
                    $r['n_fields'], $r['n_dx'], $r['n_meds'], $r['n_xr'],
                ] ) );
            }
        }
        fclose( $fp );
        $this->getOutput()->disable();
    }

    public function doesWrites() { return false; }
    protected function getGroupName() { return 'pharmacopedia'; }
}
