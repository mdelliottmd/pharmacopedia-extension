-- pcp_perspective: one submitted perspective. A registered user or a
-- non-user, holding a valid invite (pcp_perspective_invite), provides a
-- structured perspective on an object owned by someone else.
--
-- Part of the Perspective subsystem (see perspective_subsystem_spec.md).
-- Gate 2, publication: psp_consent is 0 (private to the owner) until the
-- owner gives express, per-perspective consent, which sets it to 1. A
-- perspective is never publicly visible while psp_consent is 0.

CREATE TABLE /*_*/pcp_perspective (
    psp_id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    psp_invite_id        INT UNSIGNED   NOT NULL,    -- FK -> pcp_perspective_invite.pvi_id
    psp_owner_id         INT UNSIGNED   NOT NULL,    -- denormalized owner / consent-holder
    psp_object_type      VARBINARY(32)  NOT NULL,
    psp_object_id        VARBINARY(64)  NOT NULL,
    psp_perspective_type VARBINARY(32)  NOT NULL,
    psp_giver_user_id    INT UNSIGNED   DEFAULT NULL, -- set if a logged-in user; NULL for non-users
    psp_giver_label      VARBINARY(128) DEFAULT NULL, -- the giver's own chosen label (e.g. relationship)
    psp_payload          BLOB           NOT NULL,     -- JSON; shape defined by the perspective type
    psp_validity         VARBINARY(16)  DEFAULT NULL, -- optional type-specific quality flag
    psp_consent          TINYINT UNSIGNED NOT NULL DEFAULT 0, -- gate 2: 0 private, 1 owner-consented public
    psp_consent_at       BINARY(14)     DEFAULT NULL,
    psp_submitted        BINARY(14)     NOT NULL,
    psp_submitter_ip     VARBINARY(48)  DEFAULT NULL, -- abuse tracing only; never displayed
    PRIMARY KEY (psp_id),
    KEY /*i*/psp_owner (psp_owner_id),
    KEY /*i*/psp_object (psp_object_type, psp_object_id),
    KEY /*i*/psp_invite (psp_invite_id)
) /*$wgDBTableOptions*/;
