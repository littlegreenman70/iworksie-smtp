<?php
/**
 * Can we sign in to the mail service right now?
 *
 * @package IWorksIE\SMTP
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- PHPMailer's own property names.

namespace IWorksIE\SMTP\Checks;

use IWorksIE\SMTP\Mail\Transport\Gmail;
use IWorksIE\SMTP\Mail\Transport\MicrosoftGraph;
use IWorksIE\SMTP\Mail\Transport\TransportException;
use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Support\ErrorTranslator;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Check;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Result;

/**
 * Live sign-in test without sending an email: token request for the APIs,
 * connect + STARTTLS + AUTH for SMTP. Runs only when the admin clicks
 * "Run diagnostics".
 */
final class ConnectionCheck implements Check {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'connection';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Sign-in to the mail service', 'iworksie-smtp' );
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
		$mailer = Settings::mailer();
		try {
			if ( 'microsoft' === $mailer ) {
				if ( ! MicrosoftGraph::is_configured() ) {
					return $this->skipped();
				}
				( new MicrosoftGraph() )->access_token( true );
				return Result::good(
					__( 'Signed in to Microsoft 365', 'iworksie-smtp' ),
					__( 'Microsoft accepted the tenant ID, client ID and client secret. If test emails still fail, the problem is the Mail.Send permission or the mailbox; send a test email to see the exact reason.', 'iworksie-smtp' )
				);
			}
			if ( 'google' === $mailer ) {
				if ( ! Gmail::is_configured() ) {
					return $this->skipped();
				}
				( new Gmail() )->access_token( true );
				return Result::good(
					/* translators: %s: Google account email */
					sprintf( __( 'Connected to Google as %s', 'iworksie-smtp' ), (string) Settings::get( 'google_email' ) ),
					__( 'Google accepted the saved connection and issued a fresh access token.', 'iworksie-smtp' )
				);
			}
			if ( 'smtp' === $mailer ) {
				if ( '' === (string) Settings::get( 'smtp_host' ) ) {
					return $this->skipped();
				}
				return $this->smtp();
			}
		} catch ( TransportException $e ) {
			return $this->failed( $e->getMessage(), $mailer );
		}
		return $this->skipped();
	}

	/**
	 * SMTP handshake and authentication (no message sent).
	 *
	 * @return Result
	 */
	private function smtp() {
		if ( ! class_exists( '\PHPMailer\PHPMailer\SMTP', false ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}
		$host       = (string) Settings::get( 'smtp_host' );
		$port       = (int) Settings::get( 'smtp_port' );
		$encryption = (string) Settings::get( 'smtp_encryption' );
		$smtp       = new \PHPMailer\PHPMailer\SMTP();
		$smtp->setTimeout( 10 );
		$errors            = array();
		$smtp->do_debug    = 1;
		$smtp->Debugoutput = static function ( $line ) use ( &$errors ) {
			$errors[] = trim( (string) $line );
		};

		$target = ( 'ssl' === $encryption ? 'ssl://' : '' ) . $host;
		if ( ! $smtp->connect( $target, $port, 10 ) ) {
			return $this->failed( 'SMTP connect() failed. ' . self::last_error( $smtp, $errors ), 'smtp' );
		}
		$helo = wp_parse_url( home_url(), PHP_URL_HOST );
		$helo = is_string( $helo ) && '' !== $helo ? $helo : 'localhost';
		if ( ! $smtp->hello( $helo ) ) {
			$smtp->close();
			return $this->failed( self::last_error( $smtp, $errors ), 'smtp' );
		}
		$tls = 'ssl' === $encryption;
		if ( ! $tls && ( 'tls' === $encryption || $smtp->getServerExt( 'STARTTLS' ) ) ) {
			if ( ! $smtp->startTLS() ) {
				$smtp->close();
				return $this->failed( 'STARTTLS failed: certificate verify failed? ' . self::last_error( $smtp, $errors ), 'smtp' );
			}
			$tls = true;
			$smtp->hello( $helo );
		}
		if ( Settings::get( 'smtp_auth' ) ) {
			if ( ! $smtp->authenticate( (string) Settings::get( 'smtp_username' ), Settings::secret( 'smtp_password' ) ) ) {
				$smtp->close();
				return $this->failed( 'Could not authenticate. ' . self::last_error( $smtp, $errors ), 'smtp' );
			}
		}
		$smtp->quit();
		$smtp->close();

		return Result::good(
			/* translators: 1: host, 2: port */
			sprintf( __( 'Connected to %1$s on port %2$d', 'iworksie-smtp' ), $host, $port ),
			$tls
				? ( Settings::get( 'smtp_auth' ) ? __( 'The connection is encrypted and the username and password were accepted.', 'iworksie-smtp' ) : __( 'The connection is encrypted. No sign-in is configured.', 'iworksie-smtp' ) )
				: __( 'Connected, but the connection is not encrypted. Passwords and emails travel in plain text.', 'iworksie-smtp' ),
			$tls ? array() : array( 'fix' => __( 'Choose TLS (port 587) or SSL (port 465) if your provider supports it.', 'iworksie-smtp' ) )
		);
	}

	/**
	 * Last SMTP error text.
	 *
	 * @param \PHPMailer\PHPMailer\SMTP $smtp   SMTP.
	 * @param array<int,string>         $errors Debug lines.
	 * @return string
	 */
	private static function last_error( $smtp, array $errors ) {
		$err = $smtp->getError();
		$out = trim( (string) ( isset( $err['error'] ) ? $err['error'] : '' ) . ' ' . ( isset( $err['smtp_code'] ) ? $err['smtp_code'] : '' ) . ' ' . ( isset( $err['detail'] ) ? $err['detail'] : '' ) );
		if ( '' === $out && ! empty( $errors ) ) {
			$out = (string) end( $errors );
		}
		return $out;
	}

	/**
	 * Failure result with a plain-English explanation.
	 *
	 * @param string $raw    Raw error.
	 * @param string $mailer Mailer.
	 * @return Result
	 */
	private function failed( $raw, $mailer ) {
		$explained = ErrorTranslator::explain( $raw, $mailer );
		return Result::critical(
			$explained['title'],
			$explained['explain'] . ' ' . sprintf(
				/* translators: %s: raw error */
				__( 'Technical message: %s', 'iworksie-smtp' ),
				$raw
			),
			array(
				'fix'     => $explained['fix'],
				'actions' => array(
					array(
						'label' => __( 'Open Setup', 'iworksie-smtp' ),
						'url'   => admin_url( 'admin.php?page=iworksie-smtp&tab=setup' ),
					),
				),
			)
		);
	}

	/**
	 * Nothing to test yet.
	 *
	 * @return Result
	 */
	private function skipped() {
		return Result::info(
			__( 'Sign-in not tested', 'iworksie-smtp' ),
			__( 'Finish the Setup tab first; this check then signs in to your mail service without sending anything.', 'iworksie-smtp' )
		);
	}
}
