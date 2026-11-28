<?php
/**
 * SPF / DMARC / DKIM parsing.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Tests\Unit;

use IWorksIE\SMTP\Support\Dns;

/**
 * @covers \IWorksIE\SMTP\Support\Dns
 */
final class DnsTest extends UnitTestCase {

	public function test_spf_records_found_among_txt() {
		$txt = array( 'google-site-verification=abc', 'v=spf1 include:spf.protection.outlook.com -all', 'MS=ms123' );
		$this->assertSame( array( 'v=spf1 include:spf.protection.outlook.com -all' ), Dns::spf_records( $txt ) );
	}

	public function test_spf_includes_and_all() {
		$spf = 'v=spf1 ip4:1.2.3.4 include:_spf.google.com ~all';
		$this->assertTrue( Dns::spf_includes( $spf, array( '_spf.google.com' ) ) );
		$this->assertFalse( Dns::spf_includes( $spf, array( 'spf.protection.outlook.com' ) ) );
		$this->assertFalse( Dns::spf_includes( 'v=spf1 include:x_spf.google.com ~all', array( '_spf.google.com' ) ) );
		$this->assertSame( '~', Dns::spf_all( $spf ) );
		$this->assertSame( '+', Dns::spf_all( 'v=spf1 a mx all' ) );
		$this->assertSame( '-', Dns::spf_all( 'v=spf1 -all' ) );
		$this->assertSame( '', Dns::spf_all( 'v=spf1 include:a.com' ) );
	}

	public function test_dmarc_parsing() {
		$records = Dns::dmarc_records( array( 'v=DMARC1; p=quarantine; rua=mailto:x@example.com', 'other' ) );
		$this->assertCount( 1, $records );
		$tags = Dns::dmarc_tags( $records[0] );
		$this->assertSame( 'quarantine', $tags['p'] );
	}

	public function test_dmarc_candidates_walk_to_parent() {
		$this->assertSame( array( 'mail.example.co.uk', 'example.co.uk', 'co.uk' ), Dns::dmarc_candidates( 'mail.example.co.uk' ) );
		$this->assertSame( array( 'example.com' ), Dns::dmarc_candidates( 'example.com' ) );
	}

	public function test_dkim_key_detection() {
		$this->assertTrue( Dns::has_dkim_key( array( 'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA' ) ) );
		$this->assertFalse( Dns::has_dkim_key( array( 'v=DKIM1; p=' ) ) );
	}

	public function test_provider_lookup() {
		$this->assertSame( 'Microsoft 365', Dns::provider( 'microsoft' )['name'] );
		$this->assertSame( 'SendGrid', Dns::provider( 'smtp', 'smtp.sendgrid.net' )['name'] );
		$this->assertSame( 'Amazon SES', Dns::provider( 'smtp', 'email-smtp.eu-west-1.amazonaws.com' )['name'] );
		$this->assertNull( Dns::provider( 'smtp', 'mail.example.com' ) );
		$this->assertNull( Dns::provider( 'default' ) );
	}

	public function test_domain_of() {
		$this->assertSame( 'example.com', Dns::domain_of( 'Hello@Example.com' ) );
		$this->assertSame( '', Dns::domain_of( 'nope' ) );
	}
}
