<?php

use App\Filament\Admin\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Admin\Resources\Announcements\RelationManagers\RecipientsRelationManager;
use App\Models\Announcement;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Livewire;

/**
 * SW-157 — TWO TABLE COLUMNS OF ONE NAME, AND THE FIRST ONE VANISHES.
 *
 * `Table::pushColumns()` keys the set by name — `$this->columns[$component->getName()] = $component`
 * (vendor/filament/tables/src/Table/Concerns/HasColumns.php:84) — and `getVisibleColumns()` renders
 * from that map. So a second column of the same name silently REPLACES the first: no error, no
 * warning, and both declarations still read perfectly in the source.
 *
 * It had happened once. The announcement recipient list declared `IconColumn::make('read_at')` (the
 * at-a-glance read/unread tick) and `TextColumn::make('read_at')` (when they opened it) nine lines
 * apart, so the tick never rendered — on the one screen whose whole purpose, per its own docblock,
 * is that "we told you" is a record rather than an assertion.
 *
 * The gate below is the class rather than the instance. It is a SOURCE sweep for the reason
 * `ActionStrips` is one: the defect lives in relation managers as often as in resources, and a
 * relation manager cannot be mounted without an owner record. Measured across `app/Filament` when
 * it was written: 629 files, 168 column lists, 1,074 declared columns, ONE duplicate — this one. It
 * cannot see a column list assembled from a helper's return value (`CustomFieldsTable::columnsFor`),
 * which is stated rather than implied.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    $this->asset = makeAsset(['code' => 'ANN']);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->announcement = Announcement::create([
        'asset_id' => $this->asset->id,
        'title' => 'Roof works',
        'body' => 'Expect some noise this week.',
    ]);
});

it('shows the read tick AND the time it was read', function () {
    $tenant = makeTenant();

    $this->announcement->recipients()->create([
        'tenant_id' => $tenant->id,
        'notified_at' => now(),
        'read_at' => now(),
    ]);

    $columns = asTenant($this->asset, fn () => Livewire::test(RecipientsRelationManager::class, [
        'ownerRecord' => $this->announcement,
        'pageClass' => EditAnnouncement::class,
    ])->instance()->getTable()->getColumns());

    // Exact, and in order. With both columns named `read_at` the second overwrote the first and
    // this list was four long — which is the entire finding, and is invisible from either
    // declaration on its own.
    expect(array_keys($columns))->toBe(['tenant.name', 'is_read', 'read_at', 'readBy.name', 'notified_at']);
});

it('leaves no table declaring the same column twice', function () {
    $duplicates = [];
    $files = 0;
    $lists = 0;
    $declared = 0;

    foreach (filamentTableSourceFiles() as $path => $source) {
        $files++;

        foreach (tableColumnNamesIn($source) as $names) {
            $lists++;
            $declared += count($names);

            foreach (array_count_values($names) as $name => $times) {
                if ($times > 1) {
                    $duplicates[] = str_replace(base_path().'/', '', $path).' → '.$name.' ×'.$times;
                }
            }
        }
    }

    // The sweep must prove it collected something before reporting on it — the class of failure
    // where a gate quietly matches zero files and stays green for a year.
    expect($files)->toBeGreaterThan(300)
        ->and($lists)->toBeGreaterThan(100)
        ->and($declared)->toBeGreaterThan(700);

    expect($duplicates)->toBe([], implode("\n", [
        'These tables declare one column name twice. Filament keys its column set by name, so the',
        'second declaration silently REPLACES the first and one of them never renders — no error,',
        'and both still read correctly in the source. Give the derived one a name of its own.',
        '',
        ...$duplicates,
    ]));
});

it('still keys Filament\'s own column set by name', function () {
    // Upstream's behaviour is the whole reason the sweep above is a rule, so it is pinned as a
    // contract — the idiom `FilamentActionDispatchContractTest` uses. A release that started
    // keeping both would turn this red rather than quietly making the gate pointless.
    $component = asTenant($this->asset, fn () => Livewire::test(RecipientsRelationManager::class, [
        'ownerRecord' => $this->announcement,
        'pageClass' => EditAnnouncement::class,
    ])->instance());

    $table = Table::make($component)->columns([
        IconColumn::make('duplicated')->label('First'),
        TextColumn::make('duplicated')->label('Second'),
    ]);

    expect($table->getColumns())->toHaveCount(1)
        ->and($table->getColumns()['duplicated']->getLabel())->toBe('Second');
});

/**
 * Every PHP file under app/Filament, keyed by path.
 *
 * @return array<string, string>
 */
function filamentTableSourceFiles(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament'))) as $file) {
        if (! $file->isDir() && $file->getExtension() === 'php') {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($files);

    return $files;
}

/**
 * The column names each `->columns([...])` / `->pushColumns([...])` call declares.
 *
 * Tokenised rather than grepped, for the reason `ActionStrips` gives: a regex cannot tell a table's
 * column list from a form's `->columns(2)` or from a responsive `->columns(['md' => 2])`, and a
 * bracket counter over raw characters fails OPEN on a `[` inside a string literal. A token stream
 * has strings as single tokens, so the slice cannot run away. `#[` opens a bracket too and closes
 * with a plain `]`, which is why T_ATTRIBUTE counts.
 *
 * Nested lists count as siblings on purpose: a `ColumnGroup` or a layout component merges its own
 * columns into the SAME keyed map, so a name repeated inside one is dropped exactly as it is at the
 * top level.
 *
 * @return array<int, array<int, string>>
 */
function tableColumnNamesIn(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $lists = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], ['columns', 'pushColumns'], true)) {
            continue;
        }

        $previous = null;
        for ($p = $i - 1; $p >= 0; $p--) {
            if (is_array($tokens[$p]) && in_array($tokens[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $previous = $tokens[$p];
            break;
        }

        if (! is_array($previous) || ! in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }

        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (($tokens[$j] ?? null) !== '(') {
            continue;
        }
        $j++;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (($tokens[$j] ?? null) !== '[') {
            continue;
        }

        $depth = 0;
        $slice = [];
        for ($k = $j; $k < $count; $k++) {
            $current = $tokens[$k];

            if ($current === '[' || (is_array($current) && $current[0] === T_ATTRIBUTE)) {
                $depth++;
            } elseif ($current === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }

            $slice[] = $current;
        }

        $names = [];
        $length = count($slice);

        for ($k = 0; $k < $length; $k++) {
            $current = $slice[$k];

            if (! is_array($current) || $current[0] !== T_STRING || ! str_ends_with($current[1], 'Column')) {
                continue;
            }

            $operator = $slice[$k + 1] ?? null;
            $make = $slice[$k + 2] ?? null;
            $name = $slice[$k + 4] ?? null;

            if (! is_array($operator) || $operator[0] !== T_DOUBLE_COLON) {
                continue;
            }
            if (! is_array($make) || $make[0] !== T_STRING || $make[1] !== 'make') {
                continue;
            }
            if (($slice[$k + 3] ?? null) !== '(' || ($slice[$k + 5] ?? null) !== ')') {
                continue;
            }
            if (! is_array($name) || $name[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $names[] = trim($name[1], "'\"");
        }

        if ($names !== []) {
            $lists[] = $names;
        }
    }

    return $lists;
}
