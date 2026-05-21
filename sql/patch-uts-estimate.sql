ALTER TABLE /*_*/pcp_user_test_scores
    ADD COLUMN uts_raw_is_estimate TINYINT(1) NOT NULL DEFAULT 0 AFTER uts_raw_score,
    ADD COLUMN uts_pct_is_estimate TINYINT(1) NOT NULL DEFAULT 0 AFTER uts_percentile;
