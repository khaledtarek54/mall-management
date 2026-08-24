<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Models\LeaseEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The lease's commercial history — the timeline story LE-01 asks for.
 *
 * **Read-only by construction.** There is no create, edit or delete action here, and the model
 * refuses all three anyway. Every line arrives from the service that made the change (a rent
 * modification, a relief, a holdover conversion), which is the only way the history stays true: a
 * timeline you can type into is a notes field with columns.
 *
 * Ordered by effective date descending — "what happened to this lease recently" — with the id as
 * the tiebreak so two changes effective the same month read in the order they were made rather than
 * in whatever order the database returns them.
 */
class LeaseHistoryRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'events';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.lease_events.title');
    }

    public function form(Schema $schema): Schema
    {
        // No form: nothing here is editable. Filament still asks for one.
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_date', 'desc')
            // No search box: LeaseEvent carries no `search_text` blob, and a box that can only
            // answer with a raw-column LIKE is the trap SearchPolicy exists to catch.
            ->searchable(false)
            ->emptyStateHeading(__('admin.lease_events.empty'))
            ->emptyStateDescription(__('admin.lease_events.empty_hint'))
            ->emptyStateIcon('heroicon-o-clock')
            ->columns([
                TextColumn::make('effective_date')
                    ->label(__('admin.lease_events.effective'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.lease_events.types.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        LeaseEvent::TYPE_TERMINATION => 'danger',
                        LeaseEvent::TYPE_ABATEMENT, LeaseEvent::TYPE_CONTRACTION => 'warning',
                        LeaseEvent::TYPE_EXPANSION, LeaseEvent::TYPE_EXTENSION => 'success',
                        LeaseEvent::TYPE_HOLDOVER => 'gray',
                        default => 'info',
                    }),
                // The money, when there is money. A rent modification whose before/after the
                // operator has to reconstruct from the schedule is half an answer.
                TextColumn::make('payload')
                    ->label(__('admin.fields.amount'))
                    ->state(function (LeaseEvent $record): ?string {
                        $change = $record->amountChange();

                        if ($change === null) {
                            return null;
                        }

                        return number_format($change['from'], 2).' → '.number_format($change['to'], 2);
                    })
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->label(__('admin.lease_events.reason'))
                    ->wrap()
                    ->limit(120),
                TextColumn::make('document_reference')
                    ->label(__('admin.lease_events.document'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('user_id')
                    ->label(__('admin.lease_events.actor'))
                    ->state(fn (LeaseEvent $record): string => $record->actorName())
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('admin.lease_events.recorded'))
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
