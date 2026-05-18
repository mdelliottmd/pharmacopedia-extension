ALTER TABLE /*_*/pcp_user_profiles
    ADD COLUMN prof_research_id CHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    ADD UNIQUE KEY /*i*/prof_research_id (prof_research_id);
