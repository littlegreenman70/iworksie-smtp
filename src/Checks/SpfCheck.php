<?php
/**
 * SPF record for the From domain.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Checks;

use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Support\Dns;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Check;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Result;

/**
 * One SPF record, not +all, includes the sending provider when known.
 */
final class SpfCheck implements Check {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'spf';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'SPF record', 'iworksie-smtp' );
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
		$txt = Dns::txt( $domain );
		if ( null === $txt ) {
			return DnsCheckHelper::dns_unavailable( $this->label() );
		}
		$records  = Dns::spf_records( $txt );
		$provider = Dns::provider( Settings::mailer(), (string) Settings::get( 'smtp_host' ) );
		$why      = __( 'SPF tells receiving mail servers which services may send email for your domain. Without it, or if your mail service is not listed, your emails are more likely to be marked as spam. Google and Yahoo require SPF or DKIM for all senders.', 'iworksie-smtp' );

		if ( empty( $records ) ) {
			return Result::recommended(
				/* translators: %s: domain */
				sprintf( __( 'No SPF record for %s', 'iworksie-smtp' ), $domain ),
				__( 'Your domain does not publish an SPF record.', 'iworksie-smtp' ),
				array(
					'why' => $why,
					'fix' => null !== $provider
						/* translators: 1: include domain, 2: provider */
						? sprintf( __( 'Add a TXT record on your domain with the value: v=spf1 include:%1$s ~all (this authorises %2$s). Add other services you send from before ~all.', 'iworksie-smtp' ), $provider['spf'][0], $provider['name'] )
						: __( 'Add a TXT record starting v=spf1 that lists the services you send from. Your email provider documents the exact value.', 'iworksie-smtp' ),
				)
			);
		}

		if ( count( $records ) > 1 ) {
			return Result::critical(
				/* translators: %s: domain */
				sprintf( __( '%s has more than one SPF record', 'iworksie-smtp' ), $domain ),
				__( 'A domain may only have one SPF record. With two or more, receivers treat SPF as broken for all of them.', 'iworksie-smtp' ),
				array(
					'why'  => $why,
					'fix'  => __( 'Merge them into one TXT record: keep one v=spf1 at the start, put every include: from both records in the middle, and one ~all at the end. Delete the other record.', 'iworksie-smtp' ),
					'data' => array( 'matches' => $records ),
				)
			);
		}

		$spf = $records[0];
		if ( '+' === Dns::spf_all( $spf ) ) {
			return Result::critical(
				__( 'SPF allows anyone to send as your domain', 'iworksie-smtp' ),
				/* translators: %s: SPF record */
				sprintf( __( 'Your SPF record ends in +all (or all), which authorises every server on the internet: %s', 'iworksie-smtp' ), $spf ),
				array(
					'why' => $why,
					'fix' => __( 'Change the ending to ~all (soft fail) or -all (fail).', 'iworksie-smtp' ),
				)
			);
		}

		if ( null !== $provider && ! empty( $provider['spf'] ) && ! Dns::spf_includes( $spf, $provider['spf'] ) ) {
			return Result::recommended(
				/* translators: %s: provider */
				sprintf( __( 'SPF does not mention %s', 'iworksie-smtp' ), $provider['name'] ),
				/* translators: 1: record, 2: include */
				sprintf( __( 'Your SPF record is: %1$s. We expected it to contain include:%2$s for the service you send through.', 'iworksie-smtp' ), $spf, $provider['spf'][0] ),
				array(
					'why' => $why,
					/* translators: %s: include */
					'fix' => sprintf( __( 'Add include:%s to the record, just before the ~all or -all at the end. If your provider told you to use a different include, you can ignore this.', 'iworksie-smtp' ), $provider['spf'][0] ),
				)
			);
		}

		return Result::good(
			/* translators: %s: domain */
			sprintf( __( 'SPF is set up for %s', 'iworksie-smtp' ), $domain ),
			null === $provider
				/* translators: %s: record */
				? sprintf( __( 'Found: %s. We cannot tell which include your SMTP provider needs, so check it is listed.', 'iworksie-smtp' ), $spf )
				/* translators: %s: record */
				: sprintf( __( 'Found: %s', 'iworksie-smtp' ), $spf )
		);
	}
}
