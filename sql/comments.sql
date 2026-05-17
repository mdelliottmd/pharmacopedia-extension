CREATE TABLE /*_*/pcp_comments (
    c_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    c_element_id   INT UNSIGNED NOT NULL,
    c_voter_hash   CHAR(64)     NOT NULL,
    c_display_name VARBINARY(255) DEFAULT NULL,
    c_parent_id    INT UNSIGNED DEFAULT NULL,
    c_text         MEDIUMBLOB   NOT NULL,
    c_timestamp    BINARY(14)   NOT NULL,
    c_edited       BINARY(14)   DEFAULT NULL,
    c_deleted      TINYINT      NOT NULL DEFAULT 0,
    PRIMARY KEY (c_id),
    KEY c_element (c_element_id),
    KEY c_parent  (c_parent_id),
    KEY c_voter_hash (c_voter_hash)
) /*$wgDBTableOptions*/;
