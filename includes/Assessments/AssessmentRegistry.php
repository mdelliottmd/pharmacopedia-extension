<?php
namespace MediaWiki\Extension\Pharmacopedia\Assessments;

/**
 * AssessmentRegistry - the single source of truth for every assessment that
 * can be administered to outside respondents.
 *
 * Special:AdministerAssessments (the owner hub) and Special:RespondToAssessment
 * (the respondent take-flow) both read this registry, so a new assessment
 * registered here ONCE autopopulates the scale-picker, the take-flow, and the
 * dashboard. Nothing else needs touching.
 *
 * The 'model' tells the take-flow how to render the instrument:
 *   radio    discrete RESPONSE_LABELS radio buttons
 *   slider   one continuous slider per item, uniform lo/hi anchor text
 *   bipolar  one slider per item, anchors taken from the item itself
 *            (the two opposing phrases of an MBTI forced-choice pair)
 *
 * slider/bipolar entries carry min/max/step; slider entries also carry the
 * lo/hi anchor text shown at the ends of every slider.
 *
 * @license GPL-3.0-or-later
 */
class AssessmentRegistry {

    /** key => spec. Alphabetical by key; the picker sorts by display label. */
    private const ASSESSMENTS = [
        'amaas'      => [ 'class' => Amaas::class,      'model' => 'slider',
            'min' => 0, 'max' => 100, 'step' => 1,
            'lo' => '0% of the time', 'hi' => '100% of the time' ],
        'asrs'       => [ 'class' => Asrs::class,       'model' => 'radio' ],
        'bpns'       => [ 'class' => Bpns::class,       'model' => 'radio' ],
        'cati'       => [ 'class' => Cati::class,       'model' => 'radio' ],
        'catq'       => [ 'class' => Catq::class,       'model' => 'radio' ],
        'enneagram'  => [ 'class' => Enneagram::class,  'model' => 'slider',
            'min' => 1, 'max' => 5, 'step' => 0.01,
            'lo' => 'Strongly disagree', 'hi' => 'Strongly agree' ],
        'hyd'        => [ 'class' => Hyd::class,        'model' => 'slider',
            'min' => -100, 'max' => 100, 'step' => 1,
            'lo' => 'Really poorly', 'hi' => 'Really well' ],
        'mbti'       => [ 'class' => Mbti::class,       'model' => 'bipolar',
            'min' => 1, 'max' => 5, 'step' => 0.01 ],
        'nfcs'       => [ 'class' => Nfcs::class,       'model' => 'radio' ],
        'ocean'      => [ 'class' => Ocean::class,      'model' => 'slider',
            'min' => 0, 'max' => 100, 'step' => 1,
            'lo' => 'Disagree', 'hi' => 'Agree' ],
        'ocipcp'     => [ 'class' => Ocipcp::class,     'model' => 'radio' ],
        'pid5bf'     => [ 'class' => Pid5bf::class,     'model' => 'radio' ],
        'whoqolbref' => [ 'class' => WhoqolBref::class, 'model' => 'radio' ],
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

    /** Response model for $key: 'radio' | 'slider' | 'bipolar'. Defaults to radio. */
    public static function model( string $key ): string {
        return self::ASSESSMENTS[ $key ]['model'] ?? 'radio';
    }

    /** Display label for $key, read from the scorer's NAME constant. */
    public static function label( string $key ): string {
        $class = self::ASSESSMENTS[ $key ]['class'] ?? null;
        return $class ? $class::NAME : strtoupper( $key );
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
