<?php
namespace MediaWiki\Extension\Pharmacopedia;

/**
 * A perspective-type handler. Each kind of perspective (amaas_or, and
 * future types) implements this interface. The generic Perspective
 * subsystem stays generic by delegating every type-specific decision
 * here: the input form, how a submission parses, the quality flag, and
 * the owner-facing summary.
 *
 * See perspective_subsystem_spec.md section 3.
 */
interface PerspectiveTypeHandler {

    /** The registered perspective-type key, e.g. 'amaas_or'. */
    public function typeKey(): string;

    /** Human-readable label for the type. */
    public function label(): string;

    /**
     * The input-form HTML shown to an invitee. $invite carries the
     * owner-chosen display name (pvi_display_name) and nothing else
     * identifying; the form must show only that plus the questions.
     *
     * @param \stdClass $invite a pcp_perspective_invite row
     */
    public function renderForm( \stdClass $invite ): string;

    /**
     * Parse a submitted form into a JSON-able payload array, stored as
     * psp_payload.
     *
     * @param \MediaWiki\Request\WebRequest $request
     */
    public function parseSubmission( $request ): array;

    /**
     * A short type-specific quality-check status code (at most 16 bytes;
     * stored verbatim in psp_validity), or null if the type has no
     * validity concept. The consent inbox maps the code to a display
     * treatment, so keep distinct facts (passed / flagged / not
     * assessed) as distinct codes rather than collapsing them.
     */
    public function validity( array $payload ): ?string;

    /**
     * Owner-facing display summary, given the WHOLE pcp_perspective row
     * (not just the payload), so a type that needs comparative context
     * can do its own lookups. Returns HTML.
     *
     * @param \stdClass $perspective a pcp_perspective row
     */
    public function summarize( \stdClass $perspective ): string;
}
