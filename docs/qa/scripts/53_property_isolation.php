<?php

require __DIR__.'/boot.php';
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use App\Models\Asset;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\VendorBill;
use App\Services\CreditNoteService;
use App\Support\TenantScope;
use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$aw = Asset::where('code', 'AW')->firstOrFail();
$pa = Asset::where('code', 'PA')->firstOrFail();

qa_section('ISOLATION setup — a manager assigned ONLY to Plaza Annex');
$paUser = User::firstOrCreate(['email' => 'qa-pa@mall.test'],
    ['name' => 'QA Plaza Manager', 'password' => bcrypt('password'), 'email_verified_at' => now()]);
$paUser->syncRoles(['manager']);
$paUser->assignedAssets()->sync([$pa->id]);
qa_eq('assigned to exactly one property', 1, $paUser->fresh()->assignedAssets()->count());
qa_eq('…and it is Plaza Annex', $pa->id, (int) $paUser->fresh()->assignedAssets()->first()->id);

function qa_visit(string $uri, User $u): array
{
    $kernel = app(Kernel::class);
    Auth::guard('web')->login($u);
    $req = Request::create($uri, 'GET');
    $req->headers->set('Accept', 'text/html');
    $req->setLaravelSession(app('session.store'));
    try {
        $r = $kernel->handle($req);

        return ['status' => $r->getStatusCode(), 'body' => (string) $r->getContent()];
    } catch (Throwable $e) {
        return ['status' => 0, 'body' => get_class($e).': '.$e->getMessage()];
    }
}

qa_section('ISOLATION 1 — a foreign property URL is refused outright');
foreach (['units', 'leases', 'invoices', 'payments', 'vendor-bills', 'expenses', 'credit-notes'] as $slug) {
    $r = qa_visit("/admin/AW/$slug", $paUser);
    qa_ok("/admin/AW/$slug refused", in_array($r['status'], [403, 404, 302], true), 'HTTP '.$r['status']);
}
foreach (['units', 'leases', 'invoices', 'payments', 'vendor-bills', 'expenses'] as $slug) {
    $r = qa_visit("/admin/PA/$slug", $paUser);
    qa_ok("/admin/PA/$slug allowed (the control)", $r['status'] === 200, 'HTTP '.$r['status']);
}

qa_section('ISOLATION 2 — the ALL pseudo-property is not reachable');
$r = qa_visit('/admin/ALL/invoices', $paUser);
qa_ok('/admin/ALL is refused', in_array($r['status'], [403, 404, 302], true), 'HTTP '.$r['status']);

qa_section('ISOLATION 3 — reads are scoped even when the record id is guessed');
$awUnit = Unit::where('asset_id', $aw->id)->firstOrFail();
$awInvoice = Invoice::where('asset_id', $aw->id)->firstOrFail();
$awBill = VendorBill::where('asset_id', $aw->id)->first();
foreach ([['units', $awUnit->id], ['invoices', $awInvoice->id]] as [$slug,$id]) {
    $r = qa_visit("/admin/PA/$slug/$id/edit", $paUser);
    qa_ok("PA-scoped edit of an AW $slug record is refused", in_array($r['status'], [403, 404], true), 'HTTP '.$r['status']);
}
if ($awBill) {
    $r = qa_visit("/admin/PA/vendor-bills/{$awBill->id}/edit", $paUser);
    qa_ok('PA-scoped edit of an AW vendor bill is refused', in_array($r['status'], [403, 404], true), 'HTTP '.$r['status']);
}

qa_section('ISOLATION 4 — the scoped query itself returns only the right property');
Auth::login($paUser);
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant($pa, true);
$visible = TenantScope::visibleAssetIds();
printf("  visibleAssetIds() = %s\n", json_encode($visible));
qa_eq('the visible set is exactly Plaza Annex', [$pa->id], array_values($visible));

$leakedUnits = UnitResource::getEloquentQuery()->pluck('asset_id')->unique()->values()->all();
qa_eq('the unit list shows only PA', [$pa->id], $leakedUnits);
$leakedInv = InvoiceResource::getEloquentQuery()->pluck('asset_id')->unique()->values()->all();
qa_ok('the invoice list shows only PA', $leakedInv === [$pa->id] || $leakedInv === [], json_encode($leakedInv));
$leakedBills = VendorBillResource::getEloquentQuery()->pluck('asset_id')->unique()->values()->all();
qa_ok('the vendor-bill list shows only PA', $leakedBills === [$pa->id] || $leakedBills === [], json_encode($leakedBills));
$leakedLeases = LeaseResource::getEloquentQuery()->with('unit')->get()
    ->pluck('unit.asset_id')->unique()->values()->all();
qa_ok('the lease list shows only PA', $leakedLeases === [$pa->id] || $leakedLeases === [], json_encode($leakedLeases));

qa_section('ISOLATION 5 — a WRITE stamped with a foreign property is refused');
qa_refuses('creating a unit on another mall is refused by the page guard',
    fn () => UnitResource::assertAssetInScope($aw->id),
    null, Throwable::class);
qa_allows('…and the own property is allowed (the control)',
    fn () => UnitResource::assertAssetInScope($pa->id));
qa_refuses('a blank property is refused too (null === 0)',
    fn () => UnitResource::assertAssetInScope((int) null),
    null, Throwable::class);

qa_section('ISOLATION 6 — a cross-property credit note cannot settle a foreign invoice');
$paLease = Lease::whereHas('unit', fn ($q) => $q->where('asset_id', $pa->id))->first();
if ($paLease) {
    $cn = CreditNote::create(['tenant_id' => $paLease->tenant_id, 'lease_id' => $paLease->id, 'asset_id' => $pa->id,
        'issue_date' => '2026-08-10', 'reason' => 'adjustment', 'reason_notes' => 'QA cross-property', 'status' => 'draft',
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'balance' => 1000]);
    $cn->items()->create(['description' => 'QA', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 1000]);
    app(CreditNoteService::class)->issue($cn->fresh());
    $awOpen = Invoice::where('asset_id', $aw->id)->where('balance', '>', 0)
        ->where('tenant_id', $paLease->tenant_id)->first();
    if ($awOpen) {
        qa_refuses('a PA credit note cannot settle an AW invoice',
            fn () => app(CreditNoteService::class)->applyToInvoice($cn->fresh(), $awOpen->fresh()));
    } else {
        echo "  (the PA tenant has no open AW invoice — building one)\n";
        $awLease = Lease::whereHas('unit', fn ($q) => $q->where('asset_id', $aw->id))->first();
        $foreign = Invoice::create(['tenant_id' => $paLease->tenant_id, 'lease_id' => $awLease->id, 'asset_id' => $aw->id,
            'number' => 'QA-XP-'.uniqid(), 'issue_date' => '2026-08-01', 'due_date' => '2026-08-15',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'issued',
            'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'paid_amount' => 0, 'balance' => 5000, 'currency' => 'EGP']);
        qa_refuses('a PA credit note cannot settle an AW invoice',
            fn () => app(CreditNoteService::class)->applyToInvoice($cn->fresh(), $foreign->fresh()));
    }
}

qa_section('ISOLATION 7 — a payment cannot be spread across two tenants');
$t1 = Tenant::first();
$t2 = Tenant::where('id', '!=', $t1->id)->first();
$i1 = Invoice::where('tenant_id', $t1->id)->where('balance', '>', 0)->first();
$i2 = Invoice::where('tenant_id', $t2->id)->where('balance', '>', 0)->first();
if ($i1 && $i2) {
    qa_refuses('one payment cannot allocate across two tenants', function () use ($t1, $i1, $i2) {
        $p = Payment::create(['tenant_id' => $t1->id, 'amount' => 100, 'payment_date' => '2026-08-20',
            'method' => 'cash', 'status' => 'captured', 'reference' => 'QA-XT-'.uniqid()]);
        $p->assertInvoicesShareTenant([$i1->id, $i2->id]);
    }, null, Throwable::class);
}

qa_summary();
