<?php

use App\Models\Invoice;
use Spatie\Activitylog\Models\Activity;

// The renderer helper lives in tests/Pest.php: ActivityLogVocabularyConformanceTest needs it
// too, and Pest parallelises per FILE — a helper declared here is invisible to a worker that
// loads only that file, and fatal to a worker that loads both.

it('renders a single-field update as old → new with proper HTML', function () {
    $a = new Activity;
    $a->attribute_changes = [
        'old' => ['status' => 'draft'],
        'attributes' => ['status' => 'issued'],
    ];

    $html = renderActivityChanges($a);

    expect($html)
        ->toContain('Status')                // humanised field name
        ->toContain('line-through')          // old value strikethrough
        ->toContain('draft')
        ->toContain('issued')
        ->toContain('text-success');         // new value highlight
});

it('renders a "created" diff (no old value) without the strikethrough form', function () {
    $a = new Activity;
    $a->attribute_changes = [
        'old' => [],
        'attributes' => ['number' => 'INV-001', 'total' => 1500],
    ];

    $html = renderActivityChanges($a);

    expect($html)
        ->toContain('Number')
        ->toContain('INV-001')
        ->toContain('Total')
        ->toContain('1500')
        ->not->toContain('line-through');    // no old → new arrow when created
});

it('renders the empty-value marker when old or new is null', function () {
    $a = new Activity;
    $a->attribute_changes = [
        'old' => ['eta_status' => null],
        'attributes' => ['eta_status' => 'valid'],
    ];

    $html = renderActivityChanges($a);

    expect($html)->toContain('valid');
    // Null old means we render as a "created"-style line, no strikethrough.
    expect($html)->not->toContain('line-through');
});

it('labels a catalogued field from the catalogue, and humanises only what has no key', function () {
    // The two rungs of ActivityVocabulary::field(). `paid_amount` is in `admin.fields` — the
    // same catalogue the FORMS label from — so the audit trail must call it what the form calls
    // it ("Paid"), not manufacture "Paid amount" from the column name. `eta_status` is in no
    // catalogue, so it falls to the humaniser, which still uppercases the acronym.
    $a = new Activity;
    $a->attribute_changes = [
        'old' => ['paid_amount' => 0, 'eta_status' => null],
        'attributes' => ['paid_amount' => 100, 'eta_status' => 'valid'],
    ];

    $html = renderActivityChanges($a);

    expect($html)
        ->toContain(__('admin.fields.paid_amount'))
        ->toContain('ETA status');
});

it('reads the same row in Arabic, down to the direction of the arrow', function () {
    // The whole point of resolving at read time: one stored row, two languages. Nothing about
    // the row changes here — only the locale.
    $a = new Activity;
    $a->log_name = 'invoice';
    $a->subject_type = Invoice::class;
    $a->attribute_changes = [
        'old' => ['status' => 'draft'],
        'attributes' => ['status' => 'issued'],
    ];

    app()->setLocale('en');
    expect(strip_tags(renderActivityChanges($a)))
        ->toContain(__('admin.statuses.invoice.issued'))
        ->toContain('→');

    app()->setLocale('ar');
    expect(strip_tags(renderActivityChanges($a)))
        ->toContain(__('admin.statuses.invoice.issued'))
        // `→` is not mirrored by the bidi algorithm, so in RTL it would point at the OLD value.
        ->toContain('←')
        ->not->toContain('→');

    app()->setLocale('en');
});

it('escapes HTML in user-supplied values to prevent XSS', function () {
    $a = new Activity;
    $a->attribute_changes = [
        'old' => ['name' => 'Acme'],
        'attributes' => ['name' => '<script>alert(1)</script>'],
    ];

    $html = renderActivityChanges($a);

    expect($html)
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});

it('returns the em-dash placeholder when there are no changes attached', function () {
    $a = new Activity;
    $a->attribute_changes = null;

    expect(renderActivityChanges($a))->toContain('—');
});

it('formats boolean and array values legibly', function () {
    $a = new Activity;
    $a->attribute_changes = [
        'old' => ['is_active' => true, 'metadata' => null],
        'attributes' => ['is_active' => false, 'metadata' => ['key' => 'val']],
    ];

    $html = renderActivityChanges($a);

    expect($html)
        ->toContain('yes')               // old true
        ->toContain('no')                // new false
        ->toContain('&quot;key&quot;');  // JSON-encoded array, html-escaped
});

/**
 * Coverage guard: every place that surfaces activity diffs in the UI
 * must route through ActivityLogChangeRenderer. If anyone copy-pastes
 * the old `$old[$field] ?? '∅'` formatter back in, this test should
 * fail because it'd duplicate the column state again.
 */
it('the standalone ActivityLog page and the resource-embedded ActivitiesRelationManager both use the shared renderer', function () {
    $page = file_get_contents(app_path('Filament/Admin/Pages/ActivityLog.php'));
    $rm = file_get_contents(app_path('Filament/Admin/RelationManagers/ActivitiesRelationManager.php'));

    foreach (['page' => $page, 'relation manager' => $rm] as $label => $source) {
        expect($source)
            ->toContain('ActivityLogChangeRenderer')
            ->not->toContain('∅')                // old empty-marker glyph
            ->not->toContain("'attribute_changes'"); // old binding that caused the duplicate render
    }
});
