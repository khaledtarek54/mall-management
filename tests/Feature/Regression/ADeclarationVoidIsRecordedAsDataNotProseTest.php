<?php

use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Lang;
use Spatie\Activitylog\Models\Activity;

/**
 * SW-173 — **`voidLocked()` wrote a sentence into a column.**
 *
 * It composed `"Voided on {$stamp} by {$voidedBy->name}: {$reason}"` and stored the result in
 * `audit_notes`. That is English frozen into the row at write time: no lang edit can ever reach it,
 * and the Arabic-speaking accountant who has to account for a reversed overage reads half a
 * sentence in a language they did not choose. It is the shape `JournalNarrative` (EG-36) and
 * `LeaseEventNarrative` were both built to end — **a row stores DATA, never PROSE** — and the seam
 * for exactly this act already existed: `App\Support\ReversalReason`, which thirteen money
 * reversals use and which this one did not touch at all.
 *
 * So the column now keeps the OPERATOR'S OWN WORDS behind the house `[VOID]` marker (a token, not a
 * language), and the WHO and the WHEN go to `activity_log`, where they cannot be edited away by the
 * person who caused the reversal and where the description is a KEY that renders in the reader's
 * language.
 *
 * The actor is passed EXPLICITLY. `voidLocked($declaration, $voidedBy, $reason)` takes the operator
 * as an argument precisely because `auth()->user()` is not always the answer, and spatie defaults
 * the causer to the session — so `ReversalReason::record()` now takes the causer the caller named.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->operator = makeUser('manager', [$this->asset->id]);
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant, [
        'status' => 'active',
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 100000,
        'percentage_rent_rate' => 5,
    ]);
});

function voidableLockedDeclaration($ctx, ?string $auditNotes = null): TenantSalesDeclaration
{
    $declaration = TenantSalesDeclaration::create([
        'lease_id' => $ctx->lease->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'declared_sales' => 200000,
        'declared_at' => now(),
        'declared_by_type' => $ctx->tenant::class,
        'declared_by_id' => $ctx->tenant->id,
        'status' => 'submitted',
    ]);

    app(PercentageRentCalculationService::class)->lock($declaration, $ctx->operator, $auditNotes);

    return $declaration->fresh();
}

it('keeps the operator’s own words in the column and writes no English around them', function () {
    $declaration = voidableLockedDeclaration($this, 'Checked against the POS export.');

    app(PercentageRentCalculationService::class)
        ->voidLocked($declaration, $this->operator, 'Ledger error in tenant accounting');

    $notes = $declaration->fresh()->audit_notes;

    expect($notes)
        // What was already there survives — the append discipline `lock()` already follows.
        ->toContain('Checked against the POS export.')
        ->toContain('[VOID] Ledger error in tenant accounting')
        // …and the frozen sentence is gone. "Voided on" was the whole of it.
        ->not->toContain('Voided on')
        ->not->toContain($this->operator->name);
});

it('records who voided it, why, and under a key rather than a sentence', function () {
    $declaration = voidableLockedDeclaration($this);

    app(PercentageRentCalculationService::class)
        ->voidLocked($declaration, $this->operator, 'Tenant restated their figures');

    $row = Activity::query()
        ->where('log_name', 'tenant_sales')
        ->where('event', 'voided')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull()
        // A KEY. `ActivityVocabulary` turns it into words at READ time, in the reader's language,
        // and a wording fix reaches rows written years ago.
        ->and($row->description)->toBe('tenant_sales.voided')
        ->and($row->properties['reason'])->toBe('Tenant restated their figures')
        // The CALLER's actor, not the session's — there is no signed-in user in this test at all,
        // and an unattributed reversal is the audit question this trail exists to answer.
        ->and($row->causer_id)->toBe($this->operator->id);
});

it('resolves that key in English AND Arabic', function () {
    // The control the parity gate cannot give: `ReversalReason::record()` logs a VARIABLE
    // description, so the "descriptions are keys" sweep — which greps `->log('literal')` — never
    // sees this one. A missing key renders as the raw `tenant_sales.voided` on the audit trail.
    // `fallback: false`, or a key present only in English passes for Arabic.
    $missing = [];

    foreach (['en', 'ar'] as $locale) {
        if (! Lang::has('admin.activity.descriptions.tenant_sales.voided', $locale, fallback: false)) {
            $missing[] = $locale;
        }
    }

    expect($missing)->toBe([]);
});

it('still refuses to void anything that is not locked — the control', function () {
    // A change to how a void is RECORDED must not change what may be voided. Without this, a patch
    // that recorded beautifully on every call would look identical to one that recorded correctly.
    $sibling = TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
        'declared_sales' => 50000,
        'declared_at' => now(),
        'declared_by_type' => $this->tenant::class,
        'declared_by_id' => $this->tenant->id,
        'status' => 'submitted',
    ]);

    app(PercentageRentCalculationService::class)
        ->voidLocked($sibling, $this->operator, 'cannot void a submitted declaration');

    expect($sibling->fresh()->status)->toBe('submitted')
        ->and($sibling->fresh()->audit_notes)->toBeNull()
        ->and(Activity::query()->where('log_name', 'tenant_sales')->where('event', 'voided')->count())->toBe(0);
});
