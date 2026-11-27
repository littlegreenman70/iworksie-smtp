<?php
/**
 * Google Workspace / Gmail via the Gmail API.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Mail\Transport;

use IWorksIE\SMTP\Settings\Settings;
use IWorksIE\SMTP\Support\Crypto;

/**
 * OAuth 2.0 authorisation-code flow with a refresh token, and
 * messages.send through the media upload endpoint (raw MIME, up to 35 MB).
 */
final class Gmail implements Transport {

	const TOKEN_TRANSIENT = 'iwiesm_google_token';

	const SCOPES = 'openid email https://www.googleapis.com/auth/gmail.send';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'google';
	}

	/**
	 * Client ID and secret saved.
	 *
	 * @return bool
	 */
	public static function has_client() {
		return '' !== (string) Settings::get( 'google_client_id' ) && Settings::has_secret( 'google_client_secret' );
	}

	/**
	 * Connected (refresh token saved).
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return self::has_client() && '' !== (string) Settings::get( 'google_refresh_token' );
	}

	/**
	 * The exact redirect URI to register in Google Cloud.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin.php?page=iworksie-smtp' );
	}

	/**
	 * Google consent screen URL.
	 *
	 * @param string $state CSRF state.
	 * @return string
	 */
	public static function authorize_url( $state ) {
		return add_query_arg(
			array(
				'client_id'              => rawurlencode( (string) Settings::get( 'google_client_id' ) ),
				'redirect_uri'           => rawurlencode( self::redirect_uri() ),
				'response_type'          => 'code',
				'scope'                  => rawurlencode( self::SCOPES ),
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => rawurlencode( $state ),
			),
			'https://accounts.google.com/o/oauth2/v2/auth'
		);
	}

	/**
	 * Exchange an authorisation code for tokens and store them.
	 *
	 * @param string $code Code from the redirect.
	 * @return string Connected account email.
	 * @throws TransportException On failure.
	 */
	public function exchange_code( $code ) {
		$body = $this->token_request(
			array(
				'code'          => $code,
				'client_id'     => (string) Settings::get( 'google_client_id' ),
				'client_secret' => Settings::secret( 'google_client_secret' ),
				'redirect_uri'  => self::redirect_uri(),
				'grant_type'    => 'authorization_code',
			)
		);

		if ( empty( $body['refresh_token'] ) ) {
			throw new TransportException( 'invalid_grant: Google did not return a refresh token. Remove the app under myaccount.google.com/permissions and connect again.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
		}
		if ( isset( $body['scope'] ) && false === strpos( (string) $body['scope'], 'gmail.send' ) ) {
			throw new TransportException( 'insufficientPermissions: the send-email permission was not granted.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
		}

		$email = self::email_from_id_token( isset( $body['id_token'] ) ? (string) $body['id_token'] : '' );
		Settings::set_secret( 'google_refresh_token', (string) $body['refresh_token'] );
		Settings::update( array( 'google_email' => $email ) );
		$this->cache_token( $body );
		return $email;
	}

	/**
	 * Revoke and forget the connection.
	 *
	 * @return void
	 */
	public static function disconnect() {
		$refresh = Settings::secret( 'google_refresh_token' );
		if ( '' !== $refresh ) {
			wp_remote_post(
				'https://oauth2.googleapis.com/revoke',
				array(
					'timeout' => 10,
					'body'    => array( 'token' => $refresh ),
				)
			);
		}
		Settings::update(
			array(
				'google_refresh_token' => '',
				'google_email'         => '',
			)
		);
		delete_transient( self::TOKEN_TRANSIENT );
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
			throw new TransportException( __( 'Google is selected but not connected. Click "Connect with Google" on the Setup tab.', 'iworksie-smtp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
		}
		$token    = $this->access_token();
		$response = wp_remote_post(
			'https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send?uploadType=media',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'message/rfc822',
				),
				'body'    => $mime,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new TransportException( $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return;
		}
		if ( 401 === $code ) {
			delete_transient( self::TOKEN_TRANSIENT );
		}
		throw new TransportException( self::api_error( $response, $code ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
	}

	/**
	 * Access token from cache or by refreshing.
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
		$refresh = Settings::secret( 'google_refresh_token' );
		if ( '' === $refresh ) {
			throw new TransportException( 'invalid_grant: no saved Google connection.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
		}
		$body = $this->token_request(
			array(
				'client_id'     => (string) Settings::get( 'google_client_id' ),
				'client_secret' => Settings::secret( 'google_client_secret' ),
				'refresh_token' => $refresh,
				'grant_type'    => 'refresh_token',
			)
		);
		return $this->cache_token( $body );
	}

	/**
	 * POST to the token endpoint.
	 *
	 * @param array<string,string> $params Form fields.
	 * @return array<string,mixed>
	 * @throws TransportException On failure.
	 */
	private function token_request( array $params ) {
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 20,
				'body'    => $params,
			)
		);
		if ( is_wp_error( $response ) ) {
			self::record_token_status( false, $response->get_error_message() );
			throw new TransportException( $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$error = is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : 'HTTP ' . (int) wp_remote_retrieve_response_code( $response );
			$desc  = is_array( $body ) && ! empty( $body['error_description'] ) ? (string) $body['error_description'] : '';
			$msg   = trim( $error . ': ' . $desc, ': ' );
			self::record_token_status( false, $msg );
			throw new TransportException( $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output.
		}
		self::record_token_status( true, '' );
		return $body;
	}

	/**
	 * Cache an access token from a token response.
	 *
	 * @param array<string,mixed> $body Token response.
	 * @return string
	 */
	private function cache_token( array $body ) {
		$token   = (string) $body['access_token'];
		$expires = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3599;
		set_transient( self::TOKEN_TRANSIENT, Crypto::encrypt( $token ), max( 60, $expires - 120 ) );
		return $token;
	}

	/**
	 * Read the email claim from an ID token received directly from Google's
	 * token endpoint over TLS (signature check not required in that case).
	 *
	 * @param string $jwt ID token.
	 * @return string
	 */
	public static function email_from_id_token( $jwt ) {
		$parts = explode( '.', $jwt );
		if ( count( $parts ) < 2 ) {
			return '';
		}
		$payload = base64_decode( strtr( $parts[1], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT payload.
		if ( false === $payload ) {
			return '';
		}
		$claims = json_decode( $payload, true );
		return is_array( $claims ) && ! empty( $claims['email'] ) ? sanitize_email( (string) $claims['email'] ) : '';
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
				'mailer'  => 'google',
				'ok'      => (bool) $ok,
				'message' => (string) $message,
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Readable error from a Gmail API response.
	 *
	 * @param array<string,mixed>|\WP_Error $response Response.
	 * @param int                           $code     HTTP status.
	 * @return string
	 */
	private static function api_error( $response, $code ) {
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ) {
			$reason = '';
			if ( ! empty( $body['error']['errors'][0]['reason'] ) ) {
				$reason = (string) $body['error']['errors'][0]['reason'];
			} elseif ( ! empty( $body['error']['status'] ) ) {
				$reason = (string) $body['error']['status'];
			}
			$message = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : '';
			return trim( $reason . ': ' . $message . ' (HTTP ' . $code . ')', ': ' );
		}
		return 'Gmail API returned HTTP ' . $code;
	}
}
