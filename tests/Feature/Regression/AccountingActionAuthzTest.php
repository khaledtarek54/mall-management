<?php

use App\Filament\Admin\Resources\CreditNotes\Pages\EditCreditNote;
use App\Filament\Admin\Resources\Expenses\Pages\EditExpense;
use App\Filament\Admin\Resources\JournalEntries\Pages\EditJournalEntry;
use App\Filament\Admin\Resources\VendorBills\Pages\EditVendorBill;
use App\Models\Asset;
use App\Models\CreditNote;
use App\Models\Expense;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Regression (module audit): several AR/AP/GL-mutating header actions were gated by
 * ->visible() only (or, for credit notes, not at all), missing the server-side
 * ->authorize() — so an under-privileged user could execute them via a crafted
 * Livewire request (the documented Filament gotcha). Every such action now carries
 * ->authorize(); a user lacking the specific permission must NOT see/run it.
 *
 * The forbidden user is the accounting role (can reach the Edit page) with only the
 * one action permission revoked — isolating action-level authz.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    Filament::setTenant($this->asset, isQuiet: true); // no auth user yet in beforeEach
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * A user holding the accounting permission set MINUS one permission, as DIRECT
 * grants (no accounting role, so nothing re-inherits the removed permission). They
 * can still open the edit page (they keep view/update etc.) but lack the one action.
 */
function accountingWithout(string $permission): User
{
    $perms = Role::findByName('accounting', 'web')->permissions
        ->pluck('name')->reject(fn ($p) => $p === $permission)->values()->all();

    $user = makeUser('viewer'); // minimal role; effective perms come from the direct grants below
    $user->syncPermissions($perms);

    return $user;
}

function makeDraftCreditNote(Asset $asset): CreditNote
{
    $lease = makeLease(makeUnit($asset));

    return CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'status' => 'draft',
        'issue_date' => now()->toDateString(), 'reason' => 'adjustment',
        'subtotal' => 500, 'vat_amount' => 70, 'total' => 570, 'applied_amount' => 0, 'balance' => 570, 'currency' => 'EGP',
    ]);
}

it('credit-note issue/void require their permissions (were fully ungated)', function () {
    $note = makeDraftCreditNote($this->asset);

    // Permitted (full accounting role) sees them.
    $this->actingAs(makeUser('accounting'));
    Livewire::test(EditCreditNote::class, ['record' => $note->getRouteKey()])
        ->assertActionVisible('issue')
        ->assertActionVisible('void');

    // Lacking credit_notes.issue → issue hidden (server-side gate).
    $this->actingAs(accountingWithout('credit_notes.issue'));
    Livewire::test(EditCreditNote::class, ['record' => $note->getRouteKey()])
        ->assertActionHidden('issue');

    // Lacking credit_notes.void → void hidden.
    $this->actingAs(accountingWithout('credit_notes.void'));
    Livewire::test(EditCreditNote::class, ['record' => $note->getRouteKey()])
        ->assertActionHidden('void');
});

it('vendor-bill record_payment requires vendor_bills.pay', function () {
    $bill = VendorBill::create([
        'vendor_id' => Vendor::factory()->create()->id, 'asset_id' => $this->asset->id,
        'category' => 'utilities', 'status' => 'approved', 'bill_date' => now()->toDateString(),
        'subtotal' => 2000, 'vat_amount' => 280, 'total' => 2280, 'balance' => 2280,
    ]);

    $this->actingAs(makeUser('accounting'));
    Livewire::test(EditVendorBill::class, ['record' => $bill->getRouteKey()])
        ->assertActionVisible('record_payment');

    $this->actingAs(accountingWithout('vendor_bills.pay'));
    Livewire::test(EditVendorBill::class, ['record' => $bill->getRouteKey()])
        ->assertActionHidden('record_payment');
});

it('journal-entry post requires journal_entries.post', function () {
    $ar = LedgerAccount::where('code', '11201001')->value('id');
    $rev = LedgerAccount::where('code', '41101001')->value('id');
    $entry = app(JournalPostingService::class)->post([
        'status' => 'draft', 'entry_date' => now(), 'description_en' => 'D', 'description_ar' => 'د',
        'lines' => [
            ['ledger_account_id' => $ar, 'debit' => 100, 'credit' => 0],
            ['ledger_account_id' => $rev, 'debit' => 0, 'credit' => 100],
        ],
    ]);

    $this->actingAs(makeUser('accounting'));
    Livewire::test(EditJournalEntry::class, ['record' => $entry->getRouteKey()])
        ->assertActionVisible('post');

    $this->actingAs(accountingWithout('journal_entries.post'));
    Livewire::test(EditJournalEntry::class, ['record' => $entry->getRouteKey()])
        ->assertActionHidden('post');
});

it('expense cancel_expense is gated by expenses.edit (also the edit-page gate)', function () {
    $expense = Expense::create([
        'asset_id' => $this->asset->id, 'category' => 'admin', 'amount' => 500, 'vat_amount' => 0,
        'total' => 500, 'paid_from' => 'cash', 'expense_date' => now()->toDateString(), 'status' => 'recorded',
    ]);

    // cancel_expense checks expenses.edit, which is also the edit-page gate — so a user
    // lacking it can't even open the page. Assert the permitted user sees it, and the
    // action now carries an explicit ->authorize() (defense-in-depth) confirmed in code.
    $this->actingAs(makeUser('accounting'));
    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->assertActionVisible('cancel_expense');
});
