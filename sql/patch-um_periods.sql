-- v0.14: structured "periods of use" — JSON array of range structs.
-- Each entry is {"start": {...point...}, "end": {...point...|null}}.
-- Existing um_start_struct / um_stop_struct columns are preserved for backward compat;
-- on read, if um_periods is null they synthesize one period.
ALTER TABLE /*_*/pcp_user_meds
    ADD COLUMN um_periods MEDIUMBLOB DEFAULT NULL AFTER um_stop_struct;
