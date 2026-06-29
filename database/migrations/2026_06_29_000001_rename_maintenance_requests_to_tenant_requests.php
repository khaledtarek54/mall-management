<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 1 — internal rename of the data layer: the maintenance-request feature is
 * now the general "tenant request" system. Renames the tables + the comment FK
 * column, and re-points the default-FQCN morphs (Spatie activity-log subject +
 * media model) from App\Models\MaintenanceRequest → App\Models\TenantRequest so
 * existing history/attachments resolve to the renamed model.
 *
 * The class/file renames happen in code; the API routes + Filament slugs are
 * intentionally left as /maintenance-requests for back-compat with the mobile
 * app + existing links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('maintenance_requests', 'tenant_requests');
        Schema::rename('maintenance_request_comments', 'tenant_request_comments');

        Schema::table('tenant_request_comments', function (Blueprint $table) {
            $table->renameColumn('maintenance_request_id', 'tenant_request_id');
        });

        DB::table('activity_log')
            ->where('subject_type', 'App\\Models\\MaintenanceRequest')
            ->update(['subject_type' => 'App\\Models\\TenantRequest']);

        DB::table('media')
            ->where('model_type', 'App\\Models\\MaintenanceRequest')
            ->update(['model_type' => 'App\\Models\\TenantRequest']);
    }

    public function down(): void
    {
        DB::table('media')
            ->where('model_type', 'App\\Models\\TenantRequest')
            ->update(['model_type' => 'App\\Models\\MaintenanceRequest']);

        DB::table('activity_log')
            ->where('subject_type', 'App\\Models\\TenantRequest')
            ->update(['subject_type' => 'App\\Models\\MaintenanceRequest']);

        Schema::table('tenant_request_comments', function (Blueprint $table) {
            $table->renameColumn('tenant_request_id', 'maintenance_request_id');
        });

        Schema::rename('tenant_request_comments', 'maintenance_request_comments');
        Schema::rename('tenant_requests', 'maintenance_requests');
    }
};
