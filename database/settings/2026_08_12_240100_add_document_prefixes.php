<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Empty = every document type keeps the letters it shipped with, so an existing install is
        // unchanged and only moves when somebody deliberately sets one.
        $this->migrator->add('accounting.document_prefixes', []);
    }

    public function down(): void
    {
        $this->migrator->delete('accounting.document_prefixes');
    }
};
