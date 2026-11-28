<?php
/**
 * Diagnostics checks, admin wiring and uninstall.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Tests\Integration;

use IWorksIE\SMTP\Checks\SecretExpiryCheck;
use IWorksIE\SMTP\Log\Logger;
use IWorksIE\SMTP\Plugin;
use IWorksIE\SMTP\Settings\Settings;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
final class ChecksAndLifecycleTest extends WP_UnitTestCase {

	/**
	 * Fake DNS.
	 *
	 * @var array<string,array<int,string>>
	 */
	private $dns = array();

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::flush();
		$this->dns = array();
		add_filter( 'iwiesm_pre_dns_txt', array( $this, 'fake_txt' ), 10, 2 );
		add_filter( 'iwiesm_pre_dns_cname', array( $this, 'fake_cname' ), 10, 2 );
		Plugin::instance()->boot();
	}

	public function tear_down() {
		remove_filter( 'iwiesm_pre_dns_txt', array( $this, 'fake_txt' ), 10 );
		remove_filter( 'iwiesm_pre_dns_cname', array( $this, 'fake_cname' ), 10 );
		delete_option( Settings::OPTION );
		Settings::flush();
		parent::tear_down();
	}

	/**
	 * @param mixed  $pre  Pre.
	 * @param string $name Name.
	 * @return array<int,string>
	 */
	public function fake_txt( $pre, $name ) {
		return isset( $this->dns[ $name ] ) ? $this->dns[ $name ] : array();
	}

	/**
	 * @param mixed  $pre  Pre.
	 * @param string $name Name.
	 * @return string
	 */
	public function fake_cname( $pre, $name ) {
		return isset( $this->dns[ 'CNAME:' . $name ] ) ? $this->dns[ 'CNAME:' . $name ][0] : '';
	}

	/**
	 * Run one registered check.
	 *
	 * @param string $id Check id.
	 * @return array<string,mixed>
	 */
	private function run_check( $id ) {
		$check = Plugin::instance()->core()->checks()->get( $id );
		$this->assertNotNull( $check, $id );
		return $check->run()->to_array();
	}

	public function test_default_mailer_is_critical() {
		$this->assertSame( 'critical', $this->run_check( 'mailer_configured' )['status'] );
	}

	public function test_dns_checks_for_microsoft_domain() {
		Settings::update(
			array(
				'mailer'     => 'microsoft',
				'ms_mailbox' => 'web@contoso.ie',
			)
		);
		$this->dns['contoso.ie']                       = array( 'v=spf1 include:spf.protection.outlook.com -all' );
		$this->dns['CNAME:selector1._domainkey.contoso.ie'] = array( 'selector1-contoso-ie._domainkey.contoso.onmicrosoft.com' );
		$this->dns['_dmarc.contoso.ie']                = array( 'v=DMARC1; p=none; rua=mailto:d@contoso.ie' );

		$this->assertSame( 'good', $this->run_check( 'spf' )['status'] );
		$this->assertSame( 'good', $this->run_check( 'dkim' )['status'] );
		$this->assertSame( 'recommended', $this->run_check( 'dmarc' )['status'] );
	}

	public function test_spf_problems() {
		Settings::update(
			array(
				'mailer'     => 'smtp',
				'smtp_host'  => 'smtp.sendgrid.net',
				'from_email' => 'hi@example.ie',
			)
		);
		$this->dns['example.ie'] = array( 'v=spf1 include:a.com ~all', 'v=spf1 include:b.com ~all' );
		$this->assertSame( 'critical', $this->run_check( 'spf' )['status'] );

		$this->dns['example.ie'] = array( 'v=spf1 include:a.com ~all' );
		$result                  = $this->run_check( 'spf' );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'sendgrid.net', $result['fix'] );

		$this->dns['example.ie'] = array();
		$this->assertSame( 'recommended', $this->run_check( 'spf' )['status'] );
		$this->assertSame( 'recommended', $this->run_check( 'dmarc' )['status'] );
		$this->assertSame( 'recommended', $this->run_check( 'dkim' )['status'] );
	}

	public function test_free_mailbox_from_is_critical() {
		Settings::update(
			array(
				'mailer'     => 'smtp',
				'smtp_host'  => 'smtp.example.net',
				'from_email' => 'someone@gmail.com',
			)
		);
		$this->assertSame( 'critical', $this->run_check( 'from_address' )['status'] );
	}

	public function test_secret_expiry_states() {
		Settings::update( array( 'mailer' => 'microsoft' ) );
		$this->assertSame( 'recommended', ( new SecretExpiryCheck() )->run()->to_array()['status'] );
		Settings::update( array( 'ms_secret_expiry' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS * 2 ) ) );
		$this->assertSame( 'critical', ( new SecretExpiryCheck() )->run()->to_array()['status'] );
		Settings::update( array( 'ms_secret_expiry' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS * 10 ) ) );
		$this->assertSame( 'recommended', ( new SecretExpiryCheck() )->run()->to_array()['status'] );
		Settings::update( array( 'ms_secret_expiry' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS * 200 ) ) );
		$this->assertSame( 'good', ( new SecretExpiryCheck() )->run()->to_array()['status'] );
	}

	public function test_unreadable_secret_is_flagged() {
		Settings::update( array( 'smtp_password' => 'iwie1:' . base64_encode( str_repeat( 'x', 60 ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertTrue( Settings::secret_unreadable( 'smtp_password' ) );
		$this->assertSame( 'critical', $this->run_check( 'stored_credentials' )['status'] );
	}

	public function test_every_check_runs_without_error() {
		foreach ( array( 'default', 'microsoft', 'google', 'smtp' ) as $mailer ) {
			Settings::update( array( 'mailer' => $mailer ) );
			foreach ( Plugin::instance()->core()->checks()->all() as $id => $check ) {
				$result = $check->run()->to_array();
				$this->assertContains( $result['status'], array( 'good', 'recommended', 'critical', 'info' ), $mailer . '/' . $id );
				$this->assertNotSame( '', $result['label'], $mailer . '/' . $id );
			}
		}
	}

	public function test_submenu_registered_under_iworks_tools() {
		global $submenu;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		do_action( 'admin_menu' );
		$slugs = isset( $submenu['iworks-tools'] ) ? wp_list_pluck( $submenu['iworks-tools'], 2 ) : array();
		$this->assertContains( 'iworksie-smtp', $slugs );
	}

	public function test_uninstall_removes_everything() {
		global $wpdb;
		Logger::install();
		Settings::update( array( 'mailer' => 'smtp' ) );
		set_transient( 'iwiesm_ms_token', 'x', 60 );
		update_option( 'iwiesm_last_failure', array( 'time' => time() ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'iworksie-smtp/iworksie-smtp.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( 'iwiesm_last_failure' ) );
		$this->assertFalse( get_transient( 'iwiesm_ms_token' ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Logger::table() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Logger::install();
	}
}
