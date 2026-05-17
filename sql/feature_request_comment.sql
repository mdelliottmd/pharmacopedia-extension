CREATE TABLE /*_*/pcp_feature_request_comment (
    frc_id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    frc_request_id      BIGINT UNSIGNED NOT NULL,
    frc_user_id         INT UNSIGNED NOT NULL,
    frc_created         BINARY(14) NOT NULL,
    frc_updated         BINARY(14) NOT NULL,
    frc_body            MEDIUMBLOB NOT NULL,
    -- Was this comment posted as a sysop (badge displayed)? 0 = no, 1 = yes.
    frc_is_sysop        TINYINT NOT NULL DEFAULT 0,
    frc_deleted         TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (frc_id),
    KEY frc_request (frc_request_id, frc_created)
) /*$wgDBTableOptions*/;
