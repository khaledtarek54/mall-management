<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The switch behind "Ask Atriom".
 *
 * Defaults TRUE, like every other module switch: the screen reads only material this system
 * already publishes — the screen guides and the report catalogue — and shows nobody anything they
 * could not already open, so an install that upgrades into it gains a search box and loses nothing.
 *
 * It is a switch rather than a permission because there is nothing here to grant: results are
 * filtered through each screen's own `canAccess()`, so a right named `assistant.view` would confer
 * exactly what the reader already holds. What an operator might genuinely want is to turn the whole
 * thing OFF — while the guides are still being written for a module, say — and that is this row.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('modules.assistant', true);
    }
};
