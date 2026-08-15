<?php

use App\Models\Vendor;
use App\Models\VendorDocument;

/**
 * Renewing a COI the correct way made the contractor permanently undispatchable.
 *
 * `Vendor::hasExpiredBlockingDocument()` and `scopeAssignable()` both asked *"does this vendor have
 * ANY lapsed insurance row?"*. A compliance file keeps its history — you upload the new certificate
 * and leave last year's on file as the record of what was in force then — so the lapsed row never
 * stops existing and the answer is yes forever. The vendor disappears from every picker,
 * `FacilityWorkOrder::saving()` refuses it, and the only escape is deleting the evidence.
 *
 * It also stopped the vendor's preventive-maintenance plans generating at all: `generateFor()`
 * wraps everything in one transaction, so the throw rolled back `advanceDue()` too and the plan
 * retried the same cycle every night, forever. A statutory lift round that silently never happens.
 *
 * The fix is one predicate — `current()` — reached through the SAME scope chain by the row question
 * and the set question, because a picker that offers a vendor the save guard then refuses is worse
 * than either half being wrong alone.
 */
function makeContractor(array $attrs = []): Vendor
{
    return Vendor::create(array_merge([
        'name' => 'Otis Lifts', 'type' => 'contractor', 'status' => Vendor::STATUS_ACTIVE,
    ], $attrs));
}

function coi(Vendor $vendor, ?string $expires, array $attrs = []): VendorDocument
{
    return VendorDocument::create(array_merge([
        'vendor_id' => $vendor->id,
        'type' => VendorDocument::TYPE_INSURANCE_COI,
        'expires_on' => $expires,
    ], $attrs));
}

it('treats a renewed vendor as dispatchable while keeping the lapsed certificate on file', function () {
    $vendor = makeContractor();
    coi($vendor, now()->subMonths(2)->toDateString());   // last year's, kept as the record
    coi($vendor, now()->addYear()->toDateString());      // this year's

    expect($vendor->fresh()->isDispatchable())->toBeTrue()
        ->and(Vendor::assignable()->pluck('id')->all())->toContain($vendor->id)
        // The evidence is still there. Compliance is a question about the current certificate, not
        // a reason to destroy the history that proves last year's cover existed.
        ->and($vendor->documents()->count())->toBe(2);
});

it('still refuses a vendor whose CURRENT certificate has lapsed — the paired control', function () {
    // The whole gate, and the reason it exists: an uninsured contractor on the mall floor is a
    // liability the operator carries. Renewing must clear the block; time passing must restore it.
    $vendor = makeContractor();
    coi($vendor, now()->subYears(2)->toDateString());
    coi($vendor, now()->subDay()->toDateString());       // the newest one has itself now lapsed

    expect($vendor->fresh()->isDispatchable())->toBeFalse()
        ->and(Vendor::assignable()->pluck('id')->all())->not->toContain($vendor->id);
});

it('asks the row question and the set question the same way', function () {
    // These two are one predicate. They drifted apart once by being written twice, and that is what
    // a vendor offered by the picker and refused by the save guard looks like.
    foreach ([[null, true], ['-2 days', false], ['+2 days', true]] as [$offset, $dispatchable]) {
        $vendor = makeContractor(['name' => 'V'.($offset ?? 'none')]);
        if ($offset !== null) {
            coi($vendor, now()->modify($offset)->format('Y-m-d'));
        }

        expect($vendor->fresh()->isDispatchable())->toBe($dispatchable)
            ->and(Vendor::assignable()->whereKey($vendor->id)->exists())->toBe($dispatchable);
    }
});

it('lets an open-ended certificate supersede a dated one', function () {
    // `hasExpired()` already treats a missing expiry as "never lapses", so a row that cannot lapse
    // must rank at least as current as one that can — otherwise recording an open-ended cover note
    // leaves the vendor blocked by the certificate it replaced.
    $vendor = makeContractor();
    coi($vendor, now()->subMonth()->toDateString());
    coi($vendor, null);

    expect($vendor->fresh()->isDispatchable())->toBeTrue();
});

it('breaks a same-day tie on the later-entered row, so exactly one document is current', function () {
    // Without a total order two rows can both be "current" and the answer depends on row order —
    // which is how a predicate starts giving different answers to the same question.
    $vendor = makeContractor();
    $first = coi($vendor, now()->addMonth()->toDateString());
    $second = coi($vendor, now()->addMonth()->toDateString());

    $current = $vendor->documents()->current()->pluck('id')->all();

    expect($current)->toBe([$second->id])
        ->and($first->fresh()->isSuperseded())->toBeTrue()
        ->and($second->fresh()->isSuperseded())->toBeFalse();
});

it('does not let a DELETED certificate supersede the one that was kept', function () {
    // The subquery is raw SQL, so the soft-delete global scope does not reach into it. A document
    // somebody removed must stop counting for both questions — otherwise deleting a mistaken
    // upload silently hides the real, lapsed certificate behind it.
    $vendor = makeContractor();
    coi($vendor, now()->subMonth()->toDateString());
    $mistake = coi($vendor, now()->addYear()->toDateString());
    $mistake->delete();

    expect($vendor->fresh()->isDispatchable())->toBeFalse()
        ->and(Vendor::assignable()->whereKey($vendor->id)->exists())->toBeFalse();
});

it('supersedes only within the same document type', function () {
    // A valid tax card says nothing about insurance. Ranking across types would clear a blocking
    // lapse with an unrelated piece of paper.
    $vendor = makeContractor();
    coi($vendor, now()->subMonth()->toDateString());
    VendorDocument::create([
        'vendor_id' => $vendor->id,
        'type' => VendorDocument::TYPE_TAX_CARD,
        'expires_on' => now()->addYears(3)->toDateString(),
    ]);

    expect($vendor->fresh()->isDispatchable())->toBeFalse();
});

it('stops chasing a certificate the vendor already renewed', function () {
    // The same defect in the nag channel: `needsAttention()` is the chase list behind
    // `vendors:scan-document-expiry`, the Action Required card and the table filter. History cannot
    // be renewed, so a superseded row makes an item that can never be cleared — and a nag nobody
    // can clear is a nag people learn to close.
    $vendor = makeContractor();
    coi($vendor, now()->subMonth()->toDateString());
    coi($vendor, now()->addYear()->toDateString());

    expect($vendor->documents()->needsAttention()->count())->toBe(0)
        ->and(Vendor::documentsNeedAttention()->whereKey($vendor->id)->exists())->toBeFalse();

    // Control: the chase must still fire on a current certificate approaching expiry. A refusal
    // test alone passes just as happily when the chase is broken outright.
    $lapsing = makeContractor(['name' => 'Lapsing Lifts']);
    coi($lapsing, now()->addDays(10)->toDateString());
    expect(Vendor::documentsNeedAttention()->whereKey($lapsing->id)->exists())->toBeTrue();
});

it('is not displaced by a back-dated certificate filed after the renewal', function () {
    // Why the rule is "the cover that runs longest", not "the row entered last". A compliance file
    // invites back-filling — somebody scans the 2019 certificate for completeness — and under
    // latest-entered-wins that upload would silently reintroduce the exact block this fixes.
    $vendor = makeContractor();
    coi($vendor, now()->addYear()->toDateString());
    coi($vendor, now()->subYears(5)->toDateString());   // filed later, in force earlier

    expect($vendor->fresh()->isDispatchable())->toBeTrue();
});
