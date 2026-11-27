<?php
/**
 * DKIM key for the From domain.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Checks;

use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Support\Dns;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Check;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Result;

/**
 * Looks up the provider's usual selectors (or the one entered in Setup).
 */
final class DkimCheck implements Check {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'dkim';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'DKIM signing', 'iworksie-smtp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function group() {
		return 'dns';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		$domain = Dns::domain_of( Settings::from_email() );
		if ( '' === $domain ) {
			return DnsCheckHelper::no_domain( $this->label() );
		}
		$mailer   = Settings::mailer();
		$provider = Dns::provider( $mailer, (string) Settings::get( 'smtp_host' ) );
		$custom   = (string) Settings::get( 'dkim_selector' );
		$why      = __( 'DKIM adds a digital signature that proves an email really came from your domain and was not changed. It is the most important signal for getting into the inbox, and it is required for DMARC to pass when you send through a third-party service.', 'iworksie-smtp' );

		$selectors = array();
		if ( '' !== $custom ) {
			$selectors[] = $custom;
		}
		if ( null !== $provider ) {
			$selectors = array_merge( $selectors, $provider['dkim'] );
		}
		$selectors = array_values( array_unique( $selectors ) );

		if ( empty( $selectors ) ) {
			return Result::info(
				$this->label(),
				__( 'We do not know which DKIM selector your email provider uses, so we cannot look it up.', 'iworksie-smtp' ),
				array(
					'why'     => $why,
					'fix'     => __( 'Find the selector in your provider\'s domain authentication settings (the part before ._domainkey) and enter it under "DKIM selector" on the Setup tab.', 'iworksie-smtp' ),
					'actions' => array(
						array(
							'label' => __( 'Open Setup', 'iworksie-smtp' ),
							'url'   => admin_url( 'admin.php?page=iworksie-smtp&tab=setup' ),
						),
					),
				)
			);
		}

		$found = array();
		foreach ( $selectors as $selector ) {
			$name = $selector . '._domainkey.' . $domain;
			$txt  = Dns::txt( $name );
			if ( null === $txt ) {
				return DnsCheckHelper::dns_unavailable( $this->label() );
			}
			if ( Dns::has_dkim_key( $txt ) ) {
				$found[] = $selector;
				continue;
			}
			$cname = Dns::cname( $name );
			if ( is_string( $cname ) && '' !== $cname ) {
				$found[] = $selector;
			}
		}

		if ( ! empty( $found ) ) {
			return Result::good(
				/* translators: %s: domain */
				sprintf( __( 'DKIM key found for %s', 'iworksie-smtp' ), $domain ),
				/* translators: %s: selectors */
				sprintf( __( 'Selector(s) found: %s.', 'iworksie-smtp' ), implode( ', ', $found ) )
			);
		}

		if ( 'microsoft' === $mailer ) {
			$fix = __( 'In the Microsoft Defender portal go to Email & collaboration → Policies → Email authentication settings → DKIM, select your domain, publish the two CNAME records it shows (selector1._domainkey and selector2._domainkey), then switch signing on.', 'iworksie-smtp' );
		} elseif ( 'google' === $mailer ) {
			$fix = __( 'In the Google Admin console go to Apps → Google Workspace → Gmail → Authenticate email, generate a new record, add the TXT record google._domainkey to your DNS, then click Start authentication. Personal Gmail addresses cannot set this up.', 'iworksie-smtp' );
		} else {
			$fix = __( 'Follow your email provider\'s "domain authentication" or "DKIM" guide and add the records it gives you. If it uses a different selector, enter it on the Setup tab.', 'iworksie-smtp' );
		}

		return Result::recommended(
			/* translators: %s: domain */
			sprintf( __( 'No DKIM key found for %s', 'iworksie-smtp' ), $domain ),
			/* translators: %s: selectors */
			sprintf( __( 'We looked for these selectors and found none: %s.', 'iworksie-smtp' ), implode( ', ', $selectors ) ),
			array(
				'why' => $why,
				'fix' => $fix,
			)
		);
	}
}
