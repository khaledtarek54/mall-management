<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\KeepsFilterAnswered;
use App\Services\Accounting\TaxDepreciationService;
use App\Support\TaxDepreciation as Pools;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * The Egyptian income-tax depreciation schedule (Law 91/2005, Art. 25).
 *
 * The accounting book depreciates straight-line over a chosen useful life and posts to the GL; this
 * is the TAX basis on the same assets — statutory rates, and for most classes a pooled
 * diminishing-value calculation. Until this page existed no tax-basis figure could be produced at
 * all, so the corporate return could not be prepared from the register however complete it was.
 *
 * **A schedule, not a second ledger.** Nothing here posts. Egypt files single-book, so the
 * statutory accounts stay on the accounting basis and this is a computation attached to the return.
 * The row that earns the page is the last one: the DIFFERENCE from the book charge, which is the
 * temporary difference an accountant carries into deferred tax.
 */
class TaxDepreciation extends Page implements DeliverableReport, HasSchemas
{
    use ExportsReport;
    use InteractsWithSchemas;
    use KeepsFilterAnswered;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected string $view = 'filament.pages.tax-depreciation';

    protected static string $routePath = 'tax-depreciation';

    /** @var array<string, mixed> */
    public array $data = [];

    public ?int $year = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('reports.view') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.tax_depreciation.title');
    }

    public function getTitle(): string
    {
        return __('admin.tax_depreciation.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin.tax_depreciation.subheading');
    }

    public function mount(): void
    {
        $this->year = (int) now()->year;
        $this->form->fill(['year' => $this->year]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('year')
                ->label(__('admin.tax_depreciation.fields.year'))
                ->options(fn () => collect(range((int) now()->year, (int) now()->year - 9))
                    ->mapWithKeys(fn (int $y) => [$y => (string) $y])->all())
                ->native(false)
                // NOT CLEARABLE. Filament renders a blank option on every Select unless it is
                // told otherwise, and clearing one sets the bound Livewire property to null —
                // which UNSETS a non-nullable typed property, so every later read of it throws
                // and the page 500s. Measured on all seven report screens that had it.
                //
                // The fix is the control, not the type: there is no such thing as "no fiscal
                // year" or "no period" for a statement, so offering the blank was offering an
                // action that cannot work. Where a blank IS an answer it stays — `period` on
                // the shared ledger bar means "full year", says so in its placeholder, and is
                // typed `?string` accordingly.
                ->selectablePlaceholder(false)
                ->live()
                ->afterStateUpdated(fn ($state) => $this->year = (int) $state),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            ...$this->exportActions(),
        ];
    }

    /** @return array{year:int, pools:array<int,array>, tax_total:float, book_total:float, difference:float} */
    public function report(): array
    {
        return app(TaxDepreciationService::class)->schedule($this->year, TenantScope::visibleAssetIds());
    }

    /**
     * The report as CSV — the same numbers a scheduled delivery emails.
     *
     * @return array{filename:string, headers:array<int,string>, rows:array<int,array<int,string|float>>}
     */
    public function reportCsv(): array
    {
        $report = $this->report();

        $rows = [];

        foreach ($report['pools'] as $pool) {
            $rows[] = [
                __("admin.tax_depreciation.pools.{$pool['pool']}"),
                $pool['rate'].'%',
                $pool['pooled'] ? __('admin.tax_depreciation.pooled') : __('admin.tax_depreciation.straight_line'),
                $pool['opening'], $pool['additions'], $pool['disposals'],
                $pool['base'], $pool['depreciation'], $pool['closing'],
            ];
        }

        // The comparison is part of the report, not decoration — an accountant reading the CSV
        // needs the same bottom line the screen shows.
        $rows[] = [__('admin.tax_depreciation.tax_total'), '', '', '', '', '', '', $report['tax_total'], ''];
        $rows[] = [__('admin.tax_depreciation.book_total'), '', '', '', '', '', '', $report['book_total'], ''];
        $rows[] = [__('admin.tax_depreciation.difference'), '', '', '', '', '', '', $report['difference'], ''];

        return [
            'filename' => "tax-depreciation-{$report['year']}",
            'headers' => [
                __('admin.tax_depreciation.table.pool'),
                __('admin.tax_depreciation.table.rate'),
                __('admin.tax_depreciation.table.basis'),
                __('admin.tax_depreciation.table.opening'),
                __('admin.tax_depreciation.table.additions'),
                __('admin.tax_depreciation.table.disposals'),
                __('admin.tax_depreciation.table.base'),
                __('admin.tax_depreciation.table.depreciation'),
                __('admin.tax_depreciation.table.closing'),
            ],
            'rows' => $rows,
        ];
    }

    /** @return array<int, string> */
    public static function pools(): array
    {
        return Pools::pools();
    }

    /**
     * `$year` is never blank — the Select offers no clear, and a payload that sends one is
     * restored here rather than left to break the page. {@see KeepsFilterAnswered}
     *
     * @return array<string, mixed>
     */
    protected function answerableFilters(): array
    {
        return ['year' => (int) now()->year];
    }
}
