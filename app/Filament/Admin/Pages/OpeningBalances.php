<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Services\Accounting\ImportOpeningBalancesService;
use App\Support\TenantScope;
use BackedEnum;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Load the operator's opening trial balance at go-live, from the accountant's own spreadsheet.
 *
 * Opening AR already arrives through `OpeningInvoiceImporter` and opening fixed assets through
 * `FixedAssetImporter`, so the two sub-ledgers were covered and the GENERAL ledger was not: cash,
 * bank, AP, accruals, capital and retained earnings had to be typed into the manual journal screen
 * one line at a time, from a trial balance that is routinely forty rows. Nobody types forty
 * balanced rows without a mistake, and a mistake in an opening balance follows every report made
 * afterwards.
 *
 * **It creates a DRAFT, never a posted entry** — see `ImportOpeningBalancesService` for why that is
 * the design and not a shortcut. In one line: an import run twice would otherwise double the whole
 * balance sheet in silence, and posting is the accountant's assertion rather than the importer's.
 *
 * The preview is the feature as much as the import is. It resolves every account code against the
 * chart and reports every bad row AT ONCE, because fixing a forty-row paste one exception at a
 * time is forty round trips.
 */
class OpeningBalances extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $slug = 'opening-balances';

    protected string $view = 'filament.pages.opening-balances';

    /** @var array<string, mixed> */
    public array $data = [];

    /** The parsed preview, recomputed on demand rather than on every keystroke. */
    public ?array $preview = null;

    public static function canAccess(): bool
    {
        // Creating a draft journal entry is exactly what this does, so it is gated on exactly that.
        return Auth::user()?->can('journal_entries.create') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.opening_balances.title');
    }

    public function getTitle(): string
    {
        return __('admin.opening_balances.title');
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
        ];
    }

    public function mount(): void
    {
        $this->form->fill(['as_at' => null, 'trial_balance' => null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(__('admin.opening_balances.sections.input'))
                ->description(__('admin.opening_balances.sections.input_description'))
                ->components([
                    DatePicker::make('as_at')
                        ->label(__('admin.opening_balances.fields.as_at'))
                        ->native(false)
                        ->required()
                        ->helperText(__('admin.opening_balances.helpers.as_at')),
                    Textarea::make('trial_balance')
                        ->label(__('admin.opening_balances.fields.trial_balance'))
                        ->rows(12)
                        ->required()
                        ->helperText(__('admin.opening_balances.helpers.trial_balance'))
                        ->placeholder("11101001, 250000, 0\n21101001, 0, 180000\n31101001, 0, 70000"),
                ]),
        ]);
    }

    /** Parse and check WITHOUT writing — the operator sees every bad row before committing. */
    public function preview(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        $this->preview = app(ImportOpeningBalancesService::class)
            ->preview((string) ($state['trial_balance'] ?? ''));
    }

    public function import(): void
    {
        // The real gate. `canAccess()` governs the menu and the route; this governs the write, and
        // a Livewire method is dispatchable without either.
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        // Clamped from the panel, never taken from the payload — the same rule every other
        // asset-stamping write in this codebase follows.
        $assetId = TenantScope::currentAssetId();
        abort_if($assetId === null, 403);

        try {
            $entry = app(ImportOpeningBalancesService::class)->import(
                (string) ($state['trial_balance'] ?? ''),
                CarbonImmutable::parse($state['as_at']),
                $assetId,
            );
        } catch (DomainException $e) {
            // A refusal, not a fault: bad paste, unknown account, does not balance.
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->preview = null;
        $this->form->fill(['as_at' => null, 'trial_balance' => null]);

        Notification::make()
            ->success()
            ->title(__('admin.opening_balances.imported', ['number' => $entry->number]))
            ->body(__('admin.opening_balances.imported_body'))
            ->send();
    }
}
