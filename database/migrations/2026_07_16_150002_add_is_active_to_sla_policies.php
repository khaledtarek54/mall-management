<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes a gap the SLA slice shipped with: deleting a policy was the only way to return a
 * property to the operator default, and delete is **super_admin-only project-wide** — so a
 * manager could set an override but never remove one.
 *
 * Deactivating is an EDIT, so it respects that invariant instead of working around it with
 * a "reset" action that would be delete by another name. It is also strictly better than
 * the workaround of retyping the default into the override: a pinned copy silently stops
 * tracking the default the moment the default changes, whereas an inactive row genuinely
 * falls back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sla_policies', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('resolve_hours');
        });
    }

    public function down(): void
    {
        Schema::table('sla_policies', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
