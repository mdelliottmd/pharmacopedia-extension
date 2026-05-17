<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * Read-only public view of a user's profile.
 * URL: Special:UserProfile/<username>
 *
 * Permission tiers:
 * - Anonymous viewer / other logged-in user → sees only fields with visibility ≥ 1
 *   (Public). Attribution is per the field/profile defaults.
 * - Profile owner viewing self → sees everything, with link back to Special:MyProfile.
 * - Sysop with pharmacopedia-profile-view-others-full → sees private fields too,
 *   with a "viewing as sysop" indicator.
 */
class SpecialUserProfile extends SpecialPage {

    public function __construct() {
        parent::__construct( 'UserProfile' );
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles', 'ext.pharmacopedia.share' ] );

        $par = trim( (string)$par );
        if ( $par === '' ) {
            $out->setPageTitle( 'User profile' );
            $out->addHTML(
                '<p>Specify a username in the URL — e.g. <code>Special:UserProfile/Alice</code> — '
                . 'or visit your own at <a href="' .
                htmlspecialchars( SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() ) .
                '">Special:MyProfile</a>.</p>'
            );
            return;
        }

        $store = new UserProfileStore();
        $profile = $store->getByUsername( $par );
        if ( !$profile ) {
            $out->setPageTitle( "User profile: $par" );
            $out->addHTML( '<p>No profile found for <strong>' .
                htmlspecialchars( $par ) . '</strong>.</p>' );
            return;
        }

        $viewer = $this->getUser();
        $isSelf  = $viewer->isRegistered() && (int)$profile->prof_user_id === $viewer->getId();
        $isSysop = $viewer->isAllowed( 'pharmacopedia-profile-view-others-full' );
        $canSeePrivate = $isSelf || $isSysop;
        // Phase 6: if owner has privacy mode on and viewer isn't an exception, hide profile.
        if ( !$isSelf && !$isSysop && \MediaWiki\Extension\Pharmacopedia\VisibilityResolver::hasPrivacyMode( (int)$profile->prof_id ) ) {
            $linkToken0 = trim( (string)$this->getRequest()->getVal( 'pcpshare', '' ) );
            $tokenAllows = $linkToken0 !== '' && \MediaWiki\Extension\Pharmacopedia\VisibilityResolver::canViewByRule(
                (int)$profile->prof_id, (int)$viewer->getId(), '*', null, $linkToken0
            );
            if ( !$tokenAllows ) {
                $out->setPageTitle( 'Profile is private' );
                $out->addWikiTextAsInterface(
                    "'''" . wfEscapeWikiText( $profile->prof_public_alias ?: $forUserName ) . "''' shares only via explicit links.\n\n" .
                    "If they have shared content with you specifically, the link they sent will include the access token."
                );
                return;
            }
        }
                $minVis = $canSeePrivate ? 0 : 1;
        // Phase 2-4: also accept a share-link token; if it permits *-wide access
        // for this profile, drop minVis to 0 for the entire view.
        $linkToken = trim( (string)$this->getRequest()->getVal( 'pcpshare', '' ) );
        if ( $linkToken !== '' ) {
            $ruleAllowsAll = \MediaWiki\Extension\Pharmacopedia\VisibilityResolver::canViewByRule(
                (int)$profile->prof_id, (int)$viewer->getId(), '*', null, $linkToken
            );
            if ( $ruleAllowsAll ) {
                $minVis = 0;
                \MediaWiki\Extension\Pharmacopedia\VisibilityResolver::logView(
                    null,
                    (int)$profile->prof_id,
                    (int)$viewer->getId(),
                    $viewer->isRegistered() ? null : (string)$this->getRequest()->getIP(),
                    '*',
                    null
                );
            }
        }

        // Determine the public display name shown at the top
        $displayHeader = $store->publicDisplayName( $profile, UserProfileStore::VIS_PUBLIC_DEFAULT );
        if ( $canSeePrivate ) {
            $userFactory = MediaWikiServices::getInstance()->getUserFactory();
            $realUser = $userFactory->newFromId( (int)$profile->prof_user_id );
            $realName = $realUser ? $realUser->getName() : '?';
            $headerTitle = "Profile: $realName";
        } else {
            $headerTitle = "Profile: $displayHeader";
        }
        $out->setPageTitle( $headerTitle );

        // Header banner
        $out->addHTML( '<div class="pcp-banner">' );
        $out->addHTML( '<span class="pcp-banner__title">' . htmlspecialchars( $displayHeader ) . '</span>' );
        if ( $isSelf ) {
            $out->addHTML( '<span class="pcp-banner__body">This is your profile. <a href="' .
                htmlspecialchars( SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() ) .
                '">Edit it on Special:MyProfile</a>.</span>' );
        } elseif ( $isSysop ) {
            $out->addHTML( '<span class="pcp-banner__body"><strong>Viewing as sysop</strong> &mdash; ' .
                'all fields are shown including those marked private by the user. ' .
                'Real user id: <code>' . (int)$profile->prof_user_id . '</code>.</span>' );
        } else {
            $out->addHTML( '<span class="pcp-banner__body">Public view. Only fields the user has chosen to share are displayed.</span>' );
        }
        $out->addHTML( '</div>' );

        // Sections
        $this->renderFields( $out, $store, $profile, $minVis, $isSysop );
        $this->renderDiagnoses( $out, $store, $profile, $minVis, $isSysop );
        $this->renderMeds( $out, $store, $profile, $minVis, $isSysop );
        $this->renderAssessments( $out, $store, $profile, $minVis, $isSysop );
    }

    private function visBadge( int $vis, bool $showSysop ): string {
        if ( !$showSysop ) return '';
        $icons = [ 0 => '🔒', 1 => '👁', 2 => '🆔', 3 => '🎭' ];
        $labels = [ 0 => 'private', 1 => 'public-default', 2 => 'public-username', 3 => 'public-anonymous' ];
        $ic = $icons[ $vis ] ?? '?';
        $lab = $labels[ $vis ] ?? '?';
        return ' <small class="pcp-vis-badge" title="' . $lab . '">' . $ic . '</small>';
    }

    private function renderFields( $out, $store, $profile, int $minVis, bool $isSysop ) {
        $profileId = (int)$profile->prof_id;

        // Group fields by namespace
        $byNs = [];
        foreach ( $store->getFields( $profileId, null, $minVis ) as $f ) {
            $ns = (string)$f->pf_namespace;
            $byNs[ $ns ][] = $f;
        }
        if ( !$byNs ) return;

        $nsLabels = [
            'demographics' => 'Demographics',
            'ocean'        => 'Personality — Big Five (OCEAN)',
        ];

        foreach ( $byNs as $ns => $rows ) {
            // These are all rendered as cards by renderAssessments below.
            if ( in_array( $ns, [ 'ocean', 'cati', 'cati_raw', 'catq', 'catq_raw', 'pid5bf', 'pid5bf_raw', 'mbti', 'mbti_raw', 'enneagram', 'enneagram_raw', 'raadsr', 'raadsr_raw', 'bfi10', 'bfi10_raw', 'nfcs', 'nfcs_raw', 'bpns', 'bpns_raw', 'whoqolbref', 'whoqolbref_raw' ], true ) ) continue;
            $label = $nsLabels[ $ns ] ?? ucfirst( $ns );
            $out->addHTML( '<h2>' . htmlspecialchars( $label ) . '</h2>' );
            $out->addHTML( '<table class="pcp-prof-readout"><tbody>' );
            foreach ( $rows as $r ) {
                $key = (string)$r->pf_key;
                $val = $r->pf_value_text !== null && $r->pf_value_text !== ''
                    ? (string)$r->pf_value_text
                    : ( $r->pf_value_num !== null ? rtrim( rtrim( (string)$r->pf_value_num, '0' ), '.' ) : '' );
                if ( $val === '' ) continue;

                // Birthday: always show computed age; show raw date only to self/sysop
                if ( $key === 'birthday' ) {
                    $age = SpecialMyProfile::computeAge( $val );
                    if ( $age === null ) continue;
                    $label = 'age';
                    $display = (string)$age;
                    if ( $minVis === 0 ) {
                        // self or sysop — show birthday too; format struct nicely if present
                        $birth = (string)$val;
                        if ( $birth !== '' && $birth[0] === '{' ) {
                            $struct = json_decode( $birth, true );
                            if ( is_array( $struct ) ) {
                                $birth = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatForDisplay( $struct );
                            }
                        }
                        $display .= ' <small>(born ' . htmlspecialchars( $birth ) . ')</small>';
                    }
                    $out->addHTML(
                        '<tr><th>' . $label . '</th>' .
                        '<td>' . $display . $this->visBadge( (int)$r->pf_visibility, $isSysop ) . '</td></tr>'
                    );
                    continue;
                }

                $out->addHTML(
                    '<tr><th>' . htmlspecialchars( $key ) . '</th>' .
                    '<td>' . htmlspecialchars( $val ) . $this->visBadge( (int)$r->pf_visibility, $isSysop ) . '</td></tr>'
                );
            }
            $out->addHTML( '</tbody></table>' );
        }
    }

    private function renderDiagnoses( $out, $store, $profile, int $minVis, bool $isSysop ) {
        $rows = $store->getDiagnoses( (int)$profile->prof_id, $minVis );
        if ( !$rows ) return;
        $out->addHTML( '<h2>Diagnoses</h2>' );
        $out->addHTML( '<ul class="pcp-prof-dx-readout">' );
        $statusLabels = [ 0=>'', 1=>'current', 2=>'past', 3=>'in remission', 4=>'suspected' ];
        $originLabels = [ 0=>'', 1=>'self-identified', 2=>'professional dx', 3=>'both' ];
        foreach ( $rows as $r ) {
            $parts = [];
            $parts[] = '<strong>' . htmlspecialchars( (string)$r->pd_description ) . '</strong>';
            if ( $r->pd_code )   $parts[] = '<code>' . htmlspecialchars( (string)$r->pd_code ) . '</code>';
            if ( $r->pd_system ) $parts[] = '<small>' . htmlspecialchars( (string)$r->pd_system ) . '</small>';
            $meta = [];
            if ( $r->pd_status )   $meta[] = $statusLabels[ (int)$r->pd_status ] ?? '';
            if ( $r->pd_origin )   $meta[] = $originLabels[ (int)$r->pd_origin ] ?? '';
            if ( $r->pd_severity ) $meta[] = 'severity ' . rtrim( rtrim( number_format( (float)$r->pd_severity, 1, '.', '' ), '0' ), '.' ) . '/100';
            if ( isset( $r->pd_disability ) && $r->pd_disability ) $meta[] = 'disability ' . rtrim( rtrim( number_format( (float)$r->pd_disability, 1, '.', '' ), '0' ), '.' ) . '/100';
            $selfFmt = null;
            $proFmt  = null;
            if ( !empty( $r->pd_date_struct ) ) {
                $dxS = json_decode( (string)$r->pd_date_struct, true );
                if ( is_array( $dxS ) ) $selfFmt = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatForDisplay( $dxS );
            }
            if ( !empty( $r->pd_date_struct_pro ) ) {
                $dxP = json_decode( (string)$r->pd_date_struct_pro, true );
                if ( is_array( $dxP ) ) $proFmt = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatForDisplay( $dxP );
            }
            if ( $selfFmt && $proFmt ) {
                $meta[] = 'self-noticed ' . $selfFmt . ' &middot; diagnosed ' . $proFmt;
            } elseif ( $selfFmt ) {
                $meta[] = ( (int)$r->pd_origin === 2 ? 'diagnosed ' : 'first noticed ' ) . $selfFmt;
            } elseif ( $proFmt ) {
                $meta[] = 'diagnosed ' . $proFmt;
            } elseif ( $r->pd_year_first ) {
                $meta[] = 'since ' . (int)$r->pd_year_first;
            }
            if ( $meta ) $parts[] = '<em>' . implode( ' &middot; ', array_filter( $meta ) ) . '</em>';
            $line = implode( ' &middot; ', $parts );
            $line .= $this->visBadge( (int)$r->pd_visibility, $isSysop );
            $out->addHTML( '<li>' . $line );
            if ( $r->pd_notes ) {
                $out->addHTML( '<br><small>' . htmlspecialchars( (string)$r->pd_notes ) . '</small>' );
            }
            $out->addHTML( '</li>' );
        }
        $out->addHTML( '</ul>' );
    }

    private function renderMeds( $out, $store, $profile, int $minVis, bool $isSysop ) {
        // Profile owner & sysop see XRs unconditionally; everyone else only when the
        // user has opted in to aggregating their approved experience reports on
        // this profile page (prof_show_xr_on_profile=1). Defaults to OFF so
        // per-page approved reports are NOT silently aggregated under the URL.
        $isOwnerViewing = ( $minVis === 0 );  // minVis is 0 only when viewer is owner or sysop
        $showXrOptIn    = !empty( $profile->prof_show_xr_on_profile );
        $showXr         = $isOwnerViewing || $isSysop || $showXrOptIn;

        $xrs  = $showXr ? $store->getExperienceReports( (string)$profile->prof_voter_hash ) : [];
        $meds = $store->getMeds( (int)$profile->prof_id, $minVis );

        if ( !$xrs && !$meds ) return;

        $out->addHTML( '<h2>Medicines tried</h2>' );

        if ( $xrs ) {
            $out->addHTML( '<h3>From experience reports on wiki pages</h3>' );
            $out->addHTML( '<ul>' );
            foreach ( $xrs as $xr ) {
                $title = str_replace( '_', ' ', (string)$xr->page_title );
                $url   = htmlspecialchars( Title::makeTitle( NS_MAIN, (string)$xr->page_title )->getLocalURL() );
                $eff = $xr->xr_efficacy !== null ? "efficacy " . (int)$xr->xr_efficacy . "/100" : null;
                $bur = $xr->xr_burden   !== null ? "burden "   . (int)$xr->xr_burden   . "/100" : null;
                $persp = (int)$xr->xr_perspective === 2 ? "clinical" : "personal";
                $parts = array_filter( [ $persp, $eff, $bur ] );
                $out->addHTML( '<li><a href="' . $url . '">' . htmlspecialchars( $title ) . '</a>' .
                    ( $parts ? ' <small>· ' . implode( ' · ', $parts ) . '</small>' : '' ) . '</li>' );
            }
            $out->addHTML( '</ul>' );
        }

        if ( $meds ) {
            $out->addHTML( '<h3>Other meds</h3>' );
            $statusLabels = [ 0=>'', 1=>'still taking', 2=>'stopped', 3=>'brief / rarely' ];
            $out->addHTML( '<ul>' );
            foreach ( $meds as $m ) {
                $parts = [];
                if ( $m->um_page_id ) {
                    // Resolve title from page_id
                    $title = Title::newFromID( (int)$m->um_page_id );
                    if ( $title ) {
                        $parts[] = '<a href="' . htmlspecialchars( $title->getLocalURL() ) . '">' . htmlspecialchars( (string)$m->um_med_name ) . '</a>';
                    } else {
                        $parts[] = '<strong>' . htmlspecialchars( (string)$m->um_med_name ) . '</strong>';
                    }
                } else {
                    $parts[] = '<strong>' . htmlspecialchars( (string)$m->um_med_name ) . '</strong>';
                }
                $meta = [];
                if ( $m->um_efficacy !== null )      $meta[] = 'efficacy ' . (int)$m->um_efficacy . '/100';
                if ( $m->um_burden   !== null )      $meta[] = 'burden '   . (int)$m->um_burden   . '/100';
                if ( $m->um_dose_mg  !== null )      $meta[] = round( (float)$m->um_dose_mg, 1 ) . ' mg/day';
                if ( $m->um_duration_days !== null ) $meta[] = (int)$m->um_duration_days . ' days';
                if ( $m->um_current )                $meta[] = $statusLabels[ (int)$m->um_current ] ?? '';
                $startFmt = null;
                $stopFmt  = null;
                if ( !empty( $m->um_start_struct ) ) {
                    $s = json_decode( (string)$m->um_start_struct, true );
                    if ( is_array( $s ) ) $startFmt = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatForDisplay( $s );
                }
                if ( !empty( $m->um_stop_struct ) ) {
                    $s = json_decode( (string)$m->um_stop_struct, true );
                    if ( is_array( $s ) ) $stopFmt = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatForDisplay( $s );
                }
                $periodStrs = [];
                if ( !empty( $m->um_periods ) ) {
                    $arr = json_decode( (string)$m->um_periods, true );
                    if ( is_array( $arr ) ) {
                        foreach ( $arr as $p ) {
                            $rg = [ 'kind' => 'range', 'start' => $p['start'] ?? null, 'end' => $p['end'] ?? null ];
                            $f = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatForDisplay( $rg );
                            if ( $f !== '' ) $periodStrs[] = $f;
                        }
                    }
                }
                if ( $periodStrs ) {
                    $meta[] = ( count( $periodStrs ) === 1 ? '' : 'periods: ' ) . implode( ' &middot; ', $periodStrs );
                } elseif ( $startFmt && $stopFmt ) $meta[] = $startFmt . ' → ' . $stopFmt;
                elseif ( $startFmt )         $meta[] = 'started ' . $startFmt;
                elseif ( $stopFmt )          $meta[] = 'stopped ' . $stopFmt;
                if ( $meta ) $parts[] = '<small>' . implode( ' · ', array_filter( $meta ) ) . '</small>';
                $line = implode( ' · ', $parts );
                $line .= $this->visBadge( (int)$m->um_visibility, $isSysop );
                $out->addHTML( '<li>' . $line );
                if ( $m->um_notes ) {
                    $out->addHTML( '<br><small>' . htmlspecialchars( (string)$m->um_notes ) . '</small>' );
                }
                $out->addHTML( '</li>' );
            }
            $out->addHTML( '</ul>' );
        }
    }

    public function doesWrites() { return false; }
    protected function getGroupName() { return 'users'; }

    private function renderAssessments( $out, $store, $profile, int $minVis, bool $isSysop ) {
        $profileId = (int)$profile->prof_id;
        $userName = '';
        $userFactory = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory();
        $u = $userFactory->newFromId( (int)$profile->prof_user_id );
        if ( $u ) { $userName = $u->getName(); }

        // Test keys to render, in display order. Each entry has:
        //   ns:       data namespace in pcp_profile_fields
        //   label:    section heading on the card
        //   shortLabel: short label for top of card
        //   hasReport: true if Special:MyAssessment/<key> has a rich report
        //   build:    callable($scores) returning [ 'headline' => str, 'sub' => str ]
        $tests = [
            'ocean'     => [
                'label'     => 'Big Five (OCEAN)',
                'short'     => 'OCEAN',
                'report'    => false,
                'build'     => function ( $s ) { return $this->oceanHeadline( $s ); },
            ],
            'cati'      => [
                'label'     => 'CATI (autistic traits)',
                'short'     => 'CATI',
                'report'    => true,
                'build'     => function ( $s ) { return $this->totalHeadline( $s, 210, 'CATI total', 134 ); },
            ],
            'catq'      => [
                'label'     => 'CAT-Q (social camouflaging)',
                'short'     => 'CAT-Q',
                'report'    => true,
                'build'     => function ( $s ) { return $this->totalHeadline( $s, 175, 'CAT-Q total', 110 ); },
            ],
            'pid5bf'    => [
                'label'     => 'PID-5-BF (personality pathology)',
                'short'     => 'PID-5-BF',
                'report'    => true,
                'build'     => function ( $s ) { return $this->pid5bfHeadline( $s ); },
            ],
            'mbti'      => [
                'label'     => 'MBTI / Jungian Type',
                'short'     => 'MBTI',
                'report'    => true,
                'build'     => function ( $s ) { return $this->mbtiHeadline( $s ); },
            ],
            'enneagram' => [
                'label'     => 'Enneagram of Personality',
                'short'     => 'Enneagram',
                'report'    => true,
                'build'     => function ( $s ) { return $this->enneagramHeadline( $s ); },
            ],
            'nfcs'      => [
                'label'     => 'Need for Closure (brief)',
                'short'     => 'NFCS',
                'report'    => true,
                'build'     => function ( $s ) { return $this->totalHeadline( $s, 90, 'NFCS total', 70 ); },
            ],
            'bpns'      => [
                'label'     => 'Basic Psychological Needs',
                'short'     => 'BPNS',
                'report'    => true,
                'build'     => function ( $s ) { return $this->meanHeadline( $s, 7, 'BPNS mean' ); },
            ],
            'whoqolbref' => [
                'label'     => 'WHO Quality of Life, Brief',
                'short'     => 'WHOQOL',
                'report'    => true,
                'build'     => function ( $s ) { return $this->totalHeadline( $s, 100, 'WHOQOL overall', 50 ); },
            ],
        ];

        $cards = [];
        foreach ( $tests as $key => $def ) {
            $scores = []; $takenAt = null; $vis = 0;
            foreach ( $store->getFields( $profileId, $key, 0 ) as $f ) {
                $fk = (string)$f->pf_key;
                if ( $fk === '_vis' )     { $vis = (int)( $f->pf_value_num ?? 0 ); continue; }
                if ( $fk === 'taken_at' ) { $takenAt = (string)$f->pf_value_text; continue; }
                $scores[ $fk ] = $f->pf_value_num !== null ? (float)$f->pf_value_num : null;
            }
            if ( !$scores ) continue;
            if ( !$isSysop && $minVis > 0 && $vis < $minVis ) continue;

            $h = ( $def['build'] )( $scores );
            $cards[] = [
                'key'       => $key,
                'def'       => $def,
                'headline'  => $h['headline'] ?? '',
                'sub'       => $h['sub'] ?? '',
                'vis'       => $vis,
                'takenAt'   => $takenAt,
            ];
        }
        if ( !$cards ) return;

        $out->addHTML( '<h2>Personality &amp; autism assessments</h2>' );
        $out->addHTML( '<div class="pcp-up-cards">' );
        foreach ( $cards as $c ) {
            $reportLink = '';
            if ( $c['def']['report'] && $userName !== '' ) {
                $url = \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'MyAssessment', $c['key'] )
                    ->getLocalURL( [ 'user' => $userName ] );
                $reportLink = '<a class="pcp-up-card-link" href="' . htmlspecialchars( $url ) . '">View full report &rarr;</a>';
            }
            $dateStr = '';
            if ( $c['takenAt'] ) {
                $dateStr = '<span class="pcp-up-card-date">' . htmlspecialchars( substr( (string)$c['takenAt'], 0, 10 ) ) . '</span>';
            }
            $out->addHTML(
                '<div class="pcp-up-card">' .
                '<div class="pcp-up-card-head">' .
                '<span class="pcp-up-card-name">' . htmlspecialchars( (string)$c['def']['short'] ) . '</span>' .
                $this->visBadge( (int)$c['vis'], $isSysop ) .
                '</div>' .
                '<div class="pcp-up-card-full">' . htmlspecialchars( (string)$c['def']['label'] ) . '</div>' .
                '<div class="pcp-up-card-headline">' . $c['headline'] . '</div>' .
                ( $c['sub'] !== '' ? '<div class="pcp-up-card-sub">' . $c['sub'] . '</div>' : '' ) .
                '<div class="pcp-up-card-foot">' . $reportLink . $dateStr . '</div>' .
                '</div>'
            );
        }
        $out->addHTML( '</div>' );
    }

    // ===== Per-test headline builders =====

    private function oceanHeadline( array $s ): array {
        $labels = [ 'O' => 'O', 'C' => 'C', 'E' => 'E', 'A' => 'A', 'N' => 'N' ];
        $parts = [];
        foreach ( $labels as $k => $lab ) {
            if ( !isset( $s[ $k ] ) ) continue;
            $v = (int)round( (float)$s[ $k ] );
            $parts[] = '<span class="pcp-up-ocean-trait"><span class="pcp-up-ocean-label">' . $lab . '</span>'
                . '<span class="pcp-up-ocean-bar"><span class="pcp-up-ocean-fill" style="width:' . $v . '%"></span></span>'
                . '<span class="pcp-up-ocean-val">' . $v . '</span></span>';
        }
        return [
            'headline' => '<div class="pcp-up-ocean">' . implode( '', $parts ) . '</div>',
            'sub'      => '<em>Continuous trait scores, 0&ndash;100.</em>',
        ];
    }

    private function totalHeadline( array $s, int $max, string $label, int $cutoff ): array {
        if ( !isset( $s['total'] ) ) return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        $total = (float)$s['total'];
        $totalStr = number_format( $total, $total == (int)$total ? 0 : 1 );
        $above = $total >= $cutoff;
        $head = '<span class="pcp-up-big">' . htmlspecialchars( $totalStr ) . '</span><span class="pcp-up-of">/ ' . $max . '</span>';
        $sub  = $above
            ? '<span class="pcp-up-above">above suggestive cutoff (&ge; ' . $cutoff . ')</span>'
            : '<span class="pcp-up-below">below suggestive cutoff (&ge; ' . $cutoff . ')</span>';
        return [ 'headline' => $head, 'sub' => $sub ];
    }

    private function meanHeadline( array $s, float $max, string $label ): array {
        if ( !isset( $s['total'] ) ) return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        $m = (float)$s['total'];
        $head = '<span class="pcp-up-big">' . number_format( $m, 2 ) . '</span><span class="pcp-up-of">/ ' . $max . '</span>';
        return [ 'headline' => $head, 'sub' => '<em>Overall mean across all items.</em>' ];
    }

    private function pid5bfHeadline( array $s ): array {
        // Identify the highest-scoring subscale by raw score.
        $top = null; $topVal = -INF;
        foreach ( $s as $k => $v ) {
            if ( strpos( $k, 'subscale_' ) !== 0 ) continue;
            if ( $v === null ) continue;
            if ( (float)$v > $topVal ) { $topVal = (float)$v; $top = $k; }
        }
        $subscaleNames = [
            'subscale_NA'  => 'Negative Affect',
            'subscale_DET' => 'Detachment',
            'subscale_ANT' => 'Antagonism',
            'subscale_DIS' => 'Disinhibition',
            'subscale_PSY' => 'Psychoticism',
        ];
        $totalStr = isset( $s['total'] ) ? number_format( (float)$s['total'], 2 ) : '?';
        if ( $top !== null ) {
            $name = $subscaleNames[ $top ] ?? $top;
            return [
                'headline' => '<span class="pcp-up-big">' . htmlspecialchars( $name ) . '</span>',
                'sub'      => '<em>Highest of 5 facets. Total ' . htmlspecialchars( $totalStr ) . '.</em>',
            ];
        }
        return [ 'headline' => 'Total ' . htmlspecialchars( $totalStr ), 'sub' => '' ];
    }

    private function mbtiHeadline( array $s ): array {
        $code = '';
        foreach ( \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::DICHOTOMIES as $k => $def ) {
            if ( !isset( $s[ $k ] ) || $s[ $k ] === null ) { $code = ''; break; }
            $v = (float)$s[ $k ];
            $code .= ( $v < 0 ? $def['left'] : $def['right'] );
        }
        if ( strlen( $code ) !== 4 ) {
            return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        }
        $typeInfo = \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::TYPES[ $code ] ?? null;
        $name = $typeInfo ? $typeInfo[0] : '';
        return [
            'headline' => '<span class="pcp-up-big pcp-up-typecode">' . htmlspecialchars( $code ) . '</span>',
            'sub'      => $name !== '' ? '<em>' . htmlspecialchars( $name ) . '</em>' : '',
        ];
    }

    private function enneagramHeadline( array $s ): array {
        // Pick the highest-scoring type and its second-highest neighbor as the wing.
        $top = null; $topVal = -INF;
        for ( $t = 1; $t <= 9; $t++ ) {
            $k = 'type' . $t;
            if ( !isset( $s[ $k ] ) || $s[ $k ] === null ) continue;
            $v = (float)$s[ $k ];
            if ( $v > $topVal ) { $topVal = $v; $top = $t; }
        }
        if ( $top === null ) return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        // Wing: higher-scoring of the two neighbors (mod 9)
        $left  = $top === 1 ? 9 : $top - 1;
        $right = $top === 9 ? 1 : $top + 1;
        $lv = isset( $s[ 'type' . $left ] ) && $s[ 'type' . $left ] !== null ? (float)$s[ 'type' . $left ] : -INF;
        $rv = isset( $s[ 'type' . $right ] ) && $s[ 'type' . $right ] !== null ? (float)$s[ 'type' . $right ] : -INF;
        $wing = $lv >= $rv ? $left : $right;
        $code = $top . 'w' . $wing;
        $typeInfo = \MediaWiki\Extension\Pharmacopedia\Assessments\Enneagram::TYPES[ $top ] ?? null;
        $name = $typeInfo && isset( $typeInfo['name'] ) ? (string)$typeInfo['name']
              : ( is_array( $typeInfo ) && isset( $typeInfo[0] ) ? (string)$typeInfo[0] : '' );
        return [
            'headline' => '<span class="pcp-up-big pcp-up-typecode">' . htmlspecialchars( $code ) . '</span>',
            'sub'      => $name !== '' ? '<em>' . htmlspecialchars( $name ) . '</em>' : '',
        ];
    }

}
