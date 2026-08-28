<?php

namespace App\Filament\Admin\Resources\MarketingBudgets\RelationManagers;

use App\Filament\Actions\ReverseDocumentAction;
use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Models\MarketingBudget;
use App\Models\MarketingPost;
use App\Models\MarketingSpend;
use App\Support\Filament\EntitySelect;
use App\Support\ReportCsv;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Records marketing spend (offers / promotions / events / printed work) against
 * a budget. Each create/edit/delete keeps the budget's spent_amount derived via
 * the MarketingSpend model events (FR MKT-1/4/5).
 */
class MarketingSpendsRelationManager extends RelationManager
{
    protected static string $relationship = 'spends';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.marketing_spend.plural');
    }

    public function form(Schema $schema): Schema
    {
        // getOwnerRecord() is declared as Model; naming the real type here is what lets the
        // property reads below (accrued/spent/balance, and asset_id for the campaign picker) be
        // checked rather than assumed. Three of them were sitting in the PHPStan baseline.
        /** @var MarketingBudget $budget */
        $budget = $this->getOwnerRecord();

        return $schema->columns(2)->components([
            TextEntry::make('fund')
                ->hiddenLabel()
                ->columnSpanFull()
                ->html()
                ->state(new HtmlString(
                    // Theme-aware: translucent neutral bg + inherited text colour so it
                    // reads in both light and dark mode (no hardcoded light background).
                    '<div style="display:flex;flex-wrap:wrap;gap:1.25rem;font-size:.875rem;padding:.6rem .85rem;background:rgba(148,163,184,0.15);border:1px solid rgba(148,163,184,0.25);border-radius:.5rem;color:inherit;">'
                    .'<span style="opacity:.8;">'.e(__('admin.tables.marketing_budget.accrued')).': <strong>'.number_format((float) $budget->accrued_amount, 2).'</strong></span>'
                    .'<span style="opacity:.8;">'.e(__('admin.tables.marketing_budget.spent')).': <strong>'.number_format((float) $budget->spent_amount, 2).'</strong></span>'
                    .'<span style="color:#14b8a6;font-weight:600;">'.e(__('admin.tables.marketing_budget.balance')).': <strong>'.number_format((float) $budget->balance(), 2).' EGP</strong></span>'
                    .'</div>'
                )),
            // The campaign this money paid for (module 36) — the join that makes "what did the
            // Ramadan campaign cost, and what did it say" one question. Optional: plenty of spend
            // is not tied to a published post (a printed directory, a banner frame), and
            // requiring one would push operators into inventing a campaign row to record a cost.
            //
            // Scoped to the budget's OWN property, never the portfolio — the same rule as every
            // other cross-record Select. Ordered newest-first because the spend being recorded is
            // almost always against a campaign that just ran.
            EntitySelect::make('marketing_post_id')
                ->label(__('admin.tables.marketing_spend.campaign'))
                ->helperText(__('admin.tables.marketing_spend.campaign_hint'))
                ->entity(MarketingPost::class)
                ->modifyOptionsQuery(fn ($query) => $query->where('asset_id', $budget->asset_id))
                ->placeholder(__('admin.tables.marketing_spend.campaign_none'))
                ->columnSpanFull(),
            Select::make('category')
                ->label(__('admin.tables.marketing_spend.category'))
                ->options(fn () => collect(MarketingSpend::CATEGORIES)
                    ->mapWithKeys(fn ($c) => [$c => __("admin.enums.marketing_spend_category.{$c}")]))
                ->default('other')
                ->required()
                ->native(false),
            TextInput::make('amount')
                ->label(__('admin.tables.marketing_spend.amount'))
                ->numeric()
                ->minValue(0)
                ->required()
                ->helperText(__('admin.tables.marketing_spend.overspend_hint')),
            Select::make('paid_from')
                ->label(__('admin.fields.paid_from'))
                ->options(fn () => __('admin.enums.expense_paid_from'))
                ->default('cash')
                ->required()
                ->native(false),
            DatePicker::make('spent_on')
                ->label(__('admin.tables.marketing_spend.spent_on'))
                ->default(now())
                ->native(false)
                ->required(),
            TextInput::make('receipt_reference')
                ->label(__('admin.tables.marketing_spend.receipt'))
                ->maxLength(255),
            TextInput::make('description')
                ->label(__('admin.tables.marketing_spend.description'))
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: MarketingSpend carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('spent_on')
                    ->label(__('admin.tables.marketing_spend.spent_on'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('category')
                    ->label(__('admin.tables.marketing_spend.category'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.marketing_spend_category.{$state}"))
                    ->color('gray'),
                TextColumn::make('description')
                    ->label(__('admin.tables.marketing_spend.description'))
                    ->limit(40),
                TextColumn::make('amount')
                    ->label(__('admin.tables.marketing_spend.amount'))
                    ->money('EGP')
                    ->weight('bold'),
                TextColumn::make('paid_from')
                    ->label(__('admin.fields.paid_from'))
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.enums.expense_paid_from.{$state}") : '—')
                    ->toggleable(),
                TextColumn::make('receipt_reference')
                    ->label(__('admin.tables.marketing_spend.receipt'))
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()->after(fn () => $this->warnIfOverBudget())
                    ->visible(fn () => auth()->user()?->can('marketing.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('marketing.edit') ?? false),
                // Where the marketing fund went, as a spreadsheet — the record the owner reviews.
                Action::make('export_csv')
                    ->label(__('admin.reports.csv.export'))
                    ->icon('heroicon-o-table-cells')
                    ->color('gray')
                    ->visible(fn () => (auth()->user()?->can('marketing.view') ?? false) && $this->budget()->spends()->exists())
                    ->authorize(fn () => auth()->user()?->can('marketing.view') ?? false)
                    ->action(function () {
                        $budget = $this->budget();
                        $csv = MarketingBudgetResource::spendRegisterCsv($budget);

                        return ReportCsv::stream("marketing-spend-{$budget->asset_id}-{$budget->period_year}", $csv['headers'], $csv['rows']);
                    }),
            ])
            ->recordActions([
                EditAction::make()->after(fn () => $this->warnIfOverBudget())
                    ->visible(fn () => auth()->user()?->can('marketing.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('marketing.edit') ?? false),
                // Was a bare `DeleteAction` on a GL-posting document: no reason asked, nothing
                // recorded but the row leaving the list. The mechanism is unchanged — a soft-delete,
                // which the sweep reads as "no ledger effect" and reverses the entry for — but it is
                // now called what it is and it records why. See ReverseDocumentAction.
                ReverseDocumentAction::make(
                    can: fn () => auth()->user()?->can('marketing.edit') ?? false,
                    label: 'admin.actions.cancel_spend',
                    confirm: 'admin.actions.cancel_spend_confirm',
                    done: 'admin.notifications.spend_cancelled',
                    event: 'cancelled',
                )->after(fn () => $this->warnIfOverBudget()),
            ])
            ->defaultSort('spent_on', 'desc');
    }

    /**
     * "Warn but allow": marketing spend MAY exceed the accrued budget, but if
     * it pushes the balance negative we surface a non-blocking warning so the
     * overspend is visible (FR MKT-5 — confirmed behaviour 2026-06-25).
     */
    /** The owning budget, typed (getOwnerRecord() is declared as the base Model). */
    private function budget(): MarketingBudget
    {
        /** @var MarketingBudget $record */
        $record = $this->getOwnerRecord();

        return $record;
    }

    protected function warnIfOverBudget(): void
    {
        $balance = $this->getOwnerRecord()->fresh()->balance();

        if ($balance < 0) {
            Notification::make()
                ->warning()
                ->title(__('admin.tables.marketing_spend.overspend_title'))
                ->body(__('admin.tables.marketing_spend.overspend_body', [
                    'amount' => number_format(abs($balance), 2),
                ]))
                ->send();
        }
    }
}
