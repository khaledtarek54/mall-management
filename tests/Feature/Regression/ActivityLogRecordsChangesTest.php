<?php

use App\Support\MorphMap;
use App\Models\Vendor;
use App\Support\ActivityLogChangeRenderer;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit log records WHAT changed, not merely that something did.
 *
 * WHY THIS EXISTS. `activity_log.properties` reads `[]` on every row, and I once concluded from
 * that the audit trail was decorative — repo-wide, on a system whose log is meant to defend against
 * a tenant dispute. It was a false alarm: **spatie v5 moved the before/after diff out of
 * `properties` into its own `attribute_changes` column** (`ActivityLogger::withChanges()` writes
 * `attribute_changes`; `withProperties()` writes `properties`). `properties` is now only the
 * custom-properties bucket, and empty is correct.
 *
 * This pins the real behaviour so the next person to notice the empty column finds an answer here
 * instead of re-deriving the wrong one — and so a future upgrade that genuinely breaks the diff
 * fails CI instead of passing quietly.
 */
it('records the before and after of a change, in attribute_changes', function () {
    $vendor = Vendor::create(['name' => 'ProbeCo', 'category' => 'hvac', 'status' => 'active']);
    $vendor->update(['name' => 'ProbeCo Renamed']);

    $updated = Activity::where('subject_type', MorphMap::alias(Vendor::class))
        ->where('subject_id', $vendor->id)->where('event', 'updated')->sole();

    // The diff lives here...
    expect($updated->attribute_changes['attributes']['name'])->toBe('ProbeCo Renamed');
    expect($updated->attribute_changes['old']['name'])->toBe('ProbeCo');

    // ...and NOT here. An empty `properties` is correct in v5, not a broken trail.
    expect($updated->properties->toArray())->toBe([]);
});

it('renders a change into something a human can read', function () {
    // The trail is only a control if someone can actually read it in the UI.
    $vendor = Vendor::create(['name' => 'ProbeCo', 'category' => 'hvac', 'status' => 'active']);
    $vendor->update(['status' => 'inactive']);

    $updated = Activity::where('subject_type', MorphMap::alias(Vendor::class))
        ->where('subject_id', $vendor->id)->where('event', 'updated')->sole();

    $rendered = strip_tags((string) app(ActivityLogChangeRenderer::class)->render($updated));

    // "Readable" now means the CATALOGUE label, not the stored token. `status` resolves through
    // `admin.statuses.{log_name}` (App\Support\ActivityVocabulary), so the operator reads
    // "Inactive" / «غير نشط» — and the raw `inactive` must NOT survive into the cell, because in
    // Arabic that is an English word sitting mid-sentence.
    expect($rendered)
        ->toContain(__('admin.statuses.vendor.inactive'))
        ->not->toContain('inactive');
});
