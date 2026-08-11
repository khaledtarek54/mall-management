<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much it matters when this machine stops.
 *
 * A chiller serving the food court and a hand dryer in a back corridor are both "equipment", and
 * until now the system could not tell them apart — so every fault arrived at the same priority and
 * the coordinator triaged from memory.
 *
 * **Deliberately three values, not five.** A scale nobody can apply consistently is a field that
 * gets left on its default: critical (trading stops or someone is unsafe), important (a service
 * degrades), routine (everything else).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('criticality')->default('routine')->after('category');
            $table->index(['asset_id', 'criticality']);
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropIndex(['asset_id', 'criticality']);
            $table->dropColumn('criticality');
        });
    }
};
