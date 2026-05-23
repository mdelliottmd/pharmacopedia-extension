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
        $this->renderAsrsScreen( $out, $store, $profile, $minVis, $isSysop );
        $this->renderAmaasCard( $out, $store, $profile, $minVis, $isSysop );
        $this->renderFormalTesting( $out, $profile, $minVis, $isSysop );
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

        // Namespaces handled as cards by renderAssessments below. The base
        // list is every self-takeable scale in the registry (plus its _raw
        // namespace), plus a few legacy aliases (bfi10, raadsr) that predate
        // the registry. Adding a new self-takeable scale to the registry
        // automatically routes its rows here.
        $cardNamespaces = [ 'bfi10', 'bfi10_raw', 'raadsr', 'raadsr_raw', 'ocipcp', 'ocipcp_raw' ];
        foreach ( \MediaWiki\Extension\Pharmacopedia\Assessments\AssessmentRegistry::keysSelfTakeable() as $regKey ) {
            $cardNamespaces[] = $regKey;
            $cardNamespaces[] = $regKey . '_raw';
        }
        $cardNamespaces = array_values( array_unique( $cardNamespaces ) );

        foreach ( $byNs as $ns => $rows ) {
            if ( in_array( $ns, $cardNamespaces, true ) ) continue;
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
            $out->addHTML( '</div>' );
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

    /** Resolved gender key for the profile being rendered. Set by renderAssessments(); read by catiHeadline() to pick CatiNorms reference group. */
    private ?string $catiGenderKey = null;

    public function doesWrites() { return false; }
    protected function getGroupName() { return 'users'; }

    /**
     * ASRS v1.1 Part A screener: the verdict card. The asrs namespace
     * stores positive (0/1), shaded_count (0-6), complete (0/1),
     * taken_at, and _vis. _vis governs visibility and is never shown.
     */
    private function renderAsrsScreen( $out, $store, $profile, int $minVis, bool $isSysop ) {
        $profileId = (int)$profile->prof_id;
        $f = []; $vis = 0; $takenAt = null;
        foreach ( $store->getFields( $profileId, 'asrs', 0 ) as $row ) {
            $k = (string)$row->pf_key;
            if ( $k === '_vis' )     { $vis = (int)( $row->pf_value_num ?? 0 ); continue; }
            if ( $k === 'taken_at' ) { $takenAt = (string)$row->pf_value_text; continue; }
            $f[ $k ] = $row->pf_value_num !== null ? (int)$row->pf_value_num : null;
        }
        if ( !$f ) return;
        if ( !$isSysop && $minVis > 0 && $vis < $minVis ) return;
        // The verdict card represents a finished screen; an incomplete
        // assessment renders nothing (incomplete-state is out of scope).
        if ( (int)( $f['complete'] ?? 0 ) !== 1 ) return;

        $positive = (int)( $f['positive'] ?? 0 ) === 1;
        $shaded   = max( 0, min( 6, (int)( $f['shaded_count'] ?? 0 ) ) );
        $word     = $positive ? 'Screen positive' : 'Screen negative';
        $wordCls  = $positive ? 'v-word' : 'v-word neg';
        $detail   = $positive
            ? 'A positive screen warrants full ADHD evaluation.'
            : 'A negative screen makes ADHD unlikely but does not rule it out; '
                . 'evaluate further if clinical suspicion persists.';
        $strip = '';
        for ( $i = 0; $i < 6; $i++ ) {
            $strip .= $i < $shaded ? '<span class="on"></span>' : '<span></span>';
        }
        $date = '';
        if ( $takenAt && strlen( $takenAt ) >= 8 ) {
            $ts = \DateTime::createFromFormat( 'YmdHis', str_pad( $takenAt, 14, '0' ) );
            if ( $ts ) { $date = $ts->format( 'j M Y' ); }
        }

        $out->addHTML( '<h2>ADHD screening</h2>' );
        $out->addHTML(
            '<div class="verdict">' .
            '<div class="v-head"><span class="v-code">ASRS &middot; binary screen</span></div>' .
            '<div class="v-body">' .
            '<span class="v-pre">Screening result</span>' .
            '<p class="' . $wordCls . '">' . $word . '</p>' .
            '<p class="v-detail">' . $shaded . ' of 6 cardinal items above cutoff. '
                . htmlspecialchars( $detail ) . '</p>' .
            '<div class="v-binary">' . $strip . '</div>' .
            '</div>' .
            '<div class="v-foot"><a>About this screen &#8594;</a>' .
            ( $date !== '' ? '<span class="d">' . htmlspecialchars( $date ) . '</span>' : '' ) .
            '</div>' .
            '</div>'
        );
    }

    /**
     * AMAAS-SR self-report: the featured card, radar face. The three
     * symptom subscales (INA, HYP, IMP) plot as percentages of each
     * subscale's maximum. The dashed amber threshold is a fixed 50%
     * triangle, captioned arbitrary and experimental, since AMAAS-SR
     * has no validated cutoff. The amaas namespace stores
     * subscale_<CODE>, complete, taken_at, and _vis; _vis is never shown.
     */
    private function renderAmaasCard( $out, $store, $profile, int $minVis, bool $isSysop ) {
        $profileId = (int)$profile->prof_id;
        $f = []; $vis = 0; $takenAt = null;
        foreach ( $store->getFields( $profileId, 'amaas', 0 ) as $row ) {
            $k = (string)$row->pf_key;
            if ( $k === '_vis' )     { $vis = (int)( $row->pf_value_num ?? 0 ); continue; }
            if ( $k === 'taken_at' ) { $takenAt = (string)$row->pf_value_text; continue; }
            $f[ $k ] = $row->pf_value_num !== null ? (float)$row->pf_value_num : null;
        }
        if ( !$f ) return;
        if ( !$isSysop && $minVis > 0 && $vis < $minVis ) return;
        if ( (int)( $f['complete'] ?? 0 ) !== 1 ) return;

        $Amaas = \MediaWiki\Extension\Pharmacopedia\Assessments\Amaas::class;
        $pct = [];
        foreach ( [ 'INA', 'HYP', 'IMP' ] as $code ) {
            $raw = $f[ 'subscale_' . $code ] ?? null;
            $max = (float)$Amaas::subscaleMax( $code );
            $pct[ $code ] = ( $raw !== null && $max > 0 )
                ? max( 0.0, min( 1.0, (float)$raw / $max ) ) : 0.0;
        }

        // Radar geometry: centre (80,70), full radius 50; INA up, HYP
        // lower-right, IMP lower-left, 120 degrees apart.
        $coord = static function ( string $c, float $p ): array {
            $r = 50.0 * $p;
            if ( $c === 'INA' ) return [ 80.0, round( 70.0 - $r, 2 ) ];
            if ( $c === 'HYP' ) return [ round( 80.0 + 0.86603 * $r, 2 ), round( 70.0 + 0.5 * $r, 2 ) ];
            return [ round( 80.0 - 0.86603 * $r, 2 ), round( 70.0 + 0.5 * $r, 2 ) ];
        };
        $tri = static function ( float $p ) use ( $coord ): string {
            $s = [];
            foreach ( [ 'INA', 'HYP', 'IMP' ] as $c ) { $xy = $coord( $c, $p ); $s[] = $xy[0] . ',' . $xy[1]; }
            return implode( ' ', $s );
        };
        $data = []; $dots = '';
        foreach ( [ 'INA', 'HYP', 'IMP' ] as $c ) {
            $xy = $coord( $c, $pct[ $c ] );
            $data[] = $xy[0] . ',' . $xy[1];
            $dots .= '<circle cx="' . $xy[0] . '" cy="' . $xy[1] . '" r="2.6"/>';
        }
        $svg = '<svg viewBox="0 0 160 120" aria-hidden="true">'
            . '<polygon points="' . $tri( 1.0 ) . '" fill="none" stroke="#232230" stroke-width="1"/>'
            . '<g stroke="#1a1922" stroke-width="1">'
            . '<line x1="80" y1="70" x2="80" y2="20"/>'
            . '<line x1="80" y1="70" x2="123.3" y2="95"/>'
            . '<line x1="80" y1="70" x2="36.7" y2="95"/></g>'
            . '<polygon points="' . $tri( 0.5 ) . '" fill="none" stroke="#c7884a" '
                . 'stroke-width="1.3" stroke-dasharray="3 3"/>'
            . '<polygon points="' . implode( ' ', $data ) . '" fill="rgba(139,92,246,0.16)" '
                . 'stroke="#8b5cf6" stroke-width="1.8"/>'
            . '<g fill="#a78bfa">' . $dots . '</g>'
            . '<g font-family="Geist, sans-serif" font-size="9" fill="#94909c">'
            . '<text x="80" y="13" text-anchor="middle">INA</text>'
            . '<text x="128" y="106" text-anchor="middle">HYP</text>'
            . '<text x="32" y="106" text-anchor="middle">IMP</text></g></svg>';

        $facts = '';
        foreach ( [ 'INA' => 'Inattention', 'HYP' => 'Hyperactivity', 'IMP' => 'Impulsivity' ] as $code => $label ) {
            $facts .= '<div class="fact"><span class="fl">' . $label . '</span>'
                . '<span class="fv">' . round( $pct[ $code ] * 100 ) . '%</span></div>';
        }

        $date = '';
        if ( $takenAt && strlen( $takenAt ) >= 8 ) {
            $ts = \DateTime::createFromFormat( 'YmdHis', str_pad( $takenAt, 14, '0' ) );
            if ( $ts ) { $date = $ts->format( 'j M Y' ); }
        }

        $out->addHTML( '<h2>Attention self-report</h2>' );
        $out->addHTML(
            '<div class="fc fc-experimental">' .
            '<div class="fc-head">' .
            '<span class="fc-code">AMAAS-PCP-SR &middot; experimental self-report</span>' .
            '<span class="fc-name">Self-reported attention and self-regulation</span>' .
            '</div>' .
            '<div class="fc-viz">' . $svg .
            '<p class="viz-cap">Dashed amber: an arbitrary, experimental threshold at '
                . '50%, not a validated cutoff.</p>' .
            '</div>' .
            '<div class="fc-facts">' . $facts . '</div>' .
            '<div class="fc-foot"><a>View full report &#8594;</a>' .
            ( $date !== '' ? '<span class="d">' . htmlspecialchars( $date ) . '</span>' : '' ) .
            '</div>' .
            '</div>'
        );
    }

    private function renderAssessments( $out, $store, $profile, int $minVis, bool $isSysop ) {
        $profileId = (int)$profile->prof_id;
        $userName = '';
        $userFactory = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory();
        $u = $userFactory->newFromId( (int)$profile->prof_user_id );
        if ( $u ) { $userName = $u->getName(); }

        // Resolve gender for CATI norm-group lookup (uses the same visibility
        // clearance as everything else this viewer can see).
        $sexAtBirth = null; $genderIdentity = null;
        foreach ( $store->getFields( $profileId, 'demographics', $minVis ) as $df ) {
            $dk = (string)$df->pf_key;
            if ( $dk === 'sex_at_birth' )    $sexAtBirth = $df->pf_value_text;
            if ( $dk === 'gender_identity' ) $genderIdentity = $df->pf_value_text;
        }
        $this->catiGenderKey = \MediaWiki\Extension\Pharmacopedia\Assessments\CatiNorms::genderKey( $sexAtBirth, $genderIdentity );

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
                'report'    => true,
                'build'     => function ( $s ) { return $this->oceanHeadline( $s ); },
            ],
            'cati'      => [
                'label'     => 'CATI-PCP (autistic traits)',
                'short'     => 'CATI-PCP',
                'report'    => true,
                'build'     => function ( $s ) { return $this->catiHeadline( $s, $this->catiGenderKey ); },
            ],
            'catq'      => [
                'label'     => 'CAT-Q-PCP (social camouflaging)',
                'short'     => 'CAT-Q-PCP',
                'report'    => true,
                'build'     => function ( $s ) { return $this->catqHeadline( $s ); },
            ],
            'pid5bf'    => [
                'label'     => 'PID-5-BF-PCP (personality pathology)',
                'short'     => 'PID-5-BF-PCP',
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
                'short'     => 'Enneagram-PCP',
                'report'    => true,
                'build'     => function ( $s ) { return $this->enneagramHeadline( $s ); },
            ],
            'nfcs'      => [
                'label'     => 'Need for Closure (brief)',
                'short'     => 'NFCS-PCP',
                'report'    => true,
                'build'     => function ( $s ) { return $this->nfcsHeadline( $s ); },
            ],
            'bpns'      => [
                'label'     => 'Basic Psychological Needs',
                'short'     => 'BPNS-PCP',
                'report'    => true,
                'build'     => function ( $s ) { return $this->bpnsHeadline( $s ); },
            ],
            'whoqolbref' => [
                'label'     => 'WHO Quality of Life, Brief',
                'sublabel'  => '(higher is better)',
                'short'     => 'WHOQOL-BREF-PCP',
                'report'    => true,
                'build'     => function ( $s ) { return $this->whoqolbrefHeadline( $s ); },
            ],
            'ocipcp'    => [
                'label'     => 'OCI-PCP (Adapted from OCI-R)',
                'short'     => 'OCI-PCP',
                'report'    => true,
                'build'     => function ( $s ) { return $this->ocipcpHeadline( $s ); },
            ],
            'hyd'       => [
                'label'     => 'HYD-PCP (wellbeing check-in)',
                'sublabel'  => '(higher is better)',
                'short'     => 'HYD-PCP',
                'report'    => true,
                'build'     => function ( $s ) { return $this->hydHeadline( $s ); },
            ],
            'bsl23'     => [
                'label'     => 'BSL-23-PCP (borderline-spectrum check-in)',
                'short'     => 'BSL-23-PCP',
                'report'    => true,
                'build'     => function ( $s ) { return $this->bsl23Headline( $s ); },
            ],
            'ess'       => [
                'label'     => 'ESS-PCP (daytime-sleepiness check)',
                'short'     => 'ESS-PCP',
                'report'    => true,
                'build'     => function ( $s ) { return $this->essHeadline( $s ); },
            ],
        ];
        // Autopopulate any registered self-takeable scale that doesn't have
        // a bespoke headline builder above. New scales get a generic card
        // (total + interpret() reading) until someone wants a richer one.
        foreach ( \MediaWiki\Extension\Pharmacopedia\Assessments\AssessmentRegistry::keysSelfTakeable() as $regKey ) {
            if ( isset( $tests[ $regKey ] ) ) {
                continue;
            }
            $cls = \MediaWiki\Extension\Pharmacopedia\Assessments\AssessmentRegistry::scorerClass( $regKey );
            if ( !$cls ) {
                continue;
            }
            $tests[ $regKey ] = [
                'label'  => (string)$cls::FULL_NAME,
                'short'  => (string)$cls::NAME,
                'report' => true,
                'build'  => function ( $s ) use ( $cls ) {
                    return $this->genericHeadline( $cls, $s );
                },
            ];
        }

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
                '<div class="pcp-up-card-full">' . htmlspecialchars( (string)$c['def']['label'] ) . ( !empty( $c['def']['sublabel'] ) ? '<br><span class="pcp-up-card-sublabel">' . htmlspecialchars( (string)$c['def']['sublabel'] ) . '</span>' : '' ) . '</div>' .
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

    /**
     * HYD-PCP read-view card: a one-line summary of a completed wellbeing
     * check-in. The overall figure is the mean of the eight domains the
     * person answered, on the bipolar -100 (really poorly) to +100 (really
     * well) scale. The headline shows that mean on a bar whose midpoint is
     * the neutral 0; the sub carries Hyd::interpret()'s gentle reading.
     */
    private function hydHeadline( array $s ): array {
        $Hyd = \MediaWiki\Extension\Pharmacopedia\Assessments\Hyd::class;
        if ( !isset( $s['total'] ) || $s['total'] === null ) {
            return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        }
        $total = (float)$s['total'];
        $min   = (float)$Hyd::SCALE_MIN;
        $max   = (float)$Hyd::SCALE_MAX;
        // Map the bipolar -100..+100 figure onto a 0..100% bar fill.
        $pct  = max( 0.0, min( 100.0, ( ( $total - $min ) / ( $max - $min ) ) * 100.0 ) );
        $disp = number_format( $total, $total == (int)$total ? 0 : 1 );

        $headline = '<div class="pcp-up-cati">'
            . '<span class="pcp-up-cati-trait">'
            . '<span class="pcp-up-cati-label">Overall</span>'
            . '<span class="pcp-up-cati-bar">'
                . '<span class="pcp-up-cati-fill" style="width:' . number_format( $pct, 1 ) . '%"></span>'
                . '<span class="pcp-up-cati-cutoff-mark" style="left:50%" title="Neutral midpoint: 0"></span>'
            . '</span>'
            . '<span class="pcp-up-cati-val">' . htmlspecialchars( $disp ) . '</span>'
            . '</span>'
            . '</div>';

        $reading = $Hyd::interpret( $s );
        $sub = '<em>' . htmlspecialchars( $reading['overall'] ) . '</em>';

        return [ 'headline' => $headline, 'sub' => $sub ];
    }

    /**
     * BSL-23-PCP read-view card: a one-line summary of a completed
     * borderline-spectrum check-in. The overall figure is the mean of the
     * items the person answered, on the 0 (not at all) to 4 (very strongly)
     * symptom-intensity scale, so a higher figure means more symptom load.
     * The headline shows that mean on a bar with no midpoint marker (the
     * scale is unidimensional, not bipolar); the sub carries the severity
     * band and Bsl23::interpret()'s gentle, non-diagnostic reading.
     */
    private function bsl23Headline( array $s ): array {
        $Bsl23 = \MediaWiki\Extension\Pharmacopedia\Assessments\Bsl23::class;
        if ( !isset( $s['total'] ) || $s['total'] === null ) {
            return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        }
        $total = (float)$s['total'];
        $min   = (float)$Bsl23::SCALE_MIN;
        $max   = (float)$Bsl23::SCALE_MAX;
        // Map the 0..4 figure onto a 0..100% bar fill.
        $pct  = max( 0.0, min( 100.0, ( ( $total - $min ) / ( $max - $min ) ) * 100.0 ) );
        $disp = number_format( $total, $total == (int)$total ? 0 : 2 );

        $headline = '<div class="pcp-up-cati">'
            . '<span class="pcp-up-cati-trait">'
            . '<span class="pcp-up-cati-label">Overall</span>'
            . '<span class="pcp-up-cati-bar">'
                . '<span class="pcp-up-cati-fill" style="width:' . number_format( $pct, 1 ) . '%"></span>'
            . '</span>'
            . '<span class="pcp-up-cati-val">' . htmlspecialchars( $disp ) . '</span>'
            . '</span>'
            . '</div>';

        $band    = $Bsl23::severityBand( $total );
        $reading = $Bsl23::interpret( $s );
        $sub = '';
        if ( $band !== null ) {
            $sub .= '<strong>' . htmlspecialchars( $band ) . '</strong> &middot; ';
        }
        $sub .= '<em>' . htmlspecialchars( $reading['overall'] ) . '</em>';

        return [ 'headline' => $headline, 'sub' => $sub ];
    }

    /**
     * ESS-PCP read-view card: a one-line summary of a completed daytime-
     * sleepiness check. The overall figure is the Epworth total, summed across
     * the eight situations on the 0-to-24 scale, so a higher figure means more
     * daytime sleepiness. The headline shows that total on a bar; the sub
     * carries the band and Ess::interpret()'s gentle, non-diagnostic reading.
     */
    private function essHeadline( array $s ): array {
        $Ess = \MediaWiki\Extension\Pharmacopedia\Assessments\Ess::class;
        if ( !isset( $s['total'] ) || $s['total'] === null ) {
            return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        }
        $total = (float)$s['total'];
        $max   = (float)count( $Ess::ITEMS ) * (float)$Ess::SCALE_MAX;
        // Map the 0..24 total onto a 0..100% bar fill.
        $pct  = $max > 0 ? max( 0.0, min( 100.0, ( $total / $max ) * 100.0 ) ) : 0.0;
        $disp = number_format( $total, $total == (int)$total ? 0 : 1 );

        $headline = '<div class="pcp-up-cati">'
            . '<span class="pcp-up-cati-trait">'
            . '<span class="pcp-up-cati-label">Overall</span>'
            . '<span class="pcp-up-cati-bar">'
                . '<span class="pcp-up-cati-fill" style="width:' . number_format( $pct, 1 ) . '%"></span>'
            . '</span>'
            . '<span class="pcp-up-cati-val">' . htmlspecialchars( $disp ) . '</span>'
            . '</span>'
            . '</div>';

        $band    = $Ess::severityBand( $total );
        $reading = $Ess::interpret( $s );
        $sub = '';
        if ( $band !== null ) {
            $sub .= '<strong>' . htmlspecialchars( $band ) . '</strong> &middot; ';
        }
        $sub .= '<em>' . htmlspecialchars( $reading['overall'] ) . '</em>';

        return [ 'headline' => $headline, 'sub' => $sub ];
    }

    /**
     * Generic fallback headline for any registered self-takeable scale that
     * does not have a bespoke per-test builder above. Shows the global score
     * (if any) and the scorer's interpret() reading. Triggered when a new
     * scale (e.g. EDE-Q-PCP) is added to the registry: it lands here with no
     * UserProfile-side wiring needed.
     */
    private function genericHeadline( string $cls, array $s ): array {
        if ( !isset( $s['total'] ) || $s['total'] === null ) {
            return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        }
        $total = (float)$s['total'];
        $disp  = number_format( $total, $total == (int)$total ? 0 : 2 );
        $headline = '<div class="pcp-up-generic-total">'
            . '<span class="pcp-up-generic-label">Total</span>'
            . '<span class="pcp-up-generic-val">' . htmlspecialchars( $disp ) . '</span>'
            . '</div>';

        $sub = '';
        if ( method_exists( $cls, 'interpret' ) ) {
            try {
                $reading = $cls::interpret( $s );
                if ( is_array( $reading ) ) {
                    $sub = (string)( $reading['overall'] ?? '' );
                } elseif ( is_string( $reading ) ) {
                    $sub = $reading;
                }
            } catch ( \Throwable $e ) {
                $sub = '';
            }
        }
        if ( $sub !== '' ) {
            $sub = '<em>' . htmlspecialchars( $sub ) . '</em>';
        }
        return [ 'headline' => $headline, 'sub' => $sub ];
    }

    /** OCI-PCP read-view card: total bar + 6-axis subscale radar + verdict. */
    private function ocipcpHeadline( array $s ): array {
        if ( !isset( $s['total'] ) || $s['total'] === null ) {
            return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        }
        $Ocipcp  = \MediaWiki\Extension\Pharmacopedia\Assessments\Ocipcp::class;
        $total   = (float)$s['total'];
        $cutoff  = (float)$Ocipcp::CUTOFF_TOTAL;
        $concern = (float)$Ocipcp::SUBSCALE_CONCERN;
        $pct       = max( 0.0, min( 100.0, ( $total / 72.0 ) * 100.0 ) );
        $cutoffPct = ( $cutoff / 72.0 ) * 100.0;
        $disp = number_format( $total, $total == (int)$total ? 0 : 1 );

        // Total bar (0-72) with the OCI-R screening-cutoff mark at 21.
        $headline = '<div class="pcp-up-cati">'
            . '<span class="pcp-up-cati-trait">'
            . '<span class="pcp-up-cati-label">TOT</span>'
            . '<span class="pcp-up-cati-bar">'
            . '<span class="pcp-up-cati-fill" style="width:' . number_format( $pct, 1 ) . '%"></span>'
            . '<span class="pcp-up-cati-cutoff-mark" style="left:' . number_format( $cutoffPct, 2 ) . '%" title="OCI-R screening cutoff: 21"></span>'
            . '</span>'
            . '<span class="pcp-up-cati-val">' . htmlspecialchars( $disp ) . ' / 72</span>'
            . '</span>'
            . '</div>';

        // 6-axis subscale radar (shared with the OCI-PCP report).
        $headline .= '<div class="pcp-up-cati-radar-wrap">' . $Ocipcp::radarSvg( $s ) . '</div>';

        // Caption: screening verdict + which subscales reach the concern level.
        $elevated = [];
        foreach ( $Ocipcp::SUBSCALES as $code => $def ) {
            $v = $s[ 'subscale_' . $code ] ?? null;
            if ( $v !== null && (float)$v >= $concern ) { $elevated[] = $def['label']; }
        }
        $above = $total >= $cutoff;
        $level = $above ? 'At or above the OCD screening cutoff' : 'Below the OCD screening cutoff';
        $detail = $elevated
            ? count( $elevated ) . ' subscale' . ( count( $elevated ) === 1 ? '' : 's' )
                . ' at the concern level (' . htmlspecialchars( implode( ', ', $elevated ) ) . ')'
            : 'No subscale at the concern level';
        $headline .= '<div class="pcp-up-cati-caption">'
            . '<span class="pcp-up-' . ( $above ? 'above' : 'below' ) . '">' . htmlspecialchars( $level ) . '.</span> '
            . '<span class="pcp-up-cati-caption-hint">' . $detail . '. Dashed ring = subscale concern level.</span>'
            . '</div>';

        $sub = $above
            ? '<span class="pcp-up-above">at or above screening cutoff (&ge; 21)</span>'
            : '<span class="pcp-up-below">below screening cutoff (&ge; 21)</span>';

        return [ 'headline' => $headline, 'sub' => $sub ];
    }

    private function catiHeadline( array $s, ?string $genderKey = null ): array {
        // CATI: 6 subscales scored 7..35; Total 42..210. Per-subscale cutoffs
        // do not exist as published thresholds; instead, the rich report
        // calls a subscale "Consistent with autism" when the score reaches
        // the 75th percentile of the non-autistic gender-matched sample.
        // The radar visualises that: dashed-white irregular polygon at each
        // axis's NA-75th, with subscales poking past it highlighted.
        if ( !isset( $s['total'] ) || $s['total'] === null ) {
            return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        }
        $CatiNorms = \MediaWiki\Extension\Pharmacopedia\Assessments\CatiNorms::class;
        $total = (float)$s['total'];
        $pct = max( 0.0, min( 100.0, ( ( $total - 42 ) / ( 210 - 42 ) ) * 100.0 ) );
        $cutoffPct = ( ( 134 - 42 ) / ( 210 - 42 ) ) * 100.0;
        $disp = number_format( $total, $total == (int)$total ? 0 : 1 );

        // TOT bar (kept as before; 2021 overall cutoff = 134).
        $headline = '<div class="pcp-up-cati">'
            . '<span class="pcp-up-cati-trait">'
            . '<span class="pcp-up-cati-label">TOT</span>'
            . '<span class="pcp-up-cati-bar">'
                . '<span class="pcp-up-cati-fill" style="width:' . number_format( $pct, 1 ) . '%"></span>'
                . '<span class="pcp-up-cati-cutoff-mark" style="left:' . number_format( $cutoffPct, 2 ) . '%" title="2021 overall cutoff: 134"></span>'
            . '</span>'
            . '<span class="pcp-up-cati-val">' . htmlspecialchars( $disp ) . '</span>'
            . '</span>'
            . '</div>';

        // 6 axes, clockwise from top. Each entry:
        //   [ scoreKey, normSubKey, mathAngleDeg, shortLabel, fullLabel, labelX, labelY ]
        $axes = [
            [ 'subscale_SOC', 'soc',  90,  'Social',     'Social Interactions',          100, 29  ],
            [ 'subscale_COM', 'com',  30,  'Comms',      'Communication',                170, 63  ],
            [ 'subscale_CAM', 'cam', -30,  'Camouflage', 'Social Camouflage',            170, 142 ],
            [ 'subscale_FLX', 'flx', -90,  'Inflex.',    'Cognitive (In)Flexibility',    100, 174 ],
            [ 'subscale_REG', 'reg', -150, 'Self-reg',   'Self-regulatory Behaviours',    30, 142 ],
            [ 'subscale_SEN', 'sen', 150,  'Sensory',    'Sensory Sensitivity',           30, 63  ],
        ];

        // NA-75th cutoff per axis (gender-matched). Lookup CatiNorms percentile
        // table directly: NORMS['na_<g>']['percentiles']['<sub>'][75] is the raw
        // score at the 75th NA percentile.
        $g = $genderKey ?: 'all';
        $naGroup = 'na_' . $g;
        $cutoffs = [];
        foreach ( $axes as [ , $normKey, , , , , ] ) {
            $val = $CatiNorms::NORMS[ $naGroup ]['percentiles'][ $normKey ][75] ?? null;
            $cutoffs[ $normKey ] = $val !== null ? (float)$val : null;
        }

        $headline .= '<div class="pcp-up-cati-radar-wrap">'
            . $this->catiRadarSvg( $s, $axes, $cutoffs )
            . '</div>';

        // Compute the "consistent with autism" list: subscales above NA-75th.
        $consistent = [];
        foreach ( $axes as [ $k, $normKey, , $shortLabel, , , ] ) {
            if ( !isset( $s[ $k ] ) || $s[ $k ] === null ) continue;
            $cutoff = $cutoffs[ $normKey ];
            if ( $cutoff !== null && (float)$s[ $k ] >= $cutoff ) {
                $consistent[] = $shortLabel;
            }
        }

        $genderLabel = [
            'male'   => 'cis male',
            'female' => 'cis female',
            'gd'     => 'gender-diverse',
            'all'    => 'all-adults',
        ][ $g ] ?? 'all-adults';

        $headline .= $this->catiPatternCaption( $consistent, $genderLabel, $total );

        // Sub: tot cutoff chip (same as before).
        $above = $total >= 134;
        $sub = $above
            ? '<span class="pcp-up-above">above 2021 overall cutoff (&ge; 134)</span>'
            : '<span class="pcp-up-below">below 2021 overall cutoff (&ge; 134)</span>';

        return [ 'headline' => $headline, 'sub' => $sub ];
    }

    /**
     * 6-axis hexagonal radar SVG for the CATI subscales.
     * @param array $s        score array (subscale_SOC etc.)
     * @param array $axes     list of [ scoreKey, normKey, angleDeg, short, full, labelX, labelY ]
     * @param array $cutoffs  map normKey -> raw cutoff at NA-75th percentile (or null)
     */
    private function catiRadarSvg( array $s, array $axes, array $cutoffs ): string {
        $cx = 100; $cy = 100; $maxR = 58;
        // Scores normalize from raw 7..35 to 0..1 (subtract 7, divide by 28).
        $rawLo = 7; $rawHi = 35; $span = $rawHi - $rawLo;

        $vertexAtRaw = function ( float $raw, float $deg ) use ( $cx, $cy, $maxR, $rawLo, $span ) {
            $norm = max( 0.0, min( 1.0, ( $raw - $rawLo ) / $span ) );
            $r = $maxR * $norm;
            $rad = deg2rad( $deg );
            return [
                $cx + $r * cos( $rad ),
                $cy - $r * sin( $rad ),
            ];
        };

        $hexAtRadius = function ( float $r ) use ( $axes, $cx, $cy ) {
            $pts = [];
            foreach ( $axes as [ , , $deg, , , , ] ) {
                $rad = deg2rad( $deg );
                $x = $cx + $r * cos( $rad );
                $y = $cy - $r * sin( $rad );
                $pts[] = number_format( $x, 2 ) . ',' . number_format( $y, 2 );
            }
            return implode( ' ', $pts );
        };

        // Background rings at 1/3 and 3/3 of radius (mirror PID-5-BF style).
        $ringInner = '<polygon class="ring" points="' . $hexAtRadius( $maxR / 3.0 ) . '"/>';
        $ringOuter = '<polygon class="ring" points="' . $hexAtRadius( $maxR )         . '"/>';

        // Irregular NA-75th cutoff polygon. Skip if any subscale's cutoff is missing.
        $cutoffPoly = '';
        $cutoffPts = [];
        foreach ( $axes as [ , $normKey, $deg, , , , ] ) {
            $c = $cutoffs[ $normKey ];
            if ( $c === null ) { $cutoffPts = []; break; }
            [ $x, $y ] = $vertexAtRaw( (float)$c, $deg );
            $cutoffPts[] = number_format( $x, 2 ) . ',' . number_format( $y, 2 );
        }
        if ( $cutoffPts ) {
            $cutoffPoly = '<polygon class="ring-cutoff" points="' . implode( ' ', $cutoffPts ) . '"/>';
        }

        // Axes.
        $lines = '';
        foreach ( $axes as [ , , $deg, , , , ] ) {
            $rad = deg2rad( $deg );
            $x = $cx + $maxR * cos( $rad );
            $y = $cy - $maxR * sin( $rad );
            $lines .= '<line class="axis" x1="' . $cx . '" y1="' . $cy . '" x2="' . number_format( $x, 2 ) . '" y2="' . number_format( $y, 2 ) . '"/>';
        }

        // Scale ticks on top axis at raw 16, 26, 35 (visual landmarks).
        $tickRaws = [ 16, 26, 35 ];
        $ticks = '';
        foreach ( $tickRaws as $tr ) {
            [ , $ty ] = $vertexAtRaw( (float)$tr, 90 );
            $ticks .= '<text class="tick-label" x="102" y="' . number_format( $ty + 2, 1 ) . '">' . $tr . '</text>';
        }

        // Data polygon + dots.
        $dataPts = [];
        $dots = '';
        foreach ( $axes as [ $key, $normKey, $deg, , , , ] ) {
            $v = isset( $s[ $key ] ) && $s[ $key ] !== null ? (float)$s[ $key ] : (float)$rawLo;
            [ $x, $y ] = $vertexAtRaw( $v, $deg );
            $dataPts[] = number_format( $x, 2 ) . ',' . number_format( $y, 2 );
            $cutoff = $cutoffs[ $normKey ];
            $isElev = ( $cutoff !== null && $v >= $cutoff );
            $cls = 'dot' . ( $isElev ? ' is-elev' : '' );
            $rdot = $isElev ? 3.5 : 2.6;
            $dots .= '<circle class="' . $cls . '" cx="' . number_format( $x, 2 ) . '" cy="' . number_format( $y, 2 ) . '" r="' . $rdot . '"/>';
        }
        $dataPoly = '<polygon class="data" points="' . implode( ' ', $dataPts ) . '"/>';

        // Labels.
        $labels = '';
        foreach ( $axes as [ $key, $normKey, , $shortLabel, $fullLabel, $lx, $ly ] ) {
            $v = isset( $s[ $key ] ) && $s[ $key ] !== null ? (float)$s[ $key ] : (float)$rawLo;
            $cutoff = $cutoffs[ $normKey ];
            $isElev = ( $cutoff !== null && $v >= $cutoff );
            $cls = 'label' . ( $isElev ? ' is-elev' : '' );
            $labels .= '<text class="' . $cls . '" x="' . $lx . '" y="' . $ly . '">'
                . '<title>' . htmlspecialchars( $fullLabel ) . '</title>'
                . htmlspecialchars( $shortLabel )
                . '</text>';
        }

        return '<svg class="pcp-up-cati-radar" viewBox="0 0 200 200">'
            . $ringInner . $ringOuter . $cutoffPoly
            . '<g>' . $lines . '</g>'
            . $ticks
            . $dataPoly
            . $dots
            . $labels
            . '</svg>';
    }

    /**
     * Caption under the CATI radar: qualitative level (from Total) on top,
     * count + list of subscales above NA-75th as detail, gender-matched
     * percentile note as hint. Mirrors the CAT-Q card's C1-style structure.
     */
    private function catiPatternCaption( array $consistent, string $genderLabel, ?float $total = null ): string {
        $hint = '<span class="pcp-up-cati-caption-hint">dashed ring = 75th non-autistic percentile (' . htmlspecialchars( $genderLabel ) . ')</span>';
        $cnt = count( $consistent );

        // Qualitative level from Total (range 42-210; 2021 overall cutoff = 134).
        $level = 'Autistic-trait profile';
        if ( $total !== null ) {
            if      ( $total >= 160 ) $level = 'Strong autistic-trait profile';
            elseif  ( $total >= 134 ) $level = 'Elevated autistic-trait profile';
            elseif  ( $total >= 100 ) $level = 'Moderate autistic-trait profile';
            else                      $level = 'Sub-threshold autistic-trait profile';
        }

        if ( $cnt === 0 ) {
            $detail = 'no subscales consistent with autism';
        } elseif ( $cnt === 6 ) {
            $detail = 'all 6 subscales consistent with autism';
        } else {
            $joined = implode( ' &middot; ', array_map( 'htmlspecialchars', $consistent ) );
            $detail = $cnt . ' of 6 subscales consistent with autism (' . $joined . ')';
        }

        return '<p class="pcp-up-cati-caption">'
            . '<span class="pcp-up-cati-caption-pattern">' . htmlspecialchars( $level ) . '</span><br>'
            . '<span class="pcp-up-cati-caption-list">' . $detail . '</span>'
            . $hint
            . '</p>';
    }

        /**
     * Shared OCEAN-bar-style headline builder used by CAT-Q, NFCS, WHOQOL.
     * Renders one Total bar with a white cutoff marker + a "Highest: A · B"
     * (or "Lowest: A · B" for inverted tests) line + a cutoff chip.
     *
     * @param array $s           Score array (must include 'total').
     * @param int   $totalLo     Minimum possible total.
     * @param int   $totalHi     Maximum possible total.
     * @param int   $cutoff      Suggestive cutoff value on the total scale.
     * @param array $subscales   List of [ scoreKey, shortLabel, subLo, subHi ].
     *                           Ranked by normalized (raw-min)/(max-min).
     * @param int   $topN        Number of subscales to mention (usually 2).
     * @param bool  $invert      If true, label is "Lowest" and the lowest-N picked
     *                           (used for WHOQOL where higher = better).
     * @param string $cutoffLabel Human label for cutoff chip ('suggestive cutoff',
     *                            'midpoint heuristic', etc.).
     * @return array { headline, sub }
     */
    private function bargaugeHeadline(
        array $s,
        int $totalLo,
        int $totalHi,
        int $cutoff,
        array $subscales,
        int $topN = 2,
        bool $invert = false,
        string $cutoffLabel = 'suggestive cutoff'
    ): array {
        if ( !isset( $s['total'] ) || $s['total'] === null ) {
            return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        }
        $total = (float)$s['total'];
        $pct = max( 0.0, min( 100.0, ( ( $total - $totalLo ) / ( $totalHi - $totalLo ) ) * 100.0 ) );
        $disp = number_format( $total, $total == (int)$total ? 0 : 1 );
        $cutoffPct = max( 0.0, min( 100.0, ( ( $cutoff - $totalLo ) / ( $totalHi - $totalLo ) ) * 100.0 ) );

        $headline = '<div class="pcp-up-cati">'
            . '<span class="pcp-up-cati-trait">'
            . '<span class="pcp-up-cati-label">TOT</span>'
            . '<span class="pcp-up-cati-bar">'
                . '<span class="pcp-up-cati-fill" style="width:' . number_format( $pct, 1 ) . '%"></span>'
                . '<span class="pcp-up-cati-cutoff-mark" style="left:' . number_format( $cutoffPct, 2 ) . '%" title="' . htmlspecialchars( ucfirst( $cutoffLabel ) ) . ': ' . $cutoff . '"></span>'
            . '</span>'
            . '<span class="pcp-up-cati-val">' . htmlspecialchars( $disp ) . '</span>'
            . '</span>'
            . '</div>';

        // Rank subscales by normalized score so unequal ranges don't skew.
        $ranked = [];
        foreach ( $subscales as [ $key, $label, $lo, $hi ] ) {
            if ( !isset( $s[ $key ] ) || $s[ $key ] === null ) continue;
            $v = (float)$s[ $key ];
            $denom = max( 0.0001, $hi - $lo );
            $ranked[ $label ] = ( $v - $lo ) / $denom;
        }
        if ( $invert ) {
            asort( $ranked );  // ascending: lowest first
        } else {
            arsort( $ranked ); // descending: highest first
        }
        $picked = array_slice( array_keys( $ranked ), 0, $topN );

        $subParts = [];
        if ( $picked ) {
            $verb = $invert ? 'Lowest' : 'Highest';
            $names = array_map( 'htmlspecialchars', $picked );
            $joined = count( $names ) >= 2 ? ( $names[0] . ' and ' . $names[1] ) : $names[0];
            $subParts[] = '<span class="pcp-up-cati-highest">' . $verb . ' in ' . $joined . '</span>';
        }
        $above = $total >= $cutoff;
        if ( $invert ) {
            // WHOQOL: above midpoint is good, below is concerning.
            $subParts[] = $above
                ? '<span class="pcp-up-above">at or above midpoint (&ge; ' . $cutoff . ')</span>'
                : '<span class="pcp-up-below">below midpoint (&lt; ' . $cutoff . ')</span>';
        } else {
            $subParts[] = $above
                ? '<span class="pcp-up-above">above ' . htmlspecialchars( $cutoffLabel ) . ' (&ge; ' . $cutoff . ')</span>'
                : '<span class="pcp-up-below">below ' . htmlspecialchars( $cutoffLabel ) . ' (&ge; ' . $cutoff . ')</span>';
        }

        return [
            'headline' => $headline,
            'sub'      => implode( '<br>', $subParts ),
        ];
    }

    private function catqHeadline( array $s ): array {
        // CAT-Q: Camouflaging Autistic Traits Questionnaire. Total 25-175,
        // NeurodivUrgent suggestive cutoff 110. 3 subscales with unequal item
        // counts: CO/MSK 8-56, ASS 9-63. Per-subscale cutoffs CO >= 35,
        // ASS >= 40; MSK has NO published cutoff (Hull 2019 found it the
        // least discriminating factor).
        //
        // Card: TOT bar + triangle radar (V1.A from demo: closed dashed
        // cutoff polygon with MSK at outer ring + corner dots; MSK corner
        // is a hollow ring). Caption summarises overall level + dominant
        // camouflaging strategy.
        if ( !isset( $s['total'] ) || $s['total'] === null ) {
            return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        }
        $total = (float)$s['total'];
        $pct       = max( 0.0, min( 100.0, ( ( $total - 25 ) / ( 175 - 25 ) ) * 100.0 ) );
        $cutoffPct = ( ( 110 - 25 ) / ( 175 - 25 ) ) * 100.0;
        $disp = number_format( $total, $total == (int)$total ? 0 : 1 );

        $headline  = '<div class="pcp-up-cati">'
            . '<span class="pcp-up-cati-trait">'
            . '<span class="pcp-up-cati-label">TOT</span>'
            . '<span class="pcp-up-cati-bar">'
                . '<span class="pcp-up-cati-fill" style="width:' . number_format( $pct, 2 ) . '%"></span>'
                . '<span class="pcp-up-cati-cutoff-mark" style="left:' . number_format( $cutoffPct, 2 ) . '%" title="NeurodivUrgent cutoff: 110 of 175"></span>'
            . '</span>'
            . '<span class="pcp-up-cati-val">' . htmlspecialchars( $disp ) . '</span>'
            . '</span>'
            . '</div>';

        // 3 axes, clockwise from top.
        //   [ scoreKey, angleDeg, label, rawLo, rawHi, cutoff (null if none),
        //     labelX, labelY ]
        $axes = [
            [ 'subscale_CO',  90,   'Compensation', 8, 56, 35,   100, 29  ],
            [ 'subscale_MSK', -30,  'Masking',      8, 56, null, 165, 145 ],
            [ 'subscale_ASS', -150, 'Assimilation', 9, 63, 40,    35, 145 ],
        ];

        $headline .= '<div class="pcp-up-catq-radar-wrap">'
            . $this->catqRadarSvg( $s, $axes )
            . '</div>';
        $headline .= $this->catqCaptionC1( $s, $total );

        // Sub: TOT cutoff chip.
        $above = $total >= 110;
        $sub = $above
            ? '<span class="pcp-up-above">above NeurodivUrgent cutoff (&ge; 110)</span>'
            : '<span class="pcp-up-below">below NeurodivUrgent cutoff (&ge; 110)</span>';

        return [ 'headline' => $headline, 'sub' => $sub ];
    }

    /**
     * Triangle radar SVG for CAT-Q.
     * Closed dashed-white cutoff polygon with MSK pinned at outer ring
     * (no cutoff). Cutoff corner dots: solid for CO/ASS, hollow for MSK.
     *
     * @param array $s     score array (subscale_CO, _MSK, _ASS)
     * @param array $axes  list of [ scoreKey, angleDeg, label, rawLo, rawHi, cutoff|null, labelX, labelY ]
     */
    private function catqRadarSvg( array $s, array $axes ): string {
        $cx = 100; $cy = 100; $maxR = 58;

        $vertexAtRaw = function ( float $raw, float $lo, float $hi, float $deg ) use ( $cx, $cy, $maxR ) {
            $norm = max( 0.0, min( 1.0, ( $raw - $lo ) / max( 0.0001, $hi - $lo ) ) );
            $r = $maxR * $norm;
            $rad = deg2rad( $deg );
            return [
                $cx + $r * cos( $rad ),
                $cy - $r * sin( $rad ),
            ];
        };

        $polyAtRatio = function ( float $ratio ) use ( $axes, $cx, $cy, $maxR ) {
            $r = $maxR * $ratio;
            $pts = [];
            foreach ( $axes as [ , $deg, , , , , , ] ) {
                $rad = deg2rad( $deg );
                $x = $cx + $r * cos( $rad );
                $y = $cy - $r * sin( $rad );
                $pts[] = number_format( $x, 2 ) . ',' . number_format( $y, 2 );
            }
            return implode( ' ', $pts );
        };

        // Background rings at 33%, 67%, 100%.
        $rings = '<polygon class="ring-mid" points="' . $polyAtRatio( 1.0 / 3.0 ) . '"/>'
               . '<polygon class="ring-mid" points="' . $polyAtRatio( 2.0 / 3.0 ) . '"/>'
               . '<polygon class="ring"     points="' . $polyAtRatio( 1.0 )         . '"/>';

        // Axes: regular for those with a cutoff, dotted-grey for "no cutoff" axes.
        $axesSvg = '';
        foreach ( $axes as [ , $deg, , , , $cutoff, , , ] ) {
            $rad = deg2rad( $deg );
            $x = $cx + $maxR * cos( $rad );
            $y = $cy - $maxR * sin( $rad );
            $cls = $cutoff === null ? 'axis-nocutoff' : 'axis';
            $axesSvg .= '<line class="' . $cls . '" x1="' . $cx . '" y1="' . $cy . '" x2="' . number_format( $x, 2 ) . '" y2="' . number_format( $y, 2 ) . '"/>';
        }

        // Cutoff polygon: vertex at cutoff radius if defined, else at outer ring (maxR).
        $cutPts = [];
        foreach ( $axes as [ , $deg, , $lo, $hi, $cutoff, , , ] ) {
            if ( $cutoff === null ) {
                // Pin at outer ring.
                $rad = deg2rad( $deg );
                $x = $cx + $maxR * cos( $rad );
                $y = $cy - $maxR * sin( $rad );
            } else {
                [ $x, $y ] = $vertexAtRaw( (float)$cutoff, (float)$lo, (float)$hi, (float)$deg );
            }
            $cutPts[] = number_format( $x, 2 ) . ',' . number_format( $y, 2 );
        }
        $cutoffPoly = '<polygon class="cutoff-poly" points="' . implode( ' ', $cutPts ) . '"/>';

        // Cutoff corner dots: solid for axes with a cutoff, hollow for no-cutoff.
        $corners = '';
        foreach ( $axes as $i => [ , $deg, , $lo, $hi, $cutoff, , , ] ) {
            [ $x, $y ] = explode( ',', $cutPts[ $i ] );
            if ( $cutoff === null ) {
                $corners .= '<circle class="cutoff-corner-hollow" cx="' . $x . '" cy="' . $y . '" r="3.2"/>';
            } else {
                $corners .= '<circle class="cutoff-corner-solid" cx="' . $x . '" cy="' . $y . '" r="2.6"/>';
            }
        }

        // User data polygon + dots.
        $dataPts = [];
        $dots = '';
        foreach ( $axes as [ $key, $deg, , $lo, $hi, $cutoff, , , ] ) {
            $v = isset( $s[ $key ] ) && $s[ $key ] !== null ? (float)$s[ $key ] : (float)$lo;
            [ $x, $y ] = $vertexAtRaw( $v, (float)$lo, (float)$hi, (float)$deg );
            $dataPts[] = number_format( $x, 2 ) . ',' . number_format( $y, 2 );
            $isElev = ( $cutoff !== null && $v >= $cutoff );
            $cls = 'dot' . ( $isElev ? ' is-elev' : '' );
            $rdot = $isElev ? 3.5 : 2.6;
            $dots .= '<circle class="' . $cls . '" cx="' . number_format( $x, 2 ) . '" cy="' . number_format( $y, 2 ) . '" r="' . $rdot . '"/>';
        }
        $dataPoly = '<polygon class="data" points="' . implode( ' ', $dataPts ) . '"/>';

        // Labels (elevated = above cutoff; muted-italic for no-cutoff axis).
        $labels = '';
        foreach ( $axes as [ $key, , $label, $lo, $hi, $cutoff, $lx, $ly ] ) {
            $v = isset( $s[ $key ] ) && $s[ $key ] !== null ? (float)$s[ $key ] : (float)$lo;
            if ( $cutoff === null ) {
                $cls = 'label is-nocutoff';
            } elseif ( $v >= $cutoff ) {
                $cls = 'label is-elev';
            } else {
                $cls = 'label';
            }
            $labels .= '<text class="' . $cls . '" x="' . $lx . '" y="' . $ly . '">' . htmlspecialchars( $label ) . '</text>';
        }

        return '<svg class="pcp-up-catq-radar" viewBox="0 0 200 200">'
            . $rings
            . $cutoffPoly
            . $axesSvg
            . $corners
            . $dataPoly
            . $dots
            . $labels
            . '</svg>';
    }

    /**
     * Caption C1: "[Level] camouflaging / primary strategy: X / Total ... reference".
     * Level derived from Total; dominant strategy from normalized subscale scores.
     */
    private function catqCaptionC1( array $s, float $total ): string {
        // Level thresholds (Total range 25-175; NeurodivUrgent cutoff 110).
        if      ( $total >= 140 ) $level = 'High';
        elseif  ( $total >= 110 ) $level = 'Moderate-high';
        elseif  ( $total >=  75 ) $level = 'Moderate';
        else                      $level = 'Low';

        // Dominant strategy by normalized subscale score.
        $subs = [
            'subscale_CO'  => [ 'Compensation', 8, 56 ],
            'subscale_MSK' => [ 'Masking',      8, 56 ],
            'subscale_ASS' => [ 'Assimilation', 9, 63 ],
        ];
        $norm = [];
        foreach ( $subs as $k => [ $lab, $lo, $hi ] ) {
            if ( !isset( $s[ $k ] ) || $s[ $k ] === null ) continue;
            $norm[ $lab ] = ( (float)$s[ $k ] - $lo ) / max( 0.0001, $hi - $lo );
        }
        arsort( $norm );
        $top = array_keys( $norm )[0] ?? null;

        // Hint: reference comparison on Total.
        $totalStr = number_format( $total, $total == (int)$total ? 0 : 1 );
        if      ( $total >= 110 ) $hint = 'Total ' . $totalStr . ' above 110 reference';
        else                      $hint = 'Total ' . $totalStr . ' of 175';

        $out = '<p class="pcp-up-catq-caption">'
             . '<span class="pcp-up-catq-caption-headline">' . htmlspecialchars( $level ) . ' camouflaging</span><br>'
             . ( $top !== null ? '<span class="pcp-up-catq-caption-detail">primary strategy: ' . htmlspecialchars( $top ) . '</span>' : '' )
             . '<span class="pcp-up-catq-caption-hint">' . htmlspecialchars( $hint ) . '</span>'
             . '</p>';
        return $out;
    }

    private function nfcsHeadline( array $s ): array {
        // NFCS-brief: 15-item Need for Closure. Total 15-90. 70 = midpoint heuristic.
        // Card design: Total bar + 5-axis radar/spider chart (profile shape) + caption.
        if ( !isset( $s['total'] ) || $s['total'] === null ) {
            return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        }
        $total = (float)$s['total'];
        $pct = max( 0.0, min( 100.0, ( ( $total - 15 ) / ( 90 - 15 ) ) * 100.0 ) );
        $disp = number_format( $total, $total == (int)$total ? 0 : 1 );
        $cutoffPct = max( 0.0, min( 100.0, ( ( 70 - 15 ) / ( 90 - 15 ) ) * 100.0 ) );

        $headline  = '<div class="pcp-up-cati">'
            . '<span class="pcp-up-cati-trait">'
            . '<span class="pcp-up-cati-label">TOT</span>'
            . '<span class="pcp-up-cati-bar">'
                . '<span class="pcp-up-cati-fill" style="width:' . number_format( $pct, 1 ) . '%"></span>'
                . '<span class="pcp-up-cati-cutoff-mark" style="left:' . number_format( $cutoffPct, 2 ) . '%" title="Midpoint heuristic: 70"></span>'
            . '</span>'
            . '<span class="pcp-up-cati-val">' . htmlspecialchars( $disp ) . '</span>'
            . '</span>'
            . '</div>';
        $headline .= '<div class="pcp-up-nfcs-radar">' . $this->nfcsRadarSvg( $s ) . '</div>';
        // Compute top-2 highest facets by normalized score (each facet 3-18).
        $nfcsSubs = [
            'subscale_DEC' => 'Decisiveness',
            'subscale_ORD' => 'Order',
            'subscale_PRD' => 'Predictability',
            'subscale_AMB' => 'Ambiguity intolerance',
            'subscale_CLM' => 'Closed-mindedness',
        ];
        $nfcsRanked = [];
        foreach ( $nfcsSubs as $k => $lab ) {
            if ( isset( $s[ $k ] ) && $s[ $k ] !== null ) {
                $nfcsRanked[ $lab ] = ( (float)$s[ $k ] - 3 ) / 15.0;
            }
        }
        arsort( $nfcsRanked );
        $nfcsTop2 = array_slice( array_keys( $nfcsRanked ), 0, 2 );
        if ( $nfcsTop2 ) {
            $joinedNfcs = count( $nfcsTop2 ) >= 2 ? ( $nfcsTop2[0] . ' and ' . $nfcsTop2[1] ) : $nfcsTop2[0];
            $headline .= '<p class="pcp-up-nfcs-caption"><span class="pcp-up-cati-highest">Highest in ' . htmlspecialchars( $joinedNfcs ) . '</span></p>';
        }

        $above = $total >= 70;
        $sub = $above
            ? '<span class="pcp-up-above">above midpoint heuristic (&ge; 70)</span>'
            : '<span class="pcp-up-below">below midpoint heuristic (&ge; 70)</span>';

        return [ 'headline' => $headline, 'sub' => $sub ];
    }

    /** Build the 5-axis radar SVG for the NFCS subscales. Each axis 3..18, normalized to 0..1. */
    private function nfcsRadarSvg( array $s ): string {
        // Clockwise from top.
        $axes = [
            // [ score-key, angle (math convention; 90=top), label, labelX, labelY ]
            [ 'subscale_DEC', 90,   'Decisive', 100, 18 ],
            [ 'subscale_ORD', 18,   'Order',    188, 78 ],
            [ 'subscale_PRD', -54,  'Predict.', 155, 174 ],
            [ 'subscale_AMB', -126, 'Ambig.',    45, 174 ],
            [ 'subscale_CLM', 162,  'Closed',    12, 78 ],
        ];
        $cx = 100; $cy = 100; $maxR = 70;

        // Concentric pentagons (background rings).
        $rings = '';
        foreach ( [ [ 70, 0.50 ], [ 47, 0.40 ], [ 23, 0.30 ] ] as [ $rr, $op ] ) {
            $pts = [];
            foreach ( $axes as [ , $deg, , , ] ) {
                $rad = deg2rad( $deg );
                $x = $cx + $rr * cos( $rad );
                $y = $cy - $rr * sin( $rad );
                $pts[] = number_format( $x, 1 ) . ',' . number_format( $y, 1 );
            }
            $rings .= '<polygon points="' . implode( ' ', $pts ) . '" opacity="' . $op . '"/>';
        }

        // Axis lines.
        $lines = '';
        foreach ( $axes as [ , $deg, , , ] ) {
            $rad = deg2rad( $deg );
            $x = $cx + $maxR * cos( $rad );
            $y = $cy - $maxR * sin( $rad );
            $lines .= '<line x1="' . $cx . '" y1="' . $cy . '" x2="' . number_format( $x, 1 ) . '" y2="' . number_format( $y, 1 ) . '"/>';
        }

        // Data polygon + dots.
        $dataPts = [];
        $dots = '';
        foreach ( $axes as [ $key, $deg, , , ] ) {
            $v = isset( $s[ $key ] ) && $s[ $key ] !== null ? (float)$s[ $key ] : 3.0;
            $norm = max( 0.0, min( 1.0, ( $v - 3 ) / ( 18 - 3 ) ) );
            $r = $maxR * $norm;
            $rad = deg2rad( $deg );
            $x = $cx + $r * cos( $rad );
            $y = $cy - $r * sin( $rad );
            $dataPts[] = number_format( $x, 1 ) . ',' . number_format( $y, 1 );
            $dots .= '<circle cx="' . number_format( $x, 1 ) . '" cy="' . number_format( $y, 1 ) . '" r="3"/>';
        }
        $dataPoly = '<polygon points="' . implode( ' ', $dataPts ) . '" fill="rgba(124,58,237,0.4)" stroke="#c4b5fd" stroke-width="1.5" stroke-linejoin="round"/>';

        // Labels.
        $labels = '';
        foreach ( $axes as [ , , $lbl, $lx, $ly ] ) {
            $labels .= '<text x="' . $lx . '" y="' . $ly . '">' . htmlspecialchars( $lbl ) . '</text>';
        }

        return '<svg viewBox="0 0 200 200" width="180" height="180" style="overflow:visible; display:block;">'
            . '<g stroke="#333" fill="none" stroke-width="0.6">' . $rings . '</g>'
            . '<g stroke="#444" stroke-width="0.5">' . $lines . '</g>'
            . $dataPoly
            . '<g fill="#ede9fe" stroke="#fff" stroke-width="1">' . $dots . '</g>'
            . '<g fill="#aaa" font-size="9" text-anchor="middle">' . $labels . '</g>'
            . '</svg>';
    }

    private function whoqolbrefHeadline( array $s ): array {
        // WHOQOL-BREF: 4 domains rescaled to 0-100. Higher = better QoL.
        // Card design (Option 1): Total bar + 4 ranked domain bars (ascending,
        // since lowest = most impacted). The 2 lowest domains get bright/medium
        // color tiers; the others are dim. Legend strip at bottom. OVR excluded.
        if ( !isset( $s['total'] ) || $s['total'] === null ) {
            return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        }
        $total = (float)$s['total'];
        $pct = max( 0.0, min( 100.0, $total ) );
        $disp = number_format( $total, $total == (int)$total ? 0 : 1 );

        // Total bar with midpoint marker at 50.
        $headline = '<div class="pcp-up-cati">'
            . '<span class="pcp-up-cati-trait">'
            . '<span class="pcp-up-cati-label">TOT</span>'
            . '<span class="pcp-up-cati-bar">'
                . '<span class="pcp-up-cati-fill" style="width:' . number_format( $pct, 1 ) . '%"></span>'
                . '<span class="pcp-up-cati-cutoff-mark" style="left:50%" title="Midpoint: 50"></span>'
            . '</span>'
            . '<span class="pcp-up-cati-val">' . htmlspecialchars( $disp ) . '</span>'
            . '</span>'
            . '</div>';

        // Domain rows, ranked ascending (lowest first = most impacted).
        $domains = [
            'subscale_PHY' => 'Physical',
            'subscale_PSY' => 'Psychological',
            'subscale_SOC' => 'Social',
            'subscale_ENV' => 'Environment',
        ];
        $vals = [];
        foreach ( $domains as $k => $lab ) {
            if ( isset( $s[ $k ] ) && $s[ $k ] !== null ) {
                $vals[ $lab ] = (float)$s[ $k ];
            }
        }
        asort( $vals );

        $rows = '';
        $i = 0;
        foreach ( $vals as $lab => $v ) {
            $cls = 'whoqol-row';
            if ( $i === 0 )      { $cls .= ' is-lowest'; }
            elseif ( $i === 1 )  { $cls .= ' is-second'; }
            $w = max( 0.0, min( 100.0, $v ) );
            $valDisp = number_format( $v, $v == (int)$v ? 0 : 1 );
            $rows .= '<div class="' . $cls . '">'
                . '<span class="whoqol-name">' . htmlspecialchars( $lab ) . '</span>'
                . '<span class="pcp-up-cati-bar"><span class="pcp-up-cati-fill" style="width:' . number_format( $w, 1 ) . '%"></span></span>'
                . '<span class="whoqol-val">' . htmlspecialchars( $valDisp ) . '</span>'
                . '</div>';
            $i++;
        }
        if ( $rows !== '' ) {
            $headline .= '<div class="whoqol-rows">' . $rows . '</div>';
            $headline .= '<div class="whoqol-legend">'
                . '<span><span class="legend-swatch is-lowest"></span> Most impacted</span>'
                . '<span><span class="legend-swatch is-second"></span> Second</span>'
                . '</div>';
        }

        $above = $total >= 50;
        $sub = $above
            ? '<span class="pcp-up-above">at or above midpoint (&ge; 50)</span>'
            : '<span class="pcp-up-below">below midpoint (&lt; 50)</span>';

        return [ 'headline' => $headline, 'sub' => $sub ];
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

    private function bpnsHeadline( array $s ): array {
        // BPNS: Basic Psychological Need Satisfaction. 3 subscales (Autonomy,
        // Competence, Relatedness), each 1-7 mean. Higher = better.
        // Reference bands: <= 4.0 = unmet need, >= 5.5 = high satisfaction.
        //
        // Card: TOT bar (with cutoff marker at the 4.0 floor) + triangle
        // radar (V1: single dashed-white low-threshold ring; subscales
        // inside the ring are unmet, drawn with violet+glow) + C3
        // phenomenological caption ("Feeling X and Y / but less Z").
        if ( !isset( $s['total'] ) || $s['total'] === null ) {
            return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        }
        $total = (float)$s['total'];
        // TOT bar normalised to 1..7 range.
        $pct       = max( 0.0, min( 100.0, ( ( $total - 1 ) / 6.0 ) * 100.0 ) );
        $cutoffPct = ( ( 4.0 - 1 ) / 6.0 ) * 100.0;  // 50%
        $disp = number_format( $total, 2 );

        $headline  = '<div class="pcp-up-cati">'
            . '<span class="pcp-up-cati-trait">'
            . '<span class="pcp-up-cati-label">TOT</span>'
            . '<span class="pcp-up-cati-bar">'
                . '<span class="pcp-up-cati-fill" style="width:' . number_format( $pct, 2 ) . '%"></span>'
                . '<span class="pcp-up-cati-cutoff-mark" style="left:' . number_format( $cutoffPct, 2 ) . '%" title="Low-need threshold: mean 4.0"></span>'
            . '</span>'
            . '<span class="pcp-up-cati-val">' . htmlspecialchars( $disp ) . '</span>'
            . '</span>'
            . '</div>';

        // 3 axes, clockwise from top:
        //   [ scoreKey, angleDeg, label, labelX, labelY ]
        $axes = [
            [ 'subscale_AUT', 90,   'Autonomy',    100, 29  ],
            [ 'subscale_COM', -30,  'Competence',  165, 145 ],
            [ 'subscale_REL', -150, 'Relatedness',  35, 145 ],
        ];

        $headline .= '<div class="pcp-up-bpns-radar-wrap">'
            . $this->bpnsRadarSvg( $s, $axes )
            . '</div>';
        $headline .= $this->bpnsPhenomCaption( $s );

        // Sub: low-need chip on the TOT line.
        if ( $total >= 5.5 ) {
            $sub = '<span class="pcp-up-above">high overall need satisfaction (mean &ge; 5.5)</span>';
        } elseif ( $total >= 4.0 ) {
            $sub = '<span class="pcp-up-above">typical overall need satisfaction (mean &ge; 4.0)</span>';
        } else {
            $sub = '<span class="pcp-up-below">low overall need satisfaction (mean &lt; 4.0)</span>';
        }
        return [ 'headline' => $headline, 'sub' => $sub ];
    }

    /**
     * Triangle radar for BPNS. Single dashed-white ring at the low-need
     * threshold (mean 4.0). Subscales inside the ring are "unmet" and
     * get the violet+glow treatment (inverted valence: low = bad).
     *
     * @param array $s     score array (subscale_AUT, _COM, _REL)
     * @param array $axes  list of [ scoreKey, angleDeg, label, labelX, labelY ]
     */
    private function bpnsRadarSvg( array $s, array $axes ): string {
        $cx = 100; $cy = 100; $maxR = 58;
        // Normalise raw 1..7 to 0..1.
        $rawLo = 1.0; $rawHi = 7.0; $span = $rawHi - $rawLo;

        $vertexAtRaw = function ( float $raw, float $deg ) use ( $cx, $cy, $maxR, $rawLo, $span ) {
            $norm = max( 0.0, min( 1.0, ( $raw - $rawLo ) / $span ) );
            $r = $maxR * $norm;
            $rad = deg2rad( $deg );
            return [
                $cx + $r * cos( $rad ),
                $cy - $r * sin( $rad ),
            ];
        };

        $hexAtRadius = function ( float $r ) use ( $axes, $cx, $cy ) {
            $pts = [];
            foreach ( $axes as [ , $deg, , , , ] ) {
                $rad = deg2rad( $deg );
                $x = $cx + $r * cos( $rad );
                $y = $cy - $r * sin( $rad );
                $pts[] = number_format( $x, 2 ) . ',' . number_format( $y, 2 );
            }
            return implode( ' ', $pts );
        };

        // Background rings at raw 3 / 5 / 7 ( = r 19.33 / 38.67 / 58 ).
        $rings = '<polygon class="ring-mid" points="' . $hexAtRadius( $maxR * ( 2.0 / 6.0 ) ) . '"/>'
               . '<polygon class="ring-mid" points="' . $hexAtRadius( $maxR * ( 4.0 / 6.0 ) ) . '"/>'
               . '<polygon class="ring"     points="' . $hexAtRadius( $maxR )                  . '"/>';

        // Low-threshold polygon (regular triangle at raw 4.0 → r=29).
        $rLow = $maxR * ( ( 4.0 - $rawLo ) / $span );
        $ringLow = '<polygon class="ring-low" points="' . $hexAtRadius( $rLow ) . '"/>';

        // Axes.
        $lines = '';
        foreach ( $axes as [ , $deg, , , , ] ) {
            $rad = deg2rad( $deg );
            $x = $cx + $maxR * cos( $rad );
            $y = $cy - $maxR * sin( $rad );
            $lines .= '<line class="axis" x1="' . $cx . '" y1="' . $cy . '" x2="' . number_format( $x, 2 ) . '" y2="' . number_format( $y, 2 ) . '"/>';
        }

        // Data polygon + dots.
        $dataPts = [];
        $dots = '';
        foreach ( $axes as [ $key, $deg, , , , ] ) {
            $v = isset( $s[ $key ] ) && $s[ $key ] !== null ? (float)$s[ $key ] : $rawLo;
            [ $x, $y ] = $vertexAtRaw( $v, (float)$deg );
            $dataPts[] = number_format( $x, 2 ) . ',' . number_format( $y, 2 );
            $isUnmet = $v < 4.0;
            $cls = 'dot' . ( $isUnmet ? ' is-unmet' : '' );
            $rdot = $isUnmet ? 3.5 : 2.6;
            $dots .= '<circle class="' . $cls . '" cx="' . number_format( $x, 2 ) . '" cy="' . number_format( $y, 2 ) . '" r="' . $rdot . '"/>';
        }
        $dataPoly = '<polygon class="data" points="' . implode( ' ', $dataPts ) . '"/>';

        // Labels (highlight unmet subscales).
        $labels = '';
        foreach ( $axes as [ $key, , $label, $lx, $ly ] ) {
            $v = isset( $s[ $key ] ) && $s[ $key ] !== null ? (float)$s[ $key ] : $rawLo;
            $cls = 'label' . ( $v < 4.0 ? ' is-unmet' : '' );
            $labels .= '<text class="' . $cls . '" x="' . $lx . '" y="' . $ly . '">' . htmlspecialchars( $label ) . '</text>';
        }

        return '<svg class="pcp-up-bpns-radar" viewBox="0 0 200 200">'
            . $rings
            . $ringLow
            . '<g>' . $lines . '</g>'
            . $dataPoly
            . $dots
            . $labels
            . '</svg>';
    }

    /**
     * Phenomenological (C3) caption: "Feeling X and Y / but less Z".
     * Phrases depend on which needs are high (>=5.5) / typical (>=4.0) / low (<4.0).
     */
    private function bpnsPhenomCaption( array $s ): string {
        // Per-need vocabulary in three bands.
        //   [ label, high-phrase, typical-phrase, low-phrase ]
        $vocab = [
            'AUT' => [ 'Autonomy',    'free',              'autonomous', 'constrained'              ],
            'COM' => [ 'Competence',  'effective',         'capable',    'less effective'           ],
            'REL' => [ 'Relatedness', 'deeply connected',  'connected',  'less connected to others' ],
        ];

        $high = []; $typical = []; $low = [];
        $valueStrs = [];
        foreach ( $vocab as $k => [ $label, $hi, $tp, $lo ] ) {
            $v = $s[ 'subscale_' . $k ] ?? null;
            if ( $v === null ) continue;
            $vf = (float)$v;
            $valueStrs[] = $label . ' ' . number_format( $vf, 1 );
            if      ( $vf >= 5.5 ) $high[]    = $hi;
            elseif  ( $vf >= 4.0 ) $typical[] = $tp;
            else                   $low[]     = $lo;
        }

        $joinAnd = function ( array $items ) {
            $n = count( $items );
            if ( $n === 0 ) return '';
            if ( $n === 1 ) return $items[0];
            if ( $n === 2 ) return $items[0] . ' and ' . $items[1];
            // 3+: Oxford comma
            $last = array_pop( $items );
            return implode( ', ', $items ) . ', and ' . $last;
        };

        $metPhrases = array_merge( $high, $typical );

        // Build headline + detail.
        if ( count( $low ) === 0 && count( $high ) === count( $vocab ) ) {
            // All thriving.
            $headlineHtml = '<span class="pcp-up-bpns-caption-feel">Thriving across all three needs</span>';
            $detailHtml = '<span class="pcp-up-bpns-caption-detail">feeling ' . htmlspecialchars( $joinAnd( $high ) ) . '</span>';
        } elseif ( count( $low ) === 0 ) {
            // All met, some high / some typical.
            $headlineHtml = '<span class="pcp-up-bpns-caption-feel">Feeling ' . htmlspecialchars( $joinAnd( $metPhrases ) ) . '</span>';
            $detailHtml = '<span class="pcp-up-bpns-caption-detail">all three needs adequately met</span>';
        } elseif ( count( $low ) === count( $vocab ) ) {
            // All three unmet.
            $headlineHtml = '<span class="pcp-up-bpns-caption-feel">All three needs feel strained</span>';
            $detailHtml = '<span class="pcp-up-bpns-caption-detail">feeling ' . htmlspecialchars( $joinAnd( $low ) ) . '</span>';
        } else {
            // Mixed: some met, some unmet — the canonical C3 phrasing.
            $met = $joinAnd( $metPhrases );
            $gap = $joinAnd( $low );
            $headlineHtml = '<span class="pcp-up-bpns-caption-feel">Feeling ' . htmlspecialchars( $met ) . '</span>';
            $detailHtml = 'but <span class="pcp-up-bpns-caption-gap">' . htmlspecialchars( $gap ) . '</span>';
        }

        $hint = '<span class="pcp-up-bpns-caption-hint">' . htmlspecialchars( implode( ' · ', $valueStrs ) ) . '</span>';

        return '<p class="pcp-up-bpns-caption">'
            . $headlineHtml . '<br>'
            . $detailHtml
            . $hint
            . '</p>';
    }

    private function meanHeadline( array $s, float $max, string $label ): array {
        if ( !isset( $s['total'] ) ) return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        $m = (float)$s['total'];
        $head = '<span class="pcp-up-big">' . number_format( $m, 2 ) . '</span><span class="pcp-up-of">/ ' . $max . '</span>';
        return [ 'headline' => $head, 'sub' => '<em>Overall mean across all items.</em>' ];
    }

    private function pid5bfHeadline( array $s ): array {
        // PID-5-BF: 5 maladaptive personality domains, each scored 0..3
        // (mean of 5 items). Cutoff for "elevated" is mean >= 2.0.
        // Card design: TOT bar + 5-axis radar with dashed-white cutoff
        // ring at the 2.0 level + caption (named constellation when the
        // elevated combination matches one, otherwise the list).
        if ( !isset( $s['total'] ) || $s['total'] === null ) {
            return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        }
        $total = (float)$s['total'];
        $pct       = max( 0.0, min( 100.0, ( $total / 3.0 ) * 100.0 ) );
        $cutoffPct = ( 2.0 / 3.0 ) * 100.0;
        $disp = number_format( $total, 2 );

        $headline  = '<div class="pcp-up-cati">'
            . '<span class="pcp-up-cati-trait">'
            . '<span class="pcp-up-cati-label">TOT</span>'
            . '<span class="pcp-up-cati-bar">'
                . '<span class="pcp-up-cati-fill" style="width:' . number_format( $pct, 2 ) . '%"></span>'
                . '<span class="pcp-up-cati-cutoff-mark" style="left:' . number_format( $cutoffPct, 2 ) . '%" title="Elevated cutoff: 2.0"></span>'
            . '</span>'
            . '<span class="pcp-up-cati-val">' . htmlspecialchars( $disp ) . '</span>'
            . '</span>'
            . '</div>';

        // Domain order matches Pid5bf::SUBSCALES; radar axes go clockwise
        // from the top: NA (top), DET (upper-right), ANT (lower-right),
        // DIS (lower-left), PSY (upper-left).
        $axes = [
            // [ scoreKey, mathAngleDeg, shortLabel, fullLabel,
            //   labelX, labelY ]
            [ 'subscale_NA',   90,  'Neg Affect',  'Negative Affectivity', 100, 29  ],
            [ 'subscale_DET',  18,  'Detachment',  'Detachment',           172, 80  ],
            [ 'subscale_ANT', -54,  'Antagonism',  'Antagonism',           151, 166 ],
            [ 'subscale_DIS', -126, 'Disinhibit.', 'Disinhibition',         49, 166 ],
            [ 'subscale_PSY', 162,  'Psychotic.',  'Psychoticism',          28, 80  ],
        ];

        $headline .= '<div class="pcp-up-pid5-radar-wrap">' . $this->pid5bfRadarSvg( $s, $axes ) . '</div>';

        // Detect elevated domains + pattern name.
        $elev = [];
        foreach ( $axes as [ $k, , , $fullLabel ] ) {
            if ( isset( $s[ $k ] ) && $s[ $k ] !== null && (float)$s[ $k ] >= 2.0 ) {
                $elev[ $k ] = $fullLabel;
            }
        }
        $caption = $this->pid5bfPatternCaption( $elev );
        $headline .= $caption;

        // Sub: count chip.
        $cnt = count( $elev );
        if ( $cnt === 0 ) {
            $sub = '<span class="pcp-up-below">no domain above cutoff</span>';
        } else {
            $sub = '<span class="pcp-up-above">' . $cnt . ' of 5 domains above cutoff (&ge; 2.0)</span>';
        }

        return [ 'headline' => $headline, 'sub' => $sub ];
    }

    /**
     * Build the 5-axis radar SVG for the PID-5-BF domains.
     * @param array $s     score array (keys subscale_NA, _DET, _ANT, _DIS, _PSY)
     * @param array $axes  list of [ scoreKey, angleDeg, shortLabel, fullLabel, labelX, labelY ]
     */
    private function pid5bfRadarSvg( array $s, array $axes ): string {
        $cx = 100; $cy = 100; $maxR = 58;
        $r1 = $maxR * ( 1.0 / 3.0 );  // 19.33
        $r2 = $maxR * ( 2.0 / 3.0 );  // 38.67
        $r3 = $maxR;

        $vertices = function ( float $r ) use ( $axes, $cx, $cy ) {
            $pts = [];
            foreach ( $axes as [ , $deg, , , , ] ) {
                $rad = deg2rad( $deg );
                $x = $cx + $r * cos( $rad );
                $y = $cy - $r * sin( $rad );
                $pts[] = number_format( $x, 2 ) . ',' . number_format( $y, 2 );
            }
            return implode( ' ', $pts );
        };

        // Background rings.
        $ringInner  = '<polygon class="ring"        points="' . $vertices( $r1 ) . '"/>';
        $ringOuter  = '<polygon class="ring"        points="' . $vertices( $r3 ) . '"/>';
        $ringCutoff = '<polygon class="ring-cutoff" points="' . $vertices( $r2 ) . '"/>';

        // Axis lines.
        $lines = '';
        foreach ( $axes as [ , $deg, , , , ] ) {
            $rad = deg2rad( $deg );
            $x = $cx + $maxR * cos( $rad );
            $y = $cy - $maxR * sin( $rad );
            $lines .= '<line class="axis" x1="' . $cx . '" y1="' . $cy . '" x2="' . number_format( $x, 2 ) . '" y2="' . number_format( $y, 2 ) . '"/>';
        }

        // Tick labels on top axis (1, 2, 3 corresponding to r1, r2, r3).
        $ticks  = '<text class="tick-label"        x="102" y="' . number_format( $cy - $r1 + 2, 1 ) . '">1</text>';
        $ticks .= '<text class="tick-cutoff-label" x="102" y="' . number_format( $cy - $r2 + 2, 1 ) . '">2</text>';
        $ticks .= '<text class="tick-label"        x="102" y="' . number_format( $cy - $r3 + 2, 1 ) . '">3</text>';

        // Data polygon + dots.
        $dataPts = [];
        $dots = '';
        foreach ( $axes as [ $key, $deg, , , , ] ) {
            $v = isset( $s[ $key ] ) && $s[ $key ] !== null ? (float)$s[ $key ] : 0.0;
            $vClamped = max( 0.0, min( 3.0, $v ) );
            $r = $maxR * ( $vClamped / 3.0 );
            $rad = deg2rad( $deg );
            $x = $cx + $r * cos( $rad );
            $y = $cy - $r * sin( $rad );
            $dataPts[] = number_format( $x, 2 ) . ',' . number_format( $y, 2 );
            $isElev = $v >= 2.0;
            $cls = 'dot' . ( $isElev ? ' is-elev' : '' );
            $rdot = $isElev ? 3.5 : 2.6;
            $dots .= '<circle class="' . $cls . '" cx="' . number_format( $x, 2 ) . '" cy="' . number_format( $y, 2 ) . '" r="' . $rdot . '"/>';
        }
        $dataPoly = '<polygon class="data" points="' . implode( ' ', $dataPts ) . '"/>';

        // Labels (outside the 3.0 ring); elevated labels get bright + bold class.
        $labels = '';
        foreach ( $axes as [ $key, , $shortLabel, $fullLabel, $lx, $ly ] ) {
            $v = isset( $s[ $key ] ) && $s[ $key ] !== null ? (float)$s[ $key ] : 0.0;
            $cls = 'label' . ( $v >= 2.0 ? ' is-elev' : '' );
            $labels .= '<text class="' . $cls . '" x="' . $lx . '" y="' . $ly . '">'
                . '<title>' . htmlspecialchars( $fullLabel ) . '</title>'
                . htmlspecialchars( $shortLabel )
                . '</text>';
        }

        return '<svg class="pcp-up-pid5-radar" viewBox="0 0 200 200">'
            . $ringInner . $ringOuter . $ringCutoff
            . '<g>' . $lines . '</g>'
            . $ticks
            . $dataPoly
            . $dots
            . $labels
            . '</svg>';
    }

    /**
     * Caption text under the PID-5-BF radar. Surfaces a named constellation
     * when the elevated combination matches a recognised one; otherwise
     * lists the elevated domains; otherwise reports "all below cutoff".
     *
     * @param array $elev keyed by subscale_X with full-label values
     */
    private function pid5bfPatternCaption( array $elev ): string {
        $cnt = count( $elev );
        $keys = array_keys( $elev );
        sort( $keys );

        $patternName = null;
        $patternDetail = null;

        if ( $cnt >= 4 ) {
            $patternName = 'Broad personality pathology';
            $patternDetail = $cnt . ' of 5 domains elevated';
        } elseif ( $keys === [ 'subscale_DET', 'subscale_NA' ] ) {
            $patternName = 'Internalizing pattern';
            $patternDetail = 'Negative Affectivity + Detachment elevated';
        } elseif ( $keys === [ 'subscale_ANT', 'subscale_DIS' ] ) {
            $patternName = 'Externalizing pattern';
            $patternDetail = 'Antagonism + Disinhibition elevated';
        } elseif ( $keys === [ 'subscale_PSY' ] ) {
            $patternName = 'Schizotypal pattern';
            $patternDetail = 'Psychoticism elevated';
        }

        $hint = '<span class="pcp-up-pid5-caption-hint">mean &ge; 2.0 = elevated (dashed ring)</span>';

        // 0 elevated: explicit "Sub-threshold" headline (mirrors CATI / CAT-Q).
        if ( $cnt === 0 ) {
            return '<p class="pcp-up-pid5-caption is-none">'
                . '<span class="pcp-up-pid5-caption-pattern">Sub-threshold personality-trait profile</span><br>'
                . '<span class="pcp-up-pid5-caption-list">no domain reached mean &ge; 2.0</span>'
                . $hint
                . '</p>';
        }

        // Named pattern: headline + detail.
        if ( $patternName !== null ) {
            return '<p class="pcp-up-pid5-caption">'
                . '<span class="pcp-up-pid5-caption-pattern">' . htmlspecialchars( $patternName ) . '</span><br>'
                . '<span class="pcp-up-pid5-caption-list">' . htmlspecialchars( $patternDetail ) . '</span>'
                . $hint
                . '</p>';
        }

        // No named pattern (elevated subset doesn't match a recognised
        // constellation): lead with "Mixed personality-trait elevation"
        // headline, list the elevated domains as detail.
        $labels = array_values( $elev );
        $joined = implode( ' &middot; ', array_map( 'htmlspecialchars', $labels ) );
        return '<p class="pcp-up-pid5-caption">'
            . '<span class="pcp-up-pid5-caption-pattern">Mixed personality-trait elevation</span><br>'
            . '<span class="pcp-up-pid5-caption-list">' . $joined . ' elevated</span>'
            . $hint
            . '</p>';
    }

    private function mbtiHeadline( array $s ): array {
        $Mbti = \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::class;
        // Build the 4-letter code from dichotomy scores (positive = right pole letter).
        $code = '';
        $scores = [];
        foreach ( $Mbti::DICHOTOMIES as $k => $def ) {
            if ( !isset( $s[ $k ] ) || $s[ $k ] === null ) { $code = ''; break; }
            $v = (float)$s[ $k ];
            $scores[ $k ] = $v;
            $code .= ( $v < 0 ? $def['left'] : $def['right'] );
        }
        if ( strlen( $code ) !== 4 ) {
            return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];
        }

        $typeInfo = $Mbti::TYPES[ $code ] ?? null;
        $name = $typeInfo[0] ?? '';
        $desc = $typeInfo[1] ?? '';
        // Strip parenthetical alternate name for the compact typehead line.
        $shortName = trim( preg_replace( '/\s*\(.*\)\s*$/', '', $name ) );

        // ----- Typehead: big code pill + short name -----
        $typehead = '<div class="mbti-typehead">'
            . '<span class="pcp-up-big pcp-up-typecode">' . htmlspecialchars( $code ) . '</span>'
            . ( $shortName !== '' ? '<span class="mbti-typename">' . htmlspecialchars( $shortName ) . '</span>' : '' )
            . '</div>';

        // ----- Bipolar axis bars -----
        $axesHtml = '';
        foreach ( $Mbti::DICHOTOMIES as $k => $def ) {
            $score = $scores[ $k ];
            $axis = $Mbti::describeAxis( $k, $score );
            $strength = $axis['strength']; // balanced|slight|clear|strong
            // Position: score -2 .. +2 maps to 0% .. 100%.
            $posPct = max( 0.0, min( 100.0, ( ( $score - $Mbti::DICH_MIN ) / ( $Mbti::DICH_MAX - $Mbti::DICH_MIN ) ) * 100.0 ) );
            // Active letter: the one user is closer to (right if score >= 0).
            $leftActive  = $score < 0 ? ' is-active' : '';
            $rightActive = $score >= 0 ? ' is-active' : '';
            // Strength label text: 'strong I', 'balanced', etc.
            if ( $strength === 'balanced' ) {
                $strLabel = 'balanced';
            } else {
                $strLabel = $strength . ' ' . $axis['letter'];
            }
            $axesHtml .= '<div class="mbti-axis">'
                . '<span class="mbti-axis-letter' . $leftActive . '">' . htmlspecialchars( $def['left'] ) . '</span>'
                . '<span class="mbti-axis-bar">'
                    . '<span class="mbti-axis-mid"></span>'
                    . '<span class="mbti-axis-dot" style="left:' . number_format( $posPct, 2 ) . '%" title="' . htmlspecialchars( number_format( $score, 2 ) ) . '"></span>'
                . '</span>'
                . '<span class="mbti-axis-letter' . $rightActive . '">' . htmlspecialchars( $def['right'] ) . '</span>'
                . '<span class="mbti-axis-strength is-' . htmlspecialchars( $strength ) . '">' . htmlspecialchars( $strLabel ) . '</span>'
                . '</div>';
        }
        $axesBlock = '<div class="mbti-axes">' . $axesHtml . '</div>';

        // ----- Cognitive function stack (Dom/Aux/Tert/Inf) -----
        $funcsBlock = '';
        $stack = $Mbti::FUNCTIONS[ $code ] ?? null;
        if ( is_array( $stack ) && count( $stack ) === 4 ) {
            $roles = [ 'Dom', 'Aux', 'Tert', 'Inf' ];
            $roleCls = [ 'is-dom', 'is-aux', '', '' ];
            $funcsHtml = '';
            foreach ( $stack as $i => $fn ) {
                $fnName = $Mbti::FUNCTION_NAMES[ $fn ] ?? $fn;
                $rowCls = 'mbti-func' . ( $roleCls[ $i ] !== '' ? ' ' . $roleCls[ $i ] : '' );
                $funcsHtml .= '<div class="' . $rowCls . '">'
                    . '<span class="mbti-func-role">' . $roles[ $i ] . '</span>'
                    . '<span class="mbti-func-code">' . htmlspecialchars( $fn ) . '</span>'
                    . '<span class="mbti-func-name">' . htmlspecialchars( $fnName ) . '</span>'
                    . '</div>';
            }
            $funcsBlock = '<div class="mbti-funcs">' . $funcsHtml . '</div>';
        }

        return [
            'headline' => $typehead . $axesBlock . $funcsBlock,
            'sub'      => $desc !== '' ? '<em>' . htmlspecialchars( $desc ) . '</em>' : '',
        ];
    }

    private function enneagramHeadline( array $s ): array {
        // Collect per-type scores (each 0..100, mean of 5 items rescaled).
        $byType = [];
        for ( $t = 1; $t <= 9; $t++ ) {
            $k = 'type_' . $t;
            if ( !isset( $s[ $k ] ) || $s[ $k ] === null ) continue;
            $byType[ $t ] = (float)$s[ $k ];
        }
        if ( !$byType ) return [ 'headline' => '<em>Incomplete</em>', 'sub' => '' ];

        // Top = highest scoring type.
        arsort( $byType );
        $top = (int)array_key_first( $byType );

        // Wing = higher-scoring of the two adjacent types on the circle (1..9..1).
        $left  = $top === 1 ? 9 : $top - 1;
        $right = $top === 9 ? 1 : $top + 1;
        $lv = $byType[ $left ] ?? -INF;
        $rv = $byType[ $right ] ?? -INF;
        $wing = $lv >= $rv ? $left : $right;
        $code = $top . 'w' . $wing;

        $typeInfo = \MediaWiki\Extension\Pharmacopedia\Assessments\Enneagram::TYPES[ $top ] ?? null;
        $name = $typeInfo && isset( $typeInfo['name'] ) ? (string)$typeInfo['name'] : '';
        $wingInfo = \MediaWiki\Extension\Pharmacopedia\Assessments\Enneagram::TYPES[ $wing ] ?? null;
        $wingName = $wingInfo && isset( $wingInfo['name'] ) ? (string)$wingInfo['name'] : '';
        // Strip leading 'The ' for compactness in the sub line.
        $wingShort = preg_replace( '/^The\s+/', '', $wingName );

        // Short type names (epithet form, fits narrow cards).
        $shortNames = [
            1 => 'Reformer',     2 => 'Helper',       3 => 'Achiever',
            4 => 'Individualist',5 => 'Investigator', 6 => 'Loyalist',
            7 => 'Enthusiast',   8 => 'Challenger',   9 => 'Peacemaker',
        ];

        // Render 9 ranked rows.
        $rowsHtml = '';
        foreach ( $byType as $t => $v ) {
            $isTop  = ( $t === $top );
            $isWing = ( $t === $wing );
            $rowCls = 'pcp-up-enneagram-row';
            if ( $isTop )  $rowCls .= ' is-top';
            if ( $isWing ) $rowCls .= ' is-wing';
            $disp = number_format( $v, $v == (int)$v ? 0 : 1 );
            $rowsHtml .= '<div class="' . $rowCls . '">'
                . '<span class="pcp-up-enneagram-num">' . $t . '</span>'
                . '<span class="pcp-up-enneagram-name">' . htmlspecialchars( $shortNames[ $t ] ?? '' ) . '</span>'
                . '<span class="pcp-up-cati-bar"><span class="pcp-up-cati-fill" style="width:' . number_format( $v, 1 ) . '%"></span></span>'
                . '<span class="pcp-up-enneagram-val">' . htmlspecialchars( $disp ) . '</span>'
                . '</div>';
        }

        $legend = '<div class="pcp-up-enneagram-legend">'
            . '<span><span class="legend-swatch is-top"></span> Top type</span>'
            . '<span><span class="legend-swatch is-wing"></span> Wing</span>'
            . '</div>';

        $headline = '<div class="pcp-up-enneagram-codehead">'
            . '<span class="pcp-up-big pcp-up-typecode">' . htmlspecialchars( $code ) . '</span>'
            . '</div>'
            . '<div class="pcp-up-enneagram-rows">' . $rowsHtml . '</div>'
            . $legend;

        if ( $name !== '' && $wingShort !== '' ) {
            $sub = '<em>' . htmlspecialchars( $name ) . '</em>'
                . ' <span class="pcp-up-enneagram-wing-note">with ' . htmlspecialchars( $wingShort ) . ' wing</span>';
        } else {
            $sub = $name !== '' ? '<em>' . htmlspecialchars( $name ) . '</em>' : '';
        }

        return [ 'headline' => $headline, 'sub' => $sub ];
    }

    private function renderFormalTesting( $out, $profile, int $minVis, bool $isSysop ) {
        $profId = (int)$profile->prof_id;
        $store = new \MediaWiki\Extension\Pharmacopedia\FormalTestStore();
        $scores = $store->getUserScores( $profId, $minVis );
        if ( !$scores ) {
            return;
        }

        $h = function ( $s ) { return htmlspecialchars( (string)$s, ENT_QUOTES ); };
        $showBadge = $isSysop || ( $minVis === 0 );
        // A score field shows when the viewer owns the profile or is a
        // sysop ( minVis 0 ), or that field's own visibility is public.
        $fieldVisible = static function ( int $fieldVis ) use ( $minVis ) {
            return $minVis === 0 || $fieldVis > 0;
        };

        $byCat = [];
        foreach ( $scores as $s ) {
            $cat = $s->uts_test_id !== null ? (string)$s->ft_category : 'other';
            $byCat[ $cat ][] = $s;
        }

        $sections = '';
        foreach ( $byCat as $cat => $rows ) {
            $cards = '';
            foreach ( $rows as $s ) {
                $isCustom = $s->uts_test_id === null;
                $abbrev = $isCustom ? (string)$s->uts_custom_abbrev : (string)$s->ft_abbrev;
                $name   = $isCustom ? (string)$s->uts_custom_name   : (string)$s->ft_full_name;
                $year   = $s->uts_year_taken !== null ? (int)$s->uts_year_taken : null;
                $parts  = [];
                if ( $s->uts_raw_score !== null && $fieldVisible( (int)$s->uts_vis_raw ) ) {
                    $disp = rtrim( rtrim( sprintf( '%.2f', (float)$s->uts_raw_score ), '0' ), '.' );
                    if ( isset( $s->uts_raw_is_estimate ) && (int)$s->uts_raw_is_estimate === 1 ) {
                        $disp = '~' . $disp;
                    }
                    $parts[] = 'raw <b>' . $h( $disp ) . '</b>' . $this->visBadge( (int)$s->uts_vis_raw, $showBadge );
                }
                if ( $s->uts_percentile !== null && $fieldVisible( (int)$s->uts_vis_pct ) ) {
                    $disp = rtrim( rtrim( sprintf( '%.2f', (float)$s->uts_percentile ), '0' ), '.' );
                    if ( isset( $s->uts_pct_is_estimate ) && (int)$s->uts_pct_is_estimate === 1 ) {
                        $disp = '~' . $disp;
                    }
                    $parts[] = 'percentile <b>' . $h( $disp ) . '</b>' . $this->visBadge( (int)$s->uts_vis_pct, $showBadge );
                }
                if ( $s->uts_pass_fail !== null && $fieldVisible( (int)$s->uts_vis_passfail ) ) {
                    $parts[] = '<b>' . ( (int)$s->uts_pass_fail === 1 ? 'Pass' : 'Fail' ) . '</b>' . $this->visBadge( (int)$s->uts_vis_passfail, $showBadge );
                }
                // If every score field is private to this viewer, omit the row.
                if ( !$parts && $minVis !== 0 ) {
                    continue;
                }
                $cards .= '<div class="pcp-up-ft-card">' .
                    '<span class="pcp-up-ft-abbrev">' . $h( $abbrev ) . '</span> ' .
                    '<span class="pcp-up-ft-name">' . $h( $name ) . '</span>' .
                    ( $parts ? ' <small>&middot; ' . implode( ' &middot; ', $parts ) . '</small>' : '' ) .
                    ( $year ? ' <small class="pcp-up-ft-year">(' . $h( (string)$year ) . ')</small>' : '' ) .
                    ( isset( $s->ft_legacy ) && (int)$s->ft_legacy ? ' <small class="pcp-up-ft-legacy">legacy</small>' : '' ) .
                    '</div>';
            }
            if ( $cards === '' ) {
                continue;
            }
            $sections .= '<div class="pcp-up-ft-section">' .
                '<h3 class="pcp-up-ft-cat">' . $h( ucfirst( $cat ) ) . '</h3>' .
                '<div class="pcp-up-ft-list">' . $cards . '</div></div>';
        }
        if ( $sections === '' ) {
            return;
        }

        $out->addHTML( '<h2>Formal testing</h2>' );
        $out->addHTML( '<div class="pcp-up-formaltest">' . $sections . '</div>' );
    }
}
