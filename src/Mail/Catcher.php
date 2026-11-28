<?php
/**
 * PHPMailer subclass that hands finished messages to an HTTP API.
 *
 * @package IWorksIE\SMTP
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- PHPMailer's own property names.

namespace IWorksIE\SMTP\Mail;

use IWorksIE\SMTP\Mail\Transport\Transport;
use IWorksIE\SMTP\Mail\Transport\TransportException;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * WordPress builds the email exactly as usual (headers, attachments,
 * encoding); only the final delivery step is replaced.
 */
class Catcher extends CatcherBase {

	/**
	 * Transport for the current send, or null to use PHPMailer itself.
	 *
	 * @var Transport|null
	 */
	private $transport = null;

	/**
	 * Set (or clear) the transport used by the next send().
	 *
	 * @param Transport|null $transport Transport.
	 * @return void
	 */
	public function set_transport( $transport ) {
		$this->transport = $transport;
	}

	/**
	 * Send, through the API transport when one is set.
	 *
	 * @return bool
	 * @throws MailerException When exceptions are enabled (WordPress enables them).
	 */
	public function send() {
		if ( null === $this->transport ) {
			return parent::send();
		}

		$original = $this->Mailer;
		try {
			// Build headers as the sendmail mailer does, so To, Subject and
			// Bcc are inside the MIME message. The API reads recipients from
			// those headers (Bcc is stripped before delivery).
			$this->Mailer = 'sendmail';
			$built        = $this->preSend();
			$this->Mailer = $original;
			if ( ! $built ) {
				return false;
			}
			$this->transport->send( $this->getSentMIMEMessage() );
			return true;
		} catch ( TransportException $e ) {
			$this->Mailer = $original;
			$this->setError( $e->getMessage() );
			if ( $this->exceptions ) {
				throw new MailerException( $e->getMessage(), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
			}
			return false;
		} catch ( MailerException $e ) {
			$this->Mailer = $original;
			$this->setError( $e->getMessage() );
			if ( $this->exceptions ) {
				throw $e;
			}
			return false;
		}
	}
}
