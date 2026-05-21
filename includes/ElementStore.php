<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\MediaWikiServices;

class ElementStore {
    private function dbw() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
    }
    private function dbr() {
        return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
    }

    public function getOrCreate( $pageId, $slug, $type, $label, $options = null, $optionsHash = null, $resultsPolicy = 'live', $openEnded = false, $maxOptions = 5 ) {
        $dbw = $this->dbw();
        $row = $dbw->selectRow(
            'pcp_votable_elements', '*',
            [ 've_page_id' => $pageId, 've_slug' => $slug ],
            __METHOD__
        );
        if ( $row ) {
            // If the element exists but stored type or options changed, update them.
            // (Page editor renamed options or switched type.)
            $changed = [];
            if ( (string)$row->ve_type !== (string)$type ) $changed['ve_type'] = $type;
            $newOpts = $options !== null ? json_encode( $options ) : null;
            if ( ( $row->ve_options ?? null ) !== $newOpts ) $changed['ve_options'] = $newOpts;
            if ( ( $row->ve_options_h ?? null ) !== $optionsHash ) $changed['ve_options_h'] = $optionsHash;
            if ( (string)( $row->ve_results_policy ?? 'live' ) !== (string)$resultsPolicy ) $changed['ve_results_policy'] = $resultsPolicy;
            $ttOpen = $openEnded ? 1 : 0;
            if ( (int)( $row->ve_open_ended ?? 0 ) !== $ttOpen ) $changed['ve_open_ended'] = $ttOpen;
            if ( (int)( $row->ve_max_options ?? 5 ) !== (int)$maxOptions ) $changed['ve_max_options'] = (int)$maxOptions;
            if ( $changed ) {
                $dbw->update( 'pcp_votable_elements', $changed,
                    [ 've_id' => (int)$row->ve_id ], __METHOD__ );
                $row = $dbw->selectRow( 'pcp_votable_elements', '*', [ 've_id' => (int)$row->ve_id ], __METHOD__ );
            }
            return $row;
        }

        $dbw->insert( 'pcp_votable_elements', [
            've_page_id'   => $pageId,
            've_slug'      => $slug,
            've_type'      => $type,
            've_label'     => mb_substr( (string)$label, 0, 500 ),
            've_options'   => $options !== null ? json_encode( $options ) : null,
            've_options_h' => $optionsHash,
            've_results_policy' => $resultsPolicy,
            've_open_ended'     => $openEnded ? 1 : 0,
            've_max_options'    => (int)$maxOptions,
            've_upvotes'   => 0,
            've_downvotes' => 0,
            've_created'   => $dbw->timestamp(),
        ], __METHOD__, [ 'IGNORE' ] );

        return $dbw->selectRow(
            'pcp_votable_elements', '*',
            [ 've_page_id' => $pageId, 've_slug' => $slug ],
            __METHOD__
        );
    }

    public function getById( $id ) {
        return $this->dbr()->selectRow( 'pcp_votable_elements', '*', [ 've_id' => $id ], __METHOD__ );
    }

    public function getUserVote( $elementId, $userId ) {
        $hash = $this->voterHash( $userId );
        $row = $this->dbr()->selectRow( 'pcp_votes', 'v_value',
            [ 'v_element_id' => $elementId, 'v_voter_hash' => $hash ], __METHOD__ );
        return $row ? (int)$row->v_value : 0;
    }

    public function castVote( $elementId, $userId, $value ) {
        $hash = $this->voterHash( $userId );
        $dbw = $this->dbw();
        $dbw->startAtomic( __METHOD__ );
        try {
            $existing = $dbw->selectRow( 'pcp_votes', 'v_value',
                [ 'v_element_id' => $elementId, 'v_voter_hash' => $hash ],
                __METHOD__, [ 'FOR UPDATE' ] );

            if ( $value === 0 ) {
                if ( $existing ) {
                    $dbw->delete( 'pcp_votes',
                        [ 'v_element_id' => $elementId, 'v_voter_hash' => $hash ], __METHOD__ );
                    $this->adjustCount( $elementId, (int)$existing->v_value, -1 );
                }
            } elseif ( $existing ) {
                if ( (int)$existing->v_value !== $value ) {
                    $dbw->update( 'pcp_votes',
                        [ 'v_value' => $value, 'v_timestamp' => $dbw->timestamp() ],
                        [ 'v_element_id' => $elementId, 'v_voter_hash' => $hash ], __METHOD__ );
                    $this->adjustCount( $elementId, (int)$existing->v_value, -1 );
                    $this->adjustCount( $elementId, $value, +1 );
                }
            } else {
                $dbw->insert( 'pcp_votes', [
                    'v_element_id'   => $elementId,
                    'v_voter_hash'   => $hash,
                    'v_value'        => $value,
                    'v_timestamp'    => $dbw->timestamp(),
                ], __METHOD__ );
                $this->adjustCount( $elementId, $value, +1 );
            }
            $dbw->endAtomic( __METHOD__ );
        } catch ( \Throwable $e ) {
            $dbw->cancelAtomic( __METHOD__ );
            throw $e;
        }
        return $this->getById( $elementId );
    }

    private function adjustCount( $elementId, $voteValue, $delta ) {
        $dbw = $this->dbw();
        $col = $voteValue > 0 ? 've_upvotes' : 've_downvotes';
        $dbw->query(
            "UPDATE " . $dbw->tableName( 'pcp_votable_elements' ) .
            " SET $col = $col + " . (int)$delta . " WHERE ve_id = " . (int)$elementId,
            __METHOD__
        );
    }

    /**
     * Compute the opaque voter hash for a user.
     * Vote rows store this hash instead of user_id so admins reading the DB
     * cannot map votes back to identities without the HMAC secret.
     */
    public function voterHash( $userId ): string {
        global $wgPharmacopediaVoteHashSecret;
        if ( !$wgPharmacopediaVoteHashSecret ) {
            throw new \RuntimeException( '$wgPharmacopediaVoteHashSecret must be configured in LocalSettings.php' );
        }
        return hash_hmac( 'sha256', (string)$userId, $wgPharmacopediaVoteHashSecret );
    }


    /**
     * Cast a choice vote (single or multi). $choices is an array of int indices
     * into the element's options list. Pass [] to remove the vote.
     */
    public function castChoice( $elementId, $userId, array $choices, string $optionsHash ): void {
        $hash = $this->voterHash( $userId );
        $dbw = $this->dbw();
        // Validate option indices against the element's current option count.
        $row = $dbw->selectRow( 'pcp_votable_elements',
            [ 've_options', 've_options_h', 've_type' ],
            [ 've_id' => $elementId ], __METHOD__ );
        if ( !$row ) {
            throw new \RuntimeException( 'element not found' );
        }
        // For open-ended polls, skip the drift hash check (appends don't invalidate prior).
        if ( (int)( $row->ve_open_ended ?? 0 ) === 0
             && $row->ve_options_h && (string)$row->ve_options_h !== $optionsHash ) {
            throw new \RuntimeException( 'options changed since the page loaded; reload to vote' );
        }
        $opts = $row->ve_options ? json_decode( (string)$row->ve_options, true ) : [];
        $n = is_array( $opts ) ? count( $opts ) : 0;
        $clean = [];
        foreach ( $choices as $c ) {
            $i = (int)$c;
            if ( $i >= 0 && $i < $n ) $clean[] = $i;
        }
        $clean = array_values( array_unique( $clean ) );
        sort( $clean );
        if ( (string)$row->ve_type === 'single' && count( $clean ) > 1 ) {
            $clean = [ $clean[0] ];
        }

        $dbw->startAtomic( __METHOD__ );
        try {
            if ( !$clean ) {
                $dbw->delete( 'pcp_votes',
                    [ 'v_element_id' => $elementId, 'v_voter_hash' => $hash ], __METHOD__ );
            } else {
                $csv = implode( ',', $clean );
                $existing = $dbw->selectRow( 'pcp_votes', 'v_id',
                    [ 'v_element_id' => $elementId, 'v_voter_hash' => $hash ],
                    __METHOD__, [ 'FOR UPDATE' ] );
                if ( $existing ) {
                    $dbw->update( 'pcp_votes',
                        [ 'v_value' => 0, 'v_choices' => $csv, 'v_options_h' => $optionsHash, 'v_timestamp' => $dbw->timestamp() ],
                        [ 'v_id' => (int)$existing->v_id ], __METHOD__ );
                } else {
                    $dbw->insert( 'pcp_votes', [
                        'v_element_id' => $elementId,
                        'v_voter_hash' => $hash,
                        'v_value'      => 0,
                        'v_choices'    => $csv,
                        'v_options_h'  => $optionsHash,
                        'v_timestamp'  => $dbw->timestamp(),
                    ], __METHOD__ );
                }
            }
            $dbw->endAtomic( __METHOD__ );
        } catch ( \Throwable $e ) {
            $dbw->cancelAtomic( __METHOD__ );
            throw $e;
        }
    }

    /**
     * Tally choice votes for an element. Returns [optionIndex => count, ...].
     */
    public function tallyChoices( $elementId ): array {
        $dbr = $this->dbr();
        $rows = $dbr->select( 'pcp_votes', [ 'v_choices' ],
            [ 'v_element_id' => $elementId ], __METHOD__ );
        $counts = [];
        foreach ( $rows as $r ) {
            if ( $r->v_choices === null || $r->v_choices === '' ) continue;
            foreach ( explode( ',', (string)$r->v_choices ) as $c ) {
                $i = (int)$c;
                $counts[$i] = ( $counts[$i] ?? 0 ) + 1;
            }
        }
        ksort( $counts );
        return $counts;
    }

    /**
     * Return the array of option indices this user picked for an element, or [].
     */
    public function getUserChoices( $elementId, $userId ): array {
        $hash = $this->voterHash( $userId );
        $row = $this->dbr()->selectRow( 'pcp_votes', 'v_choices',
            [ 'v_element_id' => $elementId, 'v_voter_hash' => $hash ], __METHOD__ );
        if ( !$row || $row->v_choices === null || $row->v_choices === '' ) return [];
        $out = [];
        foreach ( explode( ',', (string)$row->v_choices ) as $c ) {
            $out[] = (int)$c;
        }
        return $out;
    }


    /**
     * Append a new option to an open-ended vote element.
     * Validates: element is open-ended; user is registered; label dedup
     * (case-insensitive); under max-options cap; label <= 120 chars.
     * Stamps the new entry with the voter_hash of the submitter for owner-side
     * attribution (privacy-preserving via HMAC).
     * Returns the updated row.
     */
    public function addOption( $elementId, $userId, string $label ) {
        $label = trim( mb_substr( $label, 0, 120 ) );
        if ( $label === '' ) {
            throw new \RuntimeException( 'option text required' );
        }
        $dbw = $this->dbw();
        $dbw->startAtomic( __METHOD__ );
        try {
            $row = $dbw->selectRow( 'pcp_votable_elements', '*',
                [ 've_id' => $elementId ], __METHOD__, [ 'FOR UPDATE' ] );
            if ( !$row ) {
                throw new \RuntimeException( 'element not found' );
            }
            if ( (int)$row->ve_open_ended !== 1 ) {
                throw new \RuntimeException( 'this poll does not accept new options' );
            }
            $rawOpts = $row->ve_options ? json_decode( (string)$row->ve_options, true ) : [];
            if ( !is_array( $rawOpts ) ) $rawOpts = [];
            // Dedup case-insensitive
            $needle = mb_strtolower( $label );
            foreach ( $rawOpts as $entry ) {
                $existing = is_string( $entry ) ? $entry : ( is_array( $entry ) ? (string)( $entry['label'] ?? '' ) : '' );
                if ( mb_strtolower( $existing ) === $needle ) {
                    throw new \RuntimeException( 'option already exists' );
                }
            }
            $maxCap = (int)( $row->ve_max_options ?? 5 );
            if ( count( $rawOpts ) >= $maxCap ) {
                throw new \RuntimeException( 'option limit reached (' . $maxCap . ')' );
            }
            $rawOpts[] = [
                'label'    => $label,
                'added_by' => $this->voterHash( $userId ),
                'added_at' => $dbw->timestamp(),
            ];
            // Recompute labels-only array for hash (same shape parseOptions feeds it).
            $labels = [];
            foreach ( $rawOpts as $entry ) {
                $labels[] = is_string( $entry ) ? $entry : (string)$entry['label'];
            }
            $newHash = substr( hash( 'sha256', json_encode( $labels ) ), 0, 8 );
            $dbw->update( 'pcp_votable_elements', [
                've_options'   => json_encode( $rawOpts ),
                've_options_h' => $newHash,
            ], [ 've_id' => $elementId ], __METHOD__ );
            $dbw->endAtomic( __METHOD__ );
        } catch ( \Throwable $e ) {
            $dbw->cancelAtomic( __METHOD__ );
            throw $e;
        }
        return $this->getById( $elementId );
    }

}
