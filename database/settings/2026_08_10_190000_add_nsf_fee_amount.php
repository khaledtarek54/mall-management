<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * What a returned cheque costs the tenant (module 33; Yardi posts an NSF charge).
 *
 * Ships at 0 = OFF, so nothing changes until an operator sets a figure — the same posture as
 * straight-line rent. A fee that started appearing on invoices after an upgrade would be a nasty
 * surprise for both the operator and the tenant.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('billing.nsf_fee_amount', 0.0);
    }
};
