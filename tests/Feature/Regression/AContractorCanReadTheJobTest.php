<?php

/**
 * **The vendor portal shipped four verbs and no way to READ anything.**
 *
 * Two consequences, both of them the point of having the portal at all.
 *
 * **The thread was write-only.** A contractor could post an update and could never read one.
 * `FacilityWorkOrderComment::is_internal` exists precisely so an operator can write something the
 * contractor must not see, which means every PUBLIC comment on a dispatched job was written FOR a
 * contractor who had no surface to read it on. The operator ticked "share with the contractor" and
 * it reached nobody; the answer came back on WhatsApp, which is the behaviour the portal replaces.
 *
 * **The quote loop was one-way.** `nte_amount` is the not-to-exceed the operator sets, and exceeding
 * it is the whole reason a contractor is asked for a price — invisible, so the trigger for the act
 * was hidden from the person expected to perform it. And the DECISION never came back: a quote was
 * approved or declined, `decision_reason` recorded, and the contractor learnt which by being
 * dispatched or not.
 *
 * Every refusal here is paired with a control that must SUCCEED, because a brief that rendered
 * nothing would satisfy the refusals on its own and read as a pass — the trap the portal's own
 * scoping test records.
 */

use App\Filament\Vendor\Resources\WorkOrders\JobBrief;
use App\Filament\Vendor\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Models\FacilityWorkOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContact;
use App\Models\WorkOrderProposal;
use App\Services\CommentOnWorkOrderService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    $this->mine = Vendor::create(['name' => 'Cool Air Co', 'status' => Vendor::STATUS_ACTIVE]);
    $this->theirs = Vendor::create(['name' => 'Rival Mechanical', 'status' => Vendor::STATUS_ACTIVE]);

    $this->contact = VendorContact::create([
        'vendor_id' => $this->mine->id,
        'name' => 'Hani',
        'email' => 'hani@coolair.test',
        'password' => 'secret-secret',
        'is_portal_user' => true,
    ]);

    $this->job = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'external',
        'vendor_id' => $this->mine->id,
        'title' => 'Fix chiller',
        'description' => 'Chiller down on the second floor',
        'trade_id' => tradeId('hvac'),
        'priority' => 'urgent',
        'scheduled_for' => '2026-07-01',
        'status' => 'open',
        'nte_amount' => 15000,
    ]);
});

/**
 * Everything the contractor is told, as one string.
 *
 * Read from `JobBrief::factsOf()` rather than by walking `of()` and calling `getState()` on the
 * entries: a schema component outside a mounted container throws on `$container` before it answers
 * anything — the trap `getHelperText()` and `Repeater::getLabel()` set — and Filament assembles the
 * modal's HTML lazily, so neither is assertable. The modal is mounted separately below, because a
 * schema is built ON MOUNT and a page can render perfectly and fatal the moment somebody clicks.
 */
function briefText(FacilityWorkOrder $job): string
{
    return implode(' | ', JobBrief::factsOf($job));
}

it('shows the contractor the public thread, and never the internal notes', function () {
    $staff = User::factory()->create(['name' => 'Mona']);

    app(CommentOnWorkOrderService::class)->comment(
        $this->job, $staff, 'Access is arranged with the tenant for Sunday 09:00.', isInternal: false);

    app(CommentOnWorkOrderService::class)->comment(
        $this->job, $staff, 'Do not tell them we are switching supplier next quarter.', isInternal: true);

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    // The control and the refusal together: a filter that hid everything would pass the second
    // assertion on its own.
    $text = briefText($this->job->fresh());

    expect($text)->toContain('Access is arranged with the tenant for Sunday 09:00.')
        ->and($text)->not->toContain('switching supplier');
});

it('shows the contractor the spending limit they are meant to quote against', function () {
    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    expect(briefText($this->job))->toContain('15,000.00');
});

it('brings the operator s DECISION on a quote back to the contractor', function () {
    WorkOrderProposal::create([
        'facility_work_order_id' => $this->job->id,
        'vendor_id' => $this->mine->id,
        'status' => WorkOrderProposal::STATUS_REJECTED,
        'labour_amount' => 8000, 'material_amount' => 4000, 'service_amount' => 0,
        'total_amount' => 12000,
        'scope' => 'Replace the compressor',
        'decision_reason' => 'Second quote came in lower — going with the other contractor.',
        'submitted_by_vendor_contact_id' => $this->contact->id,
        'submitted_at' => now(),
    ]);

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    $text = briefText($this->job->fresh());

    expect($text)->toContain('12,000.00')
        ->and($text)->toContain('Declined')
        // The reason is the only part a contractor can ACT on. A rejection with no reason is a
        // decision they cannot answer, and re-quoting blind is what the loop exists to avoid.
        ->and($text)->toContain('Second quote came in lower');
});

it('refuses to brief a contractor on somebody else s job', function () {
    $notMine = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => 'cm', 'execution_type' => 'external',
        'vendor_id' => $this->theirs->id,
        'title' => 'Rival job', 'description' => 'Their chiller', 'trade_id' => tradeId('hvac'),
        'priority' => 'medium', 'scheduled_for' => '2026-07-01', 'status' => 'open',
    ]);

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    // The control first: their own job briefs fine.
    expect(fn () => JobBrief::of($this->job))->not->toThrow(HttpException::class);

    // 404, never 403 — a 403 confirms the job exists, which is the portal's one rule. BOTH public
    // entry points: `factsOf()` is the test seam and is reachable on its own, so a gate only on
    // `of()` would leave the facts readable by whoever called the seam directly.
    foreach ([fn () => JobBrief::of($notMine), fn () => JobBrief::factsOf($notMine)] as $call) {
        try {
            $call();
            $this->fail('a contractor was briefed on another company\'s job');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(404);
        }
    }
});

it('shows the job itself — the description a contractor is being sent to fix', function () {
    // The plainest thing the portal did not show. The list carried a reference, a title and a date;
    // WHAT IS WRONG and WHERE were both only in the dispatch email.
    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    $text = briefText($this->job);

    expect($text)->toContain('Chiller down on the second floor')
        ->and($text)->toContain($this->asset->name);
});

it('builds the modal when the row is actually clicked', function () {
    // The other half, and not a formality: a Filament action's schema is built ON MOUNT, so a page
    // can render perfectly and fatal the moment somebody opens the modal — which is exactly what a
    // test that only reads the facts would miss.
    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    Livewire::test(ListWorkOrders::class)
        ->mountAction(TestAction::make('brief')->table($this->job))
        ->assertHasNoActionErrors();
});
