<?php
/**
 * Audit PMIDs embedded in pcp_interactions.pi_mechanism prose against
 * NCBI eutils to detect invalid, garbled, or hallucinated citations.
 *
 * Scans every row's mechanism field, regex-extracts PMID-shaped tokens
 * (1-8 digit numbers preceded by "PMID" or in "[PMID N, M]" lists),
 * then verifies each unique PMID via:
 *
 *   https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi
 *     ?db=pubmed&id=<id>&retmode=json
 *
 * Rate-limited: 3 requests/sec without an API key (NCBI policy). With
 * --api-key=KEY the cap is 10/sec. Caches results in /tmp/pmid_cache.json
 * across runs to avoid re-querying.
 *
 * Read-only. Reports invalid PMIDs grouped by pi_id; does not mutate the
 * database. Per the herbal-schema trust gate (PMIDs in pcp_* tables are
 * home-claude-verified), this audit is the verification step for the
 * pre-PGx-phase rows that landed without going through home-claude.
 *
 * Usage:
 *   sudo -u www-data php maintenance/run.php extensions/Pharmacopedia/maintenance/AuditPmidsAgainstEutils.php \
 *     [--api-key=KEY] [--limit=N] [--out=/tmp/pmid_audit_report.txt]
 */
$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/mediawiki';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;

class AuditPmidsAgainstEutils extends Maintenance {

    private const CACHE_FILE = '/tmp/pmid_cache.json';
    private const EUTILS_BASE = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi';

    public function __construct() {
        parent::__construct();
        $this->addOption( 'api-key', 'NCBI API key (raises rate limit 3/s -> 10/s)', false, true );
        $this->addOption( 'limit', 'Cap on unique PMIDs to verify (testing)', false, true );
        $this->addOption( 'out', 'Path to write the report (default stdout)', false, true );
        $this->requireExtension( 'Pharmacopedia' );
    }

    public function execute() {
        $apiKey = $this->getOption( 'api-key' );
        $limit  = $this->hasOption( 'limit' ) ? (int)$this->getOption( 'limit' ) : 0;
        $delay  = $apiKey ? 110 : 350; // milliseconds between calls

        $services = MediaWikiServices::getInstance();
        $dbr = $services->getConnectionProvider()->getReplicaDatabase();
        $http = $services->getHttpRequestFactory();

        // 1) Extract every PMID-shaped token from pi_mechanism.
        $this->output( "Scanning pi_mechanism prose for PMID references ...\n" );
        $rows = $dbr->select( 'pcp_interactions',
            [ 'pi_id', 'pi_mechanism' ],
            [ 'pi_mechanism IS NOT NULL' ],
            __METHOD__ );
        $pmidToRows = [];      // pmid -> [pi_id, ...]
        $byRow = [];           // pi_id -> [pmid, ...]
        foreach ( $rows as $r ) {
            $mech = (string)$r->pi_mechanism;
            $found = self::extractPmids( $mech );
            if ( !$found ) continue;
            $byRow[ (int)$r->pi_id ] = $found;
            foreach ( $found as $p ) {
                if ( !isset( $pmidToRows[$p] ) ) $pmidToRows[$p] = [];
                $pmidToRows[$p][] = (int)$r->pi_id;
            }
        }
        $uniquePmids = array_keys( $pmidToRows );
        $this->output( "  rows with PMID refs: " . count( $byRow ) . "\n" );
        $this->output( "  unique PMIDs       : " . count( $uniquePmids ) . "\n\n" );

        // 2) Load cache.
        $cache = [];
        if ( file_exists( self::CACHE_FILE ) ) {
            $cacheRaw = @file_get_contents( self::CACHE_FILE );
            $decoded = $cacheRaw !== false ? json_decode( $cacheRaw, true ) : null;
            if ( is_array( $decoded ) ) $cache = $decoded;
            $this->output( "  cache hits available: " . count( $cache ) . "\n" );
        }

        // 3) Verify each PMID via eutils.
        $toQuery = array_values( array_diff( $uniquePmids, array_keys( $cache ) ) );
        if ( $limit > 0 ) $toQuery = array_slice( $toQuery, 0, $limit );
        $this->output( "  PMIDs to query     : " . count( $toQuery ) . "\n\n" );

        $verified = 0;
        $invalid = 0;
        $errors = 0;
        $i = 0;
        foreach ( $toQuery as $pmid ) {
            $i++;
            $url = self::EUTILS_BASE
                . '?db=pubmed&retmode=json&id=' . urlencode( $pmid );
            if ( $apiKey ) $url .= '&api_key=' . urlencode( $apiKey );
            $body = $http->get( $url, [
                'timeout' => 15,
                'userAgent' => 'Pharmacopedia-PMID-Audit/1.0 (mailto:mdelliott@pharmacopedia.wiki)',
            ], __METHOD__ );
            if ( $body === null ) {
                $cache[$pmid] = [ 'status' => 'error', 'reason' => 'fetch_failed' ];
                $errors++;
            } else {
                $j = json_decode( (string)$body, true );
                $ok = self::looksValid( $j, $pmid );
                if ( $ok === true ) {
                    $cache[$pmid] = [ 'status' => 'valid',
                        'title' => $ok === true ? mb_substr(
                            (string)( $j['result'][$pmid]['title'] ?? '' ), 0, 120 )
                            : null ];
                    $verified++;
                } elseif ( $ok === false ) {
                    $cache[$pmid] = [ 'status' => 'invalid', 'reason' => 'no_record' ];
                    $invalid++;
                } else {
                    $cache[$pmid] = [ 'status' => 'error', 'reason' => $ok ];
                    $errors++;
                }
            }
            if ( $i % 25 === 0 ) {
                $this->output( "  ...$i / " . count( $toQuery ) . " queried\n" );
                @file_put_contents( self::CACHE_FILE, json_encode( $cache ) );
                @chmod( self::CACHE_FILE, 0644 );
            }
            usleep( $delay * 1000 );
        }
        @file_put_contents( self::CACHE_FILE, json_encode( $cache ) );
        @chmod( self::CACHE_FILE, 0644 );

        // 4) Report.
        $report = "PMID audit report\n";
        $report .= "=================\n";
        $report .= sprintf( "  rows-with-PMIDs    : %d\n", count( $byRow ) );
        $report .= sprintf( "  unique-PMIDs       : %d\n", count( $uniquePmids ) );
        $report .= sprintf( "  newly-queried      : %d (verified=%d invalid=%d errors=%d)\n",
            count( $toQuery ), $verified, $invalid, $errors );

        $invalidList = [];
        $errorList   = [];
        foreach ( $pmidToRows as $pmid => $piIds ) {
            $c = $cache[$pmid] ?? null;
            if ( !$c ) continue;
            if ( $c['status'] === 'invalid' ) $invalidList[$pmid] = $piIds;
            elseif ( $c['status'] === 'error' ) $errorList[$pmid] = $piIds;
        }

        $report .= "\n";
        if ( $invalidList ) {
            $report .= "INVALID PMIDs (eutils returned no record):\n";
            foreach ( $invalidList as $pmid => $piIds ) {
                $report .= sprintf( "  PMID %s -> pi_ids %s\n",
                    $pmid, implode( ', ', array_slice( $piIds, 0, 10 ) )
                    . ( count( $piIds ) > 10 ? ' ... +' . ( count( $piIds ) - 10 ) : '' ) );
            }
            $report .= "\n";
        } else {
            $report .= "INVALID PMIDs: none.\n\n";
        }
        if ( $errorList ) {
            $report .= "ERROR PMIDs (could not verify, retry next run):\n";
            foreach ( $errorList as $pmid => $piIds ) {
                $report .= sprintf( "  PMID %s -> pi_ids %s\n",
                    $pmid, implode( ', ', array_slice( $piIds, 0, 5 ) ) );
            }
            $report .= "\n";
        }

        $report .= "Top 10 most-cited valid PMIDs:\n";
        $byCount = [];
        foreach ( $pmidToRows as $pmid => $piIds ) {
            if ( ( $cache[$pmid]['status'] ?? '' ) === 'valid' ) {
                $byCount[$pmid] = count( $piIds );
            }
        }
        arsort( $byCount );
        $k = 0;
        foreach ( $byCount as $pmid => $n ) {
            $title = $cache[$pmid]['title'] ?? '';
            $report .= sprintf( "  %5dx  PMID %s  %s\n", $n, $pmid, $title );
            if ( ++$k >= 10 ) break;
        }

        $out = $this->getOption( 'out' );
        if ( $out ) {
            @file_put_contents( $out, $report );
            @chmod( $out, 0644 );
            $this->output( "\nReport written to $out\n" );
        }
        $this->output( "\n" . $report );
    }

    /** Extract bare PMID numbers from prose. Conservative: requires a
     *  clear terminator (], ), ., or non-list character) so trailing
     *  truncated digits at end-of-string don't generate false PMIDs.
     *  Picks up "PMID NNN", "PMID: NNN", "[PMID NN, MM]", "(PMID NNN)".
     */
    private static function extractPmids( string $mech ): array {
        $out = [];
        // Match a PMID anchor + a list of digits-commas-spaces, requiring
        // a terminator (], ), ., letter, whitespace+letter, or DOUBLE
        // whitespace). Truncated lists (digits running into end-of-string
        // without a terminator) are deliberately not matched.
        $re = '/PMID[:\s]+([\d][\d,\s]*?\d)\s*(?:\]|\)|\.|;|[A-Za-z])/i';
        if ( preg_match_all( $re, $mech, $matches ) ) {
            foreach ( $matches[1] as $block ) {
                if ( preg_match_all( '/\d+/', $block, $nums ) ) {
                    foreach ( $nums[0] as $n ) {
                        $n = trim( $n );
                        // PMID range historically ~5 to 8 digits today.
                        if ( strlen( $n ) >= 5 && strlen( $n ) <= 9 ) {
                            $out[$n] = true;
                        }
                    }
                }
            }
        }
        // Also catch single-PMID forms followed by ANY non-digit including
        // the very end of string: "PMID 33387367" at end of mechanism.
        $reSingle = '/PMID[:\s]+(\d{5,9})(?![\d])/i';
        if ( preg_match_all( $reSingle, $mech, $matches ) ) {
            foreach ( $matches[1] as $n ) {
                $out[trim( $n )] = true;
            }
        }
        return array_keys( $out );
    }

    /** Returns true if eutils JSON contains a valid record for $pmid,
     *  false if confirmed invalid, or a string reason on parse error. */
    private static function looksValid( $j, string $pmid ) {
        if ( !is_array( $j ) ) return 'bad_json';
        if ( isset( $j['error'] ) ) return false;
        $r = $j['result'] ?? null;
        if ( !is_array( $r ) ) return 'no_result';
        if ( isset( $r[$pmid] ) && is_array( $r[$pmid] ) ) {
            $rec = $r[$pmid];
            if ( !empty( $rec['error'] ) ) return false;
            // A valid record has a non-empty title or pubdate.
            if ( !empty( $rec['title'] ) || !empty( $rec['pubdate'] )
                 || !empty( $rec['authors'] ) ) return true;
            return false;
        }
        return false;
    }
}
$maintClass = AuditPmidsAgainstEutils::class;
require_once RUN_MAINTENANCE_IF_MAIN;
