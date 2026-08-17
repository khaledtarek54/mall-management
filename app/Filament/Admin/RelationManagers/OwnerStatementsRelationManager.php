<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use App\Models\OwnerStatement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The per-owner statements a run produced — the module's actual output.
 *
 * A run could be generated, finalised, revised, PDF'd and sent, and the statements themselves were
 * never listed anywhere: the working breakdown showed the property's revenue and expenses in total,
 * and the PDF showed one owner's copy. "Who gets what out of this run, and has it gone out?" — the
 * question the run exists to answer — had no screen.
 *
 * Read-only. A statement is DERIVED: `OwnerStatementService` computes each owner's share from their
 * tenure-weighted ownership over the period, and finalising freezes it. Editing a row here would
 * put a number in front of an owner that the ledger cannot reproduce.
 */
class OwnerStatementsRelationManager extends RelationManager
{
    protected static string $relationship = 'statements';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.owner_statements.statements_title');
    }

    /** The same gate the run's PDF and send actions use — statements carry owner-level money. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return OwnerStatementRunResource::canViewStatements();
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.fields.reference'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->searchable(),

                TextColumn::make('owner.name')
                    ->label(__('admin.owner_statements.fields.owner'))
                    ->searchable(),

                // The share is tenure-WEIGHTED: an owner who sold mid-period does not get a whole
                // period's income, and showing the raw percentage beside the weight is what makes a
                // part-period figure explicable rather than surprising.
                TextColumn::make('ownership_percentage')
                    ->label(__('admin.owner_statements.fields.ownership_percentage'))
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%')
                    ->description(fn (OwnerStatement $record) => (float) $record->weight < 1
                        ? __('admin.owner_statements.part_period', [
                            'pct' => rtrim(rtrim(number_format((float) $record->weight * 100, 1), '0'), '.'),
                        ])
                        : null),

                TextColumn::make('share_revenue')
                    ->label(__('admin.owner_statements.pdf.revenue'))
                    ->money('EGP')
                    ->toggleable(),

                TextColumn::make('share_expense')
                    ->label(__('admin.owner_statements.pdf.expenses'))
                    ->money('EGP')
                    ->toggleable(),

                TextColumn::make('owner_share')
                    ->label(__('admin.owner_statements.fields.owner_share'))
                    ->money('EGP')
                    ->weight('bold'),

                // What has actually reached them, against what the statement says they are owed.
                TextColumn::make('paid_to_date')
                    ->label(__('admin.owner_statements.fields.paid_to_date'))
                    ->money('EGP')
                    ->color(fn (OwnerStatement $record) => (float) $record->paid_to_date >= (float) $record->owner_share
                        ? 'success'
                        : 'warning'),

                TextColumn::make('status')
                    ->label(__('admin.filters.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.owner_statements.statuses.{$state}")),

                TextColumn::make('sent_at')
                    ->label(__('admin.owner_statements.fields.sent_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('admin.owner_statements.not_sent'))
                    ->toggleable(),
            ])
            ->defaultSort('owner_share', 'desc')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading(__('admin.owner_statements.empty_statements_heading'))
            ->emptyStateDescription(__('admin.owner_statements.empty_statements_description'));
    }
}
