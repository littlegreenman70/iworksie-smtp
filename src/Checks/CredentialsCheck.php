<?php
/**
 * Saved secrets readable, and where they live.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Checks;

use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Support\Crypto;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Check;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Result;

/**
 * Detects secrets that can no longer be decrypted (salts changed).
 */
final class CredentialsCheck implements Check {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'stored_credentials';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Saved passwords and secrets', 'iworksie-smtp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function group() {
		return 'setup';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		$keys       = array( 'smtp_password', 'ms_client_secret', 'google_client_secret', 'google_refresh_token' );
		$unreadable = array();
		$constants  = array();
		foreach ( $keys as $key ) {
			if ( Settings::secret_unreadable( $key ) ) {
				$unreadable[] = $key;
			}
			if ( '' !== Settings::secret_constant( $key ) ) {
				$constants[] = Settings::secret_constant( $key );
			}
		}

		if ( ! empty( $unreadable ) ) {
			return Result::critical(
				__( 'A saved password can no longer be read', 'iworksie-smtp' ),
				__( 'Secrets are encrypted with your site\'s security keys (salts) in wp-config.php. Those keys have changed since the secret was saved (a security plugin or host may rotate them), so it cannot be decrypted and sending will fail.', 'iworksie-smtp' ),
				array(
					'fix'     => __( 'Re-enter the password or client secret on the Setup tab (or reconnect Google). To stop this happening again, add define( \'IWIESM_ENCRYPTION_KEY\', \'a long random string\' ); to wp-config.php before re-entering them.', 'iworksie-smtp' ),
					'actions' => array(
						array(
							'label' => __( 'Open Setup', 'iworksie-smtp' ),
							'url'   => admin_url( 'admin.php?page=iworksie-smtp&tab=setup' ),
						),
					),
				)
			);
		}

		if ( ! empty( $constants ) ) {
			return Result::good(
				__( 'Secrets are kept in wp-config.php', 'iworksie-smtp' ),
				/* translators: %s: constant names */
				sprintf( __( 'Defined there: %s. They are never stored in the database.', 'iworksie-smtp' ), implode( ', ', $constants ) )
			);
		}

		return Result::good(
			__( 'Saved secrets are encrypted', 'iworksie-smtp' ),
			Crypto::has_custom_key()
				? __( 'Secrets are encrypted in the database with your IWIESM_ENCRYPTION_KEY.', 'iworksie-smtp' )
				: __( 'Secrets are encrypted in the database with a key derived from your site\'s security salts. For extra safety you can define them as constants in wp-config.php instead (see the plugin FAQ).', 'iworksie-smtp' )
		);
	}
}
