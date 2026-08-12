<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The fallback payment terms become a setting.
 *
 * Seeded with 7 — exactly the literal the twelve call sites carried — so nothing an existing lease
 * bills moves on upgrade. What changes is that it is one number in one place instead of twelve.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('billing.default_payment_terms_days', 7);
    }
};
