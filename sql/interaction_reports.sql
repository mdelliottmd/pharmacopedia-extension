CREATE TABLE /*_*/pcp_interaction_reports (
    pir_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pir_element_id   INT UNSIGNED NOT NULL,
    pir_voter_hash   CHAR(64)     NOT NULL,
    pir_perspective  TINYINT NOT NULL DEFAULT 1,
    pir_experience   TINYINT DEFAULT NULL,
    pir_valence      TINYINT DEFAULT NULL,
    pir_note         MEDIUMBLOB DEFAULT NULL,
    pir_created      BINARY(14) NOT NULL,
    pir_updated      BINARY(14) NOT NULL,
    PRIMARY KEY (pir_id),
    UNIQUE KEY /*i*/pir_elem_hash_persp (pir_element_id, pir_voter_hash, pir_perspective),
    KEY /*i*/pir_element (pir_element_id),
    KEY /*i*/pir_perspective (pir_perspective)
) /*$wgDBTableOptions*/;
