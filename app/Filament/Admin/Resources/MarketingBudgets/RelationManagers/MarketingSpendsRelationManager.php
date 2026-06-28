<?php

namespace App\Filament\Admin\Resources\MarketingBudgets\RelationManagers;

use App\Models\MarketingSpend;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
        $budget = $this->getOwnerRecord();

        return $schema->columns(2)->components([
            \Filament\Forms\Components\Placeholder::make('fund')
                ->hiddenLabel()
                ->columnSpanFull()
                ->content(new \Illuminate\Support\HtmlString(
                    '<div style="display:flex;flex-wrap:wrap;gap:1.5rem;font-size:.875rem;padding:.5rem .75rem;background:#f1f5f9;border-radius:.5rem;">'
                    .'<span>'.e(__('admin.tables.marketing_budget.accrued')).': <strong>'.number_format((float) $budget->accrued_amount, 2).'</strong></span>'
                    .'<span>'.e(__('admin.tables.marketing_budget.spent')).': <strong>'.number_format((float) $budget->spent_amount, 2).'</strong></span>'
                    .'<span style="color:#0f766e;">'.e(__('admin.tables.marketing_budget.balance')).': <strong>'.number_format((float) $budget->balance(), 2).' EGP</strong></span>'
                    .'</div>'
                )),
            Select::make('category')
                ->label(__('admin.tables.marketing_spend.category'))
                ->options(fn () => collect(MarketingSpend::CATEGORIES)->mapWithKeys(fn ($c) => [$c => Str::headline($c)]))
                ->default('other')
                ->required()
                ->native(false),
            TextInput::make('amount')
                ->label(__('admin.tables.marketing_spend.amount'))
                ->numeric()
                ->minValue(0)
                ->required()
                ->helperText(__('admin.tables.marketing_spend.overspend_hint')),
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
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('spent_on')
                    ->label(__('admin.tables.marketing_spend.spent_on'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('category')
                    ->label(__('admin.tables.marketing_spend.category'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Str::headline($state))
                    ->color('gray'),
                TextColumn::make('description')
                    ->label(__('admin.tables.marketing_spend.description'))
                    ->limit(40),
                TextColumn::make('amount')
                    ->label(__('admin.tables.marketing_spend.amount'))
                    ->money('EGP')
                    ->weight('bold'),
                TextColumn::make('receipt_reference')
                    ->label(__('admin.tables.marketing_spend.receipt'))
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()->after(fn () => $this->warnIfOverBudget()),
            ])
            ->recordActions([
                EditAction::make()->after(fn () => $this->warnIfOverBudget()),
                DeleteAction::make(),
            ])
            ->defaultSort('spent_on', 'desc');
    }

    /**
     * "Warn but allow": marketing spend MAY exceed the accrued budget, but if
     * it pushes the balance negative we surface a non-blocking warning so the
     * overspend is visible (FR MKT-5 — confirmed behaviour 2026-06-25).
     */
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
