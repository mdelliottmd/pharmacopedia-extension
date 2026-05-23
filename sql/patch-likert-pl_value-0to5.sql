-- pcp_likert_reports.pl_value: rescale the medicine-problem efficacy rating
-- from a 0-100 scale to a single 0-5 scale (Mark consolidated display and
-- storage onto 0-5).
--
-- The column is first widened to DECIMAL(5,2) so the existing 0-100 values
-- still fit, every stored rating is multiplied by 0.05 (84 becomes 4.20),
-- then the column is narrowed to DECIMAL(3,2), the natural width for
-- 0.00 to 5.00. A NULL pl_value stays the "Don't know" abstention, untouched.

ALTER TABLE /*_*/pcp_likert_reports
    MODIFY pl_value DECIMAL(5,2) DEFAULT NULL;

UPDATE /*_*/pcp_likert_reports
    SET pl_value = pl_value * 0.05
    WHERE pl_value IS NOT NULL;

ALTER TABLE /*_*/pcp_likert_reports
    MODIFY pl_value DECIMAL(3,2) DEFAULT NULL;
