-- pcp_administer_respondents: a person an owner sends assessment scales to.
-- The respondent has no account. r_name_enc is the owner's own label for
-- the person, sealed to the owner's X25519 public key (crypto_box_seal), so
-- the contact roster is readable only by the owner.
-- Part of the "Administer to others" feature (Phase 1 schema).

CREATE TABLE /*_*/pcp_administer_respondents (
    r_id            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    r_owner_user_id INT UNSIGNED   NOT NULL,    -- owner: a registered user
    r_name_enc      BLOB           NOT NULL,    -- owner's label, sealed to the owner's public key
    r_created       BINARY(14)     NOT NULL,
    r_updated       BINARY(14)     NOT NULL,
    PRIMARY KEY (r_id),
    KEY /*i*/r_owner (r_owner_user_id)
) /*$wgDBTableOptions*/;
