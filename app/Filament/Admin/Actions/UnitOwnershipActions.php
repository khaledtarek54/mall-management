<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\UnitOwnerships\Tables\UnitOwnershipsTable;
use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use App\Models\Tenant;
use App\Models\UnitOwnership;
use App\Services\TransferUnitOwnershipService;
use App\Support\Filament\EntitySelect;
use App\Support\RowActionPolicy;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

/**
 * **Everything you can DO to a unit ownership, defined once.**
 *
 * `transfer` lived inline in `UnitOwnershipsTable`,
 * so the act was reachable from the LIST and the record's
 * own page carried Delete and little else — backwards from the record-hub architecture this
 * project took from Yardi: **the list finds, the record acts**. Defined here, composed onto the
 * record page, so the two surfaces can never drift.
 *
 * Safe to move, and measured rather than assumed: every role that can perform this act can open
 * the page it moved to. Four resources failed that check — an act held by a role that
 * deliberately lacks `{module}.edit` — and kept their verbs on the row; see
 * {@see RowActionPolicy}.
 */
class UnitOwnershipActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            // THE SALE — Yardi's change-of-ownership. Closes the seller's tenure (never deletes
            // it: his assessments, CAM shares and statements all point at that row) and opens
            // the buyer's on the same terms.
            //
            // The service shipped with module 37 in August 2026 and had NO caller outside the
            // test suite until 2026-08-18 — a unit could be sold in the real world and there was
            // no way to record it, so the register silently described the wrong owner from the
            // first resale onward.
            Action::make('transfer')
                ->label(__('admin.unit_ownerships.transfer.action'))
                ->icon('heroicon-o-arrows-right-left')
                ->color('warning')
                ->modalHeading(fn (UnitOwnership $record) => __('admin.unit_ownerships.transfer.heading', ['unit' => $record->unit?->code ?? '—']))
                ->modalSubmitActionLabel(__('admin.unit_ownerships.transfer.confirm'))
                // A transferred tenure is terminal — re-selling it would open a second buyer on
                // a unit already handed on. The service refuses it too; this hides the button.
                ->visible(fn (UnitOwnership $record): bool => UnitOwnershipResource::canEdit($record)
                    && ! ($record->status?->isTerminal() ?? false))
                ->authorize(fn (UnitOwnership $record): bool => UnitOwnershipResource::canEdit($record))
                ->fillForm(fn () => ['transferred_on' => now()->toDateString()])
                ->schema([
                    // A record picker, so EntitySelect — it reaches the tenant's folded blob, so
                    // the buyer is findable by trade name in either language, tax ID or phone.
                    // ->suggest(), not a hard filter: the browse list opens on parties already
                    // registered as unit owners, but search still reaches everyone, because the
                    // service gives a far better refusal for a retailer than Filament's silent
                    // "value cannot be labelled" rejection would.
                    EntitySelect::make('buyer_id')
                        ->label(__('admin.unit_ownerships.transfer.buyer'))
                        ->entity(Tenant::class)
                        ->suggest(fn ($query) => $query->unitOwners())
                        ->required()
                        ->helperText(__('admin.unit_ownerships.transfer.buyer_help')),
                    DatePicker::make('transferred_on')
                        ->label(__('admin.unit_ownerships.transfer.date'))
                        ->native(false)
                        ->required()
                        ->live()
                        ->helperText(__('admin.unit_ownerships.transfer.date_help')),
                    // Live feedback, not explanation: this is the number the buyer's solicitor
                    // holds back against, read from the books at the date chosen above.
                    Placeholder::make('position')
                        ->hiddenLabel()
                        ->content(fn (Get $get, UnitOwnership $record) => UnitOwnershipsTable::certificateSummary($record, $get('transferred_on'))),
                    Toggle::make('allow_outstanding')
                        ->label(__('admin.unit_ownerships.transfer.allow_outstanding'))
                        ->helperText(__('admin.unit_ownerships.transfer.allow_outstanding_help')),
                    Textarea::make('reason')
                        ->label(__('admin.unit_ownerships.transfer.reason'))
                        ->rows(2)
                        ->maxLength(500),
                ])
                ->action(function (UnitOwnership $record, array $data) {
                    // action() is the real gate — a view-only role holds unit_ownerships.view
                    // and must never move a unit between owners.
                    abort_unless(UnitOwnershipResource::canEdit($record), 403);

                    $result = app(TransferUnitOwnershipService::class)->transfer(
                        $record,
                        Tenant::findOrFail($data['buyer_id']),
                        CarbonImmutable::parse($data['transferred_on']),
                        (bool) ($data['allow_outstanding'] ?? false),
                        $data['reason'] ?? null,
                    );

                    Notification::make()
                        ->title(__('admin.unit_ownerships.transfer.done'))
                        ->body(__('admin.unit_ownerships.transfer.done_body', [
                            'unit' => $record->unit?->code ?? '—',
                            'buyer' => $result['buyer']->owner?->name ?? '',
                            'outstanding' => number_format((float) $result['certificate']['outstanding'], 2),
                        ]))
                        ->success()
                        ->send();
                }),
        ];
    }
}
