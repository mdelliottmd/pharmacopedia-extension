<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\Context\RequestContext;

class VoteTag {

    /** Hard cap on choice-vote options to keep UI sensible. */
    private const MAX_OPTIONS = 5;
    private const MAX_OPEN_OPTIONS = 10;
    private const MIN_OPTIONS = 2;

    public static function render( $input, array $args, $parser, $frame ) {
        $slug = isset( $args['slug'] ) ? trim( (string)$args['slug'] ) : '';
        if ( $slug === '' ) {
            return '<span class="pcp-error">&lt;vote&gt;: slug required</span>';
        }
        $type = isset( $args['type'] ) ? strtolower( trim( (string)$args['type'] ) ) : 'binary';
        $resultsPolicy = isset( $args['results'] ) ? strtolower( trim( (string)$args['results'] ) ) : 'live';
        if ( !in_array( $resultsPolicy, [ 'live', 'after-vote', 'hidden' ], true ) ) {
            return '<span class="pcp-error">&lt;vote&gt;: results must be live, after-vote, or hidden</span>';
        }
        $openEnded = isset( $args['open-ended'] ) && in_array(
            strtolower( trim( (string)$args['open-ended'] ) ), [ '1', 'true', 'yes' ], true );
        $maxOptionsCap = $openEnded ? self::MAX_OPEN_OPTIONS : self::MAX_OPTIONS;
        if ( !in_array( $type, [ 'binary', 'single', 'multi' ], true ) ) {
            return '<span class="pcp-error">&lt;vote&gt;: type must be binary, single, or multi</span>';
        }
        $title = $parser->getTitle();
        if ( !$title ) {
            return '<span class="pcp-error">&lt;vote&gt;: no page context</span>';
        }
        $pageId = $title->getArticleID();
        if ( $pageId <= 0 ) {
            return $parser->recursiveTagParse( (string)$input, $frame );
        }

        // dedent: strip leading whitespace per line so user indentation
        // inside <vote>...</vote> does not trigger MW <pre> wrapping
        $cleaned  = preg_replace( "/^[ \t]+/m", "", (string)$input );
        $cleaned  = trim( $cleaned );
        $rendered = $parser->recursiveTagParse( $cleaned, $frame );
        $label    = trim( strip_tags( $rendered ) );

        // Parse options attr for non-binary types. Separator: ; (recommended), ||, or |.
        $options = null;
        $optionsHash = null;
        if ( $type !== 'binary' ) {
            $rawOpts = isset( $args['options'] ) ? (string)$args['options'] : '';
            $options = self::parseOptions( $rawOpts, $maxOptionsCap );
            if ( !$options ) {
                return '<span class="pcp-error">&lt;vote type="' . htmlspecialchars( $type ) . '"&gt;: options required (semicolon-separated, ' . self::MIN_OPTIONS . '-' . self::MAX_OPTIONS . ' items). Use ; as separator inside templates.</span>';
            }
            $optionsHash = substr( hash( 'sha256', json_encode( $options ) ), 0, 8 );
        }

        $store   = new ElementStore();
        $element = $store->getOrCreate( $pageId, $slug, $type, $label, $options, $optionsHash, $resultsPolicy, $openEnded, $maxOptionsCap );

        $user = RequestContext::getMain()->getUser();
        $isLoggedIn = $user->isRegistered();

        // Disable parser cache for logged-in users so each viewer's own state renders against their session.
        $parser->getOutput()->updateCacheExpiry( 0 );
        $parser->getOutput()->addModules( [ 'ext.pharmacopedia' ] );
        $parser->getOutput()->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        if ( $type === 'binary' ) {
            $userVote = $isLoggedIn ? $store->getUserVote( (int)$element->ve_id, $user->getId() ) : 0;
            return self::renderBinary( $element, $rendered, $userVote );
        }
        $userChoices = $isLoggedIn ? $store->getUserChoices( (int)$element->ve_id, $user->getId() ) : [];
        $tally       = $store->tallyChoices( (int)$element->ve_id );
        return self::renderChoice( $element, $rendered, $userChoices, $tally );
    }

    /**
     * Parse the options attribute. MediaWiki's wikitext parser eats `|` as
     * template/table syntax inside templates/tables, so we accept multiple
     * separators (in this order of preference):
     *   ";"   semicolon (RECOMMENDED — works everywhere)
     *   "||"  double pipe
     *   "|"   single pipe (only outside templates/tables)
     * Returns null on invalid count (must be 2-5).
     */
    private static function parseOptions( string $raw, int $maxCap = 5 ): ?array {
        if ( $raw === '' ) return null;
        $sep = null;
        if ( strpos( $raw, ';' ) !== false ) {
            $sep = '/;+/';
        } elseif ( strpos( $raw, '||' ) !== false ) {
            $sep = '/\|\|/';
        } elseif ( strpos( $raw, '|' ) !== false ) {
            $sep = '/(?<!\\\\)\|/';
        }
        if ( $sep === null ) return null;
        $parts = preg_split( $sep, $raw );
        $opts = [];
        foreach ( $parts as $p ) {
            $p = trim( str_replace( '\|', '|', $p ) );
            if ( $p !== '' ) $opts[] = mb_substr( $p, 0, 120 );
        }
        $n = count( $opts );
        if ( $n < self::MIN_OPTIONS || $n > $maxCap ) return null;
        return $opts;
    }

    private static function renderBinary( $element, string $rendered, int $userVote ): string {
        $up = (int)$element->ve_upvotes;
        $down = (int)$element->ve_downvotes;
        $score = $up - $down;
        $upActive   = $userVote ===  1 ? ' pcp-vote-active' : '';
        $downActive = $userVote === -1 ? ' pcp-vote-active' : '';
        $signClass = $score > 0 ? ' pcp-vote-score-pos'
                    : ( $score < 0 ? ' pcp-vote-score-neg' : ' pcp-vote-score-zero' );
        $scoreDisplay = $score > 0 ? ( '+' . $score ) : (string)$score;

        $html  = '<span class="pcp-vote" data-element-id="' . (int)$element->ve_id . '" data-vote-type="binary">';
        $html .= '<span class="pcp-vote-content">' . $rendered . '</span>';
        $html .= '<span class="pcp-vote-controls" role="group" aria-label="Vote">';
        $html .= '<button type="button" class="pcp-vote-btn pcp-vote-up' . $upActive . '" data-value="1" aria-label="Upvote">▲</button>';
        $html .= '<span class="pcp-vote-score' . $signClass . '" data-up="' . $up . '" data-down="' . $down . '">' . $scoreDisplay . '</span>';
        $html .= '<button type="button" class="pcp-vote-btn pcp-vote-down' . $downActive . '" data-value="-1" aria-label="Downvote">▼</button>';
        $html .= '</span></span>';
        return $html;
    }

    private static function renderChoice( $element, string $rendered, array $userChoices, array $tally ): string {
        $type = (string)$element->ve_type;
        $optionsRaw = json_decode( (string)$element->ve_options, true ) ?: [];
        // Mixed shape: entries are strings (legacy) or { label, added_by? } objects.
        $options = [];
        foreach ( $optionsRaw as $entry ) {
            if ( is_string( $entry ) ) $options[] = $entry;
            elseif ( is_array( $entry ) && isset( $entry['label'] ) ) $options[] = (string)$entry['label'];
        }
        $optionsH  = (string)( $element->ve_options_h ?? '' );
        $openEnded = (int)( $element->ve_open_ended ?? 0 ) === 1;
        $maxOptions = (int)( $element->ve_max_options ?? 5 );
        $total = 0;
        foreach ( $tally as $c ) $total += (int)$c;

        // Apply results-visibility policy.
        $policy = (string)( $element->ve_results_policy ?? 'live' );
        $showTally = self::shouldShowTally( $policy, $userChoices );
        $tallyOut = $showTally ? $tally : null;
        $totalOut = $showTally ? $total : null;

        $h = function ( $s ) { return htmlspecialchars( (string)$s, ENT_QUOTES ); };
        $payload = $h( json_encode( [
            'options' => $options,
            'tally'   => $tallyOut,
            'user'    => $userChoices,
            'total'   => $totalOut,
            'policy'  => $policy,
            'h'       => $optionsH,
        ] ) );

        $userTag = '';
        if ( $userChoices ) {
            $first = $options[ $userChoices[0] ] ?? '?';
            $userTag = ' · you: ' . $h( $first ) . ( count( $userChoices ) > 1 ? ' +' . ( count( $userChoices ) - 1 ) : '' );
        }

        // IMPORTANT: use <div> not <span>. MediaWiki auto-wraps content in <p>
        // when there are newlines, and <span> cannot legally contain <p>.
        $html  = '<div class="pcp-vote pcp-vote-choice" data-element-id="' . (int)$element->ve_id . '"';
        $html .= ' data-vote-type="' . $h( $type ) . '"';
        $html .= ' data-options-h="' . $h( $optionsH ) . '"';
        $html .= ' data-open-ended="' . ( $openEnded ? '1' : '0' ) . '"';
        $html .= ' data-max-options="' . (int)$maxOptions . '"';
        $html .= ' data-payload="' . $payload . '">';
        $html .= '<div class="pcp-vote-content">' . $rendered . '</div>';
        $html .= '<div class="pcp-vote-choice-summary" role="button" tabindex="0">';
        $html .= '<span class="pcp-vote-choice-icon"><svg class="pcp-vote-icon-svg" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false"><rect x="2" y="3"  width="12" height="2.5" fill="#000" stroke="#fff" stroke-width="0.8"/><rect x="2" y="6.75" width="8" height="2.5" fill="#000" stroke="#fff" stroke-width="0.8"/><rect x="2" y="10.5" width="5" height="2.5" fill="#000" stroke="#fff" stroke-width="0.8"/></svg></span>';
        $html .= '<span class="pcp-vote-choice-total">' . ( $showTally ? (int)$total : '–' ) . '</span>';
        if ( $userTag !== '' ) {
            $html .= '<span class="pcp-vote-choice-user">' . $userTag . '</span>';
        }
        $html .= '</div>';
        $html .= '<div class="pcp-vote-choice-picker" hidden></div>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Return true if the tally should be shown to this viewer under the policy.
     *   live       -> always
     *   after-vote -> only if user has voted ($userChoices non-empty)
     *   hidden     -> never
     */
    public static function shouldShowTally( string $policy, array $userChoices ): bool {
        if ( $policy === 'hidden' ) return false;
        if ( $policy === 'after-vote' ) return !empty( $userChoices );
        return true;  // live (default)
    }

}
