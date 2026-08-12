<?php

/*
|--------------------------------------------------------------------------
| An Excel export that is a spreadsheet, not a CSV wearing an .xlsx name (RP-07)
|--------------------------------------------------------------------------
| Every report already exported CSV, and an accountant re-did the same four things to each one
| before it was usable: bold the header, freeze it, widen the columns, and set a number format so
| 1234.5 reads as 1,234.50 instead of as right-aligned text. That is the whole reason the CSV gets
| reformatted by hand here today, and Yardi hands them a workbook that already has all four.
|
| So "it downloaded" proves nothing. The tests that matter read the file BACK: are the numbers still
| numbers, did the header freeze, is a leading zero still there.
*/

use App\Filament\Admin\Pages\RentRoll;
use App\Support\ReportXlsx;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Write a workbook to a temp file and read every row back out of it. */
function xlsxRoundTrip(array $headers, array $rows): array
{
    $path = tempnam(sys_get_temp_dir(), 'atriom').'.xlsx';

    $response = ReportXlsx::stream('test', $headers, $rows);
    ob_start();
    $response->sendContent();
    file_put_contents($path, ob_get_clean());

    $reader = new Reader;
    $reader->open($path);

    $read = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $read[] = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
        }

        break;
    }

    $reader->close();
    unlink($path);

    return $read;
}

it('writes a workbook that opens and carries every row', function () {
    $read = xlsxRoundTrip(['Account', 'Amount'], [['Rent', 1234.5], ['Service charge', 900.0]]);

    expect($read)->toHaveCount(3)
        ->and($read[0])->toBe(['Account', 'Amount'])
        ->and($read[1][0])->toBe('Rent');
});

it('keeps numbers as numbers', function () {
    // The difference that matters. A CSV hands Excel a string and Excel guesses; a column of
    // guessed strings does not sum, and an accountant discovers that after pasting it into a model.
    $read = xlsxRoundTrip(['Account', 'Amount'], [['Rent', 1234.5]]);

    expect($read[1][1])->toBeFloat()
        ->and($read[1][1])->toBe(1234.5);
});

it('keeps a leading zero on a code', function () {
    // The other half of the same problem, in the other direction: Excel reads `01234` from a CSV as
    // the number 1234 and the account code is silently wrong. Declaring the type keeps it text.
    $read = xlsxRoundTrip(['Code', 'Amount'], [['01234', 500.0]]);

    expect($read[1][0])->toBe('01234');
});

it('freezes the header row', function () {
    // A 400-line rent roll is unreadable when the header scrolls away. Asserted on the written XML
    // because there is no reader-side accessor for a pane — and because `getSheetView()` starts
    // NULL on a fresh sheet, so a chained call would have been a fatal on the first export rather
    // than a missing freeze.
    $path = tempnam(sys_get_temp_dir(), 'atriom').'.xlsx';

    $response = ReportXlsx::stream('test', ['A', 'B'], [['x', 1.0]]);
    ob_start();
    $response->sendContent();
    file_put_contents($path, ob_get_clean());

    $zip = new ZipArchive;
    $zip->open($path);
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    expect($sheetXml)->toContain('frozen')
        ->and($sheetXml)->toContain('A2');
});

it('names the file .xlsx exactly once', function () {
    // A report whose own filename already ends in .xlsx must not become `x.xlsx.xlsx`.
    expect(ReportXlsx::filename('rent-roll'))->toBe('rent-roll.xlsx')
        ->and(ReportXlsx::filename('rent-roll.xlsx'))->toBe('rent-roll.xlsx');
});

it('offers both exports on a real report page', function () {
    // The wiring. Fourteen pages each carried their own copy of the CSV action — five subtly
    // different copies — and adding Excel to each would have made twenty-eight. Both now come from
    // one concern, so this asserts the page actually gets them.
    $page = new RentRoll;
    $actions = collect(invade($page)->exportActions())->map->getName();

    expect($actions)->toContain('export_csv', 'export_xlsx');
});

it('refuses both exports to someone who may not read reports', function () {
    // The export IS the report: anyone who may read it on screen may take it away, and anyone who
    // may not must not get a second door to it. Paired with a control, because a refusal passes
    // just as happily when the predicate is broken in both directions.
    $this->actingAs(makeUser('marketing'));
    expect(RentRoll::mayExport())->toBeFalse();

    $this->actingAs(makeUser('accounting'));
    expect(RentRoll::mayExport())->toBeTrue();
});
