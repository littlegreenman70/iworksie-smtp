<?php
/**
 * Is the From address one the mail service will accept and receivers trust?
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Checks;

use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Support\Dns;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Check;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Result;

/**
 * From address present, valid, matches the account, not a free mailbox.
 */
final class FromAddressCheck implements Check {

	/**
	 * Free mailbox domains with strict DMARC that cannot be sent from
	 * through another provider.
	 *
	 * @var array<int,string>
	 */
	const FREE_DOMAINS = array( 'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'yahoo.ie', 'aol.com', 'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'live.com', 'live.ie', 'msn.com', 'icloud.com', 'me.com', 'gmx.com', 'mail.com', 'protonmail.com', 'proton.me', 'eircom.net' );

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'from_address';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'From address', 'iworksie-smtp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function group() {
		return 'setup';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		$mailer  = Settings::mailer();
		$from    = Settings::from_email();
		$actions = array(
			array(
				'label' => __( 'Open Setup', 'iworksie-smtp' ),
				'url'   => admin_url( 'admin.php?page=iworksie-smtp&tab=setup' ),
			),
		);

		if ( '' === $from ) {
			$site = Dns::domain_of( 'x@' . (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			return Result::recommended(
				__( 'No From address is set', 'iworksie-smtp' ),
				/* translators: %s: example address */
				sprintf( __( 'WordPress will send from its default address (%s), which usually does not exist and is not allowed by your mail service.', 'iworksie-smtp' ), 'wordpress@' . preg_replace( '/^www\./', '', $site ) ),
				array(
					'fix'     => __( 'Enter a real address on your own domain, for example hello@yourdomain.com, on the Setup tab.', 'iworksie-smtp' ),
					'actions' => $actions,
				)
			);
		}

		$domain = Dns::domain_of( $from );
		if ( 'google' !== $mailer && in_array( $domain, self::FREE_DOMAINS, true ) ) {
			return Result::critical(
				/* translators: %s: domain */
				sprintf( __( 'Sending from a free %s address', 'iworksie-smtp' ), $domain ),
				__( 'Free mailbox providers publish strict DMARC policies, so email claiming to be from their domain but sent from somewhere else is rejected or sent to spam.', 'iworksie-smtp' ),
				array(
					'fix'     => __( 'Use an address on your own domain. If you really want to send from a Gmail address, choose the Google mailer and connect that account.', 'iworksie-smtp' ),
					'actions' => $actions,
				)
			);
		}

		if ( 'google' === $mailer ) {
			$account = (string) Settings::get( 'google_email' );
			if ( '' !== $account && strtolower( $account ) !== strtolower( $from ) ) {
				return Result::recommended(
					__( 'From address differs from the connected Google account', 'iworksie-smtp' ),
					/* translators: 1: from address, 2: Google account */
					sprintf( __( 'Emails are set to come from %1$s but are sent through %2$s. Gmail replaces the From address with the account address unless %1$s is added as a "Send mail as" alias.', 'iworksie-smtp' ), $from, $account ),
					array(
						'fix'     => __( 'In Gmail settings → Accounts → "Send mail as", add the From address, or change the From address to the connected account.', 'iworksie-smtp' ),
						'actions' => $actions,
					)
				);
			}
		}

		if ( 'smtp' === $mailer ) {
			$user = (string) Settings::get( 'smtp_username' );
			if ( is_email( $user ) && Dns::domain_of( $user ) !== $domain ) {
				return Result::recommended(
					__( 'From address is on a different domain from the SMTP login', 'iworksie-smtp' ),
					/* translators: 1: from, 2: username */
					sprintf( __( 'You sign in as %2$s but send from %1$s. Many providers reject this or rewrite the From address.', 'iworksie-smtp' ), $from, $user ),
					array(
						'fix'     => __( 'Send from the address you sign in with, or confirm with your provider that the From domain is verified for your account.', 'iworksie-smtp' ),
						'actions' => $actions,
					)
				);
			}
		}

		if ( ! Settings::get( 'force_from_email' ) && 'microsoft' !== $mailer ) {
			return Result::recommended(
				__( 'Other plugins can change the From address', 'iworksie-smtp' ),
				/* translators: %s: from address */
				sprintf( __( 'Your From address is %s, but "Force From address" is off, so a form or shop plugin can send from a different address that your mail service may reject.', 'iworksie-smtp' ), $from ),
				array(
					'fix'     => __( 'Switch on "Force From address" unless you know you need plugins to set their own. Replies still go to the Reply-To they set.', 'iworksie-smtp' ),
					'actions' => $actions,
				)
			);
		}

		return Result::good(
			/* translators: %s: from address */
			sprintf( __( 'Sending from %s', 'iworksie-smtp' ), $from ),
			__( 'The From address is on your own domain and matches how you send.', 'iworksie-smtp' )
		);
	}
}
