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
    // Flattened: `thread` and `quotes` are LISTS, because a newline inside one `TextEntry` is
    // collapsed by the browser and the whole conversation would render as a run-on paragraph.
    return collect(JobBrief::factsOf($job))
        ->flatMap(fn ($v) => is_array($v) ? $v : [$v])
        ->implode(' | ');
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
        ->and($text)->not->toContain('switching supplier')
        // …and it says WHICH SIDE spoke. A bare "Mona" leaves a contractor unable to tell their own
        // colleague from a mall employee, which is the one thing a byline on a two-party thread is
        // for; the operator's thread already labels both sides.
        ->and($text)->toContain('Mona ('.__('vendor.jobs.brief.from_operator').')');
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

it('never shows a RIVAL contractor s quote on the same job', function () {
    // **A job legitimately carries quotes from more than one contractor.**
    // `WorkOrderProposalService::submit()` defaults the vendor to the one on the job and lets the
    // operator state another — *"a quote from somebody else is legitimate"* — the admin relation
    // manager offers a free vendor picker, and re-dispatching a job leaves the previous
    // contractor's decided quotes behind. So the losing bidder read the winner's number and the
    // operator's reason for choosing them, under a heading saying "Your quotes".
    //
    // `decision_reason` is exactly where competitive information lives, which the fixture above
    // demonstrates: *"Second quote came in lower — going with the other contractor."*
    WorkOrderProposal::create([
        'facility_work_order_id' => $this->job->id,
        'vendor_id' => $this->mine->id,
        'status' => WorkOrderProposal::STATUS_SUBMITTED,
        'labour_amount' => 8000, 'material_amount' => 4000, 'service_amount' => 0,
        'total_amount' => 12000,
        'scope' => 'Replace the compressor',
        'submitted_by_vendor_contact_id' => $this->contact->id,
        'submitted_at' => now(),
    ]);

    WorkOrderProposal::create([
        'facility_work_order_id' => $this->job->id,
        'vendor_id' => $this->theirs->id,
        'status' => WorkOrderProposal::STATUS_APPROVED,
        'labour_amount' => 6000, 'material_amount' => 3500, 'service_amount' => 0,
        'total_amount' => 9500,
        'scope' => 'Replace the compressor',
        'decision_reason' => 'Cheaper and they can start tomorrow.',
        'submitted_at' => now(),
    ]);

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    $text = briefText($this->job->fresh());

    // The control and the refusal together: a scope that showed nothing would satisfy the second
    // assertion on its own and silently take the feature away.
    expect($text)->toContain('12,000.00')
        ->and($text)->not->toContain('9,500.00')
        ->and($text)->not->toContain('Cheaper and they can start tomorrow.');
});

it('says nothing about a spending limit when none is set', function () {
    // A row reading "—" says *no limit stated* on exactly the field where silence must not be read
    // as freedom, so the section is not rendered at all.
    $this->job->update(['nte_amount' => null]);

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    $sections = collect(JobBrief::of($this->job->fresh()))
        ->filter(fn ($section): bool => $section->isVisible());

    expect($sections->pluck('heading')->filter()->implode(' | '))
        ->not->toContain(__('vendor.jobs.brief.limit'));

    // The control: with a limit set, the section IS offered.
    $this->job->update(['nte_amount' => 15000]);

    expect(collect(JobBrief::of($this->job->fresh()))
        ->filter(fn ($section): bool => $section->isVisible())
        ->count())->toBeGreaterThan($sections->count());
});

it('renders the thread as SEPARATE items, not one collapsed paragraph', function () {
    // A single-item `TextEntry` renders as `e($state)` in a bare div, and neither Filament's
    // stylesheet nor this theme sets `white-space` on it — so a newline-joined blob collapses and
    // the whole conversation reads as one run-on line, byline running into body and message into
    // message. The state has to be a LIST.
    $staff = User::factory()->create(['name' => 'Mona']);

    app(CommentOnWorkOrderService::class)->comment($this->job, $staff, 'First message.', isInternal: false);
    app(CommentOnWorkOrderService::class)->comment($this->job, $staff, 'Second message.', isInternal: false);

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    $thread = JobBrief::factsOf($this->job->fresh())['thread'];

    expect($thread)->toBeArray()->toHaveCount(2)
        ->and($thread[0])->toContain('First message.')
        ->and($thread[1])->toContain('Second message.');

    // …and the ENTRY has to be told to render them as separate items. A list handed to a plain
    // `TextEntry` is imploded back into one string, which is the collapsed paragraph again — so the
    // shape of the data alone proves nothing. `isListWithLineBreaks()` is readable without a mounted
    // container, unlike `getState()`.
    // Read through reflection, not `getChildSchema()`: a schema component outside a mounted
    // container throws on `$container` before it answers anything — the same trap the file's
    // opening docblock records for `getState()`.
    $childrenOf = function ($section): array {
        $property = new ReflectionProperty($section, 'childComponents');
        $property->setAccessible(true);
        $children = $property->getValue($section);

        return is_array($children) ? \Illuminate\Support\Arr::flatten($children) : [];
    };

    $entries = collect(JobBrief::of($this->job->fresh()))->flatMap($childrenOf);

    foreach (['thread', 'quotes'] as $name) {
        $entry = $entries->firstWhere(fn ($c) => method_exists($c, 'getName') && $c->getName() === $name);

        expect($entry)->not->toBeNull("the {$name} entry is missing")
            ->and($entry->isListWithLineBreaks())->toBeTrue(
                "the {$name} entry renders as one collapsed paragraph");
    }
});
