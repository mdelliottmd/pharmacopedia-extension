-- Phase 4 herbal medicines: indication-of-use records.
-- Two-row pattern: each row is one (medicine, indication, basis) triple
-- with its own evidence trail. A medicine used for the same indication on
-- both a traditional and an evidence-based footing gets TWO rows -- each
-- chip in the UI then carries complete provenance. No "both" enum.
--
-- hu_basis controlled vocab: traditional | evidence_based | mechanistic.
--   Traditional and evidence-based carry equal display weight, distinct
--   visual texture (designer-claude): different epistemology, not lesser.
--
-- hu_evidence_strength: single column, mixed vocab gated by hu_basis.
--   basis=evidence_based -> clinical strength vocab (cpic_strong, fda_label,
--                           dpwg, primary, ...)
--   basis=mechanistic    -> in_vitro | animal | human_cell_line | case_series
--   basis=traditional    -> NULL; strength is carried by hu_traditional_context
--                           prose ("widely attested across cultures" vs
--                           "single regional source").
--
-- hu_indication_id references pcp_problem (the existing problems table);
-- hu_indication_text is the free-text fallback when no problem row fits
-- (precision doctrine: free-text fallback).

CREATE TABLE /*_*/pcp_herbal_use (
    hu_id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    hu_medicine_slug       VARBINARY(255) NOT NULL,
    hu_indication_id       INT UNSIGNED   DEFAULT NULL,   -- FK -> pcp_problem
    hu_indication_text     VARBINARY(255) DEFAULT NULL,   -- free-text fallback
    hu_basis               VARBINARY(16)  NOT NULL,       -- traditional|evidence_based|mechanistic
    hu_evidence_strength   VARBINARY(24)  DEFAULT NULL,   -- mixed vocab, gated by hu_basis
    hu_traditional_context VARBINARY(255) DEFAULT NULL,
    hu_pmid_list           BLOB DEFAULT NULL,             -- home-claude-verified PMIDs
    hu_notes               BLOB DEFAULT NULL,
    hu_ingestion_id        INT UNSIGNED   DEFAULT NULL,   -- FK -> pcp_ingestion_log
    hu_created_user_id     INT UNSIGNED NOT NULL,
    hu_created             BINARY(14) NOT NULL,
    PRIMARY KEY (hu_id),
    UNIQUE KEY /*i*/hu_uniq (hu_medicine_slug, hu_indication_id, hu_basis),
    KEY /*i*/hu_medicine (hu_medicine_slug)
) /*$wgDBTableOptions*/;
