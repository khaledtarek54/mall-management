<?php

use App\Models\AccountingPeriod;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * A part-owner must not be paid the WHOLE property net (gap analysis, module 32, F-A).
 *
 * `GenerateOwnerStatementRunService` weights each owner `ownership_percentage / Σ percentage`, so
 * the shares always sum to the full net. That is right when every owner is recorded — module 32's
 * stated v1 assumption of one owner at 100% — and wrong when they are not: one owner recorded at
 * 50% has Σ = 50, so their weight is 50/50 = 1 and they take 100% of the net. `net_distributable`
 * is then posted Dr owner_distributions / Cr due_to_owner and becomes the cap `DisbursementService`
 * pays against, so a half-owner is accrued — and payable — twice what they are owed.
 *
 * **The fix enforces the assumption instead of changing the arithmetic** (operator's call,
 * 2026-08-18). The split stays `pct / Σ pct` and no GL amount moves; what changes is that a run
 * whose ownership register does not total 100% cannot be FINALISED.
 *
 * **Guarded on the money path, not the form.** A 50/50 register cannot be built in one save — the
 * first co-owner would be refused for totalling 50 — so blocking data entry would make co-ownership
 * unenterable. The register stays freely editable, the relation manager shows the running total, and
 * finalise is what insists. Same shape and reason as the existing no-owner refusal beside it.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->post = app(JournalPostingService::class);
    $this->r = app(AccountResolver::class);
    $this->generate = app(GenerateOwnerStatementRunService::class);
    $this->finalise = app(FinaliseOwnerStatementRunService::class);
    $this->march = AccountingPeriod::forDate(CarbonImmutable::create(2026, 3, 15));
});

/** Post revenue + expense for a property inside March 2026. Named for this file — see the helper-uniqueness gate. */
function postPandLForShareTest($test, int $assetId, float $revenue, float $expense): void
{
    $test->post->post(['entry_date' => '2026-03-10', 'asset_id' => $assetId, 'lines' => [
        ['ledger_account_id' => $test->r->id('accounts_receivable'), 'debit' => $revenue, 'credit' => 0],
        ['ledger_account_id' => $test->r->id('rent_revenue'), 'debit' => 0, 'credit' => $revenue],
    ]]);
    $test->post->post(['entry_date' => '2026-03-12', 'asset_id' => $assetId, 'lines' => [
        ['ledger_account_id' => $test->r->id('salaries_expense'), 'debit' => $expense, 'credit' => 0],
        ['ledger_account_id' => $test->r->id('bank'), 'debit' => 0, 'credit' => $expense],
    ]]);
}

it('finalises a whole 100% ownership and gives that owner the net', function () {
    $asset = makeAsset();
    $asset->propertyOwners()->attach(makeUser('owner')->id, ['ownership_percentage' => 100]);
    postPandLForShareTest($this, $asset->id, 10000, 4000); // net 6000

    $run = $this->finalise->finalise(
        $this->generate->generate($asset, $this->march),
        makeUser('accounting', [$asset->id]),
    );

    // The control, and it must succeed — a guard that refused everything would satisfy the
    // refusals below on its own and read as a pass.
    expect($run->status)->toBe('finalised')
        ->and((float) $run->net_distributable)->toBe(6000.0)
        ->and((float) $run->statements->first()->owner_share)->toBe(6000.0);
});

it('refuses to finalise when one owner holds only half the property', function () {
    $asset = makeAsset();
    // Jawad holds half the mall; the other half belongs to a partner not recorded in Atriom.
    $asset->propertyOwners()->attach(makeUser('owner')->id, ['ownership_percentage' => 50]);
    postPandLForShareTest($this, $asset->id, 10000, 4000);

    $run = $this->generate->generate($asset, $this->march);

    expect(fn () => $this->finalise->finalise($run, makeUser('accounting', [$asset->id])))
        ->toThrow(DomainException::class);

    // Refused, not half-applied: the run is still a draft, so nothing posted to the GL and no
    // disbursement can be scheduled against an inflated share.
    expect($run->fresh()->status)->toBe('draft');
});

it('refuses two part-owners who do not add up to the whole property', function () {
    $asset = makeAsset();
    // 30% + 30% recorded; 40% belongs to owners not in the system.
    $asset->propertyOwners()->attach(makeUser('owner')->id, ['ownership_percentage' => 30]);
    $asset->propertyOwners()->attach(makeUser('owner')->id, ['ownership_percentage' => 30]);
    postPandLForShareTest($this, $asset->id, 10000, 4000);

    $run = $this->generate->generate($asset, $this->march);

    expect(fn () => $this->finalise->finalise($run, makeUser('accounting', [$asset->id])))
        ->toThrow(DomainException::class);
});

it('finalises genuine co-owners once the register accounts for the whole property', function () {
    $asset = makeAsset();
    $asset->propertyOwners()->attach(makeUser('owner')->id, ['ownership_percentage' => 60]);
    $asset->propertyOwners()->attach(makeUser('owner')->id, ['ownership_percentage' => 40]);
    postPandLForShareTest($this, $asset->id, 10000, 4000); // net 6000

    $run = $this->finalise->finalise(
        $this->generate->generate($asset, $this->march),
        makeUser('accounting', [$asset->id]),
    );

    // The second control — the guard must not block real co-ownership, only incomplete registers.
    expect($run->status)->toBe('finalised')
        ->and($run->statements->pluck('owner_share')->map(fn ($s) => (float) $s)->sort()->values()->all())
        ->toBe([2400.0, 3600.0]);
});

it('still keeps a draft generatable, because that is how the shortfall is discovered', function () {
    $asset = makeAsset();
    $asset->propertyOwners()->attach(makeUser('owner')->id, ['ownership_percentage' => 50]);
    postPandLForShareTest($this, $asset->id, 10000, 4000);

    // Same reasoning as the no-owner rule: generating the draft is how an operator finds out.
    expect($this->generate->generate($asset, $this->march)->status)->toBe('draft');
});
