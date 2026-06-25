<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-user tenant portal (req #9): a tenant company can have several portal
 * logins, exactly one+ flagged as admin (only admins may submit/write; the
 * rest are read-only). Replaces the single "Tenant model = login" scheme — the
 * portal guard now authenticates TenantUser; Tenant stays the company record.
 *
 * Backfill: every existing tenant that had a portal login becomes one ADMIN
 * TenantUser (same email/password), so current logins keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_admin')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
        });

        // Backfill existing tenant logins → one admin user each.
        DB::table('tenants')
            ->whereNotNull('password')
            ->whereNotNull('email')
            ->orderBy('id')
            ->select('id', 'name', 'email', 'password', 'contact_person')
            ->chunkById(500, function ($tenants) {
                $now = now();
                $rows = [];
                foreach ($tenants as $t) {
                    $rows[] = [
                        'tenant_id' => $t->id,
                        'name' => $t->contact_person ?: $t->name,
                        'email' => $t->email,
                        'password' => $t->password,   // already hashed
                        'is_admin' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($rows) {
                    DB::table('tenant_users')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};
