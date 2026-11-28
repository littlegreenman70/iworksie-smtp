<?php
/**
 * Routes wp_mail() through the configured mailer.
 *
 * @package IWorksIE\SMTP
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- PHPMailer's own property names.

namespace IWorksIE\SMTP\Mail;

use IWorksIE\SMTP\Mail\Transport\Gmail;
use IWorksIE\SMTP\Mail\Transport\MicrosoftGraph;
use IWorksIE\SMTP\Mail\Transport\Transport;
use IWorksIE\SMTP\Settings\Settings;

/**
 * Hooks: wp_mail (swap in our PHPMailer for API mailers), phpmailer_init
 * (SMTP settings, envelope sender, debug capture), wp_mail_from(_name).
 */
final class Router {

	/**
	 * When true, the next emails use PHP mail() (used for failure alerts so
	 * an alert is not routed through the connection that just failed).
	 *
	 * @var bool
	 */
	private static $bypass = false;

	/**
	 * SMTP conversation capture for the test email.
	 *
	 * @var array<int,string>|null
	 */
	private static $transcript = null;

	/**
	 * Mailer actually used for the most recent email.
	 *
	 * @var string
	 */
	private static $last_mailer = 'default';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'wp_mail', array( $this, 'prepare' ), PHP_INT_MAX );
		add_action( 'phpmailer_init', array( $this, 'configure' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from', array( $this, 'filter_from' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), PHP_INT_MAX );
	}

	/**
	 * Run a callback with routing bypassed.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	public static function without_routing( $callback ) {
		self::$bypass = true;
		try {
			return call_user_func( $callback );
		} finally {
			self::$bypass = false;
		}
	}

	/**
	 * Start capturing the SMTP conversation.
	 *
	 * @return void
	 */
	public static function start_transcript() {
		self::$transcript = array();
	}

	/**
	 * Stop capturing and return the (redacted) lines.
	 *
	 * @return array<int,string>
	 */
	public static function stop_transcript() {
		$lines            = is_array( self::$transcript ) ? self::$transcript : array();
		self::$transcript = null;
		return $lines;
	}

	/**
	 * Mailer used for the most recent email.
	 *
	 * @return string
	 */
	public static function last_mailer() {
		return self::$last_mailer;
	}

	/**
	 * Mailer that will be used right now.
	 *
	 * @return string
	 */
	public static function effective_mailer() {
		return self::$bypass ? 'default' : Settings::mailer();
	}

	/**
	 * Runs at the start of wp_mail(), before WordPress checks the global
	 * $phpmailer. For API mailers, put our subclass there.
	 *
	 * @param array<string,mixed> $atts wp_mail arguments.
	 * @return array<string,mixed>
	 */
	public function prepare( $atts ) {
		global $phpmailer;

		$mailer            = self::effective_mailer();
		self::$last_mailer = $mailer;

		if ( $phpmailer instanceof Catcher ) {
			$phpmailer->set_transport( self::transport_for( $mailer ) );
			return $atts;
		}
		if ( 'microsoft' !== $mailer && 'google' !== $mailer ) {
			return $atts;
		}

		$phpmailer = self::new_catcher(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intended: WordPress uses whatever PHPMailer instance is in this global.
		$phpmailer->set_transport( self::transport_for( $mailer ) );
		return $atts;
	}

	/**
	 * Build our PHPMailer the same way WordPress builds its own.
	 *
	 * @return Catcher
	 */
	public static function new_catcher() {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		if ( is_readable( ABSPATH . WPINC . '/class-wp-phpmailer.php' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
		}
		$catcher             = new Catcher( true );
		$catcher::$validator = static function ( $email ) {
			return (bool) is_email( $email );
		};
		return $catcher;
	}

	/**
	 * Transport for a mailer id, or null for PHPMailer's own delivery.
	 *
	 * @param string $mailer Mailer.
	 * @return Transport|null
	 */
	public static function transport_for( $mailer ) {
		if ( 'microsoft' === $mailer ) {
			return new MicrosoftGraph();
		}
		if ( 'google' === $mailer ) {
			return new Gmail();
		}
		return null;
	}

	/**
	 * Apply SMTP settings and envelope sender.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer PHPMailer instance.
	 * @return void
	 */
	public function configure( $mailer ) {
		$active = self::effective_mailer();

		if ( 'smtp' === $active && '' !== (string) Settings::get( 'smtp_host' ) ) {
			$mailer->isSMTP();
			$mailer->Host    = (string) Settings::get( 'smtp_host' );
			$mailer->Port    = (int) Settings::get( 'smtp_port' );
			$mailer->Timeout = 20;
			$encryption      = (string) Settings::get( 'smtp_encryption' );
			if ( 'ssl' === $encryption ) {
				$mailer->SMTPSecure = 'ssl';
			} elseif ( 'tls' === $encryption ) {
				$mailer->SMTPSecure = 'tls';
			} else {
				$mailer->SMTPSecure = '';
			}
			// Upgrade to TLS whenever the server offers it, even on "None".
			$mailer->SMTPAutoTLS = true;
			if ( Settings::get( 'smtp_auth' ) ) {
				$mailer->SMTPAuth = true;
				$mailer->Username = (string) Settings::get( 'smtp_username' );
				$mailer->Password = Settings::secret( 'smtp_password' );
			} else {
				$mailer->SMTPAuth = false;
			}
			if ( Settings::get( 'return_path' ) && '' !== $mailer->From ) {
				$mailer->Sender = $mailer->From;
			}
		}

		if ( is_array( self::$transcript ) ) {
			$mailer->SMTPDebug   = 3;
			$mailer->Debugoutput = static function ( $line ) {
				self::$transcript[] = self::redact( (string) $line );
			};
		}
	}

	/**
	 * Remove credentials from SMTP debug lines (AUTH payloads are base64).
	 *
	 * @param string $line Debug line.
	 * @return string
	 */
	public static function redact( $line ) {
		static $after_auth = 0;
		$line              = rtrim( $line );
		if ( preg_match( '/CLIENT -> SERVER:\s*AUTH\s+(PLAIN|LOGIN|XOAUTH2|CRAM-MD5)\b/i', $line, $m ) ) {
			$after_auth = ( 'PLAIN' === strtoupper( $m[1] ) ) ? 0 : 2;
			return preg_replace( '/(AUTH\s+\S+).*/i', '$1 [hidden]', $line );
		}
		if ( $after_auth > 0 && false !== strpos( $line, 'CLIENT -> SERVER:' ) ) {
			--$after_auth;
			return (string) preg_replace( '/CLIENT -> SERVER:.*/', 'CLIENT -> SERVER: [hidden]', $line );
		}
		return $line;
	}

	/**
	 * From address.
	 *
	 * @param string $from Current From.
	 * @return string
	 */
	public function filter_from( $from ) {
		if ( self::$bypass ) {
			return $from;
		}
		$ours = Settings::from_email();
		if ( '' === $ours || ! is_email( $ours ) ) {
			return $from;
		}
		$forced = (bool) Settings::get( 'force_from_email' ) || 'microsoft' === Settings::mailer();
		if ( $forced || self::is_wp_default_from( (string) $from ) ) {
			return $ours;
		}
		return $from;
	}

	/**
	 * From name.
	 *
	 * @param string $name Current name.
	 * @return string
	 */
	public function filter_from_name( $name ) {
		if ( self::$bypass ) {
			return $name;
		}
		$ours = (string) Settings::get( 'from_name' );
		if ( '' === $ours ) {
			return $name;
		}
		if ( Settings::get( 'force_from_name' ) || 'WordPress' === $name || '' === $name ) {
			return $ours;
		}
		return $name;
	}

	/**
	 * Whether an address is WordPress's built-in wordpress@domain default.
	 *
	 * @param string $from Address.
	 * @return bool
	 */
	public static function is_wp_default_from( $from ) {
		return 0 === strpos( strtolower( $from ), 'wordpress@' );
	}
}
