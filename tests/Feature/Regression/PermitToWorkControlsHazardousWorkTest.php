<?php

/*
|--------------------------------------------------------------------------
| A contractor could weld in a plant room with nothing on record (2026-08-19)
|--------------------------------------------------------------------------
| **Not a Yardi construct, and flagged as an extension rather than dressed as the standard.**
| Voyager is lease-administration software and models no safety permit — the benchmark folder has
| ZERO hits for hot work, isolation or permit-to-work. This follows the FM/CMMS standard
| (ServiceChannel, Facilio and Maximo all treat a permit as core) and ordinary safety practice, and
| the gap analysis rated it 🟠 because a mall operator is legally exposed without it.
|
| The two properties that make a permit a control rather than a form, and which these tests exist
| to pin:
|
|   1. it is bounded to the HOUR, and
|   2. it must be CLOSED OUT — an issued permit whose window has passed with no closure is the
|      finding, because nobody recorded that the work stopped and the area was made safe.
|
| The second is invisible on any screen that shows what exists, exactly like the post-dated cheque
| coverage gap: the missing thing is a closure that was never written.
*/

use App\Filament\Admin\Actions\WorkPermitActions;
use App\Filament\Admin\Resources\WorkPermits\WorkPermitResource;
use App\Models\Vendor;
use App\Models\WorkPermit;
use App\Notifications\WorkPermitOverdueNotification;
use App\Services\WorkPermitService;
use App\Support\Search\SearchText;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actor = makeUser('operations', [$this->asset->id]);
    $this->actingAs($this->actor);
    Notification::fake();
});

function permit($ctx, array $attrs = []): WorkPermit
{
    return WorkPermit::create(array_merge([
        'asset_id' => $ctx->asset->id,
        'type' => WorkPermit::TYPE_HOT_WORK,
        'description' => 'Welding a bracket in the roof plant room.',
        'conditions' => 'Fire watch present. Extinguisher on site. Gas test before start.',
        'valid_from' => CarbonImmutable::parse('2026-09-01 09:00'),
        'valid_to' => CarbonImmutable::parse('2026-09-01 13:00'),
    ], $attrs));
}

it('numbers a permit so it can be quoted at a gate', function () {
    expect(permit($this)->reference)->toStartWith('PTW-');
});

it('refuses a window that ends before it begins', function () {
    expect(fn () => permit($this, [
        'valid_from' => CarbonImmutable::parse('2026-09-01 13:00'),
        'valid_to' => CarbonImmutable::parse('2026-09-01 09:00'),
    ]))->toThrow(DomainException::class);
});

/**
 * **A permit IS its conditions.** Issued with none it is a note saying work happened, which is the
 * form-shaped version of this control — worth refusing at the moment of issue rather than letting
 * somebody discover later that the field was optional.
 */
it('refuses to issue a permit with no conditions', function () {
    $draft = permit($this, ['conditions' => null]);

    expect(fn () => app(WorkPermitService::class)->issue($draft))
        ->toThrow(DomainException::class);

    expect($draft->fresh()->status)->toBe(WorkPermit::STATUS_DRAFT);
});

/**
 * The permit must not become the one door left open. `FacilityWorkOrder::saving` already refuses to
 * dispatch a blacklisted vendor or one with a lapsed compliance document; a permit issued to the
 * same contractor would be that hazard with a signature on it, so it reuses the identical
 * predicate rather than re-testing the conditions.
 */
it('refuses to issue a permit to a contractor who cannot be dispatched', function () {
    $blacklisted = Vendor::create([
        'name' => 'Risky Contracting', 'legal_name' => 'Risky Contracting LLC',
        'status' => 'blacklisted',
    ]);

    $draft = permit($this, ['vendor_id' => $blacklisted->id]);

    expect(fn () => app(WorkPermitService::class)->issue($draft))
        ->toThrow(DomainException::class);
});

/** The control: a dispatchable contractor gets their permit. */
it('issues a permit to a contractor in good standing', function () {
    $vendor = Vendor::create([
        'name' => 'Delta FM', 'legal_name' => 'Delta FM LLC',
        'status' => 'active',
    ]);

    $issued = app(WorkPermitService::class)->issue(permit($this, ['vendor_id' => $vendor->id]));

    expect($issued->status)->toBe(WorkPermit::STATUS_ISSUED)
        // Who authorised it is the point of the record.
        ->and($issued->issued_by_user_id)->toBe($this->actor->id)
        ->and($issued->issued_at)->not->toBeNull();
});

/** A permit with no registered vendor is fine — a named individual is the record. */
it('issues a permit for a contractor who is not in the vendor register', function () {
    $issued = app(WorkPermitService::class)
        ->issue(permit($this, ['contractor_name' => 'Ahmed Fathy', 'contractor_phone' => '0100 000 0000']));

    expect($issued->status)->toBe(WorkPermit::STATUS_ISSUED);
});

/**
 * **Bounded to the hour.** "Permitted on Tuesday" is not a permit — a permit good for a whole day
 * is one somebody uses at 19:00 when the fire officer has gone home.
 */
it('authorises work only inside its window', function () {
    $issued = app(WorkPermitService::class)->issue(permit($this));

    expect($issued->isLive(CarbonImmutable::parse('2026-09-01 08:59')))->toBeFalse()
        ->and($issued->isLive(CarbonImmutable::parse('2026-09-01 09:00')))->toBeTrue()
        ->and($issued->isLive(CarbonImmutable::parse('2026-09-01 13:00')))->toBeTrue()
        ->and($issued->isLive(CarbonImmutable::parse('2026-09-01 13:01')))->toBeFalse();
});

/** A draft authorises nothing, whatever its window says. */
it('does not treat a draft as authorisation', function () {
    expect(permit($this)->isLive(CarbonImmutable::parse('2026-09-01 10:00')))->toBeFalse();
});

/**
 * **The finding.** An issued permit whose window has passed and which nobody closed means no one
 * recorded that the welding stopped and the area was checked.
 */
it('flags an issued permit that expired without being closed', function () {
    $issued = app(WorkPermitService::class)->issue(permit($this));
    $after = CarbonImmutable::parse('2026-09-02 09:00');

    expect($issued->hasLapsed($after))->toBeTrue()
        ->and(WorkPermit::query()->overdueClosure($after)->pluck('id')->all())->toBe([$issued->id]);
});

/** The control: a permit closed out on time is not a finding. */
it('does not flag a permit that was closed out', function () {
    $issued = app(WorkPermitService::class)->issue(permit($this));
    app(WorkPermitService::class)->close($issued, 'Work stopped, area cleared, extinguisher checked.');

    expect(WorkPermit::query()->overdueClosure(CarbonImmutable::parse('2026-09-02 09:00'))->count())->toBe(0);
});

/**
 * Closing LATE is deliberately allowed. Refusing it would leave the register permanently wrong
 * about a job that did finish safely, and would push people to CANCEL instead — destroying the
 * distinction between "closed late" and "never happened", which is the only thing an auditor asks.
 */
it('allows a late closure rather than forcing a cancellation', function () {
    $issued = app(WorkPermitService::class)->issue(permit($this));

    $closed = app(WorkPermitService::class)->close($issued, 'Closed the next morning; area inspected.');

    expect($closed->status)->toBe(WorkPermit::STATUS_CLOSED)
        ->and($closed->closure_notes)->toContain('inspected');
});

/** A closure with nothing written cannot show the area was left safe. */
it('refuses a closure with no note', function () {
    $issued = app(WorkPermitService::class)->issue(permit($this));

    expect(fn () => app(WorkPermitService::class)->close($issued, '   '))
        ->toThrow(DomainException::class);
});

/**
 * Cancelling is NOT closing. Closing says the work finished and the area is safe; cancelling says
 * it did not proceed under this authorisation. Collapsing the two would make the register unable to
 * answer the question it exists for.
 */
it('keeps cancellation distinct from closure', function () {
    $issued = app(WorkPermitService::class)->issue(permit($this));

    $cancelled = app(WorkPermitService::class)->cancel($issued, 'Contractor did not attend.');

    expect($cancelled->status)->toBe(WorkPermit::STATUS_CANCELLED)
        ->and(WorkPermit::query()->overdueClosure(CarbonImmutable::parse('2026-09-02 09:00'))->count())->toBe(0);
});

it('refuses to act on a permit that is already finished', function () {
    $issued = app(WorkPermitService::class)->issue(permit($this));
    app(WorkPermitService::class)->close($issued, 'Done.');

    expect(fn () => app(WorkPermitService::class)->close($issued->fresh(), 'Again.'))
        ->toThrow(DomainException::class);
    expect(fn () => app(WorkPermitService::class)->cancel($issued->fresh(), 'Too late.'))
        ->toThrow(DomainException::class);
});

/**
 * There is deliberately no `expired` STATUS. Expiry is a fact about the clock, not a decision, and
 * a sweep flipping permits to `expired` would quietly close the audit question this register exists
 * to ask. The scan reports; it must not write.
 */
it('reports overdue permits without altering them', function () {
    $issued = app(WorkPermitService::class)->issue(permit($this));

    $this->artisan('facility:scan-open-permits', ['--date' => '2026-09-02 09:00'])
        ->assertExitCode(0);

    expect($issued->fresh()->status)->toBe(WorkPermit::STATUS_ISSUED)
        ->and(WorkPermit::STATUSES)->not->toContain('expired');

    Notification::assertSentTimes(WorkPermitOverdueNotification::class, 1);
});

it('says nothing when every permit is closed out', function () {
    $issued = app(WorkPermitService::class)->issue(permit($this));
    app(WorkPermitService::class)->close($issued, 'Area checked.');

    $this->artisan('facility:scan-open-permits', ['--date' => '2026-09-02 09:00'])->assertExitCode(0);

    Notification::assertNothingSent();
});

/** Issuing is a right of its own — editing a draft is not the same act as authorising the work. */
it('separates issuing from editing', function () {
    expect($this->actor->can('work_permits.issue'))->toBeTrue()
        ->and(makeUser('technician', [$this->asset->id])->can('work_permits.issue'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Review pass — what the register SHOWS (2026-08-19)
|--------------------------------------------------------------------------
| The controls above were all green while the register could not show anybody a permit: Edit is
| draft-only (correctly — a live authorisation is not a draft), so from the moment a permit was
| issued there was nothing to click and no way to read the conditions it was issued under. A
| control whose terms cannot be read is a form again.
*/

/**
 * The conditions ARE the permit, and the person authorising the work must read them before they
 * accept the risk — not after, from a list, in a column that truncates.
 */
it('shows the conditions on the permit before it is authorised and after', function () {
    $draft = permit($this, ['conditions' => 'Fire watch for 60 minutes after the last cut.']);

    $abstract = (new ReflectionMethod(
        WorkPermitActions::class, 'abstractOf'
    ));
    $abstract->setAccessible(true);

    $states = collect($abstract->invoke(null, $draft))
        ->map(fn ($entry) => (string) $entry->getName().'='.json_encode($entry->getState()))
        ->implode(' | ');

    expect($states)->toContain('Fire watch for 60 minutes')
        // The window is stated to the hour on the panel too — a permit summary that says only the
        // date is the "permitted on Tuesday" failure with a nicer font.
        ->and($states)->toContain('09:00')
        ->and($states)->toContain('13:00');
});

/** A draft with no conditions says so in the abstract, rather than showing an empty box. */
it('names the missing conditions rather than rendering a blank', function () {
    $abstract = new ReflectionMethod(
        WorkPermitActions::class, 'abstractOf'
    );
    $abstract->setAccessible(true);

    $states = collect($abstract->invoke(null, permit($this, ['conditions' => null])))
        ->map(fn ($entry) => (string) $entry->getState())
        ->implode(' | ');

    expect($states)->toContain(__('admin.work_permits.no_conditions'));
});

/**
 * The finding has to be visible where the work is. The hourly mail is read once; a permit left
 * open is a state that persists, and the sidebar is what somebody looks at on the Monday.
 */
it('counts overdue permits on the navigation badge, scoped to the property', function () {
    $other = makeAsset();

    $mine = app(WorkPermitService::class)->issue(permit($this));
    app(WorkPermitService::class)->issue(permit($this, ['asset_id' => $other->id]));

    // Nothing is overdue while the window is still ahead.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 10:00'));
    expect(WorkPermitResource::getNavigationBadge())->toBeNull();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 09:00'));
    Date::setTestNow('2026-09-02 09:00');

    // The actor holds one property, so the badge counts that property's permit and not the other's.
    expect(WorkPermitResource::getNavigationBadge())->toBe('1')
        ->and($mine->fresh()->asset_id)->toBe($this->asset->id);

    CarbonImmutable::setTestNow();
    Date::setTestNow();
});

/**
 * A permit reference is quoted at a gate and read out on the radio — which is the moment somebody
 * types it into the search bar, from whatever screen they happen to be on.
 */
it('finds a permit from the global search bar, by reference and by contractor', function () {
    $issued = permit($this, ['contractor_name' => 'شركة دلتا للصيانة', 'location' => 'Roof plant room']);

    $blob = $issued->fresh()->search_text;

    expect($blob)->toContain(SearchText::normalize($issued->reference))
        // Folded on BOTH sides: the Arabic trade name is exactly what the fold exists for.
        ->and($blob)->toContain(SearchText::normalize('شركه دلتا'))
        ->and($blob)->toContain(SearchText::normalize('Roof plant room'));
});
