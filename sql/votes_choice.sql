-- Choice/multi vote expansion to the existing binary vote system.
-- Reuses pcp_votable_elements + pcp_votes.
--
-- ve_options:   JSON array of option labels ["Yes", "No", "Maybe"] (NULL for binary)
-- ve_options_h: short hash of the options list at create time; used by API to
--               invalidate stored choices if the page editor changes options.
-- v_choices:    comma-separated 0-based indices into ve_options.
--               Single-choice: "2"
--               Multi-choice:  "0,2,4"
--               Binary: NULL (uses v_value as today)

ALTER TABLE /*_*/pcp_votable_elements
    ADD COLUMN ve_options    MEDIUMBLOB DEFAULT NULL,
    ADD COLUMN ve_options_h  CHAR(8)    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;

ALTER TABLE /*_*/pcp_votes
    ADD COLUMN v_choices   VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    ADD COLUMN v_options_h CHAR(8)     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;
