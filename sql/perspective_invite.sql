-- pcp_perspective_invite: an owner-issued, token-bearing invitation for
-- one person to provide one perspective on one of the owner's objects.
--
-- Part of the Perspective subsystem (see perspective_subsystem_spec.md).
-- A perspective is invite-only; this row is the only thing that permits
-- a contribution (gate 1). The invitee URL carries ONLY pvi_token, an
-- opaque random string: no identifying information about the owner is
-- ever in the URL. The invitee sees only pvi_display_name.

CREATE TABLE /*_*/pcp_perspective_invite (
    pvi_id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pvi_token            VARBINARY(64)  NOT NULL,    -- opaque random token; the URL carries only this
    pvi_owner_id         INT UNSIGNED   NOT NULL,    -- owner profile id: the inviter and consent-holder
    pvi_object_type      VARBINARY(32)  NOT NULL,    -- registered object type, e.g. 'profile'
    pvi_object_id        VARBINARY(64)  NOT NULL,    -- the specific object the owner owns
    pvi_perspective_type VARBINARY(32)  NOT NULL,    -- registered perspective type, e.g. 'amaas_or'
    pvi_display_name     VARBINARY(128) NOT NULL,    -- owner-chosen name; the ONLY identity shown to invitees
    pvi_max_uses         INT UNSIGNED   DEFAULT NULL,            -- NULL = unlimited (reusable link)
    pvi_uses             INT UNSIGNED   NOT NULL DEFAULT 0,
    pvi_status           VARBINARY(16)  NOT NULL DEFAULT 'active', -- active / revoked
    pvi_created          BINARY(14)     NOT NULL,
    pvi_created_user_id  INT UNSIGNED   NOT NULL,
    PRIMARY KEY (pvi_id),
    UNIQUE KEY /*i*/pvi_token (pvi_token),
    KEY /*i*/pvi_owner (pvi_owner_id)
) /*$wgDBTableOptions*/;
