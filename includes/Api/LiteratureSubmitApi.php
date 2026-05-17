<?php
namespace MediaWiki\Extension\Pharmacopedia\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Extension\Pharmacopedia\LiteratureStore;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class LiteratureSubmitApi extends ApiBase {
    public function execute() {
        $user = $this->getUser();
        if ( !$user->isRegistered() ) {
            $this->dieWithError( [ 'apierror-mustbeloggedin', 'submit literature' ], 'notloggedin' );
        }
        if ( !$user->isAllowed( 'pharmacopedia-literature-submit' ) ) {
            $this->dieWithError( [ 'apierror-permissiondenied', 'submit literature' ], 'permissiondenied' );
        }

        $params = $this->extractRequestParams();

        // Resolve target page
        $pageId = (int)$params['page_id'];
        $title = Title::newFromID( $pageId );
        if ( !$title || !$title->exists() || !$title->inNamespace( NS_MAIN ) ) {
            $this->dieWithError( [ 'rawmessage', 'Target page not found.' ], 'notfound' );
        }

        $title_text  = trim( (string)$params['title'] );
        $authors     = trim( (string)( $params['authors'] ?? '' ) );
        $etAl        = !empty( $params['et_al'] );
        $yearRaw     = $params['year'] ?? null;
        $url         = trim( (string)( $params['url'] ?? '' ) );
        $doiRaw      = trim( (string)( $params['doi'] ?? '' ) );
        $pmidRaw     = $params['pmid'] ?? null;

        if ( $title_text === '' ) {
            $this->dieWithError( [ 'rawmessage', 'Title is required.' ], 'missingparam' );
        }
        if ( mb_strlen( $title_text ) > 500 ) {
            $this->dieWithError( [ 'rawmessage', 'Title too long (max 500 chars).' ], 'badvalue' );
        }
        if ( mb_strlen( $authors ) > 500 ) {
            $this->dieWithError( [ 'rawmessage', 'Authors field too long.' ], 'badvalue' );
        }

        $year = null;
        if ( $yearRaw !== null && $yearRaw !== '' ) {
            $year = (int)$yearRaw;
            $thisYear = (int)gmdate( 'Y' );
            if ( $year < 1800 || $year > $thisYear + 1 ) {
                $this->dieWithError( [ 'rawmessage', 'Year must be between 1800 and ' . ( $thisYear + 1 ) . '.' ], 'badvalue' );
            }
        }

        if ( $url !== '' ) {
            if ( mb_strlen( $url ) > 2048 || !preg_match( '#^https?://#i', $url ) ) {
                $this->dieWithError( [ 'rawmessage', 'URL must be http(s) and under 2048 chars.' ], 'badvalue' );
            }
        }

        $doi = null;
        if ( $doiRaw !== '' ) {
            $doi = LiteratureStore::normalizeDoi( $doiRaw );
            if ( $doi === null ) {
                $this->dieWithError( [ 'rawmessage', 'DOI looks invalid (expected form: 10.xxxx/...).' ], 'badvalue' );
            }
        }
        $pmid = null;
        if ( $pmidRaw !== null && $pmidRaw !== '' ) {
            $pmid = (int)$pmidRaw;
            if ( $pmid <= 0 ) {
                $this->dieWithError( [ 'rawmessage', 'PubMed ID must be a positive integer.' ], 'badvalue' );
            }
        }

        // File upload (optional, only allowed for users with the upload right)
        $request = $this->getRequest();
        $upload = $request->getUpload( 'file' );
        $fileInfo = null;
        if ( $upload && $upload->exists() && $upload->getSize() > 0 ) {
            if ( !$user->isAllowed( 'pharmacopedia-literature-upload' ) ) {
                $this->dieWithError(
                    [ 'rawmessage', 'You do not have permission to upload files.' ],
                    'permissiondenied'
                );
            }
            $tmp = $upload->getTempName();
            $orig = $upload->getName();
            $size = (int)$upload->getSize();
            try {
                $store = new LiteratureStore();
                $path = $store->storeUploadedPdf( $tmp, $orig );
            } catch ( \Throwable $e ) {
                $this->dieWithError( [ 'rawmessage', $e->getMessage() ], 'fileerror' );
            }
            $fileInfo = [
                'path'     => $path,
                'origname' => mb_substr( $orig, 0, 255 ),
                'mime'     => 'application/pdf',
                'size'     => $size,
            ];
        }

        if ( $url === '' && !$fileInfo ) {
            $this->dieWithError(
                [ 'rawmessage', 'Provide either a URL or a PDF upload (or both).' ],
                'missingparam'
            );
        }

        // Rate limit
        if ( !$user->isAllowed( 'pharmacopedia-literature-review' ) ) {
            $store = isset( $store ) ? $store : new LiteratureStore();
            $recent = $store->countRecentForUser( $user->getId() );
            $isProvider = $user->isAllowed( 'pharmacopedia-literature-upload' );
            $limit = $isProvider ? 10 : 5;
            if ( $recent >= $limit ) {
                $this->dieWithError(
                    [ 'rawmessage', 'Daily submission limit reached (' . $limit . '/day). Try again tomorrow.' ],
                    'ratelimited'
                );
            }
        }

        $store = isset( $store ) ? $store : new LiteratureStore();

        // Dedup on (page, doi) or (page, pmid)
        $dup = $store->findDuplicate( $pageId, $doi, $pmid );
        if ( $dup ) {
            $this->dieWithError(
                [ 'rawmessage', 'A submission with that DOI or PubMed ID already exists for this page.' ],
                'duplicate'
            );
        }

        // Literature submissions are always anonymous (attributed via voter_hash to sysops only).
        // The show_name UI/API surface was removed to prevent identity-leak via submission history.
        $displayName = null;
        $newId = $store->createPending(
            $pageId,
            (int)$user->getId(),
            [
                'authors' => $authors !== '' ? $authors : null,
                'et_al'   => $etAl,
                'title'   => $title_text,
                'year'    => $year,
                'url'     => $url !== '' ? $url : null,
                'doi'     => $doi,
                'pmid'    => $pmid,
            ],
            $fileInfo,
            $displayName
        );

        // Optional moderator email (no-op if config empty).
        self::maybeNotifyModerator( $pageId, $newId, $user->getName() );

        $this->getResult()->addValue( null, 'pharmacopedialiteraturesubmit', [
            'ok'        => 1,
            'id'        => $newId,
            'status'    => 'pending',
        ] );
    }

    private static function maybeNotifyModerator( int $pageId, int $litId, string $submitter ): void {
        global $wgPharmacopediaModeratorEmail, $wgSitename;
        if ( empty( $wgPharmacopediaModeratorEmail ) ) { return; }
        $title = \MediaWiki\Title\Title::newFromID( $pageId );
        $pageName = $title ? $title->getPrefixedText() : "(page #$pageId)";
        $queueUrl = \MediaWiki\Title\Title::makeTitle( NS_SPECIAL, 'LiteratureQueue' )->getCanonicalURL();
        $subject = "[$wgSitename] New literature submission #$litId on $pageName";
        $body = "Submitter: $submitter\nPage: $pageName\n\nReview queue: $queueUrl\n";
        $services = MediaWikiServices::getInstance();
        $emailer = $services->getEmailer();
        try {
            $emailer->send(
                [ new \MediaWiki\Mail\UserEmailContact( null, $wgPharmacopediaModeratorEmail ) ],
                new \MediaWiki\Mail\UserEmailContact( null, $wgPharmacopediaModeratorEmail ),
                $subject,
                $body
            );
        } catch ( \Throwable $e ) {
            wfDebugLog( 'pharmacopedia', 'literature moderator email failed: ' . $e->getMessage() );
        }
    }

    public function getAllowedParams() {
        return [
            'page_id' => [ ApiBase::PARAM_TYPE => 'integer', ApiBase::PARAM_REQUIRED => true ],
            'title'   => [ ApiBase::PARAM_TYPE => 'string',  ApiBase::PARAM_REQUIRED => true ],
            'authors' => [ ApiBase::PARAM_TYPE => 'string' ],
            'et_al'   => [ ApiBase::PARAM_TYPE => 'boolean' ],
            'year'    => [ ApiBase::PARAM_TYPE => 'integer' ],
            'url'     => [ ApiBase::PARAM_TYPE => 'string' ],
            'doi'     => [ ApiBase::PARAM_TYPE => 'string' ],
            'pmid'    => [ ApiBase::PARAM_TYPE => 'integer' ],
        ];
    }
    public function needsToken()   { return 'csrf'; }
    public function isWriteMode()  { return true; }
    public function mustBePosted() { return true; }
}
