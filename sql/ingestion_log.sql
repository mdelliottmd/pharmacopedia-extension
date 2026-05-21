-- Audit trail for any data ingestion that mutates pcp_interactions
-- (CPIC API runs, FDA Table runs, inference engine materializations).
-- One row per ingestion run; lets any single edge be traced back to its
-- source-of-record + version.

CREATE TABLE /*_*/pcp_ingestion_log (
    il_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    il_source        VARBINARY(32) NOT NULL,           -- 'cpic_api', 'fda_table', 'derived', 'sandbox_seed', 'dpwg', etc.
    il_version       VARBINARY(64) NOT NULL,           -- API snapshot timestamp, FDA-table version, or local git rev
    il_timestamp     BINARY(14)    NOT NULL,           -- MW timestamp format YYYYMMDDHHMMSS
    il_rows_inserted INT UNSIGNED NOT NULL DEFAULT 0,
    il_rows_updated  INT UNSIGNED NOT NULL DEFAULT 0,
    il_notes         BLOB DEFAULT NULL,                -- freeform: error counts, unmapped names, etc.
    PRIMARY KEY (il_id),
    KEY /*i*/il_source_ts (il_source, il_timestamp)
) /*$wgDBTableOptions*/;
