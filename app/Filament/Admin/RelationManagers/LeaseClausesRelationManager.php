<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Models\LeaseClause;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The lease abstract — the legal terms that are not money.
 *
 * Voyager's clause register *(cited,
 * `docs/benchmarks/yardi/01-yardi-lease-administration.md` §7)*, on the lease it belongs to.
 *
 * **The numeric fields appear only for the clauses that carry a number.** A radius restriction has
 * kilometres, a co-tenancy has an occupancy floor, a kick-out has a sales threshold; a signage
 * clause has none of them. Showing all four on every type would put three empty boxes in front of
 * an operator abstracting a guarantor clause, and a field that is blank on most records stops being
 * read on the ones where it matters.
 */
class LeaseClausesRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'clauses';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.lease_clauses.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label(__('admin.fields.clause_type'))
                ->options(__('admin.enums.lease_clause_type'))
                ->required()
                ->native(false)
                ->live()
                ->helperText(__('admin.lease_clauses.type_helper')),

            Textarea::make('summary')
                ->label(__('admin.fields.clause_summary'))
                ->rows(3)
                ->columnSpanFull()
                ->helperText(__('admin.lease_clauses.summary_helper')),

            // Co-tenancy: the occupancy floor below which the protection bites.
            TextInput::make('threshold_pct')
                ->label(__('admin.fields.clause_threshold_pct'))
                ->numeric()
                ->suffix('%')
                ->minValue(0)
                ->maxValue(100)
                ->visible(fn (Get $get) => $get('type') === LeaseClause::TYPE_CO_TENANCY)
                ->helperText(__('admin.lease_clauses.threshold_pct_helper')),

            // Kick-out: the sales figure the tenant must reach to keep the landlord out.
            TextInput::make('threshold_amount')
                ->label(__('admin.fields.clause_threshold_amount'))
                ->prefix('EGP')
                ->numeric()
                ->minValue(0)
                ->visible(fn (Get $get) => $get('type') === LeaseClause::TYPE_KICK_OUT)
                ->helperText(__('admin.lease_clauses.threshold_amount_helper')),

            TextInput::make('radius_km')
                ->label(__('admin.fields.clause_radius_km'))
                ->numeric()
                ->suffix('km')
                ->minValue(0)
                ->visible(fn (Get $get) => $get('type') === LeaseClause::TYPE_RADIUS)
                ->helperText(__('admin.lease_clauses.radius_helper')),

            TextInput::make('notice_days')
                ->label(__('admin.fields.clause_notice_days'))
                ->numeric()
                ->suffix('days')
                ->minValue(0)
                // Any clause conferring a RIGHT tends to carry a notice period; the ones that
                // describe a standing obligation (insurance, repairs, signage) do not.
                ->visible(fn (Get $get) => in_array($get('type'), [
                    LeaseClause::TYPE_CO_TENANCY,
                    LeaseClause::TYPE_KICK_OUT,
                    LeaseClause::TYPE_ASSIGNMENT,
                ], true))
                ->helperText(__('admin.lease_clauses.notice_helper')),

            DatePicker::make('applies_from')
                ->label(__('admin.fields.clause_applies_from'))
                ->helperText(__('admin.lease_clauses.help.applies_from'))
                ->native(false)
                ->displayFormat('d/m/Y'),

            DatePicker::make('applies_to')
                ->label(__('admin.fields.clause_applies_to'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->afterOrEqual('applies_from')
                ->helperText(__('admin.lease_clauses.applies_to_helper')),

            TextInput::make('source_reference')
                ->label(__('admin.fields.clause_source_reference'))
                ->maxLength(64)
                ->helperText(__('admin.lease_clauses.source_helper')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: a clause has no folded blob and no searchable column, and
            // `TableDefaults` would otherwise render one that always returns nothing.
            ->searchable(false)
            ->columns([
                TextColumn::make('type')
                    ->label(__('admin.fields.clause_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.enums.lease_clause_type')[$state] ?? $state)
                    // The two the benchmark calls contingent money are coloured apart, because
                    // "which of these can cost us?" is the question this register exists to answer.
                    ->color(fn (string $state) => in_array($state, LeaseClause::CONTINGENT_MONEY, true) ? 'warning' : 'gray'),

                TextColumn::make('summary')
                    ->label(__('admin.fields.clause_summary'))
                    ->limit(60)
                    ->tooltip(fn (LeaseClause $record) => $record->summary)
                    ->placeholder('—')
                    ->wrap(),

                // One column for whichever number this clause carries — three mostly-empty columns
                // would be worse than one that says what it is showing.
                TextColumn::make('threshold_pct')
                    ->label(__('admin.fields.clause_trigger'))
                    ->placeholder('—')
                    ->state(fn (LeaseClause $record): ?string => match (true) {
                        $record->threshold_pct !== null => rtrim(rtrim(number_format((float) $record->threshold_pct, 2), '0'), '.').'%',
                        $record->threshold_amount !== null => 'EGP '.number_format((float) $record->threshold_amount, 2),
                        $record->radius_km !== null => rtrim(rtrim(number_format((float) $record->radius_km, 2), '0'), '.').' km',
                        default => null,
                    }),

                TextColumn::make('applies_to')
                    ->label(__('admin.fields.clause_applies_to'))
                    ->date('d/m/Y')
                    ->placeholder(__('admin.lease_clauses.open_ended'))
                    ->badge()
                    ->color(fn (LeaseClause $record) => $record->isInForceOn() ? 'success' : 'gray'),

                TextColumn::make('source_reference')
                    ->label(__('admin.fields.clause_source_reference'))
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->size('xs'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.fields.clause_type'))
                    ->options(__('admin.enums.lease_clause_type')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.actions.add_clause'))
                    ->modalHeading(__('admin.actions.add_clause'))
                    ->visible(fn (): bool => $this->canWrite())
                    ->authorize(fn (): bool => $this->canWrite()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->canWrite())
                    ->authorize(fn (): bool => $this->canWrite()),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->canWrite())
                    ->authorize(fn (): bool => $this->canWrite()),
            ])
            ->defaultSort('type')
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading(__('admin.lease_clauses.empty_heading'))
            ->emptyStateDescription(__('admin.lease_clauses.empty_description'));
    }

    /** Named once so `visible()` and `authorize()` cannot drift — the project's double-gate rule. */
    protected function canWrite(): bool
    {
        return auth()->user()?->can('leases.edit') ?? false;
    }
}
