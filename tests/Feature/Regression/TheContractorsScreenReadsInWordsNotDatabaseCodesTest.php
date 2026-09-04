<?php

/*
|--------------------------------------------------------------------------
| The contractor's only screen showed database codes (SW-069)
|--------------------------------------------------------------------------
| `Filament\Tables\Columns\Concerns\CanFormatState::formatState()` passes the state through
| UNTOUCHED when no `formatStateUsing` is set — there is no humanising default — so
| `TextColumn::make('status')->badge()` renders the stored string. On the vendor portal's job list
| both the status and the priority badge were exactly that, so an external maintenance company read
| `in_progress` and `urgent` on the one screen this system gives them: in English on the English
| panel, and in English on the Arabic one.
|
| The operator's own board had the word AND the colour, spelled out for itself — and so did
| `SlaPoliciesTable`, and so did the board's other badge. Three copies of one statement, and a
| fourth screen with none, which is why `App\Support\FacilityVocabulary` is the fix rather than a
| fourth copy.
|
| Measured 2026-09-03 by sweeping every badge column in both panels over a column
| `App\Support\ValueSets` governs: 150 of them, 1 deliberately verbatim (`currency`), and exactly
| these 2 unformatted. That sweep is the last case in this file.
*/

use App\Filament\Vendor\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Models\FacilityWorkOrder;
use App\Models\Vendor;
use App\Models\VendorContact;
use App\Support\ActivityVocabulary;
use App\Support\FacilityVocabulary;
use App\Support\ValueSets;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    $this->mine = Vendor::create(['name' => 'Cool Air Co', 'status' => Vendor::STATUS_ACTIVE]);

    $this->contact = VendorContact::create([
        'vendor_id' => $this->mine->id,
        'name' => 'Hani',
        'email' => 'hani@coolair.test',
        'password' => 'secret-secret',
        'is_portal_user' => true,
    ]);
});

/** A job dispatched to the signed-in contractor. `in_progress` so no default filter can hide it. */
function contractorPortalJob(array $attrs = []): FacilityWorkOrder
{
    return FacilityWorkOrder::create(array_merge([
        'asset_id' => test()->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'external',
        'vendor_id' => test()->mine->id,
        'title' => 'Fix chiller',
        'description' => 'Chiller down on the second floor',
        'trade_id' => tradeId('hvac'),
        'priority' => 'urgent',
        'status' => 'in_progress',
        'scheduled_for' => '2026-07-01',
    ], $attrs));
}

it('names a job status and priority in words, never in the database code', function () {
    $job = contractorPortalJob();

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    Livewire::test(ListWorkOrders::class)
        // The CONTROL, and it is not a formality: both columns read the raw column, so the two
        // assertions below can only pass because the FORMATTER did the work — not because the
        // database happens to hold the word.
        ->assertTableColumnStateSet('status', 'in_progress', $job)
        ->assertTableColumnStateSet('priority', 'urgent', $job)
        ->assertTableColumnFormattedStateSet('status', 'In progress', $job)
        ->assertTableColumnFormattedStateSet('priority', 'Urgent', $job);
});

it('reads the contractor their own language', function () {
    // No golden Arabic string: what has to hold is that the badge resolved through the operator's
    // OWN catalogue and that the catalogue answered in Arabic. `Lang::has()` cannot see an English
    // sentence sitting in the right key, which is why the script itself is asserted — the failure
    // this codebase records for every bilingual sweep it has written.
    $job = contractorPortalJob();

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    app()->setLocale('ar');

    $table = Livewire::test(ListWorkOrders::class)->instance()->getTable();

    $status = $table->getColumn('status')->record($job)->formatState('in_progress');
    $priority = $table->getColumn('priority')->record($job)->formatState('urgent');

    expect($status)->not->toBe('in_progress')
        ->and($status)->toBe(__('admin.facility.statuses.in_progress'))
        ->and($status)->toMatch('/\p{Arabic}/u')
        ->and($priority)->not->toBe('urgent')
        ->and($priority)->toBe(__('admin.facility.priorities.urgent'))
        ->and($priority)->toMatch('/\p{Arabic}/u');
});

it('gives a status the operator has no word for yet a readable last resort', function () {
    // `__()` returns the KEY when nothing translates it, so a bare `__("…statuses.{$state}")` would
    // print `admin.facility.statuses.on_hold` onto a third party's screen the day somebody widens
    // `FacilityWorkOrder::STATUSES` before writing the Arabic — which is worse than the raw code,
    // not better. `Translate::orHumanized()` is the whole reason the vocabulary is a class.
    expect(FacilityVocabulary::statusLabel('on_hold'))->toBe('On Hold')
        ->and(FacilityVocabulary::statusLabel(null))->toBe('')
        // …and a known code still resolves, or the fallback would be masking the catalogue.
        ->and(FacilityVocabulary::statusLabel('done'))->toBe(__('admin.facility.statuses.done'));
});

it('leaves no other badge in either panel rendering a raw database code', function () {
    // The sweep that found it, kept. A badge over a classification column with no formatter looks
    // completely correct in its own file, which is how two of them survived on the one screen a
    // third party reads.
    //
    // Derived on BOTH ends, so this cannot become a list that drifts from what it guards: which
    // columns are classifications comes from `ValueSets::SETS`, and which values stay raw on
    // purpose comes from `ActivityVocabulary::verbatimReason()` — `currency` is an ISO 4217 code
    // the operator reconciles against a bank statement, and that reason is already written down
    // there. No third list.
    //
    // A table fed from `->records()` is skipped: its "status" is prose its own array builder
    // already composed, not a stored code. `TableSortPolicy::owns()` excludes those for the same
    // reason.
    $classification = [];

    foreach (array_keys(ValueSets::SETS) as $key) {
        [, $column] = explode('.', $key, 2);
        $classification[$column] = true;
    }

    $offenders = [];
    $badges = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament'))) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if (str_contains($source, '->records(')) {
            continue;
        }

        if (! preg_match_all("/TextColumn::make\(\s*'([a-z0-9_.]+)'\s*\)/", $source, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($matches[0] as $i => [, $offset]) {
            $name = $matches[1][$i][0];
            $leaf = str_contains($name, '.') ? substr($name, (int) strrpos($name, '.') + 1) : $name;

            if (! isset($classification[$leaf])) {
                continue;
            }

            // This column's chain runs until the next column is declared. Coarse, and it fails
            // toward MISSING an offender rather than inventing one, which is the right direction
            // for a sweep whose job is to keep a fixed defect fixed.
            $next = $matches[0][$i + 1][1] ?? strlen($source);
            $chain = substr($source, $offset, $next - $offset);

            if (! str_contains($chain, '->badge()')) {
                continue;
            }

            $badges++;

            if (str_contains($chain, 'formatStateUsing') || str_contains($chain, '->state(')) {
                continue;
            }

            if (ActivityVocabulary::verbatimReason($leaf) !== null) {
                continue;
            }

            $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).' → '.$name;
        }
    }

    // The premise. A sweep that silently stopped collecting would report no offenders and pass —
    // the failure this codebase has been bitten by more than any other. 150 on 2026-09-03.
    expect($badges)->toBeGreaterThan(100);

    sort($offenders);

    expect($offenders)->toBe([], "A badge renders a raw database code — the operator's word for it exists\n"
        ."and nothing is asking for it. Add a `formatStateUsing`, or record the column in\n"
        ."ActivityVocabulary::VERBATIM_VALUES with the reason it stays raw:\n  ".implode("\n  ", $offenders));
});
