-- pcp_likert_reports.pl_value: make it nullable.
--
-- A NULL pl_value is the "Don't know" abstention. It replaces the old -1
-- sentinel, so a rating is now a plain 0-100 integer with nothing else
-- crowded into the column. See includes/LikertStore.php.

ALTER TABLE /*_*/pcp_likert_reports
    MODIFY pl_value TINYINT DEFAULT NULL;

-- Migrate the existing -1 "Don't know" rows to NULL.
UPDATE /*_*/pcp_likert_reports SET pl_value = NULL WHERE pl_value = -1;
