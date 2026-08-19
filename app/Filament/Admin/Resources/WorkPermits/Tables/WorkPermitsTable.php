<?php

namespace App\Filament\Admin\Resources\WorkPermits\Tables;

use App\Models\WorkPermit;
use App\Services\WorkPermitService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
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
                    ->schema(fn (WorkPermit $record): array => self::abstractOf($record)),

                EditAction::make()
                    ->visible(fn (WorkPermit $r): bool => self::canWrite() && $r->status === WorkPermit::STATUS_DRAFT)
                    ->authorize(fn (): bool => self::canWrite()),

                Action::make('issue')
                    ->label(__('admin.work_permits.actions.issue'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.work_permits.actions.issue_hint'))
                    // The authoriser reads what they are authorising. A confirmation dialog that
                    // says only "are you sure?" asks a named person to accept a risk they cannot
                    // see from the button — and the conditions are the permit.
                    ->schema(fn (WorkPermit $record): array => self::abstractOf($record))
                    ->visible(fn (WorkPermit $r): bool => self::canIssue() && $r->status === WorkPermit::STATUS_DRAFT)
                    ->authorize(fn (): bool => self::canIssue())
                    ->action(function (WorkPermit $record): void {
                        abort_unless(self::canIssue(), 403);
                        self::run(fn () => app(WorkPermitService::class)->issue($record),
                            __('admin.work_permits.actions.issued', ['ref' => $record->reference]));
                    }),

                Action::make('close')
                    ->label(__('admin.work_permits.actions.close'))
                    ->icon('heroicon-o-lock-closed')
                    ->color('gray')
                    ->visible(fn (WorkPermit $r): bool => self::canIssue() && $r->status === WorkPermit::STATUS_ISSUED)
                    ->authorize(fn (): bool => self::canIssue())
                    ->schema([
                        Textarea::make('closure_notes')
                            ->label(__('admin.fields.permit_closure_notes'))
                            ->required()
                            ->rows(3)
                            ->helperText(__('admin.work_permits.help.closure_notes')),
                    ])
                    ->action(function (WorkPermit $record, array $data): void {
                        abort_unless(self::canIssue(), 403);
                        self::run(fn () => app(WorkPermitService::class)->close($record, $data['closure_notes']),
                            __('admin.work_permits.actions.closed', ['ref' => $record->reference]));
                    }),

                Action::make('cancel')
                    ->label(__('admin.work_permits.actions.cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (WorkPermit $r): bool => self::canIssue() && ! in_array($r->status, WorkPermit::TERMINAL, true))
                    ->authorize(fn (): bool => self::canIssue())
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('admin.fields.permit_cancel_reason'))
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (WorkPermit $record, array $data): void {
                        abort_unless(self::canIssue(), 403);
                        self::run(fn () => app(WorkPermitService::class)->cancel($record, $data['reason']),
                            __('admin.work_permits.actions.cancelled', ['ref' => $record->reference]));
                    }),
            ])
            ->defaultSort('valid_from', 'desc');
    }

    /**
     * What this permit authorises, in one panel.
     *
     * Shared by the View action and by the issue confirmation on purpose: the facts a person needs
     * to AUTHORISE hazardous work are exactly the facts anyone else needs to check it later, and
     * two divergent summaries of one permit is how the conditions come to be missing from the one
     * that matters.
     *
     * @return array<int, TextEntry>
     */
    private static function abstractOf(WorkPermit $record): array
    {
        return [
            TextEntry::make('type')
                ->label(__('admin.fields.permit_type'))
                ->badge()
                ->state(fn (): string => __('admin.enums.work_permit_type')[$record->type] ?? $record->type),

            TextEntry::make('description')
                ->label(__('admin.fields.permit_description'))
                ->state(fn (): string => $record->description ?? '—'),

            TextEntry::make('window')
                ->label(__('admin.fields.permit_window'))
                ->state(fn (): string => trim(
                    ($record->valid_from?->format('d/m/Y H:i') ?? '—')
                    .' → '
                    .($record->valid_to?->format('d/m/Y H:i') ?? '—')
                )),

            TextEntry::make('where')
                ->label(__('admin.fields.location'))
                ->state(fn (): string => $record->location
                    ?? $record->unit?->code
                    ?? $record->area?->name
                    ?? '—'),

            TextEntry::make('contractor')
                ->label(__('admin.fields.permit_contractor'))
                ->state(fn (): string => trim(
                    ($record->vendor?->name ?? $record->contractor_name ?? '—')
                    .($record->contractor_phone ? ' · '.$record->contractor_phone : '')
                )),

            // The conditions ARE the permit — never abbreviated, never behind a hover.
            TextEntry::make('conditions')
                ->label(__('admin.fields.permit_conditions'))
                ->state(fn (): string => filled($record->conditions)
                    ? $record->conditions
                    : __('admin.work_permits.no_conditions'))
                ->color(fn (): ?string => blank($record->conditions) ? 'danger' : null)
                ->columnSpanFull(),

            TextEntry::make('issued_by')
                ->label(__('admin.fields.permit_issued_by'))
                ->state(fn (): string => trim(
                    ($record->issuedBy?->name ?? '—')
                    .($record->issued_at ? ' · '.$record->issued_at->format('d/m/Y H:i') : '')
                ))
                ->visible(fn (): bool => $record->issued_at !== null),

            TextEntry::make('closure')
                ->label(__('admin.fields.permit_closure_notes'))
                ->state(fn (): string => $record->closure_notes ?? '—')
                ->columnSpanFull()
                ->visible(fn (): bool => $record->closed_at !== null),
        ];
    }

    /** A refusal is a message that says what to do next, never a 500. */
    private static function run(callable $act, string $success): void
    {
        try {
            $act();
        } catch (\DomainException|\InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }

    /** Named once each so `visible()` and `authorize()` cannot drift — the double-gate rule. */
    private static function canWrite(): bool
    {
        return auth()->user()?->can('work_permits.edit') ?? false;
    }

    /**
     * Issuing, closing and cancelling are one right, and it is NOT the same as editing a draft.
     * Authorising hazardous work is the act a named person is accountable for.
     */
    private static function canIssue(): bool
    {
        return auth()->user()?->can('work_permits.issue') ?? false;
    }
}
