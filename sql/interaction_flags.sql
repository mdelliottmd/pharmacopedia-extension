-- pcp_interaction_flags: granular per-voter flags on a PGx interaction
-- edge (the granular interaction-voting feature; spec by interface-claude
-- 2026-05-20). One row per (element, voter, dimension).
--
-- pif_element_id is the element-system id (pcp_interactions.pi_element_id,
-- the same id pcp_interaction_reports.pir_element_id uses).
-- pif_voter_hash reuses InteractionStore::voterHash() (sha256 HMAC hex,
-- 64 chars), matching pcp_interaction_reports.pir_voter_hash.
-- pif_type is one of: clinical_relevance, derived_confidence,
-- mechanism_flag, kinetics_flag, noise. pif_value range is per type.
-- pif_note (mechanism_flag / kinetics_flag) carries optional free text.

CREATE TABLE /*_*/pcp_interaction_flags (
    pif_id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    pif_element_id   INT UNSIGNED  NOT NULL,
    pif_voter_hash   CHAR(64)      NOT NULL,
    pif_type         VARBINARY(32) NOT NULL,
    pif_value        TINYINT       NOT NULL,
    pif_note         MEDIUMBLOB    DEFAULT NULL,
    pif_created      BINARY(14)    NOT NULL,
    pif_updated      BINARY(14)    NOT NULL,
    PRIMARY KEY (pif_id),
    UNIQUE KEY /*i*/pif_elem_hash_type (pif_element_id, pif_voter_hash, pif_type),
    KEY        /*i*/pif_elem_type      (pif_element_id, pif_type)
) /*$wgDBTableOptions*/;
