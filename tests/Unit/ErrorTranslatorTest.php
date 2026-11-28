<?php
/**
 * Error translation.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Tests\Unit;

use IWorksIE\SMTP\Support\ErrorTranslator;

/**
 * @covers \IWorksIE\SMTP\Support\ErrorTranslator
 */
final class ErrorTranslatorTest extends UnitTestCase {

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function provider() {
		return array(
			'secret value vs id'   => array( 'invalid_client: AADSTS7000215: Invalid client secret provided. Ensure the secret being sent in the request is the client secret value, not the client secret ID', 'microsoft', 'AADSTS7000215' ),
			'secret expired'       => array( 'AADSTS7000222: The provided client secret keys for app are expired.', 'microsoft', 'AADSTS7000222' ),
			'app not in tenant'    => array( 'unauthorized_client: AADSTS700016: Application with identifier was not found in the directory', 'microsoft', 'AADSTS700016' ),
			'tenant invalid'       => array( 'AADSTS90002: Tenant not found.', 'microsoft', 'AADSTS90002' ),
			'graph access denied'  => array( 'ErrorAccessDenied: Access is denied. Check credentials and try again. (HTTP 403)', 'microsoft', 'ErrorAccessDenied' ),
			'graph user'           => array( 'ErrorInvalidUser: The requested user is invalid. (HTTP 404)', 'microsoft', 'ErrorInvalidUser' ),
			'graph mailbox'        => array( 'MailboxNotEnabledForRESTAPI: The mailbox is either inactive, soft-deleted, or is hosted on-premise.', 'microsoft', 'ErrorInvalidUser' ),
			'graph size'           => array( 'ErrorMessageSizeExceeded: message is 5000000 bytes', 'microsoft', 'ErrorMessageSizeExceeded' ),
			'google testing'       => array( 'invalid_grant: Token has been expired or revoked.', 'google', 'invalid_grant' ),
			'google api off'       => array( 'accessNotConfigured: Gmail API has not been used in project 123 before or it is disabled. (HTTP 403)', 'google', 'accessNotConfigured' ),
			'google redirect'      => array( 'redirect_uri_mismatch', 'google', 'redirect_uri_mismatch' ),
			'm365 smtp basic auth' => array( 'SMTP Error: Could not authenticate. 535 5.7.139 Authentication unsuccessful, SmtpClientAuthentication is disabled for the Tenant.', 'smtp', '5.7.139' ),
			'smtp auth'            => array( 'SMTP Error: Could not authenticate.', 'smtp', '535' ),
			'smtp connect'         => array( 'SMTP Error: Could not connect to SMTP host. Failed to connect to server', 'smtp', 'connect_failed' ),
			'smtp connect()'       => array( 'SMTP connect() failed. https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting', 'smtp', 'connect_failed' ),
			'gmail app password'   => array( '534-5.7.9 Application-specific password required.', 'smtp', '534' ),
			'mail() disabled'      => array( 'Could not instantiate mail function.', 'default', 'mail_function' ),
			'http timeout'         => array( 'cURL error 28: Operation timed out after 20001 milliseconds', 'microsoft', 'http_failed' ),
		);
	}

	/**
	 * @dataProvider provider
	 *
	 * @param string $raw    Raw error.
	 * @param string $mailer Mailer.
	 * @param string $code   Expected rule code.
	 */
	public function test_known_errors_are_explained( $raw, $mailer, $code ) {
		$out = ErrorTranslator::explain( $raw, $mailer );
		$this->assertSame( $code, $out['code'] );
		$this->assertNotSame( '', $out['title'] );
		$this->assertNotSame( '', $out['fix'] );
	}

	public function test_unknown_error_has_generic_explanation() {
		$out = ErrorTranslator::explain( 'Something odd happened', 'smtp' );
		$this->assertSame( '', $out['code'] );
		$this->assertNotSame( '', $out['fix'] );
	}
}
