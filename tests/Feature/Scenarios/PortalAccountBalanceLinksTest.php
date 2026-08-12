<?php

use App\Filament\Portal\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Portal\Widgets\AccountBalance;
use App\Support\ResourceLink;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Livewire;

/**
 * The tenant's dashboard figures link to the rows behind them.
 *
 * "EGP 48,000 outstanding" and "3 overdue" named a problem and left the tenant to find it by hand
 * in a register they may have a year of — the same defect the admin dashboard carried, on the
 * surface whose reader has the least patience for it.
 *
 * Checked the way the admin links are: not that a URL was produced, but that it RESOLVES — the
 * filter exists on the destination, the sort column is really sortable, and the list narrows.
 * A link that 200s on an unfiltered page is the failure mode, and it looks like success.
 */
beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('portal'));

    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $lease = makeLease(makeUnit($this->asset), $this->tenant, ['status' => 'active']);

    $this->overdue = makeInvoice($lease, [
        'status' => 'overdue', 'balance' => 5000, 'due_date' => now()->subDays(10),
    ]);
    $this->paid = makeInvoice($lease, [
        'status' => 'paid', 'balance' => 0, 'paid_amount' => 11400, 'due_date' => now()->subDays(40),
    ]);

    $this->actingAs(makeTenantUser($this->tenant), 'portal');
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

/** @return array<string, string> stat label => url */
function accountBalanceLinks(): array
{
    $stats = (new ReflectionMethod(AccountBalance::class, 'getStats'))->invoke(new AccountBalance);

    $links = [];

    foreach ($stats as $stat) {
        /** @var Stat $stat */
        if ($url = $stat->getUrl()) {
            $links[$stat->getLabel()] = $url;
        }
    }

    return $links;
}

it('links every figure a tenant might act on', function () {
    // Four: outstanding, overdue, active leases, lifetime paid. A figure with nowhere to go is
    // the thing this test exists to prevent.
    expect(accountBalanceLinks())->toHaveCount(4);
});

it('the overdue link lands on the overdue invoices, and excludes the settled one', function () {
    $url = accountBalanceLinks()[__('admin.widgets.account_balance.overdue_invoices')] ?? null;
    expect($url)->not->toBeNull();

    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    $ids = Livewire::withQueryParams($query)->test(ListInvoices::class)
        ->instance()->getTableRecords()->pluck('id')->all();

    // Present AND excluded: together these can only hold if the filter actually ran.
    expect($ids)->toContain($this->overdue->id)
        ->not->toContain($this->paid->id);
});

it('the outstanding link lands on everything still carrying a balance', function () {
    Livewire::withQueryParams([]);

    $url = accountBalanceLinks()[__('admin.widgets.account_balance.outstanding_balance')] ?? null;
    expect($url)->not->toBeNull();

    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

    $ids = Livewire::withQueryParams($query)->test(ListInvoices::class)
        ->instance()->getTableRecords()->pluck('id')->all();

    expect($ids)->toContain($this->overdue->id)
        ->not->toContain($this->paid->id);
});

it('emits only query keys Filament v4 actually binds', function () {
    foreach (accountBalanceLinks() as $label => $url) {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        foreach (array_keys($query) as $key) {
            $this->assertNotContains($key, ResourceLink::DEAD_KEYS, "{$label} uses the dead v3 key `{$key}`");
            $this->assertArrayHasKey($key, ResourceLink::QUERY_KEYS, "{$label} uses `{$key}`, which no list page reads");
        }
    }
});

it('sorts by a column that is really sortable', function () {
    // Filament drops a sort naming a non-sortable column, silently — the operator lands on a list
    // ordered by something other than the urgency they clicked.
    $table = Livewire::test(ListInvoices::class)->instance()->getTable();

    foreach (accountBalanceLinks() as $label => $url) {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        if (! isset($query['sort'])) {
            continue;
        }

        $column = str($query['sort'])->before(':')->toString();

        $this->assertNotNull(
            $table->getSortableVisibleColumn($column),
            "{$label} sorts by `{$column}`, which is not a sortable visible column",
        );
    }
});
