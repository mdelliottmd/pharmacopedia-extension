-- Anonymization renamed er_user_id → er_voter_hash; the unique index follows suit.
-- Lands on fresh installs; existing installs already have this index under this name.
ALTER TABLE /*_*/pcp_effect_reports
    ADD UNIQUE KEY /*i*/er_element_hash_persp
    (er_element_id, er_voter_hash, er_perspective);
