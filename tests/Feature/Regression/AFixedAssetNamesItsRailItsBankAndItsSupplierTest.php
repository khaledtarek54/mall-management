<?php

use App\Filament\Admin\Resources\FixedAssets\Pages\CreateFixedAsset;
use App\Filament\Admin\Resources\FixedAssets\Pages\EditFixedAsset;
use App\Filament\Imports\FixedAssetImporter;
use App\Models\BankAccount;
use App\Models\FixedAsset;
use App\Models\LedgerAccount;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\DepreciationService;
use App\Services\DisposeFixedAssetService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FixedAssetCategorySeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Meeting 2026-09-02, point 15 — *"funded from: make it a payment method, and who is the supplier —
 * one in the system, or a new name."*
 *
 * `funded_from` held the literal `cash|bank` and the acquisition's credit leg resolved by POSTING
 * ROLE alone: a bank-funded asset landed in the generic `bank` role — the unattributed state SW-228
 * closed for receipts — and no supplier was recorded at all. The market acquires an asset THROUGH
 * the supplier (SAP F-90, Odoo's asset-from-bill, Yardi's capital GL on the payable) or by a payment
 * that names the bank. Slice 1: the rail is the outbound catalogue, the asset is the ninth
 * document on `RecordsBankAccount` (asked · defaulted · required exactly as the others), and the supplier is a `vendors` ROW, never a typed name. The supplier BILL that
 * capitalises the purchase is slice 2.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(PaymentMethodSeeder::class);
    $this->seed(FixedAssetCategorySeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'FAR']);
    $this->other = makeAsset(['code' => 'FAX']);

    // A bank with a chart account of its OWN — the whole point of naming it.
    $this->leaf = LedgerAccount::create([
        'code' => '11900001', 'name_en' => 'CIB — FAR', 'name_ar' => 'CIB — FAR',
        'type' => 'asset', 'is_postable' => true, 'is_active' => true,
    ]);
    $this->cib = BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'CIB — operating', 'account_number' => 'FAR-OP-1',
        'purpose' => BankAccount::PURPOSE_OPERATING, 'is_default' => true, 'ledger_account_id' => $this->leaf->id,
    ]);
});

function suppliedAsset(int $assetId, array $attrs = []): FixedAsset
{
    return FixedAsset::create(array_merge([
        'asset_id' => $assetId,
        'name' => 'Lobby chiller',
        'category' => 'HVAC',
        'acquisition_date' => now()->startOfYear()->toDateString(),
        'acquisition_cost' => 240000,
        'method' => 'straight_line',
        'funded_from' => 'bank_transfer',
    ], $attrs));
}

// ── The credit leg names the bank ─────────────────────────────────────────────────────────────

it('credits the asset\'s own bank account, and the role only when it names none', function () {
    $poster = app(LedgerPoster::class);
    $accounts = app(AccountResolver::class);

    // Defaulted from the property on a bank rail (Yardi's shape: the operator confirms, never picks
    // three hundred times), so the credit lands in CIB's own leaf — never the generic role.
    $named = suppliedAsset($this->asset->id);
    expect($named->bank_account_id)->toBe($this->cib->id);

    $lines = $poster->post($named)->lines->keyBy('ledger_account_id');
    expect($lines->has($this->leaf->id))->toBeTrue()
        ->and((float) $lines[$this->leaf->id]->credit)->toBe(240000.0)
        ->and($lines->has($accounts->id('bank', $this->asset->id)))->toBeFalse();

    // A cash purchase names no bank and credits the till — the control, and the legacy literal
    // `bank` on a property with no register still falls to the role exactly as before.
    $cash = suppliedAsset($this->asset->id, ['name' => 'Desk', 'funded_from' => 'cash']);
    expect($cash->bank_account_id)->toBeNull()
        ->and($poster->post($cash)->lines->keyBy('ledger_account_id')->has($accounts->id('cash', $this->asset->id)))->toBeTrue();

    $legacy = suppliedAsset($this->other->id, ['name' => 'Old rack', 'funded_from' => 'bank']);
    expect($legacy->bank_account_id)->toBeNull()
        ->and($poster->post($legacy)->lines->keyBy('ledger_account_id')->has($accounts->id('bank', $this->other->id)))->toBeTrue();
});

it('takes any outbound rail the catalogue offers and refuses an inbound-only one', function () {
    expect(suppliedAsset($this->asset->id, ['funded_from' => 'cheque'])->funded_from)->toBe('cheque');

    // `wallet` is seeded inbound-only: a shopper pays with it, a mall does not buy a chiller with it.
    expect(fn () => suppliedAsset($this->asset->id, ['name' => 'Odd', 'funded_from' => 'wallet']))
        ->toThrow(DomainException::class);
    expect(fn () => suppliedAsset($this->asset->id, ['name' => 'Odder', 'funded_from' => 'barter']))
        ->toThrow(DomainException::class);
});

it('refuses another mall\'s bank account, on create and on a re-home', function () {
    $foreign = BankAccount::create([
        'asset_id' => $this->other->id, 'name' => 'Other mall', 'account_number' => 'FAX-1',
        'purpose' => BankAccount::PURPOSE_OPERATING,
    ]);

    expect(fn () => suppliedAsset($this->asset->id, ['bank_account_id' => $foreign->id]))
        ->toThrow(DomainException::class, __('admin.errors.bank_account_other_property'));

    // Re-homing the asset to the other mall while it still names CIB is the same wrong posting
    // arrived at from the other side.
    $own = suppliedAsset($this->asset->id);
    expect(fn () => $own->update(['asset_id' => $this->other->id]))
        ->toThrow(DomainException::class, __('admin.errors.bank_account_other_property'));
});

it('freezes the credit leg on a disposed asset, with the name still editable', function () {
    $sold = suppliedAsset($this->asset->id);
    app(DepreciationService::class)->run(now()->startOfYear(), [$this->asset->id]);
    app(DisposeFixedAssetService::class)->dispose($sold, ['disposed_on' => now()->toDateString(), 'proceeds' => 0]);

    $frozen = $sold->fresh();
    expect(fn () => $frozen->update(['funded_from' => 'cash']))
        ->toThrow(DomainException::class, __('admin.fixed_assets.errors.disposed_immutable'));
    expect(fn () => $sold->fresh()->update(['bank_account_id' => null]))
        ->toThrow(DomainException::class, __('admin.fixed_assets.errors.disposed_immutable'));

    $sold->fresh()->update(['name' => 'Lobby chiller (sold)']);
    expect($sold->fresh()->name)->toBe('Lobby chiller (sold)');
});

it('keeps a rail that an asset was bought on from being deleted', function () {
    suppliedAsset($this->asset->id, ['funded_from' => 'cheque']);

    // The rail catalogue's own rule — a deleted row leaves documents naming a code nothing can
    // explain — reaches the eighth column it serves; it did not, until the relation existed.
    expect(fn () => PaymentMethod::where('code', 'cheque')->sole()->delete())->toThrow(DomainException::class);
    expect(PaymentMethod::where('code', 'cheque')->exists())->toBeTrue();

    // The control: a rail nothing names is still deletable.
    PaymentMethod::where('code', 'meeza')->sole()->delete();
    expect(PaymentMethod::where('code', 'meeza')->exists())->toBeFalse();
});

// ── The supplier is a row ─────────────────────────────────────────────────────────────────────

it('keeps a supplier that an asset names from being deleted', function () {
    $vendor = Vendor::create(['name' => 'Carrier Egypt', 'type' => 'supplier', 'status' => 'active']);
    suppliedAsset($this->asset->id, ['vendor_id' => $vendor->id]);

    expect(fn () => $vendor->delete())->toThrow(DomainException::class);
    expect(Vendor::whereKey($vendor->id)->exists())->toBeTrue();

    // The control: a supplier nothing names is still deletable.
    $unused = Vendor::create(['name' => 'Nobody bought here', 'type' => 'supplier', 'status' => 'active']);
    $unused->delete();
    expect(Vendor::whereKey($unused->id)->exists())->toBeFalse();
});

// ── The doors: the form, the importer ─────────────────────────────────────────────────────────

describe('through the panel', function () {
    beforeEach(function () {
        $this->actingAs(makeUser('accounting', [$this->asset->id]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    });

    it('offers the outbound rails, asks which bank on a bank rail, and registers a new supplier from the picker', function () {
        asTenant($this->asset, function () {
            $fill = fn (string $rail, ?int $account, string $name) => [
                'asset_id' => $this->asset->id, 'category' => 'HVAC', 'name' => $name, 'tag' => null,
                'acquisition_date' => now()->startOfYear()->toDateString(), 'acquisition_cost' => 1000,
                'funded_from' => $rail, 'bank_account_id' => $account,
            ];

            $page = Livewire::test(CreateFixedAsset::class);

            // The rail picker is the catalogue's outbound side, floored on the legacy literals.
            $options = $page->instance()->form->getComponent('funded_from')->getOptions();
            expect($options)->toHaveKeys(['cash', 'bank_transfer', 'cheque'])
                ->and($options)->not->toHaveKey('wallet');

            // Asked, defaulted, required — the same three the expense form does.
            $page->assertFormSet(['bank_account_id' => $this->cib->id]);
            $page->fillForm($fill('bank_transfer', null, 'Unbanked chiller'))->call('create')
                ->assertHasFormErrors(['bank_account_id']);
            Livewire::test(CreateFixedAsset::class)->fillForm($fill('cash', null, 'Till chiller'))->call('create')
                ->assertHasNoFormErrors();
            Livewire::test(CreateFixedAsset::class)->fillForm($fill('bank_transfer', $this->cib->id, 'CIB chiller'))->call('create')
                ->assertHasNoFormErrors();
            expect(FixedAsset::where('name', 'CIB chiller')->sole()->bank_account_id)->toBe($this->cib->id);

            // An EXISTING supplier is picked and linked; the "new name" door is the next case.
            $vendor = Vendor::create(['name' => 'Carrier Egypt', 'type' => 'supplier', 'status' => 'active']);
            Livewire::test(CreateFixedAsset::class)
                ->fillForm($fill('cash', null, 'Supplied chiller') + ['vendor_id' => $vendor->id])
                ->call('create')->assertHasNoFormErrors();
            expect(FixedAsset::where('name', 'Supplied chiller')->sole()->vendor_id)->toBe($vendor->id);
        });
    });

    it('leaves a legacy bank-funded asset editable, asks again only when the rail moves, and never lets a named bank be cleared', function () {
        asTenant($this->asset, function () {
            // Every pre-register asset on a real install: `bank`, no account, posted long ago.
            $legacy = suppliedAsset($this->asset->id, ['name' => 'Old chiller', 'funded_from' => 'bank', 'bank_account_id' => null]);
            $legacy->forceFill(['bank_account_id' => null])->saveQuietly();
            expect($legacy->fresh()->bank_account_id)->toBeNull();

            // A name-only save is not a bank question — the requirement stands down on a row that
            // never named one (measured: it refused "The bank account field is required", and
            // answering it was a DERIVED re-post a closed period then refused as well).
            Livewire::test(EditFixedAsset::class, ['record' => $legacy->getKey()])
                ->fillForm(['name' => 'Old chiller (renamed)'])
                ->call('save')->assertHasNoFormErrors();
            expect($legacy->fresh()->name)->toBe('Old chiller (renamed)')
                ->and($legacy->fresh()->bank_account_id)->toBeNull();

            // Re-stating the rail re-opens the question: a purchase re-railed onto a transfer says
            // which bank, or it is refused.
            Livewire::test(EditFixedAsset::class, ['record' => $legacy->getKey()])
                ->fillForm(['funded_from' => 'bank_transfer', 'bank_account_id' => null])
                ->call('save')->assertHasFormErrors(['bank_account_id']);

            // A row that NAMES a bank cannot have it cleared — that is a restatement to the generic
            // role, never a correction.
            $named = suppliedAsset($this->asset->id, ['name' => 'Named chiller']);
            expect($named->bank_account_id)->toBe($this->cib->id);
            Livewire::test(EditFixedAsset::class, ['record' => $named->getKey()])
                ->fillForm(['bank_account_id' => null])
                ->call('save')->assertHasFormErrors(['bank_account_id']);

            // And a cash purchase left on the form's own defaults books to CASH: the bank the
            // field fills in from mount is not recorded on a rail that carries none (measured on
            // the expense form too — the credit leg went to the bank's leaf).
            Livewire::test(CreateFixedAsset::class)->fillForm([
                'asset_id' => $this->asset->id, 'category' => 'HVAC', 'name' => 'Petty chiller', 'tag' => null,
                'acquisition_date' => now()->startOfYear()->toDateString(), 'acquisition_cost' => 500,
            ])->call('create')->assertHasNoFormErrors();
            $petty = FixedAsset::where('name', 'Petty chiller')->sole();
            expect($petty->funded_from)->toBe('cash')->and($petty->bank_account_id)->toBeNull();
        });
    });

    it('opens the "new name" door only to a role that may register suppliers, and registers a real one', function () {
        asTenant($this->asset, function () {
            // `accounting` holds `fixed_assets.create` and not `vendors.create` — it could mint
            // suppliers through the picker until the action carried the register's own right.
            // Asserted on the PREDICATE: `callAction()` asserts visibility first and `mountAction`
            // refuses a hidden action, so neither can tell a gate from a no-op.
            $action = Livewire::test(CreateFixedAsset::class)->instance()->form->getComponent('vendor_id')->getCreateOptionAction();
            expect(auth()->user()->can('vendors.create'))->toBeFalse()
                ->and($action)->not->toBeNull()
                ->and($action->isHidden())->toBeTrue()
                ->and($action->isAuthorized())->toBeFalse();
        });

        auth()->logout();
        session()->flush();
        $this->actingAs(makeUser('manager', [$this->asset->id]));
        expect(auth()->user()->can('vendors.create'))->toBeTrue();

        asTenant($this->asset, function () {
            $before = Vendor::count();
            $page = Livewire::test(CreateFixedAsset::class)
                ->callAction(
                    TestAction::make('createOption')->schemaComponent('vendor_id'),
                    ['name' => 'Quick Created Supplier', 'type' => 'supplier', 'phone' => '01000000000'],
                )
                ->assertHasNoActionErrors();
            expect(Vendor::count())->toBe($before + 1);
            $vendor = Vendor::where('name', 'Quick Created Supplier')->sole();
            // A real counterparty: the party code is allocated by the model.
            expect($vendor->code)->not->toBeNull()->and($vendor->status)->toBe('active');

            $page->fillForm([
                'asset_id' => $this->asset->id, 'category' => 'HVAC', 'name' => 'Quick-supplied chiller', 'tag' => null,
                'acquisition_date' => now()->startOfYear()->toDateString(), 'acquisition_cost' => 1000,
                'funded_from' => 'cash', 'bank_account_id' => null, 'vendor_id' => $vendor->id,
            ])->call('create')->assertHasNoFormErrors();
            expect(FixedAsset::where('name', 'Quick-supplied chiller')->sole()->vendor_id)->toBe($vendor->id);
        });
    });
});

describe('through the importer', function () {
    beforeEach(function () {
        $this->import = Import::create([
            'completed_at' => null, 'file_name' => 'fa.csv', 'file_path' => 'fa.csv',
            'importer' => FixedAssetImporter::class, 'processed_rows' => 0, 'total_rows' => 1, 'successful_rows' => 0,
            'user_id' => User::factory()->create()->id,
        ]);
    });

    it('resolves a supplier by its code, refuses one it does not know in words, and never offers the rail', function () {
        $row = function (array $data): void {
            $map = collect(array_keys($data))->mapWithKeys(fn ($k) => [$k => $k])->all();
            (new FixedAssetImporter($this->import, $map, []))($data);
        };
        $vendor = Vendor::create(['name' => 'Carrier Egypt', 'type' => 'supplier', 'status' => 'active']);

        $row(['asset_code' => 'FAR', 'tag' => 'CH-1', 'name' => 'Old chiller', 'category' => 'HVAC', 'acquisition_date' => '2023-01-01',
            'acquisition_cost' => '100000', 'opening_accumulated_depreciation' => '30000', 'useful_life_months' => '', 'vendor_code' => $vendor->code]);
        expect(FixedAsset::where('name', 'Old chiller')->sole()->vendor_id)->toBe($vendor->id);

        // A mapped BLANK cell on a re-import CLEARS — what a door set, the same door can unset.
        $row(['asset_code' => 'FAR', 'tag' => 'CH-1', 'name' => 'Old chiller', 'category' => 'HVAC', 'acquisition_date' => '2023-01-01',
            'acquisition_cost' => '100000', 'opening_accumulated_depreciation' => '30000', 'useful_life_months' => '', 'vendor_code' => '']);
        expect(FixedAsset::where('name', 'Old chiller')->sole()->vendor_id)->toBeNull();

        // Refused in the one exception `ImportCsv` writes into the failed-rows file with its
        // sentence — never a vendor minted from a spreadsheet cell.
        expect(fn () => $row(['asset_code' => 'FAR', 'tag' => 'CH-2', 'name' => 'Odd chiller', 'category' => 'HVAC', 'acquisition_date' => '2023-01-01',
            'acquisition_cost' => '1000', 'opening_accumulated_depreciation' => '0', 'useful_life_months' => '', 'vendor_code' => 'VN-NOPE']))
            ->toThrow(RowImportFailedException::class, __('admin.fixed_assets.errors.unknown_vendor_code', ['code' => 'VN-NOPE']));
        expect(FixedAsset::where('name', 'Odd chiller')->exists())->toBeFalse()
            ->and(Vendor::count())->toBe(1);

        // Every imported asset is an opening balance and posts nothing, so the credit side is not
        // a question the file may answer.
        $columns = collect(FixedAssetImporter::getColumns())->map(fn ($c) => $c->getName())->all();
        expect($columns)->toContain('vendor_code')
            ->and($columns)->not->toContain('funded_from')
            ->and($columns)->not->toContain('bank_account_id');
    });
});
