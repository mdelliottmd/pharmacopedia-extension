<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\Parser\Parser;
use PPFrame;
use MediaWiki\Title\Title;

/**
 * Read-only render for the new Interactions section.
 *
 * Drop <pharmaInteractions/> anywhere in a medicine article or Category page.
 *  - On medicine pages: lists direct medicine-to-medicine / medicine-to-category
 *    interactions plus transitive category-to-anything edges via the page's
 *    own categories. Direct rows win over transitive ones for the same counterparty.
 *  - On Category pages: lists direct edges only.
 *
 * No vote widgets yet -- that's Phase 4.
 */
class InteractionTag {
    public static function render( $input, array $args, Parser $parser, PPFrame $frame ) {
        $title = $parser->getTitle();
        if ( !$title ) { return ''; }
        $ns = $title->getNamespace();
        if ( $ns !== NS_MAIN && $ns !== NS_CATEGORY ) {
            return '<div class="pcp-interactions pcp-interactions-skipped"><em>' .
                   'The Interactions section only renders on medicine articles and Category pages.' .
                   '</em></div>';
        }

        $store    = new InteractionStore();
        $pageSlug = $title->getDBkey();
        $entries  = [];

        if ( $ns === NS_CATEGORY ) {
            $entries = $store->listForCategory( $pageSlug );
        } else {
            // NS_MAIN -- collect this page's categories (DB-key form, no prefix).
            $categories = [];
            foreach ( array_keys( $title->getParentCategories() ) as $catTitleText ) {
                $t = Title::newFromText( $catTitleText );
                if ( $t && $t->getNamespace() === NS_CATEGORY ) {
                    $categories[] = $t->getDBkey();
                }
            }
            $entries = $store->listForMedicineWithCategories( $pageSlug, $categories );
        }

        $parser->getOutput()->updateCacheExpiry( 0 );
        $parser->getOutput()->addModules( [ 'ext.pharmacopedia' ] );
        $parser->getOutput()->addModuleStyles( [ 'ext.pharmacopedia.styles' ] );

        if ( empty( $entries ) ) {
            return '<div class="pcp-interactions">' .
                '<div class="pcp-interactions-empty"><em>No interactions reported yet.</em></div>' .
                self::renderAddButton( $title ) .
                '</div>';
        }

        // Pre-compute pooled / per-perspective aggregates once per row.
        $rendered = [];
        foreach ( $entries as $e ) {
            $eid = (int)$e['row']->pi_element_id;
            $rendered[] = [
                'entry'    => $e,
                'pooled'   => $store->getAggregates( $eid ),
                'user'     => $store->getAggregates( $eid, InteractionStore::PERSPECTIVE_USER ),
                'provider' => $store->getAggregates( $eid, InteractionStore::PERSPECTIVE_PROVIDER ),
            ];
        }

        // Sort by pooled valence_mean ASCENDING: most-negative outcome on top.
        // Nulls (no reports) sink to the bottom. Tiebreaker: n desc, then alphabetic.
        usort( $rendered, function ( $a, $b ) {
            $av = $a['pooled']['valence_mean'];
            $bv = $b['pooled']['valence_mean'];
            if ( $av === null && $bv !== null ) { return 1; }
            if ( $av !== null && $bv === null ) { return -1; }
            if ( $av !== null && $bv !== null && $av !== $bv ) {
                return $av <=> $bv;
            }
            $an = (int)$a['pooled']['n']; $bn = (int)$b['pooled']['n'];
            if ( $an !== $bn ) { return $bn - $an; }
            return strcmp( $a['entry']['other_slug'], $b['entry']['other_slug'] );
        } );

        $html = '<div class="pcp-interactions">';
        foreach ( $rendered as $r ) {
            $html .= self::renderRow( $r );
        }
        $html .= self::renderAddButton( $title );
        $html .= '</div>';
        return $html;
    }

    /**
     * Inline rating widget rendered under each interaction row. The actual
     * button event handlers live in ext.pharmacopedia.js (.pcp-interaction-rate-* selectors).
     * Server-side we only emit markup + the current user's existing report values
     * as data-* attrs so the buttons can pre-highlight without an extra API call.
     */
    /**
     * Public reader-facing notes block.
     * Renders a <details>Notes (N)</details> listing every report's note for this
     * interaction, attributed to the reporter and tagged by perspective.
     * Returns '' when no notes exist.
     */
    /** Returns [ 'count' => int, 'html' => string ] for the notes panel under a row. */
    private static function renderNotesPanel( $elementId, $store ) {
        $reports = $store->listReports( $elementId );
        $withNotes = [];
        foreach ( $reports as $r ) {
            if ( $r->pir_note !== null && trim( (string)$r->pir_note ) !== '' ) {
                $withNotes[] = $r;
            }
        }
        if ( !$withNotes ) { return [ 'count' => 0, 'html' => '' ]; }
        $h  = '<div class="pcp-row-panel pcp-row-notes-panel pcp-interaction-notes" hidden>';
        $h .= '<ul class="pcp-interaction-notes-list">';
        foreach ( $withNotes as $r ) {
            $isProvider = (int)$r->pir_perspective === InteractionStore::PERSPECTIVE_PROVIDER;
            $icon = $isProvider ? '⚕️' : '👤';
            // Anonymized: row no longer carries user_name.
            $name = 'Anonymous';
            $ts = $r->pir_updated ?: $r->pir_created;
            $tsFmt = '';
            if ( $ts ) {
                $tsFmt = substr( $ts, 0, 4 ) . '-' . substr( $ts, 4, 2 ) . '-' . substr( $ts, 6, 2 );
            }
            $body = htmlspecialchars( (string)$r->pir_note, ENT_QUOTES, 'UTF-8' );
            $body = nl2br( $body );
            $h .= '<li class="pcp-interaction-note' . ( $isProvider ? ' pcp-interaction-note-provider' : '' ) . '"' .
                  // anonymized: no author id
                  ' data-author-name="' . htmlspecialchars( $name ) . '"' .
                  ' data-perspective="' . (int)$r->pir_perspective . '"' .
                  ' data-element-id="' . (int)$elementId . '">';
            $h .= '<div class="pcp-interaction-note-meta">';
            $h .= '<span class="pcp-interaction-note-icon">' . $icon . '</span> ';
            $h .= '<span class="pcp-interaction-note-user">' . htmlspecialchars( $name ) . '</span> ';
            if ( $tsFmt ) {
                $h .= '<span class="pcp-interaction-note-ts">' . htmlspecialchars( $tsFmt ) . '</span>';
            }
            $h .= '<button type="button" class="pcp-ix-del-note" title="Delete this note" aria-label="Delete note">×</button>';
            $h .= '</div>';
            $h .= '<div class="pcp-interaction-note-body">' . $body . '</div>';
            $h .= '</li>';
        }
        $h .= '</ul></div>';
        return [ 'count' => count( $withNotes ), 'html' => $h ];
    }

    private static function renderRateWidget( $elementId, $user, $store ) {
        $loggedIn = $user && $user->isRegistered();
        $canProvider = $loggedIn && $user->isAllowed( 'pharmacopedia-effect-as-provider' );

        // Pre-fetch the user's current votes (both perspectives) for state seeding.
        $userVotes = [ 1 => [ 'exp' => null, 'val' => null ],
                       2 => [ 'exp' => null, 'val' => null ] ];
        if ( $loggedIn ) {
            foreach ( [ 1, 2 ] as $p ) {
                $r = $store->getUserReport( $elementId, $user->getId(), $p );
                if ( $r ) {
                    $userVotes[$p]['exp'] = $r->pir_experience !== null ? (int)$r->pir_experience : null;
                    $userVotes[$p]['val'] = $r->pir_valence    !== null ? (int)$r->pir_valence    : null;
                }
            }
        }
        // Also fetch the user's existing notes for both perspectives
        $userNotes = [ 1 => '', 2 => '' ];
        if ( $loggedIn ) {
            foreach ( [ 1, 2 ] as $p ) {
                $r = $store->getUserReport( $elementId, $user->getId(), $p );
                if ( $r && $r->pir_note !== null ) {
                    $userNotes[$p] = (string)$r->pir_note;
                }
            }
        }
        $dataAttrs =
            ' data-user-1-exp="' . ( $userVotes[1]['exp'] ?? '' ) . '"' .
            ' data-user-1-val="' . ( $userVotes[1]['val'] ?? '' ) . '"' .
            ' data-user-1-note="' . htmlspecialchars( $userNotes[1], ENT_QUOTES, 'UTF-8' ) . '"' .
            ' data-user-2-exp="' . ( $userVotes[2]['exp'] ?? '' ) . '"' .
            ' data-user-2-val="' . ( $userVotes[2]['val'] ?? '' ) . '"' .
            ' data-user-2-note="' . htmlspecialchars( $userNotes[2], ENT_QUOTES, 'UTF-8' ) . '"' .
            ' data-can-provider="' . ( $canProvider ? '1' : '0' ) . '"' .
            ' data-logged-in="'   . ( $loggedIn    ? '1' : '0' ) . '"';

        $h  = '<div class="pcp-row-panel pcp-row-rate-panel pcp-interaction-rate"' . $dataAttrs . ' hidden>';

        // Perspective selector (rendered for everyone; UI hides it if not eligible)
        $h .= '<div class="pcp-interaction-persp-row">';
        $h .= '<label><input type="radio" name="pcp-ix-persp-' . $elementId . '" value="1" checked> Personal experience</label> ';
        $h .= '<label class="pcp-ix-persp-provider"><input type="radio" name="pcp-ix-persp-' . $elementId . '" value="2"> As a clinician (provider)</label>';
        $h .= '</div>';

        // Experience row (1-5)
        $h .= '<div class="pcp-interaction-q">How much experience do you have with this combination (1 a little, 5 a lot)?</div>';
        $h .= '<div class="pcp-interaction-btnrow pcp-interaction-exprow">';
        for ( $i = 1; $i <= 5; $i++ ) {
            $h .= '<button type="button" class="pcp-ix-expbtn" data-experience="' . $i . '">' . $i . '</button>';
        }
        $h .= '</div>';

        // Valence row (-100..+100)
        $h .= '<div class="pcp-interaction-q">How did it go? (-100 worst, +100 best)</div>';
        $h .= '<div class="pcp-interaction-btnrow pcp-interaction-valrow">';
        $h .= '<span class="pcp-effect-vslider-wrap pcp-ix-vslider-wrap">';
        $h .= '<span class="pcp-effect-vslider-anchor pcp-effect-vslider-anchor-neg">−100</span>';
        $h .= '<input type="range" class="pcp-ix-vslider" min="-100" max="100" step="1" value="0" oninput="this.nextElementSibling.value=(this.value>=0?\'+\':\'\')+this.value">';
        $h .= '<output class="pcp-effect-vslider-out">0</output>';
        $h .= '<span class="pcp-effect-vslider-anchor pcp-effect-vslider-anchor-pos">+100</span>';
        $h .= '</span>';
        $h .= '</div>';

        // Optional note (hidden behind a toggle)
        $h .= '<div class="pcp-interaction-note-wrap">';
        $h .= '<a class="pcp-ix-note-toggle" href="#">+ Add a note</a>';
        $h .= '<div class="pcp-ix-note" hidden>';
        $h .= '<textarea class="pcp-ix-note-input" rows="2" maxlength="8000" placeholder="What happened? (optional)"></textarea>';
        $h .= '<button type="button" class="pcp-ix-note-save mw-ui-button mw-ui-progressive">Save note</button>';
        $h .= '<div class="pcp-ix-note-status"></div>';
        $h .= '</div>';
        $h .= '</div>';

        $h .= '</div>'; // /panel
        return $h;
    }

    private static function renderAddButton( $pageTitle ) {
        $ns = $pageTitle->getNamespace();
        $type = ( $ns === NS_CATEGORY ) ? InteractionStore::TYPE_CATEGORY : InteractionStore::TYPE_MEDICINE;
        $slug = $pageTitle->getDBkey();
        $h  = '<div class="pcp-interaction-addwrap">';
        $h .= '<button type="button" class="pcp-interaction-add"';
        $h .= ' data-page-type="' . htmlspecialchars( $type ) . '"';
        $h .= ' data-page-slug="' . htmlspecialchars( $slug ) . '"';
        $h .= ' data-page-name="' . htmlspecialchars( str_replace( '_', ' ', $slug ) ) . '"';
        $h .= '>+ Add an interaction</button>';
        $h .= '</div>';
        return $h;
    }

    private static function renderRow( $r ) {
        $entry    = $r['entry'];
        $row      = $entry['row'];
        $otherT   = $entry['other_type'];
        $otherS   = $entry['other_slug'];
        $via      = $entry['via'];
        $pooled   = $r['pooled'];
        $userAgg  = $r['user'];
        $provAgg  = $r['provider'];
        $eid      = (int)$row->pi_element_id;

        // Counterparty link
        $otherName = str_replace( '_', ' ', $otherS );
        $isOtherCategory = ( $otherT === InteractionStore::TYPE_CATEGORY );
        if ( $isOtherCategory ) {
            $otherTitle = Title::makeTitle( NS_CATEGORY, $otherS );
        } else {
            $otherTitle = Title::newFromText( $otherName );
        }
        $otherLabel = $otherName;
        $otherUrl = $otherTitle ? $otherTitle->getLocalURL() : '#';

        $severe = $pooled['severe'] || $userAgg['severe'] || $provAgg['severe'];
        $severeCls = $severe ? ' pcp-interaction-severe' : '';

        $store = new InteractionStore();
        $notesPanel = self::renderNotesPanel( $eid, $store );
        $user = \RequestContext::getMain()->getUser();

        $h  = '<div class="pcp-row pcp-row-interaction pcp-interaction-row' . $severeCls . '"';
        $h .= ' data-element-id="' . $eid . '"';
        $h .= ' data-n="' . (int)$pooled['n'] . '"';
        $h .= ' data-vmean="' . ( $pooled['valence_mean'] === null ? '' : $pooled['valence_mean'] ) . '"';
        $h .= '>';

        // HEAD line
        $h .= '<div class="pcp-row-head">';
        $h .= '<span class="pcp-row-title">';
        $otherCls = 'pcp-interaction-other' . ( $isOtherCategory ? ' pcp-interaction-other-category' : '' );
        $h .= '<a class="' . $otherCls . '" href="' . htmlspecialchars( $otherUrl ) . '">' .
              htmlspecialchars( $otherLabel ) . '</a>';
        if ( $via !== null ) {
            $viaName = str_replace( '_', ' ', $via );
            $viaTitle = Title::makeTitle( NS_CATEGORY, $via );
            $viaUrl = $viaTitle ? $viaTitle->getLocalURL() : '#';
            $h .= ' <a class="pcp-interaction-via" href="' . htmlspecialchars( $viaUrl ) . '">' .
                  'via Category:' . htmlspecialchars( $viaName ) . '</a>';
        }
        if ( $severe ) {
            $h .= ' <span class="pcp-interaction-severe-tag">severe</span>';
        }
        $h .= '</span>';

        $h .= '<span class="pcp-row-aggs">';
        $h .= self::renderAggLine( '👤', 'user',     $userAgg );
        $h .= self::renderAggLine( '⚕️', 'provider', $provAgg );
        $h .= '</span>';

        $h .= '<span class="pcp-row-actions">';
        $h .= '<button type="button" class="pcp-row-action pcp-row-action-toggle" data-target="rate" aria-expanded="false">Rate</button>';
        if ( $notesPanel['count'] > 0 ) {
            $h .= '<button type="button" class="pcp-row-action pcp-row-action-toggle" data-target="notes" aria-expanded="false">Notes (' . (int)$notesPanel['count'] . ')</button>';
        }
        $h .= '<button type="button" class="pcp-ix-del-row" data-element-id="' . $eid . '" title="Delete this interaction (admin)" aria-label="Delete interaction">×</button>';
        $h .= '</span>';
        $h .= '</div>';

        // Panels below the head
        $h .= self::renderRateWidget( $eid, $user, $store );
        $h .= $notesPanel['html'];

        $h .= '</div>';
        return $h;
    }

    private static function renderAggLine( $icon, $persp, $agg ) {
        $h  = '<span class="pcp-interaction-agg pcp-interaction-agg-' . htmlspecialchars( $persp ) . '"';
        $h .= ' data-n="' . (int)$agg['n'] . '"';
        if ( $agg['valence_mean'] !== null ) {
            $h .= ' data-vmean="' . $agg['valence_mean'] . '"';
        }
        if ( $agg['experience_mean'] !== null ) {
            $h .= ' data-emean="' . $agg['experience_mean'] . '"';
        }
        $h .= '>';
        $h .= '<span class="pcp-interaction-agg-icon">' . $icon . '</span> ';
        if ( (int)$agg['n'] === 0 ) {
            $h .= '<span class="pcp-interaction-agg-empty">no reports yet</span>';
        } else {
            $expFmt = $agg['experience_mean'] !== null
                ? number_format( (float)$agg['experience_mean'], 1 ) : '—';
            $vmean  = $agg['valence_mean'];
            $vFmt = $vmean !== null ? sprintf( '%+.1f', (float)$vmean ) : '—';
            $h .= '<span class="pcp-interaction-agg-exp" title="experience: 1=a little, 5=extensive">' .
                  'exp ' . htmlspecialchars( $expFmt ) . '/5</span> ';
            $h .= '<span class="pcp-interaction-agg-val" title="outcome: -3 worst, +3 best">' .
                  'outcome ' . htmlspecialchars( $vFmt ) . '</span> ';
            $h .= '<span class="pcp-interaction-agg-n">(n=' . (int)$agg['n'] . ')</span>';
        }
        $h .= '</span>';
        return $h;
    }
}
