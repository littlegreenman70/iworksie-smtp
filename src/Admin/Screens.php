<?php
/**
 * Admin screens: Setup, Send a test, Email log, Diagnostics.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Admin;

use IWorksIE\SMTP\Log\Logger;
use IWorksIE\SMTP\Mail\Transport\Gmail;
use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Support\ErrorTranslator;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Core;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\UI;

/**
 * Renders the plugin page inside the shared iWorks Tools chrome.
 */
final class Screens {

	/**
	 * @var Core
	 */
	private $core;

	/**
	 * @param Core $core Core.
	 */
	public function __construct( Core $core ) {
		$this->core = $core;
	}

	/**
	 * Display names of the mailers.
	 *
	 * @return array<string,string>
	 */
	public static function mailer_names() {
		return array(
			'default'   => __( 'WordPress default (PHP mail)', 'iworksie-smtp' ),
			'microsoft' => __( 'Microsoft 365', 'iworksie-smtp' ),
			'google'    => __( 'Google Workspace / Gmail', 'iworksie-smtp' ),
			'smtp'      => __( 'Other SMTP', 'iworksie-smtp' ),
		);
	}

	/**
	 * "Sent" / "Failed" badge in the shared badge style.
	 *
	 * @param bool $sent Sent.
	 * @return string
	 */
	public static function sent_badge( $sent ) {
		return '<span class="iwt-badge iwt-badge--' . ( $sent ? 'good' : 'critical' ) . '">' . esc_html( $sent ? __( 'Sent', 'iworksie-smtp' ) : __( 'Failed', 'iworksie-smtp' ) ) . '</span>';
	}

	/**
	 * Menu callback.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( (string) $this->core->config( 'capability' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'iworksie-smtp' ) );
		}

		$tabs = array(
			'setup'       => __( 'Setup', 'iworksie-smtp' ),
			'test'        => __( 'Send a test', 'iworksie-smtp' ),
			'log'         => __( 'Email log', 'iworksie-smtp' ),
			'diagnostics' => __( 'Diagnostics', 'iworksie-smtp' ),
		);

		$this->core->page()->render(
			array(
				'title'   => (string) $this->core->config( 'name' ),
				'version' => (string) $this->core->config( 'version' ),
				'tagline' => (string) $this->core->config( 'tagline' ),
				'tabs'    => $tabs,
			),
			function ( $active ) {
				$flash = Actions::take_flash();
				if ( null !== $flash && empty( $flash['test'] ) ) {
					$this->render_flash( $flash );
				}
				switch ( $active ) {
					case 'test':
						$this->render_test( $flash );
						break;
					case 'log':
						$this->render_log();
						break;
					case 'diagnostics':
						$this->render_diagnostics();
						break;
					default:
						$this->render_setup();
						break;
				}
			}
		);
	}

	/**
	 * Plain notice, with explanation when it carries a raw error.
	 *
	 * @param array<string,mixed> $flash Flash data.
	 * @return void
	 */
	private function render_flash( array $flash ) {
		$type = in_array( $flash['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? (string) $flash['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' iwiesm-notice"><p><strong>' . esc_html( (string) $flash['message'] ) . '</strong></p>';
		if ( ! empty( $flash['raw'] ) ) {
			$this->render_explanation( (string) $flash['raw'], 'google' );
		}
		echo '</div>';
	}

	/**
	 * Plain-English explanation block.
	 *
	 * @param string $raw    Raw error.
	 * @param string $mailer Mailer.
	 * @return void
	 */
	private function render_explanation( $raw, $mailer ) {
		$explained = ErrorTranslator::explain( $raw, $mailer );
		echo '<div class="iwiesm-explain">';
		echo '<h3>' . esc_html( $explained['title'] ) . '</h3>';
		echo '<p>' . esc_html( $explained['explain'] ) . '</p>';
		echo '<h4>' . esc_html__( 'How to fix it', 'iworksie-smtp' ) . '</h4>';
		echo '<p>' . esc_html( $explained['fix'] ) . '</p>';
		echo '<details><summary>' . esc_html__( 'Technical message', 'iworksie-smtp' ) . '</summary><pre class="iwiesm-pre">' . esc_html( $raw ) . '</pre></details>';
		echo '</div>';
	}

	// ---------------------------------------------------------------------
	// Setup.
	// ---------------------------------------------------------------------

	/**
	 * Setup tab.
	 *
	 * @return void
	 */
	private function render_setup() {
		$s      = Settings::all();
		$mailer = Settings::mailer();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="iwiesm-form" novalidate>';
		wp_nonce_field( 'iwiesm_save_settings' );
		echo '<input type="hidden" name="action" value="iwiesm_save_settings" />';

		// Step 1: mailer.
		echo '<section class="iwiesm-section">';
		echo '<h2>' . esc_html__( '1. How should this site send email?', 'iworksie-smtp' ) . '</h2>';
		echo '<fieldset class="iwiesm-mailers"><legend class="screen-reader-text">' . esc_html__( 'Mailer', 'iworksie-smtp' ) . '</legend>';
		$descriptions = array(
			'microsoft' => __( 'Your Microsoft 365 / Outlook business mailbox, through the Microsoft Graph API. No SMTP password, no redirect loop.', 'iworksie-smtp' ),
			'google'    => __( 'Google Workspace or a Gmail account, through the Gmail API with Google sign-in.', 'iworksie-smtp' ),
			'smtp'      => __( 'Any SMTP server: your host, Brevo, Mailgun, SendGrid, Amazon SES, Postmark, Zoho and others.', 'iworksie-smtp' ),
			'default'   => __( 'Leave email to the web server. Often blocked or sent to spam.', 'iworksie-smtp' ),
		);
		$names        = self::mailer_names();
		foreach ( array( 'microsoft', 'google', 'smtp', 'default' ) as $id ) {
			echo '<label class="iwiesm-mailer' . ( $id === $mailer ? ' is-selected' : '' ) . '">';
			echo '<input type="radio" name="iwiesm[mailer]" value="' . esc_attr( $id ) . '"' . checked( $id, $mailer, false ) . ' data-iwiesm-mailer />';
			echo '<span class="iwiesm-mailer__name">' . esc_html( $names[ $id ] ) . '</span>';
			echo '<span class="iwiesm-mailer__desc">' . esc_html( $descriptions[ $id ] ) . '</span>';
			echo '</label>';
		}
		echo '</fieldset></section>';

		// Step 2: mailer details.
		echo '<section class="iwiesm-section">';
		echo '<h2>' . esc_html__( '2. Connect it', 'iworksie-smtp' ) . '</h2>';
		$this->panel_microsoft( $s, $mailer );
		$this->panel_google( $s, $mailer );
		$this->panel_smtp( $s, $mailer );
		echo '<div class="iwiesm-panel" data-iwiesm-panel="default"' . ( 'default' === $mailer ? '' : ' hidden' ) . '>';
		echo '<p>' . esc_html__( 'Nothing to connect. WordPress will hand emails to the web server, as it does without this plugin. The email log and alerts below still work.', 'iworksie-smtp' ) . '</p>';
		echo '</div>';
		echo '</section>';

		// Step 3: From.
		echo '<section class="iwiesm-section">';
		echo '<h2>' . esc_html__( '3. Who emails come from', 'iworksie-smtp' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row_text(
			'from_email',
			__( 'From address', 'iworksie-smtp' ),
			(string) $s['from_email'],
			'email',
			'microsoft' === $mailer
				? __( 'With Microsoft 365, emails are always sent from the mailbox above, so this is not used.', 'iworksie-smtp' )
				: __( 'A real address on your own domain, for example hello@yourdomain.com. With Google, leave it empty to use the connected account.', 'iworksie-smtp' )
		);
		$this->row_checkbox( 'force_from_email', __( 'Force From address', 'iworksie-smtp' ), (bool) $s['force_from_email'], __( 'Use this address for every email, even when another plugin sets its own. Recommended: mail services reject addresses you do not own. Replies still go to the Reply-To a form sets.', 'iworksie-smtp' ) );
		$this->row_text( 'from_name', __( 'From name', 'iworksie-smtp' ), (string) $s['from_name'], 'text', __( 'For example your business name. Used when a plugin does not set its own name (or always, if forced below).', 'iworksie-smtp' ) );
		$this->row_checkbox( 'force_from_name', __( 'Force From name', 'iworksie-smtp' ), (bool) $s['force_from_name'], __( 'Use this name for every email.', 'iworksie-smtp' ) );
		$this->row_checkbox( 'return_path', __( 'Return path (SMTP only)', 'iworksie-smtp' ), (bool) $s['return_path'], __( 'Send bounce messages to the From address. Helps SPF alignment with most SMTP providers.', 'iworksie-smtp' ) );
		$this->row_text( 'dkim_selector', __( 'DKIM selector (optional)', 'iworksie-smtp' ), (string) $s['dkim_selector'], 'text', __( 'Only for Diagnostics. If your SMTP provider gave you a DKIM record like abc._domainkey.yourdomain.com, enter abc. Microsoft 365 and Google are detected automatically.', 'iworksie-smtp' ) );
		echo '</tbody></table></section>';

		// Step 4: Log and alerts.
		echo '<section class="iwiesm-section">';
		echo '<h2>' . esc_html__( '4. Email log and alerts', 'iworksie-smtp' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row_checkbox( 'log_enabled', __( 'Keep an email log', 'iworksie-smtp' ), (bool) $s['log_enabled'], __( 'Records the date, recipient, subject, result and error for each email.', 'iworksie-smtp' ) );
		$this->row_checkbox( 'log_keep_content', __( 'Also keep message content', 'iworksie-smtp' ), (bool) $s['log_keep_content'], __( 'Needed to resend an email from the log. Off by default because emails such as password resets contain private links.', 'iworksie-smtp' ) );
		$this->row_number( 'log_days', __( 'Delete log entries after', 'iworksie-smtp' ), (int) $s['log_days'], 1, 365, __( 'days', 'iworksie-smtp' ) );
		$this->row_number( 'log_max_rows', __( 'Keep at most', 'iworksie-smtp' ), (int) $s['log_max_rows'], 100, 100000, __( 'entries (oldest are deleted first)', 'iworksie-smtp' ) );
		$this->row_checkbox( 'alerts_enabled', __( 'Email me when sending fails', 'iworksie-smtp' ), (bool) $s['alerts_enabled'], __( 'At most one alert per hour, sent with the server\'s own mail() so it does not depend on the connection that failed. With Microsoft 365, also warns two weeks before the client secret expires.', 'iworksie-smtp' ) );
		$this->row_text( 'alert_email', __( 'Alert address', 'iworksie-smtp' ), (string) $s['alert_email'], 'email', __( 'Leave empty to use the site admin email. Use an address on a different provider if you can.', 'iworksie-smtp' ) );
		echo '</tbody></table></section>';

		submit_button( __( 'Save settings', 'iworksie-smtp' ) );
		echo '</form>';
	}

	/**
	 * Microsoft 365 panel with the guided steps.
	 *
	 * @param array<string,mixed> $s      Settings.
	 * @param string              $mailer Active mailer.
	 * @return void
	 */
	private function panel_microsoft( array $s, $mailer ) {
		echo '<div class="iwiesm-panel" data-iwiesm-panel="microsoft"' . ( 'microsoft' === $mailer ? '' : ' hidden' ) . '>';
		echo '<div class="iwiesm-guide">';
		echo '<h3>' . esc_html__( 'Set up Microsoft 365 (about 10 minutes, needs a Microsoft 365 admin)', 'iworksie-smtp' ) . '</h3>';
		echo '<ol>';
		echo '<li>' . wp_kses( __( 'Sign in to the <a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener noreferrer">Microsoft Entra admin centre</a> and go to <strong>App registrations → New registration</strong>.', 'iworksie-smtp' ), self::kses_guide() ) . '</li>';
		/* translators: %s: suggested app name */
		echo '<li>' . wp_kses( sprintf( __( 'Name it something you will recognise, for example <code>%s</code>. Choose <strong>Accounts in this organizational directory only</strong>. Leave Redirect URI empty. Click Register.', 'iworksie-smtp' ), esc_html( 'Website email - ' . (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ), self::kses_guide() ) . '</li>';
		echo '<li>' . wp_kses( __( 'On the app\'s <strong>Overview</strong> page, copy the <strong>Application (client) ID</strong> and the <strong>Directory (tenant) ID</strong> into the fields below.', 'iworksie-smtp' ), self::kses_guide() ) . '</li>';
		echo '<li>' . wp_kses( __( 'Go to <strong>Certificates &amp; secrets → Client secrets → New client secret</strong>. Pick the longest expiry offered. Copy the <strong>Value</strong> column straight away (not the Secret ID; the Value is only shown once) and note the <strong>Expires</strong> date.', 'iworksie-smtp' ), self::kses_guide() ) . '</li>';
		echo '<li>' . wp_kses( __( 'Go to <strong>API permissions → Add a permission → Microsoft Graph → Application permissions</strong>, tick <strong>Mail.Send</strong>, and click Add. Then click <strong>Grant admin consent for &lt;your organisation&gt;</strong> and check the status shows a green tick.', 'iworksie-smtp' ), self::kses_guide() ) . '</li>';
		echo '<li>' . wp_kses( __( 'Recommended: Mail.Send as an application permission can send as any mailbox in your organisation. Ask your admin to limit the app to the one mailbox below with <a href="https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac" target="_blank" rel="noopener noreferrer">RBAC for Applications in Exchange Online</a>.', 'iworksie-smtp' ), self::kses_guide() ) . '</li>';
		echo '</ol>';
		echo '<p class="description">' . esc_html__( 'Why not SMTP? Microsoft is retiring password sign-in over SMTP. The Graph API is Microsoft\'s supported way for apps to send mail, and this setup never needs a browser redirect, so there is no sign-in loop to get stuck in.', 'iworksie-smtp' ) . '</p>';
		echo '</div>';

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row_text( 'ms_tenant_id', __( 'Directory (tenant) ID', 'iworksie-smtp' ), (string) $s['ms_tenant_id'], 'text', __( 'A GUID like 1a2b3c4d-..., or yourcompany.onmicrosoft.com.', 'iworksie-smtp' ) );
		$this->row_text( 'ms_client_id', __( 'Application (client) ID', 'iworksie-smtp' ), (string) $s['ms_client_id'], 'text', '' );
		$this->row_secret( 'ms_client_secret', __( 'Client secret value', 'iworksie-smtp' ) );
		$this->row_text( 'ms_secret_expiry', __( 'Client secret expires on', 'iworksie-smtp' ), (string) $s['ms_secret_expiry'], 'date', __( 'From the Expires column. We email you two weeks before it runs out.', 'iworksie-smtp' ) );
		$this->row_text( 'ms_mailbox', __( 'Send from mailbox', 'iworksie-smtp' ), (string) $s['ms_mailbox'], 'email', __( 'The primary address of a licensed user or a shared mailbox, for example hello@yourdomain.com. Every email is sent from this address.', 'iworksie-smtp' ) );
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Google panel with the guided steps.
	 *
	 * @param array<string,mixed> $s      Settings.
	 * @param string              $mailer Active mailer.
	 * @return void
	 */
	private function panel_google( array $s, $mailer ) {
		$redirect = Gmail::redirect_uri();
		echo '<div class="iwiesm-panel" data-iwiesm-panel="google"' . ( 'google' === $mailer ? '' : ' hidden' ) . '>';

		if ( Gmail::is_configured() ) {
			echo '<div class="iwiesm-connected"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ';
			echo esc_html(
				sprintf(
					/* translators: %s: Google account email */
					__( 'Connected as %s', 'iworksie-smtp' ),
					'' !== (string) $s['google_email'] ? (string) $s['google_email'] : __( 'your Google account', 'iworksie-smtp' )
				)
			);
			echo ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=iwiesm_google_disconnect' ), 'iwiesm_google_disconnect' ) ) . '">' . esc_html__( 'Disconnect', 'iworksie-smtp' ) . '</a>';
			echo '</div>';
		}

		echo '<div class="iwiesm-guide">';
		echo '<h3>' . esc_html__( 'Set up Google (about 10 minutes)', 'iworksie-smtp' ) . '</h3>';
		echo '<ol>';
		echo '<li>' . wp_kses( __( 'Open the <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener noreferrer">Google Cloud console</a>, signed in as the account that will send the email, and create a project (for example "Website email").', 'iworksie-smtp' ), self::kses_guide() ) . '</li>';
		echo '<li>' . wp_kses( __( 'Go to <strong>APIs &amp; Services → Library</strong>, search for <strong>Gmail API</strong> and click <strong>Enable</strong>.', 'iworksie-smtp' ), self::kses_guide() ) . '</li>';
		echo '<li>' . wp_kses( __( 'Go to <strong>Google Auth Platform</strong> (OAuth consent screen) and click Get started. For <strong>Audience</strong>: with Google Workspace choose <strong>Internal</strong>. With a personal Gmail account choose <strong>External</strong>, then on the Audience page click <strong>Publish app</strong> so it is "In production". Apps left in "Testing" are disconnected by Google after 7 days.', 'iworksie-smtp' ), self::kses_guide() ) . '</li>';
		echo '<li>' . wp_kses( __( 'Go to <strong>Clients → Create client</strong>, choose <strong>Web application</strong>, and under <strong>Authorised redirect URIs</strong> add exactly this address:', 'iworksie-smtp' ), self::kses_guide() );
		echo '<span class="iwiesm-copy-row"><code id="iwiesm-redirect-uri">' . esc_html( $redirect ) . '</code> ' . UI::copy_button( 'iwiesm-redirect-uri' ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UI escapes.
		echo '<li>' . wp_kses( __( 'Click Create, copy the <strong>Client ID</strong> and <strong>Client secret</strong> into the fields below, and save.', 'iworksie-smtp' ), self::kses_guide() ) . '</li>';
		echo '<li>' . wp_kses( __( 'Click <strong>Connect with Google</strong> and approve "Send email on your behalf". With External apps Google shows "Google hasn\'t verified this app": click <strong>Advanced → Go to (your app)</strong>. That is expected, because the app is yours.', 'iworksie-smtp' ), self::kses_guide() ) . '</li>';
		echo '</ol>';
		echo '<p class="description">' . esc_html__( 'Your credentials stay on this site. Emails go straight from your server to Google; nothing passes through iWorks.', 'iworksie-smtp' ) . '</p>';
		echo '</div>';

		echo '<table class="form-table" role="presentation"><tbody>';
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- help text naming Google's client-id suffix, not a CDN URL.
		$this->row_text( 'google_client_id', __( 'Client ID', 'iworksie-smtp' ), (string) $s['google_client_id'], 'text', __( 'Ends in .apps.googleusercontent.com', 'iworksie-smtp' ) );
		$this->row_secret( 'google_client_secret', __( 'Client secret', 'iworksie-smtp' ) );
		echo '</tbody></table>';

		if ( Gmail::has_client() && ! Gmail::is_configured() ) {
			echo '<p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=iwiesm_google_connect' ), 'iwiesm_google_connect' ) ) . '">' . esc_html__( 'Connect with Google', 'iworksie-smtp' ) . '</a></p>';
		} elseif ( ! Gmail::has_client() ) {
			echo '<p class="description">' . esc_html__( 'Save the client ID and secret, then a "Connect with Google" button appears here.', 'iworksie-smtp' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Other SMTP panel.
	 *
	 * @param array<string,mixed> $s      Settings.
	 * @param string              $mailer Active mailer.
	 * @return void
	 */
	private function panel_smtp( array $s, $mailer ) {
		echo '<div class="iwiesm-panel" data-iwiesm-panel="smtp"' . ( 'smtp' === $mailer ? '' : ' hidden' ) . '>';
		echo '<p class="iwiesm-presets"><span>' . esc_html__( 'Fill in for:', 'iworksie-smtp' ) . '</span> ';
		$presets = array(
			'brevo'    => array( 'Brevo', 'smtp-relay.brevo.com', 587, 'tls' ),
			'mailgun'  => array( 'Mailgun', 'smtp.mailgun.org', 587, 'tls' ),
			'sendgrid' => array( 'SendGrid', 'smtp.sendgrid.net', 587, 'tls' ),
			'postmark' => array( 'Postmark', 'smtp.postmarkapp.com', 587, 'tls' ),
			'ses'      => array( 'Amazon SES (eu-west-1)', 'email-smtp.eu-west-1.amazonaws.com', 587, 'tls' ), // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- SMTP host preset, not a CDN URL.
			'zoho'     => array( 'Zoho (EU)', 'smtp.zoho.eu', 465, 'ssl' ),
		);
		foreach ( $presets as $key => $p ) {
			echo '<button type="button" class="button button-small" data-iwiesm-preset="' . esc_attr( $key ) . '" data-host="' . esc_attr( $p[1] ) . '" data-port="' . esc_attr( (string) $p[2] ) . '" data-enc="' . esc_attr( $p[3] ) . '">' . esc_html( $p[0] ) . '</button> ';
		}
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Using Microsoft 365 or Gmail? Choose them above instead: both are retiring password sign-in over SMTP.', 'iworksie-smtp' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row_text( 'smtp_host', __( 'SMTP host', 'iworksie-smtp' ), (string) $s['smtp_host'], 'text', '' );
		echo '<tr><th scope="row"><label for="iwiesm-smtp_encryption">' . esc_html__( 'Encryption', 'iworksie-smtp' ) . '</label></th><td>';
		echo '<select id="iwiesm-smtp_encryption" name="iwiesm[smtp_encryption]">';
		$encs = array(
			'tls'  => __( 'TLS / STARTTLS (usually port 587)', 'iworksie-smtp' ),
			'ssl'  => __( 'SSL (usually port 465)', 'iworksie-smtp' ),
			'none' => __( 'None (upgrades to TLS if the server offers it)', 'iworksie-smtp' ),
		);
		foreach ( $encs as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, (string) $s['smtp_encryption'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		$this->row_number( 'smtp_port', __( 'Port', 'iworksie-smtp' ), (int) $s['smtp_port'], 1, 65535, '' );
		$this->row_checkbox( 'smtp_auth', __( 'Sign in', 'iworksie-smtp' ), (bool) $s['smtp_auth'], __( 'Almost every provider needs a username and password (or API key).', 'iworksie-smtp' ) );
		$this->row_text( 'smtp_username', __( 'Username', 'iworksie-smtp' ), (string) $s['smtp_username'], 'text', '' );
		$this->row_secret( 'smtp_password', __( 'Password or API key', 'iworksie-smtp' ) );
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Allowed tags in guide steps.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function kses_guide() {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'code'   => array(),
			'em'     => array(),
		);
	}

	/**
	 * Text-like input row.
	 *
	 * @param string $key      Setting key.
	 * @param string $label    Label.
	 * @param string $value    Value.
	 * @param string $type     Input type.
	 * @param string $help     Help text.
	 * @return void
	 */
	private function row_text( $key, $label, $value, $type, $help ) {
		$id = 'iwiesm-' . $key;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="iwiesm[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="off"' . ( '' !== $help ? ' aria-describedby="' . esc_attr( $id ) . '-help"' : '' ) . ' />';
		if ( '' !== $help ) {
			echo '<p class="description" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Number input row.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param int    $value Value.
	 * @param int    $min   Min.
	 * @param int    $max   Max.
	 * @param string $unit  Text after the field.
	 * @return void
	 */
	private function row_number( $key, $label, $value, $min, $max, $unit ) {
		$id = 'iwiesm-' . $key;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" id="' . esc_attr( $id ) . '" name="iwiesm[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" class="' . ( $max > 9999 ? 'regular-text iwiesm-number' : 'small-text' ) . '" /> ' . esc_html( $unit );
		echo '</td></tr>';
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $key     Key.
	 * @param string $label   Label.
	 * @param bool   $checked Checked.
	 * @param string $help    Help.
	 * @return void
	 */
	private function row_checkbox( $key, $label, $checked, $help ) {
		$id = 'iwiesm-' . $key;
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="iwiesm[' . esc_attr( $key ) . ']" value="1"' . checked( $checked, true, false ) . ' /> ' . esc_html( $help ) . '</label>';
		echo '</td></tr>';
	}

	/**
	 * Secret row: never prints the saved value.
	 *
	 * @param string $key   Secret key.
	 * @param string $label Label.
	 * @return void
	 */
	private function row_secret( $key, $label ) {
		$id       = 'iwiesm-' . $key;
		$constant = Settings::secret_constant( $key );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( '' !== $constant ) {
			echo '<input type="password" id="' . esc_attr( $id ) . '" value="" class="regular-text" disabled placeholder="' . esc_attr__( 'Set in wp-config.php', 'iworksie-smtp' ) . '" />';
			/* translators: %s: constant name */
			echo '<p class="description">' . esc_html( sprintf( __( 'Defined by the %s constant in wp-config.php.', 'iworksie-smtp' ), $constant ) ) . '</p>';
		} else {
			$saved       = Settings::has_secret( $key );
			$unreadable  = Settings::secret_unreadable( $key );
			$placeholder = $saved ? __( 'Saved. Leave empty to keep it.', 'iworksie-smtp' ) : '';
			echo '<input type="password" id="' . esc_attr( $id ) . '" name="iwiesm[' . esc_attr( $key ) . ']" value="" class="regular-text" autocomplete="new-password" spellcheck="false" placeholder="' . esc_attr( $placeholder ) . '" />';
			if ( $unreadable ) {
				echo '<p class="description iwiesm-warning">' . esc_html__( 'The saved value can no longer be decrypted (the site\'s security keys changed). Please enter it again.', 'iworksie-smtp' ) . '</p>';
			} elseif ( $saved ) {
				echo '<p class="description"><label><input type="checkbox" name="iwiesm[' . esc_attr( $key ) . '_clear]" value="1" /> ' . esc_html__( 'Remove the saved value', 'iworksie-smtp' ) . '</label></p>';
			}
		}
		echo '</td></tr>';
	}

	// ---------------------------------------------------------------------
	// Test.
	// ---------------------------------------------------------------------

	/**
	 * Send-a-test tab.
	 *
	 * @param array<string,mixed>|null $flash Flash with test result.
	 * @return void
	 */
	private function render_test( $flash ) {
		$names  = self::mailer_names();
		$mailer = Settings::mailer();
		$to     = (string) get_user_meta( get_current_user_id(), 'iwiesm_test_to', true );
		if ( '' === $to ) {
			$user = wp_get_current_user();
			$to   = (string) $user->user_email;
		}

		if ( null !== $flash && ! empty( $flash['test'] ) ) {
			$this->render_test_result( $flash );
		}

		echo '<div class="iwt-card iwiesm-card">';
		/* translators: %s: mailer name */
		echo '<p>' . esc_html( sprintf( __( 'Currently sending through: %s', 'iworksie-smtp' ), $names[ $mailer ] ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'iwiesm_send_test' );
		echo '<input type="hidden" name="action" value="iwiesm_send_test" />';
		echo '<p><label for="iwiesm-test-to"><strong>' . esc_html__( 'Send a test email to', 'iworksie-smtp' ) . '</strong></label><br />';
		echo '<input type="email" id="iwiesm-test-to" name="to" value="' . esc_attr( $to ) . '" class="regular-text" required /></p>';
		echo '<p><label><input type="checkbox" name="html" value="1" /> ' . esc_html__( 'Send as HTML', 'iworksie-smtp' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Tip: send it to an address at a different provider (for example a Gmail address if you send from Microsoft 365) to see whether it lands in the inbox or spam.', 'iworksie-smtp' ) . '</p>';
		submit_button( __( 'Send test email', 'iworksie-smtp' ), 'primary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Result of the last test.
	 *
	 * @param array<string,mixed> $flash Flash.
	 * @return void
	 */
	private function render_test_result( array $flash ) {
		$ok     = 'sent' === $flash['test'];
		$mailer = isset( $flash['mailer'] ) ? (string) $flash['mailer'] : '';
		echo '<div class="iwiesm-result iwiesm-result--' . ( $ok ? 'good' : 'critical' ) . '" role="status">';
		echo '<p class="iwiesm-result__headline">' . self::sent_badge( $ok ) . ' <strong>' . esc_html( (string) $flash['message'] ) . '</strong></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in sent_badge().
		if ( $ok ) {
			echo '<p>' . esc_html__( '"Sent" means the mail service accepted it. Check the inbox (and the spam folder) to confirm delivery. If it went to spam, run Diagnostics.', 'iworksie-smtp' ) . '</p>';
		} else {
			$this->render_explanation( isset( $flash['raw'] ) ? (string) $flash['raw'] : '', $mailer );
		}
		if ( ! empty( $flash['transcript'] ) && is_array( $flash['transcript'] ) ) {
			echo '<details><summary>' . esc_html__( 'SMTP conversation (passwords hidden)', 'iworksie-smtp' ) . '</summary>';
			echo '<pre class="iwiesm-pre" id="iwiesm-transcript">' . esc_html( implode( "\n", array_map( 'strval', $flash['transcript'] ) ) ) . '</pre>';
			echo UI::copy_button( 'iwiesm-transcript' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UI escapes.
			echo '</details>';
		}
		echo '</div>';
	}

	// ---------------------------------------------------------------------
	// Log.
	// ---------------------------------------------------------------------

	/**
	 * Email log tab.
	 *
	 * @return void
	 */
	private function render_log() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$view   = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;
		// phpcs:enable

		if ( ! Settings::get( 'log_enabled' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The email log is switched off. Turn it on in Setup → Email log and alerts.', 'iworksie-smtp' ) . '</p></div>';
		}

		if ( $view > 0 ) {
			$this->render_log_entry( $view );
			return;
		}

		$per   = 50;
		$data  = Logger::query( $status, $page, $per, $search );
		$total = (int) $data['total'];

		echo '<div class="iwiesm-log-toolbar">';
		echo '<ul class="subsubsub">';
		$filters = array(
			''       => __( 'All', 'iworksie-smtp' ),
			'sent'   => __( 'Sent', 'iworksie-smtp' ),
			'failed' => __( 'Failed', 'iworksie-smtp' ),
		);
		$links   = array();
		foreach ( $filters as $key => $label ) {
			$url     = Actions::url( 'log', '' === $key ? array() : array( 'status' => $key ) );
			$current = $key === $status ? ' class="current" aria-current="page"' : '';
			$links[] = '<li><a href="' . esc_url( $url ) . '"' . $current . '>' . esc_html( $label ) . '</a>';
		}
		echo implode( ' |</li>', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '</ul>';
		echo '<form method="get" class="iwiesm-log-search">';
		echo '<input type="hidden" name="page" value="iworksie-smtp" /><input type="hidden" name="tab" value="log" />';
		if ( '' !== $status ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '" />';
		}
		echo '<label class="screen-reader-text" for="iwiesm-log-s">' . esc_html__( 'Search recipient or subject', 'iworksie-smtp' ) . '</label>';
		echo '<input type="search" id="iwiesm-log-s" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Recipient or subject', 'iworksie-smtp' ) . '" /> ';
		echo '<button class="button">' . esc_html__( 'Search', 'iworksie-smtp' ) . '</button>';
		echo '</form></div>';

		if ( 0 === $total ) {
			echo '<p class="iwiesm-empty">' . esc_html__( 'No emails logged yet.', 'iworksie-smtp' ) . '</p>';
		} else {
			$names = self::mailer_names();
			echo '<table class="widefat striped iwt-table iwiesm-log"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Date', 'iworksie-smtp' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Status', 'iworksie-smtp' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'To', 'iworksie-smtp' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Subject', 'iworksie-smtp' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Sent through', 'iworksie-smtp' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $data['rows'] as $row ) {
				$failed = 'failed' === $row['status'];
				$when   = get_date_from_gmt( (string) $row['created_gmt'], 'Y-m-d H:i' );
				$link   = Actions::url( 'log', array( 'view' => (string) $row['id'] ) );
				echo '<tr>';
				echo '<td><a href="' . esc_url( $link ) . '">' . esc_html( $when ) . '</a></td>';
				echo '<td>' . self::sent_badge( ! $failed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in sent_badge().
				if ( $failed ) {
					$explained = ErrorTranslator::explain( (string) $row['error'], (string) $row['mailer'] );
					echo '<br /><span class="iwiesm-log-error">' . esc_html( $explained['title'] ) . '</span>';
				}
				echo '</td>';
				echo '<td>' . esc_html( (string) $row['to_addr'] ) . '</td>';
				echo '<td><a href="' . esc_url( $link ) . '">' . esc_html( '' !== (string) $row['subject'] ? (string) $row['subject'] : __( '(no subject)', 'iworksie-smtp' ) ) . '</a></td>';
				echo '<td>' . esc_html( isset( $names[ $row['mailer'] ] ) ? $names[ $row['mailer'] ] : (string) $row['mailer'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';

			$pages = (int) ceil( $total / $per );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					(string) paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $page,
							'total'   => $pages,
						)
					)
				);
				echo '</div></div>';
			}
		}

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: days, 2: rows */
				__( 'Entries are deleted after %1$d days, and only the newest %2$d are kept. Change this on the Setup tab.', 'iworksie-smtp' ),
				(int) Settings::get( 'log_days' ),
				(int) Settings::get( 'log_max_rows' )
			)
		) . '</p>';

		if ( $total > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="iwiesm-inline-form">';
			wp_nonce_field( 'iwiesm_log_clear' );
			echo '<input type="hidden" name="action" value="iwiesm_log_clear" />';
			submit_button( __( 'Clear the log', 'iworksie-smtp' ), 'delete', 'submit', false );
			echo '</form>';
		}
	}

	/**
	 * One log entry.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	private function render_log_entry( $id ) {
		$row = Logger::find( $id );
		echo '<p><a href="' . esc_url( Actions::url( 'log' ) ) . '">&larr; ' . esc_html__( 'Back to the log', 'iworksie-smtp' ) . '</a></p>';
		if ( null === $row ) {
			echo '<p>' . esc_html__( 'This entry no longer exists.', 'iworksie-smtp' ) . '</p>';
			return;
		}
		$names  = self::mailer_names();
		$failed = 'failed' === $row['status'];
		echo '<div class="iwt-card iwiesm-card">';
		echo '<h2>' . esc_html( '' !== (string) $row['subject'] ? (string) $row['subject'] : __( '(no subject)', 'iworksie-smtp' ) ) . ' ' . self::sent_badge( ! $failed ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UI escapes.
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Date', 'iworksie-smtp' ) . '</th><td>' . esc_html( get_date_from_gmt( (string) $row['created_gmt'], 'Y-m-d H:i:s' ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'To', 'iworksie-smtp' ) . '</th><td>' . esc_html( (string) $row['to_addr'] ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Sent through', 'iworksie-smtp' ) . '</th><td>' . esc_html( isset( $names[ $row['mailer'] ] ) ? $names[ $row['mailer'] ] : (string) $row['mailer'] ) . '</td></tr>';
		if ( (int) $row['resend_of'] > 0 ) {
			echo '<tr><th scope="row">' . esc_html__( 'Resend of', 'iworksie-smtp' ) . '</th><td><a href="' . esc_url( Actions::url( 'log', array( 'view' => (string) $row['resend_of'] ) ) ) . '">#' . esc_html( (string) $row['resend_of'] ) . '</a></td></tr>';
		}
		if ( null !== $row['headers'] && '' !== (string) $row['headers'] ) {
			echo '<tr><th scope="row">' . esc_html__( 'Headers', 'iworksie-smtp' ) . '</th><td><pre class="iwiesm-pre">' . esc_html( (string) $row['headers'] ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';

		if ( $failed ) {
			$this->render_explanation( (string) $row['error'], (string) $row['mailer'] );
		}

		if ( null !== $row['message'] ) {
			echo '<h3>' . esc_html__( 'Message', 'iworksie-smtp' ) . '</h3>';
			echo '<pre class="iwiesm-pre iwiesm-message">' . esc_html( (string) $row['message'] ) . '</pre>';
			$resend = wp_nonce_url( admin_url( 'admin-post.php?action=iwiesm_log_resend&id=' . (int) $row['id'] ), 'iwiesm_log_resend_' . (int) $row['id'] );
			echo '<p><a class="button" href="' . esc_url( $resend ) . '">' . esc_html__( 'Resend this email', 'iworksie-smtp' ) . '</a></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Message content was not kept, so this email cannot be resent. Switch on "Also keep message content" on the Setup tab to resend future emails.', 'iworksie-smtp' ) . '</p>';
		}
		echo '</div>';
	}

	// ---------------------------------------------------------------------
	// Diagnostics.
	// ---------------------------------------------------------------------

	/**
	 * Diagnostics tab (core checks runner).
	 *
	 * @return void
	 */
	private function render_diagnostics() {
		echo '<div class="iwt-empty" data-iwt-empty>';
		echo '<div class="iwt-empty__icon" aria-hidden="true"><span class="dashicons dashicons-email-alt"></span></div>';
		echo '<h2 class="iwt-empty__title">' . esc_html__( 'Check your email setup', 'iworksie-smtp' ) . '</h2>';
		echo '<p class="iwt-empty__body">' . esc_html__( 'Signs in to your mail service without sending anything, and looks up SPF, DKIM and DMARC for your From domain. Takes a few seconds and changes nothing.', 'iworksie-smtp' ) . '</p>';
		echo '<p class="iwt-empty__action"><button type="button" class="button button-primary button-hero" data-iwt-run-checks>' . esc_html__( 'Run diagnostics', 'iworksie-smtp' ) . '</button></p>';
		echo '</div>';
		echo '<div class="iwt-summary-wrap" data-iwt-summary-wrap hidden>';
		echo '<div class="iwt-summary" data-iwt-summary role="status"></div>';
		echo '</div>';
		echo '<div data-iwt-results aria-live="polite" aria-label="' . esc_attr__( 'Diagnostics results', 'iworksie-smtp' ) . '"></div>';
	}
}
