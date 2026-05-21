-- Phase 4 herbal medicines: preparation records.
-- A plant medicine carries one-or-more preparation records. Each
-- preparation is a pharmacologically distinct deliverable (decoction vs
-- tincture vs standardized extract: different dose ranges, onsets,
-- sometimes different active constituents). Preparation is a top-level
-- facet, not a cosmetic variation -- precision doctrine, multi where reality
-- is multi.
--
-- prep_form is a controlled vocabulary enforced at the app layer (a future
-- herbal validator), NOT a SQL ENUM, so the term list can be revised
-- without an ALTER. Documented vocab (2026-05-20):
--   decoction, infusion, tea, tincture, fluid_extract, standardized_extract,
--   dried_herb, powder, capsule, essential_oil, oil_infusion, syrup,
--   poultice, salve
--
-- prep_medicine_slug references the plant-medicine page by slug (same
-- convention as pcp_interactions; medicines are wiki pages, not a table).

CREATE TABLE /*_*/pcp_preparation (
    prep_id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    prep_medicine_slug        VARBINARY(255) NOT NULL,
    prep_form                 VARBINARY(32)  NOT NULL,
    prep_constituent          VARBINARY(64)  DEFAULT NULL,  -- "hyperforin-standardized 0.3%"; NULL = whole-herb
    prep_bioavailability_note VARBINARY(255) DEFAULT NULL,
    prep_ingestion_id         INT UNSIGNED   DEFAULT NULL,  -- FK -> pcp_ingestion_log
    prep_created_user_id      INT UNSIGNED NOT NULL,
    prep_created              BINARY(14) NOT NULL,
    PRIMARY KEY (prep_id),
    UNIQUE KEY /*i*/prep_uniq (prep_medicine_slug, prep_form, prep_constituent),
    KEY /*i*/prep_medicine (prep_medicine_slug)
) /*$wgDBTableOptions*/;
