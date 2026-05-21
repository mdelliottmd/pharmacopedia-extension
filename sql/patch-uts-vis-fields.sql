-- Per-field visibility for formal-testing score entries:
-- separate privacy for raw score / percentile / pass-fail.
-- Backfills the three from the existing record-level uts_vis so
-- no field's exposure changes on upgrade.
ALTER TABLE /*_*/pcp_user_test_scores
    ADD COLUMN uts_vis_raw      TINYINT NOT NULL DEFAULT 0 AFTER uts_vis,
    ADD COLUMN uts_vis_pct      TINYINT NOT NULL DEFAULT 0 AFTER uts_vis_raw,
    ADD COLUMN uts_vis_passfail TINYINT NOT NULL DEFAULT 0 AFTER uts_vis_pct;

UPDATE /*_*/pcp_user_test_scores
    SET uts_vis_raw      = LEAST( 3, CAST( uts_vis AS UNSIGNED ) ),
        uts_vis_pct      = LEAST( 3, CAST( uts_vis AS UNSIGNED ) ),
        uts_vis_passfail = LEAST( 3, CAST( uts_vis AS UNSIGNED ) );
