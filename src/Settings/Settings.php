<?php
/**
 * Plugin settings storage.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Settings;

use IWorksIE\SMTP\Support\Crypto;

/**
 * Reads and writes the single `iwiesm_settings` option.
 *
 * Secrets (SMTP password, Microsoft client secret, Google client secret and
 * refresh token) are stored encrypted, and can instead be defined as constants
 * in wp-config.php, which always win.
 */
final class Settings {

	const OPTION = 'iwiesm_settings';

	const MAILERS = array( 'default', 'microsoft', 'google', 'smtp' );

	/**
	 * Secret keys and the wp-config.php constant that overrides each one.
	 *
	 * @var array<string,string>
	 */
	const SECRET_CONSTANTS = array(
		'smtp_password'        => 'IWIESM_SMTP_PASSWORD',
		'ms_client_secret'     => 'IWIESM_MS_CLIENT_SECRET',
		'google_client_secret' => 'IWIESM_GOOGLE_CLIENT_SECRET',
	);

	/**
	 * Request-level cache.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'mailer'               => 'default',
			'from_email'           => '',
			'from_name'            => '',
			'force_from_email'     => true,
			'force_from_name'      => false,
			'return_path'          => true,
			'smtp_host'            => '',
			'smtp_port'            => 587,
			'smtp_encryption'      => 'tls',
			'smtp_auth'            => true,
			'smtp_username'        => '',
			'smtp_password'        => '',
			'ms_tenant_id'         => '',
			'ms_client_id'         => '',
			'ms_client_secret'     => '',
			'ms_secret_expiry'     => '',
			'ms_mailbox'           => '',
			'google_client_id'     => '',
			'google_client_secret' => '',
			'google_refresh_token' => '',
			'google_email'         => '',
			'dkim_selector'        => '',
			'log_enabled'          => true,
			'log_keep_content'     => false,
			'log_days'             => 30,
			'log_max_rows'         => 10000,
			'alerts_enabled'       => true,
			'alert_email'          => '',
		);
	}

	/**
	 * All settings, secrets still encrypted.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			self::$cache = array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );
		}
		return self::$cache;
	}

	/**
	 * One setting (raw; secrets encrypted).
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Decrypted secret, honouring wp-config.php constants.
	 *
	 * @param string $key Secret key.
	 * @return string
	 */
	public static function secret( $key ) {
		$constant = self::secret_constant( $key );
		if ( '' !== $constant ) {
			return (string) constant( $constant );
		}
		$stored = (string) self::get( $key );
		if ( '' === $stored ) {
			return '';
		}
		return Crypto::decrypt( $stored );
	}

	/**
	 * Name of the defined constant overriding a secret, or ''.
	 *
	 * @param string $key Secret key.
	 * @return string
	 */
	public static function secret_constant( $key ) {
		if ( isset( self::SECRET_CONSTANTS[ $key ] ) && defined( self::SECRET_CONSTANTS[ $key ] ) ) {
			return self::SECRET_CONSTANTS[ $key ];
		}
		return '';
	}

	/**
	 * Whether a secret has a value (stored or constant).
	 *
	 * @param string $key Secret key.
	 * @return bool
	 */
	public static function has_secret( $key ) {
		return '' !== self::secret_constant( $key ) || '' !== (string) self::get( $key );
	}

	/**
	 * Whether a stored secret exists but can no longer be decrypted
	 * (usually because the site's salts were changed).
	 *
	 * @param string $key Secret key.
	 * @return bool
	 */
	public static function secret_unreadable( $key ) {
		if ( '' !== self::secret_constant( $key ) ) {
			return false;
		}
		$stored = (string) self::get( $key );
		return '' !== $stored && '' === Crypto::decrypt( $stored );
	}

	/**
	 * Active mailer id.
	 *
	 * @return string
	 */
	public static function mailer() {
		$mailer = (string) self::get( 'mailer' );
		return in_array( $mailer, self::MAILERS, true ) ? $mailer : 'default';
	}

	/**
	 * Whether the active mailer sends through an HTTP API.
	 *
	 * @return bool
	 */
	public static function is_api_mailer() {
		return in_array( self::mailer(), array( 'microsoft', 'google' ), true );
	}

	/**
	 * Effective From address for the active mailer.
	 *
	 * Microsoft Graph sends as the mailbox in the URL, so that mailbox is always
	 * the From address. Google sends as the connected account.
	 *
	 * @return string
	 */
	public static function from_email() {
		$mailer = self::mailer();
		if ( 'microsoft' === $mailer && '' !== (string) self::get( 'ms_mailbox' ) ) {
			return (string) self::get( 'ms_mailbox' );
		}
		$from = (string) self::get( 'from_email' );
		if ( '' === $from && 'google' === $mailer ) {
			$from = (string) self::get( 'google_email' );
		}
		return $from;
	}

	/**
	 * Merge and save values. Unknown keys are dropped.
	 *
	 * @param array<string,mixed> $values Already-sanitised values.
	 * @return void
	 */
	public static function update( array $values ) {
		$merged = array_merge( self::all(), array_intersect_key( $values, self::defaults() ) );
		update_option( self::OPTION, $merged, true );
		self::$cache = null;
	}

	/**
	 * Store a secret encrypted (empty string clears it).
	 *
	 * @param string $key   Secret key.
	 * @param string $value Plain value.
	 * @return void
	 */
	public static function set_secret( $key, $value ) {
		$value = (string) $value;
		self::update( array( $key => '' === $value ? '' : Crypto::encrypt( $value ) ) );
	}

	/**
	 * Forget the request cache (tests, after external option writes).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanitise posted form values into a settings array.
	 *
	 * Secrets are not part of the result; the caller handles them so an empty
	 * password field means "keep the saved one".
	 *
	 * @param array<string,mixed> $input Raw (unslashed) input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ) {
		$d   = self::defaults();
		$out = array();

		$mailer        = isset( $input['mailer'] ) ? sanitize_key( (string) $input['mailer'] ) : $d['mailer'];
		$out['mailer'] = in_array( $mailer, self::MAILERS, true ) ? $mailer : 'default';

		$out['from_email']       = isset( $input['from_email'] ) ? sanitize_email( (string) $input['from_email'] ) : '';
		$out['from_name']        = isset( $input['from_name'] ) ? sanitize_text_field( (string) $input['from_name'] ) : '';
		$out['force_from_email'] = ! empty( $input['force_from_email'] );
		$out['force_from_name']  = ! empty( $input['force_from_name'] );
		$out['return_path']      = ! empty( $input['return_path'] );

		$out['smtp_host'] = isset( $input['smtp_host'] ) ? self::sanitize_host( (string) $input['smtp_host'] ) : '';
		$port             = isset( $input['smtp_port'] ) ? absint( $input['smtp_port'] ) : 0;
		$out['smtp_port'] = ( $port > 0 && $port < 65536 ) ? $port : 587;
		$enc              = isset( $input['smtp_encryption'] ) ? sanitize_key( (string) $input['smtp_encryption'] ) : 'tls';

		$out['smtp_encryption'] = in_array( $enc, array( 'none', 'ssl', 'tls' ), true ) ? $enc : 'tls';
		$out['smtp_auth']       = ! empty( $input['smtp_auth'] );
		$out['smtp_username']   = isset( $input['smtp_username'] ) ? sanitize_text_field( (string) $input['smtp_username'] ) : '';

		$out['ms_tenant_id']     = isset( $input['ms_tenant_id'] ) ? self::sanitize_tenant( (string) $input['ms_tenant_id'] ) : '';
		$out['ms_client_id']     = isset( $input['ms_client_id'] ) ? self::sanitize_guid( (string) $input['ms_client_id'] ) : '';
		$out['ms_secret_expiry'] = isset( $input['ms_secret_expiry'] ) ? self::sanitize_date( (string) $input['ms_secret_expiry'] ) : '';
		$out['ms_mailbox']       = isset( $input['ms_mailbox'] ) ? sanitize_email( (string) $input['ms_mailbox'] ) : '';

		$out['google_client_id'] = isset( $input['google_client_id'] ) ? sanitize_text_field( trim( (string) $input['google_client_id'] ) ) : '';

		$selector             = isset( $input['dkim_selector'] ) ? strtolower( trim( (string) $input['dkim_selector'] ) ) : '';
		$out['dkim_selector'] = (string) preg_replace( '/[^a-z0-9._-]/', '', $selector );

		$out['log_enabled']      = ! empty( $input['log_enabled'] );
		$out['log_keep_content'] = ! empty( $input['log_keep_content'] );
		$days                    = isset( $input['log_days'] ) ? absint( $input['log_days'] ) : $d['log_days'];
		$out['log_days']         = min( 365, max( 1, $days ) );
		$rows                    = isset( $input['log_max_rows'] ) ? absint( $input['log_max_rows'] ) : $d['log_max_rows'];
		$out['log_max_rows']     = min( 100000, max( 100, $rows ) );
		$out['alerts_enabled']   = ! empty( $input['alerts_enabled'] );
		$out['alert_email']      = isset( $input['alert_email'] ) ? sanitize_email( (string) $input['alert_email'] ) : '';

		return $out;
	}

	/**
	 * Hostname only: strips scheme, path, port and whitespace.
	 *
	 * @param string $host Raw host.
	 * @return string
	 */
	public static function sanitize_host( $host ) {
		$host = strtolower( trim( $host ) );
		$host = (string) preg_replace( '#^[a-z]+://#', '', $host );
		$host = (string) preg_replace( '#[/:].*$#', '', $host );
		return (string) preg_replace( '/[^a-z0-9.-]/', '', $host );
	}

	/**
	 * GUID (lowercase) or ''.
	 *
	 * @param string $value Raw.
	 * @return string
	 */
	public static function sanitize_guid( $value ) {
		$value = strtolower( trim( $value ) );
		return preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value ) ? $value : '';
	}

	/**
	 * Tenant: a GUID or a domain such as contoso.onmicrosoft.com.
	 *
	 * @param string $value Raw.
	 * @return string
	 */
	public static function sanitize_tenant( $value ) {
		$guid = self::sanitize_guid( $value );
		if ( '' !== $guid ) {
			return $guid;
		}
		$value = strtolower( trim( $value ) );
		return preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $value ) ? $value : '';
	}

	/**
	 * Y-m-d or ''.
	 *
	 * @param string $value Raw.
	 * @return string
	 */
	public static function sanitize_date( $value ) {
		$value = trim( $value );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return '';
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : '';
	}
}
