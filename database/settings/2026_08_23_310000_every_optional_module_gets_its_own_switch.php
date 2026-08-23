<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Eighteen more module switches, all ON — so nothing an install does changes on deploy.
 *
 * `App\Support\Modules::KEYS` held sixteen toggleable modules out of the sixty-six resources and
 * thirty-three pages this panel registers, so most of the system had no switch at all: owner
 * statements, violations, post-dated cheques, security deposits, payroll, marketing, areas, the
 * approval ladder, custom fields and the rest were "core" only in the sense that nobody had
 * decided otherwise. That is a decision the operator should be making, not a default the code
 * makes for them by omission.
 *
 * **ON, without exception.** `Modules::enabled()` already answers TRUE for a key with no settings
 * row, so a migration writing `false` anywhere would be the software switching a working module off
 * underneath a running mall. The rows exist so the SCREEN has something to bind to and so a
 * deliberate `false` survives a cache clear — not to state a policy.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $modules = [
            'modules.unit_ownerships',
            'modules.rentable_items',
            'modules.rent_indices',
            'modules.post_dated_cheques',
            'modules.deposit_transactions',
            'modules.recurring_expenses',
            'modules.owner_statements',
            'modules.owner_requests',
            'modules.bank_accounts',
            'modules.budget',
            'modules.violations',
            'modules.areas',
            'modules.approvals',
            'modules.payrolls',
            'modules.marketing',
            'modules.announcements',
            'modules.custom_fields',
            'modules.document_templates',
        ];

        foreach ($modules as $key) {
            // Idempotent: an install that already carries the row (a settings table restored from a
            // later backup, a re-run after a partial deploy) must not throw and must not be reset.
            if (! $this->migrator->exists($key)) {
                $this->migrator->add($key, true);
            }
        }
    }
};
