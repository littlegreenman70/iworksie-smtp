=== iWorks.ie SMTP ===
Contributors: littlegreenman
Tags: smtp, email, microsoft 365, gmail, email log
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress email through Microsoft 365, Google or any SMTP server, with plain-English errors, deliverability checks and an email log. No upsells.

== Description ==

WordPress email that actually arrives, set up with step-by-step guides and errors you can understand.

**Everything is free. Nothing is locked behind a Pro version.** Microsoft 365, Google, the email log, alerts and diagnostics are all included.

= Send through =

* **Microsoft 365** using the Microsoft Graph API (Microsoft's supported way for apps to send mail now that SMTP password sign-in is being retired). Uses an app registration with the Mail.Send permission, so there is no browser redirect and no sign-in loop.
* **Google Workspace or Gmail** using the Gmail API and Google sign-in.
* **Any SMTP server**: your host, Brevo, Mailgun, SendGrid, Amazon SES, Postmark, Zoho and others, with one-click presets.

= Errors in plain English =

When something goes wrong you get what happened and how to fix it, not just a code. For example, AADSTS7000215 becomes "The Microsoft client secret is wrong: you probably copied the Secret ID instead of the Value". Covered: Microsoft sign-in (AADSTS) and Graph errors, Google OAuth and Gmail API errors, and common SMTP failures such as blocked ports, rejected passwords and certificate problems.

= Diagnostics =

One click checks:

* Sign-in to your mail service (without sending anything)
* SPF, DKIM and DMARC records for your From domain, with the exact record to add
* From address problems (free mailbox domains, mismatched accounts)
* Microsoft client secret expiry countdown, with an email two weeks before it expires
* Other email plugins that would conflict
* Saved secrets that can no longer be decrypted

= Email log that cannot run away =

Every email's date, recipient, subject, result and error is logged. Entries are deleted after 30 days and the log never keeps more than 10,000 entries (both adjustable), so it cannot grow to gigabytes. Message content is only kept if you switch it on (needed for resending), because emails such as password resets contain private links.

= Failure alerts =

If sending fails, you get one email an hour at most, sent with the server's own mail() so it does not depend on the connection that just failed. A notice also appears in the dashboard.

= Privacy =

Emails go straight from your server to Microsoft, Google or your SMTP provider. Nothing passes through iWorks. No tracking, no analytics. Passwords and secrets are encrypted in the database, or you can define them in wp-config.php instead.

Need a custom plugin? iWorks builds them: https://www.iworks.ie

== Installation ==

1. Upload the `iworksie-smtp` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New.
2. Activate the plugin.
3. Open **iWorks Tools → SMTP**, choose how to send, follow the guide for your provider, and save.
4. Use **Send a test**, then **Diagnostics**.

== Frequently Asked Questions ==

= Why does Microsoft 365 use an app registration instead of my password? =

Microsoft is switching off password ("Basic auth") sign-in for SMTP. The Graph API with an app registration is the supported replacement. The Setup tab walks you through it step by step; you need a Microsoft 365 administrator for the "Grant admin consent" step.

= Can I limit the Microsoft app to one mailbox? =

Yes, and we recommend it. The Mail.Send application permission allows sending as any mailbox in your organisation; your Microsoft 365 admin can restrict it to one mailbox with RBAC for Applications in Exchange Online.

= My Google connection stops working after a week =

Your Google Cloud app is still in "Testing". Google disconnects testing apps after 7 days. Set the audience to Internal (Workspace) or publish the app "In production" (Gmail), then connect again.

= Can I keep passwords out of the database? =

Yes. Add any of these to wp-config.php and the field is locked on the Setup tab:

`define( 'IWIESM_SMTP_PASSWORD', '...' );`
`define( 'IWIESM_MS_CLIENT_SECRET', '...' );`
`define( 'IWIESM_GOOGLE_CLIENT_SECRET', '...' );`

Values saved in the database are encrypted with a key derived from your site's security salts. If a security plugin rotates your salts, saved secrets can no longer be read; Diagnostics tells you, and you re-enter them. To avoid that, define your own key: `define( 'IWIESM_ENCRYPTION_KEY', 'a long random string' );`

= Does it work with my contact form / shop plugin? =

Yes. It works at the WordPress wp_mail() level, so anything that sends email through WordPress is covered, including attachments.

= Does it work with other SMTP plugins? =

Only run one. Diagnostics warns you if another email plugin is active.

= Is there a WP-CLI command? =

`wp iworksie-smtp test you@example.com` sends a test email and explains any error. `wp iworksie-smtp status` shows the active mailer.

= What is removed when I delete the plugin? =

Everything it created: settings, the email log table, cached tokens and scheduled tasks.

== External services ==

This plugin connects to these services only when you choose them as your mailer, to sign in and send your site's emails:

* **Microsoft identity platform and Microsoft Graph** (login.microsoftonline.com, graph.microsoft.com): when Microsoft 365 is selected, the site requests an access token with your tenant ID, client ID and client secret, and sends each email (recipients, subject, content and attachments) to Graph for delivery. [Microsoft Services Agreement](https://www.microsoft.com/servicesagreement), [Privacy Statement](https://privacy.microsoft.com/privacystatement).
* **Google OAuth and Gmail API** (accounts.google.com, oauth2.googleapis.com, gmail.googleapis.com): when Google is selected, you sign in with Google to authorise sending, the site exchanges and refreshes tokens with your client ID and secret, and sends each email to the Gmail API for delivery. Disconnecting revokes the token. [Google Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy).
* **Your SMTP provider**: when Other SMTP is selected, emails are sent to the server you enter, under that provider's terms.

Diagnostics also makes DNS lookups for your From domain's SPF, DKIM and DMARC records through your server's normal DNS resolver, only when you click Run diagnostics.

== Screenshots ==

1. Setup: choose Microsoft 365, Google or Other SMTP and follow the guide.
2. A failed test explained in plain English, with how to fix it.
3. Diagnostics: sign-in, SPF, DKIM, DMARC and more.
4. The email log.

== Changelog ==

= 1.0.0 =
* Use singular/plural forms for the email-log retention description.
* First release: Microsoft 365 (Graph API), Google (Gmail API) and SMTP mailers, plain-English errors, diagnostics, size-capped email log with resend, failure and secret-expiry alerts, WP-CLI commands.
