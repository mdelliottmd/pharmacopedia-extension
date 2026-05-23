-- Add e_page_id to pcp_effects: links the canonical Effect row to its
-- wiki page in NS_EFFECT (3010). Nullable so legacy rows pre-migration
-- still load; the migration script backfills.
ALTER TABLE /*_*/pcp_effects
    ADD COLUMN e_page_id INT UNSIGNED DEFAULT NULL,
    ADD KEY /*i*/e_page_id ( e_page_id );
