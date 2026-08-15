<?php

use App\Services\SendAnnouncementAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who a notice went to, and who has read it.
 *
 * **One table, two jobs, and they belong together.** The recipient list and the read receipt are
 * the same fact seen at two moments — "this tenant was sent it" and "this tenant opened it" — so
 * splitting them would mean a join to answer the only question an operator ever asks, which is
 * *"has that store seen it yet"*. `notified_at` null means the fan-out failed for that tenant
 * (the blast isolates per-recipient failures rather than stranding the record); `read_at` null
 * means it is still unread.
 *
 * **The recipient set is what makes the tenant feed correct, not just possible.** Without it, a
 * feed would have to re-derive "which notices apply to me" from property membership at READ time,
 * and that answer changes: a retailer who signs a lease on the 10th would suddenly see September's
 * notices as if they had been there, and a retailer who moves out loses the notice they were
 * actually sent — including the one they are arguing about. Recording the set at send time is the
 * only version that stays true, and it matches what {@see SendAnnouncementAction}
 * already computed and threw away.
 *
 * **Keyed on the TENANT, not the portal login.** A store is one recipient however many staff
 * logins it has, which is already how `recipients_count` counts. `read_by_tenant_user_id` records
 * *who* opened it when the reader came through the web portal; the mobile API authenticates the
 * Tenant company itself and has no user to record, so null there is normal and expected.
 *
 * **Backfill is a reconstruction and is labelled as one.** The true recipient set of every
 * already-sent announcement was never stored — it was a Collection inside a queued job. The
 * closest honest approximation is the property's CURRENT active tenants, stamped with the
 * announcement's own `sent_at`, and that is what runs below. `announcements.recipients_count` is
 * deliberately NOT overwritten: it is the recorded count from the actual blast, and a
 * reconstruction must not overwrite a measurement. The two can disagree on historic rows; on
 * everything sent from here on they cannot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_recipients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Stamped by the fan-out. Null = this recipient's delivery threw and was logged.
            $table->timestamp('notified_at')->nullable();

            $table->timestamp('read_at')->nullable();
            // Which portal login opened it. Null when the reader was the mobile app, which
            // authenticates the Tenant and has no user. nullOnDelete: losing a staff login must
            // not lose the fact that the store read the notice.
            $table->foreignId('read_by_tenant_user_id')->nullable()
                ->constrained('tenant_users')->nullOnDelete();

            $table->timestamps();

            // One row per (notice, store) — the upsert key for the fan-out, and what makes a
            // re-run idempotent even if the sent_at guard were ever bypassed.
            $table->unique(['announcement_id', 'tenant_id'], 'announcement_recipient_unique');
            // The tenant feed and its unread badge: "my notices, unread first".
            $table->index(['tenant_id', 'read_at'], 'announcement_recipients_inbox_idx');
        });

        $this->reconstructHistoricRecipients();
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_recipients');
    }

    /**
     * Rebuild a plausible recipient set for notices broadcast before this table existed, so the
     * new tenant feed does not open empty on a system with history. See the class docblock for
     * why this is an approximation and why `recipients_count` is left alone.
     *
     * Reaches units through the `lease_unit` pivot, never `leases.unit_id` — the master-unit trap
     * the fan-out itself documents: a multi-unit lease whose ADDITIONAL unit sits in the target
     * property would otherwise be missed.
     */
    private function reconstructHistoricRecipients(): void
    {
        $sent = DB::table('announcements')
            ->whereNotNull('sent_at')
            ->whereNull('deleted_at')
            ->get(['id', 'asset_id', 'sent_at']);

        foreach ($sent as $announcement) {
            $tenantIds = DB::table('tenants')
                ->join('leases', 'leases.tenant_id', '=', 'tenants.id')
                ->join('lease_unit', 'lease_unit.lease_id', '=', 'leases.id')
                ->join('units', 'units.id', '=', 'lease_unit.unit_id')
                ->where('leases.status', 'active')
                ->where('units.asset_id', $announcement->asset_id)
                ->whereNull('units.deleted_at')
                ->distinct()
                ->pluck('tenants.id');

            if ($tenantIds->isEmpty()) {
                continue;
            }

            DB::table('announcement_recipients')->insertOrIgnore(
                $tenantIds->map(fn ($tenantId) => [
                    'announcement_id' => $announcement->id,
                    'tenant_id' => $tenantId,
                    'notified_at' => $announcement->sent_at,
                    'read_at' => null,
                    'read_by_tenant_user_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all()
            );
        }
    }
};
