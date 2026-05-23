-- Add p_page_id to pcp_problem: links the canonical Problem row to its
-- wiki page in NS_PROBLEM (3008). Nullable so legacy rows pre-migration
-- still load; the migration script backfills.
ALTER TABLE /*_*/pcp_problem
    ADD COLUMN p_page_id INT UNSIGNED DEFAULT NULL,
    ADD KEY /*i*/p_page_id ( p_page_id );
