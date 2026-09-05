<?php

/*
|--------------------------------------------------------------------------
| Remember which mall, never which date (RP-02)
|--------------------------------------------------------------------------
| Eltizam runs several malls. An accountant who works one of them re-picked it on every report, on
| every visit, and the pick was never wrong — just repeated.
|
| The obvious implementation remembers every parameter, and it should not. A remembered AS-OF DATE
| means an operator opens the AR ageing three weeks later and reads totals struck at a date they did
| not choose and did not notice. The date is on screen; the totals are what get quoted in a meeting.
| That is the same failure as a filter that updates without clearing its cache: invisible, and it
| looks authoritative.
|
| "What I picked last time" is also least likely to be right for a date — a period or an as-of is
| exactly the parameter an operator changes because the answer they want has moved. Property, bucket
| and account are the opposite: they say which slice of the business this person is responsible for.
|
| So the tests here are: does the slice come back, does the date NOT, and does an explicit URL still
| win over both.
*/

use App\Filament\Admin\Pages\IncomeStatement;
use App\Models\ReportPreference;
use App\Services\Reports\ComparativeStatementService;
use App\Support\ReportParameters;
use App\Support\ReportPreferences;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->user = makeUser('super_admin');
    $this->actingAs($this->user);
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('remembers which property the operator was looking at', function () {
    $page = new IncomeStatement;
    $page->assetId = 7;
    $page->period = '2026-03';

    ReportPreferences::remember($page);

    $stored = ReportPreference::query()
        ->where('user_id', $this->user->id)
        ->where('report', IncomeStatement::class)
        ->value('parameters');

    expect($stored)->toBe(['assetId' => 7]);
});

it('never remembers a date, however it was set', function () {
    // The whole design. `period` and `year` are on the same page as `assetId` and must not survive.
    $page = new IncomeStatement;
    $page->assetId = 7;
    $page->period = '2026-03';
    $page->year = 2026;

    ReportPreferences::remember($page);

    $stored = ReportPreference::query()->where('user_id', $this->user->id)->value('parameters');

    expect($stored)->not->toHaveKey('period')
        ->and($stored)->not->toHaveKey('year');
});

it('restores the slice onto a freshly opened report', function () {
    ReportPreference::create([
        'user_id' => $this->user->id,
        'report' => IncomeStatement::class,
        'parameters' => ['assetId' => 7],
    ]);

    $page = new IncomeStatement;
    $applied = ReportPreferences::restore($page);

    expect($applied)->toBe(['assetId' => 7])
        ->and($page->assetId)->toBe(7);
});

it('lets an explicit URL beat the memory', function () {
    // A bookmark, a shared link, or a saved view (RP-05) that pinned Cairo Festival must not be
    // silently re-pointed at whichever mall the RECIPIENT last looked at. That would make a shared
    // report link mean something different for every person who opens it.
    ReportPreference::create([
        'user_id' => $this->user->id,
        'report' => IncomeStatement::class,
        'parameters' => ['assetId' => 7],
    ]);

    $this->get('/?assetId=3');   // put assetId into the current request's query bag
    request()->query->set('assetId', '3');

    $page = new IncomeStatement;
    $page->assetId = 3;
    $applied = ReportPreferences::restore($page);

    expect($applied)->toBe([])
        ->and($page->assetId)->toBe(3);
});

it('refuses to restore one operator preference onto another', function () {
    // Per USER, not per role or per install. Two accountants covering different malls must not
    // fight over one stored value.
    ReportPreference::create([
        'user_id' => $this->user->id,
        'report' => IncomeStatement::class,
        'parameters' => ['assetId' => 7],
    ]);

    $this->actingAs(makeUser('accounting'));

    expect(ReportPreferences::restore(new IncomeStatement))->toBe([]);
});

it('clears the row when the operator deselects everything', function () {
    // "I deselected the property" is itself a preference. Leaving the old row would re-apply a mall
    // the operator had just stepped out of.
    ReportPreference::create([
        'user_id' => $this->user->id,
        'report' => IncomeStatement::class,
        'parameters' => ['assetId' => 7],
    ]);

    $page = new IncomeStatement;
    $page->assetId = null;

    ReportPreferences::remember($page);

    expect(ReportPreference::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('does nothing at all for a guest', function () {
    // The scheduled delivery renders reports with no session. Writing a preference row there would
    // attribute one operator's slice to whoever the job authenticated as.
    auth()->logout();

    $page = new IncomeStatement;
    $page->assetId = 7;

    ReportPreferences::remember($page);

    expect(ReportPreference::query()->count())->toBe(0)
        ->and(ReportPreferences::restore($page))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The wiring, not the resolver
|--------------------------------------------------------------------------
| Everything above drives `ReportPreferences` directly, which proves the rule and NOT that anything
| calls it. That distinction is not academic here: the first cut wired remembering into
| `ReportFilters` only, and the sole rememberable parameter — `assetId` — is declared by the three
| pages exempt from that component. The feature was dead on arrival and every test above still
| passed. These drive the real Livewire page.
*/

it('remembers the property when the operator actually changes it on the page', function () {
    $asset = makeAsset(['code' => 'PRIME']);

    Livewire::test(IncomeStatement::class)
        ->set('assetId', $asset->id);

    $stored = ReportPreference::query()
        ->where('user_id', $this->user->id)
        ->where('report', IncomeStatement::class)
        ->value('parameters');

    expect($stored)->toBe(['assetId' => $asset->id]);
});

it('opens the next visit on the slice it remembered, and lets the switcher have the property', function () {
    // TWO parameters, two answers, and the difference is the point.
    //
    // `comparison` is a slice of the BUSINESS QUESTION — am I reading this month against budget or
    // against last year — so it comes back, which is the whole of RP-02.
    //
    // `assetId` does NOT, on a financial statement, and that is deliberate rather than a gap:
    // `hydrateLedgerScopeFromQuery()` pins it to the mall the operator is standing in as the LAST
    // word, because `TenantScope::reportAssetIds()` clamps the figures to that mall regardless.
    // Left unpinned, the disabled picker names the mall this operator worked yesterday while the
    // rows underneath come from the one they are in — a statement headed with the wrong mall, which
    // is the single failure mode a financial statement must not have.
    $elsewhere = makeAsset(['code' => 'PRIME']);
    $selected = Filament::getTenant();

    ReportPreference::create([
        'user_id' => $this->user->id,
        'report' => IncomeStatement::class,
        'parameters' => ['assetId' => $elsewhere->id, 'comparison' => ComparativeStatementService::BASES[0]],
    ]);

    // A fresh mount, exactly as opening the report from the menu.
    Livewire::test(IncomeStatement::class)
        ->assertSet('comparison', ComparativeStatementService::BASES[0])
        ->assertSet('assetId', $selected->id);

    expect($elsewhere->id)->not->toBe($selected->id);
});

it('opens at today even though the operator last looked at an old period', function () {
    // The design, proved on the page rather than in the resolver. A remembered period would have
    // this report open on March's numbers under a heading nobody read.
    ReportPreference::create([
        'user_id' => $this->user->id,
        'report' => IncomeStatement::class,
        'parameters' => ['assetId' => 1, 'period' => '2020-03'],
    ]);

    Livewire::test(IncomeStatement::class)
        ->assertNotSet('period', '2020-03');
});

/*
|--------------------------------------------------------------------------
| Two consumers of one snapshot, and only one of them wants the tenant
|--------------------------------------------------------------------------
| `ReportParameters::snapshot()` feeds both a SAVED VIEW (re-rendered later by a queue worker with
| no Filament tenant) and a PREFERENCE (re-applied on this operator's next visit, always on-screen
| with a tenant already selected). The standing property is part of what reproduces the first and is
| meaningless to the second — `apply()` skips any key the page does not declare, so it can never be
| re-applied to a preference at all.
|
| Adding it to `snapshot()` therefore reached the wrong consumer AND broke its clearing rule:
| `remember()` deletes the row when nothing is left, because "I deselected the property" is itself
| the preference, and a snapshot carrying one guaranteed key is never empty. Measured: an operator
| who stepped out of a mall kept a preference row pointing at it, and three tests here went red for
| a week because CI is paused and a red push is silent.
|
| Asserted as a PAIR, in both directions, so the split cannot quietly collapse back: routing the
| preference path through `snapshotForSavedView()` breaks the first, and dropping the key from
| `snapshotForSavedView()` breaks the second.
*/
it('keeps the standing property out of a preference and inside a saved view', function () {
    $page = new IncomeStatement;
    $page->assetId = 7;

    // A tenant IS selected — without one the key would be absent from both and this proves nothing.
    expect(Filament::getTenant())->not->toBeNull();

    expect(ReportParameters::snapshot($page))
        ->not->toHaveKey(ReportParameters::PROPERTY_KEY)
        ->and(ReportParameters::snapshotForSavedView($page))
        ->toHaveKey(ReportParameters::PROPERTY_KEY);
});
