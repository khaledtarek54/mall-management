<?php

use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\RetailCategory;
use App\Models\TenantRequestSubcategory;
use App\Models\VendorDocumentType;
use App\Models\ViolationCategory;
use Illuminate\Support\Facades\File;

/**
 * **Renaming a catalogue row must change what the reader sees, in the same process.**
 *
 * Every one of the six catalogues memoises its label map per request, keyed by locale, and drops it
 * from a `saved` hook. That was written six times by hand, and one of the six got it wrong in a way
 * nothing could see: `TenantRequestSubcategory` filled `…map.labels.en` and forgot `…map.labels`,
 * so the key it dropped had never existed and the key it filled was never dropped. An operator
 * renaming a subcategory saw the old word for the rest of the request — and on a `queue:work`
 * daemon, which is ONE long-lived process, for the rest of the day.
 *
 * That is why `IsCodeCatalogue` exists. This is its proof, and it is written as a sweep over the six
 * rather than as one case, because the whole point of the extraction is that the seventh inherits it.
 *
 * The mutation that must turn this red: delete the locale loop from
 * `IsCodeCatalogue::flushCatalogue()`.
 */
$catalogues = [
    'payment_method' => [PaymentMethod::class, ['code' => 'fawry', 'name_en' => 'Fawry', 'name_ar' => 'فوري']],
    'expense_category' => [ExpenseCategory::class, ['code' => 'insurance', 'name_en' => 'Insurance', 'name_ar' => 'تأمين']],
    'retail_category' => [RetailCategory::class, ['code' => 'cinema', 'name_en' => 'Cinema', 'name_ar' => 'سينما']],
    'violation_category' => [ViolationCategory::class, ['code' => 'fire_exit', 'name_en' => 'Blocked fire exit', 'name_ar' => 'مخرج طوارئ مسدود']],
    'vendor_document_type' => [VendorDocumentType::class, ['code' => 'civil_defence', 'name_en' => 'Civil-defence permit', 'name_ar' => 'تصريح دفاع مدني']],
    'tenant_request_subcategory' => [TenantRequestSubcategory::class, [
        'request_type' => 'maintenance', 'code' => 'lift', 'name_en' => 'Lift', 'name_ar' => 'مصعد',
    ]],
];

foreach ($catalogues as $name => [$model, $attributes]) {
    it("shows a renamed {$name} without waiting for a new process", function () use ($model, $attributes) {
        $row = $model::create($attributes);

        // Fill the memo the way a real request does — a table cell asking for one label.
        expect($model::labelFor($attributes['code']))->toBe($attributes['name_en']);

        $row->update(['name_en' => 'Renamed by the operator']);

        expect($model::labelFor($attributes['code']))->toBe('Renamed by the operator');
    });

    it("shows a renamed {$name} in the OTHER language too", function () use ($model, $attributes) {
        // The bug this half exists for: a memo keyed without the locale had English reading the
        // Arabic cache. A flush that drops only the CURRENT locale's key leaves the same hole one
        // language along, and a PDF service or a queued notification is exactly where languages
        // switch inside one process.
        app()->setLocale('ar');
        $row = $model::create($attributes);
        expect($model::labelFor($attributes['code']))->toBe($attributes['name_ar']);

        app()->setLocale('en');
        expect($model::labelFor($attributes['code']))->toBe($attributes['name_en']);

        $row->update(['name_ar' => 'اسم جديد', 'name_en' => 'A new name']);

        app()->setLocale('ar');
        expect($model::labelFor($attributes['code']))->toBe('اسم جديد');

        app()->setLocale('en');
        expect($model::labelFor($attributes['code']))->toBe('A new name');
    });
}

it('covers every catalogue that uses the shared concern', function () use ($catalogues) {
    // The premise. A sweep that silently stopped covering a model would pass just as happily — the
    // failure mode CLAUDE.md names as "a gate can report on a set it has silently stopped
    // collecting". Discovery is from disk, so a seventh catalogue fails this until it is listed.
    $onDisk = collect(File::allFiles(app_path('Models')))
        ->filter(fn ($f) => str_contains($f->getContents(), 'use IsCodeCatalogue;'))
        ->map(fn ($f) => 'App\\Models\\'.$f->getFilenameWithoutExtension())
        ->sort()
        ->values()
        ->all();

    $covered = collect($catalogues)->map(fn (array $c) => $c[0])->sort()->values()->all();

    expect($onDisk)->not->toBeEmpty()
        ->and($covered)->toBe($onDisk, 'A model uses IsCodeCatalogue and is not swept here.');
});

foreach ($catalogues as $name => [$model, $attributes]) {
    it("keeps a retired {$name} findable in its own filter, and out of the form", function () use ($model, $attributes) {
        // A form asks "what may I file under?"; a filter asks "what is already filed?". Pointing a
        // filter at `options()` meant retiring a code hid every record ever classified under it
        // from the list those records are on.
        $row = $model::create($attributes);
        $row->update(['is_active' => false]);

        $filter = $model === PaymentMethod::class
            ? $model::filterOptions('inbound')
            : ($model === TenantRequestSubcategory::class ? $model::filterOptions() : $model::filterOptions());

        expect($filter)->toHaveKey($attributes['code']);

        // …and the label survives too, which is the other half: a retired code must not render as
        // its raw code on the history it still explains.
        expect($model::labelFor($attributes['code']))->toBe($attributes['name_en']);
    });
}

it('never falls back to a raw translation key', function () {
    // The trait's third stated rule. An operator-added code has no lang key, so resolving the
    // fallback group against it would print `admin.enums.method.zzz_no_such_key` on the very screen
    // whose filter lists it. The last resort is the CODE.
    expect(PaymentMethod::labelFor('zzz_no_such_key'))->toBe('zzz_no_such_key')
        ->and(ViolationCategory::labelFor('zzz_no_such_key'))->toBe('zzz_no_such_key')
        ->and(VendorDocumentType::labelFor('zzz_no_such_key'))->toBe('zzz_no_such_key')
        // The control: a code the lang group DOES name still resolves through it, so the assertions
        // above are about the fallback and not about the group being unreachable.
        ->and(PaymentMethod::labelFor('cash'))->not->toBe('cash');
});

it('coerces a blanked sort order to zero on every catalogue', function () use ($catalogues) {
    // `sort_order` is NOT NULL with a default, and a column default applies when the column is
    // OMITTED — never when null is written to it. A blanked numeric field in Filament submits null.
    foreach ($catalogues as $name => [$model, $attributes]) {
        $row = $model::create($attributes + ['sort_order' => null]);

        expect($row->fresh()->sort_order)->toBe(0, "{$name} let a null sort_order through.");
    }
});
