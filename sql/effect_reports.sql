CREATE TABLE /*_*/pcp_effect_reports (
    er_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    er_element_id    INT UNSIGNED NOT NULL,
    er_voter_hash    CHAR(64)     NOT NULL,
    er_perspective   TINYINT NOT NULL DEFAULT 1,
    er_experienced   TINYINT DEFAULT NULL,
    er_frequency_pct TINYINT DEFAULT NULL,
    er_valence       TINYINT DEFAULT NULL,
    er_timestamp     BINARY(14) NOT NULL,
    PRIMARY KEY (er_id),
    UNIQUE KEY /*i*/er_element_hash_persp (er_element_id, er_voter_hash, er_perspective),
    KEY /*i*/er_element (er_element_id),
    KEY /*i*/er_perspective (er_perspective)
) /*$wgDBTableOptions*/;
