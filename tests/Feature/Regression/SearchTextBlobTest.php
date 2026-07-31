<?php

/*
|--------------------------------------------------------------------------
| The search blob is only useful if it is actually correct at the moment it is written
|--------------------------------------------------------------------------
| Each test here pins a trap that was found by building this, not one imagined afterwards. The
| first two are the ones that would have shipped a search bar that looked finished and could not
| find the two things operators type most.
*/

use App\Models\Concerns\HasSearchText;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\Violation;
use App\Services\Search\RebuildSearchIndex;
use App\Support\Search\SearchText;
use App\Support\SearchPolicy;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedRoles();
    $this->property = makeAsset(['name' => 'Atriom Walk', 'code' => 'AW']);
    $this->unit = makeUnit($this->property, ['code' => 'A-102']);
    $this->tenant = makeTenant(['name' => 'Zara']);
    $this->lease = makeLease($this->unit, $this->tenant);
});

it('includes a document number that is only assigned after the blob is first folded', function () {
    // THE trap. Eloquent fires saving → creating → INSERT, and `AllocatesDocumentNumber` assigns
    // `number` in `creating`. A saving-only hook therefore stores a blob with no invoice number in
    // it — on the model whose number is the single most-typed string in the system — and every
    // other numbered document (credit note, vendor bill, expense, journal entry, payroll, deposit
    // transaction) has exactly the same shape.
    //
    // Verified against the STORED row, not the in-memory instance: the in-memory object can look
    // right while the column holds the pre-insert fold.
    $invoice = makeInvoice($this->lease);

    $stored = DB::table('invoices')->where('id', $invoice->id)->value('search_text');

    expect($invoice->number)->not->toBeEmpty()
        ->and($stored)->toContain(SearchText::normalize($invoice->number));
});

it('includes an id-derived reference that cannot exist before the insert', function () {
    // `Violation::$reference` is the accessor 'VIO-'.str_pad($this->id) — there is no id at
    // `saving` time, so the first fold would bake in VIO-00000 forever. That reference is the only
    // identifier printed on the notice handed to the tenant, so an operator holding the paper
    // would have no way to find the record.
    $violation = Violation::create([
        'asset_id' => $this->property->id,
        'tenant_id' => $this->tenant->id,
        'category' => 'signage',
        'description' => 'Unlicensed signage',
        'fine_amount' => 500,
        'violation_date' => now(),
        'status' => 'open',
    ]);

    $stored = DB::table('violations')->where('id', $violation->id)->value('search_text');

    expect($violation->reference)->toBe('VIO-'.str_pad((string) $violation->id, 5, '0', STR_PAD_LEFT))
        ->and($stored)->toContain(SearchText::normalize($violation->reference));
});

it('refolds the blob when the record is edited', function () {
    $this->tenant->update(['name' => 'Cilantro']);

    expect($this->tenant->fresh()->search_text)->toContain('cilantro')
        ->and($this->tenant->fresh()->search_text)->not->toContain('zara');
});

it('finds an Arabic name typed with a different but equivalent spelling', function () {
    $this->tenant->update(['name' => 'شركة أحمد للتجارة']);

    // Every one of these is the same name to a human and a different string to LIKE.
    // Asserted via in_array rather than ->toContain() because toContain() is variadic — a second
    // argument reads as another value that must be present, not as a failure message, so the
    // message would silently become part of the assertion.
    foreach (['شركة أحمد', 'شركه احمد', 'احمد', 'أحمد للتجاره'] as $query) {
        $found = Tenant::search($query)->pluck('id')->all();

        expect(in_array($this->tenant->id, $found, true))
            ->toBeTrue("«{$query}» did not find «شركة أحمد للتجارة»");
    }
});

it('finds a document number typed without its punctuation', function () {
    $invoice = makeInvoice($this->lease);

    expect(Invoice::search(str_replace('-', '', $invoice->number))->pluck('id'))
        ->toContain($invoice->id);
});

it('finds a phone number typed as one run of digits however it was stored', function () {
    $this->tenant->update(['phone' => '+20 100 123 4567']);

    expect(Tenant::search('01001234567')->pluck('id'))->toContain($this->tenant->id)
        ->and(Tenant::search('201001234567')->pluck('id'))->toContain($this->tenant->id);
});

it('narrows rather than widens when more words are typed', function () {
    $other = makeTenant(['name' => 'Zara Cairo']);
    $this->tenant->update(['name' => 'Zara Alexandria']);

    $both = Tenant::search('zara')->pluck('id');
    $one = Tenant::search('zara cairo')->pluck('id');

    expect($both)->toContain($this->tenant->id)->toContain($other->id)
        ->and($one)->toContain($other->id)->not->toContain($this->tenant->id);
});

it('leaves the query untouched when the search folds to nothing', function () {
    // The scope must NOT decide what "no usable query" means — a table with an empty search box
    // wants every row, global search wants none. Baking either in here would be wrong for the
    // other caller, so the scope stays neutral and each caller decides.
    $all = Tenant::count();

    expect(Tenant::search('---')->count())->toBe($all)
        ->and(Tenant::search(null)->count())->toBe($all);
});

it('rebuilds every blob from scratch without touching updated_at', function () {
    // The repair path for a changed fold or a mass update. A rebuild that moved `updated_at` would
    // silently reorder every "recently changed" list in the system.
    $before = $this->tenant->updated_at;

    DB::table('tenants')->where('id', $this->tenant->id)->update(['search_text' => 'stale garbage']);

    $counts = app(RebuildSearchIndex::class)([Tenant::class]);

    $this->tenant->refresh();

    expect($counts[Tenant::class])->toBe(1)
        ->and($this->tenant->search_text)->toContain('zara')
        ->and($this->tenant->updated_at->timestamp)->toBe($before->timestamp);
});

it('rewrites nothing on a second rebuild', function () {
    // Safe to run in production means: a re-run is a read-only pass, not a full table rewrite.
    app(RebuildSearchIndex::class)([Tenant::class]);
    $second = app(RebuildSearchIndex::class)([Tenant::class]);

    expect($second[Tenant::class])->toBe(0);
});

it('rebuilds soft-deleted rows too', function () {
    // A trashed record still shows up in restore flows and withTrashed() reports — exactly when
    // someone is hunting for it.
    //
    // A tenant with no lease, because `RefusesDeletionWhenReferenced` (correctly) refuses to
    // delete one that carries history. Using $this->tenant here would fail on the deletion policy
    // and never reach the thing this test is about.
    $unreferenced = makeTenant(['name' => 'Ephemeral Retail']);
    $unreferenced->delete();

    DB::table('tenants')->where('id', $unreferenced->id)->update(['search_text' => '']);
    app(RebuildSearchIndex::class)([Tenant::class]);

    expect(DB::table('tenants')->where('id', $unreferenced->id)->value('search_text'))
        ->toContain('ephemeral');
});

it('populates a blob for every registered model the factories can build', function () {
    // Cheap breadth: a model whose searchTextSources() references a column that was later renamed
    // would fold to an empty string here rather than throwing anywhere.
    $empty = [];

    foreach (SearchPolicy::INDEXED as $model) {
        expect(in_array(HasSearchText::class, class_uses_recursive($model), true))->toBeTrue(
            class_basename($model).' is registered but does not use HasSearchText',
        );
    }

    foreach ([$this->tenant, $this->unit, $this->lease, $this->property] as $record) {
        if (blank($record->fresh()->search_text)) {
            $empty[] = class_basename($record);
        }
    }

    expect($empty)->toBe([], 'these saved with an empty blob: '.implode(', ', $empty));
});
