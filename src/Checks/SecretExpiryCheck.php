<?php
/**
 * Microsoft client secret expiry countdown.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Checks;

use IWorksIE\SMTP\Log\Alerts;
use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Check;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Result;

/**
 * Warns 30 days before the client secret stops working.
 */
final class SecretExpiryCheck implements Check {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'secret_expiry';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Microsoft client secret expiry', 'iworksie-smtp' );
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
		if ( 'microsoft' !== Settings::mailer() ) {
			return Result::info(
				$this->label(),
				__( 'Only used with the Microsoft 365 mailer.', 'iworksie-smtp' )
			);
		}
		$fix     = __( 'In the Entra admin centre open the app registration → Certificates & secrets → New client secret. Paste the new Value and its expiry date on the Setup tab, then delete the old secret.', 'iworksie-smtp' );
		$actions = array(
			array(
				'label' => __( 'Open Setup', 'iworksie-smtp' ),
				'url'   => admin_url( 'admin.php?page=iworksie-smtp&tab=setup' ),
			),
		);
		$days    = Alerts::days_until_secret_expiry();
		$expiry  = (string) Settings::get( 'ms_secret_expiry' );

		if ( null === $days ) {
			return Result::recommended(
				__( 'Secret expiry date not entered', 'iworksie-smtp' ),
				__( 'Microsoft client secrets stop working on a fixed date, and email silently stops when they do. Enter the date shown in the "Expires" column under Certificates & secrets so we can warn you two weeks before.', 'iworksie-smtp' ),
				array( 'actions' => $actions )
			);
		}
		if ( $days < 0 ) {
			return Result::critical(
				/* translators: %s: date */
				sprintf( __( 'The client secret expired on %s', 'iworksie-smtp' ), $expiry ),
				__( 'Microsoft no longer accepts it, so the site cannot send email.', 'iworksie-smtp' ),
				array(
					'fix'     => $fix,
					'actions' => $actions,
				)
			);
		}
		if ( $days <= 30 ) {
			return Result::recommended(
				/* translators: %d: days */
				sprintf( _n( 'The client secret expires in %d day', 'The client secret expires in %d days', $days, 'iworksie-smtp' ), $days ),
				/* translators: %s: date */
				sprintf( __( 'It expires on %s. Replace it before then to avoid an outage.', 'iworksie-smtp' ), $expiry ),
				array(
					'fix'     => $fix,
					'actions' => $actions,
				)
			);
		}
		return Result::good(
			/* translators: %d: days */
			sprintf( _n( 'Client secret valid for %d more day', 'Client secret valid for %d more days', $days, 'iworksie-smtp' ), $days ),
			/* translators: %s: date */
			sprintf( __( 'Expires on %s. We will email you two weeks before.', 'iworksie-smtp' ), $expiry )
		);
	}
}
