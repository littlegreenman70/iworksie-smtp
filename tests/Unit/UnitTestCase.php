<?php
/**
 * Base unit test with common WordPress stubs.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Brain Monkey setup.
 */
abstract class UnitTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$identity = static function ( $text ) {
			return $text;
		};
		Functions\when( '__' )->alias( $identity );
		Functions\when( 'esc_html__' )->alias( $identity );
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $text ) {
				return trim( strip_tags( (string) $text ) );
			}
		);
		Functions\when( 'sanitize_email' )->alias(
			static function ( $email ) {
				$email = trim( (string) $email );
				return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $n ) {
				return abs( (int) $n );
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
