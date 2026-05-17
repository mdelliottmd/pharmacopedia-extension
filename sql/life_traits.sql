CREATE TABLE /*_*/pcp_life_traits (
    lt_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lt_event_id    INT UNSIGNED NOT NULL,
    lt_namespace   VARBINARY(16) NOT NULL,
    lt_key         VARBINARY(64) NOT NULL,
    lt_label       VARBINARY(128) DEFAULT NULL,
    lt_value_num   DECIMAL(10,3) NOT NULL,
    lt_min         DECIMAL(10,3) DEFAULT NULL,
    lt_max         DECIMAL(10,3) DEFAULT NULL,
    lt_estimated   TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (lt_id),
    KEY /*i*/lt_event (lt_event_id),
    KEY /*i*/lt_namespace_key (lt_namespace, lt_key)
) /*$wgDBTableOptions*/;
