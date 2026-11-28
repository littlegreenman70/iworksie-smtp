<?php
/**
 * Encryption round trip, ID token parsing, SMTP transcript redaction.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Tests\Unit;

use IWorksIE\SMTP\Mail\Router;
use IWorksIE\SMTP\Mail\Transport\Gmail;
use IWorksIE\SMTP\Support\Crypto;

/**
 * @covers \IWorksIE\SMTP\Support\Crypto
 * @covers \IWorksIE\SMTP\Mail\Transport\Gmail::email_from_id_token
 * @covers \IWorksIE\SMTP\Mail\Router::redact
 */
final class CryptoAndHelpersTest extends UnitTestCase {

	public function test_crypto_round_trip_and_tamper() {
		if ( ! defined( 'LOGGED_IN_KEY' ) ) {
			define( 'LOGGED_IN_KEY', 'unit-key' );
			define( 'LOGGED_IN_SALT', 'unit-salt' );
		}
		$enc = Crypto::encrypt( 'p@ss w0rd "quoted" ñ' );
		$this->assertStringStartsWith( Crypto::PREFIX, $enc );
		$this->assertStringNotContainsString( 'p@ss', $enc );
		$this->assertSame( 'p@ss w0rd "quoted" ñ', Crypto::decrypt( $enc ) );
		$this->assertNotSame( $enc, Crypto::encrypt( 'p@ss w0rd "quoted" ñ' ), 'nonce must be random' );

		$tampered = substr( $enc, 0, -4 ) . 'AAAA';
		$this->assertSame( '', Crypto::decrypt( $tampered ) );
		$this->assertSame( '', Crypto::decrypt( 'plain-text' ) );
		$this->assertSame( '', Crypto::encrypt( '' ) );
	}

	public function test_email_from_id_token() {
		\Brain\Monkey\Functions\when( 'sanitize_email' )->returnArg( 1 );
		$payload = rtrim( strtr( base64_encode( wp_json( array( 'email' => 'me@example.com' ) ) ), '+/', '-_' ), '=' );
		$this->assertSame( 'me@example.com', Gmail::email_from_id_token( 'h.' . $payload . '.sig' ) );
		$this->assertSame( '', Gmail::email_from_id_token( 'garbage' ) );
	}

	public function test_redact_hides_auth_payloads() {
		$this->assertSame( 'CLIENT -> SERVER: AUTH PLAIN [hidden]', Router::redact( 'CLIENT -> SERVER: AUTH PLAIN AHVzZXIAcGFzcw==' ) );
		$this->assertSame( 'CLIENT -> SERVER: AUTH LOGIN [hidden]', Router::redact( 'CLIENT -> SERVER: AUTH LOGIN' ) );
		$this->assertSame( 'CLIENT -> SERVER: [hidden]', Router::redact( 'CLIENT -> SERVER: dXNlcg==' ) );
		$this->assertSame( 'CLIENT -> SERVER: [hidden]', Router::redact( 'CLIENT -> SERVER: cGFzcw==' ) );
		$this->assertSame( 'CLIENT -> SERVER: MAIL FROM:<a@b.c>', Router::redact( 'CLIENT -> SERVER: MAIL FROM:<a@b.c>' ) );
	}
}

/**
 * JSON helper without WordPress.
 *
 * @param array<string,string> $data Data.
 * @return string
 */
function wp_json( array $data ) {
	return (string) json_encode( $data );
}
