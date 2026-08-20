<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant confirms that the work is actually done — close-out step 4.
 *
 * ## The standard
 *
 * `docs/benchmarks/fm/02-servicechannel-contractor-loop.md` §4/§6: check-out requires the tenant's
 * confirmation, and a rejection reopens the job. *"A tenant confirming completion is a control, not
 * a courtesy. It is what stops a job being closed by the person who was paid to do it."* Scenario S7
 * is the shape of the failure: a drain partially cleared, marked done, and the shop floods two days
 * later during trading hours.
 *
 * ## What already existed, and what did not
 *
 * The lifecycle was already right — `resolved → closed` and `resolved → in_progress` are both legal
 * transitions, and `requests:auto-close` closes a resolved request after
 * `config('requests.auto_close_after_days')`. The tenant could even RATE a resolved request.
 *
 * What they could not do was **accept or dispute it**. Rating is feedback after the fact; confirming
 * is a control before closure. So the operator closed the request, or the timer did, and the tenant's
 * only recourse to "it is not actually fixed" was to raise a second request that nothing connected
 * to the first.
 *
 * ## Silence is consent, bounded by a timer — and the two are now distinguishable
 *
 * `requests:auto-close` keeps its behaviour and gains a meaning: a tenant who does not answer within
 * the window is taken to have accepted. That is the right default — chasing a retailer for a click
 * is how a queue of "resolved" requests never closes — but a close nobody confirmed should not look
 * like one somebody did. `confirmed_at` is what tells them apart: **null on a closed request means
 * the timer or the operator closed it**, and an operator asking "did the tenant actually say this
 * was fixed?" can now get an answer.
 *
 * ## Who confirmed, not just that somebody did
 *
 * The portal is multi-user (`TenantUser`), so the control is only worth something if it names the
 * person. Nullable because the column is also null for every request closed before this shipped and
 * for every auto-close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table) {
            $table->dateTime('confirmed_at')->nullable()->after('closed_at');
            $table->foreignId('confirmed_by_tenant_user_id')->nullable()->after('confirmed_at')
                ->constrained('tenant_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table) {
            $table->dropForeign(['confirmed_by_tenant_user_id']);
            $table->dropColumn(['confirmed_at', 'confirmed_by_tenant_user_id']);
        });
    }
};
