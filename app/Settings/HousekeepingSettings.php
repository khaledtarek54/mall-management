<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * How long the system keeps the by-products of running itself (D2-09).
 *
 * `AccountingSettings::activity_log_retention_days` gave the AUDIT TRAIL a retention period on
 * 2026-08-23 (EG-34) and stopped there. Five other tables grow for ever with nothing to prune them:
 * `notifications`, `exports` (and their FILES on disk), `imports` / `failed_import_rows`,
 * `failed_jobs`, and expired Sanctum tokens. None of it is money and none of it is evidence — which
 * is exactly why nobody notices until a table has years in it.
 *
 * **Separate periods, not one**, because the four classes answer different questions and a single
 * number would have to be set for the most conservative of them:
 *
 * - A **notification** is a nudge whose subject still exists. Once it has been on someone's bell for
 *   three months it is noise, and the invoice or work order it points at is still there.
 * - An **export** is a snapshot someone asked for once. It is also the widest door in the system —
 *   a full CSV of a register sitting on disk indefinitely is a data-protection liability, not just
 *   storage.
 * - An **import failure** is a working note for fixing a spreadsheet. It stops being useful the
 *   moment the corrected file is loaded. (Filament's own `FailedImportRow` hardcodes one month; this
 *   makes it the operator's choice.)
 * - A **failed job** and an **expired token** are diagnostic and security residue respectively.
 *
 * **`0` means keep everything, at every key** — the same convention EG-34 set, and the same reason:
 * an operator who decides to keep something for ever has made a decision, and the command reports
 * it rather than passing over in silence.
 *
 * These are SETTINGS rather than config for the reason EG-34 gives about the audit trail: the
 * screen showing the chosen number is the documentation of the retention period. Notifications name
 * a person, and an export can contain an entire tenant register.
 */
class HousekeepingSettings extends Settings
{
    /** Bell notifications. The record they point at outlives them by design. */
    public int $notification_retention_days = 90;

    /**
     * Generated export files AND their rows.
     *
     * Shortest of the four deliberately: this is the only one that leaves a file containing real
     * data sitting on a disk.
     */
    public int $export_retention_days = 30;

    /** `imports` and their `failed_import_rows` — the notes for fixing a spreadsheet. */
    public int $import_retention_days = 30;

    /** `failed_jobs`, pruned through Laravel's own `queue:prune-failed`. */
    public int $failed_job_retention_days = 30;

    /**
     * Expired API tokens, pruned through Sanctum's own `sanctum:prune-expired`.
     *
     * A grace period rather than a lifetime: the token is already expired and grants nothing. The
     * days are there so a support question about "my app stopped working" can still be answered.
     */
    public int $expired_token_grace_days = 7;

    public static function group(): string
    {
        return 'housekeeping';
    }
}
