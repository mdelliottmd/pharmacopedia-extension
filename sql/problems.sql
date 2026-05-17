CREATE TABLE /*_*/pcp_problem (
    p_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    p_slug        VARBINARY(255) NOT NULL,
    p_name        VARBINARY(255) NOT NULL,
    p_description MEDIUMBLOB DEFAULT NULL,
    p_category    VARBINARY(64) DEFAULT NULL,
    p_created_by  INT UNSIGNED NOT NULL,
    p_created     BINARY(14) NOT NULL,
    p_updated     BINARY(14) NOT NULL,
    p_retired     TINYINT NOT NULL DEFAULT 0,
    p_merged_into INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (p_id),
    UNIQUE KEY /*i*/p_slug (p_slug),
    KEY /*i*/p_retired (p_retired),
    KEY /*i*/p_name (p_name),
    KEY /*i*/p_category (p_category)
) /*$wgDBTableOptions*/;
