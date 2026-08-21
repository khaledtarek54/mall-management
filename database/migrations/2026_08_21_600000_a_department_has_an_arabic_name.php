<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departments were five seeded, English-only names inside an otherwise bilingual panel, with
 * `DepartmentResource::canCreate()` returning `false` — so an operator with a Security team, a
 * Facilities team or a Tenant Relations team could not add one, and an Arabic-reading manager saw
 * "Operations" in a screen that spoke Arabic everywhere else.
 *
 * The rows and the screen both already existed. This adds the missing half of the name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            // Nullable, and the model falls back to `name`: the seeded rows have no Arabic yet, and
            // refusing to load them until somebody types one would be a worse first morning.
            $table->string('name_ar', 150)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('name_ar');
        });
    }
};
