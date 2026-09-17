# Changelog

## 2.1.0 — 2026-09-17

### Added
- **Recurring income and expenses**: weekly, every two weeks, monthly, quarterly or yearly. Occurrences are added automatically (catching up after gaps, never twice), month-end dates are handled, and schedules can be stopped from the ledger.
- **Notifications** in the app and by email: budget alerts at 80% and over the limit, category caps, upcoming recurring payments, a monthly summary, and security notices. Each type can be turned off; email is optional. New Notifications page with unread badges.
- **Password reset by email** with single-use, hashed, one-hour tokens and rate limiting. Resetting or changing a password signs out every other session.
- **Email delivery**: built-in SMTP client (STARTTLS/SSL, AUTH), PHP `mail()`, or a log driver for development, behind a retrying queue. "Send a test email" in Settings.
- **Vector PDF reports** generated on the server, with real selectable text, replacing the screenshot-based export.
- **Automated tests**: 65 dependency-free unit and end-to-end tests (`php tests/run.php`) and a GitHub Actions workflow for PHP 8.1–8.4, SQLite and MySQL.
- `scripts/cron.php` for scheduled upkeep; "Coming up" panel on the overview.

### Changed
- **Project layout**: `public/` is now the only web-accessible folder, with a single front controller and clean URLs (`/expenses` instead of `expenses.php`; old URLs redirect). Code lives in `app/core`, `app/services` and `app/pages`.
- **No third-party requests**: fonts and Chart.js are served locally, which allows a strict Content-Security-Policy.
- SQLite runs in WAL mode so reads and writes don't block each other.
- **License**: FinPulse is now proprietary (all rights reserved) instead of MIT.

### Fixed
- Line breaks in email header values could collapse into double spaces.

## 2.0.0 — 2026-09-17

A full redesign and hardening pass.

### Design
- New design system in plain CSS (`assets/css/app.css`) replacing the Tailwind Play CDN, which compiled styles in the browser on every page load and isn't meant for production.
- Warm neutral palette with one accent, Geist + Instrument Serif type, tabular figures, and light/dark themes that follow the system with a manual override.
- Rebuilt every page: landing, sign in, create account, overview, income, expenses, budgets, savings, reports and settings. Added a 404 page.
- Replaced emoji icons with a consistent SVG icon set, added empty states, inline validation errors, toasts and a confirmation dialog.
- Fixed horizontal overflow on phones and added a proper mobile navigation drawer.

### Features
- Edit income and expense entries (previously add/delete only).
- Type new categories, sources and payment methods straight into the form.
- Payment method on expenses; month-by-month browsing, search and category filters.
- Budget pace marker, per-day remaining amount and plain-language status; period presets fill in end dates.
- Savings goals: monthly amount needed, "Add money" contributions, completed goals; edit savings account balances.
- Reports: date presets, previous-period comparison, adaptive day/week/month buckets, running balance, category breakdown with change, table views for charts.
- Currency preference (12 currencies), full CSV export and real account deletion in Settings.
- `scripts/seed-demo.php` for a demo account with a year of data.

### Fixes
- The overview's "Total balance" was actually this month's net; it's now labelled correctly.
- Curve smoothing on the charts drew values that weren't in the data, such as a running balance dipping below zero.
- Month boundaries used the database clock (UTC) instead of the app timezone.
- Flash messages ("Account created", "Welcome back") were set but never shown.
- Refreshing after submitting a form re-submitted it; all forms now use Post/Redirect/Get.
- CSRF tokens were single-use, so a second tab or the back button caused "Invalid request" errors.
- Visiting the site without a `.env` redirected to a `setup.php` that no longer exists.
- Search treated `%` and `_` in the search box as wildcards.
- Delete buttons were invisible on touch devices (shown only on hover).

### Security
- Budget updates could modify another user's budget category limits by posting their budget ID.
- Changing a password no longer works without the current password.
- Login throttling moved from the session (bypassable by discarding the cookie) to the database, with timing-safe handling of unknown users.
- Hardened session cookies, added security headers, and made sign-out a POST.
- `.htaccess` rules block the SQLite database, `.env`, and internal folders from being downloaded.
- CSV exports neutralise spreadsheet formulas.
- Errors are no longer displayed to visitors unless `APP_DEBUG=true`.

### Project
- Code organised into `includes/` modules with a single bootstrap; income and expenses share one controller.
- Added the missing MySQL schema, automatic migrations for both databases, `LICENSE`, and a rewritten README.
