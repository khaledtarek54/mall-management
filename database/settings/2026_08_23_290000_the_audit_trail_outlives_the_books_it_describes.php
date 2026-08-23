<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * EG-34 — how long the activity log is kept.
 *
 * It was `config('activitylog.clean_after_days') = 365`, hardcoded, with no screen, and the prune is
 * SCHEDULED monthly — so audit history really was being deleted at a year old.
 *
 * 1,825 days (five years) is the Egyptian statutory book-retention period commonly cited for
 * commercial and tax records, and this system's money documents are never deletable at all. An
 * invoice from four years ago being on the books while the record of who voided a line on it has
 * expired is not a defensible position in front of an auditor.
 *
 * **Raising a retention period destroys nothing** — it only stops the next prune deleting rows it
 * would have deleted — so every install gets the new default, including one that has been pruning at
 * 365 for a year. Rows already gone are already gone; this stops the bleeding.
 *
 * Bounded rather than infinite on purpose: the log names who did each thing, which is personal data,
 * and PDPL asks that it not be kept longer than the purpose needs AND that the period be documented.
 * `0` is available on the screen for an operator who decides to keep everything.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('accounting.activity_log_retention_days', 1825);
    }
};
