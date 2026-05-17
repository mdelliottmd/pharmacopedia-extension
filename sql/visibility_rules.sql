-- Visibility rules: richer per-record sharing than the legacy pf_visibility enum.
-- See: feedback "visibility model rethink" 2026-05-17.
CREATE TABLE /*_*/pcp_visibility_rules (
    vr_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vr_profile_id   INT UNSIGNED NOT NULL,
    vr_namespace    VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    vr_key          VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    vr_rule_type    VARCHAR(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    vr_payload      MEDIUMBLOB   DEFAULT NULL,
    vr_attribution  TINYINT      NOT NULL DEFAULT 1,
    vr_expires      BINARY(14)   DEFAULT NULL,
    vr_revoked      TINYINT      NOT NULL DEFAULT 0,
    vr_label        VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    vr_created      BINARY(14)   NOT NULL,
    vr_updated      BINARY(14)   NOT NULL,
    PRIMARY KEY (vr_id),
    KEY /*i*/vr_target  (vr_profile_id, vr_namespace, vr_key),
    KEY /*i*/vr_type    (vr_rule_type),
    KEY /*i*/vr_expires (vr_expires)
) /*$wgDBTableOptions*/;

-- Cohorts (groups of users that share visibility scopes).
CREATE TABLE /*_*/pcp_cohorts (
    co_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    co_owner_id    INT UNSIGNED NOT NULL,
    co_name        VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    co_description MEDIUMBLOB   DEFAULT NULL,
    co_created     BINARY(14)   NOT NULL,
    co_updated     BINARY(14)   NOT NULL,
    PRIMARY KEY (co_id),
    KEY /*i*/co_owner (co_owner_id)
) /*$wgDBTableOptions*/;

CREATE TABLE /*_*/pcp_cohort_members (
    cm_cohort_id   INT UNSIGNED NOT NULL,
    cm_user_id     INT UNSIGNED NOT NULL,
    cm_joined      BINARY(14)   NOT NULL,
    PRIMARY KEY (cm_cohort_id, cm_user_id),
    KEY /*i*/cm_user (cm_user_id)
) /*$wgDBTableOptions*/;

-- Audit log of viewed-by events for visibility-gated content.
-- Useful for the "who has viewed my shared X" UI in Phase 5.
CREATE TABLE /*_*/pcp_visibility_view_log (
    vl_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vl_rule_id    INT UNSIGNED DEFAULT NULL,
    vl_owner_id   INT UNSIGNED NOT NULL,
    vl_viewer_id  INT UNSIGNED DEFAULT NULL,
    vl_viewer_ip  VARBINARY(45) DEFAULT NULL,
    vl_namespace  VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    vl_key        VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    vl_viewed_at  BINARY(14)   NOT NULL,
    PRIMARY KEY (vl_id),
    KEY /*i*/vl_owner_time (vl_owner_id, vl_viewed_at),
    KEY /*i*/vl_rule       (vl_rule_id)
) /*$wgDBTableOptions*/;
