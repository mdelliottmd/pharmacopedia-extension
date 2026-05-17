-- v0.12.1: pd_severity TINYINT → DECIMAL(3,2) for 2-sig-fig severity (e.g. 4.23/5).
-- Existing integer values convert losslessly (4 → 4.00).
ALTER TABLE /*_*/pcp_profile_diagnoses MODIFY pd_severity DECIMAL(3,2) DEFAULT NULL;
