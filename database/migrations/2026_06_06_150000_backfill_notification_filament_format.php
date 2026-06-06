<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two Filament database-notification markers the app's custom notification
 * classes historically omitted:
 *
 *  - `format: filament`  — Filament's bell only renders rows tagged with this
 *    (see Filament\Notifications\Livewire\DatabaseNotifications::
 *    getNotificationsQuery → where data->format). Without it, delivered
 *    notifications never appeared in the bell.
 *  - `duration: persistent` — a non-persistent toast (default 6s) auto-fires
 *    `notificationClosed`, which DELETES the row. Without it, notifications
 *    vanished from the database a few seconds after surfacing.
 *
 * The notification classes now emit both markers; this backfills the rows
 * written before the fix so existing notifications render and survive too.
 * Driver-agnostic (decodes in PHP rather than relying on JSON_SET) and
 * idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                $data = json_decode($row->data, true) ?: [];

                if (($data['format'] ?? null) === 'filament' && ($data['duration'] ?? null) === 'persistent') {
                    continue;
                }

                $data['format'] = 'filament';
                $data['duration'] = 'persistent';

                DB::table('notifications')
                    ->where('id', $row->id)
                    ->update(['data' => json_encode($data)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('notifications')->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                $data = json_decode($row->data, true) ?: [];

                if (! array_key_exists('format', $data) && ! array_key_exists('duration', $data)) {
                    continue;
                }

                unset($data['format'], $data['duration']);

                DB::table('notifications')
                    ->where('id', $row->id)
                    ->update(['data' => json_encode($data)]);
            }
        });
    }
};
