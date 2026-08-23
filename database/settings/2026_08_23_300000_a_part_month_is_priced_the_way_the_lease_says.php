<?php

use App\Support\ProrationMethod;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * EG-29 — the portfolio default for how a partial month is priced.
 *
 * `actual` is what every invoice this system has ever raised used, so every install keeps billing
 * exactly as it did. The setting exists so a portfolio whose leases say "one thirtieth per day" can
 * say so once, and a property or an individual lease can differ.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('billing.proration_method', ProrationMethod::DEFAULT);
    }
};
