<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\Pharmacopedia\ExperienceStore;
use MediaWiki\Title\Title;

/**
 * POST pharmacopediaexperiencesubmit
 *
 * Stages one (Personal|Clinical) Experience submission as a PENDING row. The
 * effect / indication / anecdote picks ride along as an opaque JSON payload and
 * are committed to their live systems only on moderator approval.
 */
class ExperienceSubmitApi extends ApiBase {
    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( [ 'apierror-mustbeloggedin', 'submit an experience report' ], 'notloggedin' );
        }
        if ( !$user->isAllowed( 'pharmacopedia-vote' ) ) {
            $this->dieWithError( [ 'apierror-permissiondenied', 'submit an experience report' ], 'permissiondenied' );
        }

        $params = $this->extractRequestParams();
        $pageId = (int)$params['page_id'];
        $title = Title::newFromID( $pageId );
        if ( !$title || !$title->exists() ) {
            $this->dieWithError( [ 'rawmessage', 'Target page not found.' ], 'notfound' );
        }

        $perspective = (int)$params['perspective'];
        if ( !ExperienceStore::isValidPerspective( $perspective ) ) {
            $this->dieWithError( [ 'rawmessage', 'Invalid perspective.' ], 'badvalue' );
        }
        if ( $perspective === ExperienceStore::PERSPECTIVE_CLINICAL
             && !$user->isAllowed( 'pharmacopedia-effect-as-provider' ) ) {
            $this->dieWithError( [ 'apierror-permissiondenied', 'submit a clinical report' ], 'permissiondenied' );
        }

        // current (required)
        $current = (int)$params['current'];
        if ( !in_array( $current, [ 1, 2, 3 ], true ) ) {
            $this->dieWithError( [ 'rawmessage', 'Please answer whether you currently take/prescribe it.' ], 'badvalue' );
        }

        // efficacy + burden (required, 0..5)
        $efficacy = $this->intOrNull( $params['efficacy'] );
        $burden   = $this->intOrNull( $params['burden'] );
        if ( $efficacy === null || $efficacy < 0 || $efficacy > 100 ) {
            $this->dieWithError( [ 'rawmessage', 'Please rate effectiveness (0-100).' ], 'badvalue' );
        }
        if ( $burden === null || $burden < 0 || $burden > 100 ) {
            $this->dieWithError( [ 'rawmessage', 'Please rate side-effect burden (0-100).' ], 'badvalue' );
        }

        // duration (optional)
        $durationDays = null;
        $durVal = trim( (string)( $params['duration_value'] ?? '' ) );
        if ( $durVal !== '' ) {
            $durationDays = ExperienceStore::normalizeDurationToDays(
                $durVal, (string)( $params['duration_unit'] ?? '' )
            );
            if ( $durationDays === null ) {
                $this->dieWithError( [ 'rawmessage', 'Invalid duration.' ], 'badvalue' );
            }
        }

        // patient_count (clinical only, optional)
        $patientCount = null;
        if ( $perspective === ExperienceStore::PERSPECTIVE_CLINICAL ) {
            $pc = trim( (string)( $params['patient_count'] ?? '' ) );
            if ( $pc !== '' ) {
                $patientCount = max( 0, (int)$pc );
            }
        }

        // patient_count_max (clinical only, optional — range upper bound)
        $patientCountMax = null;
        if ( $perspective === self::PERSPECTIVE_CLINICAL ) {
            $pcm = trim( (string)( $params['patient_count_max'] ?? '' ) );
            if ( $pcm !== '' && ctype_digit( $pcm ) ) {
                $patientCountMax = (int)$pcm;
            }
        }

        // route + schedule (both optional, personal or clinical)
        $route = trim( (string)( $params['route'] ?? '' ) );
        $validRoutes = [ '', 'PO', 'IV', 'IM', 'SC', 'SL', 'buccal', 'inhaled', 'intranasal', 'topical', 'transdermal', 'PR', 'ophthalmic', 'otic', 'vaginal', 'insufflated', 'other' ];
        if ( !in_array( $route, $validRoutes, true ) ) { $route = 'other'; }
        if ( $route === '' ) { $route = null; }
        $schedule = trim( (string)( $params['schedule'] ?? '' ) );
        if ( strlen( $schedule ) > 64 ) { $schedule = substr( $schedule, 0, 64 ); }
        if ( $schedule === '' ) { $schedule = null; }

        // dose_mg (personal only, optional) -- precise total daily dose in mg
        $doseMg = null;
        if ( $perspective === ExperienceStore::PERSPECTIVE_PERSONAL ) {
            $dm = trim( (string)( $params['dose_mg'] ?? '' ) );
            if ( $dm !== '' && is_numeric( $dm ) ) {
                $d = (float)$dm;
                if ( $d > 0 && $d <= 100000 ) {
                    $doseMg = round( $d, 3 );
                }
            }
        }

        // stop_reasons — multi-select with optional severity per code (JSON)
        $stopReasonsJson = null;
        if ( $perspective === self::PERSPECTIVE_PERSONAL && (int)$current === 2 ) {
            $raw = trim( (string)( $params['stop_reasons'] ?? '' ) );
            if ( $raw !== '' && $raw[ 0 ] === '[' ) {
                $arr = json_decode( $raw, true );
                if ( is_array( $arr ) ) {
                    $allowed = [ 'side_effects','ineffective','cost','no_longer_needed','clinician_advised','other' ];
                    $clean = [];
                    foreach ( $arr as $entry ) {
                        if ( !is_array( $entry ) ) continue;
                        $code = (string)( $entry['code'] ?? '' );
                        if ( !in_array( $code, $allowed, true ) ) continue;
                        $row = [ 'code' => $code ];
                        if ( isset( $entry['severity'] ) && $entry['severity'] !== '' && $entry['severity'] !== null ) {
                            $sev = (int)$entry['severity'];
                            if ( $sev < 0 ) $sev = 0; if ( $sev > 100 ) $sev = 100;
                            $row['severity'] = $sev;
                        }
                        $clean[] = $row;
                    }
                    if ( $clean ) $stopReasonsJson = json_encode( $clean );
                }
            }
        }


        // payload -- sanitize the staged indications / effects / anecdote
        $payload = $this->sanitizePayload( (string)( $params['payload'] ?? '' ) );

        $store = new ExperienceStore();
        $xrId = $store->submit( $pageId, (int)$user->getId(), $perspective, [
            'current'       => $current,
            'duration_days' => $durationDays,
            'dose_mg'       => $doseMg,
            'route'         => $route,
            'schedule'      => $schedule,
            'patient_count' => $patientCount,
            'patient_count_max' => $patientCountMax,
            'efficacy'      => $efficacy,
            'burden'        => $burden,
            'stop_reason'   => $stopReasonsJson,
        ], $payload );

        $this->getResult()->addValue( null, 'pharmacopediaexperiencesubmit', [
            'ok'     => 1,
            'xr_id'  => (int)$xrId,
            'status' => 'pending',
        ] );
    }

    private function intOrNull( $v ) {
        if ( $v === null || $v === '' ) { return null; }
        return (int)$v;
    }

    private function sanitizePayload( $raw ) {
        $out = [ 'indications' => [], 'effects' => [], 'anecdote' => '' ];
        if ( $raw === '' ) { return $out; }
        $d = json_decode( $raw, true );
        if ( !is_array( $d ) ) { return $out; }

        if ( isset( $d['indications'] ) && is_array( $d['indications'] ) ) {
            foreach ( array_slice( $d['indications'], 0, 30 ) as $it ) {
                if ( !is_array( $it ) ) { continue; }
                $rating = ( isset( $it['rating'] ) && $it['rating'] !== null ) ? (int)$it['rating'] : null;
                if ( $rating !== null && ( $rating < -1 || $rating > 5 ) ) { $rating = null; }
                if ( !empty( $it['ref'] ) ) {
                    $out['indications'][] = [
                        'ref'    => substr( (string)$it['ref'], 0, 255 ),
                        'rating' => $rating,
                    ];
                } elseif ( !empty( $it['new_name'] ) ) {
                    $out['indications'][] = [
                        'new_name' => substr( trim( (string)$it['new_name'] ), 0, 255 ),
                        'rating'   => $rating,
                    ];
                }
            }
        }
        if ( isset( $d['effects'] ) && is_array( $d['effects'] ) ) {
            $validFreqs = [ -1, 0, 5, 20, 33, 50, 66, 80, 95 ];
            foreach ( array_slice( $d['effects'], 0, 50 ) as $it ) {
                if ( !is_array( $it ) ) { continue; }
                $val = isset( $it['valence'] ) ? (int)$it['valence'] : null;
                if ( $val !== null && ( $val < -3 || $val > 3 ) ) { $val = null; }
                $freq = ( isset( $it['frequency'] ) && $it['frequency'] !== null )
                    ? (int)$it['frequency'] : null;
                if ( $freq !== null && !in_array( $freq, $validFreqs, true ) ) { $freq = null; }
                if ( !empty( $it['slug'] ) ) {
                    $out['effects'][] = [
                        'slug'      => substr( (string)$it['slug'], 0, 255 ),
                        'label'     => substr( (string)( $it['label'] ?? $it['slug'] ), 0, 255 ),
                        'valence'   => $val,
                        'frequency' => $freq,
                    ];
                } elseif ( !empty( $it['new_name'] ) ) {
                    $out['effects'][] = [
                        'new_name'  => substr( trim( (string)$it['new_name'] ), 0, 255 ),
                        'valence'   => $val,
                        'frequency' => $freq,
                    ];
                }
            }
        }
        if ( isset( $d['anecdote'] ) && is_string( $d['anecdote'] ) ) {
            $out['anecdote'] = substr( trim( $d['anecdote'] ), 0, 8000 );
        }
        return $out;
    }

    public function getAllowedParams() {
        return [
            'page_id'        => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'perspective'    => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'current'        => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'duration_value' => [ ApiBase::PARAM_TYPE => 'string' ],
            'duration_unit'  => [ ApiBase::PARAM_TYPE => 'string' ],
            'dose_mg'        => [ ApiBase::PARAM_TYPE => 'string' ],
            'route'          => [ ApiBase::PARAM_TYPE => 'string' ],
            'schedule'       => [ ApiBase::PARAM_TYPE => 'string' ],
            'patient_count'  => [ ApiBase::PARAM_TYPE => 'string' ],
            'efficacy'       => [ ApiBase::PARAM_TYPE => 'string' ],
            'burden'         => [ ApiBase::PARAM_TYPE => 'string' ],
            'stop_reasons'      => [ ApiBase::PARAM_TYPE => 'string' ],
            'patient_count_max' => [ ApiBase::PARAM_TYPE => 'string' ],
            'payload'        => [ ApiBase::PARAM_TYPE => 'string' ],
        ];
    }
    public function needsToken()   { return 'csrf'; }
    public function isWriteMode()  { return true; }
    public function mustBePosted() { return true; }
}
