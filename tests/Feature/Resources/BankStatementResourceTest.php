<?php

use App\Filament\Admin\Resources\BankStatements\BankStatementResource;
use App\Filament\Admin\Resources\BankStatements\Pages\ListBankStatements;
use App\Models\BankAccount;
use App\Models\BankStatement;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The reconciliation workspace — the screen that makes slices 2 and 3 reachable by an operator.
 *
 * The engine is tested elsewhere (import idempotency, the four match guards). This is about the
 * surface: who can open it, and that a statement is scoped to the property whose money it is —
 * which it inherits from its ACCOUNT, having no `asset_id` of its own. That indirection is the part
 * worth a test: a resource that forgot it would leak every mall's bank statements to everyone.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    $this->actingAs(makeUser('accounting'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    Filament::setTenant($this->asset);

    $this->account = BankAccount::create([
        'asset_id' => $this->asset->id,
        'name' => 'CIB — current',
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function makeStatement(BankAccount $account, string $start = '2026-03-01', string $end = '2026-03-31'): BankStatement
{
    return BankStatement::create([
        'bank_account_id' => $account->id,
        'period_start' => $start,
        'period_end' => $end,
        'opening_balance' => 0,
        'closing_balance' => 0,
    ]);
}

it('lists the statements for this property', function () {
    $mine = makeStatement($this->account);

    Livewire::test(ListBankStatements::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine]);
});

it('scopes through the ACCOUNT, since a statement has no property of its own', function () {
    $mine = makeStatement($this->account);

    $otherAccount = BankAccount::create(['asset_id' => makeAsset()->id, 'name' => 'Another mall']);
    $theirs = makeStatement($otherAccount);

    Livewire::test(ListBankStatements::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('is gated on the banking permission', function () {
    expect(BankStatementResource::canViewAny())->toBeTrue(); // accounting, from beforeEach

    $this->actingAs(makeUser('leasing'));
    expect(BankStatementResource::canViewAny())->toBeFalse();
});

it('shows whether the bank\'s own arithmetic holds', function () {
    // The signal an operator needs BEFORE matching anything: a statement that does not add up was
    // mis-ingested, and matching against it would spread that error into the books' story.
    $statement = makeStatement($this->account);
    $statement->update(['opening_balance' => 0, 'closing_balance' => 500]);

    expect($statement->isSelfConsistent())->toBeFalse();

    $statement->update(['closing_balance' => 0]);

    expect($statement->refresh()->isSelfConsistent())->toBeTrue();
});
