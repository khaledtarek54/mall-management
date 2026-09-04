<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ONE LOGIN PER PERSON, FOR BOTH TENANT-FACING SURFACES (owner's decision, 2026-09-05).
 *
 * The mobile API authenticated the COMPANY row (`tenants.email` + `tenants.password`) while the web
 * portal authenticated a `tenant_users` row. Two credentials for one retailer: the app could not say
 * which member of staff acted, one person could not be revoked without changing everybody's
 * password, and the operator had to set up two things per tenant — which then drifted, and did:
 * reported from staging as a portal login that "always gives wrong creds".
 *
 * The guard now resolves `tenant_users`. WITHOUT THIS BACKFILL that change locks out every tenant
 * who has a working app login today, silently and on deploy — their credential would simply no
 * longer resolve to anything. So every company holding a password gets the person that password
 * already stood for.
 *
 * `is_admin = true` is deliberate and is NOT a widening: the company credential could already do
 * everything the API offers, so anything less would REMOVE capability from people who have it
 * today. New staff added on the Portal Users tab default to read-only, which is where the
 * distinction starts to earn its keep.
 *
 * The hash is copied with a raw insert rather than a model save: `TenantUser::$casts` marks
 * `password` as `hashed`, and assigning an already-hashed value through the model risks hashing the
 * hash — every backfilled person would be locked out by the very migration meant to keep them in.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('tenants')
            ->whereNotNull('password')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(200, function ($tenants) use ($now) {
                foreach ($tenants as $tenant) {
                    if (blank($tenant->email)) {
                        continue;
                    }

                    // Already has a login on this address — the portal user IS the person, and
                    // re-creating it would collide with the unique index on tenant_users.email.
                    $taken = DB::table('tenant_users')->where('email', $tenant->email)->exists();

                    if ($taken) {
                        continue;
                    }

                    DB::table('tenant_users')->insert([
                        'tenant_id' => $tenant->id,
                        'name' => $tenant->contact_person ?: $tenant->name,
                        'email' => $tenant->email,
                        'password' => $tenant->password,
                        'is_admin' => true,
                        'locale' => $tenant->locale ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    /**
     * Deliberately irreversible in the direction that matters. Down() cannot know which
     * `tenant_users` rows this migration created versus which the operator has added since, and
     * deleting a real person's login to undo a data backfill is the worse mistake. The credential
     * on `tenants.password` is left untouched by up(), so rolling the CODE back restores the old
     * behaviour without needing anything here.
     */
    public function down(): void
    {
        // no-op, on purpose — see the docblock.
    }
};
