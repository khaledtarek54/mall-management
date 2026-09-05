<?php

use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Models\AccountingPeriod;
use App\Models\Announcement;
use App\Models\FiscalYear;
use App\Models\OwnerStatementRun;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * A VIEW PAGE'S TABS DO NOT WRITE.
 *
 * Filament already says this: every relation manager under a `ViewRecord` is read-only, and its
 * Create/Edit/Delete are denied before their own gates are consulted
 * (`RelationManager::getDefaultActionAuthorizationResponse()`). Three affordances escaped it, and
 * each escaped in a different way, which is why one assertion could not have caught them all:
 *
 *  1. `TenantNotesRelationManager` waived the rule outright with `isReadOnly(): false`.
 *  2. `TenantPaymentsRelationManager` offered *Record payment* — a LINK to `PaymentResource`'s
 *     create page. A link is not a `CreateAction`, so the default has nothing to deny.
 *  3. `TenantViolationsRelationManager` offered *Record violation*, the same shape.
 *
 * So the gate has a tooth per escape route, and the two teeth are scoped differently on purpose:
 *
 * - **No relation manager under a `ViewRecord` waives the read-only rule.** A View-page question.
 * - **No relation manager on ANY record page links into a `.create` route.** Not a View-page
 *   question at all: a receipt and a violation are their own documents, and the act that raises
 *   one belongs to the RECORD's header, by permission, on every page — Yardi's shape and this
 *   repo's own reading of it (`docs/benchmarks/yardi/08`). Scoping this tooth to View pages would
 *   have left the same button on the Edit page's tab, which is the drift the record hub exists to
 *   prevent.
 *
 * A tab's OWN `CreateAction` is untouched by either — adding a portal user or a document to this
 * tenant is that relationship's own row, and belongs in its tab.
 *
 * The route name is resolved through the ROUTER rather than by pattern-matching the string,
 * because "/create" is a substring of plenty of legitimate paths and a resource could be named
 * for it.
 *
 * The control that stops all this passing for the wrong reason is that the acts must still be
 * REACHABLE — see the header tests below and `FrontDeskCanLogTheCallItTookTest`. A change that
 * simply deleted the three buttons satisfies every refusal here.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
});

/**
 * An owner record for each resource that has a View page WITH tabs.
 *
 * Keyed by resource and asserted exhaustive by the sweeps below: a fourth such resource fails the
 * gate with "no fixture" rather than being skipped, because a sweep that quietly reports on two of
 * three is the shape this codebase has been bitten by repeatedly.
 *
 * @return array<class-string, \Illuminate\Database\Eloquent\Model>
 */
function viewPageOwnerRecords($asset): array
{
    $year = FiscalYear::create([
        'year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open',
    ]);
    $period = AccountingPeriod::create([
        'fiscal_year_id' => $year->id, 'period_no' => 6,
        'starts_on' => '2026-06-01', 'ends_on' => '2026-06-30', 'status' => 'open',
    ]);

    return [
        \App\Filament\Admin\Resources\Tenants\TenantResource::class => makeTenant(),
        \App\Filament\Admin\Resources\Announcements\AnnouncementResource::class => Announcement::create([
            'asset_id' => $asset->id, 'title' => 'Lift maintenance', 'body' => 'Sunday 06:00-10:00',
            'audience' => 'all', 'status' => 'sent', 'sent_at' => now(),
        ]),
        // The portal's request thread — read by a retailer's staff, and swept for the same reason.
        \App\Filament\Portal\Resources\TenantRequests\TenantRequestResource::class => makeTenantRequest(),
        \App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource::class => OwnerStatementRun::create([
            'accounting_period_id' => $period->id, 'posting_date' => '2026-06-30', 'reference' => 'OSR-1',
            'asset_id' => $asset->id, 'basis' => 'accrual', 'period_start' => '2026-06-01',
            'period_end' => '2026-06-30', 'status' => 'draft',
        ]),
    ];
}

/**
 * The ROUTE NAME an action's URL resolves to, or null when it resolves to none.
 *
 * Through the router, never by matching "/create" in the string: that substring appears in
 * legitimate paths and a resource could be named for it. `RouteCollection::match()` THROWS on a
 * miss rather than returning null (`handleMatchedRoute()` raises `NotFoundHttpException`), so the
 * `?->` that looks like the guard here is dead code — this is the guard.
 */
function routeNameOf(?string $url): ?string
{
    if (! is_string($url) || $url === '') {
        return null;
    }

    try {
        return Route::getRoutes()->match(Illuminate\Http\Request::create($url, 'GET'))?->getName();
    } catch (Throwable) {
        return null;
    }
}

/** The names of the header actions a mounted record page actually offers. */
function headerActionNames($component): array
{
    return collect($component->instance()->getCachedHeaderActions())
        ->filter(fn ($action) => $action->isVisible())
        ->map(fn ($action) => $action->getName())
        ->values()
        ->all();
}

/** Every (resource, View page, relation manager) triple in every panel. */
function viewPageRelationManagers(): array
{
    $found = [];
    $unreadable = [];

    // EVERY panel, not just admin. The portal already has a View page with a relation manager
    // (`ViewTenantRequest`), read by a retailer's staff — and a gate scoped to one panel while its
    // title speaks of all of them is the shape recorded in CLAUDE.md for the Arabic-chrome sweep.
    foreach (Filament::getPanels() as $panel) {
        foreach ($panel->getResources() as $resource) {
            foreach ($resource::getPages() as $registration) {
                $page = $registration->getPage();

                if (! is_subclass_of($page, ViewRecord::class)) {
                    continue;
                }

                foreach ($resource::getRelations() as $relation) {
                    if (is_string($relation) && is_subclass_of($relation, RelationManager::class)) {
                        $found[] = [$resource, $page, $relation];

                        continue;
                    }

                    // A `RelationGroup` or a `RelationManager::make(…)` configuration is not a
                    // class string. None exists today; SKIPPING one silently is how a sweep comes
                    // to report on less than it claims, so it fails instead.
                    $unreadable[] = $resource.' → '.(is_object($relation) ? $relation::class : gettype($relation));
                }
            }
        }
    }

    // (No message argument: a Pest matcher's second parameter is an expected VALUE on several
    // matchers, which is the false-assertion trap this very file was bitten by.)
    expect($unreadable)->toBe([]);

    return $found;
}

it('never waives the read-only rule on a relation manager under a view page', function () {
    $triples = viewPageRelationManagers();

    // The premise: a sweep that collected nothing reports "no offenders" just as happily.
    expect($triples)->not->toBeEmpty();

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    $owners = viewPageOwnerRecords($this->asset);

    $waived = [];
    $swept = 0;

    asTenant($this->asset, function () use ($triples, $owners, &$waived, &$swept) {
        foreach ($triples as [$resource, $page, $relation]) {
            // A fourth resource growing a View page with tabs FAILS here rather than being
            // skipped. (`toHaveKey($key, $value)` compares the VALUE, it is not a message —
            // that trap is on record in CLAUDE.md and it bit this very line.)
            expect($owners)->toHaveKey($resource);

            $manager = Livewire::test($relation, ['ownerRecord' => $owners[$resource], 'pageClass' => $page])->instance();
            $swept++;

            if (! $manager->isReadOnly()) {
                $waived[] = class_basename($relation);
            }
        }
    });

    expect($waived)->toBe([])
        ->and($swept)->toBe(count($triples));
});

it('renders no link into a create form from a view page tab', function () {
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    $owners = viewPageOwnerRecords($this->asset);
    $triples = viewPageRelationManagers();

    // The premise. Test 1 asserts it too, and asserting it only there is the trap: if discovery
    // ever returns nothing, THAT test goes red and gets read as the one failure while this one
    // passes reporting on an empty set.
    expect($triples)->not->toBeEmpty();

    [$offenders, $examined] = asTenant($this->asset, function () use ($owners, $triples): array {
        $found = [];
        $examined = 0;

        foreach ($triples as [$resource, $page, $relation]) {
            expect($owners)->toHaveKey($resource);

            $manager = Livewire::test($relation, ['ownerRecord' => $owners[$resource], 'pageClass' => $page])->instance();
            $table = $manager->getTable();
            $record = $manager->getTableRecords()->first();

            // ROW actions as well as header and toolbar ones. Sweeping only the strip the three
            // known offenders happened to sit in would make this a test about those three; a row
            // action is where an `open` link lives, and the difference between the two is the rule
            // this file exists to state.
            $strips = [
                ...$table->getHeaderActions(),
                ...$table->getToolbarActions(),
                ...($record ? array_map(fn ($a) => $a->getClone()->record($record), $table->getRecordActions()) : []),
            ];

            foreach ($strips as $action) {
                $examined++;

                if (! $action->isVisible()) {
                    continue;
                }

                if (routeNameOf($action->getUrl()) === null) {
                    continue;
                }

                // A `.create` route is the tab ADDING to itself, which is what was reported. An
                // `.edit`/`.view` route is NAVIGATION to an existing record with gates of its own —
                // the requests, sales and violations tabs each carry one and they stay.
                if (str_ends_with(routeNameOf($action->getUrl()), '.create')) {
                    $found[] = class_basename($relation).' -> '.$action->getName();
                }
            }
        }

        return [$found, $examined];
    });

    expect($offenders)->toBe([])
        // …and it read some strips. Counted BEFORE the visibility filter on purpose: on a
        // read-only tab the right answer is that nothing is visible, so counting survivors would
        // be a premise that goes to zero exactly when the feature works.
        ->and($examined)->toBeGreaterThan(0);
});

it('still lets a view page tab LINK to a record, which is navigation and not a write', function () {
    // The control for the rule above, and the reason it is drawn where it is. Without it, refusing
    // every link would pass both sweeps and would strand the reader on a tab of rows they cannot
    // open — the tenant hub's requests, sales and violations tabs each carry exactly such a link.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    // In THIS property: the relation manager scopes by it, so a request built in a fresh asset
    // (which is what `makeTenantRequest()` does) yields an empty table and the sweep below would
    // report "no offending links" having examined none.
    $tenant = makeTenant();
    \App\Models\TenantRequest::create([
        'reference' => 'MR-'.uniqid(), 'unit_id' => makeUnit($this->asset)->id, 'tenant_id' => $tenant->id,
        'title' => 'Lift stuck', 'description' => 'North lift stopped between 2 and 3.',
        'status' => 'submitted', 'priority' => 'medium', 'category' => 'electrical', 'submitted_at' => now(),
    ]);

    $urls = asTenant($this->asset, function () use ($tenant): array {
        $manager = Livewire::test(\App\Filament\Admin\RelationManagers\TenantRequestsRelationManager::class, [
            'ownerRecord' => $tenant,
            'pageClass' => \App\Filament\Admin\Resources\Tenants\Pages\ViewTenant::class,
        ])->instance();

        $record = $manager->getTableRecords()->first();

        return collect($manager->getTable()->getRecordActions())
            ->map(fn ($action) => $action->getClone()->record($record))
            ->filter(fn ($action) => $action->isVisible())
            ->map(fn ($action) => routeNameOf($action->getUrl()))
            ->all();
    });

    expect($urls)->not->toBeEmpty()
        ->and(collect($urls)->every(fn (?string $name) => is_string($name) && ! str_ends_with($name, '.create')))->toBeTrue();
});

it('offers the SAME acts on the view page and the edit page, by permission alone', function () {
    // THE CONTROL, and the rule itself. Every refusal above is satisfied by a change that simply
    // deleted the three buttons; this is what says they still exist, and that which page you opened
    // does not decide what you may do — Yardi's shape (docs/benchmarks/yardi/08), where one customer
    // screen's buttons are governed by function access.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    $tenant = makeTenant();

    [$onView, $onEdit] = asTenant($this->asset, fn (): array => [
        headerActionNames(Livewire::test(ViewTenant::class, ['record' => $tenant->getKey()])),
        headerActionNames(Livewire::test(EditTenant::class, ['record' => $tenant->getKey()])),
    ]);

    $acts = ['logCommunication', 'recordPayment', 'recordViolation'];

    expect($onView)->toContain(...$acts)
        ->and($onEdit)->toContain(...$acts)
        // The Edit page keeps what only it has, so "identical" is about the ACTS and not about the
        // whole strip: editing and deleting are still page-shaped questions.
        ->and($onEdit)->toContain('delete')
        ->and($onView)->not->toContain('delete');
});

it('keeps the notes tab writable on the edit page', function () {
    // The other half: the tab went back to Filament's default, which means read-only under a
    // ViewRecord and writable everywhere else. A change that made the manager read-only outright
    // would satisfy every refusal above and would break the ordinary way notes are managed.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    $tenant = makeTenant();

    $visible = asTenant($this->asset, function () use ($tenant): array {
        $manager = Livewire::test(\App\Filament\Admin\RelationManagers\TenantNotesRelationManager::class, [
            'ownerRecord' => $tenant,
            'pageClass' => EditTenant::class,
        ])->instance();

        expect($manager->isReadOnly())->toBeFalse();

        return collect($manager->getTable()->getHeaderActions())
            ->filter(fn ($action) => $action->isVisible())
            ->map(fn ($action) => $action->getName())
            ->all();
    });

    expect($visible)->toContain('create');
});

it('registers every tab that still links into a create form, with a reason', function () {
    // The behavioural sweep above can only reach a tab it has an owner-record fixture for, i.e. the
    // four resources with a View page. This one reads all 64 relation managers on disk, so the rule
    // is visible everywhere it could apply rather than only where a fixture happens to exist.
    //
    // Two are registered rather than fixed, and the reason is that the rule's JUSTIFICATION does
    // not reach them: `LeaseResource` has no View page, so there is no read-only surface for the
    // link to escape onto. Moving them would still be the consistent thing — an act belongs to the
    // record's header — but `EditLease`'s header is GROUPED, and CLAUDE.md records what happens to
    // an act added to a grouped header without its entry in the group map: it is defined and
    // rendered NOWHERE, passing every visibility and authorisation check while never appearing.
    // That is a change worth doing deliberately, not as a rider on this one.
    $registered = [
        'app/Filament/Admin/RelationManagers/LeaseInvoicesRelationManager.php' =>
            'LeaseResource has no View page, so no read-only surface; EditLease has a grouped header and moving this needs the group map.',
        'app/Filament/Admin/RelationManagers/LeaseSalesDeclarationsRelationManager.php' =>
            'Same as LeaseInvoicesRelationManager.',
    ];

    $managers = collect(glob(app_path('Filament/*/RelationManagers/*.php')))
        ->merge(glob(app_path('Filament/*/Resources/*/RelationManagers/*.php')))
        ->values();

    // The premise: a sweep that found no relation managers reports "none offend" just as happily.
    expect($managers->count())->toBeGreaterThan(50);

    $offenders = $managers
        ->filter(function (string $path): bool {
            // Comments stripped: this file's own explanations name the call, and a gate that fires
            // on a sentence is one that gets weakened rather than fixed.
            $code = collect(token_get_all(file_get_contents($path)))
                ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
                ->map(fn ($token) => is_array($token) ? $token[1] : $token)
                ->implode('');

            return str_contains($code, 'ResourceLink::create(');
        })
        ->map(fn (string $path): string => str_replace(base_path().'/', '', $path))
        ->reject(fn (string $path): bool => array_key_exists($path, $registered))
        ->values()
        ->all();

    expect($offenders)->toBe([])
        // …and a registered entry that has since been fixed must be removed, or the list becomes a
        // record of what used to be true.
        ->and(collect($registered)->keys()->reject(fn (string $p) => $managers->contains(base_path($p)))->all())->toBe([]);
});
