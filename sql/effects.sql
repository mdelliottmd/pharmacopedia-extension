CREATE TABLE /*_*/pcp_effects (
    e_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    e_slug         VARBINARY(255) NOT NULL,
    e_name         VARBINARY(255) NOT NULL,
    e_description  MEDIUMBLOB DEFAULT NULL,
    e_aliases      MEDIUMBLOB DEFAULT NULL,
    e_created_by   INT UNSIGNED NOT NULL,
    e_created      BINARY(14) NOT NULL,
    e_updated      BINARY(14) NOT NULL,
    e_retired      TINYINT NOT NULL DEFAULT 0,
    e_merged_into  INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (e_id),
    UNIQUE KEY /*i*/e_slug (e_slug),
    KEY /*i*/e_retired (e_retired),
    KEY /*i*/e_name (e_name)
) /*$wgDBTableOptions*/;
