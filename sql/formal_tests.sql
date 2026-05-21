CREATE TABLE IF NOT EXISTS /*_*/pcp_formal_tests (
    ft_id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ft_abbrev              VARBINARY(40) NOT NULL,
    ft_full_name           VARBINARY(255) NOT NULL,
    ft_category            VARBINARY(40) NOT NULL,
    ft_score_min           FLOAT NULL,
    ft_score_max           FLOAT NULL,
    ft_score_format        VARBINARY(40) NOT NULL DEFAULT 'scaled',
    ft_percentile_available TINYINT(1) NOT NULL DEFAULT 0,
    ft_notes               VARBINARY(1024) DEFAULT NULL,
    ft_sort_key            INT NOT NULL DEFAULT 0,
    ft_legacy              TINYINT(1) NOT NULL DEFAULT 0,
    ft_aka                 VARBINARY(255) DEFAULT NULL,
    PRIMARY KEY (ft_id),
    UNIQUE KEY /*i*/ft_abbrev (ft_abbrev),
    KEY /*i*/ft_category (ft_category, ft_sort_key)
) /*$wgDBTableOptions*/;
