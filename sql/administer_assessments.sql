-- pcp_administer_assessments: the scale(s) bundled inside one invite. One
-- invite may carry several scales, hence several rows per invite.
-- aa_payload_enc holds the completed result, sealed to the owner's X25519
-- public key (crypto_box_seal); aa_respondent_enc holds the same result
-- sealed to a key derived from the invite token, so the respondent can
-- read their own dashboard. Both are NULL until the respondent submits.
-- Part of the "Administer to others" feature.

CREATE TABLE /*_*/pcp_administer_assessments (
    aa_id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    aa_invite_id      INT UNSIGNED      NOT NULL,   -- -> pcp_administer_invites.inv_id
    aa_instrument     VARBINARY(32)     NOT NULL,   -- assessment instrument slug
    aa_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- presentation order in the invite
    aa_status         VARBINARY(16)     NOT NULL DEFAULT 'pending',  -- pending / done
    aa_payload_enc    BLOB              DEFAULT NULL,   -- sealed to the owner; NULL until taken
    aa_respondent_enc BLOB              DEFAULT NULL,   -- sealed to the invite token; NULL until taken
    aa_scheme_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,   -- crypto scheme version
    aa_completed_at   BINARY(14)        DEFAULT NULL,
    PRIMARY KEY (aa_id),
    KEY /*i*/aa_invite (aa_invite_id)
) /*$wgDBTableOptions*/;
