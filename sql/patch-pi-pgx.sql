-- Pharmacogenomics Phase 1: extend pcp_interactions to carry edge semantics
-- (relationship, intensity, evidence, mechanism, kinetics) so the same two
-- endpoints can hold multiple semantically-distinct claims (e.g. fluoxetine
-- <-> sertraline as both pd_additive AND pk_via_CYP2D6).
--
-- Unique-key extension: includes pi_relationship so multi-relationship edges
-- between the same endpoints are valid. pi_relationship is NOT NULL with a
-- default of 'unspecified' so legacy rows back-fill cleanly and the unique
-- constraint actually holds (nullable cols defeat MySQL UNIQUE semantics).

ALTER TABLE /*_*/pcp_interactions
    ADD COLUMN pi_relationship VARBINARY(32)    NOT NULL DEFAULT 'unspecified',
    ADD COLUMN pi_intensity    TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN pi_evidence     VARBINARY(16)    DEFAULT NULL,
    ADD COLUMN pi_mechanism    VARCHAR(255)     DEFAULT NULL,
    ADD COLUMN pi_kinetics     VARBINARY(32)    DEFAULT NULL,
    DROP INDEX IF EXISTS pi_pair,
    ADD UNIQUE KEY pi_pair_rel
        (pi_left_type, pi_left_slug, pi_right_type, pi_right_slug, pi_relationship),
    ADD KEY pi_rel      (pi_relationship),
    ADD KEY pi_evidence (pi_evidence);
