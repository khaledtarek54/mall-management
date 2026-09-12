<?php

use App\Filament\Admin\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Models\JournalEntry;
use App\Models\MarketingBudget;
use App\Models\MarketingSpend;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Support\SourceDocumentLabel;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The journal register names an entry's source in the reader's language, never by its morph alias
 * (the reports audit, 2026-09-12).
 *
 * The "Source document" column tried `number`, then `reference`, then `label()`, and then printed
 * the alias — `depreciation_entry` on every one of the 141 depreciation rows of the demo books,
 * `tenant_credit_application` beside a credit applied — a raw storage key on the one register an
 * auditor reads end to end. `SourceDocumentLabel` composes what already existed: the document's own
 * number where it has one, else its KIND from `ActivityVocabulary`'s bilingual subjects and its id.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** A marketing spend — a posting source with no number, no reference and no label() of its own. */
function sourceWithNoNumber(int $assetId): MarketingSpend
{
    $budget = MarketingBudget::forPeriod($assetId, 2026);

    $spend = MarketingSpend::create([
        'marketing_budget_id' => $budget->id,
        'description' => 'Ramadan campaign — mall dressing',
        'amount' => 40000,
        'spent_on' => '2026-06-01',
        'paid_from' => 'bank',
    ]);

    app(LedgerPoster::class)->sync($spend->fresh());

    return $spend;
}

it('names a source with no number by its kind and id, in the reader\'s language', function () {
    $spend = sourceWithNoNumber($this->asset->id);
    $entry = JournalEntry::where('source_type', 'marketing_spend')->where('source_id', $spend->id)->firstOrFail();

    app()->setLocale('en');
    expect(SourceDocumentLabel::for($entry->source, $entry->source_type))->toBe(__('admin.activity.subjects.marketing_spend').' #'.$spend->id)
        ->and(__('admin.activity.subjects.marketing_spend'))->not->toStartWith('admin.');

    app()->setLocale('ar');
    expect(SourceDocumentLabel::for($entry->source, $entry->source_type))
        ->toMatch('/\p{Arabic}/u')
        ->toEndWith('#'.$spend->id)
        ->not->toContain('marketing_spend');
});

it('still prefers the document\'s own number, and names a deleted source by its kind alone', function () {
    $invoice = makeInvoice(makeLease(makeUnit($this->asset)));

    // The control: a document with a number is named by it.
    expect(SourceDocumentLabel::for($invoice, 'invoice'))->toBe($invoice->number);

    // A source that no longer exists: the row still knows its kind, and that is what prints —
    // never the alias, and never "Other".
    app()->setLocale('ar');
    expect(SourceDocumentLabel::for(null, 'depreciation_entry'))->toMatch('/\p{Arabic}/u')
        ->and(SourceDocumentLabel::for(null, 'depreciation_entry'))->not->toBe('depreciation_entry')
        ->and(SourceDocumentLabel::for(null, null))->toBeNull();
});

it('renders that name on the journal register, in both languages', function () {
    $spend = sourceWithNoNumber($this->asset->id);

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        Livewire::test(ListJournalEntries::class)
            ->assertOk()
            ->assertSee(__('admin.activity.subjects.marketing_spend').' #'.$spend->id)
            ->assertDontSee('marketing_spend');
    }
});
