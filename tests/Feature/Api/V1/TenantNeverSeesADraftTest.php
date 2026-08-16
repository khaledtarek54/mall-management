<?php

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\TenantStatementPdfService;
use App\Support\TenantVisibility;
use App\Support\ValueSets;
use Illuminate\Support\Facades\Event;

/**
 * A draft belongs to the operator, not to the tenant.
 *
 * `invoices.status` and `credit_notes.status` both DEFAULT to `'draft'` at the column, so a draft
 * is not an exotic state — it is what any create that doesn't set the status explicitly produces.
 * It leaked on every tenant-facing surface at once: list, show, invoice PDF, the Statement of
 * Account, both payment initiations and the portal table. `?status=draft` even let a tenant
 * enumerate them directly.
 *
 * **Every case here pairs the refusal with a control that must succeed.** A scope that hid
 * everything would satisfy the refusals alone, and read as a pass.
 */
function draftAndIssuedInvoices($tenant): array
{
    $lease = makeLease(makeUnit(makeAsset()), $tenant);

    return [
        makeInvoice($lease, ['status' => 'draft']),
        makeInvoice($lease, ['status' => 'issued']),
    ];
}

function draftAndIssuedCreditNotes($tenant): array
{
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    $invoice = makeInvoice($lease, ['status' => 'issued']);

    $make = fn (string $status) => CreditNote::create([
        'tenant_id' => $tenant->id,
        'lease_id' => $lease->id,
        'invoice_id' => $invoice->id,
        'status' => $status,
        'issue_date' => now(),
        'reason' => 'adjustment',
        'subtotal' => 100,
        'vat_amount' => 0,
        'total' => 100,
        'applied_amount' => 0,
        'balance' => 100,
        'currency' => 'EGP',
    ]);

    return [$make('draft'), $make('issued')];
}

it('does not list a draft invoice, but does list an issued one', function () {
    $tenant = makeTenant();
    [$draft, $issued] = draftAndIssuedInvoices($tenant);

    $ids = collect($this->getJson('/api/v1/me/invoices', apiHeaders($tenant))
        ->assertOk()->json('data'))->pluck('id');

    expect($ids)->not->toContain($draft->id)
        ->and($ids)->toContain($issued->id);
});

it('will not let a tenant ask for drafts by name', function () {
    $tenant = makeTenant();
    [$draft] = draftAndIssuedInvoices($tenant);

    // The filter runs INSIDE the scope, so naming the hidden status returns nothing rather
    // than bypassing it.
    $ids = collect($this->getJson('/api/v1/me/invoices?status=draft', apiHeaders($tenant))
        ->assertOk()->json('data'))->pluck('id');

    expect($ids)->not->toContain($draft->id)->and($ids)->toBeEmpty();
});

it('404s a draft invoice by id, and serves an issued one', function () {
    $tenant = makeTenant();
    [$draft, $issued] = draftAndIssuedInvoices($tenant);

    $this->getJson("/api/v1/me/invoices/{$draft->id}", apiHeaders($tenant))->assertNotFound();
    $this->getJson("/api/v1/me/invoices/{$issued->id}", apiHeaders($tenant))->assertOk();
});

it('refuses the PDF of a draft invoice, and renders one for an issued invoice', function () {
    $tenant = makeTenant();
    [$draft, $issued] = draftAndIssuedInvoices($tenant);

    $this->getJson("/api/v1/me/invoices/{$draft->id}/pdf", apiHeaders($tenant))->assertNotFound();
    $this->get("/api/v1/me/invoices/{$issued->id}/pdf", apiHeaders($tenant))->assertOk();
});

it('keeps a draft off the statement of account', function () {
    $tenant = makeTenant();
    [$draft, $issued] = draftAndIssuedInvoices($tenant);

    // The statement is an accounting document — the worst place for a number that was never
    // raised. `build()` returns a compressed PDF, so grepping its bytes would prove nothing
    // either way; capture what the view is actually handed instead.
    $data = null;
    Event::listen('composing: tenants.statement', function ($view) use (&$data) {
        $data = $view->getData();
    });

    app(TenantStatementPdfService::class)->build($tenant);

    expect($data)->toBeArray();  // null here means the statement view never rendered

    $numbers = collect($data['openInvoices'])
        ->merge($data['recentInvoices'])
        ->pluck('number');

    expect($numbers)->not->toContain($draft->number)
        ->and($numbers)->toContain($issued->number);
});

it('refuses the demo payment shortcut against a draft invoice, but allows an issued one', function () {
    // Both flags stated explicitly: `DemoPayments::enabled()` requires the gateway to be OFF, and
    // without setting them the endpoint 409s at an earlier guard and never reaches the one under
    // test — which is a green test proving nothing.
    config(['integrations.paymob.enabled' => false, 'integrations.demo_payments.enabled' => true]);

    $tenant = makeTenant();
    [$draft, $issued] = draftAndIssuedInvoices($tenant);

    $this->postJson("/api/v1/me/invoices/{$draft->id}/pay-demo", [], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonPath('error', 'invoice_not_payable');

    // The control: the same call on an issued invoice must NOT be refused for that reason.
    $response = $this->postJson("/api/v1/me/invoices/{$issued->id}/pay-demo", [], apiHeaders($tenant));

    expect($response->json('error'))->not->toBe('invoice_not_payable');
});

it('refuses a Paymob session against a draft invoice, but allows an issued one', function () {
    // The other money door. Both are gated separately, so both are tested separately.
    config(['integrations.paymob.enabled' => true]);

    $tenant = makeTenant();
    [$draft, $issued] = draftAndIssuedInvoices($tenant);

    $this->postJson("/api/v1/me/invoices/{$draft->id}/paymob-session", [], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonPath('error', 'invoice_not_payable');

    $response = $this->postJson("/api/v1/me/invoices/{$issued->id}/paymob-session", [], apiHeaders($tenant));

    expect($response->json('error'))->not->toBe('invoice_not_payable');
});

it('does not list a draft credit note, but does list an issued one', function () {
    $tenant = makeTenant();
    [$draft, $issued] = draftAndIssuedCreditNotes($tenant);

    $ids = collect($this->getJson('/api/v1/me/credit-notes', apiHeaders($tenant))
        ->assertOk()->json('data'))->pluck('id');

    expect($ids)->not->toContain($draft->id)
        ->and($ids)->toContain($issued->id);
});

it('404s a draft credit note by id, and serves an issued one', function () {
    $tenant = makeTenant();
    [$draft, $issued] = draftAndIssuedCreditNotes($tenant);

    $this->getJson("/api/v1/me/credit-notes/{$draft->id}", apiHeaders($tenant))->assertNotFound();
    $this->getJson("/api/v1/me/credit-notes/{$issued->id}", apiHeaders($tenant))->assertOk();
});

// ============================================================================
// The registry itself
// ============================================================================

it('hides only statuses that really exist in the column value set', function () {
    // A typo'd status hides nothing and looks identical to a working guard — the same failure
    // mode DeletionPolicy's `blocked_by` relations are checked for.
    //
    // No message arguments: a Pest matcher's second argument is ANOTHER EXPECTED VALUE, not a
    // description, so `toContain($status, 'why this matters')` quietly asserts the array also
    // contains that sentence. Build the diagnostic into the compared value instead.
    foreach (TenantVisibility::HIDDEN as $table => $hidden) {
        $allowed = ValueSets::allowed($table, 'status');

        expect($allowed)->toBeArray();

        foreach ($hidden as $status) {
            expect("{$table}.{$status}")
                ->toBe($table.'.'.(in_array($status, $allowed, true) ? $status : 'NOT-A-REAL-STATUS'));
        }
    }
});

it('derives the visible set from the value set rather than listing it', function () {
    // A status added to ValueSets must become visible on its own. Listing the visible set by
    // hand is what silently drops a new status out of a tenant's history.
    $all = ValueSets::allowed('invoices', 'status');

    expect(TenantVisibility::visibleFor('invoices'))
        ->toEqualCanonicalizing(array_values(array_diff($all, ['draft'])));
});

it('leaves a model with no registered hidden statuses untouched', function () {
    // The scope must be inert where nothing is hidden, not accidentally empty.
    expect(TenantVisibility::hiddenFor('payments'))->toBe([]);
});

it('still reaches a row whose status is not in the value set at all', function () {
    // Excluding beats allowlisting: a legacy or imported status must still reach its tenant.
    // Losing a real document from someone's history is the worse failure.
    $tenant = makeTenant();
    [, $issued] = draftAndIssuedInvoices($tenant);

    Invoice::withoutEvents(fn () => $issued->forceFill(['status' => 'legacy_import'])->saveQuietly());

    $ids = collect($this->getJson('/api/v1/me/invoices', apiHeaders($tenant))
        ->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($issued->id);
});
