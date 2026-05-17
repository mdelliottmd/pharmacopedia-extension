-- v0.13: structured start/stop dates for meds (pcp-date-input widget).
-- um_added (auto-timestamp of when row was created) and um_duration_days remain for
-- backward compat; um_start_struct/um_stop_struct override when present.
ALTER TABLE /*_*/pcp_user_meds
    ADD COLUMN um_start_struct MEDIUMBLOB DEFAULT NULL AFTER um_duration_days,
    ADD COLUMN um_stop_struct  MEDIUMBLOB DEFAULT NULL AFTER um_start_struct;
