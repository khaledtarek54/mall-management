<?php

use App\Filament\Actions\OpenRecordAction;
use App\Filament\Admin\Pages\NotificationCenter;
use App\Filament\Admin\RelationManagers\LeaseInvoicesRelationManager;
use App\Filament\Admin\RelationManagers\TenantInvoicesRelationManager;
use App\Filament\Admin\RelationManagers\TenantViolationsRelationManager;
use App\Filament\Admin\RelationManagers\UnitEncumbrancesRelationManager;
use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\Units\Pages\EditUnit;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Models\DepositTransaction;
use App\Models\LeaseOption;
use App\Models\Violation;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Notifications\Notification;
use Livewire\Livewire;

/**
 * A TAB'S *OPEN* BUTTON IS ONE ACT, AND IT GOES WHERE CLICKING THE ROW GOES.
 *
 * Measured 2026-09-11: twelve relation managers each hand-wrote an `open` row action, in four
 * shapes. `LeaseInvoicesRelationManager`'s had NO visibility gate — a viewer holding `invoices.view`
 * and not `invoices.edit` was offered a link straight into the Edit page's 403. Six gated on
 * `canEdit` alone. Four resolved the row's own property through `PropertyLink`; the rest let
 * `getUrl()` read the switcher, which on a tab spanning malls is a 404 off a row on screen.
 *
 * `App\Filament\Actions\OpenRecordAction` is the one definition, and `RowClickTarget` now reads it
 * as the row's own destination on a tab — so the button and the click cannot disagree.
 *
 * Teeth: the viewer case (the ungated copy offered the link), the cross-property case (the
 * switcher-reading copies built a wrong-mall URL), and the row-click case (a new property that no
 * copy had). The editor case is the control — every copy already answered it.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant, ['status' => 'active']);
    $this->invoice = makeInvoice($this->lease, ['status' => 'issued']);
});

it('offers an editor the invoice from the lease tab, and takes the row click there too', function () {
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    asTenant($this->asset, function (): void {
        $tab = Livewire::test(LeaseInvoicesRelationManager::class, [
            'ownerRecord' => $this->lease, 'pageClass' => EditLease::class,
        ]);

        $tab->assertTableActionVisible(OpenRecordAction::NAME, $this->invoice);

        $expected = InvoiceResource::getUrl('edit', ['record' => $this->invoice]);

        expect($tab->instance()->getTable()->getAction(OpenRecordAction::NAME)->record($this->invoice)->getUrl())->toBe($expected)
            // The row click resolves the same act — `RowClickTarget` reads `open` on a tab.
            ->and($tab->instance()->getTable()->getRecordUrl($this->invoice))->toBe($expected);
    });
});

it('offers a viewer NO open button where the resource has no view page — never a link into a 403', function () {
    // `viewer` holds every `.view` and no `.edit`; invoices has no View page, so there is nowhere
    // for a viewer to land. The lease tab's own copy offered the link anyway.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));

    asTenant($this->asset, function (): void {
        Livewire::test(LeaseInvoicesRelationManager::class, [
            'ownerRecord' => $this->lease, 'pageClass' => EditLease::class,
        ])
            ->assertCanSeeTableRecords([$this->invoice])
            ->assertTableActionHidden(OpenRecordAction::NAME, $this->invoice);
    });
});

it('falls back to the VIEW page for a viewer where the resource has one', function () {
    // The `RowClickTarget` rule carried onto the button: a reader who may not edit still opens
    // the record where a View page exists. Tenants has one.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));

    $url = asTenant($this->asset, fn (): ?string => OpenRecordAction::urlFor(TenantResource::class, $this->tenant));

    expect($url)->toBe(asTenant($this->asset, fn () => TenantResource::getUrl('view', ['record' => $this->tenant])));
});

/** A violation filed in a SECOND mall against the same tenant — the row a spanning tab shows. */
function violationInAnotherMall($tenant): array
{
    $other = makeAsset(['code' => 'OT', 'name' => 'Other Mall']);
    $otherLease = makeLease(makeUnit($other), $tenant, ['status' => 'active']);

    $violation = Violation::create([
        'asset_id' => $other->id,
        'tenant_id' => $tenant->id,
        'lease_id' => $otherLease->id,
        'category' => 'other',
        'description' => 'Signage without approval',
        'violation_date' => now()->toDateString(),
        'status' => Violation::STATUS_OPEN,
        'fine_amount' => 500,
    ]);

    return [$other, $violation];
}

it('links a row from ANOTHER mall into THAT mall, never the selected one', function () {
    // A tenant's violations tab spans every mall they trade in. A copy reading the switcher built
    // `/admin/{this mall}/violations/{id}/edit` for a row filed in the other one — a 404.
    [$other, $violation] = violationInAnotherMall($this->tenant);

    // Holding BOTH malls: the link names the OTHER mall's slug, not the selected one.
    $this->actingAs(makeUser('manager', [$this->asset->id, $other->id]));

    $url = asTenant($this->asset, fn (): ?string => OpenRecordAction::urlFor(ViolationResource::class, $violation));

    expect($url)->toBe(asTenant($this->asset, fn () => ViolationResource::getUrl('edit', ['record' => $violation], tenant: $other)))
        ->and($url)->not->toContain('/'.$this->asset->code.'/');
});

it('offers no link at all into a mall the reader cannot enter', function () {
    // One actor per test — a second `actingAs()` in one case answers from the first actor's
    // memoised assignments, the trap CLAUDE.md records for the role matrix.
    [, $violation] = violationInAnotherMall($this->tenant);

    $this->actingAs(makeUser('manager', [$this->asset->id]));

    asTenant($this->asset, function () use ($violation): void {
        // The tab itself is property-scoped (`PropertyScope`), so the row never reaches this
        // reader — and the factory refuses on its own account for any tab that is not: a link
        // into a mall the reader cannot enter is a dead end wearing the look of a control.
        Livewire::test(TenantViolationsRelationManager::class, [
            'ownerRecord' => $this->tenant, 'pageClass' => EditTenant::class,
        ])->assertCanNotSeeTableRecords([$violation]);

        expect(OpenRecordAction::urlFor(ViolationResource::class, $violation))->toBeNull();
    });
});

it('opens the record BEHIND the row where the tab lists something else', function () {
    // The unit's encumbrances tab lists lease OPTIONS and opens the LEASE each one belongs to.
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    // An EXPANSION option on this lease over the shop next door: it encumbers THAT unit, whose
    // tab lists the option and opens the lease holding it.
    $nextDoor = makeUnit($this->asset, ['code' => 'S-02']);

    $option = LeaseOption::create([
        'lease_id' => $this->lease->id,
        'unit_id' => $nextDoor->id,
        'type' => 'expansion',
        'status' => 'open',
        'earliest_notice_date' => now()->addMonths(6)->toDateString(),
        'latest_notice_date' => now()->addMonths(9)->toDateString(),
    ]);

    asTenant($this->asset, function () use ($option, $nextDoor): void {
        $tab = Livewire::test(UnitEncumbrancesRelationManager::class, [
            'ownerRecord' => $nextDoor, 'pageClass' => EditUnit::class,
        ]);

        $tab->assertTableActionVisible(OpenRecordAction::NAME, $option);

        expect($tab->instance()->getTable()->getAction(OpenRecordAction::NAME)->record($option)->getUrl())
            ->toBe(LeaseResource::getUrl('edit', ['record' => $this->lease]));
    });
});

it('keeps the tenant invoices tab on the same act as the lease tab', function () {
    // Two tabs, one record type, one factory — the rendered act on each is the same name with the
    // same destination, which is the whole of what "reused, not duplicated" means here.
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $urls = asTenant($this->asset, fn (): array => [
        Livewire::test(LeaseInvoicesRelationManager::class, ['ownerRecord' => $this->lease, 'pageClass' => EditLease::class])
            ->instance()->getTable()->getAction(OpenRecordAction::NAME)->record($this->invoice)->getUrl(),
        Livewire::test(TenantInvoicesRelationManager::class, ['ownerRecord' => $this->tenant, 'pageClass' => EditTenant::class])
            ->instance()->getTable()->getAction(OpenRecordAction::NAME)->record($this->invoice)->getUrl(),
    ]);

    expect($urls[0])->toBe($urls[1])->and($urls[0])->not->toBeNull();
});

it('leaves a PAGE that declares its own open alone — the notification centre row still opens the alert', function () {
    // The review's finding: `open` read by NAME on every table turned the notification centre's
    // row (a page, `recordAction('details')`) into a navigation to the deep link, skipping the
    // read mark. The fallback is for a relation manager's table only.
    $this->actingAs($user = makeUser('manager', [$this->asset->id]));

    $user->notify(new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        public function toArray(object $notifiable): array
        {
            return ['title' => 'An alert', 'body' => 'With a deep link', 'actions' => [['label' => 'Open', 'url' => 'https://example.test/somewhere']]];
        }
    });

    $notification = $user->notifications()->firstOrFail();

    asTenant($this->asset, function () use ($notification): void {
        $table = Livewire::test(NotificationCenter::class)->instance()->getTable();

        // The button carries the link; the row does not follow it.
        expect($table->getAction(OpenRecordAction::NAME)->record($notification)->getUrl())->toBe('https://example.test/somewhere')
            ->and($table->getRecordUrl($notification))->toBeNull();
    });
});

it('still links a portfolio-wide row that has no property of its own', function () {
    // `DepositTransaction` is `portfolioRowsWhenNull`: a movement filed against no mall is shown
    // under every mall, and the copies this factory replaced linked it. The review measured the
    // factory going silent on it — `PropertyLink` answers null for "no property" and for "cannot
    // enter" alike, and only the second is a reason to offer nothing.
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $deposit = DepositTransaction::query()->create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->tenant->id,
        'type' => 'receipt',
        'amount' => 1000,
        'method' => 'cash',
        'transaction_date' => now()->toDateString(),
        'status' => 'recorded',
    ]);
    DepositTransaction::query()->whereKey($deposit->getKey())->update(['asset_id' => null]);
    $deposit = $deposit->fresh();

    expect($deposit->asset_id)->toBeNull();

    $url = asTenant($this->asset, fn (): ?string => OpenRecordAction::urlFor(DepositTransactionResource::class, $deposit));

    expect($url)->toBe(asTenant($this->asset, fn () => DepositTransactionResource::getUrl('edit', ['record' => $deposit])));
});
