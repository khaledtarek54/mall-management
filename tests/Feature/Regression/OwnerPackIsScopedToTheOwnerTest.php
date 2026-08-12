<?php

/*
|--------------------------------------------------------------------------
| An owner pack that shows one owner another owner's numbers (RP-08)
|--------------------------------------------------------------------------
| Module 32 issues the owner STATEMENT — what Jawad is owed. The pack is the evidence behind it: how
| each of his malls traded, who is in them, who has not paid. Today an operator opens five reports,
| sets the property on each, exports each, and attaches five files per owner per month.
|
| The risk is not that the pack is missing a report. It is that a report inside it was rendered
| PORTFOLIO-WIDE — because that leak looks exactly like a working feature: the file opens, the
| numbers are real, and nobody notices they are the wrong ones. An operator would have to know
| another landlord's revenue by heart to spot it.
|
| So the test that matters here is not "a zip appeared". It is: does a second owner's property
| appear anywhere in the first owner's pack.
*/

use App\Models\User;
use App\Services\OwnerAccounting\BuildOwnerPackService;
use App\Support\OwnerPack;
use App\Support\ReportParameters;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(AccountingSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** An owner holding one property from a date. */
function ownerHolding(string $name, \App\Models\Asset $asset, string $since = '2020-01-01'): User
{
    // makeUser()'s second argument is ASSET IDS, not attributes — the name is set afterwards.
    $owner = makeUser('owner');
    $owner->update(['name' => $name]);

    $owner->ownedAssets()->attach($asset->id, ['started_at' => $since, 'ownership_percentage' => 100]);

    return $owner;
}

/** Every entry name inside the built pack. */
function packEntries(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path);

    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    $zip->close();

    return $names;
}

it('contains only the properties this owner holds', function () {
    // THE test. Two owners, two malls, one pack.
    $jawadMall = makeAsset(['code' => 'JAWAD', 'name' => 'Jawad Mall']);
    $otherMall = makeAsset(['code' => 'OTHERCO', 'name' => 'Other Landlord Mall']);

    $jawad = ownerHolding('Jawad', $jawadMall);
    ownerHolding('Other Landlord', $otherMall);

    $path = app(BuildOwnerPackService::class)->build(
        $jawad,
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    $entries = implode("\n", packEntries($path));

    expect($entries)->toContain('jawad')
        ->and($entries)->not->toContain('otherco');

    @unlink($path);
});

it('stops at the properties a former owner still holds', function () {
    // Tenure, not "ever owned". A landlord who sold in March must not receive April's trading
    // figures for a building that is no longer theirs — the same former-owner leak module 15 fixed.
    $sold = makeAsset(['code' => 'SOLD']);
    $kept = makeAsset(['code' => 'KEPT']);

    $owner = ownerHolding('Departing', $kept);
    $owner->ownedAssets()->attach($sold->id, [
        'started_at' => '2020-01-01',
        'ended_at' => '2026-02-28',
        'ownership_percentage' => 100,
    ]);

    $path = app(BuildOwnerPackService::class)->build(
        $owner,
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    $entries = implode("\n", packEntries($path));

    expect($entries)->toContain('kept')
        ->and($entries)->not->toContain('sold');

    @unlink($path);
});

it('carries every report the pack promises', function () {
    // The control for the leak test above. A pack that leaked nothing because it contained nothing
    // would pass that test perfectly.
    $asset = makeAsset(['code' => 'JAWAD']);
    $owner = ownerHolding('Jawad', $asset);

    $path = app(BuildOwnerPackService::class)->build(
        $owner,
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    expect(packEntries($path))->toHaveCount(count(OwnerPack::REPORTS));

    @unlink($path);
});

it('refuses to build a pack for an owner who holds nothing', function () {
    // An empty zip emailed to a landlord reads as "your malls earned nothing". Refusing is the
    // honest answer, and it is the same sentinel reasoning as module 15: no properties must mean
    // SEE NOTHING, never see everything.
    $owner = makeUser('owner');

    expect(fn () => app(BuildOwnerPackService::class)->build(
        $owner,
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    ))->toThrow(RuntimeException::class);
});

it('gives the operator back their own session afterwards', function () {
    // The service authenticates as the OWNER to render. If that leaked past the build, the operator
    // would spend the rest of the request as a landlord — seeing one property and, worse, writing
    // as them.
    $asset = makeAsset(['code' => 'JAWAD']);
    $owner = ownerHolding('Jawad', $asset);

    $operator = makeUser('super_admin');
    $this->actingAs($operator);

    $path = app(BuildOwnerPackService::class)->build(
        $owner,
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    expect(auth()->id())->toBe($operator->id);

    @unlink($path);
});

it('names a real, packable report in the registry', function () {
    // A pack entry that cannot be scoped to one property is the leak this whole file is about, so
    // the registry is checked rather than trusted: every report must exist, be openable, and be
    // able to say which property it is for.
    foreach (OwnerPack::REPORTS as $report => $reason) {
        expect(class_exists($report))->toBeTrue("{$report} does not exist");
        expect(method_exists($report, 'reportCsv'))
            ->toBeTrue("{$report} is in the owner pack but has no CSV to put in it");
        expect(strlen($reason))->toBeGreaterThan(40, "{$report} must say why it belongs to the OWNER");
    }

    // And the exclusions carry their reasoning too — the omissions are the interesting part.
    foreach (OwnerPack::EXCLUDED as $what => $why) {
        expect(strlen($why))->toBeGreaterThan(40, "{$what} needs a real reason");
    }
});

it('points every report at the pack period rather than today', function () {
    // A rent roll dated today inside a pack labelled March is wrong in the quietest possible way.
    // The reports speak different vocabularies (period vs as-of), which is why the service sets
    // both — setting one would leave the other at its default.
    $asset = makeAsset(['code' => 'JAWAD']);
    $owner = ownerHolding('Jawad', $asset);

    $path = app(BuildOwnerPackService::class)->build(
        $owner,
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    // The filenames carry the period the report was run for.
    $entries = implode("\n", packEntries($path));

    expect($entries)->toContain('2026-03');

    @unlink($path);
});

/*
|--------------------------------------------------------------------------
| The leak test above checks FOLDER NAMES, which is not the same thing
|--------------------------------------------------------------------------
| Caught by mutation: deleting `Filament::setTenant($asset)` from the service — so every report
| renders portfolio-wide — left every test above green. Of course it did. The folders are named
| after the assets the loop walks, and the loop walks the right assets; it is the CONTENT of the
| files inside them that leaks.
|
| This is the same false-pass shape the codebase keeps hitting: a refusal asserted against something
| that was never the thing being refused. So this one reads the rent roll back out of the zip and
| looks for the other landlord's tenant by name.
*/

it('does not put another landlord tenant inside this owner rent roll', function () {
    $jawadMall = makeAsset(['code' => 'JAWAD', 'name' => 'Jawad Mall']);
    $otherMall = makeAsset(['code' => 'OTHERCO', 'name' => 'Other Landlord Mall']);

    // A tenant in each building, named so either one is unmistakable in a spreadsheet.
    makeLease(makeUnit($jawadMall), makeTenant(['name' => 'ZZTenantOfJawad']));
    makeLease(makeUnit($otherMall), makeTenant(['name' => 'ZZTenantOfOtherLandlord']));

    $jawad = ownerHolding('Jawad', $jawadMall);
    ownerHolding('Other Landlord', $otherMall);

    $path = app(BuildOwnerPackService::class)->build(
        $jawad,
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    $contents = packContents($path);

    // The control FIRST: if the rent roll came out empty, the refusal below would pass for the
    // wrong reason and this whole test would be theatre.
    expect($contents)->toContain('ZZTenantOfJawad')
        ->and($contents)->not->toContain('ZZTenantOfOtherLandlord');

    @unlink($path);
});

/** Every cell value in every worksheet in the pack, as one string. */
function packContents(string $path): string
{
    $zip = new ZipArchive;
    $zip->open($path);

    $text = '';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $sheet = tempnam(sys_get_temp_dir(), 'atriom-pack').'.xlsx';
        file_put_contents($sheet, $zip->getFromIndex($i));

        $reader = new OpenSpout\Reader\XLSX\Reader;
        $reader->open($sheet);

        foreach ($reader->getSheetIterator() as $s) {
            foreach ($s->getRowIterator() as $row) {
                foreach ($row->getCells() as $cell) {
                    $text .= (string) $cell->getValue()."\n";
                }
            }
        }

        $reader->close();
        unlink($sheet);
    }

    $zip->close();

    return $text;
}
