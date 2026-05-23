CREATE TABLE /*_*/pcp_likert_reports (
    pl_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pl_element_id INT UNSIGNED NOT NULL,
    pl_voter_hash CHAR(64)     NOT NULL,
    pl_value      DECIMAL(3,2) DEFAULT NULL,
    pl_timestamp  BINARY(14) NOT NULL,
    PRIMARY KEY (pl_id),
    UNIQUE KEY /*i*/pl_element_hash (pl_element_id, pl_voter_hash),
    KEY /*i*/pl_element (pl_element_id)
) /*$wgDBTableOptions*/;
