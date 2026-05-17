<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

class InteractionStore {
    const PERSPECTIVE_USER     = 1;
    const PERSPECTIVE_PROVIDER = 2;

    const TYPE_MEDICINE = 'medicine';
    const TYPE_CATEGORY = 'category';

    /** Severity threshold for vmean (matches the effects bucket). */
    const SEVERE_VMEAN = -83.0;  // rescaled 2026-05-17 with valence widening (was -2.5 on -3..+3 scale)

    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }
    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    /** Page-title style: trim, collapse spaces, MW underscore convention. */
    public static function normalizeSlug( $s ) {
        $s = trim( (string)$s );
        $s = preg_replace( '/\s+/u', '_', $s );
        // MW page-title case sensitivity: first letter capitalized for NS_MAIN / NS_CATEGORY.
        if ( $s === '' ) { return ''; }
        return mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 );
    }

    public static function isValidType( $t ) {
        return $t === self::TYPE_MEDICINE || $t === self::TYPE_CATEGORY;
    }

    /**
     * Normalize a pair into canonical (left,right) order.
     * Returns [ leftType, leftSlug, rightType, rightSlug ] or null for an invalid / self pair.
     */
    public static function normalizePair( $aType, $aSlug, $bType, $bSlug ) {
        if ( !self::isValidType( $aType ) || !self::isValidType( $bType ) ) {
            return null;
        }
        $aSlug = self::normalizeSlug( $aSlug );
        $bSlug = self::normalizeSlug( $bSlug );
        if ( $aSlug === '' || $bSlug === '' ) { return null; }
        if ( $aType === $bType && $aSlug === $bSlug ) { return null; }
        $cmp = strcmp( $aType, $bType );
        if ( $cmp === 0 ) { $cmp = strcmp( $aSlug, $bSlug ); }
        if ( $cmp > 0 ) {
            return [ $bType, $bSlug, $aType, $aSlug ];
        }
        return [ $aType, $aSlug, $bType, $bSlug ];
    }

    /** Slug used on pcp_votable_elements.ve_slug. */
    public static function elementSlugFor( $lt, $ls, $rt, $rs ) {
        return 'pcp-interaction:' . $lt . ':' . strtolower( $ls ) . '::' . $rt . ':' . strtolower( $rs );
    }
    /** Human-readable label, ascii arrow. */
    public static function pairLabel( $lt, $ls, $rt, $rs ) {
        $disp = function ( $t, $s ) {
            $name = str_replace( '_', ' ', $s );
            return $t === self::TYPE_CATEGORY ? 'Category:' . $name : $name;
        };
        return $disp( $lt, $ls ) . ' <-> ' . $disp( $rt, $rs );
    }

    /**
     * Find or create the interaction (and its backing votable element).
     * Returns the pcp_interactions row.
     */
    public function getOrCreate( $aType, $aSlug, $bType, $bSlug, $userId ) {
        $pair = self::normalizePair( $aType, $aSlug, $bType, $bSlug );
        if ( !$pair ) { return null; }
        [ $lt, $ls, $rt, $rs ] = $pair;
        $dbw = $this->dbw();
        $row = $dbw->selectRow(
            'pcp_interactions', '*',
            [ 'pi_left_type' => $lt, 'pi_left_slug' => $ls,
              'pi_right_type' => $rt, 'pi_right_slug' => $rs ],
            __METHOD__
        );
        if ( $row ) { return $row; }

        // Create the backing votable element (page_id = 0 means "global, not page-bound").
        $elementSlug = self::elementSlugFor( $lt, $ls, $rt, $rs );
        $element = ( new ElementStore() )->getOrCreate(
            0, $elementSlug, 'interaction', self::pairLabel( $lt, $ls, $rt, $rs )
        );
        if ( !$element ) { return null; }

        $dbw->insert( 'pcp_interactions', [
            'pi_element_id'      => (int)$element->ve_id,
            'pi_left_type'       => $lt,
            'pi_left_slug'       => $ls,
            'pi_right_type'      => $rt,
            'pi_right_slug'      => $rs,
            'pi_created_user_id' => (int)$userId,
            'pi_created'         => $dbw->timestamp(),
        ], __METHOD__, [ 'IGNORE' ] );

        return $dbw->selectRow(
            'pcp_interactions', '*',
            [ 'pi_left_type' => $lt, 'pi_left_slug' => $ls,
              'pi_right_type' => $rt, 'pi_right_slug' => $rs ],
            __METHOD__
        );
    }

    /** No-create lookup; returns the row or null. Args MUST be pre-normalized. */
    public function findPair( $lt, $ls, $rt, $rs ) {
        return $this->dbr()->selectRow( 'pcp_interactions', '*', [
            'pi_left_type'  => $lt, 'pi_left_slug'  => $ls,
            'pi_right_type' => $rt, 'pi_right_slug' => $rs,
        ], __METHOD__ );
    }

    public function getByElementId( $elementId ) {
        return $this->dbr()->selectRow( 'pcp_interactions', '*',
            [ 'pi_element_id' => (int)$elementId ], __METHOD__ );
    }
    public function getById( $id ) {
        return $this->dbr()->selectRow( 'pcp_interactions', '*',
            [ 'pi_id' => (int)$id ], __METHOD__ );
    }

    /**
     * All interaction rows where (type, slug) appears as either side.
     */
    public function listForEndpoint( $type, $slug ) {
        $slug = self::normalizeSlug( $slug );
        $dbr = $this->dbr();
        $left = $dbr->select( 'pcp_interactions', '*',
            [ 'pi_left_type' => $type, 'pi_left_slug' => $slug ], __METHOD__ );
        $right = $dbr->select( 'pcp_interactions', '*',
            [ 'pi_right_type' => $type, 'pi_right_slug' => $slug ], __METHOD__ );
        $rows = [];
        foreach ( $left  as $r ) { $rows[ (int)$r->pi_id ] = $r; }
        foreach ( $right as $r ) { $rows[ (int)$r->pi_id ] = $r; }
        return array_values( $rows );
    }

    /**
     * For a medicine page: direct rows + transitive rows via the given category slugs.
     * Returns array of [ 'row' => $piRow, 'other_type' => ..., 'other_slug' => ...,
     *                    'via' => null|categorySlug ].
     * If a pair is matched both directly and transitively, the direct match wins.
     */
    public function listForMedicineWithCategories( $medicineSlug, array $categorySlugs ) {
        $medicineSlug = self::normalizeSlug( $medicineSlug );
        $direct = $this->listForEndpoint( self::TYPE_MEDICINE, $medicineSlug );

        // De-dup by COUNTERPARTY (other_type|other_slug), not canonical pair: two
        // different pair rows can point at the same other party (e.g. a direct
        // Fluoxetine<->Tramadol and a transitive Category:SSRI<->Tramadol both
        // resolve to Tramadol). Direct wins, then first-transitive wins.
        $byCounter = [];
        $otherSideMed = function ( $r ) use ( $medicineSlug ) {
            if ( $r->pi_left_type === self::TYPE_MEDICINE && $r->pi_left_slug === $medicineSlug ) {
                return [ $r->pi_right_type, $r->pi_right_slug ];
            }
            return [ $r->pi_left_type, $r->pi_left_slug ];
        };
        foreach ( $direct as $r ) {
            [ $ot, $os ] = $otherSideMed( $r );
            $byCounter[ $ot . '|' . $os ] = [
                'row' => $r,
                'other_type' => $ot,
                'other_slug' => $os,
                'via' => null,
            ];
        }

        foreach ( $categorySlugs as $cat ) {
            $cat = self::normalizeSlug( $cat );
            if ( $cat === '' ) { continue; }
            $rows = $this->listForEndpoint( self::TYPE_CATEGORY, $cat );
            foreach ( $rows as $r ) {
                // Pick the "other" side: the one that is NOT this category.
                if ( $r->pi_left_type === self::TYPE_CATEGORY && $r->pi_left_slug === $cat ) {
                    $ot = $r->pi_right_type; $os = $r->pi_right_slug;
                } else {
                    $ot = $r->pi_left_type; $os = $r->pi_left_slug;
                }
                // Defensive: skip if the "other side" is the medicine itself (shouldn't
                // happen since normalize rejects self-pairs, but cheap to guard).
                if ( $ot === self::TYPE_MEDICINE && $os === $medicineSlug ) { continue; }
                $ck = $ot . '|' . $os;
                if ( isset( $byCounter[ $ck ] ) ) { continue; } // direct or earlier transitive wins
                $byCounter[ $ck ] = [
                    'row' => $r,
                    'other_type' => $ot,
                    'other_slug' => $os,
                    'via' => $cat,
                ];
            }
        }
        return array_values( $byCounter );
    }

    /**
     * For a category page: direct rows only. Returns the same shape as above (via=null).
     */
    public function listForCategory( $categorySlug ) {
        $cat = self::normalizeSlug( $categorySlug );
        $rows = $this->listForEndpoint( self::TYPE_CATEGORY, $cat );
        $out = [];
        foreach ( $rows as $r ) {
            // "other side" is whichever side isn't this category. If both are
            // category-typed and one IS this category, that's the self-side;
            // the other is shown. If both are categories and neither equals
            // (shouldn't happen via listForEndpoint), pick right.
            if ( $r->pi_left_type === self::TYPE_CATEGORY && $r->pi_left_slug === $cat ) {
                $ot = $r->pi_right_type; $os = $r->pi_right_slug;
            } else {
                $ot = $r->pi_left_type; $os = $r->pi_left_slug;
            }
            $out[] = [
                'row' => $r,
                'other_type' => $ot,
                'other_slug' => $os,
                'via' => null,
            ];
        }
        return $out;
    }

    // ===== Reports =====

    public function getUserReport( $elementId, $userId, $perspective ) {
        return $this->dbr()->selectRow( 'pcp_interaction_reports', '*', [
            'pir_element_id'  => (int)$elementId,
            'pir_voter_hash'  => $this->voterHash( $userId ),
            'pir_perspective' => (int)$perspective,
        ], __METHOD__ );
    }

    /**
     * Upsert a report. Any of $experience / $valence / $note may be null to clear.
     */
    public function submitReport( $elementId, $userId, $perspective, $experience, $valence, $note ) {
        $hash = $this->voterHash( $userId );
        $dbw = $this->dbw();
        $existing = $dbw->selectRow( 'pcp_interaction_reports', 'pir_id', [
            'pir_element_id'  => (int)$elementId,
            'pir_voter_hash'  => $hash,
            'pir_perspective' => (int)$perspective,
        ], __METHOD__ );
        $fields = [
            'pir_experience' => $experience === null ? null : (int)$experience,
            'pir_valence'    => $valence    === null ? null : (int)$valence,
            'pir_note'       => $note,
            'pir_updated'    => $dbw->timestamp(),
        ];
        if ( $existing ) {
            $dbw->update( 'pcp_interaction_reports', $fields,
                [ 'pir_id' => $existing->pir_id ], __METHOD__ );
        } else {
            $dbw->insert( 'pcp_interaction_reports', $fields + [
                'pir_element_id'  => (int)$elementId,
                'pir_voter_hash'  => $hash,
                'pir_perspective' => (int)$perspective,
                'pir_created'     => $dbw->timestamp(),
            ], __METHOD__ );
        }
    }

    /**
     * Aggregate (experience, valence) for an interaction, optionally filtered by perspective.
     * Returns [ 'n' => int, 'experience_mean' => float|null, 'experience_n' => int,
     *           'valence_mean' => float|null, 'valence_n' => int, 'severe' => bool ]
     */
    public function getAggregates( $elementId, $perspective = null ) {
        $cond = [ 'pir_element_id' => (int)$elementId ];
        if ( $perspective !== null ) { $cond['pir_perspective'] = (int)$perspective; }
        $row = $this->dbr()->selectRow( 'pcp_interaction_reports', [
            'n'           => 'COUNT(*)',
            'exp_sum'     => 'SUM(pir_experience)',
            'exp_n'       => 'SUM(CASE WHEN pir_experience IS NOT NULL THEN 1 ELSE 0 END)',
            'val_sum'     => 'SUM(pir_valence)',
            'val_n'       => 'SUM(CASE WHEN pir_valence IS NOT NULL THEN 1 ELSE 0 END)',
        ], $cond, __METHOD__ );
        if ( !$row || (int)$row->n === 0 ) {
            return [ 'n' => 0,
                'experience_mean' => null, 'experience_n' => 0,
                'valence_mean'    => null, 'valence_n'    => 0,
                'severe' => false ];
        }
        $en = (int)$row->exp_n;
        $vn = (int)$row->val_n;
        $vmean = $vn > 0 ? round( (float)$row->val_sum / $vn, 2 ) : null;
        return [
            'n'               => (int)$row->n,
            'experience_mean' => $en > 0 ? round( (float)$row->exp_sum / $en, 2 ) : null,
            'experience_n'    => $en,
            'valence_mean'    => $vmean,
            'valence_n'       => $vn,
            'severe'          => $vmean !== null && $vmean <= self::SEVERE_VMEAN,
        ];
    }

    /**
     * Hard-delete an interaction: drops the pcp_interactions row, its reports,
     * and the backing votable_element (and any votes/comments hanging off it).
     */
    public function deleteInteraction( $elementId ) {
        $dbw = $this->dbw();
        $dbw->delete( 'pcp_interaction_reports', [ 'pir_element_id' => (int)$elementId ], __METHOD__ );
        $dbw->delete( 'pcp_votes',               [ 'v_element_id'   => (int)$elementId ], __METHOD__ );
        $dbw->delete( 'pcp_comments',            [ 'c_element_id'   => (int)$elementId ], __METHOD__ );
        $dbw->delete( 'pcp_interactions',        [ 'pi_element_id'  => (int)$elementId ], __METHOD__ );
        $dbw->delete( 'pcp_votable_elements',    [ 've_id'          => (int)$elementId ], __METHOD__ );
    }

    /**
     * Clear just the free-text note on a single report; preserves experience/valence.
     */
    public function clearNote( $elementId, $userId, $perspective ) {
        $this->dbw()->update(
            'pcp_interaction_reports',
            [ 'pir_note' => null, 'pir_updated' => $this->dbw()->timestamp() ],
            [
                'pir_element_id'  => (int)$elementId,
                'pir_voter_hash'  => $this->voterHash( $userId ),
                'pir_perspective' => (int)$perspective,
            ],
            __METHOD__
        );
    }

        /**
     * All reports for an element with attached user names + timestamps.
     * For phase 5 (notes expansion).
     */
    public function listReports( $elementId ) {
        // Anonymized: no user join. Notes display without author attribution.
        $dbr = $this->dbr();
        $rows = $dbr->select(
            'pcp_interaction_reports',
            [ 'pir_perspective', 'pir_experience', 'pir_valence', 'pir_note',
              'pir_created', 'pir_updated' ],
            [ 'pir_element_id' => (int)$elementId ],
            __METHOD__,
            [ 'ORDER BY' => 'pir_updated DESC' ]
        );
        $out = [];
        foreach ( $rows as $r ) { $out[] = $r; }
        return $out;
    }

    /** Map a user id to its opaque voter hash. */
    public function voterHash( $userId ): string {
        global $wgPharmacopediaVoteHashSecret;
        if ( !$wgPharmacopediaVoteHashSecret ) {
            throw new \RuntimeException( '$wgPharmacopediaVoteHashSecret must be set in LocalSettings.php' );
        }
        return hash_hmac( 'sha256', (string)$userId, $wgPharmacopediaVoteHashSecret );
    }

}
