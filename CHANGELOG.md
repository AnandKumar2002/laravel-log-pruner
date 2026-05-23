# Changelog

All notable changes to `parvion/laravel-log-pruner` are documented here.

This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
and the format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

> **Version format:** `MAJOR.MINOR.PATCH`
>
> - **MAJOR** — Breaking changes (e.g. renamed config keys, removed options)
> - **MINOR** — New features added in a backwards-compatible way
> - **PATCH** — Bug fixes, documentation, internal refactors

---

## [Unreleased]

### Added
- **`mail.header`** config key (`LOG_PRUNER_MAIL_HEADER`) — customise the
  title banner printed at the top of every email body.
- **`mail.footer`** config key (`LOG_PRUNER_MAIL_FOOTER`) — customise the
  sign-off / disclaimer line at the bottom of every email.
- **`mail.from.address`** config key (`LOG_PRUNER_MAIL_FROM_ADDRESS`) — override
  the From: sender address per-package without touching `MAIL_FROM_ADDRESS`.
- **`mail.from.name`** config key (`LOG_PRUNER_MAIL_FROM_NAME`) — override the
  From: display name shown to recipients.
- Email border/divider lines now auto-scale to match the width of the custom header.

---

## [1.0.0] — 2026-05-23

### 🎉 Initial Release

**Package:** `parvion/laravel-log-pruner`  
**Author:** Anand Kumar (Parvion)  
**PHP:** `^8.0` | **Laravel:** `10.x`, `11.x`, `12.x`

#### Added

- **Phase 1 — Atomic Log Rotation**
  - OS-level `rename()` moves `laravel.log` → `laravel-backup-YYYY-MM-DD-HHMMSS.log`
  - Immediately `touch()`es a fresh `laravel.log` with `chmod(0664)`
  - Zero window where the log file is absent

- **Phase 2 — Backup File Pruning**
  - Scans `storage/logs/` for `laravel-backup-*.log` files only
  - Deletes files older than the configured retention threshold
  - Per-file info table showing name, size, age, expiry date, and SAFE/EXPIRED status
  - Info table also included in the email report (`backup.show_info_in_email`)

- **Phase 3 — Queue Worker Restart**
  - Calls `Artisan::call('queue:restart')` to gracefully signal all workers
  - Workers finish current job before restarting — no jobs killed mid-flight

- **Phase 4 — Dynamic Database Table Pruning**
  - Accepts a comma-separated list of table names via `--tables` or config
  - Schema safety checks: verifies table existence and `created_at` column before DELETE
  - Tracks rows deleted per table; skips invalid tables with a warning

- **Phase 5 — Multi-Recipient Email Report**
  - Uses `Mail::raw()` — no Blade views, works with API drivers (ZeptoMail, Postmark, Mailgun, SES, Resend)
  - One individually addressed email per recipient (no CC/BCC)
  - Report contains: config summary, feature toggle states, backup file table, DB pruning stats

- **Publishable Config** (`config/log-pruner.php`)
  - Master kill-switch (`enabled`)
  - Default retention days (`days`)
  - Per-phase feature toggles (`features.*`)
  - Default tables (`tables`)
  - Backup info display options (`backup.*`)
  - Mail settings (`mail.*`)
  - All keys driven by `.env` variables

- **CLI Option Override** — `--days`, `--tables`, `--email` override config values
- **Priority chain:** CLI → config → built-in default
- **Laravel Auto-Discovery** via `extra.laravel.providers` in `composer.json`
- **`LogPrunerServiceProvider`** with `mergeConfigFrom()` and `publishes()`
- **`README.md`** with full usage examples, scheduling guide, and email report samples
- **`CHANGELOG.md`** — this file
- **`CONTRIBUTING.md`** — contribution guide
- **`LICENSE`** — MIT

---

<!-- Link definitions (for GitHub diff rendering) -->
[Unreleased]: https://github.com/AnandKumar2002/laravel-log-pruner/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/AnandKumar2002/laravel-log-pruner/releases/tag/v1.0.0
