<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A named filter/sort state for a resource LIST, so a question worth asking daily is built once.
 *
 * `saved_reports` did this for report PAGES a few hours earlier and stops at the report hub. The
 * registers an operator actually lives in are the resource lists, and those are where the filters
 * pile up: Leases carries 12, Invoices 10, tenant requests 9. "Active leases in Atriom Walk whose
 * option window shuts this quarter" is five controls, and the leasing manager rebuilt it every
 * morning. That is what the benchmark systems call a saved list view.
 *
 * ## Why this is a second table rather than a `type` column on saved_reports
 *
 * They are the same idea and NOT the same record. `saved_reports` carries a scheduled-delivery
 * half — `frequency`, `day_of_month`, `recipients`, `last_delivered_on` — and every query in
 * `ReportHub` and `reports:deliver` reads rows expecting that shape. Emailing someone "the leases
 * list" is not a thing, so those columns would be permanently null here, and every existing query
 * would need a `where type = …` it does not have today. A column that is always null on half a
 * table is how a schema starts lying about what it holds.
 *
 * ## A view is a URL, which is the whole trick
 *
 * The state stored here is exactly the query string Filament's list page already binds:
 * `filters`, `sort`, `search`, `tab` (see `App\Support\ResourceLink` for why those names and not
 * the property names behind them). Applying a view is therefore a plain link — no Livewire state
 * surgery, no second code path that can disagree with the first. It also means a saved view can be
 * pasted into chat and works, and that `ResourceLinkConformanceTest`'s guarantees cover it for free.
 *
 * ## Owned, and shared only on purpose
 *
 * Same rule as `saved_reports`, for the same reason: `is_shared` publishes a view to everyone who
 * can open that resource, which is the useful case (the collections list the whole AR team works)
 * and also the one needing a deliberate act, because a view's filters can name a PROPERTY.
 *
 * **Sharing grants nothing.** Opening a view is opening a URL, and the list re-scopes every filter
 * exactly as it does for a hand-typed one — `getEloquentQuery()` still applies property isolation
 * and RBAC. A saved view is a bookmark, not a capability.
 *
 * ## Keyed by SLUG, not class name
 *
 * `invoices`, not `App\Filament\Admin\Resources\Invoices\InvoiceResource`. A class name in a
 * database row breaks the day somebody moves a namespace; the slug is already what the URL uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_views', function (Blueprint $table) {
            $table->id();

            // The resource SLUG, matching the URL segment (`invoices`, `leases`).
            $table->string('resource', 64);

            $table->string('name');

            // The query string, as {filters: {...}, sort: 'due_date:asc', search: '…', tab: '…'}.
            $table->json('state')->nullable();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_shared')->default(false);

            $table->timestamps();

            // The two reads: "my views for this list" and "the team's views for this list".
            $table->index(['user_id', 'resource']);
            $table->index(['is_shared', 'resource']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_views');
    }
};
