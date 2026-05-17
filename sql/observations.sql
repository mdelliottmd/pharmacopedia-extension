-- Observation expansion to the life-story timeline.
-- Adds:
--   - le_polarity TINYINT NULL DEFAULT NULL on pcp_life_events
--     (NULL=unspecified, 1=positive 'did experience', 0=negative 'did NOT experience')
--   - le_raw_text MEDIUMBLOB on pcp_life_events
--     (the original plain-text input the user typed, before parsing)
--   - pcp_life_event_refs join table for many-to-many linkage of an event
--     to medications / effects / problems / diagnoses / free-text nouns
--
-- New event type by convention: le_type = 3 = TYPE_OBSERVATION.
-- (Existing types: 0=story, 1=image-primary, 2=keyframe.)

ALTER TABLE /*_*/pcp_life_events
    ADD COLUMN le_polarity TINYINT DEFAULT NULL,
    ADD COLUMN le_raw_text MEDIUMBLOB DEFAULT NULL;

CREATE TABLE /*_*/pcp_life_event_refs (
    ler_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ler_event_id   INT UNSIGNED NOT NULL,
    ler_ref_type   VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    ler_ref_id     INT UNSIGNED DEFAULT NULL,
    ler_ref_text   VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    ler_role       VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    ler_created    BINARY(14)   NOT NULL,
    PRIMARY KEY (ler_id),
    KEY /*i*/ler_event   (ler_event_id),
    KEY /*i*/ler_lookup  (ler_ref_type, ler_ref_id)
) /*$wgDBTableOptions*/;
