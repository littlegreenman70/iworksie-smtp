<?php
/**
 * Encryption for stored secrets.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Support;

/**
 * Authenticated encryption with libsodium secretbox.
 *
 * Sodium is built into PHP 7.2+ and WordPress ships sodium_compat as a
 * fallback, so this works on every supported host. The key comes from
 * IWIESM_ENCRYPTION_KEY when defined, otherwise from the site's salts.
 */
final class Crypto {

	const PREFIX = 'iwie1:';

	/**
	 * Encrypt a string. Returns '' for ''.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		} catch ( \Throwable $e ) {
			return '';
		}
		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext storage.
	}

	/**
	 * Decrypt a value from encrypt(). Returns '' when it cannot be read.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext storage.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		} catch ( \Throwable $e ) {
			return '';
		}
		return false === $plain ? '' : (string) $plain;
	}

	/**
	 * Whether a custom key constant is in use.
	 *
	 * @return bool
	 */
	public static function has_custom_key() {
		return defined( 'IWIESM_ENCRYPTION_KEY' ) && '' !== (string) constant( 'IWIESM_ENCRYPTION_KEY' );
	}

	/**
	 * 32-byte key.
	 *
	 * @return string
	 */
	private static function key() {
		if ( self::has_custom_key() ) {
			$material = (string) constant( 'IWIESM_ENCRYPTION_KEY' );
		} else {
			$material = ( defined( 'LOGGED_IN_KEY' ) ? (string) LOGGED_IN_KEY : '' ) . ( defined( 'LOGGED_IN_SALT' ) ? (string) LOGGED_IN_SALT : '' );
			if ( '' === $material ) {
				$material = (string) get_site_option( 'siteurl', 'iworksie-smtp' );
			}
		}
		return hash( 'sha256', 'iworksie-smtp|' . $material, true );
	}
}
