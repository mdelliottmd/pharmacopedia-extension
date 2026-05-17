-- Adds le_date_struct: full structured date payload (JSON blob) from the pcp-date-input widget.
-- Existing rows keep their le_date_iso/precision/display; backfill maintenance script populates
-- le_date_struct from those legacy columns.
ALTER TABLE /*_*/pcp_life_events
    ADD COLUMN le_date_struct MEDIUMBLOB DEFAULT NULL
    AFTER le_date_display;
