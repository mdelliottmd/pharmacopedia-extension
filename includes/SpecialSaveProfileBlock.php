<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\SpecialPage\SpecialPage;

/**
 * Special:SaveProfileBlock — AJAX-only endpoint for inline block saves
 * triggered by the pcp-block-save.js framework.
 *
 * Accepts POST with:
 *   block = <block_name>     — which block to save (whitelisted)
 *   wpEditToken              — CSRF token
 *   <other form fields>      — block's input data, same shape as the full form
 *
 * Returns JSON: {ok:true} or {ok:false, error:"..."}
 */
class SpecialSaveProfileBlock extends SpecialPage {

    public function __construct() {
        parent::__construct( 'SaveProfileBlock' );
    }

    public function execute( $par ) {
        $this->getOutput()->disable();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );

        $request = $this->getRequest();
        if ( !$request->wasPosted() ) {
            $this->emit( [ 'ok' => false, 'error' => 'POST required' ] );
            return;
        }

        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->emit( [ 'ok' => false, 'error' => 'login required' ] );
            return;
        }
        if ( !$user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
            $this->emit( [ 'ok' => false, 'error' => 'invalid token' ] );
            return;
        }

        $block = (string)$request->getVal( 'block', '' );
        $store = new UserProfileStore();
        $profile = $store->getOrCreateForUser( $user->getId() );
        $profileId = (int)$profile->prof_id;

        try {
            switch ( $block ) {
                case 'ocean':
                    $this->saveOcean( $store, $profileId, $request );
                    break;
                case 'identity':
                    $this->saveIdentity( $store, $profileId, $request );
                    break;
                case 'demographics':
                    $this->saveDemographics( $store, $profileId, $request );
                    break;
                case 'assessment-pid5bf':
                    $this->saveAssessment( $store, $profileId, $request, 'pid5bf' );
                    break;
                case 'assessment-catq':
                    $this->saveAssessment( $store, $profileId, $request, 'catq' );
                    break;
                case 'assessment-cati':
                    $this->saveAssessment( $store, $profileId, $request, 'cati' );
                    break;
                case 'assessment-nfcs':
                    $this->saveAssessment( $store, $profileId, $request, 'nfcs' );
                    break;
                case 'assessment-bpns':
                    $this->saveAssessment( $store, $profileId, $request, 'bpns' );
                    break;
                case 'assessment-whoqolbref':
                    $this->saveAssessment( $store, $profileId, $request, 'whoqolbref' );
                    break;
                case 'assessment-asrs':
                    $this->saveAssessment( $store, $profileId, $request, 'asrs' );
                    break;
                case 'assessment-amaas':
                    $this->saveAssessment( $store, $profileId, $request, 'amaas' );
                    break;
                case 'assessment-hyd':
                    $this->saveAssessment( $store, $profileId, $request, 'hyd' );
                    break;
                case 'assessment-bsl23':
                    $this->saveAssessment( $store, $profileId, $request, 'bsl23' );
                    break;
                case 'assessment-ess':
                    $this->saveAssessment( $store, $profileId, $request, 'ess' );
                    break;
                case 'assessment-ocipcp':
                    $this->saveAssessment( $store, $profileId, $request, 'ocipcp' );
                    break;
                case 'bfi10':
                    $this->saveBfi10( $store, $profileId, $request );
                    break;
                case 'mbti':
                    $this->saveMbti( $store, $profileId, $request );
                    break;
                case 'enneagram':
                    $this->saveEnneagram( $store, $profileId, $request );
                    break;
                case 'diagnoses':
                    \MediaWiki\Extension\Pharmacopedia\SpecialMyProfile::saveDiagnoses( $store, $profileId, $request );
                    break;
                case 'meds':
                    \MediaWiki\Extension\Pharmacopedia\SpecialMyProfile::saveMeds( $store, $profileId, $request );
                    break;
                default:
                    $this->emit( [ 'ok' => false, 'error' => 'unknown block: ' . $block ] );
                    return;
            }
        } catch ( \Throwable $e ) {
            $this->emit( [ 'ok' => false, 'error' => $e->getMessage() ] );
            return;
        }

        $this->emit( [ 'ok' => true ] );
    }

    // ===== Block handlers =====

    private function saveOcean( UserProfileStore $store, int $profileId, $request ): void {
        $f = $request->getArray( 'f' ) ?: [];
        $v = $request->getArray( 'v' ) ?: [];
        $ocean = is_array( $f['ocean'] ?? null ) ? $f['ocean'] : [];
        $oceanVis = is_array( $v['ocean'] ?? null ) ? $v['ocean'] : [];
        foreach ( [ 'O', 'C', 'E', 'A', 'N' ] as $key ) {
            if ( !isset( $ocean[ $key ] ) ) continue;
            $val = (int)$ocean[ $key ];
            $val = max( 0, min( 100, $val ) );
            $vis = isset( $oceanVis[ $key ] ) ? max( 0, min( 3, (int)$oceanVis[ $key ] ) ) : 0;
            $store->setField( $profileId, 'ocean', $key, null, (float)$val, $vis );
        }
    }

    private function saveBfi10( UserProfileStore $store, int $profileId, $request ): void {
        $bfi = $request->getArray( 'bfi10' ) ?: [];
        foreach ( $bfi as $idx => $val ) {
            if ( !is_numeric( $idx ) ) continue;
            $i = (int)$idx;
            if ( $i < 0 || $i > 9 ) continue;
            $v = (int)$val;
            $v = max( 0, min( 100, $v ) );
            $store->setField( $profileId, 'bfi10', 'item_' . $i, null, (float)$v, 0 );
        }
    }

    private function saveIdentity( UserProfileStore $store, int $profileId, $request ): void {
        $alias = trim( (string)$request->getVal( 'public_alias', '' ) );
        $showDefault = max( 0, min( 3, (int)$request->getVal( 'show_default', 0 ) ) );
        $showXrOnProfile = (int)$request->getVal( 'show_xr_on_profile', 0 ) === 1 ? 1 : 0;
        $store->updateProfileMeta( $profileId,
            $alias === '' ? null : $alias, $showDefault, $showXrOnProfile );
    }

    private function saveDemographics( UserProfileStore $store, int $profileId, $request ): void {
        $f = $request->getArray( 'f' ) ?: [];
        $v = $request->getArray( 'v' ) ?: [];
        $demo = is_array( $f['demographics'] ?? null ) ? $f['demographics'] : [];
        $demoVis = is_array( $v['demographics'] ?? null ) ? $v['demographics'] : [];
        $birthdayWritten = null;
        foreach ( $demo as $key => $val ) {
            $vis = isset( $demoVis[ $key ] ) ? max( 0, min( 3, (int)$demoVis[ $key ] ) ) : 0;
            $raw = (string)$val;
            if ( $key === 'birthday' && $raw !== '' && $raw[0] === '{' ) {
                $struct = \MediaWiki\Extension\Pharmacopedia\DatePicker::parseSubmitted( $raw );
                if ( !$struct ) {
                    $store->deleteField( $profileId, 'demographics', $key );
                    continue;
                }
                $raw = json_encode( $struct, JSON_UNESCAPED_UNICODE );
                $birthdayWritten = $raw;
            }
            if ( $raw === '' ) {
                $store->deleteField( $profileId, 'demographics', $key );
                continue;
            }
            if ( is_numeric( $raw ) ) {
                $store->setField( $profileId, 'demographics', $key, null, (float)$raw, $vis );
            } else {
                $store->setField( $profileId, 'demographics', $key, $raw, null, $vis );
            }
        }
        // After demographics are saved, mirror the birthday into a keyframe event.
        // Only the date is synced on update; any user-edited title/body/tags are preserved.
        if ( $birthdayWritten !== null ) {
            ( new \MediaWiki\Extension\Pharmacopedia\LifeStoryStore() )
                ->syncBirthEvent( $profileId, $birthdayWritten );
        }
    }

    private function saveAssessment( UserProfileStore $store, int $profileId, $request, string $testKey ): void {
        $clsMap = [
            'pid5bf' => \MediaWiki\Extension\Pharmacopedia\Assessments\Pid5bf::class,
            'raadsr' => \MediaWiki\Extension\Pharmacopedia\Assessments\Raadsr::class,
            'catq'   => \MediaWiki\Extension\Pharmacopedia\Assessments\Catq::class,
            'cati'   => \MediaWiki\Extension\Pharmacopedia\Assessments\Cati::class,
            'mbti'   => \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::class,
            'enneagram' => \MediaWiki\Extension\Pharmacopedia\Assessments\Enneagram::class,
        ];
        if ( !isset( $clsMap[ $testKey ] ) ) { return; }
        $cls = $clsMap[ $testKey ];
        $rawNs = $testKey . '_raw';

        // ---- per-test visibility (tv[<key>]) ----
        $tv = $request->getArray( 'tv' ) ?: [];
        if ( isset( $tv[ $testKey ] ) ) {
            $vis = max( 0, min( 3, (int)$tv[ $testKey ] ) );
            $store->setField( $profileId, $testKey, '_vis', null, (float)$vis, $vis );
            foreach ( $store->getFields( $profileId, $testKey, 0 ) as $row ) {
                $k = (string)$row->pf_key;
                if ( $k === '_vis' ) continue;
                $store->setField( $profileId, $testKey, $k,
                    $row->pf_value_text, $row->pf_value_num, $vis );
            }
        }

        // ---- items (t[<key>][<n>] + optional t_unsure[<key>][<n>]) ----
        $t = $request->getArray( 't' ) ?: [];
        $items = is_array( $t[ $testKey ] ?? null ) ? $t[ $testKey ] : [];
        $tUnsureAll = $request->getArray( 't_unsure' ) ?: [];
        $unsureFlags = $tUnsureAll[ $testKey ] ?? [];
        $touched = false;

        $sliderBounds = [
            'pid5bf' => [ 0.0, 3.0 ],
            'catq'   => [ 1.0, 7.0 ],
            'cati'   => [ 1.0, 5.0 ],
            'mbti'   => [ 1.0, 5.0 ],
            'enneagram' => [ 1.0, 5.0 ],
        ];
        if ( isset( $sliderBounds[ $testKey ] ) ) {
            [ $lo, $hi ] = $sliderBounds[ $testKey ];
            foreach ( $cls::ITEMS as $itemN => $_ ) {
                $isUnsure = isset( $unsureFlags[ $itemN ] ) && (string)$unsureFlags[ $itemN ] === '1';
                if ( $isUnsure ) {
                    $store->setField( $profileId, $rawNs, 'item_' . $itemN, 'unsure', null, 0 );
                    $touched = true;
                    continue;
                }
                if ( !array_key_exists( $itemN, $items ) ) continue;
                $valStr = trim( (string)$items[ $itemN ] );
                if ( $valStr === '' ) continue;
                $f = (float)$valStr;
                if ( $f < $lo ) $f = $lo;
                if ( $f > $hi ) $f = $hi;
                $store->setField( $profileId, $rawNs, 'item_' . $itemN, null, $f, 0 );
                $touched = true;
            }
        } else {
            // Radio (discrete) — RAADS-R
            foreach ( $items as $itemN => $val ) {
                $itemN = (int)$itemN;
                $valStr = trim( (string)$val );
                if ( $valStr === '' ) continue;
                if ( !array_key_exists( $itemN, $cls::ITEMS ) ) continue;
                $store->setField( $profileId, $rawNs, 'item_' . $itemN, null, (float)$valStr, 0 );
                $touched = true;
            }
        }
        if ( !$touched ) { return; }

        // ---- re-score from full raw set; skip 'unsure' rows ----
        $rawAll = [];
        foreach ( $store->getFields( $profileId, $rawNs, 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) !== 0 ) continue;
            if ( $f->pf_value_num === null ) continue;
            if ( (string)$f->pf_value_text === 'unsure' ) continue;
            $rawAll[ (int)substr( $k, 5 ) ] = (float)$f->pf_value_num;
        }
        $scores = $cls::scoreResponses( $rawAll );

        // Look up per-test visibility (tv[] may have just been written above)
        $vis = 0;
        foreach ( $store->getFields( $profileId, $testKey, 0 ) as $row ) {
            if ( (string)$row->pf_key === '_vis' ) {
                $vis = (int)( $row->pf_value_num ?? 0 ); break;
            }
        }
        foreach ( $scores as $sk => $sv ) {
            if ( $sv === null ) {
                $store->deleteField( $profileId, $testKey, $sk );
            } else {
                $store->setField( $profileId, $testKey, $sk, null, (float)$sv, $vis );
            }
        }
        $now = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getPrimaryDatabase()->timestamp();
        $store->setField( $profileId, $testKey, 'taken_at', $now, null, $vis );

        // Auto-create / update a private Life Story keyframe for this assessment
        try {
            $lifeStore = new \MediaWiki\Extension\Pharmacopedia\LifeStoryStore();
            $lifeStore->upsertAssessmentKeyframe( $profileId, $testKey, $cls, $scores );
        } catch ( \Throwable $e ) {
            wfDebugLog( 'pharmacopedia', 'block-save auto-keyframe failed: ' . $e->getMessage() );
        }
    }

    private function saveMbti( UserProfileStore $store, int $profileId, $request ): void {
        // Direct dichotomy sliders (f[mbti][EI/SN/TF/JP], -2..+2) — generic OCEAN-pattern
        $f = $request->getArray( 'f' ) ?: [];
        $v = $request->getArray( 'v' ) ?: [];
        $mbtiF = is_array( $f['mbti'] ?? null ) ? $f['mbti'] : [];
        $mbtiV = is_array( $v['mbti'] ?? null ) ? $v['mbti'] : [];
        $blockVis = isset( $mbtiV['_vis'] ) ? max( 0, min( 3, (int)$mbtiV['_vis'] ) ) : 0;
        foreach ( [ 'EI', 'SN', 'TF', 'JP' ] as $d ) {
            if ( !isset( $mbtiF[ $d ] ) ) continue;
            $val = (float)$mbtiF[ $d ];
            if ( $val < -2.0 ) $val = -2.0;
            if ( $val >  2.0 ) $val =  2.0;
            $store->setField( $profileId, 'mbti', $d, null, $val, $blockVis );
        }
        $store->setField( $profileId, 'mbti', '_vis', null, (float)$blockVis, $blockVis );

        // Item responses (t[mbti][n]) + Not sure (t_unsure[mbti][n])
        $t = $request->getArray( 't' ) ?: [];
        $items = is_array( $t['mbti'] ?? null ) ? $t['mbti'] : [];
        $tUnsure = $request->getArray( 't_unsure' ) ?: [];
        $unsureFlags = $tUnsure['mbti'] ?? [];
        foreach ( \MediaWiki\Extension\Pharmacopedia\Assessments\Mbti::ITEMS as $itemN => $_ ) {
            $isUnsure = isset( $unsureFlags[ $itemN ] ) && (string)$unsureFlags[ $itemN ] === '1';
            if ( $isUnsure ) {
                $store->setField( $profileId, 'mbti_raw', 'item_' . $itemN, 'unsure', null, 0 );
                continue;
            }
            if ( !array_key_exists( $itemN, $items ) ) continue;
            $valStr = trim( (string)$items[ $itemN ] );
            if ( $valStr === '' ) continue;
            $val = (float)$valStr;
            if ( $val < 1.0 ) $val = 1.0;
            if ( $val > 5.0 ) $val = 5.0;
            $store->setField( $profileId, 'mbti_raw', 'item_' . $itemN, null, $val, 0 );
        }
    }


    private function saveEnneagram( UserProfileStore $store, int $profileId, $request ): void {
        $v = $request->getArray( 'v' ) ?: [];
        $ennV = is_array( $v['enneagram'] ?? null ) ? $v['enneagram'] : [];
        $blockVis = isset( $ennV['_vis'] ) ? max( 0, min( 3, (int)$ennV['_vis'] ) ) : 0;

        // ---- Save item responses (with 'unsure' flag) ----
        $t = $request->getArray( 't' ) ?: [];
        $items = is_array( $t['enneagram'] ?? null ) ? $t['enneagram'] : [];
        $tUnsure = $request->getArray( 't_unsure' ) ?: [];
        $unsureFlags = $tUnsure['enneagram'] ?? [];
        foreach ( \MediaWiki\Extension\Pharmacopedia\Assessments\Enneagram::ITEMS as $itemN => $_ ) {
            $isUnsure = isset( $unsureFlags[ $itemN ] ) && (string)$unsureFlags[ $itemN ] === '1';
            if ( $isUnsure ) {
                $store->setField( $profileId, 'enneagram_raw', 'item_' . $itemN, 'unsure', null, 0 );
                continue;
            }
            if ( !array_key_exists( $itemN, $items ) ) continue;
            $valStr = trim( (string)$items[ $itemN ] );
            if ( $valStr === '' ) continue;
            $val = (float)$valStr;
            if ( $val < 1.0 ) $val = 1.0;
            if ( $val > 5.0 ) $val = 5.0;
            $store->setField( $profileId, 'enneagram_raw', 'item_' . $itemN, null, $val, 0 );
        }

        // ---- Re-score from full raw set; skip 'unsure' rows ----
        $rawAll = [];
        foreach ( $store->getFields( $profileId, 'enneagram_raw', 0 ) as $f ) {
            $k = (string)$f->pf_key;
            if ( strpos( $k, 'item_' ) !== 0 ) continue;
            if ( $f->pf_value_num === null ) continue;
            if ( (string)$f->pf_value_text === 'unsure' ) continue;
            $rawAll[ (int)substr( $k, 5 ) ] = (float)$f->pf_value_num;
        }
        $scores = \MediaWiki\Extension\Pharmacopedia\Assessments\Enneagram::scoreResponses( $rawAll );
        foreach ( $scores as $sk => $sv ) {
            if ( $sv === null ) {
                $store->deleteField( $profileId, 'enneagram', $sk );
            } else {
                $store->setField( $profileId, 'enneagram', $sk, null, (float)$sv, $blockVis );
            }
        }
        // Clean up any legacy instinct_* rows from earlier installs
        foreach ( [ 'sp', 'so', 'sx' ] as $ik ) {
            $store->deleteField( $profileId, 'enneagram', 'instinct_' . $ik );
        }
        $store->setField( $profileId, 'enneagram', '_vis', null, (float)$blockVis, $blockVis );
        $now = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getPrimaryDatabase()->timestamp();
        $store->setField( $profileId, 'enneagram', 'taken_at', $now, null, $blockVis );
    }

    private function emit( array $data ): void {
        echo json_encode( $data );
    }

    public function doesWrites() { return true; }
    protected function getGroupName() { return 'users'; }
}
