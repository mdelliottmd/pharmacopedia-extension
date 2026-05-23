<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * AssessmentRegistry - the single source of truth for every assessment in the
 * extension. Every surface that talks about assessments reads from here:
 *
 *   - Special:AdministerAssessments (owner picker + invite creator)
 *   - Special:RespondToAssessment   (the respondent take-flow)
 *   - Special:MyProfile             (the self-take + inline dashboard)
 *   - Special:UserProfile           (someone else's results, by visibility)
 *   - Special:MyAssessment          (the per-instrument detail report)
 *
 * Register a scale here ONCE and it autopopulates everywhere that lists or
 * renders assessments, with no per-surface allowlist to maintain.
 *
 * Spec fields:
 *   class           FQCN of the scorer class.
 *   model           Formal rendering model:
 *                     radio    discrete RESPONSE_LABELS radio buttons
 *                     slider   one continuous slider per item, uniform anchors
 *                     bipolar  one slider per item, anchors taken from each
 *                              item's own two opposing phrases (MBTI)
 *                     mixed    per-item heterogeneous; each item carries its
 *                              own 'type' (slider, count, gated_count, ...)
 *                              and the renderer dispatches per item.
 *   min, max, step  Numeric bounds for slider/bipolar models. The take-flow
 *                   clamps responses to [min, max].
 *   lo, hi          Anchor text shown at the ends of every slider (slider
 *                   model only).
 *   crisis          If true, the take-flow shows crisis resources for any
 *                   item that surfaces self-harm or eating-disorder content.
 *   self_takeable   If false, the scale is owner-administered only (think
 *                   future "-OR" outside-respondent-only instruments) and is
 *                   excluded from MyProfile and the personal dashboards.
 *                   Defaults to true.
 *   inline          Optional per-scale render override for the MyProfile
 *                   inline form. If absent, MyProfile uses the formal model
 *                   above. If present, it shapes the inline render as one of:
 *                     as     'radio' | 'slider' | 'bipolar' | 'mixed'
 *                     min, max, step, lo, hi  same as the formal spec, but
 *                                             applied only inline; lets a
 *                                             formally-radio scale render as
 *                                             a continuous slider in the
 *                                             personal dashboard (the
 *                                             house preference).
 *                     default                 starting slider position.
 *                     precision               decimals shown in the live
 *                                             readout (0 = integer display).
 *
 * @license GPL-3.0-or-later
 */
class AssessmentRegistry {

    /** key => spec. Alphabetical by key; the picker sorts by display label. */
    private const ASSESSMENTS = [
        'amaas' => [
            'class' => Amaas::class, 'model' => 'slider',
            'min' => 0, 'max' => 100, 'step' => 1,
            'lo' => '0% of the time', 'hi' => '100% of the time',
            'inline' => [
                'as' => 'slider',
                'min' => 0, 'max' => 100, 'step' => 1, 'default' => 50,
                'lo' => 'Never (0%)', 'hi' => 'Always (100%)',
                'precision' => 0,
            ],
        ],
        'asrs' => [
            'class' => Asrs::class, 'model' => 'radio',
            // ASRS is reproduced verbatim under licence: integer-coded radio
            // is required, no inline-slider override.
        ],
        'bpns' => [
            'class' => Bpns::class, 'model' => 'radio',
            'inline' => [
                'as' => 'slider',
                'min' => 1, 'max' => 7, 'step' => 0.01, 'default' => 4,
                'lo' => 'Not at all true', 'hi' => 'Very true',
                'precision' => 2,
            ],
        ],
        'bsl23' => [
            'class' => Bsl23::class, 'model' => 'slider',
            'min' => 0, 'max' => 4, 'step' => 0.01,
            'lo' => 'Not at all', 'hi' => 'Very strongly', 'crisis' => true,
            'inline' => [
                'as' => 'slider',
                'min' => 0, 'max' => 4, 'step' => 0.01, 'default' => 0,
                'lo' => 'Not at all', 'hi' => 'Very strongly',
                'precision' => 2,
            ],
        ],
        'cati' => [
            'class' => Cati::class, 'model' => 'radio',
            'inline' => [
                'as' => 'slider',
                'min' => 1, 'max' => 5, 'step' => 0.01, 'default' => 3,
                'lo' => 'Definitely Disagree', 'hi' => 'Definitely Agree',
                'precision' => 2,
            ],
        ],
        'catq' => [
            'class' => Catq::class, 'model' => 'radio',
            'inline' => [
                'as' => 'slider',
                'min' => 1, 'max' => 7, 'step' => 0.01, 'default' => 4,
                'lo' => 'Strongly disagree', 'hi' => 'Strongly agree',
                'precision' => 2,
            ],
        ],
        'edeq' => [
            'class' => Edeq::class, 'model' => 'mixed', 'crisis' => true,
            'inline' => [ 'as' => 'mixed' ],
        ],
        'enneagram' => [
            'class' => Enneagram::class, 'model' => 'slider',
            'min' => 1, 'max' => 5, 'step' => 0.01,
            'lo' => 'Strongly disagree', 'hi' => 'Strongly agree',
            'inline' => [
                'as' => 'slider',
                'min' => 1, 'max' => 5, 'step' => 0.01, 'default' => 3,
                'lo' => 'Strongly disagree', 'hi' => 'Strongly agree',
                'precision' => 2,
            ],
        ],
        'ess' => [
            'class' => Ess::class, 'model' => 'slider',
            'min' => 0, 'max' => 3, 'step' => 0.01,
            'lo' => 'Would never doze', 'hi' => 'High chance of dozing',
            'inline' => [
                'as' => 'slider',
                'min' => Ess::SCALE_MIN, 'max' => Ess::SCALE_MAX,
                'step' => 0.01, 'default' => 0,
                'lo' => Ess::ANCHOR_LOW, 'hi' => Ess::ANCHOR_HIGH,
                'precision' => 2,
            ],
        ],
        'hyd' => [
            'class' => Hyd::class, 'model' => 'slider',
            'min' => -100, 'max' => 100, 'step' => 1,
            'lo' => 'Really poorly', 'hi' => 'Really well',
            'inline' => [
                'as' => 'slider',
                'min' => Hyd::SCALE_MIN, 'max' => Hyd::SCALE_MAX,
                'step' => 1, 'default' => 0,
                'lo' => Hyd::ANCHOR_LOW, 'hi' => Hyd::ANCHOR_HIGH,
                'precision' => 0,
            ],
        ],
        'mbti' => [
            'class' => Mbti::class, 'model' => 'bipolar',
            'min' => 1, 'max' => 5, 'step' => 0.01,
            'inline' => [
                'as' => 'bipolar',
                'min' => 1, 'max' => 5, 'step' => 0.01, 'default' => 3,
                'precision' => 2,
            ],
        ],
        'nfcs' => [
            'class' => Nfcs::class, 'model' => 'radio',
            'inline' => [
                'as' => 'slider',
                'min' => 1, 'max' => 6, 'step' => 0.01, 'default' => 3.5,
                'lo' => 'Strongly disagree', 'hi' => 'Strongly agree',
                'precision' => 2,
            ],
        ],
        'ocean' => [
            'class' => Ocean::class, 'model' => 'slider',
            'min' => 0, 'max' => 100, 'step' => 1,
            'lo' => 'Disagree', 'hi' => 'Agree',
            // OCEAN has a bespoke inline renderer (renderBfi10) that lives in
            // SpecialMyProfile; no override here. The bespoke path remains.
        ],
        'ocipcp' => [
            'class' => Ocipcp::class, 'model' => 'radio',
            'inline' => [
                'as' => 'slider',
                'min' => 0, 'max' => 4, 'step' => 0.01, 'default' => 2,
                'lo' => 'Not at all', 'hi' => 'Extremely',
                'precision' => 2,
            ],
        ],
        'pid5bf' => [
            'class' => Pid5bf::class, 'model' => 'radio',
            'inline' => [
                'as' => 'slider',
                'min' => 0, 'max' => 3, 'step' => 0.01, 'default' => 1.5,
                'lo' => 'Completely false', 'hi' => 'Completely true',
                'precision' => 2,
            ],
        ],
        'whoqolbref' => [
            'class' => WhoqolBref::class, 'model' => 'radio',
            'inline' => [
                'as' => 'slider',
                'min' => 1, 'max' => 5, 'step' => 0.01, 'default' => 3,
                'lo' => 'Very poor / dissatisfied / not at all',
                'hi' => 'Very good / satisfied / completely',
                'precision' => 2,
            ],
        ],
    ];

    /** All registered assessment keys, in registry order. */
    public static function keys(): array {
        return array_keys( self::ASSESSMENTS );
    }

    /** True if $key is a registered assessment. */
    public static function has( string $key ): bool {
        return isset( self::ASSESSMENTS[ $key ] );
    }

    /** The full spec for $key, or null. */
    public static function spec( string $key ): ?array {
        return self::ASSESSMENTS[ $key ] ?? null;
    }

    /** Scorer class FQCN for $key, or null. */
    public static function scorerClass( string $key ): ?string {
        return self::ASSESSMENTS[ $key ]['class'] ?? null;
    }

    /** Response model for $key: 'radio' | 'slider' | 'bipolar' | 'mixed'. Defaults to radio. */
    public static function model( string $key ): string {
        return self::ASSESSMENTS[ $key ]['model'] ?? 'radio';
    }

    /** Display label for $key, read from the scorer's NAME constant. */
    public static function label( string $key ): string {
        $class = self::ASSESSMENTS[ $key ]['class'] ?? null;
        return $class ? $class::NAME : strtoupper( $key );
    }

    /**
     * True if this scale is self-takeable (appears in MyProfile and the
     * personal dashboards). Defaults to true; only owner-administered
     * outside-respondent-only instruments opt out.
     */
    public static function selfTakeable( string $key ): bool {
        $spec = self::ASSESSMENTS[ $key ] ?? null;
        return $spec ? ( $spec['self_takeable'] ?? true ) : false;
    }

    /** Keys of all self-takeable scales, in registry order. */
    public static function keysSelfTakeable(): array {
        return array_values( array_filter( self::keys(), [ self::class, 'selfTakeable' ] ) );
    }

    /**
     * The MyProfile inline-form render spec for $key. May override the formal
     * model (e.g. a radio scale rendered as a slider inline). Returns an
     * array shaped like:
     *   [ 'as' => 'slider'|'radio'|'bipolar'|'mixed',
     *     'min','max','step','default','lo','hi','precision' ]
     * If no inline override is registered, returns null - callers should
     * fall back to the formal spec and model.
     */
    public static function inline( string $key ): ?array {
        $spec = self::ASSESSMENTS[ $key ] ?? null;
        if ( !$spec ) {
            return null;
        }
        return $spec['inline'] ?? null;
    }

    /**
     * Keys sorted by display label (case-insensitive) - the order the
     * owner's scale-picker should present them in.
     */
    public static function keysByLabel(): array {
        $keys = self::keys();
        usort( $keys, static function ( $a, $b ) {
            return strcasecmp( self::label( $a ), self::label( $b ) );
        } );
        return $keys;
    }
}
