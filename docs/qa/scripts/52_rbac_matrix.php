<?php

require __DIR__.'/boot.php';
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

function qa_as2(string $uri, User $u): int
{
    $kernel = app(Kernel::class);
    Auth::guard('web')->login($u);
    $req = Request::create($uri, 'GET');
    $req->headers->set('Accept', 'text/html');
    $req->setLaravelSession(app('session.store'));
    try {
        return $kernel->handle($req)->getStatusCode();
    } catch (Throwable $e) {
        return 0;
    }
}

$screens = [
    'SPACING' => ['units', 'areas', 'unit-ownerships', 'occupancy-map', 'rent-roll', 'expiration-schedule', 'occupancy-cost'],
    'LEASING' => ['leases', 'tenant-sales-declarations', 'cam-expense-pools', 'billing-run-preview', 'sales-analytics'],
    'RECEIVABLES' => ['invoices', 'payments', 'credit-notes', 'deposit-transactions', 'post-dated-cheques', 'ar-aging', 'ar-collections'],
    'PAYABLES' => ['vendor-bills', 'vendors', 'expenses', 'purchase-requests', 'disbursements', 'weekly-spend', 'budget'],
];
$emails = ['manager', 'accounting', 'leasing', 'operations', 'marketing', 'hr', 'viewer'];
$users = [];
foreach ($emails as $e) {
    if ($u = User::where('email', "$e@mall.test")->first()) {
        $users[$e] = $u;
    }
}

foreach ($screens as $mod => $slugs) {
    qa_section("RBAC MATRIX · $mod   (200 = can open, 403 = refused)");
    printf("  %-26s %s\n", 'screen', collect(array_keys($users))->map(fn ($r) => str_pad(substr($r, 0, 6), 7))->join(''));
    foreach ($slugs as $slug) {
        $row = '';
        foreach ($users as $role => $u) {
            $c = qa_as2("/admin/AW/$slug", $u);
            $row .= str_pad($c === 200 ? 'yes' : ($c === 403 ? '-' : $c), 7);
        }
        printf("  %-26s %s\n", $slug, $row);
    }
}

qa_section('Reports are gated on ONE permission — who holds it?');
foreach ($users as $role => $u) {
    printf("  %-12s reports.view=%s\n", $role, $u->can('reports.view') ? 'YES' : 'no');
}
qa_ok('the leasing role can create and terminate leases',
    $users['leasing']->can('leases.create') && $users['leasing']->can('leases.terminate'));
// F-06 FIXED (f0f00844, 2026-08-19). These two were REPRODUCTIONS — they asserted the defect was
// still present, so closing the finding is what turned them red. Flipped to assert the fix, which
// is what a suite should say once the bug is gone; left as-is they train the reader to ignore a red.
qa_ok('…and CAN open the rent roll (reports.view)', $users['leasing']->can('reports.view'),
    'a leasing manager can see the rent roll, expiry schedule and occupancy cost');
qa_ok('the operations role runs work orders and procurement',
    $users['operations']->can('facility.view') && $users['operations']->can('procurement.create'));
qa_ok('…and CAN open the unit register (units.view)', $users['operations']->can('units.view'),
    'work orders route to units, so the role must be able to open the shop it dispatches to');

qa_summary();
