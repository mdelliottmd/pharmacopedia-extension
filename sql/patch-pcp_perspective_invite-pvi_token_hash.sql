-- Schema patch: pcp_perspective_invite.pvi_token_hash
--
-- M3 from server-claude's 2026-05-21 Administer + Perspective audit.
-- Adds a SHA-256 hash column alongside the cleartext pvi_token,
-- so once code reads-by-hash (interface-claude's lane in
-- PerspectiveStore::resolveToken) and the backfill is complete,
-- a stolen database yields no usable invite tokens.
--
-- Mirrors pcp_administer_invites.inv_token_hash (which already
-- shipped without a cleartext companion). pvi_token stays through
-- 0.9.8.7 as a read-fallback during the dual-write window; gets
-- dropped in 0.9.8.8.

ALTER TABLE /*_*/pcp_perspective_invite
    ADD COLUMN pvi_token_hash BINARY(32) DEFAULT NULL AFTER pvi_token,
    ADD UNIQUE KEY /*i*/pvi_token_hash (pvi_token_hash);
