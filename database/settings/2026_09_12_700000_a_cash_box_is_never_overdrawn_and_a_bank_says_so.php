<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Meeting 2026-09-02, point 16. Both ship OFF — Yardi's default, which blocks no cash account
        // (an overdraft facility is legitimate) — and OFF still WARNS in figures at the save. ON is
        // SAP's cash-journal rule for the drawer and the client's own "never in credit" for the bank:
        // what they SET, per property (both are `PropertySettings::OVERRIDABLE`), never the code
        // default. Nothing already posted changes on deploy: the guard runs on the next save.
        $this->migrator->add('accounting.refuse_overdrawn_cash', false);
        $this->migrator->add('accounting.refuse_overdrawn_bank', false);
    }
};
