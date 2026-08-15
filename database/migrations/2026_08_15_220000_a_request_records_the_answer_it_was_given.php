<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **A request that ASKED for something now records whether it was granted.**
 *
 * This reverses a stated decision, so it states why. `2026_07_18_150001` added the permit validity
 * window and closed with: *"There is NO approval step — a permit is a typed request with a validity
 * window, nothing more."* That was coherent while the only reader was the admin panel, where a
 * human reads `resolution_notes` and understands.
 *
 * It stopped being coherent the moment a client had to render a permit as a **gate artifact**. The
 * seven statuses carry no outcome, so the mobile app inferred one from the lifecycle:
 * `resolved`/`closed` → **"Approved"**. Which means **a staff rejection has been reading to the
 * tenant as an approval**, on the card they would show a security guard. The app could not have
 * done better — closing a ticket is genuinely how a refusal ends, and inferring the opposite
 * would be equally wrong.
 *
 * So the answer becomes data. Nullable, because:
 *   - most request types are not a question — a leaking pipe is fixed or not, never "rejected";
 *   - every row that already exists predates the decision and must stay legible. **Null on a
 *     resolved request means "we do not know", and every reader must render it that way** — not as
 *     an approval. That is the whole bug, and defaulting the column would recreate it at scale.
 *
 * Which types are a question is {@see \App\Enums\TenantRequestType::requiresDecision()} — `permit`,
 * `access` and `document`, the three where the tenant is asking for permission or for a thing.
 * `App\Services\TenantRequestService::transition()` refuses to resolve one of those without an
 * answer, so null stays a legacy state rather than becoming a fresh one.
 *
 * `decided_by` is the operator who answered. A refusal a tenant can act on is a refusal someone
 * put their name to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table) {
            // string(32), not an enum — house rule. The set lives in App\Support\ValueSets and is
            // refused on save by the wildcard `eloquent.saving` listener.
            $table->string('decision', 32)->nullable()->after('resolution_notes');
            // WHY it was refused. Sending this is the entire reason a rejection demands one: a
            // tenant told only "rejected" resubmits the same request on Monday.
            $table->text('decision_reason')->nullable()->after('decision');
            $table->timestamp('decided_at')->nullable()->after('decision_reason');
            $table->foreignId('decided_by')->nullable()->after('decided_at')
                ->constrained('users')->nullOnDelete();

            // The operator's board filters on this ("show me what is still unanswered"), and the
            // tenant API sorts a permit list by it.
            $table->index(['decision', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_requests', function (Blueprint $table) {
            $table->dropIndex(['decision', 'status']);
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['decision', 'decision_reason', 'decided_at']);
        });
    }
};
