<?php

use App\Filament\Admin\Pages\ActivityLog;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->page = new ActivityLog;
});

function renderChanges(Activity $activity): string
{
    $page = new ActivityLog;
    $ref = new ReflectionMethod($page, 'renderChanges');
    return $ref->invoke($page, $activity);
}

it('renders a single-field update as old → new with proper HTML', function () {
    $a = new Activity;
    $a->attribute_changes = [
        'old' => ['status' => 'draft'],
        'attributes' => ['status' => 'issued'],
    ];

    $html = renderChanges($a);

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

    $html = renderChanges($a);

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

    $html = renderChanges($a);

    expect($html)->toContain('valid');
    // Null old means we render as a "created"-style line, no strikethrough.
    expect($html)->not->toContain('line-through');
});

it('humanises snake_case field names and uppercases known acronyms', function () {
    $a = new Activity;
    $a->attribute_changes = [
        'old' => ['paid_amount' => 0, 'eta_status' => null],
        'attributes' => ['paid_amount' => 100, 'eta_status' => 'valid'],
    ];

    $html = renderChanges($a);

    expect($html)
        ->toContain('Paid amount')   // snake_case → Title Case first word
        ->toContain('ETA status');   // acronym mapping
});

it('escapes HTML in user-supplied values to prevent XSS', function () {
    $a = new Activity;
    $a->attribute_changes = [
        'old' => ['name' => 'Acme'],
        'attributes' => ['name' => '<script>alert(1)</script>'],
    ];

    $html = renderChanges($a);

    expect($html)
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});

it('returns the em-dash placeholder when there are no changes attached', function () {
    $a = new Activity;
    $a->attribute_changes = null;

    expect(renderChanges($a))->toContain('—');
});

it('formats boolean and array values legibly', function () {
    $a = new Activity;
    $a->attribute_changes = [
        'old' => ['is_active' => true, 'metadata' => null],
        'attributes' => ['is_active' => false, 'metadata' => ['key' => 'val']],
    ];

    $html = renderChanges($a);

    expect($html)
        ->toContain('yes')               // old true
        ->toContain('no')                // new false
        ->toContain('&quot;key&quot;');  // JSON-encoded array, html-escaped
});
