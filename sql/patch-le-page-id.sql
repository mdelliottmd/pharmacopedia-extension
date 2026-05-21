-- Phase 1 of Story-as-wiki-page:
-- pcp_life_events.le_page_id is a back-pointer to the canonical wiki page
-- (in NS_STORY=1500) that holds the Story's body content. NULL for any
-- card type other than TYPE_STORY, and NULL on legacy TYPE_STORY rows that
-- predate this phase.
ALTER TABLE /*_*/pcp_life_events
    ADD COLUMN le_page_id INT NULL DEFAULT NULL,
    ADD INDEX  le_page_id_idx ( le_page_id );
