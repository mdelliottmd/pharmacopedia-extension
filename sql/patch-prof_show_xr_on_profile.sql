ALTER TABLE /*_*/pcp_user_profiles
    ADD COLUMN prof_show_xr_on_profile TINYINT NOT NULL DEFAULT 0
    AFTER prof_show_default;
