ALTER TABLE /*_*/pcp_votable_elements
    ADD COLUMN ve_open_ended  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN ve_max_options INT        NOT NULL DEFAULT 5;
