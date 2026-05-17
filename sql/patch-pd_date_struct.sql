-- v0.12: structured "when first noticed" payload for diagnoses (pcp-date-input widget).
-- pd_year_first is retained for backward-compat sort / derived-events fallback.
ALTER TABLE /*_*/pcp_profile_diagnoses
    ADD COLUMN pd_date_struct MEDIUMBLOB DEFAULT NULL
    AFTER pd_year_first;
