<?php
/**
 * Microsoft 365 via Microsoft Graph (app-only, client credentials).
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Mail\Transport;

use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Support\Crypto;

/**
 * Sends a ready-made MIME message with POST /users/{mailbox}/sendMail.
 *
 * Uses the OAuth 2.0 client credentials flow, so there is no browser
 * redirect to set up or to break: tenant ID + client ID + client secret,
 * and the Mail.Send application permission with admin consent.
 */
final class MicrosoftGraph implements Transport {

	const TOKEN_TRANSIENT = 'iwiesm_ms_token';

	/**
	 * Graph accepts requests up to 4 MB; base64 adds a third.
	 */
	const MAX_MIME_BYTES = 2900000;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'microsoft';
	}

	/**
	 * Whether all required settings are present.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== (string) Settings::get( 'ms_tenant_id' )
			&& '' !== (string) Settings::get( 'ms_client_id' )
			&& Settings::has_secret( 'ms_client_secret' )
			&& '' !== (string) Settings::get( 'ms_mailbox' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $mime Full MIME message.
	 * @return void
	 * @throws TransportException On failure.
	 */
	public function send( $mime ) {
		if ( ! self::is_configured() ) {
			throw new TransportException( __( 'Microsoft 365 is selected but the tenant ID, client ID, client secret or mailbox is missing.', 'iworksie-smtp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
		}
		if ( strlen( $mime ) > self::MAX_MIME_BYTES ) {
			throw new TransportException( 'ErrorMessageSizeExceeded: message is ' . (int) strlen( $mime ) . ' bytes; Microsoft Graph accepts about 3 MB per request.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
		}

		$token   = $this->access_token();
		$mailbox = (string) Settings::get( 'ms_mailbox' );
		$url     = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode( $mailbox ) . '/sendMail';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'text/plain',
				),
				'body'    => base64_encode( $mime ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Graph requires base64 MIME.
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new TransportException( $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 202 === $code || 200 === $code ) {
			return;
		}
		if ( 401 === $code ) {
			delete_transient( self::TOKEN_TRANSIENT );
		}
		throw new TransportException( self::graph_error( $response, $code ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
	}

	/**
	 * Get (and cache) an app-only access token.
	 *
	 * @param bool $force Skip the cache.
	 * @return string
	 * @throws TransportException On failure.
	 */
	public function access_token( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TOKEN_TRANSIENT );
			if ( is_string( $cached ) && '' !== $cached ) {
				$plain = Crypto::decrypt( $cached );
				if ( '' !== $plain ) {
					return $plain;
				}
			}
		}

		$tenant = (string) Settings::get( 'ms_tenant_id' );
		$url    = 'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/token';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => (string) Settings::get( 'ms_client_id' ),
					'client_secret' => Settings::secret( 'ms_client_secret' ),
					'scope'         => 'https://graph.microsoft.com/.default',
					'grant_type'    => 'client_credentials',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::record_token_status( false, $response->get_error_message() );
			throw new TransportException( $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$message = is_array( $body ) && ! empty( $body['error_description'] )
				? (string) $body['error_description']
				: 'Token request failed with HTTP ' . (int) wp_remote_retrieve_response_code( $response );
			if ( is_array( $body ) && ! empty( $body['error'] ) && false === strpos( $message, (string) $body['error'] ) ) {
				$message = (string) $body['error'] . ': ' . $message;
			}
			self::record_token_status( false, $message );
			throw new TransportException( self::first_line( $message ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
		}

		$token   = (string) $body['access_token'];
		$expires = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3599;
		set_transient( self::TOKEN_TRANSIENT, Crypto::encrypt( $token ), max( 60, $expires - 120 ) );
		self::record_token_status( true, '' );
		return $token;
	}

	/**
	 * Remember the last sign-in outcome for Diagnostics.
	 *
	 * @param bool   $ok      Success.
	 * @param string $message Error.
	 * @return void
	 */
	private static function record_token_status( $ok, $message ) {
		update_option(
			'iwiesm_token_status',
			array(
				'mailer'  => 'microsoft',
				'ok'      => (bool) $ok,
				'message' => self::first_line( (string) $message ),
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Build a readable error string from a Graph error response.
	 *
	 * @param array<string,mixed>|\WP_Error $response Response.
	 * @param int                           $code     HTTP status.
	 * @return string
	 */
	private static function graph_error( $response, $code ) {
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ) {
			$err_code = isset( $body['error']['code'] ) ? (string) $body['error']['code'] : '';
			$message  = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : '';
			return trim( $err_code . ': ' . $message . ' (HTTP ' . $code . ')', ': ' );
		}
		return 'Microsoft Graph returned HTTP ' . $code;
	}

	/**
	 * AADSTS descriptions carry trace IDs on later lines; keep the first.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function first_line( $message ) {
		$parts = preg_split( '/\r\n|\r|\n/', $message );
		return is_array( $parts ) ? trim( (string) $parts[0] ) : $message;
	}
}
