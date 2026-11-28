<?php
/**
 * Failure and expiry alerts.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Log;

use IWorksIE\SMTP\Mail\Router;
use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Support\ErrorTranslator;

/**
 * Emails the admin (through PHP mail(), not the failing connection) when
 * sending fails, at most once an hour, and warns before a Microsoft client
 * secret expires. Also shows a dismissible admin notice.
 */
final class Alerts {

	const THROTTLE_TRANSIENT = 'iwiesm_alert_sent';

	/**
	 * Pending alert for this request.
	 *
	 * @var array<string,string>|null
	 */
	private $pending = null;

	/**
	 * Suppress alerts (test emails show their result on screen).
	 *
	 * @var bool
	 */
	private static $muted = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'wp_mail_failed', array( $this, 'on_failure' ), 20 );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'admin_notice' ) );
		add_action( Logger::CRON_HOOK, array( __CLASS__, 'daily' ) );
	}

	/**
	 * Mute or unmute alerts.
	 *
	 * @param bool $muted Muted.
	 * @return void
	 */
	public static function mute( $muted ) {
		self::$muted = (bool) $muted;
	}

	/**
	 * Queue an alert for the end of the request.
	 *
	 * @param mixed $error WP_Error from wp_mail_failed.
	 * @return void
	 */
	public function on_failure( $error ) {
		if ( self::$muted || ! Settings::get( 'alerts_enabled' ) || 'default' === Router::last_mailer() || null !== $this->pending ) {
			return;
		}
		if ( false !== get_transient( self::THROTTLE_TRANSIENT ) ) {
			return;
		}
		$data          = $error instanceof \WP_Error ? $error->get_error_data() : array();
		$subject       = is_array( $data ) && isset( $data['subject'] ) ? (string) $data['subject'] : '';
		$this->pending = array(
			'message' => $error instanceof \WP_Error ? $error->get_error_message() : '',
			'subject' => $subject,
			'mailer'  => Router::last_mailer(),
		);
		add_action( 'shutdown', array( $this, 'send_pending' ) );
	}

	/**
	 * Send the queued failure alert.
	 *
	 * @return void
	 */
	public function send_pending() {
		if ( null === $this->pending ) {
			return;
		}
		$pending       = $this->pending;
		$this->pending = null;
		set_transient( self::THROTTLE_TRANSIENT, 1, HOUR_IN_SECONDS );

		$explained = ErrorTranslator::explain( $pending['message'], $pending['mailer'] );
		$site      = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$lines     = array(
			/* translators: %s: site name */
			sprintf( __( 'An email from %s could not be sent.', 'iworksie-smtp' ), $site ),
			'',
			/* translators: %s: email subject */
			sprintf( __( 'Subject: %s', 'iworksie-smtp' ), $pending['subject'] ),
			'',
			$explained['title'],
			$explained['explain'],
			'',
			__( 'How to fix it:', 'iworksie-smtp' ),
			$explained['fix'],
			'',
			/* translators: %s: raw error */
			sprintf( __( 'Technical message: %s', 'iworksie-smtp' ), $pending['message'] ),
			'',
			/* translators: %s: URL */
			sprintf( __( 'Email log: %s', 'iworksie-smtp' ), admin_url( 'admin.php?page=iworksie-smtp&tab=log&status=failed' ) ),
			'',
			__( 'You will get at most one of these alerts per hour. Turn them off on the Setup tab.', 'iworksie-smtp' ),
		);
		self::send_alert(
			/* translators: %s: site name */
			sprintf( __( '[%s] Website email is failing', 'iworksie-smtp' ), $site ),
			implode( "\n", $lines )
		);
	}

	/**
	 * Daily: prune the log and warn about an expiring Microsoft secret.
	 *
	 * @return void
	 */
	public static function daily() {
		Logger::prune();

		if ( 'microsoft' !== Settings::mailer() || ! Settings::get( 'alerts_enabled' ) ) {
			return;
		}
		$days = self::days_until_secret_expiry();
		if ( null === $days || $days > 14 || $days < -1 ) {
			return;
		}
		$expiry = (string) Settings::get( 'ms_secret_expiry' );
		if ( get_option( 'iwiesm_expiry_alerted' ) === $expiry ) {
			return;
		}
		update_option( 'iwiesm_expiry_alerted', $expiry, false );
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		self::send_alert(
			/* translators: %s: site name */
			sprintf( __( '[%s] Microsoft 365 email secret expires soon', 'iworksie-smtp' ), $site ),
			implode(
				"\n",
				array(
					/* translators: %s: date */
					sprintf( __( 'The Microsoft client secret this website uses to send email expires on %s. When it expires, the website stops sending email.', 'iworksie-smtp' ), $expiry ),
					'',
					__( 'In the Entra admin centre open the app registration, go to Certificates & secrets, add a new client secret, then paste its Value and new expiry date on the Setup tab.', 'iworksie-smtp' ),
					'',
					admin_url( 'admin.php?page=iworksie-smtp' ),
				)
			)
		);
	}

	/**
	 * Days until the saved secret expiry date (negative once expired).
	 *
	 * @return int|null
	 */
	public static function days_until_secret_expiry() {
		$expiry = (string) Settings::get( 'ms_secret_expiry' );
		if ( '' === $expiry ) {
			return null;
		}
		$ts = strtotime( $expiry . ' 00:00:00 UTC' );
		if ( false === $ts ) {
			return null;
		}
		return (int) floor( ( $ts - time() ) / DAY_IN_SECONDS );
	}

	/**
	 * Send an alert through PHP mail(), bypassing our routing.
	 *
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @return void
	 */
	private static function send_alert( $subject, $body ) {
		$to = (string) Settings::get( 'alert_email' );
		if ( '' === $to ) {
			$to = (string) get_option( 'admin_email' );
		}
		if ( '' === $to ) {
			return;
		}
		self::$muted = true;
		Router::without_routing(
			static function () use ( $to, $subject, $body ) {
				return wp_mail( $to, $subject, $body );
			}
		);
		self::$muted = false;
	}

	/**
	 * Admin notice after a recent failure.
	 *
	 * @return void
	 */
	public function admin_notice() {
		$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			return;
		}
		$last = get_option( 'iwiesm_last_failure' );
		if ( ! is_array( $last ) || empty( $last['time'] ) ) {
			return;
		}
		$time      = (int) $last['time'];
		$dismissed = (int) get_option( 'iwiesm_notice_dismissed', 0 );
		if ( $time <= $dismissed || $time < time() - 7 * DAY_IN_SECONDS ) {
			return;
		}
		$log     = admin_url( 'admin.php?page=iworksie-smtp&tab=log&status=failed' );
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=iwiesm_dismiss_notice' ), 'iwiesm_dismiss_notice' );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'iWorks.ie SMTP:', 'iworksie-smtp' ) . '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: %s: human time difference, e.g. "2 hours" */
				__( 'an email failed to send %s ago.', 'iworksie-smtp' ),
				human_time_diff( $time )
			)
		);
		echo ' <a href="' . esc_url( $log ) . '">' . esc_html__( 'See what went wrong', 'iworksie-smtp' ) . '</a>';
		echo ' &middot; <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'iworksie-smtp' ) . '</a></p></div>';
	}
}
