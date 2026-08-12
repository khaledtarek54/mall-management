<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A named set of report parameters, so a question worth asking twice is asked once.
 *
 * Every report here takes filters — an as-at date, a fiscal year and month, a property, an account,
 * an ageing bucket — and none of them were rememberable. "AR ageing as at last month-end for Atriom
 * Walk" was six clicks, every time, and the operator who ran it on the 3rd of each month rebuilt it
 * from scratch on the 3rd of each month. That is what the benchmark systems call a report version.
 *
 * ## Owned, and shared only on purpose
 *
 * A saved view belongs to the user who saved it. `is_shared` publishes it to everyone who can open
 * the underlying report — which is the useful case (the month-end pack the whole finance team runs)
 * and also the one that needs a deliberate act, because a shared view carries a PROPERTY in its
 * parameters and would otherwise be a way to hand somebody a filter for a mall they cannot see.
 *
 * **Sharing does not grant anything.** The hub asks the report page's own `canAccess()` before
 * listing a view, and the report itself re-scopes every parameter it is given — `assetId` is clamped
 * to the operator's visible set in `ScopesLedgerReport`, exactly as it is for a hand-typed URL.
 * A saved view is a bookmark, not a capability.
 *
 * ## Parameters are a snapshot, not a reference
 *
 * The JSON holds the values the page carried when it was saved. It deliberately does not hold a
 * query the report re-runs: a report's parameter list changes as the report grows, and a saved view
 * that half-matches is worse than one that is ignored. `App\Support\ReportParameters` applies only
 * the keys the page still declares and drops the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();

            // The catalogue KEY, not the page class: a class name in a database row breaks the day
            // somebody moves a namespace, and the key is what `ReportCatalogue` already indexes by.
            $table->string('report', 64);

            $table->string('name');
            $table->json('parameters')->nullable();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_shared')->default(false);

            $table->timestamps();

            $table->index(['user_id', 'report']);
            $table->index(['is_shared', 'report']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
