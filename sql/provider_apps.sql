CREATE TABLE /*_*/pcp_provider_apps (
    pa_id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pa_user_id        INT UNSIGNED NOT NULL,
    pa_status         TINYINT NOT NULL DEFAULT 0,
    pa_profession     VARBINARY(100) DEFAULT NULL,
    pa_specialty      VARBINARY(200) DEFAULT NULL,
    pa_jurisdiction   VARBINARY(200) DEFAULT NULL,
    pa_license_number VARBINARY(200) DEFAULT NULL,
    pa_real_name      VARBINARY(200) DEFAULT NULL,
    pa_notes          BLOB DEFAULT NULL,
    pa_admin_notes    BLOB DEFAULT NULL,
    pa_reviewed_by    INT UNSIGNED DEFAULT NULL,
    pa_doc_paths      BLOB DEFAULT NULL,
    pa_submitted      BINARY(14) NOT NULL,
    pa_reviewed       BINARY(14) DEFAULT NULL,
    PRIMARY KEY (pa_id),
    KEY /*i*/pa_user (pa_user_id),
    KEY /*i*/pa_status (pa_status)
) /*$wgDBTableOptions*/;
