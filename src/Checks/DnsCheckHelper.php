<?php
/**
 * Shared results for DNS checks.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Checks;

use IWorksIE\SMTP\Vendor\IWorksIE\Core\Checks\Result;

/**
 * Common "cannot check" results.
 */
final class DnsCheckHelper {

	/**
	 * No From domain to check.
	 *
	 * @param string $label Check label.
	 * @return Result
	 */
	public static function no_domain( $label ) {
		return Result::info(
			$label,
			__( 'Set a From address on the Setup tab so we know which domain to check.', 'iworksie-smtp' )
		);
	}

	/**
	 * DNS lookups disabled on this server.
	 *
	 * @param string $label Check label.
	 * @return Result
	 */
	public static function dns_unavailable( $label ) {
		return Result::info(
			$label,
			__( 'This server does not allow DNS lookups from PHP (dns_get_record is disabled), so this could not be checked here. Use an online tool such as MXToolbox to check it.', 'iworksie-smtp' )
		);
	}
}
