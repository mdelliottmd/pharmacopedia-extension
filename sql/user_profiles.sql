CREATE TABLE /*_*/pcp_user_profiles (
    prof_id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    prof_voter_hash        BINARY(64) NOT NULL,
    prof_user_id           INT UNSIGNED NOT NULL,
    prof_public_alias      VARBINARY(255) DEFAULT NULL,
    prof_show_default      TINYINT NOT NULL DEFAULT 0,
    prof_show_xr_on_profile TINYINT NOT NULL DEFAULT 0,
    prof_created           BINARY(14) NOT NULL,
    prof_updated           BINARY(14) NOT NULL,
    PRIMARY KEY (prof_id),
    UNIQUE KEY /*i*/prof_voter_hash (prof_voter_hash),
    UNIQUE KEY /*i*/prof_user_id (prof_user_id)
) /*$wgDBTableOptions*/;
