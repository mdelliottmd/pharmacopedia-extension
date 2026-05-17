CREATE TABLE /*_*/pcp_profile_fields (
    pf_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pf_profile_id  INT UNSIGNED NOT NULL,
    pf_namespace   VARBINARY(64) NOT NULL,
    pf_key         VARBINARY(64) NOT NULL,
    pf_value_text  MEDIUMBLOB DEFAULT NULL,
    pf_value_num   DECIMAL(10,3) DEFAULT NULL,
    pf_visibility  TINYINT NOT NULL DEFAULT 0,
    pf_updated     BINARY(14) NOT NULL,
    PRIMARY KEY (pf_id),
    UNIQUE KEY /*i*/pf_profile_ns_key (pf_profile_id, pf_namespace, pf_key),
    KEY /*i*/pf_namespace (pf_namespace),
    KEY /*i*/pf_visibility (pf_visibility)
) /*$wgDBTableOptions*/;
