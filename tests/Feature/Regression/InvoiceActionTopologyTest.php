<?php

/*
|--------------------------------------------------------------------------
| The invoice header said "Regenerate payment link" twice (2026-09-01)
|--------------------------------------------------------------------------
| Reported from the panel, not by a test. `EditInvoice::getHeaderActions()` composed
| `InvoiceActions::all()` — which defines `regeneratePaymentLink` — AND a second, inline copy of
| the same act, so the operator read the same red button twice in one header. Both rotated the same
| token, so nothing was WRONG with either; what was wrong is that a destructive act appeared twice
| and neither said which was which.
|
| It survived because a duplicate is invisible from either definition: each file is correct on its
| own, and `cacheAction()` keys by name, so `mountAction('regeneratePaymentLink')` resolved cleanly
| and every existing test passed. Only the rendered header shows two.
|
| The second half is layout. Thirteen loose actions filled the header edge to edge and wrapped the
| page title down four lines — so the fix groups them, and grouping brings its own failure mode:
| **an act missing from a group is defined and rendered NOWHERE.** It passes every visibility and
| authorisation check and simply never appears, which is exactly what happened to two lease actions
| the day THEY were grouped. These tests hold both ends.
*/

use App\Filament\Admin\Actions\InvoiceActions;
use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Models\Invoice;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset), makeTenant());
    $this->invoice = makeInvoice($this->lease, ['total' => 500, 'balance' => 500, 'status' => 'issued']);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('defines every act on the invoice header exactly once', function () {
    // The reported bug, stated directly: two definitions carrying one name. Read from the page's
    // own composition rather than from a list, so a THIRD copy added anywhere it composes from is
    // caught by the same assertion.
    $page = new EditInvoice;

    $names = array_map(
        fn (Action $a): string => $a->getName(),
        [...InvoiceActions::all(), ...$page->ownActions()],
    );

    $duplicates = array_values(array_unique(array_diff_assoc($names, array_unique($names))));

    expect($duplicates)->toBe([], 'Defined more than once, so the header renders it twice: '
        .implode(', ', $duplicates));

    // Vacuity guard: a refactor that emptied the composition would satisfy the assertion above.
    expect(count($names))->toBeGreaterThan(10);
});

it('renders every invoice act in a group — or nowhere at all', function () {
    $defined = collect(array_keys((new EditInvoice)->headerActs()));
    $grouped = collect(EditInvoice::HEADER_GROUPS)->flatten();

    $ungrouped = $defined->diff($grouped)->values()->all();
    $phantom = $grouped->diff($defined)->values()->all();

    expect($ungrouped)->toBe([], 'Defined but in no group, so rendered nowhere: '.implode(', ', $ungrouped));

    // The reverse: a group naming an act that no longer exists renders nothing and says nothing.
    expect($phantom)->toBe([], 'Grouped but not defined: '.implode(', ', $phantom));

    expect($defined->count())->toBeGreaterThan(10);
});

it('puts each act in exactly ONE group', function () {
    $grouped = collect(EditInvoice::HEADER_GROUPS)->flatten();

    // Twice on the page is a different bug from missing, and just as confusing to an operator —
    // which is the bug this file is named for.
    expect($grouped->count())->toBe($grouped->unique()->count());
});

it('opens the invoice page on a handful of controls, not a wall of verbs', function () {
    $header = Livewire::test(EditInvoice::class, ['record' => $this->invoice->getRouteKey()])
        ->instance()
        ->getCachedHeaderActions();

    // The ledger panel, three dropdowns and Restore. Before this the same page rendered SEVEN loose
    // buttons on an ordinary issued invoice and the title wrapped down four lines beside them.
    expect(count($header))->toBeLessThanOrEqual(5);

    $groups = array_values(array_filter($header, fn ($a): bool => $a instanceof ActionGroup));

    expect($groups)->toHaveCount(3)
        // A dropdown with no label is a bare chevron: the operator cannot tell the three apart.
        ->and(array_map(fn (ActionGroup $g): ?string => $g->getLabel(), $groups))
        ->each->not->toBeEmpty();
});

it('still offers every act it offered before the header was grouped', function () {
    // Grouping is a LAYOUT change and must not be an authorisation one. `cacheInteractsWithHeaderActions()`
    // merges a group's flat actions into the page's cached actions, so a grouped act stays
    // mountable by name — this pins that, because if it did not the whole header would go dark
    // while every unit test above stayed green.
    $page = Livewire::test(EditInvoice::class, ['record' => $this->invoice->getRouteKey()]);

    foreach (['downloadPdf', 'sendToTenant', 'paymentLink', 'regeneratePaymentLink', 'disputeLine', 'write_off', 'void_invoice'] as $act) {
        $page->assertActionVisible($act);
    }
});

it('hides a whole group when it has nothing to offer', function () {
    // A freshly issued invoice with no receipt, no credit and no write-off has nothing to settle
    // and nothing to reverse — so the Settlement dropdown must disappear rather than open onto an
    // empty menu. `ActionGroup::isHidden()` gives this for free once every act inside it is hidden,
    // and it is the property that makes grouping safe to apply to conditional acts.
    $groups = array_values(array_filter(
        Livewire::test(EditInvoice::class, ['record' => $this->invoice->getRouteKey()])
            ->instance()
            ->getCachedHeaderActions(),
        fn ($a): bool => $a instanceof ActionGroup,
    ));

    $shown = [];

    foreach ($groups as $group) {
        if (! $group->isHidden()) {
            $shown[] = $group->getLabel();
        }
    }

    expect($shown)->not->toContain(__('admin.actions.groups.settlement'))
        // Paired with a control: a grouping that hid EVERYTHING would satisfy the refusal alone.
        ->and($shown)->toContain(__('admin.actions.groups.document'));

    // And the reverse — once there IS money on it, the group appears.
    settleInvoiceInFull($this->invoice);

    $withMoney = array_values(array_filter(
        Livewire::test(EditInvoice::class, ['record' => $this->invoice->fresh()->getRouteKey()])
            ->instance()
            ->getCachedHeaderActions(),
        fn ($a): bool => $a instanceof ActionGroup && ! $a->isHidden(),
    ));

    expect(array_map(fn (ActionGroup $g): ?string => $g->getLabel(), $withMoney))
        ->toContain(__('admin.actions.groups.settlement'));
});
