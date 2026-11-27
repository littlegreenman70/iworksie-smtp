<?php
/**
 * Picks the PHPMailer class WordPress itself would use.
 *
 * WordPress 6.8+ uses WP_PHPMailer (translated error messages); earlier
 * versions use PHPMailer directly. The caller loads the right files first.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Mail;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.DuplicateClassName.Found
if ( class_exists( '\WP_PHPMailer', false ) ) {
	/**
	 * Base on WordPress 6.8+.
	 */
	abstract class CatcherBase extends \WP_PHPMailer {
	}
} else {
	/**
	 * Base on WordPress 6.5 to 6.7.
	 */
	abstract class CatcherBase extends \PHPMailer\PHPMailer\PHPMailer {
	}
}
// phpcs:enable
