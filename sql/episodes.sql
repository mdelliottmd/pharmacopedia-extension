-- Episode expansion to the life-story timeline.
-- New event type: le_type = 4 = TYPE_EPISODE (by convention).
-- Episodes are time-bounded periods (date-struct in PCPDatePicker range mode).
--
-- Episode-specific metadata columns:
--   le_episode_type    - taxonomy bucket (mood, psychotic, anxiety, etc.)
--   le_episode_subtype - sub-bucket (depressive, manic, hypomanic, mixed, ...)
--   le_severity        - 0..100 per precision doctrine; null = unspecified
--
-- Symptoms, triggers, and treatments reuse pcp_life_event_refs with roles:
--   'symptom', 'trigger', 'treatment'
--
-- Images reuse pcp_life_images (now gated by ClamAV scan; see VirusScanner.php).

ALTER TABLE /*_*/pcp_life_events
    ADD COLUMN le_episode_type    VARCHAR(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    ADD COLUMN le_episode_subtype VARCHAR(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    ADD COLUMN le_severity        DECIMAL(5,2) DEFAULT NULL;
