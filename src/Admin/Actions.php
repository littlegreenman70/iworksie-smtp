<?php
/**
 * Form handlers (admin-post.php) and the Google OAuth callback.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Admin;

use IWorksIE\SMTP\Log\Alerts;
use IWorksIE\SMTP\Log\Logger;
use IWorksIE\SMTP\Mail\Router;
use IWorksIE\SMTP\Mail\Transport\Gmail;
use IWorksIE\SMTP\Mail\Transport\MicrosoftGraph;
use IWorksIE\SMTP\Mail\Transport\TransportException;
use IWorksIE\SMTP\Plugin;
use IWorksIE\SMTP\Settings\Settings;

/**
 * Every handler checks a nonce and the capability before doing anything.
 */
final class Actions {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_post_iwiesm_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_iwiesm_send_test', array( $this, 'send_test' ) );
		add_action( 'admin_post_iwiesm_google_connect', array( $this, 'google_connect' ) );
		add_action( 'admin_post_iwiesm_google_disconnect', array( $this, 'google_disconnect' ) );
		add_action( 'admin_post_iwiesm_log_clear', array( $this, 'log_clear' ) );
		add_action( 'admin_post_iwiesm_log_resend', array( $this, 'log_resend' ) );
		add_action( 'admin_post_iwiesm_dismiss_notice', array( $this, 'dismiss_notice' ) );
		add_action( 'admin_init', array( $this, 'google_callback' ) );
	}

	/**
	 * Settings page URL.
	 *
	 * @param string               $tab  Tab.
	 * @param array<string,string> $args Extra query args.
	 * @return string
	 */
	public static function url( $tab = 'setup', array $args = array() ) {
		return add_query_arg( array_merge( array( 'tab' => $tab ), $args ), admin_url( 'admin.php?page=iworksie-smtp' ) );
	}

	/**
	 * Stop unless the nonce and capability are valid.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function guard( $action ) {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'iworksie-smtp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Queue a notice for the next page view of this user.
	 *
	 * @param string              $type    success|error|warning|info.
	 * @param string              $message Message.
	 * @param array<string,mixed> $extra   Extra data (test results).
	 * @return void
	 */
	public static function flash( $type, $message, array $extra = array() ) {
		set_transient(
			'iwiesm_flash_' . get_current_user_id(),
			array_merge(
				array(
					'type'    => $type,
					'message' => $message,
				),
				$extra
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read and clear the queued notice.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function take_flash() {
		$key   = 'iwiesm_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( false === $flash ) {
			return null;
		}
		delete_transient( $key );
		return is_array( $flash ) ? $flash : null;
	}

	/**
	 * Save the Setup form.
	 *
	 * @return void
	 */
	public function save_settings() {
		$this->guard( 'iwiesm_save_settings' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked in guard(); sanitised field by field in Settings::sanitize().
		$input  = isset( $_POST['iwiesm'] ) && is_array( $_POST['iwiesm'] ) ? wp_unslash( $_POST['iwiesm'] ) : array();
		$before = Settings::all();
		$clean  = Settings::sanitize( $input );
		Settings::update( $clean );

		// Secrets: an empty field keeps the saved value. Values are taken
		// verbatim (only trimmed) because passwords may contain any character.
		foreach ( array( 'smtp_password', 'ms_client_secret', 'google_client_secret' ) as $key ) {
			if ( '' !== Settings::secret_constant( $key ) ) {
				continue;
			}
			if ( ! empty( $input[ $key . '_clear' ] ) ) {
				Settings::set_secret( $key, '' );
				continue;
			}
			$value = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( '' !== $value ) {
				Settings::set_secret( $key, $value );
			}
		}

		$after = Settings::all();
		// New Microsoft credentials: drop the cached token.
		foreach ( array( 'ms_tenant_id', 'ms_client_id', 'ms_client_secret' ) as $key ) {
			if ( $before[ $key ] !== $after[ $key ] ) {
				delete_transient( MicrosoftGraph::TOKEN_TRANSIENT );
				break;
			}
		}
		// A different Google client invalidates the connection.
		if ( $before['google_client_id'] !== $after['google_client_id'] && '' !== (string) $before['google_refresh_token'] ) {
			Settings::update(
				array(
					'google_refresh_token' => '',
					'google_email'         => '',
				)
			);
			delete_transient( Gmail::TOKEN_TRANSIENT );
		}
		if ( $before['ms_secret_expiry'] !== $after['ms_secret_expiry'] ) {
			delete_option( 'iwiesm_expiry_alerted' );
		}

		$message = __( 'Settings saved.', 'iworksie-smtp' );
		if ( 'google' === Settings::mailer() && Gmail::has_client() && ! Gmail::is_configured() ) {
			$message .= ' ' . __( 'Next: click "Connect with Google".', 'iworksie-smtp' );
		} elseif ( 'default' !== Settings::mailer() ) {
			$message .= ' ' . __( 'Next: send a test email.', 'iworksie-smtp' );
		}
		self::flash( 'success', $message );
		wp_safe_redirect( self::url( 'setup' ) );
		exit;
	}

	/**
	 * Send a test email and keep the outcome for the Test tab.
	 *
	 * @return void
	 */
	public function send_test() {
		$this->guard( 'iwiesm_send_test' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
		$to   = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( (string) $_POST['to'] ) ) : '';
		$html = ! empty( $_POST['html'] );
		// phpcs:enable
		if ( '' === $to || ! is_email( $to ) ) {
			self::flash( 'error', __( 'Enter a valid email address to send the test to.', 'iworksie-smtp' ) );
			wp_safe_redirect( self::url( 'test' ) );
			exit;
		}

		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Test email from %s', 'iworksie-smtp' ),
			$site
		);
		$mailer  = Settings::mailer();
		$names   = Screens::mailer_names();
		$via     = isset( $names[ $mailer ] ) ? $names[ $mailer ] : $mailer;
		$lines   = array(
			__( 'Good news: this email was sent by iWorks.ie SMTP.', 'iworksie-smtp' ),
			/* translators: %s: mailer name */
			sprintf( __( 'Sent through: %s', 'iworksie-smtp' ), $via ),
			/* translators: %s: date and time */
			sprintf( __( 'Sent at: %s', 'iworksie-smtp' ), wp_date( 'Y-m-d H:i:s T' ) ),
			/* translators: %s: site URL */
			sprintf( __( 'Website: %s', 'iworksie-smtp' ), home_url() ),
			'',
			__( 'If this arrived in spam or junk, run Diagnostics in the plugin to check SPF, DKIM and DMARC for your domain.', 'iworksie-smtp' ),
		);
		$headers = array();
		if ( $html ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
			$body      = '<p>' . implode( '<br>', array_map( 'esc_html', $lines ) ) . '</p>';
		} else {
			$body = implode( "\n", $lines );
		}

		$error   = '';
		$capture = static function ( $wp_error ) use ( &$error ) {
			if ( $wp_error instanceof \WP_Error ) {
				$error = $wp_error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $capture );
		Alerts::mute( true );
		Router::start_transcript();
		$start      = microtime( true );
		$sent       = wp_mail( $to, $subject, $body, $headers );
		$elapsed    = (int) round( ( microtime( true ) - $start ) * 1000 );
		$transcript = Router::stop_transcript();
		Alerts::mute( false );
		remove_action( 'wp_mail_failed', $capture );

		if ( $sent ) {
			self::flash(
				'success',
				/* translators: 1: address, 2: milliseconds */
				sprintf( __( 'Test email sent to %1$s in %2$d ms.', 'iworksie-smtp' ), $to, $elapsed ),
				array(
					'test'       => 'sent',
					'mailer'     => Router::last_mailer(),
					'transcript' => $transcript,
				)
			);
		} else {
			self::flash(
				'error',
				__( 'The test email could not be sent.', 'iworksie-smtp' ),
				array(
					'test'       => 'failed',
					'mailer'     => Router::last_mailer(),
					'raw'        => '' !== $error ? $error : __( 'wp_mail() returned false without an error message. Another plugin may have blocked the email.', 'iworksie-smtp' ),
					'transcript' => $transcript,
				)
			);
		}
		update_user_meta( get_current_user_id(), 'iwiesm_test_to', $to );
		wp_safe_redirect( self::url( 'test' ) );
		exit;
	}

	/**
	 * Start the Google consent flow.
	 *
	 * @return void
	 */
	public function google_connect() {
		$this->guard( 'iwiesm_google_connect' );
		if ( ! Gmail::has_client() ) {
			self::flash( 'error', __( 'Save the Google client ID and client secret first.', 'iworksie-smtp' ) );
			wp_safe_redirect( self::url( 'setup' ) );
			exit;
		}
		// Redirect to Google (an external host, so not wp_safe_redirect).
		wp_redirect( Gmail::authorize_url( wp_create_nonce( 'iwiesm_google_oauth' ) ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed accounts.google.com URL.
		exit;
	}

	/**
	 * Handle Google's redirect back to the settings page.
	 *
	 * @return void
	 */
	public function google_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the OAuth state parameter is the nonce, verified below.
		if ( ! isset( $_GET['page'] ) || 'iworksie-smtp' !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_GET['state'] ) || ( ! isset( $_GET['code'] ) && ! isset( $_GET['error'] ) ) ) {
			return;
		}
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}
		$state = sanitize_text_field( wp_unslash( (string) $_GET['state'] ) );
		if ( ! wp_verify_nonce( $state, 'iwiesm_google_oauth' ) ) {
			self::flash( 'error', __( 'The Google sign-in link expired. Click "Connect with Google" again.', 'iworksie-smtp' ) );
			wp_safe_redirect( self::url( 'setup' ) );
			exit;
		}
		if ( isset( $_GET['error'] ) ) {
			$raw = sanitize_text_field( wp_unslash( (string) $_GET['error'] ) );
			self::flash( 'error', __( 'Google did not connect.', 'iworksie-smtp' ), array( 'raw' => $raw ) );
			wp_safe_redirect( self::url( 'setup' ) );
			exit;
		}
		$code = sanitize_text_field( wp_unslash( (string) $_GET['code'] ) );
		// phpcs:enable

		try {
			$email = ( new Gmail() )->exchange_code( $code );
			self::flash(
				'success',
				'' !== $email
					/* translators: %s: email */
					? sprintf( __( 'Connected to Google as %s. Next: send a test email.', 'iworksie-smtp' ), $email )
					: __( 'Connected to Google. Next: send a test email.', 'iworksie-smtp' )
			);
		} catch ( TransportException $e ) {
			self::flash( 'error', __( 'Google did not connect.', 'iworksie-smtp' ), array( 'raw' => $e->getMessage() ) );
		}
		wp_safe_redirect( self::url( 'setup' ) );
		exit;
	}

	/**
	 * Disconnect Google.
	 *
	 * @return void
	 */
	public function google_disconnect() {
		$this->guard( 'iwiesm_google_disconnect' );
		Gmail::disconnect();
		self::flash( 'success', __( 'Disconnected from Google.', 'iworksie-smtp' ) );
		wp_safe_redirect( self::url( 'setup' ) );
		exit;
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public function log_clear() {
		$this->guard( 'iwiesm_log_clear' );
		Logger::clear();
		self::flash( 'success', __( 'Email log cleared.', 'iworksie-smtp' ) );
		wp_safe_redirect( self::url( 'log' ) );
		exit;
	}

	/**
	 * Resend one logged email.
	 *
	 * @return void
	 */
	public function log_resend() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in guard().
		$this->guard( 'iwiesm_log_resend_' . $id );
		$result = Logger::resend( $id );
		if ( null === $result ) {
			self::flash( 'error', __( 'This email cannot be resent because its content was not kept.', 'iworksie-smtp' ) );
		} elseif ( $result ) {
			self::flash( 'success', __( 'Email resent.', 'iworksie-smtp' ) );
		} else {
			self::flash( 'error', __( 'Resending failed again. See the newest entry in the log for the reason.', 'iworksie-smtp' ) );
		}
		wp_safe_redirect( self::url( 'log' ) );
		exit;
	}

	/**
	 * Hide the failure notice until the next failure.
	 *
	 * @return void
	 */
	public function dismiss_notice() {
		$this->guard( 'iwiesm_dismiss_notice' );
		update_option( 'iwiesm_notice_dismissed', time(), false );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}
}
