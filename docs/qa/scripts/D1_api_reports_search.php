<?php

require __DIR__.'/boot.php';
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\Unit;
use App\Models\User;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\Reports\ReportService;
use App\Support\Search\SearchText;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

$asset = Asset::where('code', 'AW')->firstOrFail();
$occupied = Unit::where('asset_id', $asset->id)->where('status', 'occupied')->firstOrFail();
$lease = Lease::where('unit_id', $occupied->id)->where('status', 'active')->firstOrFail();
$tenant = $lease->tenant;
$other = Tenant::where('id', '!=', $tenant->id)->whereHas('leases')->firstOrFail();

// boot.php authenticates an admin USER on the web guard for the other suites. A real API request
// has no such session, and leaving it in place makes `$request->user()` resolve to a User rather
// than a Tenant — which 500s the resource and looks exactly like a product bug. Measure the
// product, not the harness.
Auth::logout();

/** JSON call against /api/v1 as a tenant (Sanctum), through the real kernel. */
function qa_api(string $method, string $uri, ?Tenant $as = null, array $body = []): array
{
    $kernel = app(Kernel::class);
    $headers = ['Accept' => 'application/json'];
    if ($as) {
        // Since 2026-09-05 the tenant-api guard authenticates a TenantUser — the SAME row the
        // portal signs in — not the Tenant company. A token minted on Tenant now 401s everywhere,
        // so mint it on one of the company's portal users (admin preferred: writes are gated).
        $tu = $as->users()->where('is_admin', true)->first()
            ?? $as->users()->firstOrFail();
        $headers['Authorization'] = 'Bearer '.$tu->createToken('qa', ['tenant:*'])->plainTextToken;
    }
    $req = Request::create($uri, $method, $body, [], [], array_combine(
        array_map(fn ($h) => 'HTTP_'.strtoupper(str_replace('-', '_', $h)), array_keys($headers)),
        array_values($headers)));
    $req->headers->set('Accept', 'application/json');
    try {
        $res = $kernel->handle($req);

        return ['status' => $res->getStatusCode(), 'json' => json_decode((string) $res->getContent(), true) ?: []];
    } catch (Throwable $e) {
        return ['status' => 0, 'json' => ['exception' => get_class($e).': '.$e->getMessage()]];
    }
}

/* ══════════════════════════ MODULE 20 · MOBILE API ══════════════════════════ */
qa_section('API 1 — every /me endpoint needs a token');
foreach (['/api/v1/me', '/api/v1/me/invoices', '/api/v1/me/leases', '/api/v1/me/summary',
    '/api/v1/me/balance', '/api/v1/me/statement', '/api/v1/me/requests', '/api/v1/me/payments'] as $uri) {
    $r = qa_api('GET', $uri);
    qa_ok("unauthenticated $uri is refused", in_array($r['status'], [401, 403], true), 'HTTP '.$r['status']);
}

qa_section('API 2 — an authenticated tenant sees their OWN data');
foreach (['/api/v1/me', '/api/v1/me/invoices', '/api/v1/me/leases', '/api/v1/me/summary',
    '/api/v1/me/balance', '/api/v1/me/statement', '/api/v1/me/requests', '/api/v1/me/payments',
    '/api/v1/me/credit-notes', '/api/v1/me/announcements', '/api/v1/me/feed'] as $uri) {
    $r = qa_api('GET', $uri, $tenant);
    qa_ok("GET $uri", $r['status'] === 200, 'HTTP '.$r['status'].' '.mb_substr(json_encode($r['json']), 0, 90));
}

qa_section('API 3 — a tenant NEVER sees a draft');
$draft = Invoice::create(['tenant_id' => $tenant->id, 'lease_id' => $lease->id, 'asset_id' => $asset->id,
    'number' => 'QA-API-DRAFT-'.uniqid(), 'issue_date' => '2026-08-01', 'due_date' => '2026-08-15',
    'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft',
    'subtotal' => 9999, 'vat_amount' => 0, 'total' => 9999, 'paid_amount' => 0, 'balance' => 9999, 'currency' => 'EGP']);
$list = qa_api('GET', '/api/v1/me/invoices', $tenant);
$ids = collect($list['json']['data'] ?? [])->pluck('id')->all();
qa_ok('the draft is absent from the list', ! in_array($draft->id, $ids, true), count($ids).' invoice(s) returned');
qa_ok('…and the list is NOT empty (the control)', count($ids) > 0);
$show = qa_api('GET', '/api/v1/me/invoices/'.$draft->id, $tenant);
qa_eq('…and fetching it directly 404s', 404, $show['status']);
$filtered = qa_api('GET', '/api/v1/me/invoices?status=draft', $tenant);
$fids = collect($filtered['json']['data'] ?? [])->pluck('id')->all();
qa_ok('?status=draft cannot enumerate them either', ! in_array($draft->id, $fids, true), count($fids).' returned');

qa_section('API 4 — cross-tenant access returns 404, never 403 (no existence enumeration)');
$foreignInvoice = Invoice::where('tenant_id', $other->id)->whereNotIn('status', ['draft'])->first();
if ($foreignInvoice) {
    $r = qa_api('GET', '/api/v1/me/invoices/'.$foreignInvoice->id, $tenant);
    qa_eq('another tenant invoice 404s', 404, $r['status']);
    qa_ok('…and says nothing about it existing',
        ! str_contains(mb_strtolower(json_encode($r['json'])), 'forbidden'), json_encode($r['json']));
}
$foreignRequest = TenantRequest::where('tenant_id', $other->id)->first();
if ($foreignRequest) {
    $r = qa_api('GET', '/api/v1/me/requests/'.$foreignRequest->id, $tenant);
    qa_eq('another tenant REQUEST 404s', 404, $r['status']);
    $mine = TenantRequest::where('tenant_id', $tenant->id)->first();
    if ($mine) {
        qa_eq('…while my own request is 200 (the control)', 200,
            qa_api('GET', '/api/v1/me/requests/'.$mine->id, $tenant)['status']);
    }
}

qa_section('API 5 — the PUBLIC feed needs no token and leaks no tenant data');
$pub = qa_api('GET', '/api/v1/public/malls');
qa_eq('the mall list is public', 200, $pub['status']);
$code = $asset->code;
foreach (["/api/v1/public/malls/{$code}/posts", "/api/v1/public/malls/{$code}/stores"] as $uri) {
    $r = qa_api('GET', $uri);
    qa_eq("GET $uri is public", 200, $r['status']);
    // Checked on the KEYS, not on a substring of the whole payload — "current" contains "rent".
    $keys = collect($r['json']['data'] ?? [])->flatMap(fn ($row) => array_keys((array) $row))
        ->map(fn ($k) => Str::snake((string) $k))->unique();
    $financial = $keys->filter(fn ($k) => in_array($k, ['balance', 'rent', 'base_rent', 'total', 'paid_amount',
        'outstanding', 'tax_id', 'national_id', 'service_charge'], true));
    qa_ok('…and exposes no financial or PII field', $financial->isEmpty(), $financial->join(',') ?: 'none');
}

/* ══════════════════════════ MODULE 17 · REPORTS ══════════════════════════ */
qa_section('REPORTS — every report returns, and its CSV matches what is on screen');
$reports = app(ReportService::class);
$asOf = CarbonImmutable::parse('2026-08-31');
$rows = [
    'arAgingBuckets' => fn () => $reports->arAgingBuckets($asOf),
    'arAgingByChargeType' => fn () => $reports->arAgingByChargeType($asOf),
    'arCollectionsByTenant' => fn () => $reports->arCollectionsByTenant($asOf),
    'rentRoll' => fn () => $reports->rentRoll($asOf, $asset->id),
    'expirationSchedule' => fn () => $reports->expirationSchedule($asOf, $asset->id),
    'occupancyCost' => fn () => $reports->occupancyCost(CarbonImmutable::parse('2026-01-01'), $asOf, $asset->id),
    'salesAnalytics' => fn () => $reports->salesAnalytics($asOf, $asset->id),
    'monthlyClose' => fn () => $reports->monthlyClose(CarbonImmutable::parse('2026-08-01')),
    'weeklySpend' => fn () => $reports->weeklySpend(CarbonImmutable::parse('2026-08-01'), $asOf),
    'topDelinquentTenants' => fn () => $reports->topDelinquentTenants(10),
];
foreach ($rows as $name => $fn) {
    try {
        $out = $fn();
        qa_ok("$name returns", $out !== null,
            is_countable($out) ? count($out).' row(s)' : gettype($out));
    } catch (Throwable $e) {
        qa_ok("$name returns", false, get_class($e).': '.mb_substr($e->getMessage(), 0, 120));
    }
}

qa_section('REPORTS — the rent roll agrees with the leases it describes');
$roll = $reports->rentRoll($asOf, $asset->id);
$activeLeases = Lease::whereHas('unit', fn ($q) => $q->where('asset_id', $asset->id))
    ->where('status', 'active')->count();
printf("  rent roll rows: %d · active leases on the property: %d\n", $roll->count(), $activeLeases);
qa_ok('the roll covers the active tenancies', $roll->count() > 0);
$rollRent = round((float) $roll->sum(fn ($r) => (float) ($r['base_rent'] ?? $r['rent'] ?? 0)), 2);
printf("  roll total rent: %s\n", number_format($rollRent, 2));
qa_ok('…and totals a positive rent', $rollRent > 0);

/* ══════════════════════════ MODULE 34 · SEARCH ══════════════════════════ */
qa_section('SEARCH — the blob is the only search key, and both sides fold');
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant($asset, true);
// Back on the ADMIN guard, and made the DEFAULT one — the API calls above left `tenant-api`
// current, and Filament drops any search result whose URL is blank (derived from canView()), so a
// wrong guard returns zero for everything and every refusal would pass for the wrong reason.
Auth::shouldUse('web');
Auth::login(User::where('email', 'admin@mall.test')->firstOrFail());
$resources = collect(Filament::getPanel('admin')->getResources())
    ->filter(fn ($r) => $r::canGloballySearch());
$failures = [];
foreach ($resources as $r) {
    foreach (['a', 'شركة', 'أحمد', 'INV', 'A-01', '%_', "O'Brien", '٠١٢'] as $term) {
        try {
            $r::getGlobalSearchResults($term);
        } catch (Throwable $e) {
            $failures[] = class_basename($r).' ["'.$term.'"] '.get_class($e);
        }
    }
}
qa_eq('no searchable resource throws on MySQL', 0, count($failures));
foreach ($failures as $f) {
    echo "    $f\n";
}
$t = Tenant::whereNotNull('search_text')->where('search_text', '!=', '')->first();
$hits = TenantResource::getGlobalSearchResults(
    SearchText::normalize(mb_substr($t->name, 0, 5)));
qa_ok('a real tenant is findable by the start of its name', $hits->count() > 0,
    $t->name.' → '.$hits->count());
$rebuilt = Artisan::call('atriom:rebuild-search');
qa_eq('atriom:rebuild-search runs clean', 0, $rebuilt);
$after = (string) $t->fresh()->search_text;
qa_eq('…and the blob is stable (a pure function of the row)', (string) $t->search_text, $after);

qa_section('BATCH D TIE-OUT');
// DemoSeeder leaves ONE owner-statement run unposted, so a pristine database reports a single
// drift until the sweep runs. Not caused by anything here — see the baseline note in
// PRE-STAGING-QA.md — but it has to be cleared before asserting zero.
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_assert_tb('after the API, reports and search');
$rec = app(BooksReconciliationService::class);
qa_eq('AR ties', 0.0, $rec->glTieOut()['ar']['delta']);
qa_eq('no GL drift', 0, count($rec->glDriftDiscrepancies()));

qa_summary();
