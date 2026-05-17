CREATE TABLE /*_*/pcp_feature_request (
    fr_id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fr_user_id         INT UNSIGNED NOT NULL,
    fr_created         BINARY(14) NOT NULL,
    fr_updated         BINARY(14) NOT NULL,
    fr_title           VARBINARY(200) NOT NULL,
    fr_body            MEDIUMBLOB NOT NULL,
    -- Username privacy (mirrors UserProfileStore visibility constants):
    --   0 = Private (not used here; feature requests are never fully private)
    --   1 = VIS_PUBLIC_DEFAULT (use profile's prof_show_default)
    --   2 = VIS_PUBLIC_USERNAME (force username)
    --   3 = VIS_PUBLIC_ANONYMOUS (force anonymous)
    fr_username_vis    TINYINT NOT NULL DEFAULT 1,
    -- Content privacy:
    --   0 = public to all logged-in users
    --   1 = sysop-only body (title still visible)
    fr_content_vis     TINYINT NOT NULL DEFAULT 0,
    fr_status          VARBINARY(20) NOT NULL DEFAULT 'new',
    fr_priority        TINYINT NOT NULL DEFAULT 0,
    fr_sysop_notes     MEDIUMBLOB DEFAULT NULL,
    fr_resolved_at     BINARY(14) DEFAULT NULL,
    fr_resolved_by     INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (fr_id),
    KEY fr_user (fr_user_id, fr_created),
    KEY fr_queue (fr_status, fr_priority, fr_created),
    KEY fr_recent (fr_created)
) /*$wgDBTableOptions*/;
