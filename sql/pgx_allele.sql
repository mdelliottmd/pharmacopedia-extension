-- Genotype-anchor layer: the CPIC star-allele catalog.
-- Each row is one named allele of one pharmacogene, with its clinical
-- function, activity value, evidence strength, and per-population
-- frequency. This is the reference layer that connects a real
-- pharmacogenetic test result (e.g. "CYP2D6 *1/*4") to the wiki's
-- phenotype system: look up each allele's activity value, sum, map the
-- total to a phenotype band.
--
-- Source: api.cpicpgx.org/v1/allele (~1349 alleles across 22 genes).
-- Reference table, not an interaction edge -- lives outside pcp_interactions.

CREATE TABLE /*_*/pcp_pgx_allele (
    pa_id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pa_gene            VARBINARY(32)  NOT NULL,        -- gene symbol, uppercase (CYP2D6)
    pa_allele          VARBINARY(64)  NOT NULL,        -- allele name (*4, *1x>=3, rsNNN variant)
    pa_function        VARBINARY(64)  DEFAULT NULL,    -- clinical functional status
    pa_activity_value  VARBINARY(16)  DEFAULT NULL,    -- string: "0.25", ">=3.0", "n/a"
    pa_strength        VARBINARY(16)  DEFAULT NULL,    -- evidence strength for the function call
    pa_cpic_allele_id  INT UNSIGNED   DEFAULT NULL,    -- CPIC /allele.id, for traceability
    pa_frequency       BLOB           DEFAULT NULL,    -- JSON: per-population frequency map
    pa_findings        BLOB           DEFAULT NULL,    -- function-assignment rationale prose
    pa_ingestion_id    INT UNSIGNED   DEFAULT NULL,    -- FK -> pcp_ingestion_log
    pa_created_user_id INT UNSIGNED NOT NULL,
    pa_created         BINARY(14) NOT NULL,
    PRIMARY KEY (pa_id),
    UNIQUE KEY /*i*/pa_uniq (pa_gene, pa_allele),
    KEY /*i*/pa_gene (pa_gene)
) /*$wgDBTableOptions*/;
