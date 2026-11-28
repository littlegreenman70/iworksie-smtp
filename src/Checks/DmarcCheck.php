<?php
/**
 * DMARC policy for the From domain.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Checks;

use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Support\Dns;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Check;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Result;

/**
 * DMARC present, single, policy strength.
 */
final class DmarcCheck implements Check {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'dmarc';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'DMARC policy', 'iworksie-smtp' );
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
		$why = __( 'DMARC tells receivers what to do with email that fails SPF and DKIM, and stops others sending as your domain. Google, Yahoo and Microsoft now expect a DMARC record from domains that send a lot of email.', 'iworksie-smtp' );

		$records = array();
		$where   = '';
		foreach ( Dns::dmarc_candidates( $domain ) as $candidate ) {
			$txt = Dns::txt( '_dmarc.' . $candidate );
			if ( null === $txt ) {
				return DnsCheckHelper::dns_unavailable( $this->label() );
			}
			$records = Dns::dmarc_records( $txt );
			if ( ! empty( $records ) ) {
				$where = $candidate;
				break;
			}
		}

		if ( empty( $records ) ) {
			return Result::recommended(
				/* translators: %s: domain */
				sprintf( __( 'No DMARC record for %s', 'iworksie-smtp' ), $domain ),
				__( 'Your domain does not publish a DMARC policy.', 'iworksie-smtp' ),
				array(
					'why' => $why,
					/* translators: %s: domain */
					'fix' => sprintf( __( 'Once SPF and DKIM pass, add a TXT record named _dmarc.%s with the value: v=DMARC1; p=none; rua=mailto:you@yourdomain.com. After a few weeks of clean reports, change p=none to p=quarantine.', 'iworksie-smtp' ), $domain ),
				)
			);
		}

		if ( count( $records ) > 1 ) {
			return Result::critical(
				/* translators: %s: domain */
				sprintf( __( '%s has more than one DMARC record', 'iworksie-smtp' ), $where ),
				__( 'With more than one DMARC record, receivers ignore DMARC entirely.', 'iworksie-smtp' ),
				array(
					'why'  => $why,
					'fix'  => __( 'Delete all but one of the _dmarc TXT records.', 'iworksie-smtp' ),
					'data' => array( 'matches' => $records ),
				)
			);
		}

		$tags   = Dns::dmarc_tags( $records[0] );
		$policy = isset( $tags['p'] ) ? $tags['p'] : '';
		if ( 'none' === $policy || '' === $policy ) {
			return Result::recommended(
				__( 'DMARC is in monitoring mode (p=none)', 'iworksie-smtp' ),
				/* translators: %s: record */
				sprintf( __( 'Found: %s. This meets the bulk-sender requirement but does not protect your domain from spoofing.', 'iworksie-smtp' ), $records[0] ),
				array(
					'why' => $why,
					'fix' => __( 'When your DMARC reports show your real email passing, change p=none to p=quarantine (and later p=reject).', 'iworksie-smtp' ),
				)
			);
		}

		return Result::good(
			/* translators: %s: policy */
			sprintf( __( 'DMARC policy is %s', 'iworksie-smtp' ), $policy ),
			/* translators: %s: record */
			sprintf( __( 'Found: %s', 'iworksie-smtp' ), $records[0] )
		);
	}
}
