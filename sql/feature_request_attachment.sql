CREATE TABLE /*_*/pcp_feature_request_attachment (
    fra_id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fra_request_id      BIGINT UNSIGNED NOT NULL,
    fra_uploaded_by     INT UNSIGNED NOT NULL,
    fra_uploaded_at     BINARY(14) NOT NULL,
    fra_filename        VARBINARY(255) NOT NULL,
    fra_storage_name    VARBINARY(80) NOT NULL,
    fra_mime            VARBINARY(120) NOT NULL,
    fra_size            BIGINT UNSIGNED NOT NULL,
    -- 0 = pending scan, 1 = clean, 2 = infected, 3 = scanner error
    fra_scan_status     TINYINT NOT NULL DEFAULT 0,
    fra_scan_result     VARBINARY(255) DEFAULT NULL,
    fra_deleted         TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (fra_id),
    KEY fra_request (fra_request_id, fra_uploaded_at),
    UNIQUE KEY fra_storage (fra_storage_name)
) /*$wgDBTableOptions*/;
