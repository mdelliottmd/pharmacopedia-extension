-- Diplotype -> phenotype lookup, from CPIC's authoritative /diplotype
-- endpoint (the generesult field). Resolver v2 uses this table instead of
-- reimplementing CPIC's per-gene phenotype-assignment rules: CPIC already
-- computed the phenotype for every diplotype, so a lookup is correct by
-- construction (handles function-combination genes like CYP2C19 / TPMT /
-- NUDT15 that an activity-score sum cannot).
--
-- Scope: ~17,775 diplotypes across the dosing-relevant genes. Excludes
--   CYP2D6  - handled by the activity-score compute path in PhenotypeResolver
--   RYR1    - 58k diplotypes, malignant-hyperthermia susceptibility model
--   G6PD    - 17.8k diplotypes, X-linked activity-deficiency model
-- Those three are deliberately out of this table.
--
-- pd_diplotype_key is the canonical lookup form: the two alleles
-- normalized + numeric-sorted + joined, produced by
-- PhenotypeResolver::diplotypeKey() so ingest and lookup agree regardless
-- of which allele order the caller supplies.

CREATE TABLE /*_*/pcp_pgx_diplotype (
    pd_id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pd_gene            VARBINARY(32)  NOT NULL,
    pd_diplotype       VARBINARY(128) NOT NULL,   -- CPIC verbatim, e.g. "*1/*2"
    pd_diplotype_key   VARBINARY(128) NOT NULL,   -- canonical sorted lookup key
    pd_phenotype       VARBINARY(80)  DEFAULT NULL,  -- CPIC generesult verbatim
    pd_phenotype_slug  VARBINARY(48)  DEFAULT NULL,  -- wiki slug; NULL for Indeterminate
    pd_activity_score  VARBINARY(16)  DEFAULT NULL,  -- totalactivityscore
    pd_ehr_priority    VARBINARY(64)  DEFAULT NULL,
    pd_ingestion_id    INT UNSIGNED   DEFAULT NULL,  -- FK -> pcp_ingestion_log
    pd_created_user_id INT UNSIGNED NOT NULL,
    pd_created         BINARY(14) NOT NULL,
    PRIMARY KEY (pd_id),
    UNIQUE KEY /*i*/pd_uniq (pd_gene, pd_diplotype_key),
    KEY /*i*/pd_gene (pd_gene)
) /*$wgDBTableOptions*/;
