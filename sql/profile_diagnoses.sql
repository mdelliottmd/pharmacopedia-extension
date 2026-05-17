CREATE TABLE /*_*/pcp_profile_diagnoses (
    pd_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pd_profile_id  INT UNSIGNED NOT NULL,
    pd_system      VARBINARY(32) NOT NULL,
    pd_code        VARBINARY(64) DEFAULT NULL,
    pd_description VARBINARY(255) NOT NULL,
    pd_status      TINYINT DEFAULT NULL,
    pd_origin      TINYINT DEFAULT NULL,
    pd_severity    TINYINT DEFAULT NULL,
    pd_year_first  SMALLINT DEFAULT NULL,
    pd_notes       MEDIUMBLOB DEFAULT NULL,
    pd_visibility  TINYINT NOT NULL DEFAULT 0,
    pd_added       BINARY(14) NOT NULL,
    PRIMARY KEY (pd_id),
    KEY /*i*/pd_profile (pd_profile_id),
    KEY /*i*/pd_system (pd_system),
    KEY /*i*/pd_visibility (pd_visibility)
) /*$wgDBTableOptions*/;
