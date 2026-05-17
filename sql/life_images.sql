CREATE TABLE /*_*/pcp_life_images (
    li_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    li_event_id    INT UNSIGNED NOT NULL,
    li_file_path   VARBINARY(255) NOT NULL,
    li_orig_name   VARBINARY(255) NOT NULL,
    li_mime        VARBINARY(64) NOT NULL,
    li_size_bytes  INT UNSIGNED NOT NULL,
    li_caption     VARBINARY(500) DEFAULT NULL,
    li_uploaded    BINARY(14) NOT NULL,
    PRIMARY KEY (li_id),
    KEY /*i*/li_event (li_event_id)
) /*$wgDBTableOptions*/;
