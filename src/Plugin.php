<?php
/**
 * Plugin bootstrap.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP;

use IWorksIE\SMTP\Admin\Actions;
use IWorksIE\SMTP\Admin\Screens;
use IWorksIE\SMTP\Checks\ConflictsCheck;
use IWorksIE\SMTP\Checks\ConnectionCheck;
use IWorksIE\SMTP\Checks\CredentialsCheck;
use IWorksIE\SMTP\Checks\DkimCheck;
use IWorksIE\SMTP\Checks\DmarcCheck;
use IWorksIE\SMTP\Checks\FromAddressCheck;
use IWorksIE\SMTP\Checks\MailerCheck;
use IWorksIE\SMTP\Checks\RecentFailuresCheck;
use IWorksIE\SMTP\Checks\SecretExpiryCheck;
use IWorksIE\SMTP\Checks\SpfCheck;
use IWorksIE\SMTP\Cli\Command;
use IWorksIE\SMTP\Log\Alerts;
use IWorksIE\SMTP\Log\Logger;
use IWorksIE\SMTP\Mail\Router;
use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\SiteHealthBridge;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Core;

/**
 * Singleton plugin entry.
 */
final class Plugin {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @var Core|null
	 */
	private $core = null;

	/**
	 * @var bool
	 */
	private $mail_booted = false;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Capability for every screen and action.
	 *
	 * @return string
	 */
	public static function capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * @return Core|null
	 */
	public function core() {
		return $this->core;
	}

	/**
	 * Mail routing, logging and alerts (no admin UI dependency).
	 *
	 * @return void
	 */
	public function boot_mail() {
		if ( $this->mail_booted ) {
			return;
		}
		$this->mail_booted = true;

		( new Router() )->hooks();
		( new Logger() )->hooks();
		( new Alerts() )->hooks();

		if ( ! wp_next_scheduled( Logger::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Logger::CRON_HOOK );
		}
		Command::register();
	}

	/**
	 * Admin UI: core, checks, menu, Site Health.
	 *
	 * @return void
	 */
	public function boot() {
		if ( null !== $this->core ) {
			return;
		}

		$this->core = Core::boot(
			array(
				'plugin_file' => IWIESM_FILE,
				'slug'        => 'iworksie-smtp',
				'prefix'      => 'iwiesm',
				'name'        => __( 'SMTP', 'iworksie-smtp' ),
				'version'     => IWIESM_VERSION,
				'text_domain' => 'iworksie-smtp',
				'capability'  => self::capability(),
				'wporg_url'   => 'https://wordpress.org/plugins/iworksie-smtp/',
				'description' => __( 'Send email through Microsoft 365, Google or any SMTP server, with plain-English errors and deliverability checks.', 'iworksie-smtp' ),
				'icon'        => 'dashicons-email-alt',
				'tagline'     => __( 'Reliable email for WordPress. Nothing locked, no upsells, nothing sent through third parties.', 'iworksie-smtp' ),
			)
		);

		foreach ( $this->checks() as $check ) {
			$this->core->checks()->add( $check );
		}

		$screens = new Screens( $this->core );
		$this->core->menu()->add_submenu(
			__( 'SMTP', 'iworksie-smtp' ),
			__( 'SMTP', 'iworksie-smtp' ),
			'iworksie-smtp',
			array( $screens, 'render' )
		);

		$bridge = new SiteHealthBridge(
			$this->core->checks(),
			array( 'mailer_configured', 'secret_expiry', 'recent_failures' ),
			'iWorks.ie SMTP'
		);
		$bridge->hooks();

		( new Actions() )->hooks();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( IWIESM_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Plugin JS/CSS on our screen.
	 *
	 * @param string $hook Hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( null === $this->core || false === strpos( (string) $hook, 'iworksie-smtp' ) ) {
			return;
		}
		wp_enqueue_script( 'iwiesm-admin', IWIESM_URL . 'assets/js/admin.js', array( 'iwiesm-iwt-core' ), IWIESM_VERSION, true );
		wp_enqueue_style( 'iwiesm-admin', IWIESM_URL . 'assets/css/admin.css', array( 'iwiesm-iwt-core' ), IWIESM_VERSION );
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array<int|string,string> $links Links.
	 * @return array<int|string,string>
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=iworksie-smtp' ) ) . '">' . esc_html__( 'Settings', 'iworksie-smtp' ) . '</a>' );
		return $links;
	}

	/**
	 * @return array<int,object>
	 */
	private function checks() {
		$checks = array(
			new MailerCheck(),
			new ConnectionCheck(),
			new FromAddressCheck(),
			new SpfCheck(),
			new DkimCheck(),
			new DmarcCheck(),
		);
		// Only relevant (and only shown) when Microsoft 365 is the mailer.
		if ( 'microsoft' === Settings::mailer() ) {
			$checks[] = new SecretExpiryCheck();
		}
		$checks[] = new RecentFailuresCheck();
		$checks[] = new ConflictsCheck();
		$checks[] = new CredentialsCheck();
		return $checks;
	}

	/**
	 * Activation: table and cron.
	 *
	 * @param bool $network_wide Network activation.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				Logger::install();
				restore_current_blog();
			}
			return;
		}
		Logger::install();
	}

	/**
	 * Deactivation: stop cron (data is kept until uninstall).
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Logger::CRON_HOOK );
	}
}
