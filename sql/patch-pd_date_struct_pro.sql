-- v0.12.2: secondary professional-diagnosis date payload.
-- Used only when pd_origin = 3 (Both); pd_date_struct holds the self-noticed date,
-- pd_date_struct_pro holds the professional-diagnosis date.
ALTER TABLE /*_*/pcp_profile_diagnoses
    ADD COLUMN pd_date_struct_pro MEDIUMBLOB DEFAULT NULL
    AFTER pd_date_struct;
