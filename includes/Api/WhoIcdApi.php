<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

/**
 * Minimal client for the WHO ICD-API (icd.who.int/icdapi).
 *
 * Credentials live at /etc/pharmacopedia-icd-api.env (mode 600, owned by root)
 * with two lines:
 *   WHO_ICD_CLIENT_ID=...
 *   WHO_ICD_CLIENT_SECRET=...
 *
 * Token (1-hour TTL) is cached at /var/cache/pharmacopedia/who-icd-token.json
 * so we don't re-auth on every call.
 *
 * Endpoints documented at https://icd.who.int/icdapi/docs2/APIDoc-Version2/
 */
class WhoIcdApi {

    private const CRED_FILE  = '/etc/pharmacopedia-icd-api.env';
    private const TOKEN_FILE = '/var/cache/pharmacopedia/who-icd-token.json';
    private const TOKEN_URL  = 'https://icdaccessmanagement.who.int/connect/token';
    private const BASE_URL   = 'https://id.who.int';

    private ?string $clientId     = null;
    private ?string $clientSecret = null;
    private ?string $token        = null;

    public function __construct() {
        $this->loadCredentials();
    }

    private function loadCredentials(): void {
        if ( !is_readable( self::CRED_FILE ) ) {
            throw new \RuntimeException(
                'WHO ICD-API credentials file not readable: ' . self::CRED_FILE .
                ' (run as a user that can read it, usually root or www-data)'
            );
        }
        foreach ( file( self::CRED_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
            $line = trim( $line );
            if ( $line === '' || $line[ 0 ] === '#' ) continue;
            $eq = strpos( $line, '=' );
            if ( $eq === false ) continue;
            $k = trim( substr( $line, 0, $eq ) );
            $v = trim( substr( $line, $eq + 1 ) );
            $v = trim( $v, '"\'' );
            if ( $k === 'WHO_ICD_CLIENT_ID' )     $this->clientId     = $v;
            if ( $k === 'WHO_ICD_CLIENT_SECRET' ) $this->clientSecret = $v;
        }
        if ( !$this->clientId || !$this->clientSecret ) {
            throw new \RuntimeException( 'WHO ICD-API client_id or client_secret missing from ' . self::CRED_FILE );
        }
    }

    /**
     * Return a valid OAuth2 bearer token, fetching new if cache is stale.
     */
    public function getToken(): string {
        if ( $this->token !== null ) return $this->token;
        if ( is_readable( self::TOKEN_FILE ) ) {
            $cached = json_decode( (string)file_get_contents( self::TOKEN_FILE ), true );
            if ( is_array( $cached ) && isset( $cached['access_token'], $cached['expires_at'] )
                 && (int)$cached['expires_at'] > time() + 60 ) {
                $this->token = (string)$cached['access_token'];
                return $this->token;
            }
        }
        $this->token = $this->fetchNewToken();
        return $this->token;
    }

    private function fetchNewToken(): string {
        $ch = curl_init( self::TOKEN_URL );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => http_build_query( [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => 'icdapi_access',
                'grant_type'    => 'client_credentials',
            ] ),
            CURLOPT_HTTPHEADER     => [ 'Content-Type: application/x-www-form-urlencoded' ],
            CURLOPT_TIMEOUT        => 30,
        ] );
        $resp = curl_exec( $ch );
        $http = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $err  = curl_error( $ch );
        curl_close( $ch );
        if ( $http !== 200 ) {
            throw new \RuntimeException( "WHO token endpoint returned HTTP $http: $resp (curl_err=$err)" );
        }
        $data = json_decode( (string)$resp, true );
        if ( !is_array( $data ) || empty( $data['access_token'] ) ) {
            throw new \RuntimeException( "WHO token response missing access_token: $resp" );
        }
        $expiresIn = (int)( $data['expires_in'] ?? 3600 );
        @mkdir( dirname( self::TOKEN_FILE ), 0700, true );
        file_put_contents( self::TOKEN_FILE, json_encode( [
            'access_token' => $data['access_token'],
            'expires_at'   => time() + $expiresIn,
        ] ), LOCK_EX );
        @chmod( self::TOKEN_FILE, 0600 );
        return (string)$data['access_token'];
    }

    /**
     * GET an entity by URL or path. Returns decoded JSON.
     * @param string $urlOrPath either a full id.who.int URL or a /icd/... path
     * @param array $extraHeaders e.g. [ 'Accept-Language: en' ]
     */
    public function get( string $urlOrPath, array $extraHeaders = [] ): array {
        $url = str_starts_with( $urlOrPath, 'http' )
            ? $urlOrPath
            : ( self::BASE_URL . $urlOrPath );
        // WHO returns http:// URLs in their JSON responses, but the server 301-redirects
        // to https://. Coerce upfront so we don't waste a roundtrip per call.
        if ( str_starts_with( $url, 'http://' ) ) {
            $url = 'https://' . substr( $url, 7 );
        }
        $token = $this->getToken();
        $headers = array_merge( [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Accept-Language: en',
            'API-Version: v2',
        ], $extraHeaders );
        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ] );
        $resp = curl_exec( $ch );
        $http = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $err  = curl_error( $ch );
        curl_close( $ch );
        if ( $http !== 200 ) {
            throw new \RuntimeException( "WHO API GET $url returned HTTP $http: " . substr( (string)$resp, 0, 500 ) . " (curl_err=$err)" );
        }
        $data = json_decode( (string)$resp, true );
        if ( !is_array( $data ) ) {
            throw new \RuntimeException( "WHO API GET $url returned non-JSON: " . substr( (string)$resp, 0, 500 ) );
        }
        return $data;
    }

    /**
     * Recursively walk the descendants of an ICD-10 release entity.
     * Yields associative arrays: [ 'code' => '...', 'title' => '...', 'is_leaf' => bool ].
     */
    /** Map ICD-10 chapter starting letter to Roman numeral chapter id used by WHO. */
    public const ICD10_CHAPTER_ROMAN = [
        'A' => 'I',   'B' => 'I',   'C' => 'II',  'D' => 'III', 'E' => 'IV',
        'F' => 'V',   'G' => 'VI',  'H' => 'VII', 'I' => 'IX',  'J' => 'X',
        'K' => 'XI',  'L' => 'XII', 'M' => 'XIII','N' => 'XIV', 'O' => 'XV',
        'P' => 'XVI', 'Q' => 'XVII','R' => 'XVIII','S' => 'XIX','T' => 'XIX',
        'V' => 'XX',  'W' => 'XX',  'X' => 'XX',  'Y' => 'XX',  'Z' => 'XXI',
        'U' => 'XXII',
    ];

    /**
     * @param string $chapterRoot Either a Roman numeral chapter ('XXI') or a
     *                            single letter ('Z') which maps to the Roman numeral.
     */
    public function walkIcd10Chapter( string $release, string $chapterRoot ): \Generator {
        if ( strlen( $chapterRoot ) === 1 && isset( self::ICD10_CHAPTER_ROMAN[ $chapterRoot ] ) ) {
            $chapterRoot = self::ICD10_CHAPTER_ROMAN[ $chapterRoot ];
        }
        $stack = [ "/icd/release/10/$release/$chapterRoot" ];
        $seen  = [];
        while ( $stack ) {
            $path = array_shift( $stack );
            if ( isset( $seen[ $path ] ) ) continue;
            $seen[ $path ] = true;
            try {
                $entity = $this->get( $path );
            } catch ( \Throwable $e ) {
                yield [ 'error' => true, 'path' => $path, 'message' => $e->getMessage() ];
                continue;
            }
            $title = '';
            if ( isset( $entity['title']['@value'] ) ) $title = (string)$entity['title']['@value'];
            $code = isset( $entity['code'] ) ? (string)$entity['code'] : '';
            $isLeaf = empty( $entity['child'] );
            yield [
                'code'    => $code,
                'title'   => $title,
                'is_leaf' => $isLeaf,
                'path'    => $path,
            ];
            if ( !empty( $entity['child'] ) && is_array( $entity['child'] ) ) {
                foreach ( $entity['child'] as $childUrl ) {
                    $stack[] = (string)$childUrl;
                }
            }
        }
    }
}
