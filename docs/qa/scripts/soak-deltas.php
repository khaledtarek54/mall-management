<?php

use App\Models\Asset;
use App\Models\CreditNote;
use App\Models\Expense;
use App\Models\FacilityWorkOrder;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Models\TenantRequest;
use App\Models\Unit;
use App\Models\VendorBill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * What moved on the books since a point in time — the data half of the daily soak report.
 *
 * Executed INSIDE the application by `soak-check.sh` (through `php artisan tinker --execute`), so it
 * reads the real models and the real settlement rules instead of re-deriving them in SQL. Read-only.
 *
 * Prints one JSON object. `$since` is injected by the caller (ISO-8601); it defaults to 24 hours ago.
 */
$since = isset($since) ? CarbonImmutable::parse($since) : CarbonImmutable::now()->subDay();
$now = CarbonImmutable::now();

$created = fn (string $model, string $column = 'created_at') => $model::query()->where($column, '>=', $since)->count();

$invoicesSince = Invoice::query()->where('created_at', '>=', $since)->get();
$lateFees = $invoicesSince->filter(fn ($i) => $i->late_fee_for_invoice_id !== null)->count();

$overdue = Invoice::query()->where('status', 'overdue');
$open = Invoice::query()->whereIn('status', ['issued', 'partially_paid', 'overdue']);

$tb = JournalLine::query()
    ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
    ->where('journal_entries.status', 'posted')
    ->selectRaw('round(sum(journal_lines.debit), 2) as dr, round(sum(journal_lines.credit), 2) as cr')
    ->first();

$failedJobs = DB::table('failed_jobs')->where('failed_at', '>=', $since)->count();

$perProperty = Asset::query()
    ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
    ->get()
    ->map(fn ($a) => [
        'code' => $a->code,
        'invoices_new' => Invoice::query()->where('asset_id', $a->id)->where('created_at', '>=', $since)->count(),
        'open_ar' => round((float) Invoice::query()->where('asset_id', $a->id)->whereIn('status', ['issued', 'partially_paid', 'overdue'])->sum('balance'), 2),
        'overdue' => Invoice::query()->where('asset_id', $a->id)->where('status', 'overdue')->count(),
        'leases_active' => Lease::query()->where('status', 'active')->whereHas('unit', fn ($q) => $q->where('asset_id', $a->id))->count(),
        'units_vacant' => Unit::query()->where('asset_id', $a->id)->where('status', 'vacant')->count(),
    ])
    ->values()
    ->all();

echo json_encode([
    'since' => $since->toIso8601String(),
    'now' => $now->toIso8601String(),
    'new' => [
        'invoices' => $invoicesSince->count(),
        'late_fee_invoices' => $lateFees,
        'payments' => $created(Payment::class),
        'credit_notes' => $created(CreditNote::class),
        'journal_entries' => $created(JournalEntry::class),
        'journal_entries_void' => JournalEntry::query()->where('status', 'void')->where('updated_at', '>=', $since)->count(),
        'expenses' => $created(Expense::class),
        'vendor_bills' => $created(VendorBill::class),
        'work_orders' => $created(FacilityWorkOrder::class),
        'tenant_requests' => $created(TenantRequest::class),
        'lease_events' => $created(LeaseEvent::class),
        'notifications' => DB::table('notifications')->where('created_at', '>=', $since)->count(),
        'activity_log' => DB::table('activity_log')->where('created_at', '>=', $since)->count(),
    ],
    'state' => [
        'leases_by_status' => Lease::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
        'invoices_by_status' => Invoice::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
        'open_ar' => round((float) $open->sum('balance'), 2),
        'overdue_count' => $overdue->count(),
        'overdue_balance' => round((float) $overdue->sum('balance'), 2),
        'open_ap' => round((float) VendorBill::query()->whereIn('status', ['approved', 'partially_paid'])->sum('balance'), 2),
        'pdc_held_matured' => PostDatedCheque::query()->where('status', 'held')->whereDate('cheque_date', '<=', $now)->count(),
        'work_orders_open' => FacilityWorkOrder::query()->whereIn('status', ['open', 'in_progress'])->count(),
        'requests_open' => TenantRequest::query()->whereNotIn('status', ['resolved', 'closed', 'rejected', 'cancelled'])->count(),
        'trial_balance' => ['debit' => (float) ($tb->dr ?? 0), 'credit' => (float) ($tb->cr ?? 0), 'balanced' => abs((float) ($tb->dr ?? 0) - (float) ($tb->cr ?? 0)) < 0.005],
        'failed_jobs_since' => $failedJobs,
        'queue_pending' => Queue::size(),
    ],
    'properties' => $perProperty,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
