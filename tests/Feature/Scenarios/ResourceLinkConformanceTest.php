<?php

use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Filament\Admin\Widgets\ActionRequired;
use App\Filament\Admin\Widgets\EtaCompliance;
use App\Filament\Admin\Widgets\LeasingPipeline;
use App\Models\FacilityWorkOrder;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\LeaseOption;
use App\Models\PostDatedCheque;
use App\Models\TenantRequest;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorDocument;
use App\Support\ResourceLink;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Livewire;

/**
 * **The gate on "click the number, get the rows".**
 *
 * A dashboard card that says "9 overdue invoices" is a promise, and the whole of keeping it is
 * the query string. Filament v4 publishes the table component's state under aliases declared on
 * `ListRecords` (`#[Url(as: 'filters')] public ?array $tableFilters`), so `?filters[…]` binds
 * and `?tableFilters[…]` is ignored. Both give a valid URL and HTTP 200; the wrong one silently
 * lands the operator on the unfiltered list.
 *
 * Nine links shipped that way — the entire leasing pipeline, the whole compliance panel and the
 * option-window card — and the test that existed asserted the URL *string* for four other cards,
 * so it stayed green throughout. That is the failure this file is shaped around: a link can be
 * wrong in four different ways and every one of them looks like success from the outside.
 *
 *   1. the query KEY is inert         → lands unfiltered      (test A/B)
 *   2. the filter NAME doesn't exist  → lands unfiltered      (test C)
 *   3. the sort COLUMN isn't sortable → lands unsorted        (test D)
 *   4. the alias stops binding        → everything lands unfiltered at once (test E)
 *
 * Tests C and D resolve each link against the destination table itself rather than against a
 * hand-written list, so a filter renamed in a resource breaks the build here rather than
 * degrading a dashboard card into a no-op nobody notices.
 *
 * Replaces `tests/Feature/Widgets/ActionRequiredDeepLinksTest.php`, deleted in the same change.
 * That file hard-coded the expected URL substring for 4 of ActionRequired's 16 cards and knew
 * nothing of the other two widgets, so it could not fail for any of the nine broken links —
 * and two copies of "what a correct deep link looks like" is one copy too many. Its one unique
 * contribution, a real HTTP round-trip, is kept and strengthened here as test H.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    // A fixture rich enough that EVERY ActionRequired card surfaces.
    //
    // This matters more than it looks: a card only renders when its count is > 0, so a card
    // with no fixture emits no link and this gate silently never checks it. That is not
    // hypothetical — with a partial fixture only 5 of the 16 cards rendered, and deleting the
    // `->sortable()` from a work-order SLA column left the whole file green. Test F below
    // asserts the coverage itself, so the fixture cannot rot back into that state.
    $this->unit = makeUnit($this->asset, ['status' => 'vacant']);
    $this->tenant = makeTenant();
    $this->lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active',
        'commencement_date' => now()->subYear(),
        'expiry_date' => now()->addDays(15),   // expiring_critical (≤30d)
        'has_percentage_rent' => true,          // missing_sales
    ]);
    makeUnit($this->asset, ['status' => 'vacant']);                        // vacant

    // expiring_soon — a SECOND lease in the 31-90 day window.
    makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active',
        'commencement_date' => now()->subYear(),
        'expiry_date' => now()->addDays(60),
    ]);

    // holdover — active, past its end date, not yet converted to holdover billing.
    makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active',
        'commencement_date' => now()->subYears(2),
        'expiry_date' => now()->subDays(20),
    ]);

    // option_closing — an open option whose notice window shuts inside 90 days.
    LeaseOption::create([
        'lease_id' => $this->lease->id,
        'type' => 'renewal',
        'status' => 'open',
        'earliest_notice_date' => now()->subDays(10),
        'latest_notice_date' => now()->addDays(30),
        'term_months' => 12,
    ]);

    TenantRequest::create([
        'reference' => 'MR-'.uniqid(),
        'unit_id' => $this->unit->id,
        'tenant_id' => $this->tenant->id,
        'title' => 'Urgent', 'description' => 'Now',
        'status' => 'in_progress', 'priority' => 'urgent', 'category' => 'hvac',
        'submitted_at' => now()->subHours(6),
        'target_resolution_at' => now()->subHours(1),   // urgent_requests + sla_breached
    ]);

    // wo_sla_breached — a corrective job past its resolution deadline, still open.
    FacilityWorkOrder::create([
        'work_order_type' => FacilityWorkOrder::TYPE_CM,
        'execution_type' => FacilityWorkOrder::EXECUTION_INTERNAL,
        'asset_id' => $this->asset->id,
        'reference' => 'WO-'.uniqid(),
        'title' => 'Breached', 'category' => 'hvac',
        'description' => 'Past its resolution deadline.',
        'status' => 'in_progress', 'priority' => 'high',
        'scheduled_for' => now()->subDays(3),
        'acknowledged_at' => now()->subDays(2),
        'target_resolution_at' => now()->subHours(4),
    ]);

    // wo_response_breached — nobody has ACCEPTED it, so it has no resolution clock at all.
    // This is the card the partial fixture missed; without it the sort-column gate is blind.
    FacilityWorkOrder::create([
        'work_order_type' => FacilityWorkOrder::TYPE_CM,
        'execution_type' => FacilityWorkOrder::EXECUTION_INTERNAL,
        'asset_id' => $this->asset->id,
        'reference' => 'WO-'.uniqid(),
        'title' => 'Unanswered', 'category' => 'hvac',
        'description' => 'Nobody has accepted this job.',
        'status' => 'open', 'priority' => 'urgent',
        'scheduled_for' => now()->subDays(1),
        'acknowledged_at' => null,
        'target_response_at' => now()->subHours(6),
    ]);

    // vendor_documents + contract_notice — one vendor carries both, under an ACTIVE contract
    // on this property (the card scopes by engagement, not by the shared vendor catalogue).
    $vendor = Vendor::factory()->create(['status' => 'active']);
    VendorDocument::create([
        'vendor_id' => $vendor->id,
        'type' => 'insurance',
        'reference' => 'COI-1',
        'issued_on' => now()->subYear(),
        'expires_on' => now()->subDay(),
    ]);
    VendorContract::create([
        'vendor_id' => $vendor->id,
        'asset_id' => $this->asset->id,
        'reference' => 'VC-1',
        'name' => 'Cleaning',
        'status' => 'active',
        'start_date' => now()->subYear(),
        'end_date' => now()->addDays(30),
        'notice_period_days' => 60,   // → notice_deadline = end_date − 60d = 30 days ago
    ]);

    // matured_cheques — a held cheque whose date has passed and which has not cleared.
    PostDatedCheque::create([
        'reference' => 'PDC-'.uniqid(),
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id,
        'lease_id' => $this->lease->id,
        'cheque_number' => '000123',
        'bank_name' => 'CIB',
        'amount' => 5000,
        'cheque_date' => now()->subDays(3),
        'received_date' => now()->subMonth(),
        'status' => PostDatedCheque::STATUS_HELD,
    ]);

    // ledger_without_property — posted money in no property's books.
    JournalEntry::create([
        'number' => 'JE-'.uniqid(),
        'entry_date' => now(),
        'asset_id' => null,
        'status' => 'posted',
        'description' => 'Consolidated',
    ]);

    $this->overdueInvoice = makeInvoice($this->lease, [
        'balance' => 1000, 'status' => 'overdue', 'due_date' => now()->subDays(10),
    ]);

    // A control the `overdue_only` filter must EXCLUDE. Without it, test H below would pass on
    // a page that filtered nothing — every invoice would be present, including the one it looks for.
    $this->settledInvoice = makeInvoice($this->lease, [
        'balance' => 0, 'paid_amount' => 11400, 'status' => 'paid', 'due_date' => now()->addDays(20),
    ]);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
});

/**
 * Every deep link the dashboard emits, as (label, url) pairs.
 *
 * Driven off the widgets themselves, not a list written here — a new card is covered the day
 * it ships rather than the day someone remembers to add it.
 *
 * @return array<int, array{0: string, 1: string}>
 */
function dashboardLinks(): array
{
    $links = [];

    $items = (new ReflectionMethod(ActionRequired::class, 'getViewData'))
        ->invoke(new ActionRequired)['items'];

    foreach ($items as $item) {
        $links[] = ["ActionRequired[{$item['key']}]", $item['url']];
    }

    foreach ([LeasingPipeline::class, EtaCompliance::class] as $widgetClass) {
        $stats = (new ReflectionMethod($widgetClass, 'getStats'))->invoke(new $widgetClass);

        foreach ($stats as $i => $stat) {
            /** @var Stat $stat */
            if ($url = $stat->getUrl()) {
                $links[] = [class_basename($widgetClass)."[stat {$i}]", $url];
            }
        }
    }

    return $links;
}

/**
 * Resolve a link's path back to the Livewire list page that will receive it.
 *
 * @return array{0: class-string, 1: array<string, mixed>}|null
 */
function resolveLinkTarget(string $url): ?array
{
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $query);

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $indexPage = $resource::getPages()['index'] ?? null;

        if (! $indexPage) {
            continue;
        }

        if (rtrim(parse_url($resource::getUrl('index'), PHP_URL_PATH) ?? '', '/') === rtrim($parts['path'] ?? '', '/')) {
            return [$indexPage->getPage(), $query];
        }
    }

    return null;
}

it('A: no widget emits a dead Filament v3 query key', function () {
    asTenant($this->asset, function () {
        foreach (dashboardLinks() as [$label, $url]) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

            foreach (array_keys($query) as $key) {
                $this->assertNotContains(
                    $key,
                    ResourceLink::DEAD_KEYS,
                    "{$label} uses `{$key}`, which Filament v4 does not bind — the link lands unfiltered."
                );
                $this->assertArrayHasKey(
                    $key,
                    ResourceLink::QUERY_KEYS,
                    "{$label} uses `{$key}`, which is not a query-string alias any list page reads."
                );
            }
        }
    });
})->group('conformance');

it('F: the fixture surfaces EVERY ActionRequired card, so tests A/C/D see every link', function () {
    // The gate on the gate. Cards A/C/D only inspect links that were actually emitted, and a
    // card emits nothing when its count is zero — so an incomplete fixture does not fail, it
    // quietly checks less. Measured: with a partial fixture, 5 of 16 cards rendered and a
    // deliberately broken sort column passed unnoticed. Adding a card to CARD_PERMISSIONS now
    // fails here until its fixture exists.
    asTenant($this->asset, function () {
        $surfaced = array_map(
            fn (array $link) => str($link[0])->between('[', ']')->toString(),
            array_values(array_filter(dashboardLinks(), fn ($l) => str_starts_with($l[0], 'ActionRequired'))),
        );

        $declared = array_keys(
            (new ReflectionClass(ActionRequired::class))->getReflectionConstant('CARD_PERMISSIONS')->getValue()
        );

        $missing = array_values(array_diff($declared, $surfaced));

        $this->assertSame([], $missing, 'these ActionRequired cards never render under the test fixture, so their deep links are unchecked: '.implode(', ', $missing));
    });
})->group('conformance');

/**
 * Cards allowed to land on an unfiltered list, with the reason.
 *
 * Empty on purpose. Every card names a COUNT of things needing action, so a link that does not
 * narrow contradicts the number the operator just clicked — which is how `contract_notice` and
 * `missing_sales` shipped pointing at bare index pages, the second of them at a register that
 * by construction could not contain the missing rows. If a future card genuinely has nowhere
 * to narrow to, add it here with the reason rather than deleting the assertion.
 *
 * @var array<string, string>
 */
const UNFILTERED_CARDS_ALLOWED = [];

it('G: every dashboard card link actually narrows the destination list', function () {
    asTenant($this->asset, function () {
        $unnarrowed = [];

        foreach (dashboardLinks() as [$label, $url]) {
            $key = str($label)->between('[', ']')->toString();

            if (array_key_exists($key, UNFILTERED_CARDS_ALLOWED)) {
                continue;
            }

            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

            // `sort` alone is not narrowing — it reorders the same full list.
            if (! isset($query['filters']) && ! isset($query['tab'])) {
                $unnarrowed[] = $label;
            }
        }

        $this->assertSame([], $unnarrowed, 'these links land on an unfiltered list, contradicting the count that was clicked: '.implode(', ', $unnarrowed));
    });
})->group('conformance');

it('B: nothing under app/Filament builds an index link with a raw filters/sort array', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        // ResourceLink is the one place allowed to name these keys.
        if (str_contains($source, 'namespace App\Support')) {
            continue;
        }

        if (preg_match("/getUrl\(\s*'index'\s*,\s*\[[^\]]*'(filters|sort|search|tab)'\s*=>/s", $source)) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
        }

        foreach (ResourceLink::DEAD_KEYS as $dead) {
            if (str_contains($source, "'{$dead}' =>")) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname())." (dead key `{$dead}`)";
            }
        }
    }

    $this->assertSame([], $offenders, "Build index deep links with App\\Support\\ResourceLink so the query-string alias cannot be got wrong:\n  ".implode("\n  ", $offenders));
})->group('conformance');

it('C: every filter a dashboard link applies exists on the destination table', function () {
    $checked = 0;

    asTenant($this->asset, function () use (&$checked) {
        foreach (dashboardLinks() as [$label, $url]) {
            [$page, $query] = resolveLinkTarget($url) ?? [null, []];

            if (! $page || ! isset($query['filters'])) {
                continue;
            }

            $table = Livewire::test($page)->instance()->getTable();

            // withHidden: TRUE. A filter that a disabled module hides still EXISTS, and the
            // link naming it is still correct — whether the module is on is a different
            // question, asked elsewhere. Resolving against visible filters only would make
            // this gate's coverage silently depend on the settings row in the test database.
            $filters = $table->getFilters(withHidden: true);

            foreach (array_keys($query['filters']) as $filterName) {
                $this->assertArrayHasKey(
                    $filterName,
                    $filters,
                    "{$label} filters on `{$filterName}`, which does not exist on ".class_basename($page).
                    ' — the link lands unfiltered.'
                );
                $checked++;
            }
        }
    });

    // A gate that checked nothing would pass; say so out loud.
    $this->assertGreaterThan(10, $checked, 'the gate resolved almost nothing — the fixture stopped surfacing cards');
})->group('conformance');

it('D: every column a dashboard link sorts by exists AND is sortable', function () {
    $checked = 0;

    asTenant($this->asset, function () use (&$checked) {
        foreach (dashboardLinks() as [$label, $url]) {
            [$page, $query] = resolveLinkTarget($url) ?? [null, []];

            if (! $page || ! isset($query['sort'])) {
                continue;
            }

            $column = str($query['sort'])->before(':')->toString();
            $table = Livewire::test($page)->instance()->getTable();

            // Filament resolves a URL sort through getSortableVisibleColumn(), which returns
            // null for a column that is hidden or was never marked ->sortable(). It then
            // falls back to the table's default sort WITHOUT complaining, so the operator
            // arrives on a list ordered by something other than the urgency they clicked.
            $this->assertNotNull(
                $table->getSortableVisibleColumn($column),
                "{$label} sorts by `{$column}`, which is not a sortable visible column on ".
                class_basename($page).' — Filament drops the sort silently.'
            );
            $checked++;
        }
    });

    $this->assertGreaterThan(5, $checked, 'the gate resolved almost nothing — the fixture stopped surfacing cards');
})->group('conformance');

/**
 * The upstream behaviour every link in this file depends on, pinned in three parts.
 *
 * **These MUST stay three separate tests.** `Livewire::withQueryParams()` writes to a manager
 * that is not reset between `->test()` calls, so a second call in the same test inherits the
 * first one's parameters — including a bare `Livewire::test()`, which then silently sees the
 * previous test case's query string. Written as one sequential test this reports the exact
 * OPPOSITE of the truth: the v3 key appears to filter correctly, because the v4 key set two
 * lines earlier is still in effect. That is how it was first written, and it is why the
 * assertion below reads as tautological until you try to merge them.
 */
function leaseIdsWithQuery(?array $params): array
{
    $page = ListLeases::class;

    $test = $params === null
        ? Livewire::test($page)
        : Livewire::withQueryParams($params)->test($page);

    return $test->instance()->getTableRecords()->pluck('id')->sort()->values()->all();
}

it('E1: with no query string, the list shows every lease', function () {
    $draft = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'draft']);

    asTenant($this->asset, function () use ($draft) {
        expect(leaseIdsWithQuery(null))
            ->toContain($draft->id)
            ->toContain($this->lease->id);
    });
})->group('conformance');

it('E2: the `filters` alias narrows the list', function () {
    $draft = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'draft']);

    asTenant($this->asset, function () use ($draft) {
        expect(leaseIdsWithQuery(['filters' => ['status' => ['value' => 'draft']]]))
            ->toEqual([$draft->id], 'the `filters` alias no longer narrows the table — every dashboard link is now a no-op');
    });
})->group('conformance');

it('E3: the v3 property name does NOT narrow the list', function () {
    $draft = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'draft']);

    asTenant($this->asset, function () {
        // If this ever starts narrowing, Filament began binding the property name too and
        // DEAD_KEYS is wrong — which would make test B reject links that actually work.
        $this->assertContains(
            $this->lease->id,
            leaseIdsWithQuery(['tableFilters' => ['status' => ['value' => 'draft']]]),
            'the v3 property name started binding — DEAD_KEYS needs revisiting',
        );
    });
})->group('conformance');

it('H: the deep link survives a real HTTP request, and the destination page really is filtered', function () {
    // Every other test here drives the Livewire component directly. This one goes through the
    // kernel — route, middleware, tenancy, Livewire's query-string hydration — because that is
    // the path the operator's click actually takes, and it is where an alias mismatch shows up.
    //
    // Asserting the target invoice is PRESENT would pass on a completely unfiltered page, which
    // is the exact failure being guarded against. So it also asserts the settled invoice is
    // ABSENT: present-and-excluded together can only be true if the filter ran.
    asTenant($this->asset, function () {
        $card = collect(dashboardLinks())
            ->first(fn (array $link) => $link[0] === 'ActionRequired[overdue]');

        expect($card)->not->toBeNull('the overdue card stopped rendering — the fixture no longer covers it');

        $parts = parse_url($card[1]);
        $response = $this->get($parts['path'].'?'.($parts['query'] ?? ''));

        $response->assertOk();
        $response->assertSee($this->overdueInvoice->number);
        $response->assertDontSee($this->settledInvoice->number);
    });
})->group('conformance');

/**
 * Filter persistence and deep links have to coexist, and the order matters.
 *
 * `App\Support\TableDefaults` turns on `persistFiltersInSession()` for EVERY table in both
 * panels, so an operator does not lose a filter each time they open a record and come back.
 * That stores the last filter set — which would be a real bug if it then overrode a dashboard
 * card: the card says "9 overdue" and the page opens on last week's saved filter instead.
 *
 * Nothing here asserts the setting exists table-by-table; it is a panel-wide default, and the
 * point of these two tests is the INTERACTION, which no amount of reading the config shows.
 *
 * It does not, because `bootedInteractsWithTable()` only reads the session when the URL carried
 * NO filters.
 *
 * **Both tests explicitly reset the testing query params first.** `Livewire::withQueryParams()`
 * writes to the manager and is never cleared — not between `->test()` calls, and not between
 * TEST METHODS in the same process. Without the reset, test I inherited `filters[status]=draft`
 * from test E2 and passed with `persistFiltersInSession()` deleted: a green test measuring
 * another test's leftovers. They also filter on `pending_approval`, a value no other test in
 * this file uses, so a future bleed cannot silently reproduce the same false pass.
 */
it('I: a filter survives coming back to the list with no query string', function () {
    Livewire::withQueryParams([]);

    $pending = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'pending_approval']);
    $page = ListLeases::class;

    asTenant($this->asset, function () use ($pending, $page) {
        // Apply a filter the way the operator does.
        Livewire::test($page)
            ->set('tableFilters.status.value', 'pending_approval')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$this->lease]);

        // Then come back to a bare URL, as Filament's own back-link does.
        expect(leaseIdsWithQuery(null))
            ->toEqual([$pending->id], 'the filter was lost on return — persistFiltersInSession is not in effect');
    });
})->group('conformance');

it('J: a dashboard deep link still wins over a stored filter', function () {
    Livewire::withQueryParams([]);

    $pending = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'pending_approval']);
    $page = ListLeases::class;

    asTenant($this->asset, function () use ($pending, $page) {
        // Stale session state: the operator last looked at leases awaiting approval.
        Livewire::test($page)
            ->set('tableFilters.status.value', 'pending_approval')
            ->assertCanNotSeeTableRecords([$this->lease]);

        // The card says "active" and must land on THAT, not on the stored filter.
        $ids = leaseIdsWithQuery(['filters' => ['status' => ['value' => 'active']]]);

        expect($ids)->toContain($this->lease->id)
            ->not->toContain($pending->id);
    });
})->group('conformance');
