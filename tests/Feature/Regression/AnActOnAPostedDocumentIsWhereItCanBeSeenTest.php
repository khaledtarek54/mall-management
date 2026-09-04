<?php

use App\Filament\Admin\Actions\FixedAssetActions;
use App\Filament\Admin\Resources\Custodies\Pages\EditCustody;
use App\Filament\Admin\Resources\DepositTransactions\Pages\EditDepositTransaction;
use App\Filament\Admin\Resources\FixedAssets\Pages\EditFixedAsset;
use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Admin\Resources\MarketingBudgets\Pages\EditMarketingBudget;
use App\Filament\Admin\Resources\MarketingBudgets\RelationManagers\MarketingSpendsRelationManager;
use App\Filament\Admin\Resources\VendorBills\Pages\EditVendorBill;
use App\Models\Custody;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\MarketingBudget;
use App\Models\MarketingSpend;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\FiscalCalendar;
use App\Support\Filament\LedgerRestatement;
use App\Support\RowActionPolicy;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **A factory hides its `->action()` in another file, and that blinded the gate that decides where
 * write verbs live.**
 *
 * `RowActionPolicy` derives a write verb from `->action(` appearing in the row action's chain. Both
 * of this app's shared money-action factories are ONE-LINE call sites — `PostMonthAction::make(…)`
 * and `ReverseDocumentAction::make(…)` — with the closure in `app/Filament/Actions/`. Measured
 * before the fix: `InvoicesTable` reported **zero write verbs** while carrying "Post to month", the
 * act that re-posts a live AR document into a different accounting period, and `CustodiesTable` and
 * `FixedAssetsTable` each reported zero while carrying the REVERSAL of a posted GL document.
 * `FixedAssetsTable` even carried a comment reading *"The list FINDS; the record ACTS"* directly
 * above the reversal it kept in the row.
 *
 * Four tables passed a gate that could not see them, and an operator who opened a custody or a
 * fixed asset to inspect it had to go back to the list to reverse it — which is the exact thing
 * `RowActionPolicy` exists to prevent.
 *
 * Two more things this file pins, both from the same sweep of what a posted document lets you do:
 *
 * **A guarded field must LOOK guarded.** `DepositTransactionForm` locked on `status !== 'recorded'`
 * — i.e. it froze a CANCELLED deposit and left a live one wide open — while the model refuses on
 * `hasBeenDrawnOn()` and `finalAccountIsSettled()`. So a receipt already netted against arrears
 * rendered every field enabled, the operator retyped the amount, and the model answered with a
 * refusal toast on submit. `ExpenseForm` states the house rule beside its own `$moneyLocked`: the
 * same predicate on both layers.
 *
 * **DERIVED means the operator is told.** Every fillable of `MarketingSpend` is DERIVED, and
 * `AnnouncesLedgerRestatement` hooks `getSavedNotification()` — an `EditRecord` method — so it
 * reaches the nine money Edit PAGES and never the relation-manager modal where a spend is actually
 * edited. The entry was voided and re-posted behind a plain "Saved".
 */
beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset(['code' => 'AC']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

// ───────────────────── The gate can see a factory's act ─────────────────────

it('reads a factory action as the write verb it is', function () {
    // Both factories, through the policy's own entry point rather than by inspecting the helper —
    // the classification is what the gate acts on.
    foreach ([
        "ReverseDocumentAction::make(can: fn () => true, label: 'x', confirm: 'y', done: 'z'),",
        "PostMonthAction::make('invoices.edit'),",
    ] as $callSite) {
        expect(RowActionPolicy::rowActionsIn("<?php\n\$t->recordActions([{$callSite}]);")['verbs'])
            ->toHaveCount(1, "factory call site read as a read: {$callSite}");
    }
});

it('still reads the read-only ledger peek as an affordance', function () {
    // **The prose false-positive.** `LedgerEntryAction`'s own docblock says *"Read-only —
    // `modalSubmitAction(false)` and no `->action()`"*, so a raw `str_contains` over the factory's
    // file classified the one thing in that folder that genuinely reads as a destructive verb — on
    // four tables at once. Two conformance gates here have already been weakened by firing on a
    // sentence; comments are stripped before the scan for exactly this reason.
    $read = RowActionPolicy::rowActionsIn("<?php\n\$t->recordActions([LedgerEntryAction::make(),]);");

    expect($read['verbs'])->toBeEmpty()->and($read['reads'])->toContain('LedgerEntry');
});

it('leaves no write verb in the row of the four money tables it could not see', function () {
    $offenders = [];

    foreach ([
        'app/Filament/Admin/Resources/Invoices/Tables/InvoicesTable.php',
        'app/Filament/Admin/Resources/VendorBills/Tables/VendorBillsTable.php',
        'app/Filament/Admin/Resources/Custodies/Tables/CustodiesTable.php',
        'app/Filament/Admin/Resources/FixedAssets/Tables/FixedAssetsTable.php',
    ] as $table) {
        $verbs = RowActionPolicy::rowActionsIn(file_get_contents(base_path($table)))['verbs'];

        if ($verbs !== []) {
            $offenders[] = basename($table).': '.implode(', ', $verbs);
        }
    }

    expect($offenders)->toBe([]);
});

// ───────────────────── …and the act is on the record instead ─────────────────────

it('offers the post month on the invoice record page', function () {
    $lease = makeLease(makeUnit($this->asset, ['code' => 'AC-01']));
    $invoice = makeInvoice($lease);

    $page = Livewire::test(EditInvoice::class, ['record' => $invoice->id]);

    // Defined AND rendered. A grouped header drops an act that is missing from the group map, and
    // it then passes every visibility check while appearing nowhere — the failure mode
    // `EditInvoice::HEADER_GROUPS` warns about in writing.
    expect(array_keys($page->instance()->headerActs()))->toContain('postToMonth')
        ->and(EditInvoice::HEADER_GROUPS['corrections'])->toContain('postToMonth');

    $page->assertActionVisible('postToMonth');
});

it('offers the post month on the vendor bill record page', function () {
    $vendor = Vendor::create(['name' => 'Contractor', 'status' => 'active', 'asset_id' => $this->asset->id]);
    $bill = VendorBill::create([
        'asset_id' => $this->asset->id,
        'vendor_id' => $vendor->id,
        'reference' => 'SUP-1',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'category' => 'maintenance',
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000,
        'status' => 'draft',
    ]);

    Livewire::test(EditVendorBill::class, ['record' => $bill->id])
        ->assertActionVisible('postToMonth');
});

it('offers the reversal on the custody record page', function () {
    $employee = Employee::create([
        'asset_id' => $this->asset->id,
        'code' => 'EMP-AC-1',
        'name' => 'Custodian',
        'status' => 'active',
        'hire_date' => '2025-01-01',
    ]);
    $custody = Custody::create([
        'asset_id' => $this->asset->id,
        'employee_id' => $employee->id,
        'amount' => 5000,
        'custody_date' => now()->toDateString(),
        'paid_from' => 'cash',
    ]);

    Livewire::test(EditCustody::class, ['record' => $custody->id])
        ->assertActionVisible('reverse_document');
});

it('offers the reversal on the fixed asset record page', function () {
    // Through the registry the page composes, so the act cannot be present in one and absent from
    // the other.
    $names = array_map(fn ($action) => $action->getName(), FixedAssetActions::all());

    expect($names)->toContain('reverse_document')->and($names)->toContain('dispose');

    $fixedAsset = FixedAsset::create([
        'asset_id' => $this->asset->id,
        'name' => 'Chiller',
        'tag' => 'FA-1',
        'acquisition_date' => now()->toDateString(),
        'acquisition_cost' => 100000,
        'salvage_value' => 0,
        'useful_life_months' => 120,
        'funded_from' => 'cash',
        'status' => 'active',
    ]);

    Livewire::test(EditFixedAsset::class, ['record' => $fixedAsset->id])
        ->assertActionVisible('reverse_document');
});

// ───────────────────── A guarded field looks guarded ─────────────────────

it('disables a deposit receipt the pot has already been drawn on', function () {
    $lease = makeLease(makeUnit($this->asset, ['code' => 'AC-02']));
    $receipt = depositMovement($lease, 'receipt', 100000);

    // The CONTROL first: nothing depends on it yet, so a keying mistake must stay correctable.
    // Without this a form that disabled everything would satisfy the refusal below.
    expect(depositAmountIsDisabled($receipt->id))->toBeFalse();

    // Draw on the pot. `hasBeenDrawnOn()` is asked of the LEASE — the deposit is one pot per lease
    // — so a refund against it freezes the receipt that funded it.
    depositMovement($lease, 'refund', 10000);

    expect(depositAmountIsDisabled($receipt->fresh()->id))->toBeTrue();
});

function depositAmountIsDisabled(int $recordId): bool
{
    return Livewire::test(EditDepositTransaction::class, ['record' => $recordId])
        ->instance()
        ->form
        ->getComponent('amount')
        ->isDisabled();
}

it('says on the form that a posted fixed asset re-posts when these fields move', function () {
    // Not disabled — the model deliberately permits the change (a re-cost is a supported operation)
    // and a form stricter than its model is the divergence the deposit form had in the other
    // direction. What was missing is the operator being told BEFORE, rather than by the toast after.
    $fixedAsset = FixedAsset::create([
        'asset_id' => $this->asset->id,
        'name' => 'Lift',
        'tag' => 'FA-2',
        'acquisition_date' => now()->toDateString(),
        'acquisition_cost' => 50000,
        'salvage_value' => 0,
        'useful_life_months' => 60,
        'funded_from' => 'cash',
        'status' => 'active',
    ]);

    // Read what the OPERATOR sees. Filament v4 composes `helperText()` into `belowContent()` and
    // exposes no `getHelperText()` at all, so the obvious accessor is a BadMethodCall rather than a
    // wrong answer — and a sentence the form holds but never paints warns nobody.
    $page = Livewire::test(EditFixedAsset::class, ['record' => $fixedAsset->id])->assertOk();

    expect($page->html())->toContain(__('admin.fixed_assets.posted_field_hint'));

    $form = $page->instance()->form;

    foreach (['acquisition_date', 'funded_from'] as $field) {
        expect($form->getComponent($field)->isDisabled())->toBeFalse("{$field} became stricter than its model");
    }
});

// ───────────────────── DERIVED means the operator is told ─────────────────────

it('tells the operator the books moved when a posted marketing spend is edited', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $budget = MarketingBudget::create([
        'asset_id' => $this->asset->id,
        'period_year' => (int) now()->year,
        'accrued_amount' => 100000,
    ]);
    $spend = MarketingSpend::create([
        'marketing_budget_id' => $budget->id,
        'asset_id' => $this->asset->id,
        'category' => 'other',
        'description' => 'Ramadan banners',
        'amount' => 1000,
        'paid_from' => 'cash',
        'spent_on' => now()->toDateString(),
    ]);

    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    // The CONTROL: with the entry in step, the seam says nothing — a body on every save is noise
    // nobody reads, which is why a notification per re-derive was measured and declined.
    expect(LedgerRestatement::noticeFor($spend->fresh()))->toBeNull();

    Livewire::test(MarketingSpendsRelationManager::class, [
        'ownerRecord' => $budget,
        'pageClass' => EditMarketingBudget::class,
    ])
        ->callTableAction('edit', $spend, data: [
            'category' => 'other',
            'description' => 'Ramadan banners',
            'amount' => 2500,
            'paid_from' => 'cash',
            'spent_on' => now()->toDateString(),
        ])
        ->assertHasNoTableActionErrors();

    // The FIGURES, not a boolean: an operator who meant to fix a description cannot otherwise tell
    // a harmless re-derive from one that moves the month's spend.
    expect(LedgerRestatement::noticeFor($spend->fresh()))
        ->toBe(__('admin.notifications.ledger_will_repost', [
            'from' => 'EGP 1,000.00',
            'to' => 'EGP 2,500.00',
        ]));

    // …and the action is actually wired to say it, rather than the seam merely being able to.
    expect(sourceWithoutComments(base_path(
        'app/Filament/Admin/Resources/MarketingBudgets/RelationManagers/MarketingSpendsRelationManager.php'
    )))->toContain('successNotification');
});
