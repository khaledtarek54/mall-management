<?php

use App\Filament\Admin\Resources\PurchaseRequests\Pages\EditPurchaseRequest;
use App\Filament\Admin\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Services\PurchaseRequestService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * SW-067 — **a refusal nobody can read is a refusal nobody can answer.**
 *
 * `PurchaseRequestService::reject()` and `::cancel()` REQUIRE a reason (the action's `Textarea` is
 * `->required()`) and `::approve()` invites a note. All three write it to
 * `purchase_requests.decision_notes`. At HEAD (e3154f27) `grep -rn decision_notes app/` returned
 * exactly those three writes plus the `ActivityLogging` registration — and NOT ONE read. So a
 * refused purchase showed a red *Rejected* badge and nothing else: not on the list, where the badge
 * column's `description()` fell through to the supplier name (a rejected request has none), not in
 * the View modal (this resource declares no infolist, so that modal renders the FORM, which carried
 * the property, the justification and the warehouse), and not on the Edit page. Measured on
 * `mall_management_qa`: PR-AW-202609-0001 stores "Approved — within the maintenance budget." and no
 * screen printed it.
 *
 * The buyer whose purchase was refused could not find out why, and the operator who refused it left
 * no answer anybody could point at — which is the whole content of an approval workflow.
 *
 * **Shown by the DATA, never by a status list.** `approve` writes an OPTIONAL note and `receive`
 * writes none, so `PurchaseRequest::TERMINAL` would be a second answer free to drift from the three
 * services that do the writing; `filled($record->decision_notes)` cannot.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    $this->asset = makeAsset(['code' => 'RSN']);
    $this->buyer = makeUser('super_admin', [$this->asset->id]);
    $this->decider = makeUser('super_admin', [$this->asset->id]);

    // `makeUser()` names every user after its role, so two super_admins are both "super_admin
    // user" — and the helper under the field prints WHO decided. Without a distinct name that
    // assertion would pass on the wrong person.
    $this->decider->forceFill(['name' => 'Nadia Fahmy'])->save();

    $this->actingAs($this->decider);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $this->svc = app(PurchaseRequestService::class);

    // Raised by somebody OTHER than the decider: `approve()` refuses self-approval by design.
    $this->raise = fn (): PurchaseRequest => $this->svc->request([
        'asset_id' => $this->asset->id,
        'justification' => 'Two replacement pumps for the basement sump',
        'lines' => [['description' => 'Sump pump', 'quantity' => 2, 'unit_cost' => 3000]],
    ], $this->buyer);

    $this->reason = 'The lift maintenance contract already covers these pumps';
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('names the reason a purchase was refused, on the register where the badge is read', function () {
    $request = ($this->raise)();
    $this->svc->reject($request, $this->reason);

    Livewire::test(ListPurchaseRequests::class)
        // The control: the row rendered at all, so a missing reason below is a missing reason and
        // not a page that never drew.
        ->assertCanSeeTableRecords([$request->fresh()])
        ->assertSee($this->reason);
});

it('shows the supplier AND the reason when an order is unwound after it went out', function () {
    // `ordered → cancelled` is in `PurchaseRequest::TRANSITIONS`, so ONE request can carry both
    // facts — and this is the case that decides the shape: neither may displace the other.
    $vendor = Vendor::create(['name' => 'Falcon Facilities', 'status' => Vendor::STATUS_ACTIVE]);

    $request = ($this->raise)();
    $this->svc->approve($request, null);
    $this->svc->order($request->fresh(), $vendor->id);
    $this->svc->cancel($request->fresh(), 'The pumps were repaired under warranty');

    Livewire::test(ListPurchaseRequests::class)
        ->assertCanSeeTableRecords([$request->fresh()])
        ->assertSee('Falcon Facilities')                  // what the column already carried
        ->assertSee('The pumps were repaired under warranty'); // and what it dropped
});

it('leaves a request nobody has decided reading exactly as it did', function () {
    $request = ($this->raise)();

    // No `approval_rules` are seeded, so `ApprovalPolicy::permissionFor()` answers null and
    // `tierLabel()` falls to `unknown`. The point is that the `requested` branch is untouched.
    Livewire::test(ListPurchaseRequests::class)
        ->assertCanSeeTableRecords([$request])
        ->assertSee(__('admin.procurement.awaiting', ['tier' => __('admin.procurement.tiers.unknown')]));
});

it('shows the reason on the record itself, with who refused it and when', function () {
    $request = ($this->raise)();
    $this->svc->reject($request, $this->reason);

    Livewire::test(EditPurchaseRequest::class, ['record' => $request->fresh()->getRouteKey()])
        // The ARRAY form. `assertFormSet(fn ($state) => …)` IGNORES what the closure returns, so it
        // passes against a form that never rendered the field at all.
        ->assertFormSet(['decision_notes' => $this->reason])
        ->assertSee('Nadia Fahmy')
        ->assertSee($request->fresh()->decided_at->toDateString());
});

it('does not offer a decision on a request nobody has decided', function () {
    $request = ($this->raise)();

    Livewire::test(EditPurchaseRequest::class, ['record' => $request->getRouteKey()])
        ->assertDontSee(__('admin.fields.decision_notes'))
        // The control: the form itself rendered, so the absence above is the `visible()` closure
        // and not a page that failed to draw.
        ->assertSee(__('admin.procurement.fields.justification'));
});

it('will not let that reason be rewritten through the form that shows it', function () {
    // CONTROL FIRST — the same fill-and-save on a field that IS writable must land, or the refusal
    // below would pass just as happily against a save that never ran.
    $open = ($this->raise)();

    Livewire::test(EditPurchaseRequest::class, ['record' => $open->getRouteKey()])
        ->fillForm(['justification' => 'Refined justification'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($open->fresh()->justification)->toBe('Refined justification');

    // THE REFUSAL. `decision_notes` is on `$fillable` and the model's `updating` guard freezes only
    // `asset_id`, `warehouse_id` and `justification` — so nothing but the field's own dehydration
    // stands between a crafted Livewire payload and the reason somebody's purchase was refused.
    $refused = ($this->raise)();
    $this->svc->reject($refused, $this->reason);

    Livewire::test(EditPurchaseRequest::class, ['record' => $refused->fresh()->getRouteKey()])
        ->fillForm(['decision_notes' => 'Approved after all'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($refused->fresh()->decision_notes)->toBe($this->reason);
});
