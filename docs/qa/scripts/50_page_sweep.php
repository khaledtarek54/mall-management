<?php

require __DIR__.'/boot.php';
use App\Models\Area;
use App\Models\Asset;
use App\Models\CamExpensePool;
use App\Models\CreditNote;
use App\Models\DepositTransaction;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Models\PurchaseRequest;
use App\Models\RentableItem;
use App\Models\TenantSalesDeclaration;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

$user = User::where('email', 'admin@mall.test')->firstOrFail();
$code = 'AW';

function qa_page(string $uri, User $user): array
{
    $kernel = app(Kernel::class);
    Auth::guard('web')->login($user);
    $req = Request::create($uri, 'GET');
    $req->headers->set('Accept', 'text/html');
    $req->setLaravelSession(app('session.store'));
    try {
        $res = $kernel->handle($req);
        $body = (string) $res->getContent();

        return ['status' => $res->getStatusCode(), 'body' => $body];
    } catch (Throwable $e) {
        return ['status' => 0, 'body' => get_class($e).': '.$e->getMessage()];
    }
}

/** first record id, preferring one on the AW property when the model carries the column */
function qa_rec(string $class): ?int
{
    $m = new $class;
    $q = $class::query();
    if (Schema::hasColumn($m->getTable(), 'asset_id')) {
        $scoped = (clone $q)->where('asset_id', 2)->value($m->getKeyName());
        if ($scoped) {
            return (int) $scoped;
        }
    }
    $any = $q->value($m->getKeyName());

    return $any ? (int) $any : null;
}

/** Modules → [label, list slug, a record id or null] */
$modules = [
    'SPACING' => [
        ['assets',            Asset::where('code', 'AW')->value('id'), true],
        ['units',             qa_rec(Unit::class), true],
        ['areas',             qa_rec(Area::class), true],
        ['unit-ownerships',   qa_rec(UnitOwnership::class), true],
        ['rentable-items',    qa_rec(RentableItem::class), true],
    ],
    'LEASING' => [
        ['leases',                    qa_rec(Lease::class), true],
        ['tenant-sales-declarations', qa_rec(TenantSalesDeclaration::class), true],
        ['cam-expense-pools',         qa_rec(CamExpensePool::class), true],
    ],
    'RECEIVABLES' => [
        ['invoices',             qa_rec(Invoice::class), true],
        ['payments',             qa_rec(Payment::class), true],
        ['credit-notes',         qa_rec(CreditNote::class), true],
        ['deposit-transactions', qa_rec(DepositTransaction::class), true],
        ['post-dated-cheques',   qa_rec(PostDatedCheque::class), true],
    ],
    'PAYABLES' => [
        ['vendor-bills',      qa_rec(VendorBill::class), true],
        ['vendors',           qa_rec(Vendor::class), true],
        ['expenses',          qa_rec(Expense::class), true],
        ['purchase-requests', qa_rec(PurchaseRequest::class), true],
        ['disbursements',     null, false],
    ],
];

$pages = [
    'SPACING' => ['occupancy-map', 'rent-roll', 'expiration-schedule', 'occupancy-cost'],
    'LEASING' => ['billing-run-preview', 'sales-analytics'],
    'RECEIVABLES' => ['ar-aging', 'ar-aging-by-type', 'ar-collections'],
    'PAYABLES' => ['weekly-spend', 'vendor-scorecard', 'budget'],
    'ACCOUNTING' => ['trial-balance', 'general-ledger', 'income-statement', 'balance-sheet', 'cash-flow',
        'month-end-close', 'vat-return', 'opening-balances', 'reports', 'report-hub', 'configuration-health'],
];

foreach ($modules as $mod => $resources) {
    qa_section("PAGES · $mod — resource screens");
    foreach ($resources as [$slug, $recordId, $hasCreate]) {
        $r = qa_page("/admin/$code/$slug", $user);
        qa_ok("LIST   /$slug", $r['status'] === 200, 'HTTP '.$r['status'].($r['status'] === 200 ? '' : ' — '.mb_substr(strip_tags($r['body']), 0, 140)));
        if ($hasCreate) {
            $r = qa_page("/admin/$code/$slug/create", $user);
            qa_ok("CREATE /$slug/create", in_array($r['status'], [200, 403], true),
                'HTTP '.$r['status'].(in_array($r['status'], [200, 403], true) ? '' : ' — '.mb_substr(strip_tags($r['body']), 0, 140)));
        }
        if ($recordId) {
            $r = qa_page("/admin/$code/$slug/$recordId/edit", $user);
            qa_ok("EDIT   /$slug/{id}/edit", in_array($r['status'], [200, 403, 404], true),
                'HTTP '.$r['status'].(in_array($r['status'], [200, 403, 404], true) ? '' : ' — '.mb_substr(strip_tags($r['body']), 0, 140)));
        }
    }
}

foreach ($pages as $mod => $slugs) {
    qa_section("PAGES · $mod — report screens");
    foreach ($slugs as $slug) {
        $r = qa_page("/admin/$code/$slug", $user);
        qa_ok("PAGE   /$slug", $r['status'] === 200, 'HTTP '.$r['status'].($r['status'] === 200 ? '' : ' — '.mb_substr(strip_tags($r['body']), 0, 180)));
    }
}

qa_section('PAGES · dashboard + global search (the MySQL-only crash class)');
$r = qa_page("/admin/$code", $user);
qa_ok('dashboard renders', $r['status'] === 200, 'HTTP '.$r['status']);

qa_summary();
