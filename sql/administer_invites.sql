-- pcp_administer_invites: one owner-issued, token-bearing link = one send.
-- inv_token_hash is the SHA-256 of the token; the raw token lives only in
-- the link, never in the database, so a stolen database yields no usable
-- tokens. The token is the respondent's only authentication.
-- Part of the "Administer to others" feature (Phase 1 schema).

CREATE TABLE /*_*/pcp_administer_invites (
    inv_id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    inv_respondent_id INT UNSIGNED  NOT NULL,    -- -> pcp_administer_respondents.r_id
    inv_owner_user_id INT UNSIGNED  NOT NULL,    -- denormalized owner
    inv_token_hash    BINARY(32)    NOT NULL,    -- SHA-256 of the raw token
    inv_status        VARBINARY(16) NOT NULL DEFAULT 'pending',  -- pending / completed / expired
    inv_created       BINARY(14)    NOT NULL,
    inv_expires       BINARY(14)    NOT NULL,    -- inv_created + 30 days
    inv_completed_at  BINARY(14)    DEFAULT NULL,
    PRIMARY KEY (inv_id),
    UNIQUE KEY /*i*/inv_token_hash (inv_token_hash),
    KEY /*i*/inv_respondent (inv_respondent_id),
    KEY /*i*/inv_owner (inv_owner_user_id)
) /*$wgDBTableOptions*/;
