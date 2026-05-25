<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the operator_id FK + index from assets first, then drop the
        // operators table. The Operator concept is being retired in favor
        // of per-property tenancy.
        Schema::table('assets', function (Blueprint $table) {
            if (Schema::hasColumn('assets', 'operator_id')) {
                $table->dropForeign(['operator_id']);
                $table->dropIndex(['operator_id']);
                $table->dropColumn('operator_id');
            }
        });

        Schema::dropIfExists('operators');

        // Insert the synthetic "All Properties" Asset that backs the
        // portfolio-view tenant. It's a real DB row so Filament can resolve
        // it from the URL slug, but it's flagged inactive and treated
        // specially by code paths via Asset::isAllProperties().
        if (! DB::table('assets')->where('code', 'ALL')->exists()) {
            DB::table('assets')->insert([
                'name' => 'All Properties',
                'code' => 'ALL',
                'type' => 'mall',
                'city' => '—',
                'country' => '—',
                'currency' => 'EGP',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('assets')->where('code', 'ALL')->delete();

        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('primary_color', 7)->default('#C9A961');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('operator_id')
                ->nullable()
                ->after('id')
                ->constrained('operators')
                ->nullOnDelete();

            $table->index('operator_id');
        });
    }
};
