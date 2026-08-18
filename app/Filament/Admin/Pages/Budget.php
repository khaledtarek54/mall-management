<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Models\BudgetLine;
use App\Services\Accounting\BudgetService;
use App\Support\TenantScope;
use BackedEnum;
use DomainException;
use Filament\Forms\Components\Select;
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
 * The operating budget: what each P&L account is expected to do, per property, per month.
 *
 * The income statement could already compare a period against the one before it or the same one a
 * year earlier. Both answer "is this normal?" — neither answered **"is this what we planned?"**,
 * which is the question a mall's monthly review is built around. Nothing in the schema held a
 * budget at all except the marketing spend pot.
 *
 * Entry is a paste, for the same reason the opening balances are: a budget is written in a
 * spreadsheet, and re-typing forty account lines into a form is where the mistakes come from.
 * `code, amount` spreads an annual figure across twelve months; `code, month, amount` sets one
 * month exactly, because a mall is seasonal and Ramadan is not one twelfth of the year.
 *
 * **Importing REPLACES that year's budget** rather than adding to it — see `BudgetService::import()`.
 * The screen says so before you press the button, because unlike an opening balance there is no
 * draft-and-review step between the paste and the result.
 */
class Budget extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static ?string $slug = 'budget';

    protected static ?int $navigationSort = 27;

    protected string $view = 'filament.pages.budget';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        // Setting the plan the business is measured against is a management act, not a reporting
        // one — so it is gated on managing settings rather than on `reports.view`.
        return Auth::user()?->can('settings.manage') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.budget.title');
    }

    public function getTitle(): string
    {
        return __('admin.budget.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin.budget.subheading');
    }

    public function mount(): void
    {
        $this->form->fill(['year' => (int) now()->year, 'lines' => null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(__('admin.budget.sections.input'))
                ->description(__('admin.budget.sections.input_description'))
                ->components([
                    Select::make('year')
                        ->label(__('admin.budget.fields.year'))
                        ->options(fn () => collect(range((int) now()->year + 1, (int) now()->year - 3))
                            ->mapWithKeys(fn (int $y) => [$y => (string) $y])->all())
                        ->native(false)
                        ->required(),
                    Textarea::make('lines')
                        ->label(__('admin.budget.fields.lines'))
                        ->rows(12)
                        ->required()
                        ->helperText(__('admin.budget.helpers.lines'))
                        ->placeholder("41101001, 2400000\n51201001, 3, 180000"),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [GuideAction::for(static::class)];
    }

    /** What is budgeted for the selected year right now, so a re-import is never a blind overwrite. */
    public function existing(): array
    {
        $assetId = TenantScope::currentAssetId();
        $year = (int) ($this->data['year'] ?? now()->year);

        if ($assetId === null) {
            return ['lines' => 0, 'total' => 0.0, 'year' => $year];
        }

        $lines = BudgetLine::query()->where('asset_id', $assetId)->where('fiscal_year', $year)->get();

        return ['lines' => $lines->count(), 'total' => round((float) $lines->sum('amount'), 2), 'year' => $year];
    }

    public function import(): void
    {
        // The real gate: canAccess() governs the menu and the route, this governs the write.
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        // Clamped from the panel, never taken from the payload.
        $assetId = TenantScope::currentAssetId();
        abort_if($assetId === null, 403);

        try {
            $result = app(BudgetService::class)->import(
                (string) ($state['lines'] ?? ''),
                (int) $state['year'],
                $assetId,
            );
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->form->fill(['year' => (int) $state['year'], 'lines' => null]);

        Notification::make()
            ->success()
            ->title(__('admin.budget.imported', ['accounts' => $result['accounts'], 'year' => $state['year']]))
            ->body(__('admin.budget.imported_body'))
            ->send();
    }
}
