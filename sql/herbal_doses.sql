-- Phase 4 herbal medicines: dose ranges, keyed per preparation.
-- The range IS the data; a scalar dose is a degenerate range
-- (dose_low = dose_high). Both bounds NOT NULL so downstream never
-- NULL-handles. Multiple dose rows per preparation are legitimate
-- (titration phase + maintenance, or pediatric vs adult).
--
-- hd_source_pmid: per the citation trust gate, any PMID landing here has
-- been home-claude-verified via NCBI eutils. Web-claude supplies the
-- citation tuple as a candidate; home-claude verifies; only then ingest
-- writes. Parser-claude does not accept raw PMIDs.

CREATE TABLE /*_*/pcp_herbal_doses (
    hd_id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    hd_preparation_id  INT UNSIGNED NOT NULL,            -- FK -> pcp_preparation
    hd_dose_low        DECIMAL(12,4) NOT NULL,
    hd_dose_high       DECIMAL(12,4) NOT NULL,           -- = dose_low for single-value
    hd_unit            VARBINARY(16) NOT NULL,           -- g, mg, mL, drops, capsules
    hd_frequency       VARBINARY(32)  DEFAULT NULL,      -- "3x daily", "TID with meals"
    hd_route           VARBINARY(16)  DEFAULT NULL,      -- oral, topical, inhaled, sublingual
    hd_titration_note  VARBINARY(255) DEFAULT NULL,
    hd_duration_note   VARBINARY(255) DEFAULT NULL,
    hd_population      VARBINARY(32)  DEFAULT NULL,      -- adult, pediatric, elderly
    hd_evidence        VARBINARY(16)  DEFAULT NULL,      -- shared evidence vocab
    hd_source_pmid     VARBINARY(16)  DEFAULT NULL,      -- home-claude-verified only
    hd_ingestion_id    INT UNSIGNED   DEFAULT NULL,      -- FK -> pcp_ingestion_log
    hd_created_user_id INT UNSIGNED NOT NULL,
    hd_created         BINARY(14) NOT NULL,
    PRIMARY KEY (hd_id),
    KEY /*i*/hd_preparation (hd_preparation_id)
) /*$wgDBTableOptions*/;
