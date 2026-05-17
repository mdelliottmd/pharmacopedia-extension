CREATE TABLE /*_*/pcp_problem_alias (
    pa_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pa_problem_id INT UNSIGNED NOT NULL,
    pa_alias      VARBINARY(255) NOT NULL,
    PRIMARY KEY (pa_id),
    KEY /*i*/pa_problem (pa_problem_id),
    KEY /*i*/pa_alias (pa_alias)
) /*$wgDBTableOptions*/;
