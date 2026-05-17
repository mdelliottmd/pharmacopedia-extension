CREATE TABLE /*_*/pcp_interactions (
    pi_id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pi_element_id      INT UNSIGNED NOT NULL,
    pi_left_type       VARBINARY(16)  NOT NULL,
    pi_left_slug       VARBINARY(255) NOT NULL,
    pi_right_type      VARBINARY(16)  NOT NULL,
    pi_right_slug      VARBINARY(255) NOT NULL,
    pi_created_user_id INT UNSIGNED NOT NULL,
    pi_created         BINARY(14) NOT NULL,
    PRIMARY KEY (pi_id),
    UNIQUE KEY /*i*/pi_pair (pi_left_type, pi_left_slug, pi_right_type, pi_right_slug),
    KEY /*i*/pi_left  (pi_left_type, pi_left_slug),
    KEY /*i*/pi_right (pi_right_type, pi_right_slug),
    KEY /*i*/pi_element (pi_element_id)
) /*$wgDBTableOptions*/;
