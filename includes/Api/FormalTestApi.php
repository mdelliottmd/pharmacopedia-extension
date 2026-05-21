<?php
/**
 * FormalTestApi
 *
 * Action API module for the formal-testing feature.
 *
 * Actions:
 *   list   - returns catalog + caller's own scores (no token; read-only)
 *   add    - insert a new score
 *   update - update an existing score (ownership-enforced)
 *   delete - delete a score (ownership-enforced)
 *
 * @license GPL-3.0-or-later
 */

namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\Pharmacopedia\FormalTestStore;
use MediaWiki\Extension\Pharmacopedia\UserProfileStore;

class FormalTestApi extends ApiBase {

    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( 'pharmacopedia-login-required', 'notloggedin' );
        }
        $params = $this->extractRequestParams();
        $action = (string)$params['ftaction'];

        $store        = new FormalTestStore();
        $profileStore = new UserProfileStore();
        $profile      = $profileStore->getOrCreateForUser( (int)$user->getId() );
        $profId       = (int)$profile->prof_id;

        if ( $action === 'list' ) {
            $catalog = $store->getCatalog();
            $scores  = $store->getUserScores( $profId );
            $this->getResult()->addValue( null, 'catalog', $this->shapeCatalog( $catalog ) );
            $this->getResult()->addValue( null, 'scores',  $this->shapeScores( $scores ) );
            return;
        }

        // Write actions require POST + CSRF.
        if ( !$this->getRequest()->wasPosted() ) {
            $this->dieWithError( [ 'apierror-mustbeposted', $action ], 'mustbeposted' );
        }

        if ( $action === 'delete' ) {
            $utsId = (int)$params['uts_id'];
            $ok = $store->deleteScore( $utsId, $profId );
            $this->getResult()->addValue( null, 'ok', $ok ? 1 : 0 );
            return;
        }

        // Build payload from params
        $payload = [
            'test_id'       => $params['test_id']       ?? null,
            'custom_abbrev' => $params['custom_abbrev'] ?? '',
            'custom_name'   => $params['custom_name']   ?? '',
            'raw_score'     => $params['raw_score']     ?? null,
            'scaled_score'  => $params['scaled_score']  ?? null,
            'percentile'    => $params['percentile']    ?? null,
            'year_taken'    => $params['year_taken']    ?? null,
            'pass_fail'     => $params['pass_fail']     ?? null,
            'notes'         => $params['notes']         ?? '',
            'vis'           => $params['vis']           ?? 'private',
            'vis_raw'       => $params['vis_raw']       ?? null,
            'vis_pct'       => $params['vis_pct']       ?? null,
            'vis_passfail'  => $params['vis_passfail']  ?? null,
            'raw_is_estimate' => $params['raw_is_estimate'] ?? '0',
            'pct_is_estimate' => $params['pct_is_estimate'] ?? '0',
        ];

        // At least one of: test_id OR custom_name is required.
        $hasTest = isset( $payload['test_id'] ) && (int)$payload['test_id'] > 0;
        $hasCustom = trim( (string)$payload['custom_name'] ) !== '';
        if ( !$hasTest && !$hasCustom ) {
            $this->dieWithError( 'pharmacopedia-formaltest-need-name', 'badparam' );
        }

        // At least one score value is required.
        $hasValue = ( $payload['raw_score'] !== null && $payload['raw_score'] !== '' )
                 || ( $payload['scaled_score'] !== null && $payload['scaled_score'] !== '' )
                 || ( $payload['percentile'] !== null && $payload['percentile'] !== '' )
                 || ( $payload['pass_fail'] !== null && $payload['pass_fail'] !== '' );
        if ( !$hasValue ) {
            $this->dieWithError( 'pharmacopedia-formaltest-need-score', 'badparam' );
        }

        if ( $action === 'add' ) {
            $newId = $store->addScore( $profId, $payload );
            $this->getResult()->addValue( null, 'ok', $newId > 0 ? 1 : 0 );
            $this->getResult()->addValue( null, 'uts_id', $newId );
            return;
        }

        if ( $action === 'update' ) {
            $utsId = (int)$params['uts_id'];
            $ok = $store->updateScore( $utsId, $profId, $payload );
            $this->getResult()->addValue( null, 'ok', $ok ? 1 : 0 );
            return;
        }

        $this->dieWithError( 'pharmacopedia-bad-action', 'badaction' );
    }

    private function shapeCatalog( array $rows ): array {
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'ft_id'                    => (int)$r->ft_id,
                'abbrev'                   => (string)$r->ft_abbrev,
                'full_name'                => (string)$r->ft_full_name,
                'category'                 => (string)$r->ft_category,
                'score_min'                => $r->ft_score_min !== null ? (float)$r->ft_score_min : null,
                'score_max'                => $r->ft_score_max !== null ? (float)$r->ft_score_max : null,
                'score_format'             => (string)$r->ft_score_format,
                'percentile_available'     => (bool)$r->ft_percentile_available,
                'sort_key'                 => (int)$r->ft_sort_key,
                'legacy'                   => (bool)$r->ft_legacy,
                'aka'                      => $r->ft_aka !== null ? (string)$r->ft_aka : null,
                'notes'                    => $r->ft_notes !== null ? (string)$r->ft_notes : null,
            ];
        }
        return $out;
    }

    private function shapeScores( array $rows ): array {
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'uts_id'        => (int)$r->uts_id,
                'test_id'       => $r->uts_test_id !== null ? (int)$r->uts_test_id : null,
                'abbrev'        => $r->uts_test_id !== null ? (string)$r->ft_abbrev : (string)( $r->uts_custom_abbrev ?? '' ),
                'full_name'     => $r->uts_test_id !== null ? (string)$r->ft_full_name : (string)( $r->uts_custom_name ?? '' ),
                'category'      => $r->uts_test_id !== null ? (string)$r->ft_category : 'other',
                'raw_score'     => $r->uts_raw_score    !== null ? (float)$r->uts_raw_score    : null,
                'raw_is_estimate' => isset( $r->uts_raw_is_estimate ) ? (int)$r->uts_raw_is_estimate : 0,
                'scaled_score'  => $r->uts_scaled_score !== null ? (float)$r->uts_scaled_score : null,
                'percentile'    => $r->uts_percentile   !== null ? (float)$r->uts_percentile   : null,
                'pct_is_estimate' => isset( $r->uts_pct_is_estimate ) ? (int)$r->uts_pct_is_estimate : 0,
                'year_taken'    => $r->uts_year_taken   !== null ? (int)$r->uts_year_taken    : null,
                'pass_fail'     => $r->uts_pass_fail    !== null ? (int)$r->uts_pass_fail     : null,
                'notes'         => $r->uts_notes !== null ? (string)$r->uts_notes : null,
                'vis'           => (string)$r->uts_vis,
                'vis_raw'       => isset( $r->uts_vis_raw ) ? (int)$r->uts_vis_raw : 0,
                'vis_pct'       => isset( $r->uts_vis_pct ) ? (int)$r->uts_vis_pct : 0,
                'vis_passfail'  => isset( $r->uts_vis_passfail ) ? (int)$r->uts_vis_passfail : 0,
                'is_custom'     => $r->uts_test_id === null,
                'legacy'        => $r->uts_test_id !== null ? (bool)( $r->ft_legacy ?? false ) : false,
                'score_format'  => $r->uts_test_id !== null ? (string)( $r->ft_score_format ?? 'scaled' ) : 'scaled',
            ];
        }
        return $out;
    }

    public function getAllowedParams() {
        return [
            'ftaction' => [ ApiBase::PARAM_TYPE => [ 'list', 'add', 'update', 'delete' ], ApiBase::PARAM_REQUIRED => true ],
            'uts_id'        => [ ApiBase::PARAM_TYPE => 'integer' ],
            'test_id'       => [ ApiBase::PARAM_TYPE => 'string' ],
            'custom_abbrev' => [ ApiBase::PARAM_TYPE => 'string' ],
            'custom_name'   => [ ApiBase::PARAM_TYPE => 'string' ],
            'raw_score'     => [ ApiBase::PARAM_TYPE => 'string' ],
            'scaled_score'  => [ ApiBase::PARAM_TYPE => 'string' ],
            'percentile'    => [ ApiBase::PARAM_TYPE => 'string' ],
            'year_taken'    => [ ApiBase::PARAM_TYPE => 'string' ],
            'pass_fail'     => [ ApiBase::PARAM_TYPE => 'string' ],
            'notes'         => [ ApiBase::PARAM_TYPE => 'string' ],
            'raw_is_estimate' => [ ApiBase::PARAM_TYPE => 'string' ],
            'pct_is_estimate' => [ ApiBase::PARAM_TYPE => 'string' ],
            'vis'           => [ ApiBase::PARAM_TYPE => 'string' ],
            'vis_raw'       => [ ApiBase::PARAM_TYPE => 'string' ],
            'vis_pct'       => [ ApiBase::PARAM_TYPE => 'string' ],
            'vis_passfail'  => [ ApiBase::PARAM_TYPE => 'string' ],
        ];
    }

    public function isWriteMode() {
        $action = $this->getRequest()->getVal( 'ftaction', '' );
        return in_array( $action, [ 'add', 'update', 'delete' ], true );
    }
    public function needsToken() {
        $action = $this->getRequest()->getVal( 'ftaction', '' );
        return in_array( $action, [ 'add', 'update', 'delete' ], true ) ? 'csrf' : false;
    }
}
