<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * Special:Overlinked. A review queue of external-wiki pages that the wiki
 * links to very often.
 *
 * Under the linking rule (feedback_internal_linking.md, 2026-05-22), an
 * external-wiki link is the fallback for an out-of-scope term. Once the same
 * external-wiki page is linked from more than a threshold number of local
 * pages, the term has proven in-scope and should become a local article.
 * This page surfaces those over-linked targets as promotion candidates.
 *
 * Scope: links recorded in the externallinks table whose host matches one of
 * the configured wiki domains (PharmacopediaOverlinkedWikiDomains).
 * Interwiki-prefixed links (the iwlinks table) are not counted: this wiki
 * links to external wikis with raw URLs, and iwlinks is effectively empty.
 *
 * Read-only. The "promote" action is a normal local-page edit link.
 *
 * @license GPL-3.0-or-later
 */
class SpecialOverlinked extends SpecialPage {

    public function __construct() {
        parent::__construct( 'Overlinked' );
    }

    /** @inheritDoc */
    public function execute( $par ) {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->setPageTitle( 'Overlinked external links' );

        $config    = $this->getConfig();
        $threshold = (int)$config->get( 'PharmacopediaOverlinkedThreshold' );

        $domains = $config->get( 'PharmacopediaOverlinkedWikiDomains' );
        if ( !is_array( $domains ) ) {
            $domains = [];
        }
        $domains = array_values( array_filter( array_map( 'trim', $domains ) ) );

        $out->addHTML( Html::element( 'p', [],
            "These external-wiki pages are each linked from more than $threshold "
            . "local pages. A term linked this widely has proven in-scope, so "
            . "consider giving it a local article and pointing the links inward."
        ) );

        if ( !$domains ) {
            $out->addHTML( Html::warningBox(
                'No external-wiki domains are configured. Set '
                . '$wgPharmacopediaOverlinkedWikiDomains in LocalSettings.php to '
                . 'choose which wikis to track.'
            ) );
            return;
        }

        $out->addHTML( Html::element( 'p', [ 'class' => 'pcp-overlinked-scope' ],
            'Watching: ' . implode( ', ', $domains ) . '.'
        ) );

        $rows = $this->findOverlinked( $domains, $threshold );

        if ( !$rows ) {
            $out->addHTML( Html::element( 'p', [ 'class' => 'pcp-overlinked-empty' ],
                "No external-wiki page is linked from more than $threshold local "
                . "pages yet, so there is nothing to promote."
            ) );
            return;
        }

        $out->addHTML( $this->renderTable( $rows ) );
    }

    /**
     * External-wiki targets linked from more than $threshold distinct local
     * pages, most-linked first.
     *
     * @param string[] $domains watched wiki hostnames
     * @param int $threshold
     * @return array list of [ 'url' => string, 'pages' => int ]
     */
    private function findOverlinked( array $domains, int $threshold ): array {
        $dbr = MediaWikiServices::getInstance()
            ->getConnectionProvider()->getReplicaDatabase();

        // externallinks stores the host reversed in el_to_domain_index, e.g.
        // https://en.wikipedia.org/... indexes as 'https://org.wikipedia.en.'.
        // Match each watched host (and its subdomains) on that reversed form.
        $likes = [];
        foreach ( $domains as $domain ) {
            $reversed = implode( '.', array_reverse( explode( '.', $domain ) ) );
            $likes[] = 'el_to_domain_index' . $dbr->buildLike(
                $dbr->anyString(), '//' . $reversed . '.', $dbr->anyString()
            );
        }
        if ( !$likes ) {
            return [];
        }

        $res = $dbr->select(
            'externallinks',
            [
                'el_to_domain_index',
                'el_to_path',
                'pages' => 'COUNT(DISTINCT el_from)',
            ],
            $dbr->makeList( $likes, LIST_OR ),
            __METHOD__,
            [
                'GROUP BY' => [ 'el_to_domain_index', 'el_to_path' ],
                'HAVING'   => 'pages > ' . $threshold,
                'ORDER BY' => 'pages DESC',
            ]
        );

        $out = [];
        foreach ( $res as $row ) {
            $url = self::reconstructUrl(
                (string)$row->el_to_domain_index, (string)$row->el_to_path );
            if ( $url !== null ) {
                $out[] = [ 'url' => $url, 'pages' => (int)$row->pages ];
            }
        }
        return $out;
    }

    /**
     * Render the candidate table.
     *
     * @param array $rows findOverlinked() output
     * @return string HTML
     */
    private function renderTable( array $rows ): string {
        $linkRenderer = $this->getLinkRenderer();
        $linkSearch   = SpecialPage::getTitleFor( 'LinkSearch' );

        $html = Html::openElement( 'table', [ 'class' => 'wikitable pcp-overlinked' ] );
        $html .= Html::rawElement( 'tr', [],
            Html::element( 'th', [], 'External-wiki page' )
            . Html::element( 'th', [], 'Linked from' )
            . Html::element( 'th', [], 'Promote' )
        );

        foreach ( $rows as $row ) {
            $url   = $row['url'];
            $pages = (int)$row['pages'];

            $target = Html::element( 'a',
                [ 'href' => $url, 'rel' => 'nofollow', 'class' => 'external' ], $url );

            $countText  = $pages . ' ' . ( $pages === 1 ? 'page' : 'pages' );
            $linkedFrom = $linkRenderer->makeKnownLink(
                $linkSearch, $countText, [], [ 'target' => $url ] );

            $html .= Html::rawElement( 'tr', [],
                Html::rawElement( 'td', [], $target )
                . Html::rawElement( 'td', [], $linkedFrom )
                . Html::rawElement( 'td', [], $this->promoteCell( $url ) )
            );
        }
        $html .= Html::closeElement( 'table' );
        return $html;
    }

    /**
     * The "Promote" cell: a link to create the suggested local article, or a
     * note that a local page already exists for it.
     */
    private function promoteCell( string $url ): string {
        $suggested = self::suggestTitle( $url );
        if ( $suggested === '' ) {
            return '';
        }
        $title = Title::newFromText( $suggested );
        if ( !$title ) {
            return '';
        }
        $linkRenderer = $this->getLinkRenderer();
        if ( $title->exists() ) {
            return $linkRenderer->makeKnownLink(
                $title, 'local page exists: ' . $title->getPrefixedText() );
        }
        return $linkRenderer->makeBrokenLink(
            $title, 'create ' . $title->getPrefixedText() );
    }

    /**
     * Rebuild a real URL from the externallinks reversed-host index.
     * 'https://org.wikipedia.en.' + '/wiki/Serotonin'
     *   becomes 'https://en.wikipedia.org/wiki/Serotonin'.
     */
    private static function reconstructUrl( string $domainIndex, string $path ): ?string {
        $sep = strpos( $domainIndex, '://' );
        if ( $sep === false ) {
            return null;
        }
        $scheme  = substr( $domainIndex, 0, $sep );
        $revHost = rtrim( substr( $domainIndex, $sep + 3 ), '.' );
        if ( $scheme === '' || $revHost === '' ) {
            return null;
        }
        $host = implode( '.', array_reverse( explode( '.', $revHost ) ) );
        return $scheme . '://' . $host . $path;
    }

    /**
     * Suggest a local article title from an external-wiki URL. For the common
     * '/wiki/<Title>' form that is the title; otherwise the last path segment.
     * Underscores become spaces and percent-escapes are decoded.
     */
    private static function suggestTitle( string $url ): string {
        $path = (string)parse_url( $url, PHP_URL_PATH );
        if ( $path === '' ) {
            return '';
        }
        if ( preg_match( '#/wiki/(.+)$#', $path, $m ) ) {
            $raw = $m[1];
        } else {
            $segments = array_values( array_filter( explode( '/', $path ) ) );
            $raw = $segments ? (string)end( $segments ) : '';
        }
        $raw = str_replace( '_', ' ', rawurldecode( $raw ) );
        return trim( $raw );
    }

    /** @inheritDoc */
    protected function getGroupName() {
        return 'pharmacopedia';
    }
}
