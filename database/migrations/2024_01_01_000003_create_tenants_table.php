<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Café Crema"
            $table->string('legal_name')->nullable(); // e.g., "Crema Coffee Co. LLC"
            $table->enum('type', ['individual', 'company'])->default('company');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('tax_id')->nullable(); // VAT registration / national ID
            $table->string('national_id')->nullable();
            $table->text('address')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_person_phone')->nullable();
            $table->enum('status', ['active', 'inactive', 'blacklisted'])->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('tax_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
