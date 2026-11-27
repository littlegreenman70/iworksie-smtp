<?php
/**
 * Settings sanitising.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Tests\Unit;

use IWorksIE\SMTP\Settings\Settings;

/**
 * @covers \IWorksIE\SMTP\Settings\Settings
 */
final class SettingsSanitizeTest extends UnitTestCase {

	public function test_sanitize_clamps_and_validates() {
		$out = Settings::sanitize(
			array(
				'mailer'          => 'evil',
				'smtp_host'       => ' https://SMTP.Example.com:587/path ',
				'smtp_port'       => '99999',
				'smtp_encryption' => 'starttls',
				'ms_tenant_id'    => 'contoso.onmicrosoft.com',
				'ms_client_id'    => 'not-a-guid',
				'ms_secret_expiry' => '2026-02-30',
				'log_days'        => '0',
				'log_max_rows'    => '5',
				'dkim_selector'   => 'S1;drop',
			)
		);
		$this->assertSame( 'default', $out['mailer'] );
		$this->assertSame( 'smtp.example.com', $out['smtp_host'] );
		$this->assertSame( 587, $out['smtp_port'] );
		$this->assertSame( 'tls', $out['smtp_encryption'] );
		$this->assertSame( 'contoso.onmicrosoft.com', $out['ms_tenant_id'] );
		$this->assertSame( '', $out['ms_client_id'] );
		$this->assertSame( '', $out['ms_secret_expiry'] );
		$this->assertSame( 1, $out['log_days'] );
		$this->assertSame( 100, $out['log_max_rows'] );
		$this->assertSame( 's1drop', $out['dkim_selector'] );
		$this->assertFalse( $out['force_from_email'] );
		$this->assertArrayNotHasKey( 'smtp_password', $out );
	}

	public function test_guid_and_date() {
		$this->assertSame( '1a2b3c4d-0000-4000-8000-123456789abc', Settings::sanitize_guid( ' 1A2B3C4D-0000-4000-8000-123456789ABC ' ) );
		$this->assertSame( '2027-01-31', Settings::sanitize_date( '2027-01-31' ) );
	}
}
