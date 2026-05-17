CREATE TABLE /*_*/pcp_votable_elements (
    ve_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ve_page_id   INT UNSIGNED NOT NULL,
    ve_slug      VARBINARY(255) NOT NULL,
    ve_type      VARBINARY(32) NOT NULL DEFAULT 'binary',
    ve_label     VARBINARY(2048) DEFAULT NULL,
    ve_upvotes   INT UNSIGNED NOT NULL DEFAULT 0,
    ve_downvotes INT UNSIGNED NOT NULL DEFAULT 0,
    ve_created   BINARY(14) NOT NULL,
    PRIMARY KEY (ve_id),
    UNIQUE KEY ve_page_slug (ve_page_id, ve_slug),
    KEY ve_page (ve_page_id)
) /*$wgDBTableOptions*/;
