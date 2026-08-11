<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Apply a tenant's on-account credit to a new invoice automatically — Voyager's behaviour.
 *
 * Ships **ON**, unlike the NSF fee and straight-line rent which ship off. The difference is which
 * way the surprise runs: a fee that starts appearing after an upgrade charges a tenant money nobody
 * agreed to, whereas auto-apply spends a credit the tenant already holds, on a bill they already
 * owe. The conservative default is therefore the ON one — the money ends up where the tenant's own
 * balance says it should, and the alternative is an operator remembering to offset by hand every
 * month.
 *
 * Configurable rather than fixed because the case against is real: a credit raised in dispute, or
 * one the tenant expects refunded in cash, silently disappearing into next month's rent is a support
 * call. Voyager makes it configurable for the same reason.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('billing.auto_apply_tenant_credit', true);
    }
};
