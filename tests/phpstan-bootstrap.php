<?php
/**
 * PHPStan bootstrap stubs.
 *
 * @package IWorksIE\SMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'IWIESM_VERSION' ) ) {
	define( 'IWIESM_VERSION', '1.0.0' );
}
if ( ! defined( 'IWIESM_FILE' ) ) {
	define( 'IWIESM_FILE', '/tmp/iworksie-smtp/iworksie-smtp.php' );
}
if ( ! defined( 'IWIESM_PATH' ) ) {
	define( 'IWIESM_PATH', '/tmp/iworksie-smtp/' );
}
if ( ! defined( 'IWIESM_URL' ) ) {
	define( 'IWIESM_URL', 'https://example.com/wp-content/plugins/iworksie-smtp/' );
}
if ( ! defined( 'IWIESM_MIN_PHP' ) ) {
	define( 'IWIESM_MIN_PHP', '7.4' );
}
if ( ! defined( 'IWIESM_MIN_WP' ) ) {
	define( 'IWIESM_MIN_WP', '6.5' );
}

if ( ! class_exists( '\IWorksIE\SMTP\Vendor\IWorksIE\Core\Core' ) && is_readable( dirname( __DIR__ ) . '/vendor-prefixed/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor-prefixed/autoload.php';
}
