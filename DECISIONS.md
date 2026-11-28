# Decisions

Choices made while building without a review round. Each one is easy to revisit.

| Date (Europe/Dublin) | Decision | Reason |
|----------------------|----------|--------|
| 2026-11-26 | Slug `iworksie-smtp`, namespace `IWorksIE\SMTP`, prefix `iwiesm` / `IWIESM_` | Build plan: `sm` is SMTP, `sp` is Speed Monitor. |
| 2026-11-26 | Microsoft 365 uses the **client credentials** flow (app-only, `Mail.Send` application permission) rather than delegated sign-in | No redirect URI and no browser round-trip, so the "frustrating loop" complaint from the research cannot happen. Tokens do not expire with a user's password or MFA. Trade-off: needs admin consent, and the permission covers every mailbox unless the admin scopes it with RBAC for Applications (the guide recommends that). |
| 2026-11-26 | Send through Graph with raw MIME (`POST /users/{mailbox}/sendMail`, `text/plain` base64) and Gmail with raw MIME via the media upload endpoint | WordPress/PHPMailer still builds the message (headers, charset, attachments) exactly as normal, so behaviour matches core. Only delivery is replaced. |
| 2026-11-26 | Swap in our own PHPMailer subclass (`Catcher`) on the `wp_mail` filter, only for API mailers | Same technique as the leading plugins, without replacing the pluggable `wp_mail()`. SMTP mode leaves WordPress's own PHPMailer in place and only configures it in `phpmailer_init`. The base class follows WordPress (`WP_PHPMailer` on 6.8+, `PHPMailer` before) so core's translated messages stay. |
| 2026-11-26 | Build MIME as the `sendmail` mailer during `preSend()` | Only then does PHPMailer put To, Subject and **Bcc** in the headers; the APIs read recipients from there. FluentSMTP had a Bcc bug here. |
| 2026-11-26 | With Microsoft, the From address is always the configured mailbox | Graph sends as the mailbox in the URL; any other From needs Send As rights and fails with ErrorSendAsDenied. |
| 2026-11-26 | Graph size limit enforced at ~2.9 MB of MIME with a translated error; no upload sessions in v1 | Graph's simple send accepts 4 MB per request (base64 inflates by a third). Upload sessions need draft + attachment APIs and `Mail.ReadWrite`; not worth it for v1. Gmail's media endpoint takes up to 35 MB. |
| 2026-11-26 | Google uses `openid email gmail.send` scopes and reads the account address from the ID token | `gmail.send` alone cannot read the profile, and asking for read scopes would be a much scarier consent screen. |
| 2026-11-26 | Google redirect URI is `admin.php?page=iworksie-smtp`; the OAuth `state` is a WordPress nonce | Exact-match URI that is easy to copy; nonce ties the callback to the logged-in admin. |
| 2026-11-26 | Secrets encrypted with libsodium secretbox, key = sha256 of `IWIESM_ENCRYPTION_KEY` or `LOGGED_IN_KEY . LOGGED_IN_SALT`; constants in wp-config override | Sodium is in PHP 7.2+ and WordPress ships sodium_compat, so no openssl dependency. Salt rotation is detected and reported in Diagnostics. |
| 2026-11-26 | Log stores metadata only by default; message + headers only when "keep content" is on (needed for resend). Default retention 30 days / 10,000 rows, pruned daily | Password-reset links in stored bodies are a real privacy risk; FluentSMTP's 90 GB log table was a top complaint. |
| 2026-11-26 | Failure alerts go through PHP `mail()` (routing bypassed), on `shutdown`, max one per hour | Sending the alert through the connection that just failed would fail too; deferring avoids nesting `wp_mail()` inside `wp_mail_failed`. |
| 2026-11-26 | Microsoft secret expiry is a date the admin enters (from the Entra "Expires" column), not read from Graph | Reading it needs `Application.Read.All`, a far broader permission than sending mail. |
| 2026-11-26 | Diagnostics run through core's Checks runner (one AJAX call per check); the SMTP sign-in test uses PHPMailer's SMTP class (connect, STARTTLS, AUTH, QUIT) without sending | Reuses the shared UI; live network calls only happen when the admin clicks Run diagnostics. |
| 2026-11-26 | DKIM is checked by provider selectors (Microsoft `selector1/2`, Google `google`, a short list for SMTP providers) plus an optional selector field | DKIM selectors cannot be discovered from DNS. |
| 2026-11-26 | DMARC organisational domain = walk parents down to two labels | Avoids bundling the public suffix list; good enough for a diagnostic. |
| 2026-11-26 | Multisite: settings per site, capability `manage_network_options` (super admins only) | Same rule as Health Check; mail credentials are sensitive. Network-wide settings can come later. |
| 2026-11-26 | No fallback connection, no import from other SMTP plugins, no routing by From address in v1 | Keeps v1 small. All three are good v1.1 candidates (FluentSMTP has them). |
| 2026-11-26 | Guides are text steps with deep links, not screenshots | Microsoft/Google consoles change often; text is easier to keep correct. Screenshots can be added to the wordpress.org listing. |
| 2026-11-26 | Quality CI runs on PHP 8.3 (Strauss needs 8); integration tests run on PHP 7.4, 8.0, 8.2, 8.3 across WordPress 6.5, 6.7 and latest; built zip is `php -l` checked on 7.4 | Same pattern as Health Check, plus real runtime tests on 7.4. |
| 2026-11-27 | `bin/install-wp-tests.sh` resolves X.Y to the latest X.Y.Z and checks out matching `tags/X.Y.Z` tests (not trunk) | Trunk's mock-mailer requires `class-wp-phpmailer.php` (6.8+); WP 6.5/6.7 CI jobs were failing on that mismatch. |
| 2026-11-27 | Plugin Check: ABSPATH guard in `CatcherBase.php`; split `amazonaws.com` host strings; phpcs:ignore on Google client-id help text | Clears OffloadedContent false positives and missing_direct_file_access_protection. DirectDB warnings left (table name is `$wpdb->prefix . 'iwiesm_log'`, same as Search & Replace). |

## Candidates for core

- A generic "settings form row" helper (text / secret / checkbox) would help SMTP, Speed Monitor and Search & Replace.
- `UI::status_badge` is reused for log rows; a small `UI::explain_error()` block could move to core if Security Audit needs it.

## Before wordpress.org submission

- Real screenshots (Setup, failed test explained, Diagnostics, Log).
- Banner/icon artwork (placeholders not yet added to `.wordpress-org/`).
- Test once against a real Microsoft 365 tenant and a real Google account (CI uses mocked HTTP for both).

### 2026-11-28 plurals / final pass
- Plural forms for log retention copy. Plugin Check DirectDB false positives ignored with line-level reasons (custom `{$wpdb->prefix}iwiesm_log` table).

