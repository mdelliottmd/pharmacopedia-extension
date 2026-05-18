ALTER TABLE /*_*/pcp_votable_elements
    ADD COLUMN ve_results_policy VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'live';
