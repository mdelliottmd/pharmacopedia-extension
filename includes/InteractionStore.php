<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

class InteractionStore {
    use InteractionFlagTrait;

    const PERSPECTIVE_USER     = 1;
    const PERSPECTIVE_PROVIDER = 2;

    const TYPE_MEDICINE    = 'medicine';
    const TYPE_CATEGORY    = 'category';
    const TYPE_ENZYME      = 'enzyme';
    const TYPE_TRANSPORTER = 'transporter';
    const TYPE_PHENOTYPE   = 'phenotype';
    const TYPE_VARIANT     = 'variant';

    /** Canonical placeholder when no relationship is specified (legacy rows). */
    const REL_UNSPECIFIED = 'unspecified';

    /** Severity threshold for vmean (matches the effects bucket). */
    const SEVERE_VMEAN = -83.0;  // rescaled 2026-05-17 with valence widening (was -2.5 on -3..+3 scale)

    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }
    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    /**
     * Canonicalize a slug. Behavior depends on type so each endpoint
     * namespace uses its idiomatic casing convention:
     *   medicine / category  -> MW NS_MAIN page-title style (first-letter cap)
     *   enzyme / transporter -> uppercase canonical gene/protein symbol (CYP2D6)
     *   phenotype / variant  -> preserved as-given (cyp2d6_pm, hla-b_5701)
     * The legacy 1-arg signature continues to behave as before so existing
     * callers (manage-interactions UI, add-pair API) are unaffected.
     */
    public static function normalizeSlug( $s, $type = null ) {
        $s = trim( (string)$s );
        $s = preg_replace( '/\s+/u', '_', $s );
        if ( $s === '' ) { return ''; }
        if ( $type === self::TYPE_ENZYME || $type === self::TYPE_TRANSPORTER ) {
            return strtoupper( $s );
        }
        if ( $type === self::TYPE_PHENOTYPE || $type === self::TYPE_VARIANT ) {
            // Lower-case the gene-symbol portion of phenotype slugs (cyp2d6_pm)
            // but preserve the full token's existing capitalization for variant
            // slugs that may carry star-allele asterisks etc.
            if ( $type === self::TYPE_PHENOTYPE ) return strtolower( $s );
            return $s;
        }
        return mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 );
    }

    public static function isValidType( $t ) {
        return $t === self::TYPE_MEDICINE
            || $t === self::TYPE_CATEGORY
            || $t === self::TYPE_ENZYME
            || $t === self::TYPE_TRANSPORTER
            || $t === self::TYPE_PHENOTYPE
            || $t === self::TYPE_VARIANT;
    }

    /**
     * Normalize a pair into canonical (left,right) order.
     * Returns [ leftType, leftSlug, rightType, rightSlug ] or null for an invalid / self pair.
     */
    public static function normalizePair( $aType, $aSlug, $bType, $bSlug ) {
        if ( !self::isValidType( $aType ) || !self::isValidType( $bType ) ) {
            return null;
        }
        $aSlug = self::normalizeSlug( $aSlug, $aType );
        $bSlug = self::normalizeSlug( $bSlug, $bType );
        if ( $aSlug === '' || $bSlug === '' ) { return null; }
        if ( $aType === $bType && $aSlug === $bSlug ) { return null; }
        $cmp = strcmp( $aType, $bType );
        if ( $cmp === 0 ) { $cmp = strcmp( $aSlug, $bSlug ); }
        if ( $cmp > 0 ) {
            return [ $bType, $bSlug, $aType, $aSlug ];
        }
        return [ $aType, $aSlug, $bType, $bSlug ];
    }

    /**
     * Slug used on pcp_votable_elements.ve_slug. Multi-edge pairs (same two
     * endpoints, different relationships) get distinct slugs by appending
     * the relationship; legacy 'unspecified' edges keep the original slug
     * for back-compat with existing votable_element rows.
     */
    public static function elementSlugFor( $lt, $ls, $rt, $rs, $rel = self::REL_UNSPECIFIED ) {
        $base = 'pcp-interaction:' . $lt . ':' . strtolower( $ls )
              . '::' . $rt . ':' . strtolower( $rs );
        if ( $rel === self::REL_UNSPECIFIED || $rel === '' ) return $base;
        return $base . '::' . strtolower( $rel );
    }
    /** Human-readable label, ascii arrow + namespace prefix. */
    public static function pairLabel( $lt, $ls, $rt, $rs, $rel = self::REL_UNSPECIFIED ) {
        $disp = function ( $t, $s ) {
            $name = str_replace( '_', ' ', $s );
            switch ( $t ) {
                case self::TYPE_CATEGORY:    return 'Category:' . $name;
                case self::TYPE_ENZYME:      return 'Enzyme:' . $name;
                case self::TYPE_TRANSPORTER: return 'Transporter:' . $name;
                case self::TYPE_PHENOTYPE:   return 'Phenotype:' . $name;
                case self::TYPE_VARIANT:     return 'Variant:' . $name;
                default:                     return $name;
            }
        };
        $label = $disp( $lt, $ls ) . ' <-> ' . $disp( $rt, $rs );
        if ( $rel !== self::REL_UNSPECIFIED && $rel !== '' ) $label .= ' (' . $rel . ')';
        return $label;
    }

    /**
     * Find or create the interaction (and its backing votable element).
     * Returns the pcp_interactions row.
     *
     * $opts (all optional) drives Phase-1 pharmacogenomics metadata:
     *   - relationship  string  one of the vocabulary slugs (substrate_major,
     *                           inhibitor_strong, pk_via_CYP2D6, avoid, ...);
     *                           defaults to 'unspecified' for legacy edges
     *   - intensity     int     0..100 strength scalar
     *   - evidence      string  cpic_A / cpic_strong / fda_box / derived / ...
     *   - mechanism     string  freeform prose; truncated to 255 chars
     *   - kinetics      string  reversible_competitive / mechanism_based / ...
     *
     * When the row already exists, supplied metadata fields are applied as an
     * upsert (overwriting differing values; leaves null-supplied fields alone).
     */
    public function getOrCreate( $aType, $aSlug, $bType, $bSlug, $userId, array $opts = [] ) {
        $pair = self::normalizePair( $aType, $aSlug, $bType, $bSlug );
        if ( !$pair ) { return null; }
        [ $lt, $ls, $rt, $rs ] = $pair;
        $rel = isset( $opts['relationship'] ) && $opts['relationship'] !== ''
            ? (string)$opts['relationship']
            : self::REL_UNSPECIFIED;
        $dbw = $this->dbw();
        $row = $dbw->selectRow(
            'pcp_interactions', '*',
            [ 'pi_left_type' => $lt, 'pi_left_slug' => $ls,
              'pi_right_type' => $rt, 'pi_right_slug' => $rs,
              'pi_relationship' => $rel ],
            __METHOD__
        );
        if ( $row ) {
            $this->maybeUpdateMetadata( $row, $opts );
            return $this->getById( (int)$row->pi_id );
        }

        // Create the backing votable element (page_id = 0 means "global, not page-bound").
        $elementSlug = self::elementSlugFor( $lt, $ls, $rt, $rs, $rel );
        $element = ( new ElementStore() )->getOrCreate(
            0, $elementSlug, 'interaction', self::pairLabel( $lt, $ls, $rt, $rs, $rel )
        );
        if ( !$element ) { return null; }

        $fields = [
            'pi_element_id'      => (int)$element->ve_id,
            'pi_left_type'       => $lt,
            'pi_left_slug'       => $ls,
            'pi_right_type'      => $rt,
            'pi_right_slug'      => $rs,
            'pi_relationship'    => $rel,
            'pi_created_user_id' => (int)$userId,
            'pi_created'         => $dbw->timestamp(),
        ];
        // Provenance: stamp the source ingest run on insert only. Immutable
        // after creation — maybeUpdateMetadata() never touches pi_ingestion_id.
        if ( isset( $opts['ingestion_id'] ) && (int)$opts['ingestion_id'] > 0 ) {
            $fields['pi_ingestion_id'] = (int)$opts['ingestion_id'];
        }
        if ( isset( $opts['intensity'] ) && $opts['intensity'] !== null ) {
            $fields['pi_intensity'] = max( 0, min( 100, (int)$opts['intensity'] ) );
        }
        if ( isset( $opts['evidence'] ) && $opts['evidence'] !== '' ) {
            $fields['pi_evidence'] = (string)$opts['evidence'];
        }
        if ( isset( $opts['mechanism'] ) && $opts['mechanism'] !== '' ) {
            $fields['pi_mechanism'] = mb_substr( (string)$opts['mechanism'], 0, 2048 );
        }
        if ( isset( $opts['kinetics'] ) && $opts['kinetics'] !== '' ) {
            $fields['pi_kinetics'] = (string)$opts['kinetics'];
        }

        $dbw->insert( 'pcp_interactions', $fields, __METHOD__, [ 'IGNORE' ] );

        return $dbw->selectRow(
            'pcp_interactions', '*',
            [ 'pi_left_type' => $lt, 'pi_left_slug' => $ls,
              'pi_right_type' => $rt, 'pi_right_slug' => $rs,
              'pi_relationship' => $rel ],
            __METHOD__
        );
    }

    /**
     * Apply non-empty $opts metadata onto an existing row.
     * Returns true if any column actually changed.
     */
    private function maybeUpdateMetadata( $row, array $opts ): bool {
        $set = [];
        if ( isset( $opts['intensity'] ) && $opts['intensity'] !== null ) {
            $v = max( 0, min( 100, (int)$opts['intensity'] ) );
            if ( (int)( $row->pi_intensity ?? -1 ) !== $v ) $set['pi_intensity'] = $v;
        }
        if ( isset( $opts['evidence'] ) && $opts['evidence'] !== '' ) {
            $v = (string)$opts['evidence'];
            if ( (string)( $row->pi_evidence ?? '' ) !== $v ) $set['pi_evidence'] = $v;
        }
        if ( isset( $opts['mechanism'] ) && $opts['mechanism'] !== '' ) {
            $v = mb_substr( (string)$opts['mechanism'], 0, 2048 );
            if ( (string)( $row->pi_mechanism ?? '' ) !== $v ) $set['pi_mechanism'] = $v;
        }
        if ( isset( $opts['kinetics'] ) && $opts['kinetics'] !== '' ) {
            $v = (string)$opts['kinetics'];
            if ( (string)( $row->pi_kinetics ?? '' ) !== $v ) $set['pi_kinetics'] = $v;
        }
        if ( !$set ) return false;
        $this->dbw()->update( 'pcp_interactions', $set,
            [ 'pi_id' => (int)$row->pi_id ], __METHOD__ );
        return true;
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
