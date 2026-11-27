<?php
/**
 * Failed emails in the last 7 days.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Checks;

use IWorksIE\SMTP\Log\Logger;
use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Support\ErrorTranslator;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Check;
use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Result;

/**
 * Reads the email log.
 */
final class RecentFailuresCheck implements Check {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'recent_failures';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Recent failed emails', 'iworksie-smtp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function group() {
		return 'log';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		if ( ! Settings::get( 'log_enabled' ) ) {
			return Result::info(
				$this->label(),
				__( 'The email log is switched off, so we cannot tell whether emails have been failing.', 'iworksie-smtp' )
			);
		}
		$since  = time() - 7 * DAY_IN_SECONDS;
		$failed = Logger::count_since( 'failed', $since );
		$sent   = Logger::count_since( 'sent', $since );
		if ( 0 === $failed ) {
			return Result::good(
				__( 'No failed emails in the last 7 days', 'iworksie-smtp' ),
				/* translators: %d: count */
				sprintf( _n( '%d email sent successfully.', '%d emails sent successfully.', $sent, 'iworksie-smtp' ), $sent )
			);
		}
		$last      = Logger::last_failure_since( $since );
		$raw       = null !== $last ? (string) $last['error'] : '';
		$explained = ErrorTranslator::explain( $raw, null !== $last ? (string) $last['mailer'] : '' );
		$status    = $failed > $sent ? 'critical' : 'recommended';
		$label     = sprintf(
			/* translators: %d: count */
			_n( '%d email failed in the last 7 days', '%d emails failed in the last 7 days', $failed, 'iworksie-smtp' ),
			$failed
		);
		$extra = array(
			'why'     => $explained['explain'],
			'fix'     => $explained['fix'],
			'actions' => array(
				array(
					'label' => __( 'View failed emails', 'iworksie-smtp' ),
					'url'   => admin_url( 'admin.php?page=iworksie-smtp&tab=log&status=failed' ),
				),
			),
		);
		/* translators: 1: plain-English title, 2: raw error */
		$description = sprintf( __( 'Most recent: %1$s (%2$s)', 'iworksie-smtp' ), $explained['title'], $raw );
		return 'critical' === $status ? Result::critical( $label, $description, $extra ) : Result::recommended( $label, $description, $extra );
	}
}
