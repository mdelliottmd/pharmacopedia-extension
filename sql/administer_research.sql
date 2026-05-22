-- pcp_administer_research: de-identified research pool. One row is written
-- per submission, decoupled from the result write, with NO foreign key to
-- the invite, respondent, or owner. res_id is random (not sequential) and
-- there is no precise insert timestamp, only res_month, so a row cannot be
-- time-correlated or rank-correlated back to the owner-side tables.
-- Part of the "Administer to others" feature (Phase 1 schema).

CREATE TABLE /*_*/pcp_administer_research (
    res_id         BINARY(16)    NOT NULL,   -- random 128-bit id; NOT sequential
    res_instrument VARBINARY(32) NOT NULL,   -- assessment instrument slug
    res_payload    BLOB          NOT NULL,   -- JSON: item responses + computed score
    res_month      VARBINARY(7)  NOT NULL,   -- 'YYYY-MM'; coarsened, no day
    PRIMARY KEY (res_id),
    KEY /*i*/res_instrument (res_instrument)
) /*$wgDBTableOptions*/;
