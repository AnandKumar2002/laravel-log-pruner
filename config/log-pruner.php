<?php

/*
|=============================================================================
| Parvion Laravel Log Pruner — Configuration File
|=============================================================================
|
| HOW TO PUBLISH THIS FILE TO YOUR APP:
|   php artisan vendor:publish --tag=log-pruner-config
|
| WHERE IT LIVES AFTER PUBLISHING:
|   config/log-pruner.php
|
| HOW SETTINGS ARE RESOLVED (highest priority first):
|   1. CLI option  →  e.g. --days=7 --email=a@x.com
|   2. This file   →  edit the values directly below
|   3. .env file   →  fallback for environment-specific overrides
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | MASTER SWITCH
    |--------------------------------------------------------------------------
    | Set to false to completely pause the log pruner without removing it from
    | your schedule — ideal for maintenance windows or deployments.
    |
    | .env : LOG_PRUNER_ENABLED=false
    */
    'enabled' => env('LOG_PRUNER_ENABLED', true),


    /*
    |--------------------------------------------------------------------------
    | RETENTION PERIOD
    |--------------------------------------------------------------------------
    | How many days to keep log backup files and database rows.
    | Anything older than this number of days will be deleted.
    |
    | CLI override : --days=30
    | .env         : LOG_PRUNER_DAYS=15
    */
    'days' => env('LOG_PRUNER_DAYS', 15),


    /*
    |--------------------------------------------------------------------------
    | DATABASE TABLES TO PRUNE
    |--------------------------------------------------------------------------
    | Add every table you want old rows deleted from.
    | Before deleting, the command verifies:
    |   ✓ The table exists in the database
    |   ✓ The table has a `created_at` column
    | Unrecognised tables are safely skipped — never a fatal error.
    |
    | ── CUSTOM RETENTION DAYS PER TABLE ──────────────────────────────────────
    | You can now specify different retention periods for different tables!
    | Just map the table name to the number of days you want to keep:
    |
    |   'tables' => [
    |       'system_logs' => 10,  // Keep system logs for 10 days
    |       'audit_logs'  => 30,  // Keep audit logs for 30 days
    |   ],
    |
    | If you do not specify days, it falls back to the default 'days' above:
    |
    |   'tables' => [
    |       'system_logs' => 10,  // Keeps system logs for 10 days
    |       'audit_logs',         // Keeps audit logs for global retention (15 days)
    |   ],
    |
    | CLI overrides support specific days using a colon:
    |   php artisan logs:rotate-and-prune --tables=system_logs:10,audit_logs:30
    */
    'tables' => [
        // 'system_logs' => 10,
        // 'audit_logs'  => 20,
        // 'api_request_logs',
    ],


    /*
    |--------------------------------------------------------------------------
    | FEATURE TOGGLES
    |--------------------------------------------------------------------------
    | Turn each phase on or off independently.
    | Set any value to false to skip that phase entirely.
    |
    |  Phase 1 — log_rotation   : rename laravel.log → backup, touch fresh file
    |  Phase 2 — backup_pruning : delete expired laravel-backup-*.log files
    |  Phase 3 — queue_restart  : signal queue workers to restart gracefully
    |  Phase 4 — db_pruning     : delete old rows from the tables above
    |  Phase 5 — email_report   : send the summary report to recipients below
    */
    'features' => [
        'log_rotation'   => env('LOG_PRUNER_FEATURE_ROTATION',      true),
        'backup_pruning' => env('LOG_PRUNER_FEATURE_BACKUP_PRUNING', true),
        'queue_restart'  => env('LOG_PRUNER_FEATURE_QUEUE_RESTART',  true),
        'db_pruning'     => env('LOG_PRUNER_FEATURE_DB_PRUNING',     true),
        'email_report'   => env('LOG_PRUNER_FEATURE_EMAIL',          true),
    ],


    /*
    |--------------------------------------------------------------------------
    | BACKUP FILE INFO DISPLAY
    |--------------------------------------------------------------------------
    | show_info         → Print a per-file info table in the console (Phase 2).
    |                     Shows: name, size, created date, age, expiry, status.
    |
    | show_info_in_email → Include the same table inside the email report.
    |
    | date_format        → PHP date() format used in the info table columns.
    |                      'Y-m-d H:i'  →  2026-05-15 02:00
    */
    'backup' => [
        'show_info'          => env('LOG_PRUNER_BACKUP_SHOW_INFO',       true),
        'show_info_in_email' => env('LOG_PRUNER_BACKUP_SHOW_INFO_EMAIL', true),
        'date_format'        => env('LOG_PRUNER_BACKUP_DATE_FORMAT',     'Y-m-d H:i'),
    ],


    /*
    |--------------------------------------------------------------------------
    | EMAIL REPORT
    |--------------------------------------------------------------------------
    */
    'mail' => [

        /*
        | ── ON / OFF ─────────────────────────────────────────────────────────
        | Hard switch. Set to false to stop all emails immediately without
        | touching the schedule or the feature toggle above.
        |
        | .env : LOG_PRUNER_MAIL_ENABLED=false
        */
        'enabled' => env('LOG_PRUNER_MAIL_ENABLED', true),


        /*
        | ── RECIPIENTS ────────────────────────────────────────────────────────
        | Add every email address that should receive the report.
        | Each address gets its own individual email (not CC / BCC).
        |
        | HOW TO ADD MULTIPLE EMAILS — just add more lines to the array:
        |
        |   'recipients' => [
        |       'admin@yourapp.com',
        |       'devops@yourapp.com',
        |       'cto@yourapp.com',
        |   ],
        |
        | You can also use .env for CI/CD environments (both are merged):
        |   LOG_PRUNER_MAIL_RECIPIENTS=admin@yourapp.com,devops@yourapp.com
        |
        | CLI override (highest priority, overrides everything below):
        |   --email=admin@yourapp.com,devops@yourapp.com
        */
        'recipients' => [
            // Add your email addresses here — one per line:
            // 'admin@yourapp.com',
            // 'devops@yourapp.com',
            // 'cto@yourapp.com',
        ],


        /*
        | ── SUBJECT LINE ──────────────────────────────────────────────────────
        | Text prepended to the email subject for easy inbox filtering.
        | Final result: "[Log Pruner] Rotation & Pruning Report — 2026-05-23"
        |
        | .env : LOG_PRUNER_MAIL_SUBJECT_PREFIX=[Log Pruner]
        */
        'subject_prefix' => env('LOG_PRUNER_MAIL_SUBJECT_PREFIX', '[Log Pruner]'),


        /*
        | ── EMAIL HEADER ──────────────────────────────────────────────────────
        | The large title banner at the very top of the email body.
        | Change to your company or app name so recipients know who sent it.
        |
        | .env    : LOG_PRUNER_MAIL_HEADER="ACME Corp — Log Report"
        | Default : "PARVION LARAVEL LOG PRUNER — AUTOMATED REPORT"
        */
        'header' => env(
            'LOG_PRUNER_MAIL_HEADER',
            'PARVION LARAVEL LOG PRUNER — AUTOMATED REPORT'
        ),


        /*
        | ── EMAIL FOOTER ──────────────────────────────────────────────────────
        | Sign-off / disclaimer at the very bottom of the email.
        | Useful for adding a helpdesk address or support URL.
        |
        | .env    : LOG_PRUNER_MAIL_FOOTER="Questions? Contact ops@acme.com"
        | Default : "This is an automated message..."
        */
        'footer' => env(
            'LOG_PRUNER_MAIL_FOOTER',
            'This is an automated message from the Log Pruner. Please do not reply to this email.'
        ),


        /*
        | ── SENDER IDENTITY ───────────────────────────────────────────────────
        | The From: address and display name shown to recipients.
        | Leave null to use MAIL_FROM_ADDRESS / MAIL_FROM_NAME from your .env.
        |
        | .env : LOG_PRUNER_MAIL_FROM_ADDRESS=noreply@yourapp.com
        | .env : LOG_PRUNER_MAIL_FROM_NAME="Your App Alerts"
        */
        'from' => [
            'address' => env('LOG_PRUNER_MAIL_FROM_ADDRESS', null),
            'name'    => env('LOG_PRUNER_MAIL_FROM_NAME',    null),
        ],

    ],

];
