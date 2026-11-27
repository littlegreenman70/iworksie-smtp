<?php
/**
 * End-to-end routing through wp_mail() with mocked HTTP.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Tests\Integration;

use IWorksIE\SMTP\Log\Logger;
use IWorksIE\SMTP\Mail\Catcher;
use IWorksIE\SMTP\Mail\Transport\Gmail;
use IWorksIE\SMTP\Mail\Transport\MicrosoftGraph;
use IWorksIE\SMTP\Settings\Settings;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
final class MailRoutingTest extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests.
	 *
	 * @var array<int,array{url:string,args:array<string,mixed>}>
	 */
	private $requests = array();

	/**
	 * Canned responses by URL substring.
	 *
	 * @var array<string,array{code:int,body:string}>
	 */
	private $responses = array();

	/**
	 * Original global PHPMailer (the test suite's mock).
	 *
	 * @var object|null
	 */
	private $original_mailer = null;

	public function set_up() {
		parent::set_up();
		global $phpmailer;
		$this->original_mailer = $phpmailer;
		Logger::install();
		Logger::clear();
		delete_option( Settings::OPTION );
		delete_transient( MicrosoftGraph::TOKEN_TRANSIENT );
		delete_transient( Gmail::TOKEN_TRANSIENT );
		Settings::flush();
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down() {
		global $phpmailer;
		$phpmailer = $this->original_mailer;
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		delete_option( Settings::OPTION );
		Settings::flush();
		parent::tear_down();
	}

	/**
	 * Mock every outgoing request.
	 *
	 * @param mixed               $pre  Pre.
	 * @param array<string,mixed> $args Args.
	 * @param string              $url  URL.
	 * @return array<string,mixed>
	 */
	public function mock_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		foreach ( $this->responses as $needle => $response ) {
			if ( false !== strpos( $url, $needle ) ) {
				return array(
					'headers'  => array(),
					'body'     => $response['body'],
					'response' => array(
						'code'    => $response['code'],
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		return new \WP_Error( 'unmocked', 'Unmocked URL ' . $url );
	}

	/**
	 * Configure Microsoft 365.
	 *
	 * @return void
	 */
	private function use_microsoft() {
		Settings::update(
			array(
				'mailer'           => 'microsoft',
				'ms_tenant_id'     => 'contoso.onmicrosoft.com',
				'ms_client_id'     => '11111111-2222-4333-8444-555555555555',
				'ms_mailbox'       => 'website@contoso.com',
				'from_name'        => 'Contoso',
				'force_from_email' => true,
			)
		);
		Settings::set_secret( 'ms_client_secret', 'super~secret' );
	}

	/**
	 * Request whose URL contains a needle.
	 *
	 * @param string $needle Needle.
	 * @return array{url:string,args:array<string,mixed>}|null
	 */
	private function request_to( $needle ) {
		foreach ( $this->requests as $request ) {
			if ( false !== strpos( $request['url'], $needle ) ) {
				return $request;
			}
		}
		return null;
	}

	public function test_microsoft_sends_mime_with_all_recipients() {
		$this->use_microsoft();
		$this->responses['login.microsoftonline.com'] = array(
			'code' => 200,
			'body' => (string) wp_json_encode(
				array(
					'access_token' => 'tok123',
					'expires_in'   => 3599,
				)
			),
		);
		$this->responses['graph.microsoft.com']       = array(
			'code' => 202,
			'body' => '',
		);

		$file = wp_tempnam( 'iwiesm-attach' );
		file_put_contents( $file, 'attachment-body' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$sent = wp_mail(
			'to@example.org',
			'Order #42',
			'Hello there',
			array( 'From: Shop <shop@other.example>', 'Cc: cc@example.org', 'Bcc: hidden@example.org', 'Reply-To: reply@example.org' ),
			array( $file )
		);
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertTrue( $sent );
		global $phpmailer;
		$this->assertInstanceOf( Catcher::class, $phpmailer );
		if ( class_exists( '\\WP_PHPMailer', false ) ) {
			$this->assertInstanceOf( '\\WP_PHPMailer', $phpmailer, 'keeps WordPress 6.8+ translated PHPMailer' );
		}

		$token = $this->request_to( 'login.microsoftonline.com' );
		$this->assertNotNull( $token );
		$this->assertStringContainsString( '/contoso.onmicrosoft.com/oauth2/v2.0/token', $token['url'] );
		$this->assertSame( 'super~secret', $token['args']['body']['client_secret'] );
		$this->assertSame( 'client_credentials', $token['args']['body']['grant_type'] );

		$send = $this->request_to( 'graph.microsoft.com' );
		$this->assertNotNull( $send );
		$this->assertStringContainsString( '/users/website%40contoso.com/sendMail', $send['url'] );
		$this->assertSame( 'Bearer tok123', $send['args']['headers']['Authorization'] );
		$this->assertSame( 'text/plain', $send['args']['headers']['Content-Type'] );

		$mime = (string) base64_decode( (string) $send['args']['body'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertMatchesRegularExpression( '/^To: to@example\.org/m', $mime );
		$this->assertMatchesRegularExpression( '/^Cc: cc@example\.org/m', $mime );
		$this->assertMatchesRegularExpression( '/^Bcc: hidden@example\.org/m', $mime );
		$this->assertMatchesRegularExpression( '/^Subject: Order #42/m', $mime );
		$this->assertMatchesRegularExpression( '/^From: Shop <website@contoso\.com>/m', $mime, 'Microsoft always sends from the mailbox' );
		$this->assertMatchesRegularExpression( '/^Reply-To: reply@example\.org/m', $mime );
		$this->assertStringContainsString( 'Content-Disposition: attachment', $mime );

		$log = Logger::query( '', 1, 10 );
		$this->assertSame( 1, $log['total'] );
		$this->assertSame( 'sent', $log['rows'][0]['status'] );
		$this->assertSame( 'microsoft', $log['rows'][0]['mailer'] );
		$this->assertSame( 'Order #42', $log['rows'][0]['subject'] );
		$this->assertSame( '0', (string) $log['rows'][0]['has_content'], 'content is not kept by default' );

		// Second email reuses the cached token.
		$this->requests = array();
		$this->assertTrue( wp_mail( 'to@example.org', 'Again', 'Body' ) );
		$this->assertNull( $this->request_to( 'login.microsoftonline.com' ) );
	}

	public function test_microsoft_failure_is_reported_and_logged() {
		$this->use_microsoft();
		$this->responses['login.microsoftonline.com'] = array(
			'code' => 401,
			'body' => (string) wp_json_encode(
				array(
					'error'             => 'invalid_client',
					'error_description' => "AADSTS7000215: Invalid client secret provided.\r\nTrace ID: abc",
				)
			),
		);
		$failed = null;
		$listen = static function ( $error ) use ( &$failed ) {
			$failed = $error;
		};
		add_action( 'wp_mail_failed', $listen );
		$sent = wp_mail( 'to@example.org', 'Fails', 'Body' );
		remove_action( 'wp_mail_failed', $listen );

		$this->assertFalse( $sent );
		$this->assertInstanceOf( \WP_Error::class, $failed );
		$this->assertStringContainsString( 'AADSTS7000215', $failed->get_error_message() );
		$this->assertStringNotContainsString( 'Trace ID', $failed->get_error_message() );

		$log = Logger::query( 'failed', 1, 10 );
		$this->assertSame( 1, $log['total'] );
		$this->assertStringContainsString( 'AADSTS7000215', (string) $log['rows'][0]['error'] );
		$last = get_option( 'iwiesm_last_failure' );
		$this->assertSame( 'microsoft', $last['mailer'] );
	}

	public function test_google_sends_raw_mime_via_upload_endpoint() {
		Settings::update(
			array(
				'mailer'           => 'google',
				'google_client_id' => 'abc.apps.googleusercontent.com',
				'google_email'     => 'me@gmail.com',
			)
		);
		Settings::set_secret( 'google_client_secret', 'gsecret' );
		Settings::set_secret( 'google_refresh_token', 'refresh-1' );

		$this->responses['oauth2.googleapis.com/token'] = array(
			'code' => 200,
			'body' => (string) wp_json_encode(
				array(
					'access_token' => 'gtok',
					'expires_in'   => 3599,
				)
			),
		);
		$this->responses['gmail.googleapis.com']        = array(
			'code' => 200,
			'body' => '{"id":"1"}',
		);

		$this->assertTrue( wp_mail( 'to@example.org', 'Hi', 'Body', array( 'Bcc: b@example.org' ) ) );

		$token = $this->request_to( 'oauth2.googleapis.com/token' );
		$this->assertSame( 'refresh-1', $token['args']['body']['refresh_token'] );
		$send = $this->request_to( 'gmail.googleapis.com' );
		$this->assertStringContainsString( 'uploadType=media', $send['url'] );
		$this->assertSame( 'message/rfc822', $send['args']['headers']['Content-Type'] );
		$this->assertMatchesRegularExpression( '/^Bcc: b@example\.org/m', (string) $send['args']['body'] );
		$this->assertMatchesRegularExpression( '/^From: .*<me@gmail\.com>/m', (string) $send['args']['body'] );
	}

	public function test_google_revoked_token_is_explained() {
		Settings::update(
			array(
				'mailer'           => 'google',
				'google_client_id' => 'abc.apps.googleusercontent.com',
			)
		);
		Settings::set_secret( 'google_client_secret', 'gsecret' );
		Settings::set_secret( 'google_refresh_token', 'refresh-1' );
		$this->responses['oauth2.googleapis.com/token'] = array(
			'code' => 400,
			'body' => '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}',
		);
		$this->assertFalse( wp_mail( 'to@example.org', 'Hi', 'Body' ) );
		$log = Logger::query( 'failed', 1, 10 );
		$this->assertStringContainsString( 'invalid_grant', (string) $log['rows'][0]['error'] );
	}

	public function test_smtp_settings_are_applied() {
		global $phpmailer;
		Settings::update(
			array(
				'mailer'           => 'smtp',
				'smtp_host'        => 'smtp.example.net',
				'smtp_port'        => 465,
				'smtp_encryption'  => 'ssl',
				'smtp_auth'        => true,
				'smtp_username'    => 'apikey',
				'from_email'       => 'hello@example.net',
				'from_name'        => 'Example',
				'force_from_email' => true,
			)
		);
		Settings::set_secret( 'smtp_password', 'p@ss"word' );

		$this->assertNotInstanceOf( Catcher::class, $phpmailer, 'SMTP keeps WordPress\'s own PHPMailer' );
		wp_mail( 'to@example.org', 'SMTP', 'Body', array( 'From: Plugin <plugin@elsewhere.example>' ) );

		$this->assertSame( 'smtp', $phpmailer->Mailer );
		$this->assertSame( 'smtp.example.net', $phpmailer->Host );
		$this->assertSame( 465, $phpmailer->Port );
		$this->assertSame( 'ssl', $phpmailer->SMTPSecure );
		$this->assertTrue( $phpmailer->SMTPAuth );
		$this->assertSame( 'apikey', $phpmailer->Username );
		$this->assertSame( 'p@ss"word', $phpmailer->Password );
		$this->assertSame( 'hello@example.net', $phpmailer->From );
		$this->assertSame( 'Plugin', $phpmailer->FromName, 'name set by a plugin is kept unless forced' );
		$this->assertSame( 'hello@example.net', $phpmailer->Sender );
		$this->assertEmpty( $this->requests, 'no HTTP calls for SMTP' );
	}

	public function test_from_not_forced_keeps_plugin_address_but_replaces_wordpress_default() {
		global $phpmailer;
		Settings::update(
			array(
				'mailer'           => 'smtp',
				'smtp_host'        => 'smtp.example.net',
				'from_email'       => 'hello@example.net',
				'force_from_email' => false,
				'smtp_auth'        => false,
			)
		);
		wp_mail( 'to@example.org', 'A', 'Body', array( 'From: plugin@example.net' ) );
		$this->assertSame( 'plugin@example.net', $phpmailer->From );
		wp_mail( 'to@example.org', 'B', 'Body' );
		$this->assertSame( 'hello@example.net', $phpmailer->From );
	}

	public function test_secrets_are_encrypted_at_rest() {
		$this->use_microsoft();
		$raw = (string) wp_json_encode( get_option( Settings::OPTION ) );
		$this->assertStringNotContainsString( 'super~secret', $raw );
		$this->assertSame( 'super~secret', Settings::secret( 'ms_client_secret' ) );
	}

	public function test_log_keeps_content_only_when_enabled_and_can_resend() {
		global $phpmailer;
		Settings::update(
			array(
				'mailer'           => 'default',
				'log_keep_content' => true,
			)
		);
		$this->assertTrue( wp_mail( 'to@example.org', 'Keep', 'Secret body' ) );
		$log = Logger::query( '', 1, 10 );
		$row = Logger::find( (int) $log['rows'][0]['id'] );
		$this->assertSame( 'Secret body', $row['message'] );

		$this->assertTrue( Logger::resend( (int) $row['id'] ) );
		$log = Logger::query( '', 1, 10 );
		$this->assertSame( 2, $log['total'] );
		$this->assertSame( (string) $row['id'], (string) $log['rows'][0]['resend_of'] );
	}

	public function test_prune_by_count_and_age() {
		global $wpdb;
		Settings::update(
			array(
				'log_max_rows' => 100,
				'log_days'     => 30,
			)
		);
		$table = Logger::table();
		for ( $i = 0; $i < 120; $i++ ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table,
				array(
					'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
					'status'      => 'sent',
					'mailer'      => 'default',
					'to_addr'     => 'a@b.c',
					'subject'     => 'S' . $i,
				)
			);
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
				'status'      => 'sent',
				'mailer'      => 'default',
				'to_addr'     => 'old@b.c',
				'subject'     => 'Old',
			)
		);
		Logger::prune();
		$log = Logger::query( '', 1, 500 );
		$this->assertSame( 100, $log['total'] );
		$this->assertSame( 'S119', $log['rows'][0]['subject'] );
		$this->assertSame( 0, Logger::query( '', 1, 10, 'old@b.c' )['total'] );
	}
}
