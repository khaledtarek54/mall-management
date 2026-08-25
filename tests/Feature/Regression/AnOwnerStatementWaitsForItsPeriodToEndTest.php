<?php

/*
|--------------------------------------------------------------------------
| A statement cannot freeze a period that has not ended (2026-08-25)
|--------------------------------------------------------------------------
| Found by driving the module rather than by reading it. On the demo portfolio, a run for DECEMBER
| generated and FINALISED cleanly on 25 August — a frozen document, addressed to the owner, about a
| month that had not started. The August run finalised the same day, with six days of rent still to
| bill.
|
| Finalise re-reads the ledger and freezes the figures, and `net_distributable` posts as
| Dr owner_distributions / Cr due_to_owner — which becomes the cap every disbursement pays against.
| So freezing early does not merely misreport: the days that follow are money the owner is owed,
| accrued nowhere and payable up to a cap that already excludes them. Nothing on the screen says the
| period is unfinished, and the remedy (Revise) is a manual act somebody has to remember.
|
| Refused on the MONEY PATH with the draft left free — the shape the two existing refusals in this
| service already take, and for the same reason: generating a draft mid-month is how an operator
| sees where the period is heading.
|
| It is about the STATEMENT, not about paying. An interim payout to an owner is a disbursement,
| which has its own document, its own approval and its own cap.
*/

use App\Models\AccountingPeriod;
use App\Models\OwnerStatementRun;
use App\Services\Accounting\FiscalCalendar;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->actor = makeUser('super_admin', [$this->asset->id]);

    // The owner is resolved from the ownership TENURE, not from a column — a statement is
    // addressed to whoever held the property during the period. Without this the controls below
    // refuse for the no-owner reason and the guard under test is never reached, which is how the
    // first run of this file reported two passes it had not earned.
    $this->asset->propertyOwners()->attach($this->actor->id, [
        'ownership_percentage' => 100,
        'started_at' => now()->subYears(2)->toDateString(),
        'ended_at' => null,
    ]);
});

/** A period running `$start` → `$end`, and the run generated over it. */
function runOver($ctx, string $start, string $end): OwnerStatementRun
{
    // Use the period the fiscal calendar itself lays down, rather than inserting one: the table
    // carries a period number and a fiscal year, and a hand-built row is a shape the app never
    // produces. Only the END is moved, which is the one thing under test.
    app(FiscalCalendar::class)->ensureYear((int) substr($start, 0, 4));

    $period = AccountingPeriod::whereDate('starts_on', '<=', $start)
        ->whereDate('ends_on', '>=', $start)
        ->orderBy('starts_on')
        ->firstOrFail();

    $period->update(['ends_on' => $end, 'status' => 'open']);

    return app(GenerateOwnerStatementRunService::class)->generate($ctx->asset, $period);
}

it('refuses to finalise a period that is still running', function () {
    $run = runOver($this, now()->startOfMonth()->toDateString(), now()->addMonth()->toDateString());

    expect(fn () => app(FinaliseOwnerStatementRunService::class)->finalise($run, $this->actor))
        ->toThrow(DomainException::class);

    expect($run->fresh()->status)->toBe(OwnerStatementRun::STATUS_DRAFT);
});

it('refuses a period that has not even started', function () {
    // The clearest case, and the one that was measured: a December statement finalised in August.
    $run = runOver($this, now()->addMonths(3)->startOfMonth()->toDateString(), now()->addMonths(3)->endOfMonth()->toDateString());

    expect(fn () => app(FinaliseOwnerStatementRunService::class)->finalise($run, $this->actor))
        ->toThrow(DomainException::class);
});

it('still finalises a period that HAS ended', function () {
    // The control, and the assertion that matters most: a guard that refused everything would
    // satisfy both refusals above and make month-end impossible.
    $run = runOver($this, now()->subMonth()->startOfMonth()->toDateString(), now()->subMonth()->endOfMonth()->toDateString());

    app(FinaliseOwnerStatementRunService::class)->finalise($run, $this->actor);

    expect($run->fresh()->status)->toBe(OwnerStatementRun::STATUS_FINALISED);
});

it('finalises on the day the period ends, not the day after', function () {
    // The boundary is where an off-by-one would live, and it would land on the exact day every
    // operator runs month-end.
    $run = runOver($this, now()->startOfMonth()->toDateString(), now()->toDateString());

    app(FinaliseOwnerStatementRunService::class)->finalise($run, $this->actor);

    expect($run->fresh()->status)->toBe(OwnerStatementRun::STATUS_FINALISED);
});

it('leaves the DRAFT freely generatable mid-period', function () {
    // Seeing where the month is heading is the reason drafts exist, and the two refusals already
    // in this service both keep it.
    $run = runOver($this, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

    expect($run->status)->toBe(OwnerStatementRun::STATUS_DRAFT)
        ->and($run->statements()->count())->toBeGreaterThan(0);
});
