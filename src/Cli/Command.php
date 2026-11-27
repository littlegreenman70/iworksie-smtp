<?php
/**
 * WP-CLI commands.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Cli;

use IWorksIE\SMTP\Mail\Router;
use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Support\ErrorTranslator;

/**
 * Send a test email and show the current configuration from the command line.
 */
final class Command {

	/**
	 * Register with WP-CLI when available.
	 *
	 * @return void
	 */
	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'iworksie-smtp', __CLASS__ );
		}
	}

	/**
	 * Send a test email.
	 *
	 * ## OPTIONS
	 *
	 * <to>
	 * : Recipient address.
	 *
	 * ## EXAMPLES
	 *
	 *     wp iworksie-smtp test you@example.com
	 *
	 * @param array<int,string>    $args       Positional.
	 * @param array<string,string> $assoc_args Named.
	 * @return void
	 */
	public function test( $args, $assoc_args ) {
		unset( $assoc_args );
		$to = isset( $args[0] ) ? sanitize_email( $args[0] ) : '';
		if ( '' === $to ) {
			\WP_CLI::error( 'Give a valid email address.' );
		}
		$error   = '';
		$capture = static function ( $wp_error ) use ( &$error ) {
			if ( $wp_error instanceof \WP_Error ) {
				$error = $wp_error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( $to, 'Test email from ' . wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), 'This test email was sent by iWorks.ie SMTP from WP-CLI.' );
		remove_action( 'wp_mail_failed', $capture );

		if ( $sent ) {
			\WP_CLI::success( sprintf( 'Sent to %s through %s.', $to, Router::last_mailer() ) );
			return;
		}
		$explained = ErrorTranslator::explain( $error, Router::last_mailer() );
		\WP_CLI::line( $explained['title'] );
		\WP_CLI::line( $explained['explain'] );
		\WP_CLI::line( 'Fix: ' . $explained['fix'] );
		\WP_CLI::error( 'Technical message: ' . $error );
	}

	/**
	 * Show the active mailer and From address (never secrets).
	 *
	 * @param array<int,string>    $args       Positional.
	 * @param array<string,string> $assoc_args Named.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		\WP_CLI::line( 'Mailer:       ' . Settings::mailer() );
		\WP_CLI::line( 'From address: ' . Settings::from_email() );
		\WP_CLI::line( 'Logging:      ' . ( Settings::get( 'log_enabled' ) ? 'on' : 'off' ) );
	}
}
