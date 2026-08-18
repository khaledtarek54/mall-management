<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\Invoice;
use App\Models\PostDatedCheque;
use App\Models\UnitOwnership;
use App\Services\Accounting\FiscalCalendar;
use App\Services\BillBouncedChequeFeeService;
use App\Services\PostDatedChequeService;
use App\Settings\BillingSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * GAP ANALYSIS, module 33 — the bounced-cheque fee could not be billed for any cheque the UI can
 * actually create.
 *
 * `BillBouncedChequeFeeService` refused when `$cheque->lease` was null, on the stated premise that
 * "a cheque always carries its own lease, so the property is never ambiguous here". It does not.
 * **Neither the create form nor the bulk-lodge action has a lease field**, and nothing derives
 * `lease_id` — the column is fillable and written only by `lodgeSeries()` from an optional key no
 * caller passes. So every cheque an operator can produce carries `lease_id = null`, and the fee was
 * unbillable in production.
 *
 * `BouncedChequeFeeTest` did not catch it because its fixture sets `'lease_id' => $lease->id`
 * directly — a column no form, service or seeder writes. That is the F-100 shape
 * [000-plan.md](docs/gap-analysis/000-plan.md) records verbatim: *ask of every fixture, could the
 * product actually produce this state?* Here it could not, and nine green assertions sat on top.
 *
 * This file builds cheques the way the UI does — asset, tenant, invoice, no lease — and covers the
 * owner-occupier too, since a unit owner has no lease by construction.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    app(BillingSettings::class)->nsf_fee_amount = 250.0;

    $this->asset = makeAsset(['code' => 'NSF']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A bounced cheque with exactly the columns the create form collects — note: no lease_id. */
function uiShapedCheque(int $tenantId, int $assetId, ?int $invoiceId): PostDatedCheque
{
    $cheque = PostDatedCheque::create([
        'reference' => 'PDC-'.fake()->unique()->numberBetween(1000, 99999),
        'asset_id' => $assetId,
        'tenant_id' => $tenantId,
        'invoice_id' => $invoiceId,
        'cheque_number' => (string) fake()->unique()->numberBetween(100000, 999999),
        'bank_name' => 'CIB',
        'amount' => 30000,
        'cheque_date' => now()->toDateString(),
        'received_date' => now()->subMonth()->toDateString(),
        'status' => PostDatedCheque::STATUS_DEPOSITED,
    ]);

    app(PostDatedChequeService::class)->bounce($cheque);

    return $cheque->fresh();
}

it('bills the fee for a LESSEE whose cheque was lodged through the form', function () {
    $lease = makeLease(makeUnit($this->asset, ['code' => 'NSF-1']), null, ['status' => 'active']);
    $invoice = makeInvoice($lease, ['status' => 'issued']);

    $fee = app(BillBouncedChequeFeeService::class)
        ->bill(uiShapedCheque($lease->tenant_id, $this->asset->id, $invoice->id));

    expect($fee)->toBeInstanceOf(Invoice::class)
        ->and((float) $fee->total)->toBe(250.0)
        ->and($fee->asset_id)->toBe($this->asset->id)
        ->and($fee->tenant_id)->toBe($lease->tenant_id);
});

it('bills the fee for an OWNER-OCCUPIER, who has no lease by construction', function () {
    $owner = makeTenant(['party_type' => PartyType::UnitOwner->value]);
    UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => makeUnit($this->asset, ['code' => 'NSF-2'])->id,
        'tenant_id' => $owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => now()->subYear()->toDateString(),
        'payment_terms_days' => 10,
    ]);

    $fee = app(BillBouncedChequeFeeService::class)
        ->bill(uiShapedCheque($owner->id, $this->asset->id, null));

    expect((float) $fee->total)->toBe(250.0)
        ->and($fee->tenant_id)->toBe($owner->id)
        ->and($fee->asset_id)->toBe($this->asset->id)
        ->and($fee->lease_id)->toBeNull()
        ->and($fee->unit_ownership_id)->not->toBeNull();
});

it('still refuses when the party holds no agreement in that property', function () {
    // The refusal must survive: with nothing to bill against, inventing an agreement is worse than
    // refusing. This is the control that stops the fix from being "always find something".
    $stranger = makeTenant();

    expect(fn () => app(BillBouncedChequeFeeService::class)
        ->bill(uiShapedCheque($stranger->id, $this->asset->id, null)))
        ->toThrow(DomainException::class);
});

it('does not bill twice for one bounce', function () {
    $lease = makeLease(makeUnit($this->asset, ['code' => 'NSF-3']), null, ['status' => 'active']);
    $cheque = uiShapedCheque($lease->tenant_id, $this->asset->id, null);

    $first = app(BillBouncedChequeFeeService::class)->bill($cheque);
    $second = app(BillBouncedChequeFeeService::class)->bill($cheque->fresh());

    expect($second->id)->toBe($first->id)
        ->and(Invoice::query()->where('tenant_id', $lease->tenant_id)->count())->toBe(1);
});
