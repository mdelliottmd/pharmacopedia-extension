CREATE TABLE /*_*/pcp_votes (
    v_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    v_element_id   INT UNSIGNED NOT NULL,
    v_voter_hash   CHAR(64)     NOT NULL,
    v_value        TINYINT      NOT NULL,
    v_timestamp    BINARY(14)   NOT NULL,
    PRIMARY KEY (v_id),
    UNIQUE KEY v_element_voter (v_element_id, v_voter_hash),
    KEY v_element (v_element_id)
) /*$wgDBTableOptions*/;
