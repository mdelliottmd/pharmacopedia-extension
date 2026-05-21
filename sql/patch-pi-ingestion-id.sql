-- Phase 1 PGx provenance linkage: each pcp_interactions row carries an
-- optional FK back to the pcp_ingestion_log row that created it. Set by
-- ingest scripts on INSERT only; never mutated on UPDATE (immutable
-- creator semantics). Legacy rows pre-dating this column remain NULL.
--
-- Used by the renderer's row-level "ⓘ source" popover to assemble:
--   "Source: <il_source>; ingest run #<il_id> on <il_timestamp>"
-- without parsing any prose. Inference-chain prose for derived edges
-- stays in pi_mechanism (template stability gate).

ALTER TABLE /*_*/pcp_interactions
    ADD COLUMN pi_ingestion_id INT UNSIGNED DEFAULT NULL,
    ADD KEY /*i*/pi_ingestion (pi_ingestion_id);
