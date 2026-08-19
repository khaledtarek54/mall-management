<?php

require __DIR__.'/boot.php';
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Asset;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Search\SearchText;
use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$asset = Asset::where('code', 'AW')->firstOrFail();

qa_section('GLOBAL SEARCH — every searchable resource compiled AND EXECUTED on MySQL');
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant($asset, true);
Auth::login(User::where('email', 'admin@mall.test')->first());

$terms = ['a', 'INV', 'شركة', 'أحمد', 'A-01', '2026', 'CIB', "O'Brien", '%_', '01012345678'];
$resources = Filament::getPanel('admin')->getResources();
$searchable = collect($resources)->filter(fn ($r) => $r::canGloballySearch());
printf("  %d globally-searchable resources · %d terms\n", $searchable->count(), count($terms));

$failures = [];
foreach ($searchable as $resource) {
    foreach ($terms as $term) {
        try {
            $resource::getGlobalSearchResults($term);
        } catch (Throwable $e) {
            $failures[] = class_basename($resource).' ["'.$term.'"] → '.get_class($e).': '.mb_substr($e->getMessage(), 0, 150);
        }
    }
}
qa_eq('no searchable resource throws on MySQL', 0, count($failures));
foreach ($failures as $f) {
    echo "    $f\n";
}

qa_section('SEARCH — the Arabic fold matches both spellings');
$t = Tenant::whereNotNull('name')->first();
$hits = TenantResource::getGlobalSearchResults(mb_substr($t->name, 0, 4));
qa_ok('searching a real tenant name finds it', $hits->count() > 0, $t->name.' → '.$hits->count().' hits');
foreach ([['شركه', 'شركة'], ['احمد', 'أحمد']] as [$plain,$hamza]) {
    $a = SearchText::normalize($plain);
    $b = SearchText::normalize($hamza);
    qa_eq("normalize === fold('$hamza')", $a, $b);
}

qa_section('RBAC — every role can open the four modules without a crash');
$roleUrls = [
    'manager@mall.test' => ['leases', 'invoices', 'payments', 'vendor-bills', 'units', 'ar-aging'],
    'accounting@mall.test' => ['invoices', 'payments', 'credit-notes', 'vendor-bills', 'expenses', 'trial-balance', 'income-statement'],
    'leasing@mall.test' => ['leases', 'units', 'rent-roll', 'expiration-schedule'],
    'operations@mall.test' => ['units', 'purchase-requests', 'expenses'],
    'viewer@mall.test' => ['leases', 'invoices', 'units', 'ar-aging'],
];
function qa_as(string $uri, User $u): int
{
    $kernel = app(Kernel::class);
    Auth::guard('web')->login($u);
    $req = Request::create($uri, 'GET');
    $req->headers->set('Accept', 'text/html');
    $req->setLaravelSession(app('session.store'));
    try {
        return $kernel->handle($req)->getStatusCode();
    } catch (Throwable $e) {
        echo '      '.get_class($e).': '.mb_substr($e->getMessage(), 0, 120)."\n";

        return 0;
    }
}
foreach ($roleUrls as $email => $slugs) {
    $u = User::where('email', $email)->first();
    if (! $u) {
        echo "  (no user $email)\n";

        continue;
    }
    $roles = $u->getRoleNames()->join(',');
    $codes = [];
    foreach ($slugs as $slug) {
        $codes[$slug] = qa_as("/admin/AW/$slug", $u);
    }
    $bad = array_filter($codes, fn ($c) => ! in_array($c, [200, 403, 302], true));
    qa_ok("$email ($roles)", $bad === [], json_encode($codes));
}

qa_section('RBAC — a viewer cannot reach a create screen');
$viewer = User::where('email', 'viewer@mall.test')->first();
if ($viewer) {
    foreach (['invoices/create', 'payments/create', 'leases/create', 'vendor-bills/create'] as $slug) {
        $c = qa_as("/admin/AW/$slug", $viewer);
        qa_ok("viewer is refused /$slug", in_array($c, [403, 302], true), 'HTTP '.$c);
    }
}

qa_summary();
