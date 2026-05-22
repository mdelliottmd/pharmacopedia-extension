-- Adds aa_respondent_enc to pcp_administer_assessments.
--
-- The completed result, sealed a second time with a key derived from the
-- invite token (see AdminCrypto::encryptForRespondent). This lets the
-- respondent - who has no account - read their own results back and view
-- their persistent dashboard. NULL until the respondent submits.

ALTER TABLE /*_*/pcp_administer_assessments
    ADD COLUMN aa_respondent_enc BLOB DEFAULT NULL;
