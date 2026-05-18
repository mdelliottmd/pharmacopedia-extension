<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

/**
 * CRUD for the Life Story system: pcp_life_events + pcp_life_images + pcp_life_traits.
 * Per-event visibility uses the same 4-state model as profile fields.
 */
class LifeStoryStore {

    // Date precision codes
    public const DP_DAY        = 0;
    public const DP_MONTH      = 1;
    public const DP_YEAR       = 2;
    public const DP_DECADE     = 3;
    public const DP_APPROX_AGE = 4;
    public const DP_UNKNOWN    = 5;

    // Event type codes
    public const TYPE_STORY    = 0;
    public const TYPE_IMAGE    = 1;
    public const TYPE_KEYFRAME = 2;
    public const TYPE_OBSERVATION = 3;
    public const TYPE_EPISODE = 4;

    // Per-user image quota (bytes)
    public const MAX_IMAGE_BYTES_PER_USER = 524288000; // 500 MB
    public const MAX_IMAGE_BYTES_PER_FILE =  10485760; // 10 MB

    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }
    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    public static function storageRoot(): string {
        global $wgPharmacopediaLifeStoryDir;
        return $wgPharmacopediaLifeStoryDir ?: '/var/lib/pharmacopedia-life';
    }

    public static function eventDirFor( int $profileId, int $eventId ): string {
        return self::storageRoot() . "/$profileId/$eventId";
    }

    // ===== Events =====

    public function getEvent( int $eventId ): ?\stdClass {
        $row = $this->dbr()->selectRow( 'pcp_life_events', '*',
            [ 'le_id' => $eventId ], __METHOD__ );
        return $row ?: null;
    }

    /** All events for a profile, chronological by le_date_iso (NULLs last). */
    public function getEventsForProfile( int $profileId, int $minVisibility = 0 ): array {
        $where = [ 'le_profile_id' => $profileId ];
        if ( $minVisibility > 0 ) $where[] = 'le_visibility >= ' . (int)$minVisibility;
        $res = $this->dbr()->select( 'pcp_life_events', '*', $where, __METHOD__,
            [ 'ORDER BY' => 'le_date_iso IS NULL, le_date_iso ASC, le_id ASC' ] );
        $out = [];
        foreach ( $res as $r ) { $out[] = $r; }
        return $out;
    }

    public function addEvent( int $profileId, array $fields ): int {
        $dbw = $this->dbw();
        $now = $dbw->timestamp();
        $dbw->insert( 'pcp_life_events', [
            'le_profile_id'     => $profileId,
            'le_date_iso'       => $fields['date_iso']      ?? null,
            'le_date_precision' => (int)( $fields['date_precision'] ?? self::DP_UNKNOWN ),
            'le_date_display'   => $fields['date_display']  ?? null,
            'le_date_struct'    => $fields['date_struct']   ?? null,
            'le_type'           => (int)( $fields['type'] ?? self::TYPE_STORY ),
            'le_title'          => (string)( $fields['title'] ?? '' ),
            'le_body'           => $fields['body']          ?? null,
            'le_visibility'     => max( 0, min( 3, (int)( $fields['visibility'] ?? 0 ) ) ),
            'le_tags'           => $fields['tags']          ?? null,
            'le_created'        => $now,
            'le_updated'        => $now,
        ], __METHOD__ );
        return (int)$dbw->insertId();
    }

    public function updateEvent( int $eventId, array $fields ): void {
        $dbw = $this->dbw();
        $row = [
            'le_date_iso'       => $fields['date_iso']      ?? null,
            'le_date_precision' => (int)( $fields['date_precision'] ?? self::DP_UNKNOWN ),
            'le_date_display'   => $fields['date_display']  ?? null,
            'le_date_struct'    => $fields['date_struct']   ?? null,
            'le_type'           => (int)( $fields['type'] ?? self::TYPE_STORY ),
            'le_title'          => (string)( $fields['title'] ?? '' ),
            'le_body'           => $fields['body']          ?? null,
            'le_visibility'     => max( 0, min( 3, (int)( $fields['visibility'] ?? 0 ) ) ),
            'le_tags'           => $fields['tags']          ?? null,
            'le_updated'        => $dbw->timestamp(),
        ];
        $dbw->update( 'pcp_life_events', $row, [ 'le_id' => $eventId ], __METHOD__ );
    }

    public function deleteEvent( int $eventId ): void {
        $dbw = $this->dbw();
        // Cascade: traits + image rows + image files
        $images = $this->getImagesForEvent( $eventId );
        foreach ( $images as $im ) {
            $p = (string)$im->li_file_path;
            if ( $p !== '' && file_exists( $p ) ) @unlink( $p );
        }
        $dbw->delete( 'pcp_life_images', [ 'li_event_id' => $eventId ], __METHOD__ );
        $dbw->delete( 'pcp_life_traits', [ 'lt_event_id' => $eventId ], __METHOD__ );
        $dbw->delete( 'pcp_life_events', [ 'le_id' => $eventId ], __METHOD__ );
    }

    // ===== Images =====

    public function getImagesForEvent( int $eventId ): array {
        $res = $this->dbr()->select( 'pcp_life_images', '*',
            [ 'li_event_id' => $eventId ], __METHOD__,
            [ 'ORDER BY' => 'li_uploaded ASC' ] );
        $out = [];
        foreach ( $res as $r ) { $out[] = $r; }
        return $out;
    }

    public function getImage( int $imageId ): ?\stdClass {
        $row = $this->dbr()->selectRow( 'pcp_life_images', '*',
            [ 'li_id' => $imageId ], __METHOD__ );
        return $row ?: null;
    }

    public function totalImageBytesForProfile( int $profileId ): int {
        $sum = (int)$this->dbr()->selectField(
            [ 'pcp_life_images', 'pcp_life_events' ],
            'COALESCE(SUM(li_size_bytes),0)',
            [ 'le_profile_id' => $profileId ],
            __METHOD__,
            [],
            [ 'pcp_life_events' => [ 'INNER JOIN', 'le_id = li_event_id' ] ]
        );
        return $sum;
    }

    public function addImage( int $eventId, array $fields ): int {
        // PROJECT RULE: virus-scan every uploaded file before storing.
        $tmpPath = (string)( $fields['tmp_path'] ?? '' );
        if ( $tmpPath !== '' ) {
            $scan = \MediaWiki\Extension\Pharmacopedia\VirusScanner::scanFile( $tmpPath );
            if ( !$scan['ok'] ) {
                throw new \RuntimeException( 'image upload rejected: ' . $scan['reason'] );
            }
        }
        $dbw = $this->dbw();
        $dbw->insert( 'pcp_life_images', [
            'li_event_id'   => $eventId,
            'li_file_path'  => $fields['file_path'],
            'li_orig_name'  => $fields['orig_name'],
            'li_mime'       => $fields['mime'],
            'li_size_bytes' => (int)$fields['size_bytes'],
            'li_caption'    => $fields['caption'] ?? null,
            'li_uploaded'   => $dbw->timestamp(),
        ], __METHOD__ );
        return (int)$dbw->insertId();
    }

    public function deleteImage( int $imageId ): void {
        $img = $this->getImage( $imageId );
        if ( !$img ) return;
        $p = (string)$img->li_file_path;
        if ( $p !== '' && file_exists( $p ) ) @unlink( $p );
        $this->dbw()->delete( 'pcp_life_images', [ 'li_id' => $imageId ], __METHOD__ );
    }

    // ===== Traits (keyframe values) =====

    public function getTraitsForEvent( int $eventId ): array {
        $res = $this->dbr()->select( 'pcp_life_traits', '*',
            [ 'lt_event_id' => $eventId ], __METHOD__,
            [ 'ORDER BY' => 'lt_id ASC' ] );
        $out = [];
        foreach ( $res as $r ) { $out[] = $r; }
        return $out;
    }

    /** Replace all traits on an event with a new set. */
    public function setTraits( int $eventId, array $traits ): void {
        $dbw = $this->dbw();
        $dbw->delete( 'pcp_life_traits', [ 'lt_event_id' => $eventId ], __METHOD__ );
        foreach ( $traits as $t ) {
            $dbw->insert( 'pcp_life_traits', [
                'lt_event_id'  => $eventId,
                'lt_namespace' => (string)$t['namespace'],
                'lt_key'       => (string)$t['key'],
                'lt_label'     => $t['label']     ?? null,
                'lt_value_num' => (float)$t['value'],
                'lt_min'       => $t['min']       ?? null,
                'lt_max'       => $t['max']       ?? null,
                'lt_estimated' => !empty( $t['estimated'] ) ? 1 : 0,
            ], __METHOD__ );
        }
    }

    // ===== Auto-keyframe (called from SpecialMyProfile::save after assessment scoring) =====

    /**
     * Find-or-create today's keyframe event for a given assessment, with the latest scores.
     * Behavior:
     *   - If a prior keyframe-id is recorded in pcp_profile_fields ({test}::_keyframe_id)
     *     AND its date_iso === today, update its traits in place.
     *   - Otherwise create a new event + traits and record the new id.
     */
    public function upsertAssessmentKeyframe( int $profileId, string $testKey, string $assessmentClass, array $scores ): int {
        $today = date( 'Y-m-d' );
        $store = new UserProfileStore();

        // Look up prior keyframe id
        $priorEventId = null;
        foreach ( $store->getFields( $profileId, $testKey, 0 ) as $f ) {
            if ( (string)$f->pf_key === '_keyframe_id' && $f->pf_value_num !== null ) {
                $priorEventId = (int)$f->pf_value_num;
                break;
            }
        }
        $eventId = null;
        if ( $priorEventId ) {
            $event = $this->getEvent( $priorEventId );
            if ( $event && (string)$event->le_date_iso === $today ) {
                $eventId = $priorEventId;
            }
        }

        if ( $eventId === null ) {
            $kfStruct = [
                'kind'  => 'point',
                'point' => [
                    'raw_text' => $today,
                    'parsed'   => [
                        'kind' => 'point', 'precision' => 'day', 'display' => null,
                        'year' => (int)substr( $today, 0, 4 ),
                        'month' => (int)substr( $today, 5, 2 ),
                        'day' => (int)substr( $today, 8, 2 ),
                        'iso' => $today,
                    ],
                    'time' => null, 'timezone' => null, 'effective_iso' => $today,
                ],
            ];
            $eventId = $this->addEvent( $profileId, [
                'date_iso'       => $today,
                'date_precision' => self::DP_DAY,
                'date_display'   => null,
                'date_struct'    => json_encode( $kfStruct, JSON_UNESCAPED_UNICODE ),
                'type'           => self::TYPE_KEYFRAME,
                'title'          => $assessmentClass::NAME . ' completed',
                'body'           => null,
                'visibility'     => 0,
                'tags'           => 'assessment,' . $testKey,
            ] );
            $store->setField( $profileId, $testKey, '_keyframe_id', null, (float)$eventId, 0 );
        }

        // Build trait rows from the assessment scores
        $traits = [];
        foreach ( $assessmentClass::SUBSCALES as $sk => $def ) {
            $v = $scores[ 'subscale_' . $sk ] ?? null;
            if ( $v === null ) continue;
            $traits[] = [
                'namespace' => $testKey,
                'key'       => 'subscale_' . $sk,
                'label'     => $def['label'],
                'value'     => (float)$v,
                'min'       => null,
                'max'       => null,
                'estimated' => 0,
            ];
        }
        if ( isset( $scores['total'] ) && $scores['total'] !== null ) {
            $traits[] = [
                'namespace' => $testKey,
                'key'       => 'total',
                'label'     => 'Total',
                'value'     => (float)$scores['total'],
                'min'       => null,
                'max'       => null,
                'estimated' => 0,
            ];
        }
        $this->setTraits( $eventId, $traits );
        return $eventId;
    }

    // ===== Phase 2: trajectory timeseries + derived events + chart SVG =====

    /**
     * Return keyframe timeseries grouped by namespace.
     *   [ 'pid5bf' => [
     *       'subscale_NA' => [
     *           [ 'date'=>'2026-05-16', 'value'=>1.5, 'label'=>'Negative Affectivity',
     *             'min'=>null, 'max'=>null, 'estimated'=>0 ],
     *           ...
     *       ],
     *       ...
     *   ] ]
     * Only namespaces with at least one trait having >=2 dated points are included.
     */
    public function getKeyframeTimeseries( int $profileId ): array {
        $dbr = $this->dbr();
        $res = $dbr->select(
            [ 'e' => 'pcp_life_events', 't' => 'pcp_life_traits' ],
            [ 'e.le_id', 'e.le_date_iso', 't.lt_namespace', 't.lt_key', 't.lt_label',
              't.lt_value_num', 't.lt_min', 't.lt_max', 't.lt_estimated' ],
            [
                'e.le_profile_id' => $profileId,
                'e.le_type'       => self::TYPE_KEYFRAME,
                'e.le_date_iso IS NOT NULL',
            ],
            __METHOD__,
            [ 'ORDER BY' => 'e.le_date_iso ASC' ],
            [ 't' => [ 'INNER JOIN', 'e.le_id = t.lt_event_id' ] ]
        );
        $byNs = [];
        foreach ( $res as $r ) {
            $ns  = (string)$r->lt_namespace;
            $key = (string)$r->lt_key;
            $byNs[ $ns ][ $key ][] = [
                'date'      => (string)$r->le_date_iso,
                'value'     => (float)$r->lt_value_num,
                'label'     => $r->lt_label ? (string)$r->lt_label : $key,
                'min'       => $r->lt_min !== null ? (float)$r->lt_min : null,
                'max'       => $r->lt_max !== null ? (float)$r->lt_max : null,
                'estimated' => (int)$r->lt_estimated,
            ];
        }
        $out = [];
        foreach ( $byNs as $ns => $keys ) {
            $hasMulti = false;
            foreach ( $keys as $pts ) {
                if ( count( $pts ) >= 2 ) { $hasMulti = true; break; }
            }
            if ( $hasMulti ) $out[ $ns ] = $keys;
        }
        return $out;
    }

    /**
     * Synthesize timeline events from existing tables (meds, diagnoses, experience reports).
     * Returns array of stdClass objects shaped like pcp_life_events rows, plus _source / _source_id.
     * NOT stored — generated fresh on each call. For self/sysop viewers only (caller enforces).
     */
    public function getDerivedEvents( int $profileId, string $voterHash ): array {
        $dbr = $this->dbr();
        $out = [];

        // ---- Diagnoses with pd_year_first ----
        $dx = $dbr->select( 'pcp_profile_diagnoses', '*',
            [ 'pd_profile_id' => $profileId ], __METHOD__ );
        foreach ( $dx as $d ) {
            $dxStruct = null;
            $iso = null;
            $displayStr = null;
            if ( !empty( $d->pd_date_struct ) ) {
                $dxStruct = json_decode( (string)$d->pd_date_struct, true );
                if ( is_array( $dxStruct ) ) {
                    $iso = \MediaWiki\Extension\Pharmacopedia\DatePicker::sortKeyIso( $dxStruct );
                    $displayStr = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatForDisplay( $dxStruct );
                }
            }
            if ( !$iso && $d->pd_year_first ) {
                $y = (int)$d->pd_year_first;
                if ( $y >= 1900 && $y <= 2200 ) {
                    $iso = sprintf( '%04d-01-01', $y );
                    $displayStr = (string)$y;
                }
            }
            if ( !$iso ) continue;
            $out[] = (object)[
                'le_id'             => null,
                'le_profile_id'     => $profileId,
                'le_date_iso'       => $iso,
                'le_date_struct'    => $dxStruct ? json_encode( $dxStruct, JSON_UNESCAPED_UNICODE ) : null,
                'le_date_precision' => self::DP_YEAR,
                'le_date_display'   => $displayStr,
                'le_type'           => self::TYPE_STORY,
                'le_title'          => 'Diagnosed: ' . (string)$d->pd_description,
                'le_body'           => $d->pd_notes,
                'le_visibility'     => (int)$d->pd_visibility,
                'le_tags'           => 'derived,diagnosis',
                'le_created'        => $d->pd_added,
                'le_updated'        => $d->pd_added,
                '_is_derived'       => true,
                '_source'           => 'diagnosis',
                '_source_id'        => (int)$d->pd_id,
            ];
        }

        // ---- Meds: started + (stopped, if duration set) ----
        $meds = $dbr->select( 'pcp_user_meds', '*',
            [ 'um_profile_id' => $profileId ], __METHOD__ );
        foreach ( $meds as $m ) {
            $name = (string)$m->um_med_name;

            // ---- Periods of use (new model) ----
            if ( !empty( $m->um_periods ) ) {
                $periodsArr = json_decode( (string)$m->um_periods, true );
                if ( is_array( $periodsArr ) ) {
                    foreach ( $periodsArr as $pi => $p ) {
                        $pStart = $p['start'] ?? null;
                        $pEnd   = $p['end']   ?? null;
                        if ( $pStart && !empty( $pStart['effective_iso'] ) ) {
                            $iso = substr( (string)$pStart['effective_iso'], 0, 10 );
                            $disp = !empty( $pStart['raw_text'] ) ? (string)$pStart['raw_text'] : $iso;
                            $out[] = (object)[
                                'le_id'             => null,
                                'le_profile_id'     => $profileId,
                                'le_date_iso'       => $iso,
                                'le_date_struct'    => json_encode( [ 'kind' => 'point', 'point' => $pStart ], JSON_UNESCAPED_UNICODE ),
                                'le_date_precision' => self::DP_DAY,
                                'le_date_display'   => $disp,
                                'le_type'           => self::TYPE_STORY,
                                'le_title'          => 'Started: ' . $name . ( count( $periodsArr ) > 1 ? ' (period ' . ( $pi + 1 ) . ')' : '' ),
                                'le_body'           => $m->um_notes,
                                'le_visibility'     => (int)$m->um_visibility,
                                'le_tags'           => 'derived,med-start',
                                'le_created'        => $m->um_added,
                                'le_updated'        => $m->um_updated,
                                '_is_derived'       => true,
                                '_source'           => 'med',
                                '_source_id'        => (int)$m->um_id,
                            ];
                        }
                        if ( $pEnd && !empty( $pEnd['effective_iso'] ) ) {
                            $iso = substr( (string)$pEnd['effective_iso'], 0, 10 );
                            $disp = !empty( $pEnd['raw_text'] ) ? (string)$pEnd['raw_text'] : $iso;
                            $out[] = (object)[
                                'le_id'             => null,
                                'le_profile_id'     => $profileId,
                                'le_date_iso'       => $iso,
                                'le_date_struct'    => json_encode( [ 'kind' => 'point', 'point' => $pEnd ], JSON_UNESCAPED_UNICODE ),
                                'le_date_precision' => self::DP_DAY,
                                'le_date_display'   => $disp,
                                'le_type'           => self::TYPE_STORY,
                                'le_title'          => 'Stopped: ' . $name . ( count( $periodsArr ) > 1 ? ' (period ' . ( $pi + 1 ) . ')' : '' ),
                                'le_body'           => null,
                                'le_visibility'     => (int)$m->um_visibility,
                                'le_tags'           => 'derived,med-stop',
                                'le_created'        => $m->um_updated,
                                'le_updated'        => $m->um_updated,
                                '_is_derived'       => true,
                                '_source'           => 'med',
                                '_source_id'        => (int)$m->um_id,
                            ];
                        }
                    }
                    continue;
                }
            }

            // ---- Start event ----
            $startStruct = null;
            $startIso = null;
            $startDisp = null;
            if ( !empty( $m->um_start_struct ) ) {
                $startStruct = json_decode( (string)$m->um_start_struct, true );
                if ( is_array( $startStruct ) ) {
                    $startIso  = \MediaWiki\Extension\Pharmacopedia\DatePicker::sortKeyIso( $startStruct );
                    $startDisp = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatForDisplay( $startStruct );
                }
            }
            if ( !$startIso ) {
                $ts = (string)$m->um_added;
                if ( strlen( $ts ) >= 8 ) {
                    $startIso = substr( $ts, 0, 4 ) . '-' . substr( $ts, 4, 2 ) . '-' . substr( $ts, 6, 2 );
                }
            }
            if ( $startIso ) {
                $out[] = (object)[
                    'le_id'             => null,
                    'le_profile_id'     => $profileId,
                    'le_date_iso'       => $startIso,
                    'le_date_struct'    => $startStruct ? json_encode( $startStruct, JSON_UNESCAPED_UNICODE ) : null,
                    'le_date_precision' => self::DP_DAY,
                    'le_date_display'   => $startDisp,
                    'le_type'           => self::TYPE_STORY,
                    'le_title'          => 'Started: ' . $name,
                    'le_body'           => $m->um_notes,
                    'le_visibility'     => (int)$m->um_visibility,
                    'le_tags'           => 'derived,med-start',
                    'le_created'        => $m->um_added,
                    'le_updated'        => $m->um_updated,
                    '_is_derived'       => true,
                    '_source'           => 'med',
                    '_source_id'        => (int)$m->um_id,
                ];
            }

            // ---- Stop event (only when status = Stopped, or stop_struct explicitly set) ----
            $stopStruct = null;
            $stopIso = null;
            $stopDisp = null;
            if ( !empty( $m->um_stop_struct ) ) {
                $stopStruct = json_decode( (string)$m->um_stop_struct, true );
                if ( is_array( $stopStruct ) ) {
                    $stopIso  = \MediaWiki\Extension\Pharmacopedia\DatePicker::sortKeyIso( $stopStruct );
                    $stopDisp = \MediaWiki\Extension\Pharmacopedia\DatePicker::formatForDisplay( $stopStruct );
                }
            }
            if ( !$stopIso && (int)$m->um_current === 2 && $m->um_duration_days && $startIso ) {
                $stopTs = strtotime( $startIso ) + ( (int)$m->um_duration_days * 86400 );
                $stopIso = date( 'Y-m-d', $stopTs );
            }
            if ( $stopIso ) {
                $out[] = (object)[
                    'le_id'             => null,
                    'le_profile_id'     => $profileId,
                    'le_date_iso'       => $stopIso,
                    'le_date_struct'    => $stopStruct ? json_encode( $stopStruct, JSON_UNESCAPED_UNICODE ) : null,
                    'le_date_precision' => self::DP_DAY,
                    'le_date_display'   => $stopDisp,
                    'le_type'           => self::TYPE_STORY,
                    'le_title'          => 'Stopped: ' . $name,
                    'le_body'           => null,
                    'le_visibility'     => (int)$m->um_visibility,
                    'le_tags'           => 'derived,med-stop',
                    'le_created'        => $m->um_updated,
                    'le_updated'        => $m->um_updated,
                    '_is_derived'       => true,
                    '_source'           => 'med',
                    '_source_id'        => (int)$m->um_id,
                ];
            }
        }

        // ---- Experience reports (status=approved) ----
        if ( $voterHash !== '' ) {
            $xr = $dbr->newSelectQueryBuilder()
                ->select( [ 'xr.xr_id', 'xr.xr_created', 'xr.xr_efficacy', 'xr.xr_burden',
                            'xr.xr_perspective', 'p.page_title' ] )
                ->from( 'pcp_experience_reports', 'xr' )
                ->leftJoin( 'page', 'p', 'p.page_id = xr.xr_page_id' )
                ->where( [ 'xr.xr_voter_hash' => $voterHash, 'xr.xr_status' => 1 ] )
                ->caller( __METHOD__ )
                ->fetchResultSet();
            foreach ( $xr as $r ) {
                $ts = (string)$r->xr_created;
                if ( strlen( $ts ) < 8 ) continue;
                $iso = substr( $ts, 0, 4 ) . '-' . substr( $ts, 4, 2 ) . '-' . substr( $ts, 6, 2 );
                $title = str_replace( '_', ' ', (string)$r->page_title );
                $bits = [];
                if ( (int)$r->xr_perspective === 2 ) $bits[] = 'clinical perspective';
                if ( $r->xr_efficacy !== null ) $bits[] = 'efficacy ' . (int)$r->xr_efficacy . '/100';
                if ( $r->xr_burden !== null )   $bits[] = 'burden '   . (int)$r->xr_burden   . '/100';
                $body = $bits ? implode( ' · ', $bits ) : null;
                $out[] = (object)[
                    'le_id'             => null,
                    'le_profile_id'     => $profileId,
                    'le_date_iso'       => $iso,
                    'le_date_precision' => self::DP_DAY,
                    'le_date_display'   => null,
                    'le_type'           => self::TYPE_STORY,
                    'le_title'          => 'Experience report: ' . $title,
                    'le_body'           => $body,
                    'le_visibility'     => 1, // approved XRs are anonymized-public on their page
                    'le_tags'           => 'derived,experience-report',
                    'le_created'        => $r->xr_created,
                    'le_updated'        => $r->xr_created,
                    '_is_derived'       => true,
                    '_source'           => 'xr',
                    '_source_id'        => (int)$r->xr_id,
                ];
            }
        }

        return $out;
    }

    /**
     * Server-rendered SVG line chart for a single namespace's keyframe series.
     * $series is keyed by trait key:
     *   [ 'subscale_NA' => [ ['date'=>'YYYY-MM-DD', 'value'=>n, 'label'=>..., 'min'=>null, 'max'=>null, 'estimated'=>0], ... ] ]
     * $scale = [ 'min'=>0, 'max'=>3 ] or null (auto-fit).
     */
    public static function renderTrajectorySvg( string $title, array $traits, ?array $scale = null,
                                                 int $width = 720, int $height = 280 ): string {
        $pad = [ 'top' => 32, 'right' => 150, 'bottom' => 32, 'left' => 50 ];
        $plotW = $width - $pad['left'] - $pad['right'];
        $plotH = $height - $pad['top']  - $pad['bottom'];

        $allDates = []; $allValues = [];
        foreach ( $traits as $pts ) {
            foreach ( $pts as $p ) {
                $allDates[] = strtotime( $p['date'] );
                $allValues[] = (float)$p['value'];
            }
        }
        if ( !$allDates ) return '';
        $xMin = min( $allDates ); $xMax = max( $allDates );
        if ( $xMin === $xMax ) $xMax = $xMin + 86400 * 30;

        if ( $scale ) { $yMin = (float)$scale['min']; $yMax = (float)$scale['max']; }
        else          { $yMin = min( $allValues ); $yMax = max( $allValues ); }
        if ( abs( $yMax - $yMin ) < 0.0001 ) $yMax = $yMin + 1.0;

        $xScale = static function ( $t ) use ( $pad, $plotW, $xMin, $xMax ) {
            return $pad['left'] + ( $t - $xMin ) / max( 1, ( $xMax - $xMin ) ) * $plotW;
        };
        $yScale = static function ( $v ) use ( $pad, $plotH, $yMin, $yMax ) {
            return $pad['top'] + ( 1 - ( $v - $yMin ) / max( 0.0001, ( $yMax - $yMin ) ) ) * $plotH;
        };

        $colors = [ '#7c3aed', '#ef4444', '#22c55e', '#3b82f6', '#14b8a6', '#c4b5fd', '#fff', '#a78bfa' ];

        $svg  = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="pcp-life-chart" xmlns="http://www.w3.org/2000/svg">';
        $svg .= '<rect width="' . $width . '" height="' . $height . '" fill="#1a1a1a"/>';
        $svg .= '<text x="' . ( $width / 2 ) . '" y="18" text-anchor="middle" fill="#c4b5fd" font-size="13" font-weight="700">'
                . htmlspecialchars( $title ) . '</text>';

        // Y gridlines + labels
        $yTicks = 4;
        for ( $i = 0; $i <= $yTicks; $i++ ) {
            $v = $yMin + ( $yMax - $yMin ) * $i / $yTicks;
            $y = $yScale( $v );
            $svg .= '<line x1="' . $pad['left'] . '" y1="' . $y . '" x2="' . ( $pad['left'] + $plotW )
                    . '" y2="' . $y . '" stroke="#2a2a2a"/>';
            $svg .= '<text x="' . ( $pad['left'] - 6 ) . '" y="' . ( $y + 3.5 )
                    . '" text-anchor="end" fill="#c4b5fd" font-size="10">' . round( $v, 2 ) . '</text>';
        }

        // X year ticks
        $yearMin = (int)date( 'Y', $xMin );
        $yearMax = (int)date( 'Y', $xMax );
        for ( $y = $yearMin; $y <= $yearMax; $y++ ) {
            $t = mktime( 0, 0, 0, 1, 1, $y );
            if ( $t < $xMin - 86400 * 30 || $t > $xMax + 86400 * 30 ) continue;
            $tt = max( $xMin, min( $xMax, $t ) );
            $x  = $xScale( $tt );
            $svg .= '<line x1="' . $x . '" y1="' . $pad['top'] . '" x2="' . $x
                    . '" y2="' . ( $pad['top'] + $plotH )
                    . '" stroke="#2a2a2a" stroke-dasharray="2,4"/>';
            $svg .= '<text x="' . $x . '" y="' . ( $pad['top'] + $plotH + 14 )
                    . '" text-anchor="middle" fill="#c4b5fd" font-size="10">' . $y . '</text>';
        }

        // Lines + dots
        $i = 0;
        $legend = [];
        foreach ( $traits as $key => $pts ) {
            $color = $colors[ $i % count( $colors ) ];
            $legendLabel = $pts[0]['label'] ?? $key;
            $legend[] = [ 'color' => $color, 'label' => $legendLabel ];
            $coords = [];
            foreach ( $pts as $p ) {
                $coords[] = $xScale( strtotime( $p['date'] ) ) . ',' . $yScale( (float)$p['value'] );
            }
            if ( count( $coords ) >= 2 ) {
                $svg .= '<polyline points="' . implode( ' ', $coords ) . '" fill="none" stroke="' . $color . '" stroke-width="2"/>';
            }
            foreach ( $pts as $p ) {
                $cx = $xScale( strtotime( $p['date'] ) );
                $cy = $yScale( (float)$p['value'] );
                $est = (int)( $p['estimated'] ?? 0 );
                $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ( $est ? 3 : 3.5 )
                        . '" fill="' . ( $est ? '#1a1a1a' : $color )
                        . '" stroke="' . $color . '" stroke-width="' . ( $est ? 1.5 : 1 ) . '">';
                $svg .= '<title>' . htmlspecialchars( $legendLabel . ': '
                        . round( (float)$p['value'], 2 ) . ' on ' . $p['date']
                        . ( $est ? ' (estimated)' : '' ) ) . '</title>';
                $svg .= '</circle>';
            }
            $i++;
        }

        // Legend
        $lx = $pad['left'] + $plotW + 15;
        $ly = $pad['top'] + 4;
        foreach ( $legend as $entry ) {
            $svg .= '<rect x="' . $lx . '" y="' . ( $ly - 6 ) . '" width="12" height="3" fill="' . $entry['color'] . '"/>';
            $svg .= '<text x="' . ( $lx + 16 ) . '" y="' . ( $ly - 1 )
                    . '" fill="#c4b5fd" font-size="11">' . htmlspecialchars( $entry['label'] ) . '</text>';
            $ly += 16;
        }

        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Build a v0.11 widget struct from a legacy row's iso/precision/display columns,
     * for events that predate the le_date_struct column.
     */
    public static function structFromLegacy( $event ): ?array {
        $iso = isset( $event->le_date_iso ) ? (string)$event->le_date_iso : '';
        if ( $iso === '' ) return null;
        $prec = (int)( $event->le_date_precision ?? self::DP_DAY );
        $display = isset( $event->le_date_display ) ? (string)$event->le_date_display : '';
        $precMap = [
            self::DP_DAY        => 'day',
            self::DP_MONTH      => 'month',
            self::DP_YEAR       => 'year',
            self::DP_DECADE     => 'decade',
            self::DP_APPROX_AGE => 'approx-age',
            self::DP_UNKNOWN    => 'day',
        ];
        return [
            'kind' => 'point',
            'point' => [
                'raw_text' => $display !== '' ? $display : $iso,
                'parsed' => [
                    'kind'      => 'point',
                    'precision' => $precMap[ $prec ] ?? 'day',
                    'display'   => $display !== '' ? $display : null,
                    'year'      => (int)substr( $iso, 0, 4 ),
                    'month'     => (int)substr( $iso, 5, 2 ),
                    'day'       => (int)substr( $iso, 8, 2 ),
                    'iso'       => $iso,
                ],
                'time'          => null,
                'timezone'      => null,
                'effective_iso' => $iso,
            ],
        ];
    }

    /** Decode an event's le_date_struct or synthesize from legacy columns. Returns null if neither. */
    public static function eventStruct( $event ): ?array {
        if ( !empty( $event->le_date_struct ) ) {
            $d = json_decode( (string)$event->le_date_struct, true );
            if ( is_array( $d ) ) return $d;
        }
        return self::structFromLegacy( $event );
    }

    // ===== Permission helpers =====

    /** Is this viewer allowed to see this event? Mirrors UserProfile minVis logic. */

    // ===== Phase: observations =====

    /**
     * Insert a new observation (TYPE_OBSERVATION = 3) event.
     * Fields: raw_text (string), title (string), date_struct (array|null),
     *         polarity (int 0/1 or null), visibility (int 0..3).
     */
    public function addObservation( int $profileId, array $fields ): int {
        $dbw = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getPrimaryDatabase();
        $now = $dbw->timestamp();
        $iso = null;
        if ( isset( $fields['date_struct']['point']['parsed']['iso'] ) ) {
            $iso = (string)$fields['date_struct']['point']['parsed']['iso'];
        }
        $dbw->newInsertQueryBuilder()
            ->insertInto( 'pcp_life_events' )
            ->row( [
                'le_profile_id'     => $profileId,
                'le_type'           => self::TYPE_OBSERVATION,
                'le_title'          => (string)( $fields['title'] ?? '' ),
                'le_body'           => null,
                'le_tags'           => null,
                'le_visibility'     => (int)( $fields['visibility'] ?? 0 ),
                'le_polarity'       => isset( $fields['polarity'] ) && $fields['polarity'] !== null ? (int)$fields['polarity'] : null,
                'le_raw_text'       => (string)( $fields['raw_text'] ?? '' ),
                'le_date_iso'       => $iso,
                'le_date_struct'    => $fields['date_struct'] ? json_encode( $fields['date_struct'] ) : null,
                'le_date_display'   => isset( $fields['date_struct']['point']['raw_text'] ) ? (string)$fields['date_struct']['point']['raw_text'] : '',
                'le_date_precision' => self::DP_DAY,
                'le_created'        => $now,
                'le_updated'        => $now,
            ] )
            ->caller( __METHOD__ )
            ->execute();
        return (int)$dbw->insertId();
    }

    /**
     * Replace all refs for an event. Each ref is { role, type, id, text, label, matched }.
     */
    public function setEventRefs( int $eventId, array $refs ): void {
        $dbw = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getPrimaryDatabase();
        $dbw->newDeleteQueryBuilder()
            ->deleteFrom( 'pcp_life_event_refs' )
            ->where( [ 'ler_event_id' => $eventId ] )
            ->caller( __METHOD__ )
            ->execute();
        $now = $dbw->timestamp();
        foreach ( $refs as $r ) {
            $type = (string)( $r['type'] ?? 'free' );
            $dbw->newInsertQueryBuilder()
                ->insertInto( 'pcp_life_event_refs' )
                ->row( [
                    'ler_event_id'  => $eventId,
                    'ler_ref_type'  => $type,
                    'ler_ref_id'    => ( $type !== 'free' && !empty( $r['id'] ) ) ? (int)$r['id'] : null,
                    'ler_ref_text'  => (string)( $r['text'] ?? '' ),
                    'ler_role'      => (string)( $r['role'] ?? 'subject' ),
                    'ler_created'   => $now,
                ] )
                ->caller( __METHOD__ )
                ->execute();
        }
    }

    /**
     * Get refs for an event. Hydrates labels from the linked table when possible.
     */
    public function getRefsForEvent( int $eventId ): array {
        $dbr = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();
        $rows = $dbr->newSelectQueryBuilder()
            ->select( '*' )
            ->from( 'pcp_life_event_refs' )
            ->where( [ 'ler_event_id' => $eventId ] )
            ->orderBy( 'ler_id' )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'id'      => (int)$r->ler_id,
                'role'    => (string)$r->ler_role,
                'type'    => (string)$r->ler_ref_type,
                'ref_id'  => $r->ler_ref_id !== null ? (int)$r->ler_ref_id : null,
                'text'    => (string)$r->ler_ref_text,
                'matched' => $r->ler_ref_id !== null,
            ];
        }
        return $out;
    }


    // ===== Episodes =====

    /**
     * Insert a new episode event (TYPE_EPISODE = 4).
     * Fields: episode_type, episode_subtype, severity, date_struct (range JSON),
     *         title, body, visibility, tags.
     */
    public function addEpisode( int $profileId, array $fields ): int {
        $dbw = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getPrimaryDatabase();
        $now = $dbw->timestamp();
        $iso = $this->isoFromStruct( $fields['date_struct'] ?? null );
        $dispText = $this->dateDisplayFromStruct( $fields['date_struct'] ?? null );
        $dbw->newInsertQueryBuilder()
            ->insertInto( 'pcp_life_events' )
            ->row( [
                'le_profile_id'      => $profileId,
                'le_type'            => self::TYPE_EPISODE,
                'le_title'           => (string)( $fields['title'] ?? '' ),
                'le_body'            => $fields['body'] ?? null,
                'le_tags'            => $fields['tags'] ?? null,
                'le_visibility'      => (int)( $fields['visibility'] ?? 0 ),
                'le_episode_type'    => (string)( $fields['episode_type'] ?? '' ) ?: null,
                'le_episode_subtype' => (string)( $fields['episode_subtype'] ?? '' ) ?: null,
                'le_severity'        => isset( $fields['severity'] ) && $fields['severity'] !== '' ? (float)$fields['severity'] : null,
                'le_date_iso'        => $iso,
                'le_date_struct'     => $fields['date_struct'] ? json_encode( $fields['date_struct'] ) : null,
                'le_date_display'    => $dispText,
                'le_date_precision'  => self::DP_DAY,
                'le_created'         => $now,
                'le_updated'         => $now,
            ] )
            ->caller( __METHOD__ )
            ->execute();
        return (int)$dbw->insertId();
    }

    public function updateEpisode( int $eventId, array $fields ): void {
        $dbw = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getPrimaryDatabase();
        $iso = $this->isoFromStruct( $fields['date_struct'] ?? null );
        $dispText = $this->dateDisplayFromStruct( $fields['date_struct'] ?? null );
        $set = [
            'le_title'           => (string)( $fields['title'] ?? '' ),
            'le_body'            => $fields['body'] ?? null,
            'le_visibility'      => (int)( $fields['visibility'] ?? 0 ),
            'le_episode_type'    => (string)( $fields['episode_type'] ?? '' ) ?: null,
            'le_episode_subtype' => (string)( $fields['episode_subtype'] ?? '' ) ?: null,
            'le_severity'        => isset( $fields['severity'] ) && $fields['severity'] !== '' ? (float)$fields['severity'] : null,
            'le_date_iso'        => $iso,
            'le_date_struct'     => $fields['date_struct'] ? json_encode( $fields['date_struct'] ) : null,
            'le_date_display'    => $dispText,
            'le_updated'         => $dbw->timestamp(),
        ];
        $dbw->newUpdateQueryBuilder()
            ->update( 'pcp_life_events' )
            ->set( $set )
            ->where( [ 'le_id' => $eventId ] )
            ->caller( __METHOD__ )
            ->execute();
    }

    /**
     * Extract a sortable ISO date from a date_struct (point: parsed.iso;
     * range: from.parsed.iso).
     */
    private function isoFromStruct( $struct ): ?string {
        if ( !is_array( $struct ) ) return null;
        if ( ( $struct['kind'] ?? '' ) === 'range' ) {
            return $struct['from']['parsed']['iso'] ?? null;
        }
        if ( ( $struct['kind'] ?? '' ) === 'point' ) {
            return $struct['point']['parsed']['iso'] ?? null;
        }
        return null;
    }

    private function dateDisplayFromStruct( $struct ): string {
        if ( !is_array( $struct ) ) return '';
        if ( ( $struct['kind'] ?? '' ) === 'range' ) {
            $f = $struct['from']['raw_text']    ?? ( $struct['from']['parsed']['iso']    ?? '' );
            $t = $struct['through']['raw_text'] ?? ( $struct['through']['parsed']['iso'] ?? 'ongoing' );
            return "$f - $t";
        }
        if ( ( $struct['kind'] ?? '' ) === 'point' ) {
            return (string)( $struct['point']['raw_text'] ?? $struct['point']['parsed']['iso'] ?? '' );
        }
        return '';
    }


    /**
     * Duplicate an event (any type) for the given profile. Copies row fields
     * + refs; does NOT copy images. Returns the new event id.
     */
    public function duplicateEvent( int $sourceEventId, int $profileId ): int {
        $src = $this->getEvent( $sourceEventId );
        if ( !$src || (int)$src->le_profile_id !== $profileId ) {
            throw new \RuntimeException( 'Cannot duplicate: event not found or not owned' );
        }
        $dbw = \MediaWiki\MediaWikiServices::getInstance()
            ->getConnectionProvider()->getPrimaryDatabase();
        $now = $dbw->timestamp();
        $row = [
            'le_profile_id'      => $profileId,
            'le_type'            => (int)$src->le_type,
            'le_title'           => 'Copy of ' . (string)$src->le_title,
            'le_body'            => $src->le_body,
            'le_tags'            => $src->le_tags,
            'le_visibility'      => (int)$src->le_visibility,
            'le_polarity'        => $src->le_polarity !== null ? (int)$src->le_polarity : null,
            'le_raw_text'        => $src->le_raw_text,
            'le_episode_type'    => $src->le_episode_type,
            'le_episode_subtype' => $src->le_episode_subtype,
            'le_severity'        => $src->le_severity !== null ? (float)$src->le_severity : null,
            'le_date_iso'        => $src->le_date_iso,
            'le_date_struct'     => $src->le_date_struct,
            'le_date_display'    => $src->le_date_display,
            'le_date_precision'  => (int)$src->le_date_precision,
            'le_created'         => $now,
            'le_updated'         => $now,
        ];
        $dbw->newInsertQueryBuilder()
            ->insertInto( 'pcp_life_events' )
            ->row( $row )
            ->caller( __METHOD__ )
            ->execute();
        $newId = (int)$dbw->insertId();
        // Copy refs.
        $refs = $this->getRefsForEvent( $sourceEventId );
        $copyRefs = [];
        foreach ( $refs as $r ) {
            $copyRefs[] = [
                'role'    => $r['role'],
                'type'    => $r['type'],
                'id'      => $r['ref_id'],
                'text'    => $r['text'],
                'matched' => $r['matched'],
            ];
        }
        if ( $copyRefs ) $this->setEventRefs( $newId, $copyRefs );
        // Copy keyframe traits if this is a keyframe.
        if ( (int)$src->le_type === self::TYPE_KEYFRAME ) {
            $traits = $this->getTraitsForEvent( $sourceEventId );
            $copyTraits = [];
            foreach ( $traits as $t ) {
                $copyTraits[] = [
                    'namespace' => (string)$t->lt_namespace,
                    'key'       => (string)$t->lt_key,
                    'label'     => $t->lt_label !== null ? (string)$t->lt_label : null,
                    'value'     => (float)$t->lt_value_num,
                    'min'       => $t->lt_min !== null ? (float)$t->lt_min : null,
                    'max'       => $t->lt_max !== null ? (float)$t->lt_max : null,
                    'estimated' => (int)$t->lt_estimated,
                ];
            }
            if ( $copyTraits ) $this->setTraits( $newId, $copyTraits );
        }
        return $newId;
    }

    public function canViewEvent( \stdClass $event, ?int $viewerProfileId, bool $viewerIsSysop ): bool {
        if ( $viewerIsSysop ) return true;
        if ( $viewerProfileId !== null && (int)$event->le_profile_id === $viewerProfileId ) return true;
        return (int)$event->le_visibility > 0;
    }

    /**
     * Sync the auto-created "Born" keyframe to match the user's current birthday.
     *
     * - Auto-created event is tagged "auto-birth" so we can find it later.
     * - Updates touch ONLY the date columns; title/body/tags/visibility/images/
     *   traits/refs that the user may have edited are preserved across syncs.
     * - When the birthday is cleared, the existing event is left in place
     *   (the user may have attached content to it). Manual delete via the
     *   card list if they want it gone.
     */
    public function syncBirthEvent( int $profileId, ?string $birthdayJson ): void {
        // Resolve the auto-birth event id (if any) by tag match.
        $dbw = $this->dbw();
        $existingId = (int)$dbw->newSelectQueryBuilder()
            ->select( 'le_id' )
            ->from( 'pcp_life_events' )
            ->where( [ 'le_profile_id' => $profileId ] )
            ->andWhere( "le_tags LIKE " . $dbw->addQuotes( '%auto-birth%' ) )
            ->orderBy( 'le_id' )
            ->limit( 1 )
            ->caller( __METHOD__ )
            ->fetchField();

        // Empty birthday: leave any existing event alone, don't create.
        if ( $birthdayJson === null || $birthdayJson === '' ) {
            return;
        }

        // Parse the DatePicker JSON. Expect a "point" struct.
        $struct = json_decode( $birthdayJson, true );
        if ( !is_array( $struct ) ) return;
        if ( ( $struct['kind'] ?? '' ) !== 'point' ) return;
        $point = $struct['point'] ?? null;
        if ( !is_array( $point ) ) return;
        $parsed = $point['parsed'] ?? null;
        if ( !is_array( $parsed ) ) return;
        $iso = (string)( $parsed['iso'] ?? '' );
        if ( $iso === '' ) return;
        $precRaw = (string)( $parsed['precision'] ?? 'day' );
        $precision = self::DP_DAY;
        if ( $precRaw === 'month' )      $precision = self::DP_MONTH;
        elseif ( $precRaw === 'year' )   $precision = self::DP_YEAR;
        $display = (string)( $point['raw_text'] ?? $iso );

        // Wrap the birthday into a keyframe-shaped date_struct.
        $kfStruct = [
            'kind'  => 'point',
            'point' => [
                'raw_text' => $display,
                'parsed'   => [ 'kind' => 'point', 'precision' => $precRaw, 'iso' => $iso ],
            ],
        ];
        $kfStructJson = json_encode( $kfStruct, JSON_UNESCAPED_UNICODE );

        if ( $existingId > 0 ) {
            // Update ONLY the date columns. Preserve title/body/tags/visibility.
            $dbw->update( 'pcp_life_events', [
                'le_date_iso'       => $iso,
                'le_date_precision' => $precision,
                'le_date_display'   => $display,
                'le_date_struct'    => $kfStructJson,
                'le_updated'        => $dbw->timestamp(),
            ], [ 'le_id' => $existingId ], __METHOD__ );
            return;
        }

        // Create a fresh auto-birth event.
        $this->addEvent( $profileId, [
            'date_iso'       => $iso,
            'date_precision' => $precision,
            'date_display'   => $display,
            'date_struct'    => $kfStructJson,
            'type'           => self::TYPE_STORY,
            'title'          => 'Born!',
            'body'           => null,
            'visibility'     => 0,
            'tags'           => 'auto-birth',
        ] );
    }

}
