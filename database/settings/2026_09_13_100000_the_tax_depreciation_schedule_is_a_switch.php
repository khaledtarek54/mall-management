<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The switch behind the income-tax depreciation schedule (meeting 2026-09-02, point 12).
 *
 * Defaults TRUE, like every other module switch: an install that upgrades into it keeps the page,
 * the report-hub entry and the tax pool on the asset form exactly as they were, and nothing on
 * deploy changes what an operator sees. The client's own rule — no tax schedule on screen until
 * further work — is what they SET on their install, never the code default: a fixed-asset register
 * in this market keeps a tax book beside the accounting one, so a fresh install offers it.
 *
 * It is a switch rather than a code freeze because the schedule is finished and correct (Law
 * 91/2005 art. 25, tested); what the client is deciding is whether to LOOK at it, which is the
 * decision a module switch exists for. Turning it off hides the page, its report-hub and delivery
 * entries and the tax-pool fields; the book depreciation run (`fixed_assets`) is untouched, and the
 * schedule never posted a journal entry to stop.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('modules.tax_depreciation', true);
    }
};
