<?php
/**
 * Plain-English explanations for mail errors.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Support;

/**
 * Turns raw Microsoft (AADSTS / Graph), Google and SMTP errors into
 * "what happened" and "how to fix it" text.
 */
final class ErrorTranslator {

	/**
	 * Explain a raw error.
	 *
	 * @param string $raw    Raw error text (may include codes).
	 * @param string $mailer Mailer id: microsoft, google, smtp, default.
	 * @return array{title:string,explain:string,fix:string,code:string}
	 */
	public static function explain( $raw, $mailer = '' ) {
		$raw = (string) $raw;
		foreach ( self::rules() as $rule ) {
			if ( '' !== $rule['mailer'] && $rule['mailer'] !== $mailer ) {
				continue;
			}
			if ( preg_match( $rule['pattern'], $raw ) ) {
				return array(
					'title'   => $rule['title'],
					'explain' => $rule['explain'],
					'fix'     => $rule['fix'],
					'code'    => $rule['code'],
				);
			}
		}

		return array(
			'title'   => __( 'The email could not be sent', 'iworksie-smtp' ),
			'explain' => __( 'The mail service returned an error we do not have a plain-English explanation for yet. The technical message below says what it reported.', 'iworksie-smtp' ),
			'fix'     => __( 'Check the settings on the Setup tab, then run Diagnostics. If it still fails, send the technical message to whoever manages your email.', 'iworksie-smtp' ),
			'code'    => '',
		);
	}

	/**
	 * Ordered rules: the first match wins, so specific codes come before
	 * generic phrases.
	 *
	 * @return array<int,array{mailer:string,pattern:string,code:string,title:string,explain:string,fix:string}>
	 */
	private static function rules() {
		return array(
			// Microsoft Entra ID (AADSTS) sign-in errors.
			array(
				'mailer'  => '',
				'pattern' => '/AADSTS7000222/',
				'code'    => 'AADSTS7000222',
				'title'   => __( 'The Microsoft client secret has expired', 'iworksie-smtp' ),
				'explain' => __( 'Client secrets in Microsoft Entra only last for a set time (often 6, 12 or 24 months). This one has run out, so Microsoft refuses to let the site sign in.', 'iworksie-smtp' ),
				'fix'     => __( 'In the Entra admin centre open your app registration, go to Certificates & secrets, create a new client secret, and paste its Value (not the Secret ID) on the Setup tab. Enter the new expiry date too so we can warn you next time.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/AADSTS7000215/',
				'code'    => 'AADSTS7000215',
				'title'   => __( 'The Microsoft client secret is wrong', 'iworksie-smtp' ),
				'explain' => __( 'Microsoft did not recognise the client secret. The most common cause is copying the "Secret ID" column instead of the "Value" column.', 'iworksie-smtp' ),
				'fix'     => __( 'Create a new client secret under Certificates & secrets and copy the Value straight away (Microsoft only shows it once). Paste it on the Setup tab and save.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/AADSTS700016/',
				'code'    => 'AADSTS700016',
				'title'   => __( 'Microsoft cannot find this app in your tenant', 'iworksie-smtp' ),
				'explain' => __( 'The Application (client) ID does not exist in the directory (tenant) you entered. Either the client ID is mistyped, or the tenant ID belongs to a different Microsoft 365 organisation.', 'iworksie-smtp' ),
				'fix'     => __( 'Copy both the Application (client) ID and the Directory (tenant) ID from the Overview page of the same app registration.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/AADSTS(90002|900023|90013)/',
				'code'    => 'AADSTS90002',
				'title'   => __( 'The Microsoft tenant ID is not valid', 'iworksie-smtp' ),
				'explain' => __( 'Microsoft could not find a directory with the tenant ID you entered.', 'iworksie-smtp' ),
				'fix'     => __( 'Copy the Directory (tenant) ID from the app registration\'s Overview page. It looks like 12345678-abcd-... You can also use your tenant domain, for example yourcompany.onmicrosoft.com.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/AADSTS(65001|650056|500011)/',
				'code'    => 'AADSTS65001',
				'title'   => __( 'Admin consent has not been granted', 'iworksie-smtp' ),
				'explain' => __( 'The app has been registered but a Microsoft 365 administrator has not approved its permission to send mail.', 'iworksie-smtp' ),
				'fix'     => __( 'In the app registration go to API permissions and click "Grant admin consent for <your organisation>". You need to be a Global Administrator, Privileged Role Administrator or Cloud Application Administrator.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/AADSTS(7000112|700027)/',
				'code'    => 'AADSTS7000112',
				'title'   => __( 'The Microsoft app is disabled', 'iworksie-smtp' ),
				'explain' => __( 'The app registration exists but has been disabled by an administrator, or its credentials are not valid for sign-in.', 'iworksie-smtp' ),
				'fix'     => __( 'Ask your Microsoft 365 administrator to check the app under Enterprise applications and make sure "Enabled for users to sign in" is set to Yes.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/AADSTS53003/',
				'code'    => 'AADSTS53003',
				'title'   => __( 'Blocked by a Conditional Access policy', 'iworksie-smtp' ),
				'explain' => __( 'Your organisation has a Conditional Access rule (for example "only allow sign-ins from our office network") that blocked the website\'s sign-in.', 'iworksie-smtp' ),
				'fix'     => __( 'Ask your Microsoft 365 administrator to exclude this app (workload identity) from the policy, or to allow your web server\'s IP address.', 'iworksie-smtp' ),
			),
			// Microsoft Graph sendMail errors.
			array(
				'mailer'  => '',
				'pattern' => '/ErrorAccessDenied|Access is denied|Authorization_RequestDenied|"code"\s*:\s*"Forbidden"|^Forbidden:/i',
				'code'    => 'ErrorAccessDenied',
				'title'   => __( 'The app is not allowed to send from this mailbox', 'iworksie-smtp' ),
				'explain' => __( 'Sign-in worked, but Microsoft refused to send. Usually the Mail.Send permission was added as "Delegated" instead of "Application", admin consent is missing, or an RBAC / Application Access Policy limits the app to a different mailbox.', 'iworksie-smtp' ),
				'fix'     => __( 'In API permissions make sure Microsoft Graph → Mail.Send is listed with type "Application" and a green "Granted" tick. If your admin limited the app to certain mailboxes, add the From mailbox to that scope. Changes can take up to an hour to apply.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/ErrorInvalidUser|MailboxNotEnabledForRESTAPI|ResourceNotFound|ErrorNonExistentMailbox|"code"\s*:\s*"NotFound"|^NotFound:/i',
				'code'    => 'ErrorInvalidUser',
				'title'   => __( 'The From mailbox was not found in Microsoft 365', 'iworksie-smtp' ),
				'explain' => __( 'Microsoft could not find a licensed mailbox for the From address. It may be mistyped, an alias rather than the main address, a distribution list, or a user without an Exchange Online licence.', 'iworksie-smtp' ),
				'fix'     => __( 'Use the primary email address of a real mailbox (a licensed user or a shared mailbox) in "Send from mailbox" on the Setup tab.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/ErrorSendAsDenied|SendAsDenied/i',
				'code'    => 'ErrorSendAsDenied',
				'title'   => __( 'Not allowed to send as that address', 'iworksie-smtp' ),
				'explain' => __( 'The email\'s From address is different from the mailbox it is being sent through, and that mailbox does not have "Send As" rights for it.', 'iworksie-smtp' ),
				'fix'     => __( 'Leave "Force From address" switched on so every email is sent from the mailbox itself, or ask your admin to grant Send As permission.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/ErrorMessageSizeExceeded|RequestEntityTooLarge|\b413\b|too large/i',
				'code'    => 'ErrorMessageSizeExceeded',
				'title'   => __( 'The email is too large', 'iworksie-smtp' ),
				'explain' => __( 'The email with its attachments is bigger than the service accepts. Microsoft Graph accepts up to about 3 MB of attachments in one request; Gmail accepts up to 25 MB.', 'iworksie-smtp' ),
				'fix'     => __( 'Send a link to the file instead of attaching it, or reduce the attachment size.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/ErrorQuotaExceeded|ApplicationThrottled|TooManyRequests|\b429\b|rateLimitExceeded|userRateLimitExceeded|dailyLimitExceeded/i',
				'code'    => 'throttled',
				'title'   => __( 'Sending limit reached', 'iworksie-smtp' ),
				'explain' => __( 'The mail service is temporarily refusing emails because too many were sent in a short time, or a daily limit was reached.', 'iworksie-smtp' ),
				'fix'     => __( 'Wait a while and try again. If this happens regularly, the site is sending more than a normal mailbox allows; use a transactional email service (for example via Other SMTP) for bulk mail.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/InvalidAuthenticationToken|token is expired|Lifetime validation failed/i',
				'code'    => 'InvalidAuthenticationToken',
				'title'   => __( 'The access token was rejected', 'iworksie-smtp' ),
				'explain' => __( 'The short-lived sign-in token was refused. This normally fixes itself on the next email.', 'iworksie-smtp' ),
				'fix'     => __( 'Send a test email. If it keeps failing, re-save the settings on the Setup tab to force a fresh sign-in.', 'iworksie-smtp' ),
			),
			// Google OAuth / Gmail API errors.
			array(
				'mailer'  => '',
				'pattern' => '/invalid_grant/',
				'code'    => 'invalid_grant',
				'title'   => __( 'The Google connection has expired or was revoked', 'iworksie-smtp' ),
				'explain' => __( 'Google no longer accepts the saved sign-in. Common causes: the Google Cloud app is still in "Testing" (Google expires those connections after 7 days), the account password was changed, or access was removed in the Google account\'s security settings.', 'iworksie-smtp' ),
				'fix'     => __( 'In Google Cloud set the app\'s audience to Internal (Workspace) or publish it "In production" (Gmail), then click "Connect with Google" again on the Setup tab.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/invalid_client|unauthorized_client/',
				'code'    => 'invalid_client',
				'title'   => __( 'The Google client ID or secret is wrong', 'iworksie-smtp' ),
				'explain' => __( 'Google did not recognise the OAuth client ID and secret pair, or the client was deleted.', 'iworksie-smtp' ),
				'fix'     => __( 'In Google Cloud open Clients, select the Web application client, and copy the Client ID and Client secret again.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/redirect_uri_mismatch/',
				'code'    => 'redirect_uri_mismatch',
				'title'   => __( 'The redirect URI does not match', 'iworksie-smtp' ),
				'explain' => __( 'Google only sends you back to addresses listed on the OAuth client. The address for this site is missing or slightly different (http vs https, www, or a trailing character).', 'iworksie-smtp' ),
				'fix'     => __( 'Copy the redirect URI shown on the Setup tab with the Copy button and paste it, unchanged, under "Authorised redirect URIs" on your Google OAuth client.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/access_denied/',
				'code'    => 'access_denied',
				'title'   => __( 'Google access was not approved', 'iworksie-smtp' ),
				'explain' => __( 'The Google consent screen was cancelled, or the account is not allowed to use this app (in Testing mode only listed test users can connect).', 'iworksie-smtp' ),
				'fix'     => __( 'Click "Connect with Google" again and approve the "Send email on your behalf" permission. If the app is in Testing, add the account as a test user or publish the app.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/accessNotConfigured|has not been used in project|SERVICE_DISABLED|API has not been enabled/i',
				'code'    => 'accessNotConfigured',
				'title'   => __( 'The Gmail API is not switched on', 'iworksie-smtp' ),
				'explain' => __( 'The Google Cloud project behind your OAuth client does not have the Gmail API enabled.', 'iworksie-smtp' ),
				'fix'     => __( 'In Google Cloud go to APIs & Services → Library, search for "Gmail API" and click Enable. Wait a minute, then send a test email.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/insufficientPermissions|ACCESS_TOKEN_SCOPE_INSUFFICIENT|insufficient authentication scopes/i',
				'code'    => 'insufficientPermissions',
				'title'   => __( 'Google did not grant permission to send', 'iworksie-smtp' ),
				'explain' => __( 'The connection was made without the "Send email on your behalf" permission (the checkbox on Google\'s consent screen may have been left unticked).', 'iworksie-smtp' ),
				'fix'     => __( 'Click Disconnect, then "Connect with Google" again and make sure the send-email permission is ticked.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/Mail service not enabled|failedPrecondition/i',
				'code'    => 'failedPrecondition',
				'title'   => __( 'Gmail is not enabled for this account', 'iworksie-smtp' ),
				'explain' => __( 'The Google account you connected does not have Gmail switched on (common for Workspace accounts where Gmail is disabled by the admin).', 'iworksie-smtp' ),
				'fix'     => __( 'Connect an account that has a Gmail mailbox, or ask your Workspace admin to enable Gmail for it.', 'iworksie-smtp' ),
			),
			// SMTP errors.
			array(
				'mailer'  => '',
				'pattern' => '/5\.7\.139|SmtpClientAuthentication is disabled|5\.7\.3 Authentication unsuccessful/i',
				'code'    => '5.7.139',
				'title'   => __( 'Microsoft 365 has SMTP sign-in switched off', 'iworksie-smtp' ),
				'explain' => __( 'Microsoft is retiring password sign-in over SMTP (Basic auth). Most Microsoft 365 tenants now refuse it, even with the right password.', 'iworksie-smtp' ),
				'fix'     => __( 'Choose "Microsoft 365" on the Setup tab instead of Other SMTP. It sends through the Microsoft Graph API, which does not need SMTP sign-in.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/534[ -]5\.7\.(9|14)|Application-specific password required|Please log in via your web browser/i',
				'code'    => '534',
				'title'   => __( 'Google refused the password', 'iworksie-smtp' ),
				'explain' => __( 'Gmail and Google Workspace no longer accept your normal password over SMTP.', 'iworksie-smtp' ),
				'fix'     => __( 'Choose "Google" on the Setup tab to connect with Google sign-in instead. If you must use SMTP, create an App Password in your Google account (this needs 2-Step Verification).', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/Could not authenticate|535|Authentication failed|Username and Password not accepted|authentication credentials invalid/i',
				'code'    => '535',
				'title'   => __( 'The SMTP username or password was not accepted', 'iworksie-smtp' ),
				'explain' => __( 'The mail server rejected the sign-in details.', 'iworksie-smtp' ),
				'fix'     => __( 'Re-enter the SMTP username (usually the full email address, or the API key name your provider gives you) and the password or API key. Check there are no spaces before or after.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/certificate verify failed|SSL routines|ssl3_get_server_certificate|peer certificate/i',
				'code'    => 'tls_certificate',
				'title'   => __( 'The mail server\'s security certificate was not trusted', 'iworksie-smtp' ),
				'explain' => __( 'The secure connection failed because the server\'s certificate does not match the host name, has expired, or your web server\'s certificate list is out of date.', 'iworksie-smtp' ),
				'fix'     => __( 'Use the exact host name your provider documents (for example smtp.provider.com rather than an IP address or mail.yourdomain.com). If that is correct, ask your host to update the server\'s CA certificates.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/SMTP connect\(\) failed|Failed to connect to server|Connection (refused|timed out)|php_network_getaddresses|getaddrinfo/i',
				'code'    => 'connect_failed',
				'title'   => __( 'Could not connect to the mail server', 'iworksie-smtp' ),
				'explain' => __( 'Your web server could not open a connection to the SMTP host. The host name may be wrong, or (very often) your hosting company blocks outgoing mail ports such as 25, 465 and 587.', 'iworksie-smtp' ),
				'fix'     => __( 'Check the host and port. Try port 587 with TLS, then 465 with SSL. If neither connects, ask your host whether outgoing SMTP is blocked, or use Microsoft 365 / Google, which send over normal HTTPS.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/must issue a STARTTLS|STARTTLS (is )?required|530 5\.7\.0/i',
				'code'    => '530',
				'title'   => __( 'The server requires encryption', 'iworksie-smtp' ),
				'explain' => __( 'The mail server will only accept a sign-in over an encrypted connection.', 'iworksie-smtp' ),
				'fix'     => __( 'Set Encryption to TLS (port 587) or SSL (port 465) on the Setup tab.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/550[ -]5\.7\.(1|60|708)|not permitted to relay|Relay access denied|sender address rejected|not owned by user/i',
				'code'    => '550',
				'title'   => __( 'The server refused the From address', 'iworksie-smtp' ),
				'explain' => __( 'The mail server will not send email from this From address with these sign-in details, usually because the address belongs to a different account or an unverified domain.', 'iworksie-smtp' ),
				'fix'     => __( 'Set the From address to the account you sign in with, or verify your domain / sender with your email provider. Keep "Force From address" switched on.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/Could not instantiate mail function|mail\(\) .*disabled/i',
				'code'    => 'mail_function',
				'title'   => __( 'This server cannot send email by itself', 'iworksie-smtp' ),
				'explain' => __( 'WordPress is using PHP\'s built-in mail function and your host has disabled it or it is not configured.', 'iworksie-smtp' ),
				'fix'     => __( 'Choose Microsoft 365, Google or Other SMTP on the Setup tab so email goes through a real mail service.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/cURL error 28|Operation timed out|cURL error 6|Could not resolve host|cURL error 7/i',
				'code'    => 'http_failed',
				'title'   => __( 'The website could not reach the mail service', 'iworksie-smtp' ),
				'explain' => __( 'The HTTPS request to Microsoft or Google timed out or could not be made. Your host may block outgoing connections or have a DNS problem.', 'iworksie-smtp' ),
				'fix'     => __( 'Try again in a minute. If it keeps happening, ask your host to allow outgoing HTTPS to graph.microsoft.com / login.microsoftonline.com or gmail.googleapis.com / oauth2.googleapis.com.', 'iworksie-smtp' ),
			),
			array(
				'mailer'  => '',
				'pattern' => '/Invalid address|You must provide at least one recipient/i',
				'code'    => 'invalid_address',
				'title'   => __( 'An email address is not valid', 'iworksie-smtp' ),
				'explain' => __( 'The To, From or Reply-To address is empty or not a valid email address.', 'iworksie-smtp' ),
				'fix'     => __( 'Check the From address on the Setup tab and the recipient address used by the form or plugin that sent this email.', 'iworksie-smtp' ),
			),
		);
	}
}
