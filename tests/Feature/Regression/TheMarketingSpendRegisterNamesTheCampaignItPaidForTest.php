<?php

use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Filament\Admin\Resources\MarketingBudgets\Pages\EditMarketingBudget;
use App\Filament\Admin\Resources\MarketingBudgets\RelationManagers\MarketingSpendsRelationManager;
use App\Models\MarketingBudget;
use App\Models\MarketingPost;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * SW-187 — `marketing_spends.marketing_post_id` was write-only.
 *
 * Measured at HEAD 2026-09-04: the column is collected by the spend form
 * (`MarketingSpendsRelationManager:72`) and read back by NOTHING in `app/Filament` — the spend
 * table declared six columns and no `->filters()` block at all, and
 * `MarketingBudgetResource::spendRegisterCsv()` emitted six columns, none of them the campaign.
 * `MarketingPost::spends()` had no reader either. So the join the migration was written for —
 * "what did the Ramadan campaign cost, and what did it say" — could be recorded and never asked.
 *
 * The filter brings its own hazard and this file pins that too: an "Export" beside a filter that
 * ignores it hands the owner a different set from the one on screen, under a total that will not
 * match the list above it, which reads as an arithmetic fault rather than as a filter.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset(['code' => 'HW']);
    Filament::setTenant($this->asset);

    $this->budget = MarketingBudget::create([
        'asset_id' => $this->asset->id, 'period_year' => 2026, 'accrued_amount' => 100000,
    ]);

    $this->campaign = MarketingPost::create([
        'asset_id' => $this->asset->id, 'type' => 'offer', 'status' => 'published',
        'title' => 'Ramadan 2026',
    ]);

    $this->attributed = $this->budget->spends()->create([
        'marketing_post_id' => $this->campaign->id, 'category' => 'printed_work',
        'description' => 'Ramadan artwork', 'amount' => 200, 'paid_from' => 'bank',
        'spent_on' => '2026-03-02',
    ]);

    // Not tied to a campaign is the NORMAL state — a banner frame, a printed directory — which is
    // why the form never made it required and why the register must render it blank, not absent.
    $this->general = $this->budget->spends()->create([
        'category' => 'other', 'description' => 'Directory reprint', 'amount' => 300,
        'paid_from' => 'cash', 'spent_on' => '2026-03-01',
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('names the campaign each line paid for in the spend register the owner reads', function () {
    $csv = MarketingBudgetResource::spendRegisterCsv($this->budget->fresh());

    // APPENDED, never inserted: this file is a spreadsheet somebody already keeps a pivot over, so
    // moving column D would silently move every figure in it.
    expect($csv['headers'][3])->toBe(__('admin.tables.marketing_spend.amount'))
        ->and(end($csv['headers']))->toBe(__('admin.tables.marketing_spend.campaign'));

    $rows = collect($csv['rows']);

    expect($rows->firstWhere(2, 'Ramadan artwork')[6])->toBe('Ramadan 2026')
        ->and($rows->firstWhere(2, 'Directory reprint')[6])->toBe('')
        // Control: the total still ties to the fund, and the amount is still column D.
        ->and((float) $rows->last()[3])->toBe(500.0)
        ->and(round((float) $this->budget->fresh()->spent_amount, 2))->toBe(500.0);
});

it('shows the campaign on the spend list and narrows the list to it', function () {
    Livewire::test(MarketingSpendsRelationManager::class, [
        'ownerRecord' => $this->budget, 'pageClass' => EditMarketingBudget::class,
    ])
        ->assertOk()
        ->assertSee('Ramadan 2026')
        ->assertCanSeeTableRecords([$this->attributed, $this->general])
        ->filterTable('marketing_post_id', $this->campaign->id)
        ->assertCanSeeTableRecords([$this->attributed])
        ->assertCanNotSeeTableRecords([$this->general]);
});

it('exports what is on the screen, not the whole fund, once the campaign filter is on', function () {
    $download = Livewire::test(MarketingSpendsRelationManager::class, [
        'ownerRecord' => $this->budget, 'pageClass' => EditMarketingBudget::class,
    ])
        ->filterTable('marketing_post_id', $this->campaign->id)
        ->callTableAction('export_csv')
        ->effects['download'] ?? null;

    expect($download)->not->toBeNull();

    $csv = (string) base64_decode((string) $download['content']);

    expect($csv)->toContain('Ramadan artwork')
        ->and($csv)->not->toContain('Directory reprint')
        // The whole point of making the export follow the filter: the total under the list is the
        // total OF the list.
        ->and($csv)->toContain('200');
});

it('still exports the whole fund when nothing is filtered', function () {
    // Control: the register's original job is unchanged, so a caller passing no query gets exactly
    // what it always got.
    $download = Livewire::test(MarketingSpendsRelationManager::class, [
        'ownerRecord' => $this->budget, 'pageClass' => EditMarketingBudget::class,
    ])
        ->callTableAction('export_csv')
        ->effects['download'] ?? null;

    expect($download)->not->toBeNull();

    $csv = (string) base64_decode((string) $download['content']);

    expect($csv)->toContain('Ramadan artwork')
        ->and($csv)->toContain('Directory reprint');
});
