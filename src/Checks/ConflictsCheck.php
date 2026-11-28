<?php
/**
 * Other plugins that also take over email.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Checks;

use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Check;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Result;

/**
 * Known SMTP plugins active, or wp_mail() replaced.
 */
final class ConflictsCheck implements Check {

	/**
	 * Plugin folder => name.
	 *
	 * @var array<string,string>
	 */
	const KNOWN = array(
		'wp-mail-smtp'                       => 'WP Mail SMTP',
		'wp-mail-smtp-pro'                   => 'WP Mail SMTP Pro',
		'fluent-smtp'                        => 'FluentSMTP',
		'post-smtp'                          => 'Post SMTP',
		'easy-wp-smtp'                       => 'Easy WP SMTP',
		'wp-smtp'                            => 'WP SMTP',
		'smtp-mailer'                        => 'SMTP Mailer',
		'gmail-smtp'                         => 'Gmail SMTP',
		'wpo365-msgraphmailer'               => 'WPO365 Graph Mailer',
		'mailgun'                            => 'Mailgun',
		'sendgrid-email-delivery-simplified' => 'SendGrid',
		'wp-ses'                             => 'WP Offload SES',
		'offload-ses-lite'                   => 'WP Offload SES Lite',
		'smtp2go'                            => 'SMTP2GO',
		'postman-smtp'                       => 'Postman SMTP',
		'wp-mailster'                        => 'WP Mailster',
		'site-mailer'                        => 'Site Mailer',
		'suremails'                          => 'SureMail',
		'brevo'                              => 'Brevo',
		'mailin'                             => 'Brevo (Sendinblue)',
	);

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'conflicts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Other email plugins', 'iworksie-smtp' );
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
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$found = array();
		foreach ( $active as $file ) {
			$folder = strtok( (string) $file, '/' );
			if ( false !== $folder && isset( self::KNOWN[ $folder ] ) ) {
				$found[] = self::KNOWN[ $folder ];
			}
		}

		$pluggable = '';
		if ( function_exists( 'wp_mail' ) ) {
			try {
				$ref  = new \ReflectionFunction( 'wp_mail' );
				$file = wp_normalize_path( (string) $ref->getFileName() );
				if ( false === strpos( $file, '/' . WPINC . '/pluggable.php' ) ) {
					$pluggable = $file;
				}
			} catch ( \ReflectionException $e ) {
				$pluggable = '';
			}
		}

		if ( '' !== $pluggable ) {
			$relative = str_replace( wp_normalize_path( ABSPATH ), '', $pluggable );
			return Result::critical(
				__( 'Another plugin has replaced WordPress\'s wp_mail()', 'iworksie-smtp' ),
				/* translators: %s: file path */
				sprintf( __( 'wp_mail() is defined in %s instead of WordPress core, so emails may not go through iWorks.ie SMTP at all.', 'iworksie-smtp' ), $relative ),
				array(
					'fix' => __( 'Deactivate the plugin that file belongs to, or deactivate iWorks.ie SMTP and use that plugin instead. Running both causes unpredictable results.', 'iworksie-smtp' ),
				)
			);
		}

		if ( ! empty( $found ) ) {
			return Result::recommended(
				__( 'Another email plugin is active', 'iworksie-smtp' ),
				/* translators: %s: plugin names */
				sprintf( __( 'Also active: %s. Two plugins changing how email is sent will fight each other, and whichever runs last wins.', 'iworksie-smtp' ), implode( ', ', array_unique( $found ) ) ),
				array(
					'fix'     => __( 'Keep one. If you are switching to iWorks.ie SMTP, send a test email here first, then deactivate the other plugin.', 'iworksie-smtp' ),
					'actions' => array(
						array(
							'label' => __( 'Open Plugins', 'iworksie-smtp' ),
							'url'   => admin_url( 'plugins.php' ),
						),
					),
				)
			);
		}

		return Result::good(
			__( 'No conflicting email plugins', 'iworksie-smtp' ),
			__( 'No other SMTP plugin is active and wp_mail() is WordPress\'s own.', 'iworksie-smtp' )
		);
	}
}
