<?php

use App\Filament\Exports\InvoiceExporter;
use App\Filament\Imports\ChargeImporter;
use App\Filament\Imports\LedgerAccountImporter;
use App\Models\User;
use App\Support\DataTransferNotice;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Facades\Lang;

/**
 * **A bulk import or export says it finished in the language the operator is working in.**
 *
 * All ten importers and all nine exporters built this sentence as an English literal — while the
 * HEADING above it is Filament's and ships translated (`filament-actions::import.notifications.
 * completed.title`, Arabic at `vendor/filament/actions/resources/lang/ar/import.php:46`). So an
 * Arabic operator read an Arabic heading over an English sentence, on the screen that tells them
 * whether a cut-over worked.
 *
 * **The second tooth is the one that lasts.** Fixing nineteen literals is a morning; the thing that
 * keeps them fixed is a sweep DERIVED from disk, so importer number twenty either states its
 * sentence in both languages or turns the build red. A missing key is silent at runtime — `__()`
 * returns the key, and the operator reads `admin.data_transfer.import.foo`.
 *
 * `Lang::has()` is asked with `fallback: false` here: with the default it falls back to English, so
 * a key present only in `lang/en` would answer TRUE — and even that cannot see an English sentence
 * sitting in the Arabic file, which is the realistic failure when keys are written in one pass and
 * reviewed in English. Hence the script check as well.
 */

/** @return array<int, class-string> Every importer and exporter, from disk. */
function dataTransferFiles(): array
{
    $classes = [];

    foreach (glob(app_path('Filament/Imports/*Importer.php')) ?: [] as $file) {
        $classes[] = 'App\\Filament\\Imports\\'.basename($file, '.php');
    }

    foreach (glob(app_path('Filament/Exports/*Exporter.php')) ?: [] as $file) {
        $classes[] = 'App\\Filament\\Exports\\'.basename($file, '.php');
    }

    return $classes;
}

function dataTransferImport(string $importer, int $total, int $successful): Import
{
    return Import::create([
        'user_id' => User::factory()->create()->id,
        'file_name' => 'cut-over.csv',
        'file_path' => 'imports/cut-over.csv',
        'importer' => $importer,
        'processed_rows' => $total,
        'total_rows' => $total,
        'successful_rows' => $successful,
    ]);
}

function dataTransferExport(string $exporter, int $total, int $successful): Export
{
    return Export::create([
        'user_id' => User::factory()->create()->id,
        'file_disk' => 'local',
        'file_name' => 'register.csv',
        'exporter' => $exporter,
        'processed_rows' => $total,
        'total_rows' => $total,
        'successful_rows' => $successful,
    ]);
}

afterEach(fn () => app()->setLocale(config('app.locale')));

it('tells an Arabic operator in Arabic that the chart of accounts imported', function () {
    $import = dataTransferImport(LedgerAccountImporter::class, 167, 167);

    app()->setLocale('ar');
    $arabic = LedgerAccountImporter::getCompletedNotificationBody($import);

    expect($arabic)->toMatch('/\p{Arabic}/u')
        ->and($arabic)->not->toContain('has completed')
        ->and($arabic)->toContain('167');

    // CONTROL. A fix that simply emitted Arabic for everybody would be the same defect through the
    // other door, and this is what refuses it.
    app()->setLocale('en');
    $english = LedgerAccountImporter::getCompletedNotificationBody($import);

    expect($english)->toBe('Your chart of accounts import has completed. 167 rows were imported.');
});

it('keeps the thousands separator, which the obvious :count placeholder would have eaten', function () {
    // `Translator::choice()` sets `count` to the RAW number after merging the caller's
    // replacements, so a formatted figure passed as `count` is discarded. Written as `:rows`.
    $import = dataTransferImport(LedgerAccountImporter::class, 12500, 12500);

    app()->setLocale('en');

    expect(LedgerAccountImporter::getCompletedNotificationBody($import))->toContain('12,500')
        ->and(LedgerAccountImporter::getCompletedNotificationBody($import))->not->toContain('12500 ');
});

it('names the failures only when there are some, and carries the follow-up advice', function () {
    app()->setLocale('en');

    $clean = ChargeImporter::getCompletedNotificationBody(dataTransferImport(ChargeImporter::class, 40, 40));
    $dirty = ChargeImporter::getCompletedNotificationBody(dataTransferImport(ChargeImporter::class, 40, 37));

    expect($clean)->not->toContain('failed')
        ->and($dirty)->toContain('3 rows failed to import.')
        // The follow-up did not vanish when it moved into the catalogue — it is what tells the
        // operator that a schedule import can leave a lease billing nothing.
        ->and($clean)->toContain('atriom:audit-charge-schedules');

    app()->setLocale('ar');

    $arabic = ChargeImporter::getCompletedNotificationBody(dataTransferImport(ChargeImporter::class, 40, 37));

    expect($arabic)->toMatch('/\p{Arabic}/u')
        ->and($arabic)->toContain('atriom:audit-charge-schedules')
        ->and($arabic)->not->toContain('failed to import');
});

it('gives an export the same treatment as an import', function () {
    $export = dataTransferExport(InvoiceExporter::class, 500, 500);

    app()->setLocale('en');
    expect(InvoiceExporter::getCompletedNotificationBody($export))
        ->toBe('Your invoice export has completed. 500 rows were exported.');

    app()->setLocale('ar');
    expect(InvoiceExporter::getCompletedNotificationBody($export))
        ->toMatch('/\p{Arabic}/u');
});

it('makes every importer and exporter state its sentence in both languages', function () {
    $missing = [];
    $notRouted = [];
    $checked = 0;

    foreach (dataTransferFiles() as $class) {
        $checked++;

        $direction = str_ends_with($class, 'Importer') ? 'import' : 'export';
        $key = "admin.data_transfer.{$direction}.".DataTransferNotice::keyFor($class);

        foreach (['en', 'ar'] as $locale) {
            // `fallback: false` — the default falls back to English, so a key present only in
            // lang/en would answer true for Arabic.
            if (! Lang::has($key, $locale, fallback: false)) {
                $missing[] = "[{$locale}] {$key}";
            }
        }

        // And a key that EXISTS can still hold an English sentence, which `Lang::has()` cannot see.
        $arabic = (string) __($key, [], 'ar');

        if (! preg_match('/\p{Arabic}/u', $arabic)) {
            $missing[] = "[ar, no Arabic script] {$key} → {$arabic}";
        }

        // The seam, not a second copy: a file that builds its own sentence would pass every
        // assertion above by accident the day someone adds the keys and forgets the call.
        $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());

        if (! str_contains($source, 'DataTransferNotice::for')) {
            $notRouted[] = class_basename($class);
        }
    }

    expect($missing)->toBe([], "A bulk transfer would print a raw key or an English sentence at an Arabic operator:\n  ".implode("\n  ", $missing));
    expect($notRouted)->toBe([], 'These build their own completion sentence instead of going through DataTransferNotice: '.implode(', ', $notRouted));

    // Vacuity guard: a glob that matched nothing would pass for ever.
    expect($checked)->toBeGreaterThanOrEqual(19);
});
