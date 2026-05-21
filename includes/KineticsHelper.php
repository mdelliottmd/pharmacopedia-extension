<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * Kinetic-class display hints for pharmacogenomic interactions.
 *
 * Single source of truth: any code that wants to render a temporal-decay
 * annotation for a pcp_interactions.pi_kinetics value imports this class.
 * Do NOT duplicate this table elsewhere. See also
 * /var/www/mediawiki/extensions/Pharmacopedia/DESIGN_TOKENS.md for the
 * design-layer analogue (designer-claude 2026-05-19).
 *
 * Used by:
 *   - DumpPgxForRender (kinetics_hint per-edge for JSON dump)
 *   - interface-claude's <pharmaInteractions/> renderer (chip text)
 *   - any future client (a CSV export, a print PDF, an API endpoint)
 *
 * Vocabulary maps directly to the controlled-vocab set in
 * pcp_interactions.pi_kinetics (VARBINARY(32)):
 *   reversible_competitive   - interaction resolves within ~5 half-lives
 *                              of the offending med
 *   mechanism_based          - covalent / suicide inhibition; effect
 *                              persists weeks after stopping (CYP regen)
 *   irreversible_covalent    - permanent until enzyme turnover
 *   allosteric               - non-active-site; usually reversible-like
 *                              timecourse
 *   time_dependent           - accumulates with chronic dosing
 *   unknown                  - studied but unclear (NULL preferred for
 *                              truly unspecified)
 */
class KineticsHelper {

    /**
     * Kinetic class -> human-readable decay hint. Phrasing canonicalized
     * 2026-05-19 to match the renderer's chip text (was duplicated in
     * InteractionTag.php with em-dashes; em-dashes stripped per standing
     * rule, comma substituted). Each phrase reads as a complete chip line
     * with the kinetic class name + the temporal consequence.
     */
    private const HINTS = [
        'reversible_competitive' => 'reversible competitive, effect resolves over ~5 inhibitor half-lives',
        'mechanism_based'        => 'mechanism-based, interaction persists ~4-6 weeks after stopping the inhibitor',
        'irreversible_covalent'  => 'irreversible covalent, effect persists until enzyme turnover',
        'allosteric'             => 'allosteric, reversible-like timecourse',
        'time_dependent'         => 'time-dependent, effect accumulates with chronic dosing',
        'unknown'                => 'kinetics not characterised',
    ];

    /**
     * Display hint for a given kinetic class. Returns null for unknown
     * or null input so callers can branch on null = no chip.
     */
    public static function getHint( ?string $kinetics ): ?string {
        if ( $kinetics === null || $kinetics === '' ) return null;
        return self::HINTS[$kinetics] ?? null;
    }

    /**
     * Whether the kinetics class implies a long-tail decay (mechanism-based,
     * irreversible), useful for renderer chip styling (persistent vs short).
     */
    public static function isPersistent( ?string $kinetics ): bool {
        return $kinetics === 'mechanism_based'
            || $kinetics === 'irreversible_covalent';
    }

    /** Full vocab (for validators or vocab tables). */
    public static function vocab(): array {
        return array_keys( self::HINTS );
    }
}
