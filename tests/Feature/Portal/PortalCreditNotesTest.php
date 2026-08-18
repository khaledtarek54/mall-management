<?php

/**
 * The tenant portal had no credit-note screen.
 *
 * `/api/v1/me/credit-notes` has served these per tenant for a long time, so the mobile app could
 * show a credit the portal could not — the same tenant, the same records, one renderer short. A
 * tenant whose invoice dropped by 12,000 with no explanation on any screen they could open had to
 * telephone to ask why.
 *
 * The screen is read-only, and the two narrowings on it answer DIFFERENT questions:
 * `where('tenant_id', …)` answers *whose row is this*, `visibleToTenant()` answers *has this been
 * raised at all*. `credit_notes.status` defaults to `draft` at the column, so a draft is what any
 * create that omits the status produces — which makes the second scope the normal case, not an
 * exotic one. Every refusal below is paired with a control that must succeed, because a screen
 * that showed nothing would satisfy the refusals alone.
 */

use App\Filament\Portal\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Portal\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Models\CreditNote;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant);
    $this->invoice = makeInvoice($this->lease);

    $this->other = makeTenant();
    $otherLease = makeLease(makeUnit($this->asset), $this->other);

    // Issued — the tenant's own, and the one that must appear.
    $this->mine = CreditNote::create([
        'tenant_id' => $this->tenant->id, 'invoice_id' => $this->invoice->id,
        'asset_id' => $this->asset->id, 'status' => 'issued', 'reason' => 'billing_error',
        'issue_date' => '2026-02-01', 'subtotal' => 1000, 'vat_amount' => 0,
        'total' => 1000, 'applied_amount' => 0, 'balance' => 1000, 'currency' => 'EGP',
    ]);

    // A DRAFT of the tenant's own — theirs, but not yet a document.
    $this->draft = CreditNote::create([
        'tenant_id' => $this->tenant->id, 'invoice_id' => $this->invoice->id,
        'asset_id' => $this->asset->id, 'status' => 'draft', 'reason' => 'billing_error',
        'issue_date' => '2026-02-02', 'subtotal' => 5000, 'vat_amount' => 0,
        'total' => 5000, 'applied_amount' => 0, 'balance' => 5000, 'currency' => 'EGP',
    ]);

    // Another tenant's issued credit note.
    $this->theirs = CreditNote::create([
        'tenant_id' => $this->other->id, 'invoice_id' => makeInvoice($otherLease)->id,
        'asset_id' => $this->asset->id, 'status' => 'issued', 'reason' => 'billing_error',
        'issue_date' => '2026-02-03', 'subtotal' => 7000, 'vat_amount' => 0,
        'total' => 7000, 'applied_amount' => 0, 'balance' => 7000, 'currency' => 'EGP',
    ]);

    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs(makeTenantUser($this->tenant), 'portal');
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('lists the tenant own issued credit notes', function () {
    // The control. Without it every refusal below passes on an empty screen.
    Livewire::test(ListCreditNotes::class)
        ->assertCanSeeTableRecords([$this->mine]);
});

it('never shows a draft, even though it belongs to this tenant', function () {
    // "Whose row is this?" and "has this been raised?" are two questions. Scoping by tenant_id
    // alone answers only the first, and the column DEFAULTS to draft.
    Livewire::test(ListCreditNotes::class)
        ->assertCanNotSeeTableRecords([$this->draft]);

    expect(CreditNoteResource::getEloquentQuery()->pluck('id')->all())
        ->not->toContain($this->draft->id);
});

it('never shows another tenant credit note', function () {
    Livewire::test(ListCreditNotes::class)
        ->assertCanNotSeeTableRecords([$this->theirs]);
});

it('refuses a draft by direct id, not merely by hiding it from the list', function () {
    // A hidden row that is still fetchable by URL is not scoped, it is merely not advertised.
    expect(CreditNoteResource::getEloquentQuery()->find($this->draft->id))->toBeNull()
        ->and(CreditNoteResource::getEloquentQuery()->find($this->theirs->id))->toBeNull()
        // …and the control: the tenant's own issued note IS reachable that way.
        ->and(CreditNoteResource::getEloquentQuery()->find($this->mine->id))->not->toBeNull();
});

it('is read-only — the tenant can never create, edit or delete one', function () {
    // A credit note is raised through CreditNoteService, which owns the GL entry and the un-apply
    // path. A write surface here would be a second way to move money, thinner than the first.
    expect(CreditNoteResource::canCreate())->toBeFalse()
        ->and(CreditNoteResource::canEdit($this->mine))->toBeFalse()
        ->and(CreditNoteResource::canDelete($this->mine))->toBeFalse();
});

it('offers no status filter option the tenant could never see', function () {
    // The filter is derived from ValueSets minus the hidden set. A hand-written list would drift,
    // and offering `draft` would put a control on the page that can only ever return nothing.
    $options = App\Support\TenantVisibility::visibleFor('credit_notes');

    expect($options)->not->toContain('draft')
        ->and($options)->toContain('issued')
        ->and($options)->toContain('applied');
});
