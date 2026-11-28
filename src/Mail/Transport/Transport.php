<?php
/**
 * HTTP API transport contract.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Mail\Transport;

/**
 * Sends a complete RFC 5322 message built by PHPMailer.
 */
interface Transport {

	/**
	 * Mailer id.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Send the MIME message.
	 *
	 * @param string $mime Full message including headers (To, Cc, Bcc, Subject).
	 * @return void
	 * @throws TransportException On failure, with the provider's error text.
	 */
	public function send( $mime );
}
