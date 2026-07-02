<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A durable key-value store for system/operational state (not per-tenant business
 * data). First use: `ledger_last_synced_at` — when accounting:sync-ledger last ran,
 * powering the "Ledger last synced" indicator on the accounting screens.
 */
class SystemSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
