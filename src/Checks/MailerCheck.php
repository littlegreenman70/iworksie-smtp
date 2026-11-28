<?php
/**
 * Is a real mailer configured?
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Checks;

use IWorksIE\SMTP\Mail\Transport\Gmail;
use IWorksIE\SMTP\Mail\Transport\MicrosoftGraph;
use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Check;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Result;

/**
 * Mailer selected and complete.
 */
final class MailerCheck implements Check {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'mailer_configured';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Email sending method', 'iworksie-smtp' );
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
		$setup  = array(
			array(
				'label' => __( 'Open Setup', 'iworksie-smtp' ),
				'url'   => admin_url( 'admin.php?page=iworksie-smtp&tab=setup' ),
			),
		);
		$mailer = Settings::mailer();

		if ( 'default' === $mailer ) {
			return Result::critical(
				__( 'WordPress is sending email with PHP mail()', 'iworksie-smtp' ),
				__( 'No mail service is set up, so emails are handed to the web server to deliver. Many hosts block this, and mail that does get out often lands in spam because it is not authenticated for your domain.', 'iworksie-smtp' ),
				array(
					'why'     => __( 'Password resets, order confirmations and contact form messages may silently never arrive.', 'iworksie-smtp' ),
					'fix'     => __( 'On the Setup tab choose Microsoft 365, Google or Other SMTP and follow the steps.', 'iworksie-smtp' ),
					'actions' => $setup,
				)
			);
		}

		$names    = array(
			'microsoft' => __( 'Microsoft 365 (Graph API)', 'iworksie-smtp' ),
			'google'    => __( 'Google (Gmail API)', 'iworksie-smtp' ),
			'smtp'      => __( 'Other SMTP', 'iworksie-smtp' ),
		);
		$complete = true;
		if ( 'microsoft' === $mailer ) {
			$complete = MicrosoftGraph::is_configured();
		} elseif ( 'google' === $mailer ) {
			$complete = Gmail::is_configured();
		} elseif ( 'smtp' === $mailer ) {
			$complete = '' !== (string) Settings::get( 'smtp_host' ) && ( ! Settings::get( 'smtp_auth' ) || ( '' !== (string) Settings::get( 'smtp_username' ) && Settings::has_secret( 'smtp_password' ) ) );
		}

		if ( ! $complete ) {
			return Result::critical(
				/* translators: %s: mailer name */
				sprintf( __( '%s is selected but not finished', 'iworksie-smtp' ), $names[ $mailer ] ),
				'google' === $mailer
					? __( 'The Google client details are missing, or "Connect with Google" has not been completed.', 'iworksie-smtp' )
					: __( 'Some required fields on the Setup tab are empty, so emails will fail.', 'iworksie-smtp' ),
				array(
					'fix'     => __( 'Complete the highlighted steps on the Setup tab, save, then send a test email.', 'iworksie-smtp' ),
					'actions' => $setup,
				)
			);
		}

		return Result::good(
			/* translators: %s: mailer name */
			sprintf( __( 'Sending through %s', 'iworksie-smtp' ), $names[ $mailer ] ),
			__( 'Email is handed to a real mail service instead of the web server.', 'iworksie-smtp' )
		);
	}
}
