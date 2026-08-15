<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The portal-maintenance-submitted and SLA-breach notifications rendered the
 * priority label from the wrong translation key (`admin.statuses.
 * work_priority.*` instead of `admin.enums.work_priority.*`), so
 * stored bodies contain the raw key text, e.g. "priority admin.statuses.
 * work_priority.urgent". The classes now use the correct key; this
 * rewrites the already-stored bodies in place.
 *
 * Replacement labels resolve in the app's default locale (en); good enough for
 * historical rows. Idempotent — only rows still containing the raw key change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $replacements = [];
        foreach (['low', 'medium', 'high', 'urgent'] as $level) {
            $replacements["admin.statuses.work_priority.{$level}"] = __("admin.enums.work_priority.{$level}");
        }

        DB::table('notifications')->orderBy('id')->chunk(500, function ($rows) use ($replacements) {
            foreach ($rows as $row) {
                $data = json_decode($row->data, true) ?: [];
                $body = $data['body'] ?? null;

                if (! is_string($body) || ! str_contains($body, 'admin.statuses.work_priority.')) {
                    continue;
                }

                $data['body'] = strtr($body, $replacements);

                DB::table('notifications')
                    ->where('id', $row->id)
                    ->update(['data' => json_encode($data)]);
            }
        });
    }

    public function down(): void
    {
        // Irreversible: the raw translation keys cannot be re-derived from the
        // rendered labels. No-op.
    }
};
