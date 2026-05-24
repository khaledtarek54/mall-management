<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('slug')->unique();
            $table->enum('type', ['contractor', 'supplier', 'service_provider', 'consultant', 'other'])
                  ->default('service_provider');
            $table->enum('status', ['active', 'inactive', 'blacklisted'])->default('active');
            $table->string('legal_name', 200)->nullable();
            $table->string('tax_id', 50)->nullable();
            $table->string('email', 200)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
            $table->index('tax_id');
        });

        Schema::create('vendor_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('role', 100)->nullable();
            $table->string('email', 200)->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('vendor_id');
        });

        Schema::create('vendor_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('name', 200);
            $table->enum('status', ['draft', 'active', 'expired', 'terminated'])->default('draft');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('value', 14, 2)->nullable();
            $table->string('currency', 3)->default('EGP');
            $table->text('scope')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'status']);
            $table->index('asset_id');
        });

        // Link maintenance requests to vendors. The existing `assigned_to`
        // (User FK) stays for internal staff; this column tracks an external
        // vendor that may also be involved.
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->foreignId('assigned_to_vendor_id')
                  ->nullable()
                  ->after('assigned_to')
                  ->constrained('vendors')
                  ->nullOnDelete();
            $table->index('assigned_to_vendor_id');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropForeign(['assigned_to_vendor_id']);
            $table->dropIndex(['assigned_to_vendor_id']);
            $table->dropColumn('assigned_to_vendor_id');
        });

        Schema::dropIfExists('vendor_contracts');
        Schema::dropIfExists('vendor_contacts');
        Schema::dropIfExists('vendors');
    }
};
