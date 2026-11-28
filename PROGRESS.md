# Progress

## 1.0.0 (2026-11-26)

- [x] Settings, encryption, wp-config constants
- [x] Microsoft 365 (Graph, client credentials), Google (Gmail API, OAuth), SMTP
- [x] Plain-English error translator (AADSTS, Graph, Google, SMTP)
- [x] Diagnostics: mailer, sign-in, From, SPF, DKIM, DMARC, secret expiry, recent failures, conflicts, stored secrets
- [x] Email log (capped), resend, failure + expiry alerts, admin notice
- [x] WP-CLI `wp iworksie-smtp test|status`
- [x] Unit + integration tests, CI matrix PHP 7.4–8.3 × WP 6.5–latest
- [ ] Real-tenant test (Microsoft 365 and Google) by Cormac
- [ ] Screenshots and artwork

## CI fixes (2026-11-27)

- [x] install-wp-tests.sh tag matching for WP 6.5 / 6.7 integration matrix
- [x] Plugin Check: CatcherBase ABSPATH, Dns/Screens OffloadedContent false positives

## 2026-11-28 final 1.0.0 pass
- Plural forms for log retention copy. Plugin Check DirectDB false positives ignored with line-level reasons (custom `{$wpdb->prefix}iwiesm_log` table).
- Lint/analyse/unit/integration/build/Plugin Check re-run; zip refreshed in ~/Downloads.

