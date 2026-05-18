<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Html\Html;
use MediaWiki\Extension\Pharmacopedia\Assessments\Cati;
use MediaWiki\Extension\Pharmacopedia\Assessments\CatiNorms;
use MediaWiki\Extension\Pharmacopedia\Assessments\Catq;
use MediaWiki\Extension\Pharmacopedia\Assessments\CatqNorms;
use MediaWiki\Extension\Pharmacopedia\Assessments\Pid5bf;
use MediaWiki\Extension\Pharmacopedia\Assessments\Pid5bfNorms;
use MediaWiki\Extension\Pharmacopedia\Assessments\Nfcs;
use MediaWiki\Extension\Pharmacopedia\Assessments\NfcsNorms;
use MediaWiki\Extension\Pharmacopedia\Assessments\Bpns;
use MediaWiki\Extension\Pharmacopedia\Assessments\BpnsNorms;
use MediaWiki\Extension\Pharmacopedia\Assessments\WhoqolBref;
use MediaWiki\Extension\Pharmacopedia\Assessments\WhoqolBrefNorms;
use MediaWiki\Extension\Pharmacopedia\Assessments\OceanNorms;

/**
 * Special:MyAssessment/<key>: owner-facing rich results report for a
 * completed assessment. Currently implemented for the CATI; extensible
 * to other assessments in the future.
 *
 * Modelled on the NovoPsych CATI auto-scoring report
 * (https://novopsych.com.au/assessments/autism/comprehensive-autistic-trait-inventory-cati/)
 * with the plain-language interpretation style from
 * NeurodivUrgent (https://www.neurodivurgent.health/results).
 *
 * Data sources:
 *  - English et al. 2021 (Mol Autism 12(1):37). Original CATI + 134 cutoff
 *  - English et al. 2025 (Autism, doi:10.1177/13623613251347740):
 *    gender-specific cutoffs (148 / 139 / 141 / 156) + normative percentiles
 *    (full table in CatiNorms.php from OSF supplementary file)
 */
class SpecialMyAssessment extends SpecialPage {

    /** @var bool Whether the viewer owns the report being shown. */
    private $isOwner = true;
    private $viewerIsOwner = true;  // legacy alias

    /** Minimum-visibility filter to apply in render code (0 for owner, 1 for non-owner share view). */
    private function visMin(): int { return $this->isOwner ? 0 : 1; }

    public function __construct() {
        parent::__construct( 'MyAssessment' );
    }

    /**
     * Determine whether the current viewer can see the RAW item-level
     * responses for a given assessment + profile. Owners always can.
     * For non-owners, gates on the <key>_raw._vis stored field (or any row
     * vis>=1 in that namespace). Rule-based shares (VisibilityResolver) are
     * also honoured with namespace "<key>_raw" if a rule matches.
     */
    private function canViewRaw( $store, $profile, string $key, bool $isOwner ): bool {
        if ( $isOwner ) return true;
        $profileId = (int)$profile->prof_id;
        $viewer = $this->getUser();
        $linkToken = trim( (string)$this->getRequest()->getVal( 'pcpshare', '' ) );
        $rawNs = $key . '_raw';
        // Rule-based check first
        $ruleAllows = \MediaWiki\Extension\Pharmacopedia\VisibilityResolver::canViewByRule(
            $profileId,
            (int)$viewer->getId(),
            $rawNs,
            null,
            $linkToken !== '' ? $linkToken : null
        );
        if ( $ruleAllows ) return true;
        // Legacy: check stored _vis on the raw namespace
        $rawVis = 0;
        foreach ( $store->getFields( $profileId, $rawNs, 0 ) as $f ) {
            if ( (string)$f->pf_key === '_vis' ) {
                $rawVis = (int)( $f->pf_value_num ?? 0 );
                break;
            }
        }
        return $rawVis >= 1;
    }

    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->addModuleStyles( [ 'ext.pharmacopedia.styles', 'ext.pharmacopedia.share' ] );
        $out->addModules( [ 'ext.pharmacopedia.share' ] );
        $currentUser = $this->getUser();
        $forUserName = trim( (string)$this->getRequest()->getVal( 'user', '' ) );
        $key = trim( (string)$par );

        // Resolve target user (self by default; ?user=NAME for shared views).
        $isOwner = true;
        $user = $currentUser;
        if ( $forUserName !== '' ) {
            $target = \User::newFromName( $forUserName );
            if ( !$target || !$target->isRegistered() ) {
                $out->setPageTitle( 'Shared report' );
                $out->addWikiTextAsInterface( "User '''" . wfEscapeWikiText( $forUserName ) . "''' not found." );
                return;
            }
            // Owner check via userId (robust to capitalization / normalization)
            $isOwner = $currentUser->isRegistered() && (int)$target->getId() === (int)$currentUser->getId();
        }
        if ( !$isOwner ) {
            // Read-only profile lookup. getByUsername does NOT create on miss,
            // so we don't write to DB from a public GET. Absent profile = not shared.
            $store = new UserProfileStore();
            $tprofile = $store->getByUsername( $forUserName );
            if ( !$tprofile ) {
                $out->setPageTitle( 'Report not shared' );
                $out->addWikiTextAsInterface( "'''" . wfEscapeWikiText( $forUserName ) . "''' has no profile data yet." );
                return;
            }
            // Per-test visibility check: rule-based first, then legacy.
            $linkToken = trim( (string)$this->getRequest()->getVal( 'pcpshare', '' ) );
            $ruleAllows = \MediaWiki\Extension\Pharmacopedia\VisibilityResolver::canViewByRule(
                (int)$tprofile->prof_id,
                (int)$currentUser->getId(),
                $key !== '' ? $key : '*',
                null,
                $linkToken !== '' ? $linkToken : null
            );
            // Phase 5: audit-log a permitted view (rule-based path).
            if ( $ruleAllows && $key !== '' ) {
                \MediaWiki\Extension\Pharmacopedia\VisibilityResolver::logView(
                    null,
                    (int)$tprofile->prof_id,
                    (int)$currentUser->getId(),
                    $currentUser->isRegistered() ? null : (string)$this->getRequest()->getIP(),
                    $key,
                    null
                );
            }
            if ( !$ruleAllows && $key !== '' ) {
                $vis = 0;
                if ( $key === 'ocean' ) {
                    // OCEAN has per-trait visibility, not per-test. Public if ANY trait is >=1.
                    // (The render method also filters by vis>=1 so private traits stay hidden.)
                    foreach ( $store->getFields( (int)$tprofile->prof_id, 'ocean', 0 ) as $f ) {
                        if ( (int)$f->pf_visibility >= 1 ) { $vis = (int)$f->pf_visibility; break; }
                    }
                } else {
                    foreach ( $store->getFields( (int)$tprofile->prof_id, $key, 0 ) as $f ) {
                        if ( (string)$f->pf_key === '_vis' ) { $vis = (int)( $f->pf_value_num ?? 0 ); break; }
                    }
                }
                if ( $vis < 1 ) {
                    $out->setPageTitle( 'Report not shared' );
                    $out->addWikiTextAsInterface(
                        "'''" . wfEscapeWikiText( $forUserName ) . "''' has not made their " .
                        wfEscapeWikiText( strtoupper( $key ) ) . " report public.\n\n" .
                        "(The owner can change this via the visibility chip on [[Special:MyProfile]].)"
                    );
                    return;
                }
            }
            $user = $target;
        }
        if ( $isOwner && !$currentUser->isRegistered() && $forUserName === '' ) {
            $out->showErrorPage( 'pharmacopedia-login-required-title',
                'pharmacopedia-login-required' );
            return;
        }

        $this->isOwner = $isOwner;
        $this->viewerIsOwner = $isOwner;  // legacy alias
        if ( $key === '' ) {
            $out->setPageTitle( 'My assessments' );
            $out->addWikiTextAsInterface(
                "Choose an assessment: [[Special:MyAssessment/cati|CATI]]" .
                "[[Special:MyAssessment/mbti|MBTI]] · " .
                "[[Special:MyAssessment/enneagram|Enneagram]] · " .
                "[[Special:MyAssessment/pid5bf|PID-5-BF]] · " .
                "[[Special:MyAssessment/catq|CAT-Q]] &middot; [[Special:MyAssessment/ocean|OCEAN]]"
            );
            return;
        }
        $tests = [ 'cati' => 'CATI', 'mbti' => 'MBTI', 'enneagram' => 'Enneagram',
                   'catq' => 'CAT-Q', 'pid5bf' => 'PID-5-BF', 'ocean' => 'OCEAN' ];
        if ( isset( $tests[$key] ) && $this->isOwner ) {
            $out->setSubtitle( $this->renderShareChip( $key, $tests[$key] ) );
        }
        if ( $key === 'cati' ) {
            $this->renderCatiReport( $user );
            return;
        }
        if ( $key === 'mbti' ) {
            $this->renderMbtiReport( $user );
            return;
        }
        if ( $key === 'enneagram' ) {
            $this->renderEnneagramReport( $user );
            return;
        }
        if ( $key === 'catq' ) {
            $this->renderCatqReport( $user );
            return;
        }
        if ( $key === 'pid5bf' ) {
            $this->renderPid5bfReport( $user );
            return;
        }
        if ( $key === 'nfcs' ) {
            $this->renderNfcsReport( $user );
            return;
        }
        if ( $key === 'bpns' ) {
            $this->renderBpnsReport( $user );
            return;
        }
        if ( $key === 'whoqolbref' ) {
            $this->renderWhoqolBrefReport( $user );
            return;
        }
        if ( $key === 'ocean' || $key === 'bfi10' ) {
            $this->renderOceanReport( $user );
            return;
        }
        if ( $key === 'pid5bf' ) {
            $this->renderPid5bfReport( $user );
            return;
        }
        if ( $key === 'ocean' ) {
            $this->renderOceanReport( $user );
            return;
        }
        $out->setPageTitle( 'Assessment report: ' . $key );
        $out->addHTML( Html::element( 'div', [ 'class' => 'errorbox' ],
            'No rich report yet for assessment "' . $key . '".' ) );
    }

    // ===== CATI report =====

    private function renderCatiReport( $user ) {
        $out = $this->getOutput();
        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        // ---- Load scores + raw responses + meta + demographics ----
        $scores = []; $takenAt = null;
        foreach ( $store->getFields( $profileId, 'cati', $this->visMin() ) as $f ) {
            $fk = (string)$f->pf_key;
            if ( $fk === '_vis' )     { continue; }
            if ( $fk === 'taken_at' ) { $takenAt = (string)$f->pf_value_text; continue; }
            $scores[ $fk ] = $f->pf_value_num !== null ? (float)$f->pf_value_num : null;
        }
        $rawByN = [];
        foreach ( $store->getFields( $profileId, 'cati_raw', $this->visMin() ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) !== 0 ) continue;
            $rawByN[ (int)substr( $k, 5 ) ] = [
                'num'  => $f->pf_value_num,
                'text' => $f->pf_value_text,
            ];
        }
        $sexAtBirth = null; $genderIdentity = null;
        foreach ( $store->getFields( $profileId, 'demographics', 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( $k === 'sex_at_birth' )     $sexAtBirth = $f->pf_value_text;
            if ( $k === 'gender_identity' )  $genderIdentity = $f->pf_value_text;
        }

        $out->setPageTitle( 'My CATI report' );

        if ( !$scores || !isset( $scores['total'] ) ) {
            $out->addWikiTextAsInterface(
                "No CATI scores on file. Take the test on [[Special:MyProfile]] under '''Personality &amp; autism assessments''' to see your report here."
            );
            return;
        }

        $genderKey = CatiNorms::genderKey( $sexAtBirth, $genderIdentity );
        $genderLabel = [
            'male'   => 'cis males',
            'female' => 'cis females',
            'gd'     => 'gender-diverse adults',
            'all'    => 'all adults (gender not specified)',
        ][ $genderKey ];
        $genderForLookup = $genderKey === 'all' ? 'all' : $genderKey;
        $naGroup  = 'na_'  . $genderForLookup;
        $autGroup = 'aut_' . $genderForLookup;

        // ---- Title + meta ----
        $h  = '<div class="pcp-cati-report">';
        $h .= '<p style="opacity:0.75;">';
        $h .= 'Compared to <strong>' . htmlspecialchars( $genderLabel ) . '</strong>';
        if ( $takenAt ) {
            $h .= ' · Last taken ' . htmlspecialchars( substr( $takenAt, 0, 10 ) );
        }
        $h .= ' · <a href="' . htmlspecialchars( SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() ) . '#cati-take">Retake</a>';
        $h .= '</p>';

        // ---- SECTION 1: Score summary table ----
        $h .= '<h2>CATI Results</h2>';
        $h .= $this->renderScoreTable( $scores, $genderForLookup );

        // ---- SECTION 2: Total-score cutoff comparison + plain-language ----
        $h .= '<h2>What your Total score means</h2>';
        $h .= $this->renderCutoffSection( (float)$scores['total'], $genderKey, $genderLabel );

        // ---- SECTION 3: Per-subscale narrative ----
        $h .= '<h2>Subscale interpretation</h2>';
        $h .= $this->renderSubscaleNarratives( $scores, $rawByN, $naGroup, $autGroup, $genderLabel );

        // ---- SECTION 4 + 5 gated by raw-responses visibility ----
        $canRaw = $this->canViewRaw( $store, $profile, 'cati', $this->isOwner ?? true );
        $h .= '<h2>Top-scoring items per subscale</h2>';
        if ( $canRaw ) {
            $h .= '<p style="opacity:0.75; margin-top:-0.3em;">For each subscale, the four items where your response "leaned most autistic". On reverse-keyed items, a low rating produces a high score in the autistic direction, and both your answer and the resulting score are shown.</p>';
            $h .= $this->renderTopItemsPerSubscale( $rawByN );
        } else {
            $h .= $this->renderRawPrivate();
        }

        // ---- SECTION 5: Full response table (also gated) ----
        $h .= '<h2>All 42 responses</h2>';
        if ( $canRaw ) {
            $h .= $this->renderResponseTable( $rawByN );
        } else {
            $h .= $this->renderRawPrivate();
        }

        // ---- SECTION 6: Methodology + citations ----
        $h .= '<h2>About the CATI</h2>';
        $h .= $this->renderMethodologyBlurb();

        $h .= '</div>';
        $out->addHTML( $h );
    }

    // --- Section renderers ---

    private function renderScoreTable( array $scores, string $genderForLookup ): string {
        $rows = [
            [ 'total', 'Total',                       42, 210 ],
            [ 'subscale_SOC', 'Social Interactions',          7,  35 ],
            [ 'subscale_COM', 'Communication',                7,  35 ],
            [ 'subscale_CAM', 'Social Camouflage',            7,  35 ],
            [ 'subscale_FLX', 'Cognitive (In)Flexibility',    7,  35 ],
            [ 'subscale_REG', 'Self-regulatory Behaviours',   7,  35 ],
            [ 'subscale_SEN', 'Sensory Sensitivity',          7,  35 ],
        ];
        $subToNorm = [
            'total' => 'total',
            'subscale_SOC' => 'soc', 'subscale_COM' => 'com', 'subscale_CAM' => 'cam',
            'subscale_FLX' => 'flx', 'subscale_REG' => 'reg', 'subscale_SEN' => 'sen',
        ];
        $naGroup  = 'na_'  . $genderForLookup;
        $autGroup = 'aut_' . $genderForLookup;

        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-cati-scores">';
        $h .= '<thead><tr>'
           . '<th>Scale (possible range)</th>'
           . '<th>Score</th>'
           . '<th>Percentile vs<br>non-autistic</th>'
           . '<th>Percentile vs<br>autistic</th>'
           . '<th>Descriptor</th>'
           . '</tr></thead><tbody>';

        foreach ( $rows as [ $key, $label, $lo, $hi ] ) {
            $score = isset( $scores[ $key ] ) ? (float)$scores[ $key ] : null;
            if ( $score === null ) {
                $h .= '<tr><th style="text-align:left;">' . htmlspecialchars( $label ) . ' (' . $lo . '–' . $hi . ')</th>';
                $h .= '<td colspan="4" style="opacity:0.5;">incomplete</td></tr>';
                continue;
            }
            $norm = $subToNorm[ $key ];
            $pctNa  = CatiNorms::percentileOf( $naGroup,  $norm, $score );
            $pctAut = CatiNorms::percentileOf( $autGroup, $norm, $score );

            // Descriptor logic (NovoPsych-style):
            //   "Pronounced":            pctNa >= 95 OR pctAut >= 75
            //   "Consistent with Autism": pctNa >= 75
            //   "":                       otherwise (below threshold)
            $desc = '';
            $descCls = '';
            if ( $pctNa !== null ) {
                if ( $pctNa >= 95 || ( $pctAut !== null && $pctAut >= 75 ) ) {
                    $desc = 'Pronounced'; $descCls = 'pcp-cati-pronounced';
                } elseif ( $pctNa >= 75 ) {
                    $desc = 'Consistent with Autism'; $descCls = 'pcp-cati-consistent';
                } else {
                    $desc = '-'; $descCls = '';
                }
            }

            $h .= '<tr>';
            $h .= '<th style="text-align:left;">' . htmlspecialchars( $label ) . ' (' . $lo . '–' . $hi . ')</th>';
            $h .= '<td style="text-align:center; font-weight:bold;">' . number_format( $score, $score == (int)$score ? 0 : 1 ) . '</td>';
            $h .= '<td style="text-align:center;">' . ( $pctNa  !== null ? $pctNa  . 'th' : '-' ) . '</td>';
            $h .= '<td style="text-align:center;">' . ( $pctAut !== null ? $pctAut . 'th' : '-' ) . '</td>';
            $h .= '<td style="text-align:center;" class="' . $descCls . '">' . htmlspecialchars( $desc ) . '</td>';
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        $h .= '</div>';
        return $h;
    }

    private function renderCutoffSection( float $total, string $genderKey, string $genderLabel ): string {
        // Two cutoffs available:
        //   English 2021: 134 (sens 82.71%, spec 79.00%); single overall threshold
        //   English 2025: 148 all / 139 cis-M / 141 cis-F / 156 GD; gender-specific
        $cutoffs2025 = [ 'male' => 139, 'female' => 141, 'gd' => 156, 'all' => 148 ];
        $cutoff2025  = $cutoffs2025[ $genderKey ];
        $cutoff2021  = 134;

        $aboveOverall   = $total >= $cutoff2025;
        $aboveOriginal  = $total >= $cutoff2021;

        $h = '<div class="pcp-cati-cutoff-box">';
        $h .= '<p>Your Total score: <strong>' . number_format( $total, $total == (int)$total ? 0 : 1 ) . ' / 210</strong>.</p>';
        $h .= '<ul>';
        $h .= '<li>Compared to the <strong>English 2025 gender-specific cutoff</strong> for ' . htmlspecialchars( $genderLabel ) .
              ' (≥ ' . $cutoff2025 . '): ' . ( $aboveOverall ? '<strong style="color:#7c3aed;">above</strong>' : 'below' ) . '.</li>';
        $h .= '<li>Compared to the <strong>English 2021 overall cutoff</strong> (≥ ' . $cutoff2021 . ', sensitivity 82.7% / specificity 79.0%): ' .
              ( $aboveOriginal ? '<strong style="color:#7c3aed;">above</strong>' : 'below' ) . '.</li>';
        $h .= '</ul>';
        $h .= '<p><em>The CATI is not a diagnostic instrument.</em> A score above either cutoff indicates that your responses pattern more similarly to that of autistic adults than non-autistic adults in published samples; ' .
              'a score below does not rule out autism.</p>';
        $h .= '<details><summary>What "sensitivity" and "specificity" mean (plain language)</summary>';
        $h .= '<p><strong>Sensitivity 82.7%</strong> means: if you took 100 autistic people and ran the CATI on each, about 83 would score above the cutoff of 134 (and ~17 would score below; these are false negatives).</p>';
        $h .= '<p><strong>Specificity 79.0%</strong> means: if you took 100 non-autistic people and ran the CATI on each, about 79 would score below 134 (and ~21 would score above; these are false positives).</p>';
        $h .= '<p>The newer 2025 gender-specific cutoffs were derived to maximize discrimination within each gender group. They differ because the score distributions differ. For example, non-autistic cis females tend to score lower than non-autistic cis males, so a lower cutoff (141 vs the overall 148) better captures the autistic-vs-non-autistic boundary for cis females.</p>';
        $h .= '<p>Adapted from NeurodivUrgent (neurodivurgent.health/results).</p>';
        $h .= '</details>';
        $h .= '</div>';
        return $h;
    }

    private function renderSubscaleNarratives( array $scores, array $rawByN, string $naGroup, string $autGroup, string $genderLabel ): string {
        // Brief, factual narrative per subscale, no clinical advice.
        $blurbs = [
            'SOC' => [ 'Social Interactions',         'Desire for, and self-appraisal of, social interactions. High scores indicate a preference to avoid social contact and/or finding it stressful.' ],
            'COM' => [ 'Communication',                'Use and understanding of non-verbal communicative behaviours (facial expressions, body language, figures of speech).' ],
            'CAM' => [ 'Social Camouflage',            'Use of compensatory strategies (scripts, monitoring, mirroring) to fit in or appear non-autistic.' ],
            'FLX' => [ 'Cognitive (In)Flexibility',    'Preference for routines and predictability; difficulty adapting to unexpected changes.' ],
            'REG' => [ 'Self-regulatory Behaviours',   'Use of repetitive physical actions (rocking, fiddling, hair-stroking) to manage stress or maintain focus.' ],
            'SEN' => [ 'Sensory Sensitivity',          'Heightened sensitivity to sensory stimuli: light, sound, touch, taste, smell.' ],
        ];
        $subToNorm = [ 'SOC'=>'soc','COM'=>'com','CAM'=>'cam','FLX'=>'flx','REG'=>'reg','SEN'=>'sen' ];

        $h = '<dl class="pcp-cati-subscale-list">';
        foreach ( $blurbs as $code => [ $name, $blurb ] ) {
            $key = 'subscale_' . $code;
            if ( !isset( $scores[ $key ] ) || $scores[ $key ] === null ) continue;
            $s = (float)$scores[ $key ];
            $pctNa  = CatiNorms::percentileOf( $naGroup,  $subToNorm[ $code ], $s );
            $pctAut = CatiNorms::percentileOf( $autGroup, $subToNorm[ $code ], $s );
            $h .= '<dt><strong>' . htmlspecialchars( $name ) . '</strong>, ' . number_format( $s, $s == (int)$s ? 0 : 1 ) . ' / 35';
            if ( $pctNa !== null && $pctAut !== null ) {
                $h .= ' &middot; ' . $pctNa . 'th percentile vs non-autistic ' . htmlspecialchars( $genderLabel ) .
                      ', ' . $pctAut . 'th vs autistic ' . htmlspecialchars( $genderLabel );
            }
            $h .= '</dt><dd>' . htmlspecialchars( $blurb ) . '</dd>';
        }
        $h .= '</dl>';
        return $h;
    }

    private function renderTopItemsPerSubscale( array $rawByN ): string {
        // For each subscale, sort items by the user's "autism-direction" score
        // (= raw response after reverse-keying), then show top 4.
        $h = '<dl class="pcp-cati-top-items">';
        foreach ( Cati::SUBSCALES as $code => $def ) {
            $name = $def['label'];
            $items = $def['items'];
            // Score each item in the autism direction
            $byScore = [];
            foreach ( $items as $n ) {
                $entry = $rawByN[ $n ] ?? null;
                if ( !$entry || $entry['num'] === null ) continue;
                if ( (string)$entry['text'] === 'unsure' ) continue;
                $raw = (float)$entry['num'];
                $autDirScore = in_array( $n, Cati::REVERSE, true ) ? ( 6 - $raw ) : $raw;
                $byScore[ $n ] = $autDirScore;
            }
            arsort( $byScore );
            $top = array_slice( $byScore, 0, 4, true );
            if ( !$top ) continue;
            $h .= '<dt>' . htmlspecialchars( $name ) . '</dt><dd><ul class="pcp-cati-top-items-list">';
            foreach ( $top as $n => $autDirScore ) {
                $itemText = Cati::ITEMS[ $n ] ?? ('(item ' . $n . ')');
                $raw = $rawByN[ $n ]['num'] !== null ? (float)$rawByN[ $n ]['num'] : null;
                $rawLabel = $this->labelForRaw( $raw );
                $autInt = (int)round( $autDirScore );
                $isRev  = in_array( $n, Cati::REVERSE, true );
                $h .= '<li><strong>' . $n . '.</strong> ' . htmlspecialchars( $itemText )
                    . ' <span class="pcp-cati-top-meta">you answered <em>' . htmlspecialchars( $rawLabel ) . '</em>'
                    . ' &middot; <strong>' . $autInt . '/5</strong> in the autistic direction'
                    . ( $isRev ? ' <span class="pcp-cati-top-rev" title="reverse-keyed item">↻</span>' : '' )
                    . '</span></li>';
            }
            $h .= '</ul></dd>';
        }
        $h .= '</dl>';
        return $h;
    }

    private function labelForRaw( ?float $raw ): string {
        if ( $raw === null ) return '-';
        // Find closest 1..5 label
        $r = (int)round( $raw );
        $r = max( 1, min( 5, $r ) );
        $label = Cati::RESPONSE_LABELS[ $r ] ?? '';
        if ( abs( $raw - $r ) > 0.01 ) {
            return number_format( $raw, 2 ) . ' (~' . $label . ')';
        }
        return $label;
    }

    private function renderResponseTable( array $rawByN ): string {
        $h = '<table class="wikitable pcp-cati-response-table" style="width:100%; font-size:0.9em;">';
        $h .= '<thead><tr><th>#</th><th>Item</th>';
        foreach ( Cati::RESPONSE_LABELS as $v => $lab ) {
            $h .= '<th style="width:6em; text-align:center;">' . htmlspecialchars( $lab ) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ( Cati::ITEMS as $n => $text ) {
            $entry = $rawByN[ $n ] ?? null;
            $isUnsure = $entry && (string)( $entry['text'] ?? '' ) === 'unsure';
            $rawNum = $entry && $entry['num'] !== null ? (float)$entry['num'] : null;
            $picked = $rawNum !== null ? (int)round( $rawNum ) : null;
            $picked = $picked !== null ? max( 1, min( 5, $picked ) ) : null;
            $isReverse = in_array( $n, Cati::REVERSE, true );

            $h .= '<tr>';
            $h .= '<th style="text-align:center;">' . $n . '</th>';
            $h .= '<td>' . htmlspecialchars( $text );
            if ( $isReverse ) $h .= ' <em style="opacity:0.6;">(reverse-keyed)</em>';
            $h .= '</td>';
            foreach ( Cati::RESPONSE_LABELS as $v => $lab ) {
                $cls = '';
                if ( $isUnsure ) {
                    $cls = '';
                } elseif ( $picked === $v ) {
                    $cls = 'pcp-cati-picked';
                }
                $h .= '<td style="text-align:center;" class="' . $cls . '">' . $v . '</td>';
            }
            if ( $isUnsure ) {
                $h .= '</tr><tr><td colspan="' . ( 2 + count( Cati::RESPONSE_LABELS ) ) . '" style="text-align:center; opacity:0.55; font-style:italic;">(item ' . $n . ' answered "Not sure")</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        return $h;
    }

    private function renderMethodologyBlurb(): string {
        $h = '<p>The Comprehensive Autistic Trait Inventory (CATI) is a 42-item self-report measure of six dimensions of autistic traits, developed by Chris English and colleagues at the University of Western Australia.</p>';
        $h .= '<p>Percentiles and gender-specific cutoffs on this page are computed from the normative tables published as supplementary material with English et al. 2025 (Autism, doi:10.1177/13623613251347740; preprint and norm tables at <a href="https://osf.io/v3kf7/">osf.io/v3kf7</a>). The original CATI development paper (English et al. 2021, Mol Autism 12(1):37) used a single overall cutoff of 134 with sensitivity 82.7% and specificity 79.0%.</p>';
        $h .= '<p>The full CATI questionnaire, scoring key, and additional translations are available from the authors at <a href="https://www.cati-autism.com/">cati-autism.com</a>.</p>';
        $h .= '<p>This report draws presentational ideas from the <a href="https://novopsych.com.au/assessments/autism/comprehensive-autistic-trait-inventory-cati/">NovoPsych CATI auto-scoring report</a> and the plain-language interpretation style from <a href="https://www.neurodivurgent.health/results">NeurodivUrgent</a>. It is not a diagnostic tool.</p>';
        return $h;
    }


    // ===== CAT-Q report =====

    private function renderCatqReport( $user ) {
        $out = $this->getOutput();
        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        // ---- Load scores + raw responses + meta + demographics ----
        $scores = []; $takenAt = null;
        foreach ( $store->getFields( $profileId, 'catq', $this->visMin() ) as $f ) {
            $fk = (string)$f->pf_key;
            if ( $fk === '_vis' )     { continue; }
            if ( $fk === 'taken_at' ) { $takenAt = (string)$f->pf_value_text; continue; }
            $scores[ $fk ] = $f->pf_value_num !== null ? (float)$f->pf_value_num : null;
        }
        $rawByN = [];
        foreach ( $store->getFields( $profileId, 'catq_raw', $this->visMin() ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) !== 0 ) continue;
            $rawByN[ (int)substr( $k, 5 ) ] = [
                'num'  => $f->pf_value_num,
                'text' => $f->pf_value_text,
            ];
        }

        $out->setPageTitle( 'My CAT-Q report' );

        if ( !$scores || !isset( $scores['total'] ) ) {
            $out->addWikiTextAsInterface(
                "No CAT-Q scores on file. Take the test on [[Special:MyProfile]] under '''Personality &amp; autism assessments''' to see your report here."
            );
            return;
        }

        // ---- Title + meta ----
        $h  = '<div class="pcp-cati-report pcp-catq-report">';
        $h .= '<p style="opacity:0.75;">';
        $h .= 'Camouflaging Autistic Traits Questionnaire';
        if ( $takenAt ) {
            $h .= ' &middot; Last taken ' . htmlspecialchars( substr( $takenAt, 0, 10 ) );
        }
        $h .= ' &middot; <a href="' . htmlspecialchars( SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() ) . '#catq-take">Retake</a>';
        $h .= '</p>';

        // ---- SECTION 1: Score table ----
        $h .= '<h2>CAT-Q Results</h2>';
        $h .= $this->renderCatqScoreTable( $scores );

        // ---- SECTION 2: What your Total score means ----
        $h .= '<h2>What your Total score means</h2>';
        $h .= $this->renderCatqCutoffSection( (float)$scores['total'] );

        // ---- SECTION 3: Subscale interpretation ----
        $h .= '<h2>Subscale interpretation</h2>';
        $h .= $this->renderCatqSubscaleNarratives( $scores );

        // ---- SECTION 4 + 5 gated by raw-responses visibility ----
        $canRaw = $this->canViewRaw( $store, $profile, 'catq', $this->isOwner ?? true );
        $h .= '<h2>Top-scoring items per subscale</h2>';
        if ( $canRaw ) {
            $h .= '<p style="opacity:0.75; margin-top:-0.3em;">For each subscale, the four items where your response leaned most strongly toward camouflaging. On reverse-keyed items, a low rating produces a high score in the camouflaging direction, and both your answer and the resulting score are shown.</p>';
            $h .= $this->renderCatqTopItemsPerSubscale( $rawByN );
        } else {
            $h .= $this->renderRawPrivate();
        }

        // ---- SECTION 5: Full 25-item response table (also gated) ----
        $h .= '<h2>All 25 responses</h2>';
        if ( $canRaw ) {
            $h .= $this->renderCatqResponseTable( $rawByN );
        } else {
            $h .= $this->renderRawPrivate();
        }

        // ---- SECTION 6: Methodology + citations ----
        $h .= '<h2>About the CAT-Q</h2>';
        $h .= $this->renderCatqMethodologyBlurb();

        $h .= '</div>';
        $out->addHTML( $h );
    }

    private function renderCatqScoreTable( array $scores ): string {
        $rows = [
            // [ key,                label,           min, max, cutoffKey ]
            [ 'total',          'Total',          25, 175, 'total' ],
            [ 'subscale_CO',    'Compensation',    8,  56, 'CO'    ],
            [ 'subscale_MSK',   'Masking',         8,  56, 'MSK'   ],
            [ 'subscale_ASS',   'Assimilation',    9,  63, 'ASS'   ],
        ];
        $cutoffLabels = [
            'total' => 'Total &ge; 110',
            'CO'    => '&ge; 35',
            'ASS'   => '&ge; 40',
            'MSK'   => '(none)',
        ];
        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-cati-scores pcp-catq-scores">';
        $h .= '<thead><tr>'
            . '<th>Scale (possible range)</th>'
            . '<th>Score</th>'
            . '<th>Suggestive cutoff<br>(NeurodivUrgent)</th>'
            . '<th>Above cutoff?</th>'
            . '</tr></thead><tbody>';

        foreach ( $rows as [ $key, $label, $lo, $hi, $cutKey ] ) {
            $score = isset( $scores[ $key ] ) ? (float)$scores[ $key ] : null;
            $h .= '<tr>';
            $h .= '<th style="text-align:left;">' . htmlspecialchars( $label ) . ' (' . $lo . '&ndash;' . $hi . ')</th>';
            if ( $score === null ) {
                $h .= '<td colspan="3" style="opacity:0.5;">incomplete</td>';
                $h .= '</tr>';
                continue;
            }
            $h .= '<td style="text-align:center; font-weight:bold;">' . number_format( $score, $score == (int)$score ? 0 : 1 ) . '</td>';
            $h .= '<td style="text-align:center;">' . $cutoffLabels[ $cutKey ] . '</td>';
            $exceeds = CatqNorms::exceedsCutoff( $cutKey, $score );
            if ( $exceeds === null ) {
                $h .= '<td style="text-align:center; opacity:0.55;">no cutoff</td>';
            } elseif ( $exceeds ) {
                $h .= '<td style="text-align:center;" class="pcp-cati-pronounced">above</td>';
            } else {
                $h .= '<td style="text-align:center;">below</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        $h .= '</div>';
        return $h;
    }

    private function renderCatqCutoffSection( float $total ): string {
        $aboveNeurodiv = $total >= CatqNorms::CUTOFF_TOTAL_NEURODIV;
        $aboveHull     = $total >= CatqNorms::CUTOFF_TOTAL_HULL2019;
        $h  = '<div class="pcp-cati-cutoff-box">';
        $h .= '<p>Your Total CAT-Q score: <strong>' . number_format( $total, $total == (int)$total ? 0 : 1 ) . ' / 175</strong>.</p>';
        $h .= '<ul>';
        $h .= '<li>Compared to the <strong>NeurodivUrgent suggestive cutoff</strong> (Total &ge; ' . CatqNorms::CUTOFF_TOTAL_NEURODIV . '): '
            . ( $aboveNeurodiv ? '<strong style="color:#7c3aed;">above</strong>' : 'below' ) . '.</li>';
        $h .= '<li>Compared to the <strong>Hull 2019 original cutoff</strong> (Total &ge; ' . CatqNorms::CUTOFF_TOTAL_HULL2019 . '): '
            . ( $aboveHull ? '<strong style="color:#7c3aed;">above</strong>' : 'below' ) . '.</li>';
        $h .= '</ul>';
        $h .= '<p><em>The CAT-Q is not a diagnostic instrument.</em> Unlike the CATI, there is <strong>no published diagnostic-accuracy data</strong> for the CAT-Q. No sensitivity or specificity figures exist for distinguishing autistic from non-autistic respondents. The cutoffs above are descriptive thresholds reported in validation and community-reference work; they indicate that you scored higher than typical comparison samples, but they do not estimate the probability of being autistic.</p>';
        $h .= '<details><summary>Why the two cutoffs differ</summary>';
        $h .= '<p>The original CAT-Q validation paper (Hull et al. 2019) proposed a total-score reference of <strong>~100</strong> for elevated camouflaging, based on the mean of their autistic sample. The community resource at <a href="https://www.neurodivurgent.health/results">NeurodivUrgent</a> raises this to <strong>~110</strong> on the basis of follow-up data suggesting the lower threshold produced too many false positives in mixed populations.</p>';
        $h .= '<p>Both cutoffs should be read as <em>descriptive thresholds</em>: scoring above them means your responses pattern more like those of autistic adults in the published samples than like non-autistic adults, <em>not</em> that you are or are not autistic.</p>';
        $h .= '</details>';
        $h .= '</div>';
        return $h;
    }

    private function renderCatqSubscaleNarratives( array $scores ): string {
        $h = '<dl class="pcp-cati-subscale-list">';
        foreach ( CatqNorms::SUBSCALE_BLURBS as $code => [ $name, $blurb ] ) {
            $key = 'subscale_' . $code;
            if ( !isset( $scores[ $key ] ) || $scores[ $key ] === null ) continue;
            $s = (float)$scores[ $key ];
            $max = CatqNorms::SUBSCALE_MAX[ $code ];
            $exceeds = CatqNorms::exceedsCutoff( $code, $s );
            $h .= '<dt><strong>' . htmlspecialchars( $name ) . '</strong>, ' . number_format( $s, $s == (int)$s ? 0 : 1 ) . ' / ' . $max;
            if ( $exceeds !== null ) {
                if ( $exceeds ) {
                    $h .= ' &middot; <span class="pcp-cati-pronounced" style="padding:0 0.4em;">above cutoff</span>';
                } else {
                    $h .= ' &middot; <span style="opacity:0.7;">below cutoff</span>';
                }
            }
            $h .= '</dt><dd>' . htmlspecialchars( $blurb ) . '</dd>';
        }
        $h .= '</dl>';
        return $h;
    }

    private function renderCatqTopItemsPerSubscale( array $rawByN ): string {
        $h = '<dl class="pcp-cati-top-items">';
        foreach ( Catq::SUBSCALES as $code => $def ) {
            $name = $def['label'];
            $items = $def['items'];
            $byScore = [];
            foreach ( $items as $n ) {
                $entry = $rawByN[ $n ] ?? null;
                if ( !$entry || $entry['num'] === null ) continue;
                if ( (string)$entry['text'] === 'unsure' ) continue;
                $raw = (float)$entry['num'];
                $autDirScore = in_array( $n, Catq::REVERSE, true ) ? ( 8 - $raw ) : $raw;
                $byScore[ $n ] = $autDirScore;
            }
            arsort( $byScore );
            $top = array_slice( $byScore, 0, 4, true );
            if ( !$top ) continue;
            $h .= '<dt>' . htmlspecialchars( $name ) . '</dt><dd><ul class="pcp-cati-top-items-list">';
            foreach ( $top as $n => $autDirScore ) {
                $itemText = Catq::ITEMS[ $n ] ?? ('(item ' . $n . ')');
                $raw = $rawByN[ $n ]['num'] !== null ? (float)$rawByN[ $n ]['num'] : null;
                $rawLabel = $this->labelForRawCatq( $raw );
                $autInt = (int)round( $autDirScore );
                $isRev  = in_array( $n, Catq::REVERSE, true );
                $h .= '<li><strong>' . $n . '.</strong> ' . htmlspecialchars( $itemText )
                    . ' <span class="pcp-cati-top-meta">you answered <em>' . htmlspecialchars( $rawLabel ) . '</em>'
                    . ' &middot; <strong>' . $autInt . '/7</strong> in the camouflaging direction'
                    . ( $isRev ? ' <span class="pcp-cati-top-rev" title="reverse-keyed item">&#x21bb;</span>' : '' )
                    . '</span></li>';
            }
            $h .= '</ul></dd>';
        }
        $h .= '</dl>';
        return $h;
    }

    private function labelForRawCatq( ?float $raw ): string {
        if ( $raw === null ) return '-';
        $r = (int)round( $raw );
        $r = max( 1, min( 7, $r ) );
        $label = Catq::RESPONSE_LABELS[ $r ] ?? '';
        if ( abs( $raw - $r ) > 0.01 ) {
            return number_format( $raw, 2 ) . ' (~' . $label . ')';
        }
        return $label;
    }

    private function renderCatqResponseTable( array $rawByN ): string {
        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-cati-response-table pcp-catq-response-table" style="width:100%; font-size:0.9em;">';
        $h .= '<thead><tr><th>#</th><th>Item</th>';
        foreach ( Catq::RESPONSE_LABELS as $v => $lab ) {
            $h .= '<th style="width:4em; text-align:center;" title="' . htmlspecialchars( $lab ) . '">' . $v . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ( Catq::ITEMS as $n => $text ) {
            $entry = $rawByN[ $n ] ?? null;
            $isUnsure = $entry && (string)( $entry['text'] ?? '' ) === 'unsure';
            $rawNum = $entry && $entry['num'] !== null ? (float)$entry['num'] : null;
            $picked = $rawNum !== null ? (int)round( $rawNum ) : null;
            $picked = $picked !== null ? max( 1, min( 7, $picked ) ) : null;
            $isReverse = in_array( $n, Catq::REVERSE, true );

            $h .= '<tr>';
            $h .= '<th style="text-align:center;">' . $n . '</th>';
            $h .= '<td>' . htmlspecialchars( $text );
            if ( $isReverse ) $h .= ' <em style="opacity:0.6;">(reverse-keyed)</em>';
            $h .= '</td>';
            foreach ( Catq::RESPONSE_LABELS as $v => $lab ) {
                $cls = '';
                if ( !$isUnsure && $picked === $v ) $cls = 'pcp-cati-picked';
                $h .= '<td style="text-align:center;" class="' . $cls . '">' . $v . '</td>';
            }
            if ( $isUnsure ) {
                $h .= '</tr><tr><td colspan="' . ( 2 + count( Catq::RESPONSE_LABELS ) ) . '" style="text-align:center; opacity:0.55; font-style:italic;">(item ' . $n . ' answered &ldquo;Not sure&rdquo;)</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        $h .= '<p style="font-size:0.85em; opacity:0.7; margin-top:0.4em;">Column headers: 1 = Strongly disagree &middot; 2 = Disagree &middot; 3 = Somewhat disagree &middot; 4 = Neither agree nor disagree &middot; 5 = Somewhat agree &middot; 6 = Agree &middot; 7 = Strongly agree. (Hover any number for the full label.)</p>';
        $h .= '</div>';
        return $h;
    }

    private function renderCatqMethodologyBlurb(): string {
        $h  = '<p>The Camouflaging Autistic Traits Questionnaire (CAT-Q) is a 25-item self-report measure of social camouflaging strategies, developed by Hull, Mandy, Lai and colleagues at University College London. It assesses three factors: <strong>Compensation</strong> (substitutive strategies), <strong>Masking</strong> (active suppression of autistic-typical expression), and <strong>Assimilation</strong> (felt performance / inability to be oneself).</p>';
        $h .= '<p>Suggestive cutoffs on this page are drawn from two sources: the original validation paper (<a href="https://doi.org/10.1007/s10803-018-3792-6">Hull et al. 2019, J Autism Dev Disord 49(3):819-833</a>), which reported a Total threshold of ~100 from autistic-sample means; and <a href="https://www.neurodivurgent.health/results">NeurodivUrgent</a>, which recalibrated the Total threshold to ~110 plus subscale cutoffs of &ge; 35 (Compensation) and &ge; 40 (Assimilation). Masking has no suggestive cutoff because Hull 2019 found it the least discriminating factor.</p>';
        $h .= '<p><strong>Important caveat:</strong> Unlike the CATI, the CAT-Q does <em>not</em> have published diagnostic-accuracy figures (sensitivity / specificity / AUC for distinguishing autistic from non-autistic respondents). Cutoffs on this page describe how your scores compare to published reference samples; they do not estimate the probability that you are autistic. The CAT-Q is best read as a measure of <em>self-reported camouflaging</em>, not as a screening test.</p>';
        $h .= '<p>This report draws presentational ideas from the <a href="https://novopsych.com.au/">NovoPsych</a> auto-scoring report style and the plain-language interpretation style from <a href="https://www.neurodivurgent.health/results">NeurodivUrgent</a>.</p>';
        return $h;
    }

    // ===== End CAT-Q report =====

    // ===== PID-5-BF report =====

    private function renderPid5bfReport( $user ) {
        $out = $this->getOutput();
        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        // ---- Load scores + raw responses + meta ----
        $scores = []; $takenAt = null;
        foreach ( $store->getFields( $profileId, 'pid5bf', 0 ) as $f ) {
            $fk = (string)$f->pf_key;
            if ( $fk === '_vis' )     { continue; }
            if ( $fk === 'taken_at' ) { $takenAt = (string)$f->pf_value_text; continue; }
            $scores[ $fk ] = $f->pf_value_num !== null ? (float)$f->pf_value_num : null;
        }
        $rawByN = [];
        foreach ( $store->getFields( $profileId, 'pid5bf_raw', 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) !== 0 ) continue;
            $rawByN[ (int)substr( $k, 5 ) ] = [
                'num'  => $f->pf_value_num,
                'text' => $f->pf_value_text,
            ];
        }

        $out->setPageTitle( 'My PID-5-BF report' );

        if ( !$scores || !isset( $scores['total'] ) ) {
            $out->addWikiTextAsInterface(
                "No PID-5-BF scores on file. Take the test on [[Special:MyProfile]] under '''Personality &amp; autism assessments''' to see your report here."
            );
            return;
        }

        // ---- Title + meta ----
        $h  = '<div class="pcp-cati-report pcp-pid5bf-report">';
        $h .= '<p style="opacity:0.75;">';
        $h .= 'Personality Inventory for DSM-5, Brief Form';
        if ( $takenAt ) {
            $h .= ' &middot; Last taken ' . htmlspecialchars( substr( $takenAt, 0, 10 ) );
        }
        $h .= ' &middot; <a href="' . htmlspecialchars( SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() ) . '#pid5bf-take">Retake</a>';
        $h .= '</p>';

        // SECTION 1: Score table
        $h .= '<h2>PID-5-BF Results</h2>';
        $h .= $this->renderPid5bfScoreTable( $scores );

        // SECTION 2: What the scores mean
        $h .= '<h2>What your scores mean</h2>';
        $h .= $this->renderPid5bfCutoffSection( $scores );

        // SECTION 3: Per-subscale narratives
        $h .= '<h2>Domain interpretation</h2>';
        $h .= $this->renderPid5bfSubscaleNarratives( $scores );

        // SECTION 4 + 5 gated by raw-responses visibility
        $canRaw = $this->canViewRaw( $store, $profile, 'pid5bf', $this->isOwner ?? true );
        $h .= '<h2>Top-scoring items per domain</h2>';
        if ( $canRaw ) {
            $h .= '<p style="opacity:0.75; margin-top:-0.3em;">Within each domain, the items you endorsed most strongly. PID-5-BF has no reverse-keyed items, so a high rating directly indicates higher trait expression.</p>';
            $h .= $this->renderPid5bfTopItemsPerSubscale( $rawByN );
        } else {
            $h .= $this->renderRawPrivate();
        }

        // SECTION 5: Full response table (also gated)
        $h .= '<h2>All 25 responses</h2>';
        if ( $canRaw ) {
            $h .= $this->renderPid5bfResponseTable( $rawByN );
        } else {
            $h .= $this->renderRawPrivate();
        }

        // SECTION 6: Methodology
        $h .= '<h2>About the PID-5-BF</h2>';
        $h .= $this->renderPid5bfMethodologyBlurb();

        $h .= '</div>';
        $out->addHTML( $h );
    }

    private function renderPid5bfScoreTable( array $scores ): string {
        $rows = [
            [ 'total',        'Total (mean of all 25 items)' ],
            [ 'subscale_NA',  'Negative Affectivity' ],
            [ 'subscale_DET', 'Detachment' ],
            [ 'subscale_ANT', 'Antagonism' ],
            [ 'subscale_DIS', 'Disinhibition' ],
            [ 'subscale_PSY', 'Psychoticism' ],
        ];
        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-cati-scores pcp-pid5bf-scores">';
        $h .= '<thead><tr>'
            . '<th>Domain (range 0.0&ndash;3.0)</th>'
            . '<th>Score</th>'
            . '<th>Suggestive cutoff<br>(Krueger 2013)</th>'
            . '<th>Above cutoff?</th>'
            . '</tr></thead><tbody>';
        foreach ( $rows as [ $key, $label ] ) {
            $score = isset( $scores[ $key ] ) ? (float)$scores[ $key ] : null;
            $h .= '<tr>';
            $h .= '<th style="text-align:left;">' . htmlspecialchars( $label ) . '</th>';
            if ( $score === null ) {
                $h .= '<td colspan="3" style="opacity:0.5;">incomplete</td></tr>';
                continue;
            }
            $h .= '<td style="text-align:center; font-weight:bold;">' . number_format( $score, 2 ) . '</td>';
            $h .= '<td style="text-align:center;">&ge; 2.0</td>';
            $exceeds = Pid5bfNorms::exceedsCutoff( $score );
            if ( $exceeds ) {
                $h .= '<td style="text-align:center;" class="pcp-cati-pronounced">above</td>';
            } else {
                $h .= '<td style="text-align:center;">below</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        $h .= '</div>';
        return $h;
    }

    private function renderPid5bfCutoffSection( array $scores ): string {
        $total = isset( $scores['total'] ) ? (float)$scores['total'] : null;
        $h  = '<div class="pcp-cati-cutoff-box">';
        if ( $total !== null ) {
            $h .= '<p>Your overall mean score across all 25 items: <strong>' . number_format( $total, 2 ) . ' / 3.00</strong>.</p>';
        }
        $elevated = [];
        foreach ( Pid5bf::SUBSCALES as $sk => $def ) {
            $v = $scores[ 'subscale_' . $sk ] ?? null;
            if ( $v !== null && (float)$v >= Pid5bfNorms::CUTOFF_MEAN ) {
                $elevated[] = $def['label'];
            }
        }
        if ( $elevated ) {
            $h .= '<p>Domains at or above the suggestive cutoff (mean &ge; 2.0): <strong style="color:#7c3aed;">'
                . htmlspecialchars( implode( ', ', $elevated ) ) . '</strong>.</p>';
        } else {
            $h .= '<p>No domain reached the suggestive cutoff (mean &ge; 2.0).</p>';
        }
        $h .= '<p><em>The PID-5-BF is not a diagnostic instrument.</em> The brief form was developed as a quick personality-pathology screener, not as a stand-alone diagnostic tool. There is <strong>no published sensitivity/specificity data</strong> for the brief form against a personality-disorder ground truth. The "&ge; 2.0" reference is the APA reporting threshold for "elevated" expression on a domain; it does not estimate the probability of a personality-disorder diagnosis.</p>';
        $h .= '<details><summary>How to read these numbers</summary>';
        $h .= '<p>Each item is rated 0&ndash;3. Domain scores are the mean of their five items (so 0.0 to 3.0). The total is the mean across all 25 items.</p>';
        $h .= '<p>The 5 domains roughly correspond to maladaptive variants of the Big Five:</p>';
        $h .= '<ul>';
        $h .= '<li><strong>Negative Affectivity</strong>: maladaptive high Neuroticism</li>';
        $h .= '<li><strong>Detachment</strong>: maladaptive low Extraversion</li>';
        $h .= '<li><strong>Antagonism</strong>: maladaptive low Agreeableness</li>';
        $h .= '<li><strong>Disinhibition</strong>: maladaptive low Conscientiousness</li>';
        $h .= '<li><strong>Psychoticism</strong>: a separate dimension capturing unusual perceptions and beliefs (open to debate whether it is maladaptive high Openness or distinct).</li>';
        $h .= '</ul>';
        $h .= '<p>The PID-5-BF is keyed to the DSM-5 Section III Alternative Model of Personality Disorders and the ICD-11 PD trait model. Both frameworks are dimensional rather than categorical: they treat personality pathology as a position on continuous traits, not as a yes/no diagnosis.</p>';
        $h .= '</details>';
        $h .= '</div>';
        return $h;
    }

    private function renderPid5bfSubscaleNarratives( array $scores ): string {
        $h = '<dl class="pcp-cati-subscale-list">';
        foreach ( Pid5bfNorms::SUBSCALE_BLURBS as $code => [ $name, $blurb ] ) {
            $key = 'subscale_' . $code;
            if ( !isset( $scores[ $key ] ) || $scores[ $key ] === null ) continue;
            $s = (float)$scores[ $key ];
            $exceeds = Pid5bfNorms::exceedsCutoff( $s );
            $h .= '<dt><strong>' . htmlspecialchars( $name ) . '</strong>, ' . number_format( $s, 2 ) . ' / 3.00';
            if ( $exceeds ) {
                $h .= ' &middot; <span class="pcp-cati-pronounced" style="padding:0 0.4em;">above cutoff</span>';
            } else {
                $h .= ' &middot; <span style="opacity:0.7;">below cutoff</span>';
            }
            $h .= '</dt><dd>' . htmlspecialchars( $blurb ) . '</dd>';
        }
        $h .= '</dl>';
        return $h;
    }

    private function renderPid5bfTopItemsPerSubscale( array $rawByN ): string {
        $h = '<dl class="pcp-cati-top-items">';
        foreach ( Pid5bf::SUBSCALES as $code => $def ) {
            $name = $def['label'];
            $items = $def['items'];
            $byScore = [];
            foreach ( $items as $n ) {
                $entry = $rawByN[ $n ] ?? null;
                if ( !$entry || $entry['num'] === null ) continue;
                if ( (string)$entry['text'] === 'unsure' ) continue;
                $byScore[ $n ] = (float)$entry['num'];
            }
            arsort( $byScore );
            $top = array_slice( $byScore, 0, 4, true );
            if ( !$top ) continue;
            $h .= '<dt>' . htmlspecialchars( $name ) . '</dt><dd><ul class="pcp-cati-top-items-list">';
            foreach ( $top as $n => $score ) {
                $itemText = Pid5bf::ITEMS[ $n ] ?? ('(item ' . $n . ')');
                $rawLabel = $this->labelForRawPid5bf( $score );
                $h .= '<li><strong>' . $n . '.</strong> ' . htmlspecialchars( $itemText )
                    . ' <span class="pcp-cati-top-meta">you answered <em>' . htmlspecialchars( $rawLabel ) . '</em>'
                    . ' &middot; <strong>' . (int)round( $score ) . '/3</strong></span></li>';
            }
            $h .= '</ul></dd>';
        }
        $h .= '</dl>';
        return $h;
    }

    private function labelForRawPid5bf( ?float $raw ): string {
        if ( $raw === null ) return '-';
        $r = (int)round( $raw );
        $r = max( 0, min( 3, $r ) );
        $label = Pid5bf::RESPONSE_LABELS[ $r ] ?? '';
        if ( abs( $raw - $r ) > 0.01 ) {
            return number_format( $raw, 2 ) . ' (~' . $label . ')';
        }
        return $label;
    }

    private function renderPid5bfResponseTable( array $rawByN ): string {
        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-cati-response-table pcp-pid5bf-response-table" style="width:100%; font-size:0.9em;">';
        $h .= '<thead><tr><th>#</th><th>Item</th>';
        foreach ( Pid5bf::RESPONSE_LABELS as $v => $lab ) {
            $h .= '<th style="width:6em; text-align:center;" title="' . htmlspecialchars( $lab ) . '">' . $v . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ( Pid5bf::ITEMS as $n => $text ) {
            $entry = $rawByN[ $n ] ?? null;
            $isUnsure = $entry && (string)( $entry['text'] ?? '' ) === 'unsure';
            $rawNum = $entry && $entry['num'] !== null ? (float)$entry['num'] : null;
            $picked = $rawNum !== null ? (int)round( $rawNum ) : null;
            $picked = $picked !== null ? max( 0, min( 3, $picked ) ) : null;

            $h .= '<tr>';
            $h .= '<th style="text-align:center;">' . $n . '</th>';
            $h .= '<td>' . htmlspecialchars( $text ) . '</td>';
            foreach ( Pid5bf::RESPONSE_LABELS as $v => $lab ) {
                $cls = ( !$isUnsure && $picked === $v ) ? 'pcp-cati-picked' : '';
                $h .= '<td style="text-align:center;" class="' . $cls . '">' . $v . '</td>';
            }
            if ( $isUnsure ) {
                $h .= '</tr><tr><td colspan="' . ( 2 + count( Pid5bf::RESPONSE_LABELS ) ) . '" style="text-align:center; opacity:0.55; font-style:italic;">(item ' . $n . ' answered &ldquo;Not sure&rdquo;)</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        $h .= '<p style="font-size:0.85em; opacity:0.7; margin-top:0.4em;">Column headers: 0 = Very false or often false &middot; 1 = Sometimes or somewhat false &middot; 2 = Sometimes or somewhat true &middot; 3 = Very true or often true.</p>';
        $h .= '</div>';
        return $h;
    }

    private function renderPid5bfMethodologyBlurb(): string {
        $h  = '<p>The Personality Inventory for DSM-5, Brief Form (PID-5-BF) is a 25-item self-report measure of maladaptive personality traits, developed by Krueger, Derringer, Markon, Watson, and Skodol (2013). It is keyed to the DSM-5 Section III Alternative Model of Personality Disorders and the ICD-11 dimensional trait model.</p>';
        $h .= '<p>The brief form measures five trait domains: Negative Affectivity, Detachment, Antagonism, Disinhibition, and Psychoticism. Each domain is measured by five items rated 0&ndash;3, yielding a domain mean in the 0&ndash;3 range.</p>';
        $h .= '<p>The "elevated" cutoff used on this page (mean &ge; 2.0) is the threshold APA proposes in the brief form\'s scoring guidance for flagging a domain as worth clinical attention. It is descriptive rather than diagnostic: <strong>no published sensitivity/specificity data exists for the brief form against a personality-disorder ground truth</strong>. The full PID-5 (220 items) has more validation work; the brief form was designed as a quick screen, not a stand-alone test.</p>';
        $h .= '<p>Original development paper: <a href="https://doi.org/10.1037/per0000002">Krueger et al. 2013, Personality Disorders: Theory, Research, and Treatment, 4(3), 264-269</a>. The instrument is in the public domain; APA distributes the official scoring sheet at <a href="https://www.psychiatry.org/psychiatrists/practice/dsm/educational-resources/assessment-measures">psychiatry.org / Online Assessment Measures</a>.</p>';
        return $h;
    }

    // ===== End PID-5-BF report =====
    private function renderRawPrivate(): string {
        return '<div class="pcp-cati-cutoff-box" style="border-left-color:#666; opacity:0.85;"><p><em>The owner of this report has not shared the raw item responses publicly. Summary scores, cutoffs, and subscale interpretation are visible above; the per-item breakdown and full response table are hidden.</em></p></div>';
    }

    // ===== NFCS report =====

    private function renderNfcsReport( $user ) {
        $out = $this->getOutput();
        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        $scores = []; $takenAt = null;
        foreach ( $store->getFields( $profileId, 'nfcs', 0 ) as $f ) {
            $fk = (string)$f->pf_key;
            if ( $fk === '_vis' )     { continue; }
            if ( $fk === 'taken_at' ) { $takenAt = (string)$f->pf_value_text; continue; }
            $scores[ $fk ] = $f->pf_value_num !== null ? (float)$f->pf_value_num : null;
        }
        $rawByN = [];
        foreach ( $store->getFields( $profileId, 'nfcs_raw', 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) !== 0 ) continue;
            $rawByN[ (int)substr( $k, 5 ) ] = [
                'num'  => $f->pf_value_num,
                'text' => $f->pf_value_text,
            ];
        }

        $out->setPageTitle( 'My NFCS report' );
        if ( !$scores || !isset( $scores['total'] ) ) {
            $out->addWikiTextAsInterface(
                "No NFCS scores on file. Take the test on [[Special:MyProfile]] to see your report here."
            );
            return;
        }

        $h  = '<div class="pcp-cati-report pcp-nfcs-report">';
        $h .= '<p style="opacity:0.75;">Need for Closure Scale, brief 15-item form';
        if ( $takenAt ) {
            $h .= ' &middot; Last taken ' . htmlspecialchars( substr( $takenAt, 0, 10 ) );
        }
        $h .= ' &middot; <a href="' . htmlspecialchars( SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() ) . '#nfcs-take">Retake</a></p>';

        $h .= '<h2>NFCS Results</h2>';
        $h .= $this->renderNfcsScoreTable( $scores );

        $h .= '<h2>What your scores mean</h2>';
        $h .= $this->renderNfcsCutoffSection( $scores );

        $h .= '<h2>Facet interpretation</h2>';
        $h .= $this->renderGenericNarratives( $scores, NfcsNorms::SUBSCALE_BLURBS, NfcsNorms::SUBSCALE_MAX, false );

        $canRaw = $this->canViewRaw( $store, $profile, 'nfcs', $this->isOwner ?? true );
        $h .= '<h2>Top-scoring items per facet</h2>';
        if ( $canRaw ) {
            $h .= '<p style="opacity:0.75; margin-top:-0.3em;">Within each facet, the items you endorsed most strongly.</p>';
            $h .= $this->renderGenericTopItems( $rawByN, Nfcs::ITEMS, Nfcs::SUBSCALES, Nfcs::REVERSE, Nfcs::RESPONSE_LABELS, 6, 'closure direction' );
        } else {
            $h .= $this->renderRawPrivate();
        }

        $h .= '<h2>All 15 responses</h2>';
        if ( $canRaw ) {
            $h .= $this->renderGenericResponseTable( $rawByN, Nfcs::ITEMS, Nfcs::REVERSE, Nfcs::RESPONSE_LABELS );
        } else {
            $h .= $this->renderRawPrivate();
        }

        $h .= '<h2>About the NFCS</h2>';
        $h .= $this->renderNfcsMethodology();

        $h .= '</div>';
        $out->addHTML( $h );
    }

    private function renderNfcsScoreTable( array $scores ): string {
        $rows = [ [ 'total', 'Total (range 15-90)' ] ];
        foreach ( Nfcs::SUBSCALES as $sk => $def ) {
            $rows[] = [ 'subscale_' . $sk, $def['label'] . ' (range 3-18)' ];
        }
        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-cati-scores"><thead><tr>';
        $h .= '<th>Scale</th><th>Score</th><th>Reference band</th></tr></thead><tbody>';
        foreach ( $rows as [ $key, $label ] ) {
            $s = isset( $scores[ $key ] ) ? (float)$scores[ $key ] : null;
            $h .= '<tr><th style="text-align:left;">' . htmlspecialchars( $label ) . '</th>';
            if ( $s === null ) {
                $h .= '<td colspan="2" style="opacity:0.5;">incomplete</td></tr>';
                continue;
            }
            $h .= '<td style="text-align:center; font-weight:bold;">' . number_format( $s, $s == (int)$s ? 0 : 1 ) . '</td>';
            if ( $key === 'total' ) {
                $band = NfcsNorms::classifyTotal( $s );
                $h .= '<td style="text-align:center;">' . htmlspecialchars( $band ) . '</td>';
            } else {
                $h .= '<td style="text-align:center;">&ndash;</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table></div>';
        return $h;
    }

    private function renderNfcsCutoffSection( array $scores ): string {
        $t = isset( $scores['total'] ) ? (float)$scores['total'] : null;
        $h  = '<div class="pcp-cati-cutoff-box">';
        if ( $t !== null ) {
            $h .= '<p>Your overall NFCS-15 total: <strong>' . number_format( $t, $t == (int)$t ? 0 : 1 ) . ' / 90</strong>.</p>';
            $band = NfcsNorms::classifyTotal( $t );
            $h .= '<p>Reference band: <strong style="color:#7c3aed;">' . htmlspecialchars( $band ) . '</strong>.</p>';
        }
        $h .= '<p><em>The NFCS is not a diagnostic instrument.</em> It is a research measure of individual differences in preference for definite knowledge and aversion to ambiguity. There are no clinical cutoffs; the bands here (low / typical / high) are descriptive only.</p>';
        $h .= '</div>';
        return $h;
    }

    private function renderNfcsMethodology(): string {
        return '<p>The Need for Closure Scale (NFCS) was developed by Webster &amp; Kruglanski (1994) to measure individual differences in the desire for definite knowledge and aversion to ambiguity. The 15-item brief form (Roets &amp; Van Hiel 2011) preserves the five-facet structure of the original 42-item scale: Order, Predictability, Decisiveness, Ambiguity intolerance, and Closed-mindedness.</p>'
            . '<p>This implementation uses the brief form items in their published order, with the original 6-point Likert anchors. The brief form omits the parent NFCS\'s "Need to avoid invalidity" lie-scale items.</p>'
            . '<p>References: <a href="https://doi.org/10.1037/0022-3514.67.6.1049">Webster &amp; Kruglanski 1994, JPSP 67(6):1049-1062</a>; <a href="https://doi.org/10.1016/j.paid.2010.09.004">Roets &amp; Van Hiel 2011, PAID 50(1):90-94</a>.</p>';
    }

    // ===== End NFCS report =====


    // ===== BPNS report =====

    private function renderBpnsReport( $user ) {
        $out = $this->getOutput();
        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        $scores = []; $takenAt = null;
        foreach ( $store->getFields( $profileId, 'bpns', 0 ) as $f ) {
            $fk = (string)$f->pf_key;
            if ( $fk === '_vis' )     { continue; }
            if ( $fk === 'taken_at' ) { $takenAt = (string)$f->pf_value_text; continue; }
            $scores[ $fk ] = $f->pf_value_num !== null ? (float)$f->pf_value_num : null;
        }
        $rawByN = [];
        foreach ( $store->getFields( $profileId, 'bpns_raw', 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) !== 0 ) continue;
            $rawByN[ (int)substr( $k, 5 ) ] = [
                'num'  => $f->pf_value_num,
                'text' => $f->pf_value_text,
            ];
        }

        $out->setPageTitle( 'My BPNS report' );
        if ( !$scores || !isset( $scores['total'] ) ) {
            $out->addWikiTextAsInterface(
                "No BPNS scores on file. Take the test on [[Special:MyProfile]] to see your report here."
            );
            return;
        }

        $h  = '<div class="pcp-cati-report pcp-bpns-report">';
        $h .= '<p style="opacity:0.75;">Basic Psychological Need Satisfaction Scale, in-general form';
        if ( $takenAt ) {
            $h .= ' &middot; Last taken ' . htmlspecialchars( substr( $takenAt, 0, 10 ) );
        }
        $h .= ' &middot; <a href="' . htmlspecialchars( SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() ) . '#bpns-take">Retake</a></p>';

        $h .= '<h2>BPNS Results</h2>';
        $h .= $this->renderBpnsScoreTable( $scores );

        $h .= '<h2>What your scores mean</h2>';
        $h .= $this->renderBpnsCutoffSection( $scores );

        $h .= '<h2>Need interpretation</h2>';
        $h .= $this->renderGenericNarratives( $scores, BpnsNorms::SUBSCALE_BLURBS, BpnsNorms::SUBSCALE_MAX, true );

        $canRaw = $this->canViewRaw( $store, $profile, 'bpns', $this->isOwner ?? true );
        $h .= '<h2>Top-scoring items per need</h2>';
        if ( $canRaw ) {
            $h .= '<p style="opacity:0.75; margin-top:-0.3em;">Within each need, the items you endorsed most strongly in the satisfaction direction. Reverse-keyed items are inverted before ranking.</p>';
            $h .= $this->renderGenericTopItems( $rawByN, Bpns::ITEMS, Bpns::SUBSCALES, Bpns::REVERSE, Bpns::RESPONSE_LABELS, 7, 'satisfaction direction' );
        } else {
            $h .= $this->renderRawPrivate();
        }

        $h .= '<h2>All 21 responses</h2>';
        if ( $canRaw ) {
            $h .= $this->renderGenericResponseTable( $rawByN, Bpns::ITEMS, Bpns::REVERSE, Bpns::RESPONSE_LABELS );
        } else {
            $h .= $this->renderRawPrivate();
        }

        $h .= '<h2>About the BPNS</h2>';
        $h .= $this->renderBpnsMethodology();

        $h .= '</div>';
        $out->addHTML( $h );
    }

    private function renderBpnsScoreTable( array $scores ): string {
        $rows = [ [ 'total', 'Total mean (1-7)' ] ];
        foreach ( Bpns::SUBSCALES as $sk => $def ) {
            $rows[] = [ 'subscale_' . $sk, $def['label'] . ' (1-7)' ];
        }
        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-cati-scores"><thead><tr>';
        $h .= '<th>Need</th><th>Score</th><th>Band</th></tr></thead><tbody>';
        foreach ( $rows as [ $key, $label ] ) {
            $s = isset( $scores[ $key ] ) ? (float)$scores[ $key ] : null;
            $h .= '<tr><th style="text-align:left;">' . htmlspecialchars( $label ) . '</th>';
            if ( $s === null ) {
                $h .= '<td colspan="2" style="opacity:0.5;">incomplete</td></tr>';
                continue;
            }
            $h .= '<td style="text-align:center; font-weight:bold;">' . number_format( $s, 2 ) . '</td>';
            $band = BpnsNorms::classifySubscale( $s );
            $h .= '<td style="text-align:center;">' . htmlspecialchars( $band ) . '</td>';
            $h .= '</tr>';
        }
        $h .= '</tbody></table></div>';
        return $h;
    }

    private function renderBpnsCutoffSection( array $scores ): string {
        $h  = '<div class="pcp-cati-cutoff-box">';
        $low = [];
        foreach ( Bpns::SUBSCALES as $sk => $def ) {
            $v = $scores[ 'subscale_' . $sk ] ?? null;
            if ( $v !== null && (float)$v <= BpnsNorms::NEED_THRESHOLD_LOW ) {
                $low[] = $def['label'];
            }
        }
        if ( $low ) {
            $h .= '<p>Needs scoring at or below the suggestive "frustrated" threshold (mean &le; 4.0): <strong style="color:#7c3aed;">' . htmlspecialchars( implode( ', ', $low ) ) . '</strong>.</p>';
        } else {
            $h .= '<p>No need scored at or below the suggestive "frustrated" threshold (mean &le; 4.0).</p>';
        }
        $h .= '<p><em>The BPNS is not a diagnostic instrument.</em> Self-Determination Theory holds that satisfaction of the three basic needs (autonomy, competence, relatedness) is a precondition for psychological well-being. Low scores indicate domains where the need is currently frustrated, not a pathology in the respondent.</p>';
        $h .= '</div>';
        return $h;
    }

    private function renderBpnsMethodology(): string {
        return '<p>The Basic Psychological Need Satisfaction Scale (BPNS) operationalises Self-Determination Theory (Deci &amp; Ryan), which posits that the satisfaction of three innate psychological needs (autonomy, competence, relatedness) is a precondition for well-being and intrinsic motivation. The 21-item "in general" version assesses need satisfaction across everyday life rather than within a specific domain.</p>'
            . '<p>References: Deci &amp; Ryan; <a href="https://doi.org/10.1023/A:1025007614869">Gagn&eacute; 2003, Motivation &amp; Emotion 27(3):199-223</a>. The canonical version of this instrument is maintained at <a href="https://selfdeterminationtheory.org/">selfdeterminationtheory.org</a>.</p>';
    }

    // ===== End BPNS report =====


    // ===== WHOQOL-BREF report =====

    private function renderWhoqolBrefReport( $user ) {
        $out = $this->getOutput();
        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        $scores = []; $takenAt = null;
        foreach ( $store->getFields( $profileId, 'whoqolbref', 0 ) as $f ) {
            $fk = (string)$f->pf_key;
            if ( $fk === '_vis' )     { continue; }
            if ( $fk === 'taken_at' ) { $takenAt = (string)$f->pf_value_text; continue; }
            $scores[ $fk ] = $f->pf_value_num !== null ? (float)$f->pf_value_num : null;
        }
        $rawByN = [];
        foreach ( $store->getFields( $profileId, 'whoqolbref_raw', 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) !== 0 ) continue;
            $rawByN[ (int)substr( $k, 5 ) ] = [
                'num'  => $f->pf_value_num,
                'text' => $f->pf_value_text,
            ];
        }

        $out->setPageTitle( 'My WHOQOL-BREF report' );
        if ( !$scores || !isset( $scores['total'] ) ) {
            $out->addWikiTextAsInterface(
                "No WHOQOL-BREF scores on file. Take the test on [[Special:MyProfile]] to see your report here."
            );
            return;
        }

        $h  = '<div class="pcp-cati-report pcp-whoqolbref-report">';
        $h .= '<p style="opacity:0.75;">World Health Organization Quality of Life, Brief';
        if ( $takenAt ) {
            $h .= ' &middot; Last taken ' . htmlspecialchars( substr( $takenAt, 0, 10 ) );
        }
        $h .= ' &middot; <a href="' . htmlspecialchars( SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() ) . '#whoqolbref-take">Retake</a></p>';

        $h .= '<h2>WHOQOL-BREF Results</h2>';
        $h .= $this->renderWhoqolScoreTable( $scores );

        $h .= '<h2>What your scores mean</h2>';
        $h .= $this->renderWhoqolCutoffSection( $scores );

        $h .= '<h2>Domain interpretation</h2>';
        $h .= $this->renderGenericNarratives( $scores, WhoqolBrefNorms::SUBSCALE_BLURBS, [
            'PHY' => 100, 'PSY' => 100, 'SOC' => 100, 'ENV' => 100, 'OVR' => 100,
        ], false );

        $canRaw = $this->canViewRaw( $store, $profile, 'whoqolbref', $this->isOwner ?? true );
        $h .= '<h2>Top-scoring items per domain</h2>';
        if ( $canRaw ) {
            $h .= '<p style="opacity:0.75; margin-top:-0.3em;">Within each domain, the items you endorsed most strongly. Reverse-keyed items (Q3, Q4, Q26) are inverted before ranking.</p>';
            $h .= $this->renderGenericTopItems( $rawByN, WhoqolBref::ITEMS, WhoqolBref::SUBSCALES, WhoqolBref::REVERSE, WhoqolBref::RESPONSE_LABELS, 5, 'positive direction' );
        } else {
            $h .= $this->renderRawPrivate();
        }

        $h .= '<h2>All 26 responses</h2>';
        if ( $canRaw ) {
            $h .= $this->renderGenericResponseTable( $rawByN, WhoqolBref::ITEMS, WhoqolBref::REVERSE, WhoqolBref::RESPONSE_LABELS );
        } else {
            $h .= $this->renderRawPrivate();
        }

        $h .= '<h2>About the WHOQOL-BREF</h2>';
        $h .= $this->renderWhoqolMethodology();

        $h .= '</div>';
        $out->addHTML( $h );
    }

    private function renderWhoqolScoreTable( array $scores ): string {
        $rows = [ [ 'total', 'Total (mean of all 26 items, 0-100)' ] ];
        foreach ( WhoqolBref::SUBSCALES as $sk => $def ) {
            $rows[] = [ 'subscale_' . $sk, $def['label'] . ' (0-100)' ];
        }
        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-cati-scores"><thead><tr>';
        $h .= '<th>Domain</th><th>Score (0-100)</th><th>Reference (community mean)</th><th>Band</th></tr></thead><tbody>';
        foreach ( $rows as [ $key, $label ] ) {
            $s = isset( $scores[ $key ] ) ? (float)$scores[ $key ] : null;
            $h .= '<tr><th style="text-align:left;">' . htmlspecialchars( $label ) . '</th>';
            if ( $s === null ) {
                $h .= '<td colspan="3" style="opacity:0.5;">incomplete</td></tr>';
                continue;
            }
            $h .= '<td style="text-align:center; font-weight:bold;">' . number_format( $s, 1 ) . '</td>';
            $code = preg_replace( '/^subscale_/', '', $key );
            $ref = WhoqolBrefNorms::DOMAIN_TYPICAL_MEAN[ $code ] ?? null;
            $h .= '<td style="text-align:center;">' . ( $ref !== null ? number_format( $ref, 1 ) : '&ndash;' ) . '</td>';
            $band = WhoqolBrefNorms::classify( $s );
            $h .= '<td style="text-align:center;">' . htmlspecialchars( str_replace( '_', ' ', $band ) ) . '</td>';
            $h .= '</tr>';
        }
        $h .= '</tbody></table></div>';
        return $h;
    }

    private function renderWhoqolCutoffSection( array $scores ): string {
        $h  = '<div class="pcp-cati-cutoff-box">';
        $low = [];
        foreach ( WhoqolBref::SUBSCALES as $sk => $def ) {
            $v = $scores[ 'subscale_' . $sk ] ?? null;
            if ( $v !== null && (float)$v <= WhoqolBrefNorms::BAND_LOW ) {
                $low[] = $def['label'];
            }
        }
        if ( $low ) {
            $h .= '<p>Domains scoring at or below 50/100 (the lower-QoL band): <strong style="color:#7c3aed;">' . htmlspecialchars( implode( ', ', $low ) ) . '</strong>.</p>';
        } else {
            $h .= '<p>No domain scored at or below 50/100.</p>';
        }
        $h .= '<p><em>The WHOQOL-BREF is not a diagnostic instrument.</em> It is a self-report measure of quality of life across four life domains. The bands here (markedly low &lt; 25, low &lt; 50, typical, high &ge; 75) are descriptive cutpoints chosen for readability; they are not clinical thresholds.</p>';
        $h .= '<details><summary>How the scoring works</summary>';
        $h .= '<p>Each item is rated 1-5. Domain scores are the mean of the items in that domain (Q3, Q4, Q26 inverted first), rescaled to the 0-100 range via (mean &minus; 1) &times; 25. The total is the mean across all 26 items, also rescaled to 0-100.</p>';
        $h .= '<p>This implementation uses a single generic 5-point Likert across all items rather than the four different anchor sets used in the official WHOQOL-BREF instrument. The numeric scoring is unaffected, but the per-item phrasing of the response choices is less specific to each item.</p>';
        $h .= '</details>';
        $h .= '</div>';
        return $h;
    }

    private function renderWhoqolMethodology(): string {
        return '<p>The World Health Organization Quality of Life, Brief (WHOQOL-BREF) was developed by the WHOQOL Group as a 26-item subset of the parent WHOQOL-100, suitable for use in large surveys and clinical settings where the full 100-item form is impractical. It measures perceived quality of life across four domains (Physical health, Psychological, Social relationships, Environment), plus two general facets (overall QoL and satisfaction with health).</p>'
            . '<p>References: <a href="https://doi.org/10.1017/S0033291798006667">WHOQOL Group 1998, Psychological Medicine 28(3):551-558</a>; <a href="https://doi.org/10.1023/B:QURE.0000018486.91360.00">Skevington, Lotfy &amp; O\'Connell 2004, Quality of Life Research 13(2):299-310</a>. Community-sample reference means used on this page are from <a href="https://doi.org/10.1007/s11205-005-5552-1">Hawthorne, Herrman &amp; Murphy 2006, Social Indicators Research 77(1):37-59</a>.</p>'
            . '<p>The official instrument and detailed scoring manual are distributed by the WHO at <a href="https://www.who.int/tools/whoqol">who.int/tools/whoqol</a>.</p>';
    }

    // ===== End WHOQOL-BREF report =====


    // ===== Shared generic helpers for new reports =====

    private function renderGenericNarratives( array $scores, array $blurbs, array $subscaleMax, bool $isMean ): string {
        $h = '<dl class="pcp-cati-subscale-list">';
        foreach ( $blurbs as $code => [ $name, $blurb ] ) {
            $key = 'subscale_' . $code;
            if ( !isset( $scores[ $key ] ) || $scores[ $key ] === null ) continue;
            $s = (float)$scores[ $key ];
            $max = $subscaleMax[ $code ] ?? null;
            $h .= '<dt><strong>' . htmlspecialchars( $name ) . '</strong>, ' . number_format( $s, $isMean ? 2 : ( $s == (int)$s ? 0 : 1 ) );
            if ( $max !== null ) $h .= ' / ' . htmlspecialchars( (string)$max );
            $h .= '</dt><dd>' . htmlspecialchars( $blurb ) . '</dd>';
        }
        $h .= '</dl>';
        return $h;
    }

    private function renderGenericTopItems( array $rawByN, array $itemsMap, array $subscales, array $reverseList, array $labelMap, int $maxResp, string $directionLabel ): string {
        $h = '<dl class="pcp-cati-top-items">';
        foreach ( $subscales as $code => $def ) {
            $name = $def['label'];
            $items = $def['items'];
            $byScore = [];
            foreach ( $items as $n ) {
                $entry = $rawByN[ $n ] ?? null;
                if ( !$entry || $entry['num'] === null ) continue;
                if ( (string)$entry['text'] === 'unsure' ) continue;
                $raw = (float)$entry['num'];
                // Direction-positive score: high = stronger in the construct direction.
                $autDirScore = in_array( $n, $reverseList, true ) ? ( ( $maxResp + 1 ) - $raw ) : $raw;
                $byScore[ $n ] = $autDirScore;
            }
            arsort( $byScore );
            $top = array_slice( $byScore, 0, 4, true );
            if ( !$top ) continue;
            $h .= '<dt>' . htmlspecialchars( $name ) . '</dt><dd><ul class="pcp-cati-top-items-list">';
            foreach ( $top as $n => $score ) {
                $itemText = $itemsMap[ $n ] ?? ( '(item ' . $n . ')' );
                $raw = (float)$rawByN[ $n ]['num'];
                $rawInt = (int)round( $raw );
                $rawInt = max( min( $maxResp, $rawInt ), array_keys( $labelMap )[0] ?? 1 );
                $rawLabel = $labelMap[ $rawInt ] ?? (string)$raw;
                $autInt = (int)round( $score );
                $isRev = in_array( $n, $reverseList, true );
                $h .= '<li><strong>' . $n . '.</strong> ' . htmlspecialchars( $itemText )
                    . ' <span class="pcp-cati-top-meta">you answered <em>' . htmlspecialchars( $rawLabel ) . '</em>'
                    . ' &middot; <strong>' . $autInt . '/' . $maxResp . '</strong> in the ' . htmlspecialchars( $directionLabel )
                    . ( $isRev ? ' <span class="pcp-cati-top-rev" title="reverse-keyed item">&#x21bb;</span>' : '' )
                    . '</span></li>';
            }
            $h .= '</ul></dd>';
        }
        $h .= '</dl>';
        return $h;
    }

    private function renderGenericResponseTable( array $rawByN, array $itemsMap, array $reverseList, array $labelMap ): string {
        $maxResp = max( array_keys( $labelMap ) );
        $minResp = min( array_keys( $labelMap ) );
        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-cati-response-table" style="width:100%; font-size:0.9em;">';
        $h .= '<thead><tr><th>#</th><th>Item</th>';
        foreach ( $labelMap as $v => $lab ) {
            $h .= '<th style="width:4em; text-align:center;" title="' . htmlspecialchars( $lab ) . '">' . $v . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ( $itemsMap as $n => $text ) {
            $entry = $rawByN[ $n ] ?? null;
            $isUnsure = $entry && (string)( $entry['text'] ?? '' ) === 'unsure';
            $rawNum = $entry && $entry['num'] !== null ? (float)$entry['num'] : null;
            $picked = $rawNum !== null ? (int)round( $rawNum ) : null;
            $picked = $picked !== null ? max( $minResp, min( $maxResp, $picked ) ) : null;
            $isReverse = in_array( $n, $reverseList, true );
            $h .= '<tr>';
            $h .= '<th style="text-align:center;">' . $n . '</th>';
            $h .= '<td>' . htmlspecialchars( $text );
            if ( $isReverse ) $h .= ' <em style="opacity:0.6;">(reverse-keyed)</em>';
            $h .= '</td>';
            foreach ( $labelMap as $v => $lab ) {
                $cls = ( !$isUnsure && $picked === $v ) ? 'pcp-cati-picked' : '';
                $h .= '<td style="text-align:center;" class="' . $cls . '">' . $v . '</td>';
            }
            if ( $isUnsure ) {
                $h .= '</tr><tr><td colspan="' . ( 2 + count( $labelMap ) ) . '" style="text-align:center; opacity:0.55; font-style:italic;">(item ' . $n . ' answered &ldquo;Not sure&rdquo;)</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        $h .= '</div>';
        return $h;
    }

    // ===== End generic helpers =====

    // ===== Big Five (OCEAN) / BFI-10 report =====

    private function renderOceanReport( $user ) {
        $out = $this->getOutput();
        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        // Load OCEAN trait values (the "summary").
        $traits = [];
        foreach ( $store->getFields( $profileId, 'ocean', 0 ) as $f ) {
            $fk = (string)$f->pf_key;
            if ( $fk === '_vis' ) continue;
            if ( in_array( $fk, [ 'O', 'C', 'E', 'A', 'N' ], true ) ) {
                $traits[ $fk ] = $f->pf_value_num !== null ? (float)$f->pf_value_num : null;
            }
        }

        // Load BFI-10 raw item responses (the "raw").
        $rawByN = [];
        foreach ( $store->getFields( $profileId, 'bfi10', 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) === 0 ) {
                $rawByN[ (int)substr( $k, 5 ) ] = [
                    'num'  => $f->pf_value_num !== null ? (float)$f->pf_value_num : null,
                    'text' => $f->pf_value_text,
                ];
            }
        }

        $out->setPageTitle( 'My Big Five (OCEAN) report' );
        if ( !$traits ) {
            $out->addWikiTextAsInterface(
                "No Big Five scores on file. Take the BFI-10 on [[Special:MyProfile]] (or move the OCEAN sliders directly) to see your report here."
            );
            return;
        }

        $h  = '<div class="pcp-cati-report pcp-ocean-report">';
        $h .= '<p style="opacity:0.75;">Big Five (OCEAN), 5 trait scores 0&ndash;100. BFI-10 (Rammstedt &amp; John 2007) is the underlying 10-item calculator.';
        $h .= ' &middot; <a href="' . htmlspecialchars( SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() ) . '#bfi10-take">Retake / adjust</a></p>';

        $h .= '<h2>OCEAN Results</h2>';
        $h .= $this->renderOceanScoreTable( $traits );

        $h .= '<h2>What your scores mean</h2>';
        $h .= $this->renderOceanInterpretation( $traits );

        $h .= '<h2>Trait reference</h2>';
        $h .= $this->renderOceanTraitReference();

        // Raw item visibility (mirrors the pattern from other reports).
        $canRaw = $this->canViewRaw( $store, $profile, 'bfi10', $this->isOwner ?? true );
        $h .= '<h2>Top-scoring items per trait</h2>';
        if ( !$rawByN ) {
            $h .= '<p><em>You filled in OCEAN sliders directly without taking the BFI-10. There are no item-level responses to show here. Take the BFI-10 from your profile if you want this section to populate.</em></p>';
        } elseif ( $canRaw ) {
            $h .= '<p style="opacity:0.75; margin-top:-0.3em;">For each trait, the BFI-10 items you endorsed most strongly in that trait\'s direction. Reverse-keyed items are inverted before ranking.</p>';
            $h .= $this->renderBfi10TopItems( $rawByN );
        } else {
            $h .= $this->renderRawPrivate();
        }

        $h .= '<h2>All 10 BFI-10 responses</h2>';
        if ( !$rawByN ) {
            $h .= '<p><em>No BFI-10 item responses on file.</em></p>';
        } elseif ( $canRaw ) {
            $h .= $this->renderBfi10ResponseTable( $rawByN );
        } else {
            $h .= $this->renderRawPrivate();
        }

        $h .= '<h2>About the Big Five &amp; BFI-10</h2>';
        $h .= $this->renderOceanMethodology();

        $h .= '</div>';
        $out->addHTML( $h );
    }

    private function renderOceanScoreTable( array $traits ): string {
        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-cati-scores pcp-ocean-scores">';
        $h .= '<thead><tr><th>Trait</th><th>Score (0&ndash;100)</th><th>Band</th><th style="width:40%;">Distribution</th></tr></thead><tbody>';
        foreach ( OceanNorms::TRAIT_BLURBS as $code => $blurb ) {
            $v = $traits[ $code ] ?? null;
            $name = (string)$blurb[0];
            $h .= '<tr>';
            $h .= '<th style="text-align:left;">' . htmlspecialchars( $name ) . '</th>';
            if ( $v === null ) {
                $h .= '<td colspan="3" style="opacity:0.5;">not set</td></tr>';
                continue;
            }
            $band = OceanNorms::classify( $v );
            $bandLabel = OceanNorms::bandLabel( $band );
            $h .= '<td style="text-align:center; font-weight:bold;">' . number_format( $v, 0 ) . '</td>';
            $bandClass = $band === 'high' ? 'pcp-cati-pronounced' : ( $band === 'low' ? '' : '' );
            $h .= '<td style="text-align:center;" class="' . $bandClass . '">' . htmlspecialchars( $bandLabel ) . '</td>';
            // Distribution bar with low/avg/high bands marked
            $pos = max( 0, min( 100, (int)round( $v ) ) );
            $h .= '<td><div class="pcp-ocean-bar"><div class="pcp-ocean-bar-fill" style="width:' . $pos . '%"></div></div></td>';
            $h .= '</tr>';
        }
        $h .= '</tbody></table></div>';
        return $h;
    }

    private function renderOceanInterpretation( array $traits ): string {
        $h = '<dl class="pcp-cati-subscale-list">';
        foreach ( OceanNorms::TRAIT_BLURBS as $code => $blurb ) {
            $v = $traits[ $code ] ?? null;
            if ( $v === null ) continue;
            $name = (string)$blurb[0];
            $band = OceanNorms::classify( $v );
            $desc = OceanNorms::descriptionFor( $code, $band );
            $bandLabel = OceanNorms::bandLabel( $band );
            $h .= '<dt><strong>' . htmlspecialchars( $name ) . '</strong>, ' . number_format( $v, 0 )
                . ' &middot; <span style="opacity:0.85;">' . htmlspecialchars( $bandLabel ) . '</span></dt>';
            $h .= '<dd>' . htmlspecialchars( $desc ) . '</dd>';
        }
        $h .= '</dl>';
        return $h;
    }

    private function renderOceanTraitReference(): string {
        $h = '<dl class="pcp-cati-subscale-list">';
        foreach ( OceanNorms::TRAIT_BLURBS as $code => [ $name, $blurb ] ) {
            $h .= '<dt><strong>' . htmlspecialchars( (string)$name ) . '</strong> (' . htmlspecialchars( (string)$code ) . ')</dt>';
            $h .= '<dd>' . htmlspecialchars( (string)$blurb ) . '</dd>';
        }
        $h .= '</dl>';
        return $h;
    }

    /**
     * BFI-10 item table (from Rammstedt & John 2007). Each item maps to a Big Five
     * trait; some items are reverse-keyed. Mirrors the structure in renderOcean()
     * on Special:MyProfile to keep scoring consistent.
     */
    private function bfi10Items(): array {
        return [
            0 => [ 'is reserved',                       'E', true  ],
            1 => [ 'is generally trusting',             'A', false ],
            2 => [ 'tends to be lazy',                  'C', true  ],
            3 => [ 'is relaxed, handles stress well',   'N', true  ],
            4 => [ 'has few artistic interests',        'O', true  ],
            5 => [ 'is outgoing, sociable',             'E', false ],
            6 => [ 'tends to find fault with others',   'A', true  ],
            7 => [ 'does a thorough job',               'C', false ],
            8 => [ 'gets nervous easily',               'N', false ],
            9 => [ 'has an active imagination',         'O', false ],
        ];
    }

    private function renderBfi10TopItems( array $rawByN ): string {
        $items = $this->bfi10Items();
        $byTrait = [ 'O' => [], 'C' => [], 'E' => [], 'A' => [], 'N' => [] ];
        foreach ( $items as $idx => [ $stem, $trait, $reverse ] ) {
            $entry = $rawByN[ $idx ] ?? null;
            if ( !$entry || $entry['num'] === null ) continue;
            $raw = (float)$entry['num']; // 0-100
            $directionScore = $reverse ? ( 100 - $raw ) : $raw;
            $byTrait[ $trait ][] = [ 'idx' => $idx, 'stem' => $stem, 'reverse' => $reverse, 'raw' => $raw, 'score' => $directionScore ];
        }
        $h = '<dl class="pcp-cati-top-items">';
        foreach ( OceanNorms::TRAIT_BLURBS as $trait => $blurb ) {
            $list = $byTrait[ $trait ] ?? [];
            if ( !$list ) continue;
            usort( $list, function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
            $top = array_slice( $list, 0, 2 ); // BFI-10 has only 2 items per trait
            $h .= '<dt>' . htmlspecialchars( (string)$blurb[0] ) . '</dt><dd><ul class="pcp-cati-top-items-list">';
            foreach ( $top as $row ) {
                $rev = $row['reverse'] ? ' <span class="pcp-cati-top-rev" title="reverse-keyed item">&#x21bb;</span>' : '';
                $h .= '<li><strong>' . ( $row['idx'] + 1 ) . '.</strong> I see myself as someone who ' . htmlspecialchars( $row['stem'] )
                    . ' <span class="pcp-cati-top-meta">you rated ' . number_format( $row['raw'], 0 )
                    . '/100 &middot; <strong>' . number_format( $row['score'], 0 ) . '/100</strong> in the ' . htmlspecialchars( $trait ) . ' direction'
                    . $rev . '</span></li>';
            }
            $h .= '</ul></dd>';
        }
        $h .= '</dl>';
        return $h;
    }

    private function renderBfi10ResponseTable( array $rawByN ): string {
        $items = $this->bfi10Items();
        $h  = '<div class="pcp-cati-scores-wrap">';
        $h .= '<table class="wikitable pcp-bfi10-response-table" style="width:100%;">';
        $h .= '<thead><tr><th>#</th><th>I see myself as someone who&hellip;</th><th>Trait</th><th>Raw (0&ndash;100)</th><th>Trait-direction score</th></tr></thead><tbody>';
        foreach ( $items as $idx => [ $stem, $trait, $reverse ] ) {
            $entry = $rawByN[ $idx ] ?? null;
            if ( !$entry || $entry['num'] === null ) {
                $h .= '<tr><th style="text-align:center;">' . ( $idx + 1 ) . '</th>'
                    . '<td>' . htmlspecialchars( $stem ) . '</td>'
                    . '<td style="text-align:center;">' . htmlspecialchars( $trait ) . '</td>'
                    . '<td colspan="2" style="opacity:0.5;">not answered</td></tr>';
                continue;
            }
            $raw = (float)$entry['num'];
            $directionScore = $reverse ? ( 100 - $raw ) : $raw;
            $h .= '<tr>';
            $h .= '<th style="text-align:center;">' . ( $idx + 1 ) . '</th>';
            $h .= '<td>' . htmlspecialchars( $stem );
            if ( $reverse ) $h .= ' <em style="opacity:0.6;">(reverse-keyed)</em>';
            $h .= '</td>';
            $h .= '<td style="text-align:center;">' . htmlspecialchars( $trait ) . '</td>';
            $h .= '<td style="text-align:center;">' . number_format( $raw, 0 ) . '</td>';
            $h .= '<td style="text-align:center; font-weight:bold;">' . number_format( $directionScore, 0 ) . '</td>';
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        $h .= '<p style="font-size:0.85em; opacity:0.7; margin-top:0.4em;">Raw is your slider value 0&ndash;100 (totally disagree &harr; totally agree). Trait-direction score inverts reverse-keyed items so higher always means "more of that trait".</p>';
        $h .= '</div>';
        return $h;
    }

    private function renderOceanMethodology(): string {
        $h  = '<p>The Big Five (OCEAN) is the most widely-validated trait-personality model in academic psychology. It identifies five broadly stable dimensions that emerge from factor-analysing how people describe themselves and others: <strong>Openness, Conscientiousness, Extraversion, Agreeableness, Neuroticism</strong>. Sources: <a href="https://doi.org/10.1037/0022-3514.59.6.1216">Goldberg 1990, JPSP 59(6):1216-1229</a>; <a href="https://www.ocf.berkeley.edu/~johnlab/2008chapter.pdf">John, Naumann &amp; Soto 2008, Handbook of Personality</a>.</p>';
        $h .= '<p>The BFI-10 (<a href="https://doi.org/10.1016/j.jrp.2006.02.001">Rammstedt &amp; John 2007, J Research in Personality 41:203-212</a>) is a 10-item ultra-brief Big Five inventory designed for time-constrained surveys. Trade-offs vs longer instruments: lower per-trait reliability (Cronbach\'s &alpha; ~0.5&ndash;0.7 vs 0.8+ for full BFI-44), but ~80% retention of the factor structure. Best used for quick screening; longer instruments give better single-person estimates.</p>';
        $h .= '<p>Scoring on this wiki uses a continuous 0&ndash;100 slider per trait. Bands (Low &lt; 30, Average 30&ndash;70, High &gt; 70) are descriptive, not clinical thresholds. The Big Five model does not yield diagnostic categories.</p>';
        return $h;
    }

    // ===== End Big Five (OCEAN) / BFI-10 report =====

    // ===== MBTI report =====

    private function renderMbtiReport( $user ) {
        $out = $this->getOutput();
        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        $scores = [];
        foreach ( $store->getFields( $profileId, 'mbti', $this->visMin() ) as $f ) {
            $k = (string)$f->pf_key;
            if ( $k === '_vis' || $k === 'taken_at' ) continue;
            $scores[ $k ] = $f->pf_value_num !== null ? (float)$f->pf_value_num : null;
        }
        $rawByN = [];
        foreach ( $store->getFields( $profileId, 'mbti_raw', $this->visMin() ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) !== 0 ) continue;
            $rawByN[ (int)substr( $k, 5 ) ] = [
                'num' => $f->pf_value_num, 'text' => $f->pf_value_text,
            ];
        }

        $out->setPageTitle( 'My MBTI / Jungian-type report' );

        if ( !$scores || !isset( $scores['EI'] ) ) {
            $out->addWikiTextAsInterface(
                "No MBTI scores on file. Set them on [[Special:MyProfile]] under '''MBTI / Jungian Type''' to see your report here."
            );
            return;
        }

        $derivedType = \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::letterTypeFromScores( $scores );
        $typeName = $derivedType ? ( \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::TYPES[ $derivedType ][0] ?? '' ) : '';
        $typeDesc = $derivedType ? ( \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::TYPES[ $derivedType ][1] ?? '' ) : '';
        $funcs    = $derivedType ? ( \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::FUNCTIONS[ $derivedType ] ?? [] ) : [];

        $h  = '<div class="pcp-mbti-report">';
        $h .= '<p style="opacity:0.75;">Treated dimensionally, your scores are continuous positions on 4 axes. <a href="' .
              htmlspecialchars( \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() ) .
              '#personality">Edit on Special:MyProfile</a></p>';

        // ===== Derived type banner =====
        if ( $derivedType ) {
            $h .= '<div class="pcp-mbti-type-banner">';
            $h .= '<div class="pcp-mbti-type-letters">' . htmlspecialchars( $derivedType ) . '</div>';
            $h .= '<div class="pcp-mbti-type-meta">';
            $h .= '<strong>' . htmlspecialchars( $typeName ) . '</strong>';
            $h .= '<p>' . htmlspecialchars( $typeDesc ) . '</p>';
            $h .= '</div>';
            $h .= '</div>';
        }

        // ===== Per-axis dimensional display =====
        $h .= '<h2>The four axes</h2>';
        $h .= '<table class="wikitable pcp-mbti-axes" style="width:100%;">';
        $h .= '<thead><tr><th>Axis</th><th>Your score</th><th>Position</th><th>Interpretation</th></tr></thead><tbody>';
        foreach ( \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::DICHOTOMIES as $d => $def ) {
            $s = $scores[ $d ] ?? null;
            $axisInfo = \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::describeAxis( $d, $s );
            $h .= '<tr>';
            $h .= '<th style="text-align:left;">' . htmlspecialchars( $def['left'] . ' ' . $def['left_name'] . ' ↔ ' . $def['right'] . ' ' . $def['right_name'] ) . '</th>';
            $h .= '<td style="text-align:center; font-weight:bold;">' . ( $s === null ? '-' : number_format( $s, 2 ) ) . '</td>';
            // Position cell, letter + strength text on top, mini bar below.
            // Empty when score is exactly 0.0 (user spec, no lean = no position).
            if ( $s !== null && abs( $s ) > 0.0001 ) {
                $pct = ( ( $s + 2.0 ) / 4.0 ) * 100.0;
                $h .= '<td><div class="pcp-mbti-pos-text"><strong>' . htmlspecialchars( $axisInfo['letter'] ) . '</strong> <small style="opacity:0.7;">(' . htmlspecialchars( $axisInfo['strength'] ) . ')</small></div>';
                $h .= '<div class="pcp-mbti-axis-bar"><div class="pcp-mbti-axis-bar-fill" style="left:' . number_format( min( $pct, 50.0 ), 1 ) . '%; width:' . number_format( abs( $pct - 50.0 ), 1 ) . '%;"></div><div class="pcp-mbti-axis-bar-midpoint"></div></div></td>';
            } elseif ( $s !== null ) {
                $h .= '<td><div class="pcp-mbti-pos-text" style="opacity:0.55;"><em>balanced</em></div></td>';
            } else {
                $h .= '<td></td>';
            }
            $h .= '<td>' . htmlspecialchars( $axisInfo['phrase'] ) . '</td>';
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';

        // ===== Cognitive functions stack =====
        if ( $funcs ) {
            $h .= '<h2>Cognitive function stack</h2>';
            $h .= '<p style="opacity:0.85;">Per Jung 1921 + Beebe 1984. The four positions are: <strong>Dominant</strong> (your primary lens), <strong>Auxiliary</strong> (your support), <strong>Tertiary</strong> (less developed but available), <strong>Inferior</strong> (often a blind spot or stress-time refuge).</p>';
            $h .= '<table class="wikitable pcp-mbti-funcs"><thead><tr><th>Position</th><th>Function</th><th>Name</th></tr></thead><tbody>';
            $positions = [ 'Dominant', 'Auxiliary', 'Tertiary', 'Inferior' ];
            foreach ( $funcs as $i => $fn ) {
                $h .= '<tr><th>' . htmlspecialchars( $positions[ $i ] ?? '?' ) . '</th>';
                $h .= '<td style="text-align:center; font-weight:bold;">' . htmlspecialchars( $fn ) . '</td>';
                $h .= '<td>' . htmlspecialchars( \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::FUNCTION_NAMES[ $fn ] ?? '' ) . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }

        // ===== Big Five mapping =====
        $h .= '<h2>How MBTI maps to the Big Five (OCEAN)</h2>';
        $h .= '<p>Empirically (McCrae &amp; Costa 1989; Furnham 1996), the four MBTI dichotomies show moderate to strong correlations with four of the Big Five traits, the Big Five is widely considered the better-validated framework. Approximate correspondence:</p>';
        $h .= '<ul>';
        $h .= '<li><strong>E ↔ I</strong> ≈ <strong>Extraversion</strong> (E = high Extraversion)</li>';
        $h .= '<li><strong>N ↔ S</strong> ≈ <strong>Openness</strong> (N = high Openness)</li>';
        $h .= '<li><strong>F ↔ T</strong> ≈ <strong>Agreeableness</strong> (F = high Agreeableness)</li>';
        $h .= '<li><strong>P ↔ J</strong> ≈ <strong>Conscientiousness</strong> (P = low Conscientiousness)</li>';
        $h .= '<li>The Big Five\'s <strong>Neuroticism</strong> has no MBTI correlate.</li>';
        $h .= '</ul>';
        $h .= '<p style="opacity:0.85;">If you also have OCEAN scores on this profile, you can compare directly: high Extraversion should align with E-side scores, high Openness with N-side, etc.</p>';

        // ===== Items that pushed each axis the most =====
        if ( $rawByN ) {
            $h .= '<h2>Items that pushed each axis the most</h2>';
            $h .= '<p style="opacity:0.85;">For each dichotomy, the items where your response leaned hardest toward one pole (after directionality):</p>';
            foreach ( \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::DICHOTOMIES as $d => $def ) {
                $h .= '<h3>' . htmlspecialchars( $def['left'] . ' ' . $def['left_name'] . ' ↔ ' . $def['right'] . ' ' . $def['right_name'] ) . '</h3>';
                $byMag = [];
                foreach ( \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::ITEMS as $n => [ $left, $right, $itemDich, $rightPole ] ) {
                    if ( $itemDich !== $d ) continue;
                    if ( !isset( $rawByN[ $n ] ) || $rawByN[ $n ]['num'] === null ) continue;
                    if ( (string)$rawByN[ $n ]['text'] === 'unsure' ) continue;
                    $raw = (float)$rawByN[ $n ]['num'];
                    $byMag[ $n ] = [ 'mag' => abs( $raw - 3.0 ), 'raw' => $raw, 'left' => $left, 'right' => $right ];
                }
                uasort( $byMag, fn( $a, $b ) => $b['mag'] <=> $a['mag'] );
                $top = array_slice( $byMag, 0, 3, true );
                if ( !$top ) { $h .= '<p style="opacity:0.55;"><em>No responses for this axis yet.</em></p>'; continue; }
                $h .= '<ul>';
                foreach ( $top as $n => $info ) {
                    $sideText = $info['raw'] < 3.0 ? $info['left'] : ( $info['raw'] > 3.0 ? $info['right'] : 'neither' );
                    $h .= '<li><strong>Item ' . $n . ':</strong> "' . htmlspecialchars( $info['left'] ) . '" ↔ "' . htmlspecialchars( $info['right'] ) . '", you sat at ' . number_format( $info['raw'], 2 ) . ' (toward "' . htmlspecialchars( $sideText ) . '")</li>';
                }
                $h .= '</ul>';
            }
        }

        // ===== Caveats + literature =====
        $h .= '<h2>About the MBTI</h2>';
        $h .= '<p>The MBTI was developed by Katharine Cook Briggs and Isabel Briggs Myers, drawing on Carl Jung\'s 1921 <em>Psychological Types</em>. The official instrument is proprietary; the items used on this wiki are the Open Extended Jungian Type Scales (OEJTS) by Eric Jorgenson (2014), public-domain bipolar items at <a href="https://openpsychometrics.org/tests/OEJTS/">openpsychometrics.org/tests/OEJTS</a>.</p>';
        $h .= '<p>Academic critiques of the MBTI focus on (a) low test-retest reliability when treated as forced types, same person re-tested often crosses one or more dichotomy midpoints; (b) bimodality assumed by typing despite empirical distributions being roughly normal on each dichotomy; (c) limited predictive validity for outcomes the Big Five predicts well. The dimensional treatment used here (continuous scores on each axis, never a forced category) sidesteps the worst of these critiques.</p>';
        $h .= '<p>Recommended literature: Jung 1921 (<em>Psychological Types</em>), Myers et al. 1998 (<em>MBTI Manual 3rd ed.</em>), Quenk 2009 (<em>Was That Really Me?</em>), Beebe 2004 (cognitive function archetypes), McCrae &amp; Costa 1989 (MBTI vs Big Five), Pittenger 2005 (<em>Cautionary comments regarding the MBTI</em>).</p>';
        $h .= '<p>Cognitive function stacks per type follow the standard Jung–Myers framework as elaborated by Beebe. The Big Five correspondences cited above are from McCrae &amp; Costa\'s 1989 joint-factor analysis.</p>';

        $h .= '</div>';
        $out->addHTML( $h );
    }


    // ===== Enneagram report =====

    private function renderEnneagramReport( $user ) {
        $out = $this->getOutput();
        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        $Enn = \MediaWiki\Extension\Pharmacopedia\Assessments\Enneagram::class;

        // Load type scores + instinct scores
        $scores = [];           // type_1..type_9 + instinct_*
        foreach ( $store->getFields( $profileId, 'enneagram', $this->visMin() ) as $f ) {
            $k = (string)$f->pf_key;
            if ( $k === '_vis' || $k === 'taken_at' ) continue;
            $scores[ $k ] = $f->pf_value_num !== null ? (float)$f->pf_value_num : null;
        }
        $rawByN = [];
        foreach ( $store->getFields( $profileId, 'enneagram_raw', $this->visMin() ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) !== 0 ) continue;
            $rawByN[ (int)substr( $k, 5 ) ] = [
                'num' => $f->pf_value_num, 'text' => $f->pf_value_text,
            ];
        }

        $out->setPageTitle( 'My Enneagram report' );

        if ( !$scores || !isset( $scores['type_1'] ) ) {
            $out->addWikiTextAsInterface(
                "No Enneagram scores on file. Set them on [[Special:MyProfile]] under '''Enneagram''' to see your report here."
            );
            return;
        }

        $primary = $Enn::primaryType( $scores );
        $wing    = $primary ? $Enn::wingFor( $primary, $scores ) : null;
        $tri     = $Enn::tritype( $scores );
        $centers = $Enn::centerEnergy( $scores );
        $horn    = $Enn::groupEnergy( $scores, 'hornevian' );
        $harm    = $Enn::groupEnergy( $scores, 'harmonic' );

        $TYPES     = $Enn::TYPES;
        $INSTINCTS = $Enn::INSTINCTS;
        $STRESS    = $Enn::STRESS_LINE;
        $GROWTH    = $Enn::GROWTH_LINE;

        // Per-center color (matches profile-side palette)
        $typeColor = function ( int $t ): string {
            if ( in_array( $t, [ 5, 6, 7 ], true ) ) return '#3b82c4';      // head, blue
            if ( in_array( $t, [ 2, 3, 4 ], true ) ) return '#a855f7';      // heart, purple
            return '#d97757';                                                // body, terracotta
        };
        $typeColorSoft = function ( int $t ): string {
            if ( in_array( $t, [ 5, 6, 7 ], true ) ) return 'rgba(59,130,196,0.18)';
            if ( in_array( $t, [ 2, 3, 4 ], true ) ) return 'rgba(168,85,247,0.18)';
            return 'rgba(217,119,87,0.18)';
        };

        $h  = '<div class="pcp-enn-report">';
        $h .= '<p style="opacity:0.75;">Treated dimensionally, your scores are continuous positions on every type. <a href="' .
              htmlspecialchars( \MediaWiki\SpecialPage\SpecialPage::getTitleFor( 'MyProfile' )->getLocalURL() ) .
              '#personality">Edit on Special:MyProfile</a></p>';

        // ===== Hero banner =====
        if ( $primary ) {
            $pd = $TYPES[ $primary ];
            $pColor = $typeColor( $primary );
            $h .= '<div class="pcp-enn-hero" style="border-left:6px solid ' . $pColor . ';">';
            $h .= '<div class="pcp-enn-hero-num" style="color:' . $pColor . ';">' . $primary . '</div>';
            $h .= '<div class="pcp-enn-hero-meta">';
            $h .= '<div class="pcp-enn-hero-label">' . htmlspecialchars( $wing['label'] ) . '</div>';
            $h .= '<strong class="pcp-enn-hero-name">' . htmlspecialchars( $pd['name'] ) . '</strong>';
            $h .= '<div class="pcp-enn-hero-epithet">' . htmlspecialchars( $pd['epithet'] ) . '</div>';
            $h .= '<p>' . htmlspecialchars( $pd['descriptor'] ) . '</p>';
            if ( $tri['code'] ) {
                $h .= '<div class="pcp-enn-hero-tritype"><strong>Tritype:</strong> ' . htmlspecialchars( $tri['code'] ) . ' <small>(top type in each center, head, heart, body, ordered with primary first)</small></div>';
            }
            $h .= '</div>';
            $h .= '</div>';
        }

        // ===== Score profile (bar chart) =====
        $h .= '<h2>Your type profile</h2>';
        $h .= '<p style="opacity:0.85;">Every type carries a continuous score. Bars are ordered by your score, so the patterns most active in you sit at the top.</p>';
        $typeOrder = [];
        for ( $t = 1; $t <= 9; $t++ ) {
            $typeOrder[] = [ 't' => $t, 'score' => $scores[ 'type_' . $t ] ?? null ];
        }
        usort( $typeOrder, function ( $a, $b ) {
            $av = $a['score'] === null ? -1 : (float)$a['score'];
            $bv = $b['score'] === null ? -1 : (float)$b['score'];
            return $bv <=> $av;
        } );
        $h .= '<div class="pcp-enn-bars">';
        foreach ( $typeOrder as $row ) {
            $t = (int)$row['t'];
            $s = $row['score'];
            $pct = $s === null ? 0 : max( 0, min( 100, (float)$s ) );
            $color = $typeColor( $t );
            $td = $TYPES[ $t ];
            $isPrimary = ( $t === $primary );
            $primaryCls = $isPrimary ? ' pcp-enn-bar-primary' : '';
            $h .= '<div class="pcp-enn-bar-row' . $primaryCls . '">';
            $h .= '<div class="pcp-enn-bar-label"><span class="pcp-enn-bar-num" style="background:' . $color . ';">' . $t . '</span> ' . htmlspecialchars( $td['name'] );
            if ( $isPrimary ) $h .= ' <span class="pcp-enn-bar-tag">PRIMARY</span>';
            $h .= '</div>';
            $h .= '<div class="pcp-enn-bar-track"><div class="pcp-enn-bar-fill" style="width:' . number_format( $pct, 1 ) . '%; background:' . $color . ';"></div></div>';
            $h .= '<div class="pcp-enn-bar-val">' . ( $s === null ? '-' : number_format( (float)$s, 1 ) ) . '</div>';
            $h .= '</div>';
        }
        $h .= '</div>';

        // ===== Primary type deep-dive card =====
        if ( $primary ) {
            $pd = $TYPES[ $primary ];
            $h .= '<h2>Primary type, ' . $primary . ' · ' . htmlspecialchars( $pd['name'] ) . '</h2>';
            $h .= '<div class="pcp-enn-card" style="background:' . $typeColorSoft( $primary ) . '; border-left:4px solid ' . $typeColor( $primary ) . ';">';
            $h .= '<table class="pcp-enn-card-table"><tbody>';
            $h .= '<tr><th>Basic fear</th><td>' . htmlspecialchars( $pd['basic_fear'] ) . '</td></tr>';
            $h .= '<tr><th>Basic desire</th><td>' . htmlspecialchars( $pd['basic_desire'] ) . '</td></tr>';
            $h .= '<tr><th>Vice / Passion</th><td>' . htmlspecialchars( $pd['vice'] ) . '</td></tr>';
            $h .= '<tr><th>Virtue</th><td>' . htmlspecialchars( $pd['virtue'] ) . '</td></tr>';
            $h .= '<tr><th>Holy Idea</th><td>' . htmlspecialchars( $pd['holy_idea'] ) . '</td></tr>';
            $h .= '</tbody></table>';
            $h .= '<p><em>' . htmlspecialchars( $pd['descriptor'] ) . '</em></p>';
            $h .= '</div>';
        }

        // ===== Wing analysis =====
        if ( $primary && $wing ) {
            $h .= '<h2>Wing analysis</h2>';
            if ( $wing['wing'] !== null ) {
                $w = $wing['wing']; $o = $wing['opposite'];
                $wd = $TYPES[ $w ]; $od = $TYPES[ $o ];
                $h .= '<p>Your <strong>' . htmlspecialchars( $wing['label'] ) . '</strong> means your primary type ' . $primary . ' is most strongly colored by the adjacent type <strong>' . $w . ' · ' . htmlspecialchars( $wd['name'] ) . '</strong> (score ' . number_format( (float)$wing['wing_score'], 1 ) . '), not its other neighbor ' . $o . ' · ' . htmlspecialchars( $od['name'] ) . ' (score ' . number_format( (float)$wing['opposite_score'], 1 ) . ').</p>';
                $h .= '<p><strong>What the ' . $w . '-wing adds:</strong> ' . htmlspecialchars( $wd['descriptor'] ) . '</p>';
                $h .= '<p><strong>What you carry less of (the ' . $o . '-wing):</strong> ' . htmlspecialchars( $od['descriptor'] ) . '</p>';
            } else {
                [ $wa, $wb ] = $Enn::WINGS[ $primary ];
                $h .= '<p>Your two wings, <strong>' . $wa . ' · ' . htmlspecialchars( $TYPES[ $wa ]['name'] ) . '</strong> and <strong>' . $wb . ' · ' . htmlspecialchars( $TYPES[ $wb ]['name'] ) . '</strong>, are close enough in score (within 3 points) that neither clearly dominates. You may carry a balanced expression, drawing on both adjacent flavors situationally.</p>';
            }
        }

        // ===== Tritype interpretation =====
        if ( $tri['code'] && count( $tri['order'] ) === 3 ) {
            $h .= '<h2>Tritype, ' . htmlspecialchars( $tri['code'] ) . '</h2>';
            $h .= '<p style="opacity:0.85;">The tritype (Bergin & Fitzpatrick / Chestnut) is the top-scoring type in each center, head, heart, and body, listed with your primary first. It captures a richer profile than the primary alone, since most people have a clear lead in all three centers.</p>';
            $h .= '<div class="pcp-enn-tritype-grid">';
            foreach ( $tri['order'] as $tt ) {
                $td = $TYPES[ $tt ];
                $h .= '<div class="pcp-enn-tritype-card" style="border-top:3px solid ' . $typeColor( $tt ) . ';">';
                $h .= '<div class="pcp-enn-tritype-num" style="color:' . $typeColor( $tt ) . ';">' . $tt . '</div>';
                $h .= '<strong>' . htmlspecialchars( $td['name'] ) . '</strong>';
                $h .= '<p><small>' . htmlspecialchars( $td['descriptor'] ) . '</small></p>';
                $h .= '</div>';
            }
            $h .= '</div>';
        }

        // ===== Centers analysis =====
        $h .= '<h2>Centers, head / heart / body</h2>';
        $h .= '<p style="opacity:0.85;">The 9 types cluster into three centers, each organized around a root emotional response: the <strong>Head</strong> types (5, 6, 7) around <em>fear</em>; the <strong>Heart</strong> types (2, 3, 4) around <em>shame</em>; the <strong>Body / Gut</strong> types (8, 9, 1) around <em>anger</em>. Your average score across each center tells you which mode of processing the world is doing the most work in you.</p>';
        $h .= '<table class="wikitable pcp-enn-centers"><thead><tr><th>Center</th><th>Root affect</th><th>Types</th><th>Your mean</th><th>Strength</th></tr></thead><tbody>';
        foreach ( $Enn::CENTERS as $cKey => $cDef ) {
            $mean = $centers[ $cKey ];
            $color = $cKey === 'head' ? '#3b82c4' : ( $cKey === 'heart' ? '#a855f7' : '#d97757' );
            $pct = $mean === null ? 0 : max( 0, min( 100, (float)$mean ) );
            $h .= '<tr>';
            $h .= '<th style="color:' . $color . ';">' . htmlspecialchars( $cDef['label'] ) . '</th>';
            $h .= '<td><em>' . htmlspecialchars( $cDef['affect'] ) . '</em></td>';
            $h .= '<td>' . implode( ', ', $cDef['types'] ) . '</td>';
            $h .= '<td style="font-weight:bold; text-align:center;">' . ( $mean === null ? '-' : number_format( (float)$mean, 1 ) ) . '</td>';
            $h .= '<td><div class="pcp-enn-bar-track" style="width:140px;"><div class="pcp-enn-bar-fill" style="width:' . number_format( $pct, 1 ) . '%; background:' . $color . ';"></div></div></td>';
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        // Identify dominant center
        $domCenter = null; $domVal = -1;
        foreach ( $centers as $ck => $cv ) {
            if ( $cv !== null && (float)$cv > $domVal ) { $domVal = (float)$cv; $domCenter = $ck; }
        }
        if ( $domCenter !== null ) {
            $centerLore = [
                'head'  => 'Your dominant center is the <strong>Head</strong>. The root question your psyche keeps asking is some flavor of <em>"am I going to be safe / supported / oriented?"</em> Energy goes into thinking, planning, model-building, and managing fear. Healthy: clarity, intellectual engagement, prepared courage. Under stress: rumination, withdrawal, paranoia, or counter-phobic acting-out.',
                'heart' => 'Your dominant center is the <strong>Heart</strong>. The root question your psyche keeps asking is some flavor of <em>"who am I to other people, and am I worth what I think I am?"</em> Energy goes into image, relationship, expression, and managing shame. Healthy: warm presence, attuned care, authentic self-expression. Under stress: image-management, envy, comparing, manipulation through emotion.',
                'body'  => 'Your dominant center is the <strong>Body / Gut</strong>. The root question your psyche keeps asking is some flavor of <em>"how do I take up space and hold my ground in this world?"</em> Energy goes into instinct, action, autonomy, and managing anger. Healthy: grounded presence, direct action, capable boundaries. Under stress: rigidity, suppression, blowback rage, or numbing-out.',
            ];
            $h .= '<p>' . $centerLore[ $domCenter ] . '</p>';
        }

        // ===== Hornevian + Harmonic groups =====
        $h .= '<h2>Strategy groups</h2>';
        $h .= '<p style="opacity:0.85;">Two cross-cutting groupings reveal patterns that span types. The <strong>Hornevian</strong> grouping (after Karen Horney) is interpersonal strategy: how you move in relation to other people. The <strong>Harmonic</strong> grouping (Riso–Hudson) is how you handle conflict and unmet need.</p>';
        $h .= '<div class="pcp-enn-group-grid">';
        // Hornevian
        $h .= '<div class="pcp-enn-group-block">';
        $h .= '<h3>Hornevian</h3>';
        $h .= '<table class="wikitable" style="width:100%;"><tbody>';
        foreach ( $Enn::HORNEVIAN as $gKey => $gDef ) {
            $mean = $horn[ $gKey ];
            $pct = $mean === null ? 0 : max( 0, min( 100, (float)$mean ) );
            $h .= '<tr>';
            $h .= '<th style="text-align:left; width:35%;">' . htmlspecialchars( $gDef['label'] ) . '<br><small style="opacity:0.7;">' . implode( ', ', $gDef['types'] ) . '</small></th>';
            $h .= '<td>' . htmlspecialchars( $gDef['gloss'] ) . '</td>';
            $h .= '<td style="text-align:right; font-weight:bold; width:60px;">' . ( $mean === null ? '-' : number_format( (float)$mean, 1 ) ) . '</td>';
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        $h .= '</div>';
        // Harmonic
        $h .= '<div class="pcp-enn-group-block">';
        $h .= '<h3>Harmonic</h3>';
        $h .= '<table class="wikitable" style="width:100%;"><tbody>';
        foreach ( $Enn::HARMONIC as $gKey => $gDef ) {
            $mean = $harm[ $gKey ];
            $h .= '<tr>';
            $h .= '<th style="text-align:left; width:35%;">' . htmlspecialchars( $gDef['label'] ) . '<br><small style="opacity:0.7;">' . implode( ', ', $gDef['types'] ) . '</small></th>';
            $h .= '<td>' . htmlspecialchars( $gDef['gloss'] ) . '</td>';
            $h .= '<td style="text-align:right; font-weight:bold; width:60px;">' . ( $mean === null ? '-' : number_format( (float)$mean, 1 ) ) . '</td>';
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        $h .= '</div>';
        $h .= '</div>';

        // ===== Stress / Growth lines =====
        if ( $primary ) {
            $sT = $STRESS[ $primary ]; $gT = $GROWTH[ $primary ];
            $sScore = $scores[ 'type_' . $sT ] ?? null;
            $gScore = $scores[ 'type_' . $gT ] ?? null;
            $h .= '<h2>Stress &amp; growth lines</h2>';
            $h .= '<p style="opacity:0.85;">Inner lines on the enneagram diagram connect each type to two others: a <strong>stress line</strong> (the direction the type tends to move under pressure, often picking up the <em>average-to-unhealthy</em> traits of that other type) and a <strong>growth line</strong> (the direction it moves in health, picking up the <em>healthy</em> traits of that other type). The classic Riso–Hudson mapping:</p>';
            $h .= '<div class="pcp-enn-lines-grid">';
            // Stress
            $h .= '<div class="pcp-enn-line-card" style="border-left:4px solid #b91c1c;">';
            $h .= '<h3 style="color:#b91c1c; margin-top:0;">Under stress → ' . $sT . ' (' . htmlspecialchars( $TYPES[ $sT ]['name'] ) . ')</h3>';
            $h .= '<p>When pressed, ' . $primary . ' tends to take on the less healthy face of ' . $sT . ', <em>' . htmlspecialchars( $TYPES[ $sT ]['descriptor'] ) . '</em></p>';
            $h .= '<p><strong>Watch for:</strong> the ' . $sT . '-type vice of <em>' . htmlspecialchars( $TYPES[ $sT ]['vice'] ) . '</em> showing up in you when stretched.</p>';
            $h .= '<p style="opacity:0.85;">Your current score on type ' . $sT . ': <strong>' . ( $sScore === null ? '-' : number_format( (float)$sScore, 1 ) ) . '</strong>. A high score here may mean you carry this stress pattern even at baseline, not only under acute pressure.</p>';
            $h .= '</div>';
            // Growth
            $h .= '<div class="pcp-enn-line-card" style="border-left:4px solid #15803d;">';
            $h .= '<h3 style="color:#15803d; margin-top:0;">In growth → ' . $gT . ' (' . htmlspecialchars( $TYPES[ $gT ]['name'] ) . ')</h3>';
            $h .= '<p>In health, ' . $primary . ' integrates the strengths of ' . $gT . ', <em>' . htmlspecialchars( $TYPES[ $gT ]['descriptor'] ) . '</em></p>';
            $h .= '<p><strong>Aspirational:</strong> the ' . $gT . '-type virtue of <em>' . htmlspecialchars( $TYPES[ $gT ]['virtue'] ) . '</em> is the gift this line opens.</p>';
            $h .= '<p style="opacity:0.85;">Your current score on type ' . $gT . ': <strong>' . ( $gScore === null ? '-' : number_format( (float)$gScore, 1 ) ) . '</strong>. A high score here may mean you already access this growth pattern; a low score may mark a developmental edge worth leaning into.</p>';
            $h .= '</div>';
            $h .= '</div>';
        }

        // ===== Items that pushed each type the most =====
        if ( $rawByN ) {
            $h .= '<h2>Items that pushed each type the most</h2>';
            $h .= '<p style="opacity:0.85;">For each of the 9 types, the items where your agreement leaned hardest toward the type. (Showing top 2 per type that you answered.)</p>';
            for ( $t = 1; $t <= 9; $t++ ) {
                $td = $TYPES[ $t ];
                $tColor = $typeColor( $t );
                $byMag = [];
                foreach ( $Enn::ITEMS as $n => [ $stmt, $itemType ] ) {
                    if ( $itemType !== $t ) continue;
                    if ( !isset( $rawByN[ $n ] ) || $rawByN[ $n ]['num'] === null ) continue;
                    if ( (string)$rawByN[ $n ]['text'] === 'unsure' ) continue;
                    $byMag[ $n ] = [ 'raw' => (float)$rawByN[ $n ]['num'], 'stmt' => $stmt ];
                }
                uasort( $byMag, fn( $a, $b ) => $b['raw'] <=> $a['raw'] );
                $top = array_slice( $byMag, 0, 2, true );
                $h .= '<h3 style="color:' . $tColor . '; margin-bottom:4px;">' . $t . ' · ' . htmlspecialchars( $td['name'] ) . '</h3>';
                if ( !$top ) { $h .= '<p style="opacity:0.55;"><em>No responses for this type yet.</em></p>'; continue; }
                $h .= '<ul>';
                foreach ( $top as $n => $info ) {
                    $h .= '<li><strong>Item ' . $n . ' (' . number_format( $info['raw'], 2 ) . '/5):</strong> "' . htmlspecialchars( $info['stmt'] ) . '"</li>';
                }
                $h .= '</ul>';
            }
        }

        // ===== Cross-system mappings =====
        $h .= '<h2>How the Enneagram maps onto other systems</h2>';
        $h .= '<p>The Enneagram is not a perfect overlay on the Big Five or MBTI, but empirical work (Wagner 1981 dissertation; Sutton et al. 2018 meta-analysis in <em>Personality &amp; Individual Differences</em>) finds reasonably consistent correlations. Rough mappings:</p>';
        $h .= '<table class="wikitable" style="width:100%;"><thead><tr><th>Type</th><th>Big Five (OCEAN) tendency</th><th>MBTI affinities</th></tr></thead><tbody>';
        $mappings = [
            1 => [ 'High Conscientiousness; lower Openness; somewhat lower Agreeableness when critical edge dominates.', 'ISTJ, ESTJ, INTJ' ],
            2 => [ 'High Agreeableness; high Extraversion; moderate Neuroticism.',                                       'ESFJ, ENFJ, ISFJ' ],
            3 => [ 'High Extraversion; high Conscientiousness; lower Agreeableness when image-driven.',                  'ESTJ, ENTJ, ENFJ' ],
            4 => [ 'High Neuroticism; high Openness; introverted lean.',                                                 'INFP, ISFP, INFJ' ],
            5 => [ 'High Openness; very low Extraversion; lower Agreeableness.',                                         'INTP, INTJ, ISTP' ],
            6 => [ 'High Neuroticism; high Conscientiousness; variable Extraversion.',                                   'ISFJ, ISTJ, ESFJ' ],
            7 => [ 'High Extraversion; high Openness; lower Conscientiousness; low Neuroticism.',                        'ENTP, ENFP, ESFP' ],
            8 => [ 'Low Agreeableness; high Extraversion; high Conscientiousness; low Neuroticism.',                     'ENTJ, ESTP, ESTJ' ],
            9 => [ 'High Agreeableness; low Neuroticism; introverted lean; moderate-low Conscientiousness.',             'ISFP, INFP, ISFJ' ],
        ];
        foreach ( $mappings as $t => [ $big5, $mb ] ) {
            $tColor = $typeColor( $t );
            $isP = ( $t === $primary );
            $rowStyle = $isP ? ' style="background:' . $typeColorSoft( $t ) . ';"' : '';
            $h .= '<tr' . $rowStyle . '>';
            $h .= '<th style="color:' . $tColor . ';">' . $t . ' · ' . htmlspecialchars( $TYPES[ $t ]['name'] ) . '</th>';
            $h .= '<td>' . htmlspecialchars( $big5 ) . '</td>';
            $h .= '<td>' . htmlspecialchars( $mb ) . '</td>';
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        $h .= '<p style="opacity:0.85;">If you have OCEAN scores and / or an MBTI position on this profile, compare them to your primary type\'s row above for an informal triangulation. Misalignment can mean either (a) the Enneagram primary is actually a secondary or stress-line pattern, or (b) the cross-system mappings, which are rough averages, do not capture your individual profile well.</p>';

        // ===== About + caveats + literature =====
        $h .= '<h2>About the Enneagram</h2>';
        $h .= '<p>The modern Enneagram of Personality was developed by Oscar Ichazo at the Arica school in Chile (1960s–1970s), drawing on Gurdjieff-derived diagrammatic ideas and Christian-monastic and Sufi sources. It was given a psychological elaboration by the Chilean psychiatrist Claudio Naranjo at Esalen (early 1970s) and brought into the English-speaking mainstream by Don Riso (1987), Helen Palmer (1988), Riso &amp; Hudson (1996, 1999), and Beatrice Chestnut (2013). The diagram itself, a 9-pointed figure with inner lines connecting 1-4-2-8-5-7-1 and 3-6-9-3, is older, but the personality typology mapped onto it is 20th-century.</p>';
        $h .= '<p><strong>Scientific status.</strong> Standardized instruments (Wagner WEPSS, Riso–Hudson RHETI) show acceptable test-retest reliability and partial construct validity (Wagner 1981; Newgent et al. 2004; Sutton et al. 2018 meta-analysis). Critics note that (a) the 9 types are based on tradition and clinical observation rather than empirical factor analysis, (b) inter-rater reliability of typing varies, and (c) the wing / line / instinct elaborations are largely untested. The Big Five remains the better-validated trait framework. The dimensional treatment used here (continuous scores on every type, no forced primary category) is a partial answer to (a) and (b).</p>';
        $h .= '<p><strong>Recommended literature:</strong> Riso &amp; Hudson 1999, <em>The Wisdom of the Enneagram</em>; Naranjo 1990, <em>Ennea-type Structures</em>; Palmer 1988, <em>The Enneagram</em>; Chestnut 2013, <em>The Complete Enneagram</em> (esp. for instinctual subtypes); Maitri 2000, <em>The Spiritual Dimension of the Enneagram</em>; for the empirical view, Sutton 2007 and Sutton et al. 2018.</p>';
        $h .= '<p style="opacity:0.75;"><small>The items used on this wiki are content-validated screening statements grounded in the canonical Riso–Hudson type descriptions. They are <em>not</em> a standardized test, and the standard caveats apply: any single instrument is one data point, types capture central tendencies rather than people, and the most useful work happens not at "what type am I" but at "what does this pattern do <em>in me</em>, and how does it move under stress and growth."</small></p>';

        $h .= '</div>';
        $out->addHTML( $h );
    }

        protected function getGroupName() { return 'users'; }

    /**
     * Render a small Share chip for the owner only. Opens the share dialog
     * scoped to (namespace, key=null). Hidden for non-owner views.
     */
    private function renderShareChip( string $namespace, string $label ): string {
        if ( !$this->isOwner ) return '';
        return \Html::rawElement( 'a', [
            'class'      => 'pcp-share-trigger',
            'href'       => '#',
            'data-ns'    => $namespace,
            'data-label' => $label,
        ], '🔗 Share' );
    }
}
