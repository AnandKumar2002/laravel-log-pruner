# Parvion Laravel Log Pruner

[![PHP Version](https://img.shields.io/badge/php-%5E8.0-8892BF)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-22c55e.svg)](LICENSE)
[![Changelog](https://img.shields.io/badge/changelog-CHANGELOG.md-0ea5e9)](CHANGELOG.md)
[![Contributing](https://img.shields.io/badge/contributing-CONTRIBUTING.md-f59e0b)](CONTRIBUTING.md)
[![Artisan Command](https://img.shields.io/badge/artisan-logs%3Arotate--and--prune-6366f1)](https://laravel.com/docs/artisan)

> A zero-dependency, enterprise-ready Laravel package that atomically rotates
> your log file, prunes old backups and database rows, restarts queue workers,
> and emails a detailed report — all from one Artisan command, fully controlled
> from your `config/log-pruner.php` and `.env`.

---

## Table of Contents

1. [Features](#features)
2. [How It Works — The 5 Phases](#how-it-works--the-5-phases)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [Configuration](#configuration)
6. [Usage](#usage)
7. [Scheduling](#scheduling)
8. [Email Report Example](#email-report-example)
9. [Backup Info Table Example](#backup-info-table-example)
10. [.env Quick Reference](#env-quick-reference)
11. [Disabling Features](#disabling-features)
12. [License](#license)

---

## Features

| Feature | Details |
|---|---|
| 🔄 **Atomic log rotation** | Uses OS-level `rename()` — zero window where log is missing |
| 📋 **Backup info table** | Shows every backup: size, age, expiry date, and SAFE/EXPIRED status |
| 🗑️ **Backup file pruning** | Auto-deletes backups older than your configured retention period |
| 🔧 **Queue worker restart** | Gracefully signals workers to release old file handles |
| 🗃️ **Dynamic DB pruning** | Safely prunes any database table with schema existence checks |
| 📧 **Multi-recipient email** | Plain-text report via `Mail::raw()` — works with ZeptoMail, Postmark, Mailgun |
| 🛡️ **Schema safety checks** | Verifies table + `created_at` column exist before any DELETE |
| ⚙️ **Fully config-driven** | Every feature toggled from `config/log-pruner.php` and `.env` |
| 🎛️ **CLI overrides config** | Pass `--days`, `--tables`, `--email` to override config on the fly |

---

## How It Works — The 5 Phases

Each phase can be individually enabled or disabled in the config.

```
┌─────────────────────────────────────────────────────────────────┐
│                  php artisan logs:rotate-and-prune              │
├─────────────────────────────────────────────────────────────────┤
│  PHASE 1 — Log Rotation       (features.log_rotation)          │
│    rename(laravel.log → laravel-backup-2026-05-23-020000.log)  │
│    touch(laravel.log)  chmod(0664)                             │
├─────────────────────────────────────────────────────────────────┤
│  PHASE 2 — Backup Pruning     (features.backup_pruning)        │
│    Scan storage/logs/ for laravel-backup-*.log                 │
│    Print info table (name, size, age, expiry, status)          │
│    Delete files older than retention threshold                  │
├─────────────────────────────────────────────────────────────────┤
│  PHASE 3 — Queue Restart      (features.queue_restart)         │
│    Artisan::call('queue:restart')                              │
│    Workers gracefully restart → open fresh laravel.log         │
├─────────────────────────────────────────────────────────────────┤
│  PHASE 4 — Database Pruning   (features.db_pruning)            │
│    For each table → check exists → check created_at column     │
│    DELETE WHERE created_at < cutoff_date                        │
├─────────────────────────────────────────────────────────────────┤
│  PHASE 5 — Email Report       (features.email_report)          │
│    Validate each recipient → Mail::raw() → one email per addr  │
│    Report includes all phases + backup file table              │
└─────────────────────────────────────────────────────────────────┘
```

---

## Requirements

| Dependency | Version |
|---|---|
| **PHP** | `^8.0` (8.0, 8.1, 8.2, 8.3+) |
| **Laravel** | `10.x`, `11.x`, `12.x` |
| **Carbon** | Included with Laravel |

---

## Installation

### Step 1 — Add the package

**Option A — Local development (path repository)**

Add to your **Laravel app's** `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-log-pruner"
        }
    ],
    "require": {
        "parvion/laravel-log-pruner": "*"
    }
}
```

Then run:

```bash
composer require parvion/laravel-log-pruner
```

**Option B — From Packagist (once published)**

```bash
composer require parvion/laravel-log-pruner
```

> Laravel's auto-discovery registers the service provider automatically.
> No manual `config/app.php` edit required.

---

### Step 2 — Publish the config

```bash
php artisan vendor:publish --tag=log-pruner-config
```

This copies the config to your app at `config/log-pruner.php`.
You can now customise every setting without touching the package source.

---

### Step 3 — Set your `.env` values

Open your `.env` and add:

```env
# How many days to keep backup files and DB rows
LOG_PRUNER_DAYS=15

# Tables to prune (comma-separated)
LOG_PRUNER_TABLES=system_logs,audit_logs

# Email recipients for the report (comma-separated)
LOG_PRUNER_MAIL_ENABLED=true
LOG_PRUNER_MAIL_RECIPIENTS=admin@yourdomain.com,devops@yourdomain.com
```

---

### Step 4 — Verify the command is registered

```bash
php artisan list | grep logs
# Expected output:
#  logs:rotate-and-prune   Atomically rotates laravel.log, prunes old backups...
```

---

### Step 5 — Run it manually to test

```bash
php artisan logs:rotate-and-prune
```

---

## Configuration

After publishing, edit `config/log-pruner.php`. Every key maps to a `.env` variable:

```php
return [

    // ── Master on/off switch ──────────────────────────────────────────────
    'enabled' => env('LOG_PRUNER_ENABLED', true),

    // ── Retention period (days) ───────────────────────────────────────────
    'days' => env('LOG_PRUNER_DAYS', 15),

    // ── Enable / disable each phase independently ─────────────────────────
    'features' => [
        'log_rotation'   => env('LOG_PRUNER_FEATURE_ROTATION',      true),
        'backup_pruning' => env('LOG_PRUNER_FEATURE_BACKUP_PRUNING', true),
        'queue_restart'  => env('LOG_PRUNER_FEATURE_QUEUE_RESTART',  true),
        'db_pruning'     => env('LOG_PRUNER_FEATURE_DB_PRUNING',     true),
        'email_report'   => env('LOG_PRUNER_FEATURE_EMAIL',          true),
    ],

    // ── Tables to DELETE old rows from ────────────────────────────────────
    'tables' => explode(',', env('LOG_PRUNER_TABLES', 'system_logs')),

    // ── Backup file display settings ──────────────────────────────────────
    'backup' => [
        'show_info'          => env('LOG_PRUNER_BACKUP_SHOW_INFO',       true),
        'show_info_in_email' => env('LOG_PRUNER_BACKUP_SHOW_INFO_EMAIL', true),
        'date_format'        => env('LOG_PRUNER_BACKUP_DATE_FORMAT',     'Y-m-d H:i'),
    ],

    // ── Email report settings ─────────────────────────────────────────────
    'mail' => [
        'enabled'        => env('LOG_PRUNER_MAIL_ENABLED',        true),
        'recipients'     => array_filter(array_map('trim',
                              explode(',', env('LOG_PRUNER_MAIL_RECIPIENTS', '')))),
        'subject_prefix' => env('LOG_PRUNER_MAIL_SUBJECT_PREFIX', '[Log Pruner]'),
    ],
];
```

---

## Usage

### Basic — uses all config defaults

```bash
php artisan logs:rotate-and-prune
```

### Override retention period

```bash
# Keep 30 days instead of config default
php artisan logs:rotate-and-prune --days=30
```

### Override which tables to prune

```bash
# Prune three tables in one run
php artisan logs:rotate-and-prune --tables=system_logs,audit_logs,api_request_logs
```

### Override email recipients

```bash
# Send report to specific people for this run only
php artisan logs:rotate-and-prune --email=cto@example.com,sre@example.com
```

### Full override example

```bash
php artisan logs:rotate-and-prune \
  --days=7 \
  --tables=system_logs,audit_logs \
  --email=admin@example.com,devops@example.com
```

> **Priority:** CLI option → config/log-pruner.php → built-in default

---

## Scheduling

### Laravel 11+ (`routes/console.php`)

```php
<?php

use Illuminate\Support\Facades\Schedule;

// All settings come from config/log-pruner.php automatically.
// Pass CLI options here only if you need to override config for this schedule.
Schedule::command('logs:rotate-and-prune')
    ->dailyAt('02:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();
```

**With explicit overrides (optional):**

```php
Schedule::command('logs:rotate-and-prune', [
    '--days'   => 15,
    '--tables' => 'system_logs,audit_logs',
    '--email'  => 'devops@yourdomain.com',
])
->dailyAt('02:00')
->timezone('Asia/Kolkata')
->withoutOverlapping()
->runInBackground();
```

---

### Laravel 10 (`app/Console/Kernel.php`)

```php
<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('logs:rotate-and-prune')
            ->dailyAt('02:00')
            ->timezone('Asia/Kolkata')
            ->withoutOverlapping()
            ->runInBackground();
    }
}
```

---

### Server Cron Entry

Add this to your server (`crontab -e`) — this is the **only** cron entry you need:

```cron
* * * * * cd /var/www/your-laravel-app && php artisan schedule:run >> /dev/null 2>&1
```

> The cron runs every minute. Laravel's scheduler handles the `02:00 Asia/Kolkata` timing internally via `->dailyAt()` and `->timezone()`.
> **Never** put the `logs:rotate-and-prune` command directly in cron — you would bypass `withoutOverlapping()` and timezone support.

---

## Email Report Example

```
=========================================================
  PARVION LARAVEL LOG PRUNER — AUTOMATED REPORT
=========================================================

  Application   : My Laravel App
  URL           : https://myapp.com
  Report Time   : 2026-05-23 02:00:05 IST

---------------------------------------------------------
  CONFIGURATION
---------------------------------------------------------

  Retention Period : 15 days
  Cutoff Date      : 2026-05-08 02:00:05 IST
  Tables           : system_logs, audit_logs
  Recipients       : admin@myapp.com, devops@myapp.com

---------------------------------------------------------
  FEATURE STATUS
---------------------------------------------------------

  Log Rotation       : Enabled
  Backup Pruning     : Enabled
  Queue Restart      : Enabled
  DB Pruning         : Enabled
  Email Report       : Enabled

---------------------------------------------------------
  LOG FILE ROTATION
---------------------------------------------------------

  New Backup File     : laravel-backup-2026-05-23-020005.log
  Old Backups Deleted : 2

---------------------------------------------------------
  BACKUP FILES STATUS (retention: 15 days)
---------------------------------------------------------

  ------------------------------------------------------------------------
  File Name                           Size    Created          Age  Status
  ------------------------------------------------------------------------
  laravel-backup-2026-05-20-020005   2.34 MB  2026-05-20 02:00  3d  SAFE (expires 2026-06-04, in 12d)
  laravel-backup-2026-05-07-020002   1.12 MB  2026-05-07 02:00 16d  DELETED
  laravel-backup-2026-04-30-020001    987 KB  2026-04-30 02:00 23d  DELETED
  ------------------------------------------------------------------------

---------------------------------------------------------
  QUEUE WORKERS
---------------------------------------------------------

  Restart Signaled : Yes

---------------------------------------------------------
  DATABASE TABLE PRUNING
---------------------------------------------------------

  • `system_logs`                  142 rows deleted
  • `audit_logs`                    87 rows deleted

---------------------------------------------------------
  This is an automated message from the Log Pruner.
  Please do not reply to this email.
=========================================================
```

---

## Backup Info Table Example

When `LOG_PRUNER_BACKUP_SHOW_INFO=true`, Phase 2 prints this in the console:

```
  Backup Files Status (retention: 15 days)
  ────────────────────────────────────────────────────────────────────────────────
  File Name                              Size     Created          Days Kept  Expires On          Status
  ────────────────────────────────────────────────────────────────────────────────
  laravel-backup-2026-05-20-020005.log   2.34 MB  2026-05-20 02:00       3d  2026-06-04 02:00  SAFE (expires in 12d)
  laravel-backup-2026-05-10-020003.log   1.87 MB  2026-05-10 02:00      13d  2026-05-25 02:00  SAFE (expires in 2d)
  laravel-backup-2026-05-07-020002.log   1.12 MB  2026-05-07 02:00      16d  2026-05-22 02:00  EXPIRED → deleted
  laravel-backup-2026-04-30-020001.log    987 KB  2026-04-30 02:00      23d  2026-05-15 02:00  EXPIRED → deleted
  ────────────────────────────────────────────────────────────────────────────────
```

---

## .env Quick Reference

```env
# ╔══════════════════════════════════════════════════════════════╗
# ║                 LOG PRUNER CONFIGURATION                     ║
# ╚══════════════════════════════════════════════════════════════╝

# Master on/off switch (set false to pause everything)
LOG_PRUNER_ENABLED=true

# Default retention in days (can be overridden with --days=N)
LOG_PRUNER_DAYS=15

# ── Feature Toggles ──────────────────────────────────────────
LOG_PRUNER_FEATURE_ROTATION=true         # Phase 1 — atomic log rename + touch
LOG_PRUNER_FEATURE_BACKUP_PRUNING=true   # Phase 2 — delete expired backup files
LOG_PRUNER_FEATURE_QUEUE_RESTART=true    # Phase 3 — graceful queue:restart
LOG_PRUNER_FEATURE_DB_PRUNING=true       # Phase 4 — delete old DB rows
LOG_PRUNER_FEATURE_EMAIL=true            # Phase 5 — send email report

# ── Database Tables ───────────────────────────────────────────
LOG_PRUNER_TABLES=system_logs,audit_logs,api_request_logs

# ── Backup Info Display ───────────────────────────────────────
LOG_PRUNER_BACKUP_SHOW_INFO=true         # Show table in console
LOG_PRUNER_BACKUP_SHOW_INFO_EMAIL=true   # Include table in email
LOG_PRUNER_BACKUP_DATE_FORMAT="Y-m-d H:i"

# ── Email Report ──────────────────────────────────────────────
LOG_PRUNER_MAIL_ENABLED=true
LOG_PRUNER_MAIL_RECIPIENTS=admin@yourdomain.com,devops@yourdomain.com
LOG_PRUNER_MAIL_SUBJECT_PREFIX=[Log Pruner]
```

---

## Disabling Features

| What you want to stop | `.env` setting |
|---|---|
| Stop everything (maintenance) | `LOG_PRUNER_ENABLED=false` |
| Stop rotating the log file | `LOG_PRUNER_FEATURE_ROTATION=false` |
| Stop deleting old backup files | `LOG_PRUNER_FEATURE_BACKUP_PRUNING=false` |
| Skip queue worker restart | `LOG_PRUNER_FEATURE_QUEUE_RESTART=false` |
| Stop deleting old DB rows | `LOG_PRUNER_FEATURE_DB_PRUNING=false` |
| Stop sending email reports | `LOG_PRUNER_MAIL_ENABLED=false` |
| Disable email feature entirely | `LOG_PRUNER_FEATURE_EMAIL=false` |

---

## Mail Driver Compatibility

The package uses `Mail::raw()` which works with **any** mail driver — no Blade
templates are required:

| Driver | Compatible |
|---|---|
| SMTP | ✅ |
| ZeptoMail | ✅ |
| Mailgun | ✅ |
| Postmark | ✅ |
| Resend | ✅ |
| Amazon SES | ✅ |
| Log (local dev) | ✅ |

---

## License

MIT — © [Anand Kumar (Parvion)](mailto:anandkumar101002@gmail.com) — see [LICENSE](LICENSE)

---

## Contributing

Bug reports, feature requests, and pull requests are welcome!  
Please read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting a PR.

---

## Changelog

All notable changes between versions are documented in [CHANGELOG.md](CHANGELOG.md).  
This project follows [Semantic Versioning](https://semver.org) and [Keep a Changelog](https://keepachangelog.com).
