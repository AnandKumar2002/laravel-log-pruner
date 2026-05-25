<?php

declare(strict_types=1);

namespace Parvion\LaravelLogPruner\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * RotateLogsCommand
 *
 * A safe, atomic Artisan command that performs the following operations in
 * a single, auditable run — each phase fully controlled by config/log-pruner.php:
 *
 *  Phase 1 — log_rotation   : Atomically renames laravel.log to a timestamped
 *                              backup and recreates a fresh, writable laravel.log.
 *  Phase 2 — backup_pruning : Deletes backup files beyond the retention window.
 *                              Optionally prints a per-file info table (size,
 *                              age, expiry date) controlled by backup.show_info.
 *  Phase 3 — queue_restart  : Gracefully signals all queue workers to restart
 *                              so they release stale file handles.
 *  Phase 4 — db_pruning     : Deletes rows older than retention days from each
 *                              configured/provided table, with schema safety checks.
 *  Phase 5 — email_report   : Sends a plain-text summary via Mail::raw() to all
 *                              configured/provided recipients.
 *
 * Priority order for option resolution (highest → lowest):
 *   CLI option  >  config file value  >  built-in default
 *
 * Config file: config/log-pruner.php (publishable via vendor:publish)
 * Publish:     php artisan vendor:publish --tag=log-pruner-config
 *
 * Usage examples:
 *   php artisan logs:rotate-and-prune
 *   php artisan logs:rotate-and-prune --days=30
 *   php artisan logs:rotate-and-prune --tables=system_logs,audit_logs
 *   php artisan logs:rotate-and-prune --email=admin@example.com,ops@example.com
 *   php artisan logs:rotate-and-prune --days=7 --tables=system_logs --email=ops@example.com
 *
 * @package Parvion\LaravelLogPruner
 * @author  Anand Kumar <anandkumar101002@gmail.com>
 * @license MIT
 */
#[AsCommand(name: 'logs:rotate-and-prune')]
class RotateLogsCommand extends Command
{
    // =========================================================================
    // Command Signature & Description
    // =========================================================================

    /**
     * The name and signature of the console command.
     *
     * NOTE: Default values in the signature are placeholders only.
     *       Actual runtime defaults come from config/log-pruner.php,
     *       which is merged first. CLI values override config values.
     *
     * @var string
     */
    protected $signature = 'logs:rotate-and-prune
                            {--days=   : Days to retain backups and DB rows (overrides config)}
                            {--email=  : Comma-separated email recipients (overrides config)}
                            {--tables= : Comma-separated table names to prune (overrides config)}';

    /**
     * @var string
     */
    protected $description = 'Atomically rotates laravel.log, prunes old backups and DB rows, '
        . 'restarts queue workers, and emails a summary report (config-driven).';

    // =========================================================================
    // Internal State
    // =========================================================================

    /** Resolved effective retention days (config or CLI). */
    private int $retentionDays;

    /** Resolved effective list of tables to prune (config or CLI). */
    private array $tables;

    /** Resolved effective list of email recipients (config or CLI). */
    private array $recipients;

    /** Absolute path to the storage/logs directory. */
    private string $logDirectory;

    /** Absolute path to the live laravel.log file. */
    private string $logFilePath;

    /** Path to the newly created backup file, or null if no backup was made. */
    private ?string $backupFilePath = null;

    /** Number of old backup files deleted in Phase 2. */
    private int $deletedBackupsCount = 0;

    /**
     * Rich metadata for every backup file found in Phase 2.
     * Each entry: [name, size, created, days_kept, expires_on, status]
     *
     * @var array<int, array<string, mixed>>
     */
    private array $backupFileInfos = [];

    /**
     * Per-table DELETE stats.
     * e.g. ['system_logs' => 142, 'audit_logs' => 87]
     *
     * @var array<string, int>
     */
    private array $prunedTableStats = [];

    /** Whether queue:restart was successfully dispatched. */
    private bool $queueRestarted = false;

    // =========================================================================
    // Entry Point
    // =========================================================================

    /**
     * Execute the console command.
     *
     * Resolution order for every configurable value:
     *   1. CLI option (if explicitly passed by the caller)
     *   2. config/log-pruner.php value (possibly from published config)
     *   3. Package built-in default (hardcoded fallback)
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function handle(): int
    {
        // ── Master kill-switch ─────────────────────────────────────────────
        if (! config('log-pruner.enabled', true)) {
            $this->components->warn('Log Pruner is disabled via config (log-pruner.enabled = false). Exiting.');
            return Command::SUCCESS;
        }

        // ── Resolve paths ──────────────────────────────────────────────────
        $this->logDirectory = storage_path('logs');
        $this->logFilePath  = $this->logDirectory . DIRECTORY_SEPARATOR . 'laravel.log';

        // ── Resolve effective option values ────────────────────────────────
        $this->retentionDays = $this->resolveRetentionDays();
        $this->tables        = $this->resolveTables();
        $this->recipients    = $this->resolveRecipients();

        // ── Banner ─────────────────────────────────────────────────────────
        $this->printBanner();

        // ── Print resolved config summary ──────────────────────────────────
        $this->printConfigSummary();

        // ── Phase 1 : Atomic log rotation ──────────────────────────────────
        if (config('log-pruner.features.log_rotation', true)) {
            $this->rotateLogFile();
        } else {
            $this->components->warn('Phase 1 — Log rotation SKIPPED (disabled in config).');
        }

        // ── Phase 2 : Prune old backup files ───────────────────────────────
        if (config('log-pruner.features.backup_pruning', true)) {
            $this->pruneOldBackupFiles();
        } else {
            $this->components->warn('Phase 2 — Backup pruning SKIPPED (disabled in config).');
        }

        // ── Phase 3 : Restart queue workers ────────────────────────────────
        if (config('log-pruner.features.queue_restart', true)) {
            $this->restartQueueWorkers();
        } else {
            $this->components->warn('Phase 3 — Queue restart SKIPPED (disabled in config).');
        }

        // ── Phase 4 : Prune database log tables ────────────────────────────
        if (config('log-pruner.features.db_pruning', true)) {
            $this->pruneDatabaseTables();
        } else {
            $this->components->warn('Phase 4 — Database pruning SKIPPED (disabled in config).');
        }

        // ── Phase 5 : Send summary email ───────────────────────────────────
        $emailEnabled = config('log-pruner.features.email_report', true)
            && config('log-pruner.mail.enabled', true);

        if ($emailEnabled) {
            $this->sendEmailReport();
        } else {
            $this->components->warn('Phase 5 — Email report SKIPPED (disabled in config).');
        }

        // ── Footer ─────────────────────────────────────────────────────────
        $this->newLine();
        $this->components->info('╔══════════════════════════════════════════════════════╗');
        $this->components->info('║  Log Pruner Completed Successfully ✓                 ║');
        $this->components->info('╚══════════════════════════════════════════════════════╝');

        return Command::SUCCESS;
    }

    // =========================================================================
    // Option Resolution — CLI overrides Config overrides Default
    // =========================================================================

    /**
     * Resolves the effective retention period in days.
     * Priority: --days CLI option → config('log-pruner.days') → 15
     */
    private function resolveRetentionDays(): int
    {
        $cliValue = $this->option('days');

        // The option was explicitly passed on the CLI.
        if ($cliValue !== null && $cliValue !== '') {
            return max(1, (int) $cliValue);
        }

        // Fall back to config, then hardcoded default.
        return max(1, (int) config('log-pruner.days', 15));
    }

    /**
     * Resolves the effective list of database tables to prune.
     *
     * Priority:
     *   1. --tables CLI option (overrides everything, used alone)
     *   2. config 'tables' array  +  LOG_PRUNER_TABLES env string  (merged & deduped)
     *
     * Why merge config array and env string?
     *   After vendor:publish the user edits the config array directly.
     *   The env variable supports CI/CD pipelines that inject tables
     *   dynamically. Both sources work simultaneously.
     *
     * @return array<int, string>
     */
    private function resolveTables(): array
    {
        $cliValue = $this->option('tables');

        // CLI always wins — it replaces everything else for this run.
        if ($cliValue !== null && $cliValue !== '') {
            return $this->parseTables($cliValue);
        }

        // Merge the config PHP array with any env-string supplement.
        $fromConfig = $this->parseTables((array) config('log-pruner.tables', []));
        $fromEnv    = $this->parseTables((string) env('LOG_PRUNER_TABLES', ''));

        $merged = $fromConfig;
        foreach ($fromEnv as $table => $days) {
            // Env overrides or supplements config. If env specifies specific days, it overrides.
            if (! isset($merged[$table]) || $days !== null) {
                $merged[$table] = $days;
            }
        }

        return $merged;
    }

    /**
     * Resolves the effective list of email recipients.
     *
     * Priority:
     *   1. --email CLI option (overrides everything, used alone)
     *   2. config 'mail.recipients' array  +  LOG_PRUNER_MAIL_RECIPIENTS env string
     *      → both are merged and deduplicated so either method works
     *
     * This design lets users:
     *   • Add addresses directly to the config array (cleanest after vendor:publish)
     *   • Set LOG_PRUNER_MAIL_RECIPIENTS in .env for CI/CD / server configs
     *   • Do both simultaneously — duplicates are silently removed
     *   • Pass --email on the CLI to override everything for a single run
     *
     * @return array<int, string>
     */
    private function resolveRecipients(): array
    {
        $cliValue = $this->option('email');

        // CLI always wins — it replaces everything else for this run.
        if ($cliValue !== null && $cliValue !== '') {
            return $this->splitAndClean($cliValue);
        }

        // Merge the config PHP array with any env-string supplement.
        $fromConfig = (array) config('log-pruner.mail.recipients', []);
        $fromEnv    = $this->splitAndClean((string) env('LOG_PRUNER_MAIL_RECIPIENTS', ''));

        // array_unique removes duplicates if the same address appears in both.
        return array_values(array_unique(array_filter(
            array_merge($fromConfig, $fromEnv),
            fn (string $e): bool => $e !== ''
        )));
    }

    /**
     * Splits a comma-separated string into a trimmed, filtered array.
     * Shared by resolveTables() and resolveRecipients().
     *
     * @param  string        $value  Raw comma-separated input.
     * @return array<int, string>
     */
    private function splitAndClean(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            fn (string $v): bool => $v !== ''
        ));
    }

    /**
     * Parses a raw array or string of tables into an associative array [table_name => ?int].
     * Supports formats:
     *   - Associative: ['system_logs' => 10]
     *   - String with colon: "system_logs:10"
     *   - String with arrow: "system_logs => 10" or "system_logs=>10"
     *   - Flat string: "system_logs" (uses global retention days)
     *
     * @param  array|string  $input  Raw tables input.
     * @return array<string, ?int>  Associative array of table => days.
     */
    private function parseTables(array|string $input): array
    {
        $parsed = [];

        if (is_string($input)) {
            $items = array_map('trim', explode(',', $input));
        } else {
            $items = $input;
        }

        foreach ($items as $key => $val) {
            // Case 1: Associative array where key is the table name and val is the integer days.
            // e.g. ['system_logs' => 10]
            if (is_string($key) && (is_int($val) || is_numeric($val))) {
                $tableName = trim($key);
                if ($tableName !== '') {
                    $parsed[$tableName] = (int) $val;
                }
                continue;
            }

            // Case 2: The value itself is a string. It could be "table_name", "table_name:days", or "table_name=>days".
            if (is_string($val)) {
                $val = trim($val);
                if ($val === '') {
                    continue;
                }

                // Check if it has a separator: "=>" or ":" or "="
                $separator = null;
                if (str_contains($val, '=>')) {
                    $separator = '=>';
                } elseif (str_contains($val, ':')) {
                    $separator = ':';
                } elseif (str_contains($val, '=')) {
                    $separator = '=';
                }

                if ($separator !== null) {
                    $parts = explode($separator, $val, 2);
                    $tableName = trim($parts[0]);
                    $days = trim($parts[1]);

                    if ($tableName !== '') {
                        $parsed[$tableName] = is_numeric($days) ? (int) $days : null;
                    }
                } else {
                    $parsed[$val] = null;
                }
            }
        }

        return $parsed;
    }

    // =========================================================================
    // Console Output Helpers
    // =========================================================================

    /**
     * Prints the startup banner with the current effective configuration.
     */
    private function printBanner(): void
    {
        $this->newLine();
        $this->components->info('╔══════════════════════════════════════════════════════╗');
        $this->components->info('║  Parvion Laravel Log Pruner — Starting               ║');
        $this->components->info('╚══════════════════════════════════════════════════════╝');
        $this->newLine();
    }

    /**
     * Prints a summary of the resolved effective configuration so operators
     * can quickly verify what will run before any file or database changes happen.
     */
    private function printConfigSummary(): void
    {
        $cutoffDate    = Carbon::now()->subDays($this->retentionDays)->format('Y-m-d H:i T');
        $tablesStr     = $this->formatTablesList();
        $recipientsStr = empty($this->recipients) ? '(none)' : implode(', ', $this->recipients);

        $featureMap = [
            'Log Rotation'   => config('log-pruner.features.log_rotation', true),
            'Backup Pruning' => config('log-pruner.features.backup_pruning', true),
            'Queue Restart'  => config('log-pruner.features.queue_restart', true),
            'DB Pruning'     => config('log-pruner.features.db_pruning', true),
            'Email Report'   => config('log-pruner.features.email_report', true)
                                && config('log-pruner.mail.enabled', true),
        ];

        $this->line('  <fg=cyan;options=bold>┌─ Effective Configuration ────────────────────────────────┐</>');
        $this->line("  <fg=cyan>│</> Retention Period : <fg=yellow>{$this->retentionDays} days</>");
        $this->line("  <fg=cyan>│</> Cutoff Date      : <fg=yellow>{$cutoffDate}</>");
        $this->line("  <fg=cyan>│</> Tables           : <fg=yellow>{$tablesStr}</>");
        $this->line("  <fg=cyan>│</> Email Recipients : <fg=yellow>{$recipientsStr}</>");
        $this->line('  <fg=cyan>│</>');
        $this->line('  <fg=cyan>│</> <fg=cyan;options=bold>Features:</>');

        foreach ($featureMap as $name => $isEnabled) {
            $indicator = $isEnabled
                ? '<fg=green>✓ enabled </>'
                : '<fg=red>✗ disabled</>';
            $this->line(sprintf('  <fg=cyan>│</>   %-16s %s', $name, $indicator));
        }

        $this->line('  <fg=cyan;options=bold>└──────────────────────────────────────────────────────────┘</>');
        $this->newLine();
    }

    // =========================================================================
    // Phase 1 — Atomic Log File Rotation
    // =========================================================================

    /**
     * Atomically renames laravel.log to a timestamped backup and immediately
     * creates a fresh, empty, writable laravel.log in its place.
     *
     * Why atomic rename instead of copy-then-delete?
     *   rename() is a single OS syscall — there is no moment where the file
     *   is absent. Open file descriptors from queue workers remain valid on the
     *   old inode so no log data is lost during the brief rename window.
     */
    private function rotateLogFile(): void
    {
        $this->components->task('Phase 1 — Atomic log file rotation', function (): bool {
            if (! file_exists($this->logFilePath)) {
                $this->components->warn('  laravel.log not found — creating a fresh file.');
                $this->touchFreshLogFile();
                return true;
            }

            if (filesize($this->logFilePath) === 0) {
                $this->components->warn('  laravel.log is already empty — skipping rename.');
                return true;
            }

            // Format: laravel-backup-2025-06-15-143022.log
            $timestamp  = Carbon::now()->format('Y-m-d-His');
            $backupName = "laravel-backup-{$timestamp}.log";
            $this->backupFilePath = $this->logDirectory . DIRECTORY_SEPARATOR . $backupName;

            if (! rename($this->logFilePath, $this->backupFilePath)) {
                $this->components->error("  Failed to rename laravel.log → {$backupName}");
                return false;
            }

            $this->components->info("  ✓ Backup created: {$backupName}");
            $this->touchFreshLogFile();

            return true;
        });
    }

    /**
     * Creates a fresh empty laravel.log and sets permissions to 0664.
     * chmod() is a no-op on Windows but critical for Linux production servers
     * where the web server user and CLI user share a group.
     */
    private function touchFreshLogFile(): void
    {
        touch($this->logFilePath);
        chmod($this->logFilePath, 0664);
        $this->components->info('  ✓ Fresh laravel.log created with permissions 0664');
    }

    // =========================================================================
    // Phase 2 — Prune Old Backup Files (with detailed info display)
    // =========================================================================

    /**
     * Scans storage/logs/ for backup files matching `laravel-backup-*.log`.
     * Files older than the retention threshold are deleted. Unrelated log files
     * (e.g. worker.log) are never touched.
     *
     * When config('log-pruner.backup.show_info') is true, a rich per-file table
     * is printed showing name, size, creation date, days kept, expiry date,
     * and whether the file is SAFE or EXPIRED (deleted).
     */
    private function pruneOldBackupFiles(): void
    {
        $this->components->task('Phase 2 — Pruning old backup files', function (): bool {
            $cutoffTimestamp = Carbon::now()->subDays($this->retentionDays)->getTimestamp();
            $dateFormat      = config('log-pruner.backup.date_format', 'Y-m-d H:i');
            $showInfo        = config('log-pruner.backup.show_info', true);

            $pattern     = $this->logDirectory . DIRECTORY_SEPARATOR . 'laravel-backup-*.log';
            $backupFiles = glob($pattern);

            if ($backupFiles === false || count($backupFiles) === 0) {
                $this->components->info('  No backup files found in storage/logs/.');
                return true;
            }

            // Collect rich info for every backup file.
            foreach ($backupFiles as $file) {
                $mtime = filemtime($file);

                if ($mtime === false) {
                    continue; // Unreadable — skip safely.
                }

                $createdAt  = Carbon::createFromTimestamp($mtime);
                $expiresAt  = $createdAt->copy()->addDays($this->retentionDays);
                $daysKept   = (int) $createdAt->diffInDays(Carbon::now());
                $isExpired  = $mtime < $cutoffTimestamp;
                $fileSize   = $this->humanFileSize(filesize($file) ?: 0);

                $info = [
                    'file'       => $file,
                    'name'       => basename($file),
                    'size'       => $fileSize,
                    'created'    => $createdAt->format($dateFormat),
                    'days_kept'  => $daysKept,
                    'expires_on' => $expiresAt->format($dateFormat),
                    'is_expired' => $isExpired,
                    'deleted'    => false,
                ];

                // Attempt deletion if expired.
                if ($isExpired) {
                    if (unlink($file)) {
                        $this->deletedBackupsCount++;
                        $info['deleted'] = true;
                    } else {
                        $this->components->warn('  ⚠ Could not delete: ' . basename($file));
                    }
                }

                $this->backupFileInfos[] = $info;
            }

            // ── Print detailed backup info table ───────────────────────────
            if ($showInfo && ! empty($this->backupFileInfos)) {
                $this->newLine();
                $this->printBackupInfoTable();
            }

            $this->components->info("  ✓ Backup files deleted: {$this->deletedBackupsCount} of " . count($this->backupFileInfos));

            return true;
        });
    }

    /**
     * Prints a human-readable backup file info table to the console.
     * Shows every backup file with its retention status clearly highlighted.
     */
    private function printBackupInfoTable(): void
    {
        $this->line('  <fg=cyan;options=bold>  Backup Files Status (retention: ' . $this->retentionDays . ' days)</>');
        $this->line('  ' . str_repeat('─', 80));
        $this->line(sprintf(
            '  <fg=white;options=bold>%-38s %8s %16s %10s %s</>',
            'File Name',
            'Size',
            'Created',
            'Days Kept',
            'Expires On          Status'
        ));
        $this->line('  ' . str_repeat('─', 80));

        foreach ($this->backupFileInfos as $info) {
            if ($info['is_expired']) {
                $status    = $info['deleted']
                    ? '<fg=red>EXPIRED → deleted</>'
                    : '<fg=red>EXPIRED → delete failed</>';
                $nameColor = '<fg=red>';
            } else {
                $daysLeft  = $this->retentionDays - $info['days_kept'];
                $status    = "<fg=green>SAFE (expires in {$daysLeft}d)</>";
                $nameColor = '<fg=white>';
            }

            $this->line(sprintf(
                '  %s%-38s</> %8s %16s %10s  %s %s',
                $nameColor,
                substr($info['name'], 0, 37),   // Truncate for alignment
                $info['size'],
                $info['created'],
                $info['days_kept'] . 'd',
                $info['expires_on'],
                $status
            ));
        }

        $this->line('  ' . str_repeat('─', 80));
        $this->newLine();
    }

    // =========================================================================
    // Phase 3 — Restart Queue Workers
    // =========================================================================

    /**
     * Sets the cache flag that signals all queue workers to restart gracefully
     * between jobs. Workers will NOT be killed mid-task — they finish their
     * current job, then restart, at which point they open a fresh file handle
     * to the new laravel.log.
     */
    private function restartQueueWorkers(): void
    {
        $this->components->task('Phase 3 — Restarting queue workers', function (): bool {
            try {
                Artisan::call('queue:restart');
                $this->queueRestarted = true;
                $this->components->info('  ✓ Queue workers signaled for graceful restart');
            } catch (\Throwable $e) {
                $this->components->warn('  ⚠ Could not signal queue restart: ' . $e->getMessage());
            }

            return true;
        });
    }

    // =========================================================================
    // Phase 4 — Prune Database Log Tables
    // =========================================================================

    /**
     * For each configured table, verifies the table and `created_at` column
     * exist before executing the DELETE query. Mistyped table names produce a
     * warning, never a fatal exception.
     */
    private function pruneDatabaseTables(): void
    {
        $this->components->task('Phase 4 — Pruning database log tables', function (): bool {
            if (empty($this->tables)) {
                $this->components->warn('  No tables configured for pruning.');
                return true;
            }

            foreach ($this->tables as $table => $days) {
                $daysToKeep = $days ?? $this->retentionDays;
                $cutoffDate = Carbon::now()->subDays($daysToKeep)->toDateTimeString();
                $this->pruneTable($table, $cutoffDate, $daysToKeep);
            }

            return true;
        });
    }

    /**
     * Prunes a single database table after running schema safety checks.
     *
     * @param string $table       Database table name.
     * @param string $cutoffDate  ISO 8601 datetime string — rows older than this are deleted.
     * @param int    $daysToKeep  Number of retention days for this table.
     */
    private function pruneTable(string $table, string $cutoffDate, int $daysToKeep): void
    {
        $this->components->info("  Processing table: `{$table}` (retention: {$daysToKeep} days)");

        // Safety Check 1 — Table must exist.
        if (! Schema::hasTable($table)) {
            $this->components->warn("    ⚠ Skipping `{$table}` — table does not exist.");
            $this->prunedTableStats[$table] = ['rows' => 0, 'days' => $daysToKeep];
            return;
        }

        // Safety Check 2 — Table must have `created_at` column.
        if (! Schema::hasColumn($table, 'created_at')) {
            $this->components->warn("    ⚠ Skipping `{$table}` — no `created_at` column found.");
            $this->prunedTableStats[$table] = ['rows' => 0, 'days' => $daysToKeep];
            return;
        }

        try {
            $deletedRows = DB::table($table)
                ->where('created_at', '<', $cutoffDate)
                ->delete();

            $this->prunedTableStats[$table] = ['rows' => $deletedRows, 'days' => $daysToKeep];
            $this->components->info("    ✓ Deleted {$deletedRows} rows from `{$table}`");
        } catch (\Throwable $e) {
            $this->components->error("    ✗ Failed to prune `{$table}`: " . $e->getMessage());
            $this->prunedTableStats[$table] = ['rows' => 0, 'days' => $daysToKeep];
        }
    }

    // =========================================================================
    // Phase 5 — Multi-Recipient Email Report
    // =========================================================================

    /**
     * Sends a plain-text summary to every validated recipient.
     * Uses Mail::raw() — no Blade views required, works with API mail drivers
     * (ZeptoMail, Postmark, Mailgun, Resend) out of the box.
     *
     * Each recipient receives an individually addressed email (not CC/BCC)
     * for compliance with enterprise email policies.
     */
    private function sendEmailReport(): void
    {
        if (empty($this->recipients)) {
            $this->components->warn('Phase 5 — Email report SKIPPED (no recipients configured or provided).');
            return;
        }

        $this->components->task('Phase 5 — Sending email summary report', function (): bool {
            // ── Validate email addresses ───────────────────────────────────
            $validEmails = [];

            foreach ($this->recipients as $email) {
                $validator = Validator::make(
                    ['email' => $email],
                    ['email' => 'required|email:rfc']
                );

                if ($validator->fails()) {
                    $this->components->warn("  ⚠ Invalid email skipped: {$email}");
                } else {
                    $validEmails[] = $email;
                }
            }

            if (empty($validEmails)) {
                $this->components->warn('  No valid recipients after validation. Skipping.');
                return true;
            }

            $reportBody    = $this->buildEmailReportBody();
            $subjectPrefix = config('log-pruner.mail.subject_prefix', '[Log Pruner]');
            $subject       = $subjectPrefix . ' Rotation & Pruning Report — ' . Carbon::now()->format('Y-m-d H:i T');

            // ── Resolve custom sender identity from config ──────────────────
            // If the user has set log-pruner.mail.from.address we use it;
            // otherwise Mail falls back to the app's MAIL_FROM_* settings.
            $fromAddress = config('log-pruner.mail.from.address');
            $fromName    = config('log-pruner.mail.from.name');

            foreach ($validEmails as $recipient) {
                try {
                    Mail::raw($reportBody, function ($message) use ($recipient, $subject, $fromAddress, $fromName): void {
                        $message->to($recipient)->subject($subject);

                        // Override the From: address/name only when explicitly configured.
                        if (! empty($fromAddress)) {
                            $message->from($fromAddress, $fromName ?: config('app.name', 'Log Pruner'));
                        }
                    });

                    $this->components->info("  ✓ Report sent to: {$recipient}");
                } catch (\Throwable $e) {
                    $this->components->error("  ✗ Failed to send to {$recipient}: " . $e->getMessage());
                }
            }

            return true;
        });
    }

    /**
     * Builds the plain-text email body from the current run's state.
     * All sections are only included if the corresponding feature was enabled.
     *
     * @return string Full plain-text email body.
     */
    private function buildEmailReportBody(): string
    {
        $now         = Carbon::now()->format('Y-m-d H:i:s T');
        $appName     = config('app.name', 'Laravel Application');
        $appUrl      = config('app.url', 'N/A');
        $cutoffDate  = Carbon::now()->subDays($this->retentionDays)->format('Y-m-d H:i:s T');

        // ── Customisable email header and footer ───────────────────────────
        // Both fall back to sensible defaults if not set in config / .env.
        $emailHeader = config(
            'log-pruner.mail.header',
            'PARVION LARAVEL LOG PRUNER — AUTOMATED REPORT'
        );
        $emailFooter = config(
            'log-pruner.mail.footer',
            'This is an automated message from the Log Pruner. Please do not reply to this email.'
        );

        // Build a top border the same width as the header text so it always aligns.
        $borderWidth  = max(57, mb_strlen($emailHeader) + 4);
        $borderLine   = str_repeat('=', $borderWidth);
        $innerPadding = str_repeat('-', $borderWidth);
        $backupName  = $this->backupFilePath
            ? basename($this->backupFilePath)
            : 'No backup created (log was empty or missing)';

        // ── Feature toggle labels ──────────────────────────────────────────
        $features = [
            'Log Rotation'   => config('log-pruner.features.log_rotation', true),
            'Backup Pruning' => config('log-pruner.features.backup_pruning', true),
            'Queue Restart'  => config('log-pruner.features.queue_restart', true),
            'DB Pruning'     => config('log-pruner.features.db_pruning', true),
            'Email Report'   => config('log-pruner.features.email_report', true),
        ];

        $featureLines = [];
        foreach ($features as $name => $enabled) {
            $featureLines[] = sprintf('  %-18s %s', $name . ':', $enabled ? 'Enabled' : 'Disabled');
        }
        $featureSection = implode(PHP_EOL, $featureLines);

        // ── Database pruning section ───────────────────────────────────────
        $tableLines = [];
        if (empty($this->prunedTableStats)) {
            $tableLines[] = '  No tables were processed.';
        } else {
            foreach ($this->prunedTableStats as $table => $stat) {
                $rows = is_array($stat) ? ($stat['rows'] ?? 0) : (int) $stat;
                $retention = is_array($stat) ? ($stat['days'] ?? $this->retentionDays) : $this->retentionDays;
                $tableLines[] = sprintf('  • %-30s %d rows deleted (retention: %d days)', "`{$table}`", $rows, $retention);
            }
        }
        $tableSection = implode(PHP_EOL, $tableLines);

        // ── Backup file info section (if enabled) ──────────────────────────
        $backupInfoSection = '';
        if (config('log-pruner.backup.show_info_in_email', true) && ! empty($this->backupFileInfos)) {
            $dateFormat  = config('log-pruner.backup.date_format', 'Y-m-d H:i');
            $lines       = [];
            $lines[]     = str_repeat('-', 72);
            $lines[]     = sprintf('  %-35s %7s %16s %6s  %s', 'File Name', 'Size', 'Created', 'Age', 'Status / Expires On');
            $lines[]     = str_repeat('-', 72);

            foreach ($this->backupFileInfos as $info) {
                if ($info['is_expired']) {
                    $statusStr = $info['deleted'] ? 'DELETED' : 'DELETE FAILED';
                } else {
                    $daysLeft  = $this->retentionDays - $info['days_kept'];
                    $statusStr = "SAFE (expires {$info['expires_on']}, in {$daysLeft}d)";
                }

                $lines[] = sprintf(
                    '  %-35s %7s %16s %5sd  %s',
                    substr($info['name'], 0, 34),
                    $info['size'],
                    $info['created'],
                    $info['days_kept'],
                    $statusStr
                );
            }

            $lines[]           = str_repeat('-', 72);
            $backupInfoSection = PHP_EOL . implode(PHP_EOL, $lines);
        }

        // ── Assemble full report ───────────────────────────────────────────
        return <<<REPORT
{$borderLine}
  {$emailHeader}
{$borderLine}

  Application   : {$appName}
  URL           : {$appUrl}
  Report Time   : {$now}

{$innerPadding}
  CONFIGURATION
{$innerPadding}

  Retention Period : {$this->retentionDays} days
  Cutoff Date      : {$cutoffDate}
  Tables           : {$this->formatTablesList()}
  Recipients       : {$this->formatRecipientsList()}

{$innerPadding}
  FEATURE STATUS
{$innerPadding}

{$featureSection}

{$innerPadding}
  LOG FILE ROTATION
{$innerPadding}

  New Backup File     : {$backupName}
  Old Backups Deleted : {$this->deletedBackupsCount}

{$innerPadding}
  BACKUP FILES STATUS (retention: {$this->retentionDays} days)
{$innerPadding}
{$backupInfoSection}

{$innerPadding}
  QUEUE WORKERS
{$innerPadding}

  Restart Signaled : {$this->formatBool($this->queueRestarted)}

{$innerPadding}
  DATABASE TABLE PRUNING
{$innerPadding}

{$tableSection}

{$innerPadding}
  {$emailFooter}
{$borderLine}
REPORT;
    }

    // =========================================================================
    // Utility Helpers
    // =========================================================================

    /**
     * Converts a file size in bytes to a human-readable string.
     * e.g. 1048576 → "1.00 MB"
     *
     * @param  int    $bytes Raw file size in bytes.
     * @return string        Human-readable size string.
     */
    private function humanFileSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }

    /**
     * Formats the resolved tables list for display.
     *
     * @return string Comma-separated or "(none)".
     */
    private function formatTablesList(): string
    {
        if (empty($this->tables)) {
            return '(none)';
        }

        $formatted = [];
        foreach ($this->tables as $table => $days) {
            if ($days !== null) {
                $formatted[] = "{$table} ({$days} days)";
            } else {
                $formatted[] = "{$table} (global)";
            }
        }

        return implode(', ', $formatted);
    }

    /**
     * Formats the resolved recipients list for display.
     *
     * @return string Comma-separated or "(none)".
     */
    private function formatRecipientsList(): string
    {
        return empty($this->recipients) ? '(none)' : implode(', ', $this->recipients);
    }

    /**
     * Converts a boolean to a Yes/No label.
     *
     * @param  bool   $value
     * @return string
     */
    private function formatBool(bool $value): string
    {
        return $value ? 'Yes' : 'No';
    }
}
