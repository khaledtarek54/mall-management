<?php

use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\ListFacilityWorkOrders;
use App\Filament\Admin\Resources\SlaPolicies\Pages\ListSlaPolicies;
use App\Models\FacilityWorkOrder;
use App\Models\SlaPolicy;
use App\Support\SlaResolver;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * An hour is a word, and the word belongs to the reader (SW-079).
 *
 * Three column descriptions composed the count and then glued a bare Latin `h` onto it, so an
 * Arabic operator read «متأخر · 67h» — a Latin letter used as a unit inside a right-to-left
 * sentence. Measured 2026-09-03 by tokenising all 629 files under `app/Filament`: exactly three
 * sites in two files.
 *
 * `ArabicPanelHasNoEnglishChromeConformanceTest` reads `getLabel()` on every column, filter and
 * action and never `getDescriptionBelow()`, which is why it could not see this — the same shape as
 * every "a gate checks a weaker property than its name" note in CLAUDE.md. The last case here is the
 * gate for the class, so a fourth site cannot ship.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
});

afterEach(fn () => app()->setLocale('en'));

it('writes the overrun on both SLA clocks in the reader own language', function () {
    // Both deadlines are given explicitly, so `stampSlaClocks()` (which only fills a NULL deadline)
    // leaves them alone and `sla_clock` stays null — the calendar branch of `overrunHours()`, which
    // is plain elapsed time and therefore deterministic. The extra minutes are slack: `diffInHours`
    // is truncated, so an exact multiple could read one hour short.
    $order = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => FacilityWorkOrder::TYPE_CM,
        'execution_type' => FacilityWorkOrder::EXECUTION_INTERNAL,
        'title' => 'Chiller down',
        'description' => 'No cooling on level 2.',
        'trade_id' => tradeId('hvac'),
        'status' => 'open',
        'priority' => 'high',
        'scheduled_for' => now()->toDateString(),
        'target_response_at' => now()->subMinutes(545),
        'target_resolution_at' => now()->subMinutes(305),
    ]);

    expect($order->isResponseBreached())->toBeTrue()
        ->and($order->isOverdue())->toBeTrue()
        ->and($order->hoursOverResponseSla())->toBe(9)
        ->and($order->hoursOverSla())->toBe(5);

    asTenant($this->asset, function () use ($order) {
        $table = Livewire::test(ListFacilityWorkOrders::class)->instance()->getTable();

        // AFTER the component is built, deliberately: the descriptions are closures evaluated on
        // read, so this is the locale that decides them, and setting it earlier would depend on
        // whether Livewire's test harness runs the panel's SetLocale middleware.
        app()->setLocale('ar');

        $response = $table->getColumn('target_response_at');
        $response->record($order);

        $resolution = $table->getColumn('target_resolution_at');
        $resolution->record($order);

        expect((string) $response->getDescriptionBelow())
            ->toBe('بلا استجابة · '.$order->hoursOverResponseSla().' ساعة')
            ->and((string) $resolution->getDescriptionBelow())
            ->toBe('متأخر · '.$order->hoursOverSla().' ساعة');
    });
});

it('writes the operator default beside a property override in the reader own language', function () {
    $policy = SlaPolicy::create([
        'asset_id' => $this->asset->id,
        'request_type' => SlaPolicy::ANY_TYPE,
        'priority' => 'high',
        'resolve_hours' => 8,
        'is_active' => true,
    ]);

    asTenant($this->asset, function () use ($policy) {
        $column = Livewire::test(ListSlaPolicies::class)->instance()->getTable()->getColumn('resolve_hours');

        app()->setLocale('ar');

        $column->record($policy);

        expect((string) $column->getDescriptionBelow())
            ->toBe('الإعداد العام: '.SlaResolver::globalHoursFor('high').' ساعة');
    });
});

it('leaves no Latin hour suffix anywhere in either panel', function () {
    $files = filamentSources();

    // The premise, before anything is reported on it: a sweep that stopped finding files would pass
    // while checking nothing. 629 at HEAD on 2026-09-03.
    expect(count($files))->toBeGreaterThan(400);

    $offenders = [];

    foreach ($files as $file) {
        // Comments stripped, so a docblock explaining this rule is not itself an offender. The shape
        // is an expression followed by a bare one-letter string used as a unit.
        if (preg_match("/\\.\\s*'h'/", sourceWithoutComments($file))) {
            $offenders[] = str_replace(base_path().'/', '', $file);
        }
    }

    expect($offenders)->toBe([], 'an hour count glued to a Latin "h" renders a Latin letter as the '
        .'unit on the Arabic panel — compose `admin.facility.sla.hours_count` instead');
});
