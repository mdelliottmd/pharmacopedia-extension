CREATE TABLE /*_*/pcp_life_events (
    le_id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    le_profile_id      INT UNSIGNED NOT NULL,
    le_date_iso        BINARY(10) DEFAULT NULL,
    le_date_precision  TINYINT NOT NULL DEFAULT 0,
    le_date_display    VARBINARY(64) DEFAULT NULL,
    le_type            TINYINT NOT NULL DEFAULT 0,
    le_title           VARBINARY(255) NOT NULL,
    le_body            MEDIUMBLOB DEFAULT NULL,
    le_visibility      TINYINT NOT NULL DEFAULT 0,
    le_tags            VARBINARY(255) DEFAULT NULL,
    le_created         BINARY(14) NOT NULL,
    le_updated         BINARY(14) NOT NULL,
    PRIMARY KEY (le_id),
    KEY /*i*/le_profile_date (le_profile_id, le_date_iso),
    KEY /*i*/le_visibility (le_visibility)
) /*$wgDBTableOptions*/;
