<?php

namespace App\Filament\Admin\Actions;

use App\Models\WorkPermit;
use App\Services\WorkPermitService;
use App\Support\RowActionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;

/**
 * **Everything you can DO to a work permit, defined once.**
 *
 * `issue`, `close` and `cancel` lived inline in `WorkPermitsTable`,
 * so they were reachable from the LIST and the record's
 * own page carried Delete and little else — backwards from the record-hub architecture this
 * project took from Yardi: **the list finds, the record acts**. Defined here, composed onto the
 * record page, so the two surfaces can never drift.
 *
 * Safe to move, and measured rather than assumed: every role that can perform these acts can open
 * the page it moved to. Four resources failed that check — an act held by a role that
 * deliberately lacks `{module}.edit` — and kept their verbs on the row; see
 * {@see RowActionPolicy}.
 */
class WorkPermitActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
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
        ];
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
    public static function abstractOf(WorkPermit $record): array
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

    /**
     * Issuing, closing and cancelling are one right, and it is NOT the same as editing a draft.
     * Authorising hazardous work is the act a named person is accountable for.
     */
    public static function canIssue(): bool
    {
        return auth()->user()?->can('work_permits.issue') ?? false;
    }

    /** A refusal is a message that says what to do next, never a 500. */
    public static function run(callable $act, string $success): void
    {
        try {
            $act();
        } catch (\DomainException|\InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }
}
