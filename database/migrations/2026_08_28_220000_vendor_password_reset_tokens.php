<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vendor portal's own password-reset table.
 *
 * **Not shared with the tenant one, and the reason is a collision that would be silent.** A reset
 * table is keyed by EMAIL, and one person can legitimately be both a retailer's staff member and a
 * contractor's contact — a building manager, say. Sharing a table means one reset request consumes
 * the other's token, and the symptom is "the link in my email says invalid" for a reason nobody can
 * see. `tenants` and `tenant_users` share a table deliberately (both are the same person on the same
 * side); this is the other side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_password_reset_tokens');
    }
};
