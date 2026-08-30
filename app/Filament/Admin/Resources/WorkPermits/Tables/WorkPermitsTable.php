<?php

namespace App\Filament\Admin\Resources\WorkPermits\Tables;

use App\Filament\Admin\Actions\WorkPermitActions;
use App\Models\WorkPermit;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The register. Two filters carry the whole control: what is authorised RIGHT NOW, and what expired
 * without being closed out.
 */
class WorkPermitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // The contractor column falls back from vendor to a typed name, and the status column
            // asks each row whether it has lapsed — one query per row without this.
            ->modifyQueryUsing(fn ($query) => $query->with(['vendor', 'unit', 'area']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.fields.reference'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->sortable(),

                TextColumn::make('type')
                    ->label(__('admin.fields.permit_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.enums.work_permit_type')[$state] ?? $state),

                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state, WorkPermit $record) => $record->hasLapsed()
                        // A lapsed permit reads as its own state even though it is stored as
                        // `issued` — expiry is a fact about the clock, never a stored status, but
                        // the operator must not have to work that out from two columns.
                        ? __('admin.work_permits.lapsed')
                        : (__('admin.enums.work_permit_status')[$state] ?? $state))
                    ->color(fn (string $state, WorkPermit $record): string => match (true) {
                        $record->hasLapsed() => 'danger',
                        $record->isLive() => 'success',
                        $state === WorkPermit::STATUS_CLOSED => 'gray',
                        $state === WorkPermit::STATUS_CANCELLED => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('contractor')
                    ->label(__('admin.fields.permit_contractor'))
                    ->state(fn (WorkPermit $r): string => $r->vendor?->name ?? $r->contractor_name ?? '—'),

                TextColumn::make('valid_from')
                    ->label(__('admin.fields.permit_valid_from'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('valid_to')
                    ->label(__('admin.fields.permit_valid_to'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('closed_at')
                    ->label(__('admin.fields.permit_closed_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.fields.permit_type'))
                    ->options(__('admin.enums.work_permit_type')),

                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(__('admin.enums.work_permit_status')),

                // The two that matter, off the model's own scopes so the filter, the scan and the
                // badge can never disagree about what "live" or "overdue" means.
                Filter::make('live')
                    ->label(__('admin.work_permits.filters.live'))
                    ->query(fn ($query) => $query->live()),

                Filter::make('overdue_closure')
                    ->label(__('admin.work_permits.filters.overdue'))
                    ->query(fn ($query) => $query->overdueClosure()),
            ])
            ->recordActions([
                // **An issued permit has to be readable.** Edit disappears the moment it is issued
                // — correctly, a live authorisation is not a draft — and without this the register
                // could show that a permit exists and never show what it authorises. The guard at
                // the door and the manager acting on the overdue alert both need the conditions,
                // and neither is going to read them off a list. Native infolist in the action,
                // per convention; no View page.
                ViewAction::make()
                    ->modalSubmitAction(false)
                    ->schema(fn (WorkPermit $record): array => WorkPermitActions::abstractOf($record)),

                // ── The list FINDS; the record ACTS ─────────────────────────────────────
                // Defined once in App\Filament\Admin\Actions\WorkPermitActions and composed onto this
                // record's own page, so opening the record is enough to act on it.
                EditAction::make()
                    ->visible(fn (WorkPermit $r): bool => self::canWrite() && $r->status === WorkPermit::STATUS_DRAFT)
                    ->authorize(fn (): bool => self::canWrite()),

            ])
            ->defaultSort('valid_from', 'desc')
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading(__('admin.empty.work_permits.heading'))
            ->emptyStateDescription(__('admin.empty.work_permits.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.work_permits.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }

    /** Named once each so `visible()` and `authorize()` cannot drift — the double-gate rule. */
    private static function canWrite(): bool
    {
        return auth()->user()?->can('work_permits.edit') ?? false;

    }
}
