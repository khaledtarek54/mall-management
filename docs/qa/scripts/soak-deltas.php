<?php

/**
 * What moved on the books since a point in time — the data half of the daily soak report.
 *
 * Executed INSIDE the application by `soak-check.sh` (through `php artisan tinker --execute`), so it
 * reads the real models and the real settlement rules instead of re-deriving them in SQL. Read-only.
 *
 * Prints one JSON object. `$since` is injected by the caller (ISO-8601); it defaults to 24 hours ago.
 */
$since = isset($since) ? \Carbon\CarbonImmutable::parse($since) : \Carbon\CarbonImmutable::now()->subDay();
$now = \Carbon\CarbonImmutable::now();

$created = fn (string $model, string $column = 'created_at') => $model::query()->where($column, '>=', $since)->count();

$invoicesSince = \App\Models\Invoice::query()->where('created_at', '>=', $since)->get();
$lateFees = $invoicesSince->filter(fn ($i) => $i->late_fee_for_invoice_id !== null)->count();

$overdue = \App\Models\Invoice::query()->where('status', 'overdue');
$open = \App\Models\Invoice::query()->whereIn('status', ['issued', 'partially_paid', 'overdue']);

$tb = \App\Models\JournalLine::query()
    ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
    ->where('journal_entries.status', 'posted')
    ->selectRaw('round(sum(journal_lines.debit), 2) as dr, round(sum(journal_lines.credit), 2) as cr')
    ->first();

$failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')->where('failed_at', '>=', $since)->count();

$perProperty = \App\Models\Asset::query()
    ->where('code', '!=', \App\Models\Asset::ALL_PROPERTIES_CODE)
    ->get()
    ->map(fn ($a) => [
        'code' => $a->code,
        'invoices_new' => \App\Models\Invoice::query()->where('asset_id', $a->id)->where('created_at', '>=', $since)->count(),
        'open_ar' => round((float) \App\Models\Invoice::query()->where('asset_id', $a->id)->whereIn('status', ['issued', 'partially_paid', 'overdue'])->sum('balance'), 2),
        'overdue' => \App\Models\Invoice::query()->where('asset_id', $a->id)->where('status', 'overdue')->count(),
        'leases_active' => \App\Models\Lease::query()->where('status', 'active')->whereHas('unit', fn ($q) => $q->where('asset_id', $a->id))->count(),
        'units_vacant' => \App\Models\Unit::query()->where('asset_id', $a->id)->where('status', 'vacant')->count(),
    ])
    ->values()
    ->all();

echo json_encode([
    'since' => $since->toIso8601String(),
    'now' => $now->toIso8601String(),
    'new' => [
        'invoices' => $invoicesSince->count(),
        'late_fee_invoices' => $lateFees,
        'payments' => $created(\App\Models\Payment::class),
        'credit_notes' => $created(\App\Models\CreditNote::class),
        'journal_entries' => $created(\App\Models\JournalEntry::class),
        'journal_entries_void' => \App\Models\JournalEntry::query()->where('status', 'void')->where('updated_at', '>=', $since)->count(),
        'expenses' => $created(\App\Models\Expense::class),
        'vendor_bills' => $created(\App\Models\VendorBill::class),
        'work_orders' => $created(\App\Models\FacilityWorkOrder::class),
        'tenant_requests' => $created(\App\Models\TenantRequest::class),
        'lease_events' => $created(\App\Models\LeaseEvent::class),
        'notifications' => \Illuminate\Support\Facades\DB::table('notifications')->where('created_at', '>=', $since)->count(),
        'activity_log' => \Illuminate\Support\Facades\DB::table('activity_log')->where('created_at', '>=', $since)->count(),
    ],
    'state' => [
        'leases_by_status' => \App\Models\Lease::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
        'invoices_by_status' => \App\Models\Invoice::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
        'open_ar' => round((float) $open->sum('balance'), 2),
        'overdue_count' => $overdue->count(),
        'overdue_balance' => round((float) $overdue->sum('balance'), 2),
        'open_ap' => round((float) \App\Models\VendorBill::query()->whereIn('status', ['approved', 'partially_paid'])->sum('balance'), 2),
        'pdc_held_matured' => \App\Models\PostDatedCheque::query()->where('status', 'held')->whereDate('cheque_date', '<=', $now)->count(),
        'work_orders_open' => \App\Models\FacilityWorkOrder::query()->whereIn('status', ['open', 'in_progress'])->count(),
        'requests_open' => \App\Models\TenantRequest::query()->whereNotIn('status', ['resolved', 'closed', 'rejected', 'cancelled'])->count(),
        'trial_balance' => ['debit' => (float) ($tb->dr ?? 0), 'credit' => (float) ($tb->cr ?? 0), 'balanced' => abs((float) ($tb->dr ?? 0) - (float) ($tb->cr ?? 0)) < 0.005],
        'failed_jobs_since' => $failedJobs,
        'queue_pending' => \Illuminate\Support\Facades\Queue::size(),
    ],
    'properties' => $perProperty,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
