-- pcp_administer_userkey: per-owner key material. One row per owner.
-- Each owner has an X25519 keypair. uk_public_key is stored in the clear
-- (public keys are not secret); the secret key is wrapped at rest in
-- uk_wrapped_seckey. Mode A ('passphrase'): wrapped with an Argon2id-derived
-- key that the server never stores (uk_kdf_salt + uk_verifier support that).
-- Mode B ('managed'): wrapped with a server master key held in a file
-- outside the database.
-- Part of the "Administer to others" feature (Phase 1 schema).

CREATE TABLE /*_*/pcp_administer_userkey (
    uk_user_id        INT UNSIGNED      NOT NULL,   -- owner: a registered user
    uk_mode           VARBINARY(16)     NOT NULL,   -- 'passphrase' / 'managed'
    uk_public_key     BINARY(32)        NOT NULL,   -- X25519 public key
    uk_wrapped_seckey VARBINARY(120)    NOT NULL,   -- X25519 secret key, AES-256-GCM wrapped
    uk_kdf_salt       BINARY(16)        DEFAULT NULL,   -- Argon2id salt; Mode A only
    uk_verifier       BINARY(32)        DEFAULT NULL,   -- passphrase verifier; Mode A only
    uk_scheme_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    uk_created        BINARY(14)        NOT NULL,
    uk_updated        BINARY(14)        NOT NULL,
    PRIMARY KEY (uk_user_id)
) /*$wgDBTableOptions*/;
