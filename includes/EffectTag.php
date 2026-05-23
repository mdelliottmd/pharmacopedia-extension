<?php
namespace MediaWiki\Extension\Pharmacopedia;

use MediaWiki\Context\RequestContext;

class EffectTag {
    /** Display labels for frequency buttons, paired with stored values. */
    const FREQUENCY_BUTTONS = [
        [ 'value' => 0,  'label' => '0' ],
        [ 'value' => 5,  'label' => '&lt;10%' ],
        [ 'value' => 20, 'label' => '~20%' ],
        [ 'value' => 33, 'label' => '~33%' ],
        [ 'value' => 50, 'label' => '~50%' ],
        [ 'value' => 66, 'label' => '~66%' ],
        [ 'value' => 80, 'label' => '~80%' ],
        [ 'value' => 95, 'label' => '90+%' ],
    ];

    public static function render( $input, array $args, $parser, $frame ) {
        $slug  = isset( $args['slug'] )  ? trim( (string)$args['slug'] )  : '';
        $label = isset( $args['label'] ) ? trim( (string)$args['label'] ) : '';
        $author = isset( $args['author'] ) ? trim( (string)$args['author'] ) : '';
        $ref = isset( $args['ref'] ) ? trim( (string)$args['ref'] ) : '';
        if ( $ref !== '' ) {
            $globalStore = new GlobalEffectStore();
            $global = $globalStore->resolve( $ref );
            if ( $global ) {
                $slug = 'ref-' . $global->e_slug;
                $label = $global->e_name;
            } else {
                return '<span class="pcp-error">&lt;effect&gt;: unknown ref "' . htmlspecialchars( $ref ) . '"</span>';
            }
        }
        if ( $slug === '' ) { return '<span class="pcp-error">&lt;effect&gt;: slug required</span>'; }
        $title = $parser->getTitle();
        if ( !$title ) { return '<span class="pcp-error">&lt;effect&gt;: no page context</span>'; }
        $pageId = $title->getArticleID();
        if ( $pageId <= 0 ) { return $parser->recursiveTagParse( (string)$input, $frame ); }
        if ( $label === '' ) { $label = $slug; }

        $rendered = trim( $input ) !== '' ? $parser->recursiveTagParse( (string)$input, $frame ) : '';

        $elementStore = new ElementStore();
        $element = $elementStore->getOrCreate( $pageId, $slug, 'effect', $label );
        $elementId = (int)$element->ve_id;

        $effectStore = new EffectStore();
        $patient  = $effectStore->getAggregates( $elementId, EffectStore::PERSPECTIVE_PATIENT );
        $provider = $effectStore->getAggregates( $elementId, EffectStore::PERSPECTIVE_PROVIDER );

        $ctxUser = RequestContext::getMain()->getUser();
        $userPatient = null; $userProvider = null;
        if ( $ctxUser->isRegistered() ) {
            $userPatient  = $effectStore->getUserReport( $elementId, $ctxUser->getId(), EffectStore::PERSPECTIVE_PATIENT );
            $userProvider = $effectStore->getUserReport( $elementId, $ctxUser->getId(), EffectStore::PERSPECTIVE_PROVIDER );
        }
        $uPatExp  = ( $userPatient && $userPatient->er_experienced  !== null ) ? (int)$userPatient->er_experienced  : '';
        $uPatVal  = ( $userPatient && $userPatient->er_valence      !== null ) ? (int)$userPatient->er_valence      : '';
        $uProFreq = ( $userProvider && $userProvider->er_frequency_pct !== null ) ? (int)$userProvider->er_frequency_pct : '';
        $uProVal  = ( $userProvider && $userProvider->er_valence    !== null ) ? (int)$userProvider->er_valence    : '';

        $parser->getOutput()->updateCacheExpiry( 0 );
        $parser->getOutput()->addModules( ['ext.pharmacopedia'] );
        $parser->getOutput()->addModuleStyles( ['ext.pharmacopedia.styles'] );

        $labelEsc = htmlspecialchars( $label, ENT_QUOTES );

        // OUTER row (keeps .pcp-effect for existing JS handlers + chrome data attrs)
        $h  = '<div class="pcp-row pcp-row-effect pcp-effect" data-element-id="' . $elementId .
              '" data-current-perspective="1"' .
              ' data-user-patient-experienced="' . $uPatExp . '"' .
              ' data-user-patient-valence="' . $uPatVal . '"' .
              ' data-user-provider-frequency="' . $uProFreq . '"' .
              ' data-user-provider-valence="' . $uProVal . '">';

        // HEAD line
        $h .= '<div class="pcp-row-head">';
        $pcpEffTitle = defined( 'NS_EFFECT' ) ? \MediaWiki\Title\Title::makeTitleSafe( NS_EFFECT, $label ) : null;
        if ( $pcpEffTitle ) {
            $h .= '<span class="pcp-row-title pcp-effect-label"><a href="' . htmlspecialchars( $pcpEffTitle->getLocalURL() ) . '">' . $labelEsc . '</a></span>';
        } else {
            $h .= '<span class="pcp-row-title pcp-effect-label">' . $labelEsc . '</span>';
        }
        $h .= '<span class="pcp-row-aggs">';
        $h .= self::renderPatientAggRow( $patient );
        $h .= self::renderProviderAggRow( $provider );
        $h .= '</span>';
        $h .= '<span class="pcp-row-actions">';
        $h .= '<button type="button" class="pcp-row-action pcp-row-action-toggle" data-target="rate" aria-expanded="false">Rate</button>';
        $h .= SpecialDeletePharmaElement::buttonHtml( 'effect', $slug, $author );
        $h .= '</span>';
        $h .= '</div>';

        // BODY (page-specific desc, always visible)
        if ( $rendered !== '' ) {
            $h .= '<div class="pcp-row-body pcp-effect-desc">' . $rendered . '</div>';
        }

        // RATE panel (folded). Perspective + patient buttons + provider buttons + valence.
        $h .= '<div class="pcp-row-panel pcp-row-rate-panel" hidden>';

        // Fieldset + visually-hidden legend per WCAG 1.3.1 / 3.3.2
        // (a11y-claude baseline 2026-05-22).
        $h .= '<fieldset class="pcp-effect-perspective-row">';
        $h .= '<legend class="visuallyhidden">Perspective for this effect</legend>';
        $h .= '<label><input type="radio" name="persp-' . $elementId . '" value="1" checked> Personal experience</label>';
        $h .= '<label><input type="radio" name="persp-' . $elementId . '" value="2"> From my patients</label>';
        $h .= '</fieldset>';

        $h .= '<div class="pcp-effect-controls pcp-controls-patient">';
        $h .= '<span class="pcp-effect-q">Did you experience this?</span>';
        foreach ( [ 1 => 'Yes', 0 => 'No', 2 => 'Unsure' ] as $v => $lbl ) {
            $h .= '<button type="button" class="pcp-effect-btn" data-experienced="' . $v . '">' . $lbl . '</button>';
        }
        $h .= '</div>';

        $h .= '<div class="pcp-effect-controls pcp-controls-provider">';
        $h .= '<span class="pcp-effect-q">How often have you seen this?</span>';
        // Continuous 0–100 slider. Storage column er_frequency_pct (TINYINT)
        // already holds the percent. Initial value: existing provider freq if
        // set and not -1 (Don't know), otherwise 50 as a neutral midpoint.
        $initFreq = ( $uProFreq !== '' && (int)$uProFreq >= 0 ) ? (int)$uProFreq : 50;
        $h .= '<span class="pcp-effect-fslider-wrap">';
        $h .= '<input type="range" class="pcp-effect-fslider" aria-label="How often you have seen this effect" min="0" max="100" step="1" value="' . $initFreq . '" oninput="this.nextElementSibling.value=this.value+\'%\'">';
        $h .= '<output class="pcp-effect-fslider-out">' . $initFreq . '%</output>';
        $h .= '</span>';
        $h .= '<button type="button" class="pcp-effect-fbtn pcp-effect-fbtn-dk" data-frequency="-1">Don\'t know</button>';
        $h .= '</div>';

        $h .= '<div class="pcp-effect-valence-row">';
        $h .= '<span class="pcp-effect-q pcp-q-patient">How was it? (-100 worst, +100 best)</span>';
        $h .= '<span class="pcp-effect-q pcp-q-provider">How was it? (-100 worst, +100 best)</span>';
        // Continuous slider replaces the 7 discrete buttons. Patient + provider
        // perspectives share the same slider; JS sets value from the current
        // perspective's state on toggle.
        $initVal = 0;
        if ( $uPatVal !== '' ) { $initVal = (int)$uPatVal; }
        elseif ( $uProVal !== '' ) { $initVal = (int)$uProVal; }
        $h .= '<span class="pcp-effect-vslider-wrap">';
        $h .= '<span class="pcp-effect-vslider-anchor pcp-effect-vslider-anchor-neg">−100</span>';
        $h .= '<input type="range" class="pcp-effect-vslider" aria-label="How was this effect, worst to best" min="-100" max="100" step="1" value="' . $initVal . '" oninput="this.nextElementSibling.value=(this.value>=0?\'+\':\'\')+this.value">';
        $h .= '<output class="pcp-effect-vslider-out">' . ( $initVal >= 0 ? '+' : '' ) . $initVal . '</output>';
        $h .= '<span class="pcp-effect-vslider-anchor pcp-effect-vslider-anchor-pos">+100</span>';
        $h .= '</span>';
        $h .= '</div>';

        $h .= '</div>'; // /rate-panel
        $h .= '</div>'; // /row
        return $h;
    }

    private static function renderPatientAggRow( $agg ) {
        $n = (int)$agg['n'];
        $vMean = $agg['valence_mean'];
        $h = '<span class="pcp-effect-agg-row pcp-agg-patient" data-n="' . $n . '" data-yes="' . (int)$agg['yes'] . '" data-vmean="' . ( $vMean ?? '' ) . '">';
        $h .= '<span class="pcp-agg-icon">👤</span> ';
        if ( $n > 0 ) {
            $pct = (int)round( ( $agg['yes'] / $n ) * 100 );
            $vFmt = $vMean !== null ? sprintf( '%+.1f', $vMean ) : '—';
            $h .= '<span class="pcp-effect-pct">' . $pct . '%</span>';
            $h .= ' <span class="pcp-effect-vmean">' . $vFmt . '</span>';
            $h .= ' <span class="pcp-effect-n">(n=' . $n . ')</span>';
        } else {
            $h .= '<span class="pcp-effect-noreports">no reports yet</span>';
        }
        $h .= '</span>';
        return $h;
    }

    private static function renderProviderAggRow( $agg ) {
        $n = (int)$agg['n'];
        $fMean = $agg['frequency_mean'] ?? null;
        $vMean = $agg['valence_mean'];
        $h = '<span class="pcp-effect-agg-row pcp-agg-provider" data-n="' . $n . '" data-fmean="' . ( $fMean ?? '' ) . '" data-vmean="' . ( $vMean ?? '' ) . '">';
        $h .= '<span class="pcp-agg-icon">⚕️</span> ';
        if ( $n > 0 ) {
            $vFmt = $vMean !== null ? sprintf( '%+.1f', $vMean ) : '—';
            $fFmt = $fMean !== null ? '~' . (int)$fMean . '%' : '—';
            $h .= '<span class="pcp-effect-fmean">' . $fFmt . '</span>';
            $h .= ' <span class="pcp-effect-vmean">' . $vFmt . '</span>';
            $h .= ' <span class="pcp-effect-n">(n=' . $n . ')</span>';
        } else {
            $h .= '<span class="pcp-effect-noreports">no reports yet</span>';
        }
        $h .= '</span>';
        return $h;
    }
}
