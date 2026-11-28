# iWorks.ie SMTP

Free WordPress plugin: send email through **Microsoft 365 (Graph API)**, **Google (Gmail API)** or **any SMTP server**, with plain-English errors, SPF/DKIM/DMARC diagnostics, a size-capped email log and failure alerts. Part of the iWorks Tools suite; shares the `iworksie/tools-core` library (bundled with Strauss).

- Slug / text domain: `iworksie-smtp`
- Namespace: `IWorksIE\SMTP` · prefix `iwiesm_` / `IWIESM_`
- Requires WordPress 6.5+, PHP 7.4+

## Development

Clone next to `iworks-tools-core` (the Composer path repository) or let Composer use the GitHub VCS repository:

```bash
composer install          # also runs Strauss into vendor-prefixed/
composer lint             # PHPCS: WordPress standard + PHPCompatibilityWP 7.4+
composer analyse          # PHPStan level 6
composer test:unit
bash bin/install-wp-tests.sh iworks_tests_sm root '' 127.0.0.1 latest
composer test:integration
composer build            # build/iworksie-smtp.zip
```

## How sending works

| Mailer | Delivery | Hook |
|---|---|---|
| Microsoft 365 | `POST graph.microsoft.com/v1.0/users/{mailbox}/sendMail` with the MIME message, app-only token (client credentials) | `wp_mail` filter swaps in `Mail\Catcher` (a PHPMailer subclass) |
| Google | `POST gmail.googleapis.com/upload/gmail/v1/users/me/messages/send?uploadType=media`, refresh-token OAuth | same |
| Other SMTP | PHPMailer SMTP | `phpmailer_init` |

WordPress builds the message as normal; for the API mailers only the final delivery step is replaced. See `DECISIONS.md` for the reasoning behind each choice.

## wp-config.php constants

| Constant | Purpose |
|---|---|
| `IWIESM_SMTP_PASSWORD` | SMTP password / API key |
| `IWIESM_MS_CLIENT_SECRET` | Microsoft client secret |
| `IWIESM_GOOGLE_CLIENT_SECRET` | Google client secret |
| `IWIESM_ENCRYPTION_KEY` | Key for secrets stored in the database (otherwise derived from the salts) |

## Hooks

- `iwiesm_pre_dns_txt`, `iwiesm_pre_dns_cname`: short-circuit DNS lookups used by Diagnostics.
