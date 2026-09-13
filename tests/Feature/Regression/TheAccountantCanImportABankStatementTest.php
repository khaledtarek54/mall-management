<?php

use App\Filament\Admin\Resources\BankStatements\Pages\EditBankStatement;
use App\Filament\Admin\Resources\BankStatements\RelationManagers\LinesRelationManager;
use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\User;
use App\Services\Accounting\MintBankLedgerAccountService;
use App\Support\Imports;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/**
 * **The accountant can bring the bank's statement into the reconciliation workspace.**
 *
 * Found by driving the bank reconciliation as `accounting` (2026-09-13 workflow audit): the Lines
 * tab's *Import* button was gated on `Imports::allowed()` — FR-USR-02's ADMIN data-import right —
 * from the day the workspace shipped, and there is no other door onto a statement's lines. So the
 * one role the module exists for could match lines and never get any in; the module was usable by
 * admins alone, and nobody reported it because an admin driving the demo never met the gate.
 *
 * FR-USR-02 is about the operator's own REGISTERS: tenants, units, leases, where one wrong CSV
 * column rewrites hundreds of rows. A statement file is the bank's evidence — importing it writes
 * no register, posts nothing and is idempotent. It has its own right now, `bank_accounts.
 * import_statement`, which the accountant holds and which is not the admin import right — the
 * market's shape, where the bank-rec import is a function of the bank-rec role.
 *
 * Every refusal is paired with the control that the intake still works for the role it is for.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'BKI']);

    $ledger = app(MintBankLedgerAccountService::class)->mint('CIB — current', $this->asset->id);
    $account = BankAccount::create([
        'asset_id' => $this->asset->id,
        'name' => 'CIB — current',
        'ledger_account_id' => $ledger->id,
    ]);
    $this->statement = BankStatement::create([
        'bank_account_id' => $account->id,
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'opening_balance' => 0,
        'closing_balance' => 0,
    ]);
});

function bankLinesTabAs(User $user, BankStatement $statement): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::actingAs($user)->test(LinesRelationManager::class, [
        'ownerRecord' => $statement,
        'pageClass' => EditBankStatement::class,
    ]);
}

it('offers the import to the accountant and brings the lines in', function () {
    $accountant = makeUser('accounting', [$this->asset->id]);

    expect($accountant->can(Imports::PERMISSION))->toBeFalse('the premise: accounting is NOT an admin importer')
        ->and($accountant->can('bank_accounts.import_statement'))->toBeTrue();

    $csv = "Date,Description,Reference,Debit,Credit\n2026-03-10,TRF TO SUPPLIER,TRF1,4000.00,\n2026-03-11,DEPOSIT,55123,,12000.00\n";

    asTenant($this->asset, function () use ($accountant, $csv) {
        bankLinesTabAs($accountant, $this->statement)
            ->assertTableActionVisible('import')
            ->callTableAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('march.csv', $csv),
            ])
            ->assertHasNoTableActionErrors();
    });

    // The control that the door is real, not merely visible: the bank's two lines are on file.
    expect($this->statement->lines()->count())->toBe(2)
        ->and((float) $this->statement->lines()->sum('amount'))->toBe(8000.0);
});

it('is the workspace\'s own right, not the admin data-import right', function () {
    // A viewer given the ADMIN import right and nothing else must still not see the workspace's
    // import — the gate the change replaced would have shown it to them. Run BOTH ways so a revert
    // to `Imports::allowed()` fails here rather than passing on a coincidence of grants.
    $viewer = makeUser('viewer', [$this->asset->id]);
    $viewer->givePermissionTo(Permission::findOrCreate(Imports::PERMISSION, 'web'));

    expect($viewer->fresh()->can(Imports::PERMISSION))->toBeTrue()
        ->and($viewer->can('bank_accounts.import_statement'))->toBeFalse();

    asTenant($this->asset, fn () => bankLinesTabAs($viewer->fresh(), $this->statement)
        ->assertTableActionHidden('import'));

    // …and the same person holding the workspace's right, and NOT the admin one, sees it.
    $viewer->revokePermissionTo(Imports::PERMISSION);
    $viewer->givePermissionTo('bank_accounts.import_statement');

    asTenant($this->asset, fn () => bankLinesTabAs($viewer->fresh(), $this->statement)
        ->assertTableActionVisible('import'));
});

it('is seeded to the roles that reconcile and withheld from the ones that only read', function () {
    $holders = ['accounting', 'manager', 'mall_admin', 'super_admin'];

    foreach ($holders as $role) {
        expect(makeUser($role)->can('bank_accounts.import_statement'))->toBeTrue($role);
    }

    // The withheld set is DERIVED from the seeder's own role list, so a fifteenth role is asserted
    // on by existing rather than by somebody remembering to add it here.
    $withheld = array_values(array_diff(array_keys(RolesPermissionsSeeder::ROLES), $holders));
    expect($withheld)->toContain('viewer', 'owner', 'vendor', 'leasing');

    foreach ($withheld as $role) {
        expect(makeUser($role)->can('bank_accounts.import_statement'))->toBeFalse($role);
    }

    // The Roles screen labels every right from this catalogue; a key with no Arabic renders raw.
    foreach (['en', 'ar'] as $locale) {
        expect(Lang::has('admin.permissions.bank_accounts.import_statement', $locale, fallback: false))
            ->toBeTrue("label in {$locale}");
    }
});
