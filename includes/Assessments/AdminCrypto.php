<?php
/**
 * AdminCrypto
 *
 * Phase 1 crypto helpers for the "Administer to others" feature.
 *
 * Each owner has an X25519 keypair. The public key is stored in the clear;
 * respondent submissions are sealed to it with crypto_box_seal, so they can
 * be encrypted while the owner is absent. The secret key is wrapped at rest:
 *   Mode A ('passphrase'): wrapped with an Argon2id-derived key the server
 *     never stores. Zero-knowledge: without the passphrase the server cannot
 *     read results.
 *   Mode B ('managed'): wrapped with a server master key held in a file
 *     outside the database.
 *
 * Payload crypto scheme (SCHEME_VERSION, written to aa_scheme_version):
 * X25519 / crypto_box_seal payloads / AES-256-GCM key wrapping. At v1.
 *
 * Mode A passphrase KDF (KDF_VERSION, written per owner to uk_scheme_version):
 * Argon2id ARGON2ID13. v1 used INTERACTIVE limits; v2 uses the stronger
 * MODERATE limits. A new owner is created at the current KDF_VERSION; an
 * owner still on an older KDF version is transparently re-wrapped up to it
 * on their next successful unlock, leaving the X25519 keypair and every
 * payload sealed to it untouched.
 *
 * @license GPL-3.0-or-later
 */

namespace MediaWiki\Extension\Pharmacopedia\Assessments;

use MediaWiki\MediaWikiServices;
use RuntimeException;

class AdminCrypto {

    /** Payload crypto scheme version; see the class docblock. */
    public const SCHEME_VERSION = 1;

    /**
     * Current Mode A passphrase-KDF version, stored per owner in
     * uk_scheme_version. v1 = Argon2id INTERACTIVE limits (legacy),
     * v2 = MODERATE limits. setupOwnerKey writes this; unlockSecretKey
     * lazily upgrades an older row to it. See kdfLimits().
     */
    public const KDF_VERSION = 2;

    private const KEYDIR_CONFIG   = 'PharmacopediaAdminKeyDir';
    private const MASTER_KEY_FILE = 'master.key';
    private const GCM_IV_LEN      = 12;
    private const GCM_TAG_LEN     = 16;

    /**
     * Fetch an owner's key row, or false if they have not set up a key.
     */
    private static function userKeyRow( int $userId ) {
        $db = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
        return $db->newSelectQueryBuilder()
            ->select( '*' )
            ->from( 'pcp_administer_userkey' )
            ->where( [ 'uk_user_id' => $userId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
    }

    /**
     * Has this owner set up their key yet?
     */
    public static function hasOwnerKey( int $userId ): bool {
        return $userId > 0 && self::userKeyRow( $userId ) !== false;
    }

    /**
     * Create an owner's keypair on first use. One row per owner.
     * Mode A requires a passphrase; Mode B does not.
     * @return string the owner's X25519 public key (raw 32 bytes)
     */
    public static function setupOwnerKey( int $userId, string $mode, ?string $passphrase = null ): string {
        if ( $userId <= 0 ) {
            throw new RuntimeException( 'AdminCrypto: invalid user id' );
        }
        if ( self::hasOwnerKey( $userId ) ) {
            throw new RuntimeException( 'AdminCrypto: owner key already exists' );
        }

        $keypair   = sodium_crypto_box_keypair();
        $publicKey = sodium_crypto_box_publickey( $keypair );
        $secretKey = sodium_crypto_box_secretkey( $keypair );

        $salt = null;
        $verifier = null;
        if ( $mode === 'passphrase' ) {
            if ( $passphrase === null || $passphrase === '' ) {
                throw new RuntimeException( 'AdminCrypto: passphrase mode requires a passphrase' );
            }
            $salt = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
            [ $wrapKey, $verifier ] = self::deriveFromPassphrase(
                $passphrase, $salt, self::KDF_VERSION );
            $wrapped = self::gcmWrap( $secretKey, $wrapKey );
            sodium_memzero( $wrapKey );
        } elseif ( $mode === 'managed' ) {
            $wrapped = self::gcmWrap( $secretKey, self::masterKey() );
        } else {
            throw new RuntimeException( 'AdminCrypto: unknown mode ' . $mode );
        }
        sodium_memzero( $secretKey );
        sodium_memzero( $keypair );

        $now = wfTimestamp( TS_MW );
        $db = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
        $db->newInsertQueryBuilder()
            ->insertInto( 'pcp_administer_userkey' )
            ->row( [
                'uk_user_id'        => $userId,
                'uk_mode'           => $mode,
                'uk_public_key'     => $publicKey,
                'uk_wrapped_seckey' => $wrapped,
                'uk_kdf_salt'       => $salt,
                'uk_verifier'       => $verifier,
                'uk_scheme_version' => self::KDF_VERSION,
                'uk_created'        => $now,
                'uk_updated'        => $now,
            ] )
            ->caller( __METHOD__ )
            ->execute();

        return $publicKey;
    }

    /**
     * Argon2id cost limits for a KDF version. v1 keeps the original
     * INTERACTIVE limits so existing owners' wrapped keys stay derivable;
     * v2 uses the stronger MODERATE limits. The KDF runs at most once per
     * login (not per request), so the higher cost is affordable.
     */
    private static function kdfLimits( int $kdfVersion ): array {
        switch ( $kdfVersion ) {
            case 1:
                return [
                    SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
                    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
                ];
            case 2:
                return [
                    SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
                    SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
                ];
            default:
                throw new RuntimeException(
                    'AdminCrypto: unknown KDF version ' . $kdfVersion );
        }
    }

    /**
     * Argon2id(passphrase, salt) -> [ wrapKey (32 bytes), verifier (32 bytes) ]
     * at the cost limits of the given KDF version.
     */
    private static function deriveFromPassphrase(
        string $passphrase, string $salt, int $kdfVersion
    ): array {
        [ $opsLimit, $memLimit ] = self::kdfLimits( $kdfVersion );
        $out = sodium_crypto_pwhash(
            64,
            $passphrase,
            $salt,
            $opsLimit,
            $memLimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
        return [ substr( $out, 0, 32 ), substr( $out, 32, 32 ) ];
    }

    /**
     * Mode A: confirm a typed passphrase against the stored verifier,
     * without storing the passphrase or the derived key.
     */
    public static function verifyPassphrase( int $userId, string $passphrase ): bool {
        $row = self::userKeyRow( $userId );
        if ( !$row || $row->uk_mode !== 'passphrase' || $row->uk_kdf_salt === null ) {
            return false;
        }
        [ $wrapKey, $verifier ] = self::deriveFromPassphrase(
            $passphrase, $row->uk_kdf_salt, (int)$row->uk_scheme_version );
        sodium_memzero( $wrapKey );
        return hash_equals( (string)$row->uk_verifier, $verifier );
    }

    /**
     * Return the owner's X25519 secret key for THIS request only. Mode A
     * requires the passphrase; Mode B uses the server master key. The caller
     * MUST NOT persist the returned key (no session storage, no disk).
     */
    public static function unlockSecretKey( int $userId, ?string $passphrase = null ): string {
        $row = self::userKeyRow( $userId );
        if ( !$row ) {
            throw new RuntimeException( 'AdminCrypto: no key for owner' );
        }
        if ( $row->uk_mode === 'passphrase' ) {
            if ( $passphrase === null || $passphrase === '' ) {
                throw new RuntimeException( 'AdminCrypto: passphrase required' );
            }
            $kdfVersion = (int)$row->uk_scheme_version;
            [ $wrapKey, $verifier ] = self::deriveFromPassphrase(
                $passphrase, $row->uk_kdf_salt, $kdfVersion );
            if ( !hash_equals( (string)$row->uk_verifier, $verifier ) ) {
                sodium_memzero( $wrapKey );
                throw new RuntimeException( 'AdminCrypto: wrong passphrase' );
            }
            $secret = self::gcmUnwrap( $row->uk_wrapped_seckey, $wrapKey );
            sodium_memzero( $wrapKey );
            // Lazy KDF upgrade: an owner wrapped under an older Argon2id cost
            // is re-wrapped to the current KDF_VERSION now that the passphrase
            // is in hand. One-time and best-effort - a failure here must not
            // block the unlock; the next unlock simply retries.
            if ( $kdfVersion < self::KDF_VERSION ) {
                self::rewrapPassphraseKey( $userId, $passphrase, $secret );
            }
            return $secret;
        }
        if ( $row->uk_mode === 'managed' ) {
            return self::gcmUnwrap( $row->uk_wrapped_seckey, self::masterKey() );
        }
        throw new RuntimeException( 'AdminCrypto: unknown mode' );
    }

    /**
     * Re-wrap a Mode A owner's X25519 secret key under the current
     * KDF_VERSION. Called from unlockSecretKey after a successful unlock of
     * an older-KDF row. A fresh salt and verifier are derived and the secret
     * key is re-wrapped; the X25519 keypair, uk_public_key, and every payload
     * sealed to it are unchanged. Best-effort: any failure is swallowed so
     * the in-progress unlock still returns. The WHERE is scoped by owner and
     * skips a row already at KDF_VERSION, so a concurrent upgrade is not
     * clobbered.
     */
    private static function rewrapPassphraseKey(
        int $userId, string $passphrase, string $secretKey
    ): void {
        try {
            $salt = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
            [ $wrapKey, $verifier ] = self::deriveFromPassphrase(
                $passphrase, $salt, self::KDF_VERSION );
            $wrapped = self::gcmWrap( $secretKey, $wrapKey );
            sodium_memzero( $wrapKey );
            $db = MediaWikiServices::getInstance()
                ->getConnectionProvider()->getPrimaryDatabase();
            $db->newUpdateQueryBuilder()
                ->update( 'pcp_administer_userkey' )
                ->set( [
                    'uk_wrapped_seckey' => $wrapped,
                    'uk_kdf_salt'       => $salt,
                    'uk_verifier'       => $verifier,
                    'uk_scheme_version' => self::KDF_VERSION,
                    'uk_updated'        => wfTimestamp( TS_MW ),
                ] )
                ->where( [
                    'uk_user_id' => $userId,
                    'uk_mode'    => 'passphrase',
                    'uk_scheme_version < ' . (int)self::KDF_VERSION,
                ] )
                ->caller( __METHOD__ )
                ->execute();
        } catch ( \Throwable $e ) {
            // The unlock already succeeded; a failed upgrade just retries on
            // the next unlock.
        }
    }

    /**
     * Encrypt a payload for an owner. Needs only the owner's public key, so
     * this works at respondent-submission time with the owner absent.
     */
    public static function encryptForOwner( int $userId, string $plaintext ): string {
        $row = self::userKeyRow( $userId );
        if ( !$row ) {
            throw new RuntimeException( 'AdminCrypto: no key for owner' );
        }
        return sodium_crypto_box_seal( $plaintext, $row->uk_public_key );
    }

    /**
     * Decrypt a payload sealed to an owner. Needs the owner's unlocked
     * secret key (see unlockSecretKey).
     */
    public static function decryptForOwner( int $userId, string $secretKey, string $ciphertext ): string {
        $row = self::userKeyRow( $userId );
        if ( !$row ) {
            throw new RuntimeException( 'AdminCrypto: no key for owner' );
        }
        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            $secretKey, $row->uk_public_key
        );
        $plain = sodium_crypto_box_seal_open( $ciphertext, $keypair );
        sodium_memzero( $keypair );
        if ( $plain === false ) {
            throw new RuntimeException( 'AdminCrypto: decryption failed' );
        }
        return $plain;
    }

    /**
     * Mint an invite token. Returns [ rawToken, tokenHash ]. The raw token
     * goes in the link ONLY; store the hash (see hashInviteToken). 256-bit,
     * base64url, no padding.
     */
    public static function mintInviteToken(): array {
        $raw = sodium_bin2base64( random_bytes( 32 ), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
        return [ $raw, self::hashInviteToken( $raw ) ];
    }

    /**
     * Hash an invite token for storage and lookup. Raw 32-byte SHA-256.
     */
    public static function hashInviteToken( string $rawToken ): string {
        return hash( 'sha256', $rawToken, true );
    }

    /**
     * Derive the respondent's symmetric key from the raw invite token.
     *
     * The respondent has no account; the invite link they hold IS their
     * credential. The key is a domain-separated SHA-256 of the raw token.
     * The raw token is never stored (only its hash, for lookup), so this
     * key exists only while someone is actually holding the link.
     */
    private static function respondentKey( string $rawToken ): string {
        return hash( 'sha256', 'pcp-administer-respondent-v1:' . $rawToken, true );
    }

    /**
     * Encrypt a payload so the holder of the raw invite token can read it
     * back later. Used for the respondent's own copy of their results, the
     * one that powers their persistent dashboard.
     */
    public static function encryptForRespondent( string $rawToken, string $plaintext ): string {
        $key = self::respondentKey( $rawToken );
        $blob = self::gcmWrap( $plaintext, $key );
        sodium_memzero( $key );
        return $blob;
    }

    /**
     * Decrypt a payload sealed with encryptForRespondent(), given the raw
     * invite token from the link.
     */
    public static function decryptForRespondent( string $rawToken, string $ciphertext ): string {
        $key = self::respondentKey( $rawToken );
        $plain = self::gcmUnwrap( $ciphertext, $key );
        sodium_memzero( $key );
        return $plain;
    }

    /**
     * AES-256-GCM wrap. Output blob = iv(12) || ciphertext || tag(16).
     */
    private static function gcmWrap( string $plaintext, string $key ): string {
        $iv = random_bytes( self::GCM_IV_LEN );
        $tag = '';
        $ct = openssl_encrypt(
            $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::GCM_TAG_LEN
        );
        if ( $ct === false ) {
            throw new RuntimeException( 'AdminCrypto: GCM wrap failed' );
        }
        return $iv . $ct . $tag;
    }

    /**
     * AES-256-GCM unwrap of a blob produced by gcmWrap().
     */
    private static function gcmUnwrap( string $blob, string $key ): string {
        if ( strlen( $blob ) < self::GCM_IV_LEN + self::GCM_TAG_LEN ) {
            throw new RuntimeException( 'AdminCrypto: GCM blob too short' );
        }
        $iv  = substr( $blob, 0, self::GCM_IV_LEN );
        $tag = substr( $blob, -self::GCM_TAG_LEN );
        $ct  = substr( $blob, self::GCM_IV_LEN, -self::GCM_TAG_LEN );
        $pt = openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
        if ( $pt === false ) {
            throw new RuntimeException( 'AdminCrypto: GCM unwrap failed (wrong key or tampered data)' );
        }
        return $pt;
    }

    /**
     * The Mode B server master key (32 bytes). Read from a file outside the
     * database; generated on first managed-mode use if absent. The key
     * directory must already exist (created out of band, mode 700 www-data)
     * and be inside open_basedir.
     */
    private static function masterKey(): string {
        $dir = MediaWikiServices::getInstance()->getMainConfig()->get( self::KEYDIR_CONFIG );
        if ( !is_string( $dir ) || $dir === '' ) {
            throw new RuntimeException( 'AdminCrypto: ' . self::KEYDIR_CONFIG . ' is not configured' );
        }
        $path = rtrim( $dir, '/' ) . '/' . self::MASTER_KEY_FILE;

        if ( is_file( $path ) ) {
            $key = file_get_contents( $path );
            if ( $key === false || strlen( $key ) !== 32 ) {
                throw new RuntimeException( 'AdminCrypto: master key unreadable or wrong length' );
            }
            return $key;
        }

        if ( !is_dir( $dir ) ) {
            throw new RuntimeException( 'AdminCrypto: key directory does not exist: ' . $dir );
        }
        $key = random_bytes( 32 );
        $tmp = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
        if ( file_put_contents( $tmp, $key ) === false ) {
            throw new RuntimeException( 'AdminCrypto: cannot write master key' );
        }
        chmod( $tmp, 0600 );
        if ( !rename( $tmp, $path ) ) {
            // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
            @unlink( $tmp );
            throw new RuntimeException( 'AdminCrypto: cannot place master key' );
        }
        return $key;
    }
}
