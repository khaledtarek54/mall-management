<?php

/*
|--------------------------------------------------------------------------
| The cheque form cannot accept another mall's invoice (SW-014)
|--------------------------------------------------------------------------
| Filament validates a Select by asking it to LABEL the submitted value and refuses with
| `Rule::in([])` what it cannot — so a `getOptionLabelUsing` override IS the write guard, and a bare
| `Invoice::find($value)` deletes it: the OPTIONS are scoped to the property, but the options are a
| convenience and the payload is a Livewire payload, so a crafted request naming another mall's
| invoice id was labelled cleanly and accepted. A cheque for Mall A linked to Mall B's invoice is
| the cross-property AR/GL leak the query beside it names in writing.
|
| The payment form's identical picker was already scoped — the row named two doors and one had been
| fixed; this closes the survivor. Scoped by PROPERTY only, never by status or balance, because a
| cleared cheque's invoice is legitimately absent from the options and must still label on the edit
| page — the reason the resolver exists at all.
*/

use App\Models\Invoice;
use App\Support\TenantScope;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->mine = makeAsset(['code' => 'PDA']);
    $this->other = makeAsset(['code' => 'PDB']);

    // Pinned to ONE mall — an unrestricted operator sees everything, and the guard under test
    // narrows by what the OPERATOR may see.
    $this->actingAs(makeUser('manager', [$this->mine->id]));

    $this->myInvoice = makeInvoice(makeLease(makeUnit($this->mine)), ['status' => 'issued']);
    $this->foreignInvoice = makeInvoice(makeLease(makeUnit($this->other)), ['status' => 'issued']);
});

/** The label resolver the form installs — the layer Filament's validation actually calls. */
function chequeInvoiceLabel(int $invoiceId): ?string
{
    $visible = TenantScope::visibleAssetIds();

    return Invoice::query()
        ->when($visible !== null, fn ($q) => $q->whereIn('asset_id', $visible))
        ->find($invoiceId)?->number;
}

it('labels an invoice in the operator’s own mall — the control', function () {
    // No tenant needs selecting: the operator is PINNED to one mall through the assignment field,
    // and `visibleAssetIds()` narrows on the assignment — which is the production shape.
    expect(chequeInvoiceLabel($this->myInvoice->id))->toBe($this->myInvoice->number);
});

it('cannot label another mall’s invoice, so validation refuses it', function () {
    // Null is the refusal: Filament turns an unlabelable value into Rule::in([]).
    expect(chequeInvoiceLabel($this->foreignInvoice->id))->toBeNull();
});

it('the form source really routes through the scope — the wiring, not just the rule', function () {
    // The two assertions above prove the RULE; this proves the form still applies it. A later edit
    // that reverts to a bare find() leaves them green, so the source is swept for the shape.
    $source = file_get_contents(app_path('Filament/Admin/Resources/PostDatedCheques/Schemas/PostDatedChequeForm.php'));

    expect($source)->toContain('TenantScope::visibleAssetIds()')
        ->and(preg_match('/getOptionLabelUsing\(fn \(\$value\)[^}]*Invoice::find\(/s', $source))->toBe(0);
});
