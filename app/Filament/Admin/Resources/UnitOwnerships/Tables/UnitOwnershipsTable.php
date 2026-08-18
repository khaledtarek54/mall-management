<?php

namespace App\Filament\Admin\Resources\UnitOwnerships\Tables;

use App\Enums\UnitManagementMode;
use App\Enums\UnitOwnershipStatus;
use App\Enums\UnitTenureType;
use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use App\Models\Tenant;
use App\Models\UnitOwnership;
use App\Services\TransferUnitOwnershipService;
use App\Support\Filament\EntitySelect;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * The ownership register — who owns which unit, and on what footing.
 *
 * Sorted by unit rather than by date: an operator arriving here is answering "who owns A-102",
 * not "what was sold most recently".
 */
class UnitOwnershipsTable
{
    public static function configure(Table $table): Table
    {
        // No TableDefaults call: `TableDefaults::register()` is a global
        // `Table::configureUsing()` applied to every table in the panel, so search persistence,
        // striping and pagination arrive here already.
        return $table
            ->defaultSort('unit.code')
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.fields.reference'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('unit.code')
                    ->label(__('admin.fields.unit_id'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('owner.name')
                    ->label(__('admin.unit_ownerships.fields.owner'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('tenure_type')
                    ->label(__('admin.fields.tenure_type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (UnitTenureType $state): string => $state->label()),

                TextColumn::make('management_mode')
                    ->label(__('admin.fields.management_mode'))
                    ->badge()
                    ->formatStateUsing(fn (UnitManagementMode $state): string => $state->label())
                    ->color(fn (UnitManagementMode $state): string => match ($state) {
                        UnitManagementMode::OperatorManaged => 'success',
                        UnitManagementMode::Vacant => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (UnitOwnershipStatus $state): string => $state->label())
                    ->color(fn (UnitOwnershipStatus $state): string => match ($state) {
                        UnitOwnershipStatus::HandedOver => 'success',
                        UnitOwnershipStatus::Transferred => 'gray',
                        default => 'info',
                    }),

                TextColumn::make('ownership_share_pct')
                    ->label(__('admin.fields.ownership_share_pct'))
                    ->suffix('%')
                    ->alignRight()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('started_at')
                    ->label(__('admin.fields.started_at'))
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('ended_at')
                    ->label(__('admin.fields.ended_at'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('management_mode')
                    ->label(__('admin.fields.management_mode'))
                    ->options(UnitManagementMode::options()),

                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(UnitOwnershipStatus::options()),

                SelectFilter::make('tenure_type')
                    ->label(__('admin.fields.tenure_type'))
                    ->options(UnitTenureType::options()),

                // The register accumulates former owners by design — a resale ends a tenure rather
                // than deleting it — so "who owns this today" needs to be one click, not a mental
                // filter over every row that ever existed.
                Filter::make('current')
                    ->label(__('admin.unit_ownerships.filters.current'))
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->covering()),

                TrashedFilter::make(),
            ])
            ->recordActions([
                // Read the sale without opening its edit form — the register is consulted far more
                // often than it is changed ("who owns A-102, and on what footing"), and a view-only
                // role must not be handed a write surface to answer that. The schema is the
                // resource's own form rendered disabled, so it cannot drift from the real fields.
                ViewAction::make()
                    ->visible(fn ($record): bool => UnitOwnershipResource::canView($record)),
                EditAction::make(),

                // The RESALE (estoppel) CERTIFICATE — read-only, and the thing a solicitor actually
                // asks for. Separated from the transfer below because it is consulted far more often
                // than a sale happens: the buyer's side wants the figure long before anyone commits.
                // Gated on view, not edit — stating what is owed changes nothing.
                Action::make('resaleCertificate')
                    ->label(__('admin.unit_ownerships.transfer.certificate'))
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->modalHeading(fn (UnitOwnership $record) => __('admin.unit_ownerships.transfer.certificate_heading', ['unit' => $record->unit?->code ?? '—']))
                    ->modalSubmitAction(false)
                    ->visible(fn (UnitOwnership $record): bool => UnitOwnershipResource::canView($record))
                    ->authorize(fn (UnitOwnership $record): bool => UnitOwnershipResource::canView($record))
                    ->fillForm(fn () => ['as_of' => now()->toDateString()])
                    ->schema([
                        DatePicker::make('as_of')
                            ->label(__('admin.unit_ownerships.transfer.as_of'))
                            ->native(false)
                            ->required()
                            // Live so the figures below re-read the ledger when the date moves — a
                            // certificate is always "as at" a date, and the date is the question.
                            ->live(),
                        Placeholder::make('certificate')
                            ->hiddenLabel()
                            ->content(fn (Get $get, UnitOwnership $record) => static::certificateSummary($record, $get('as_of'))),
                    ]),

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
                            ->content(fn (Get $get, UnitOwnership $record) => static::certificateSummary($record, $get('transferred_on'))),
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
            ]);
    }

    /**
     * The certificate, rendered for a modal.
     *
     * Read through the service rather than re-derived here: `outstanding` is the figure the sale
     * turns on and it must be the invoices' own `balance`, which `Invoice::recomputeTotals()` owns
     * across all four settlement channels. A second arithmetic here would be a second truth.
     */
    protected static function certificateSummary(UnitOwnership $record, ?string $asOf): HtmlString
    {
        $cert = app(TransferUnitOwnershipService::class)->certificate(
            $record,
            $asOf ? CarbonImmutable::parse($asOf) : CarbonImmutable::now(),
        );

        $money = fn (float $v): string => 'EGP '.number_format($v, 2);

        $rows = [
            __('admin.unit_ownerships.transfer.owner') => $cert['owner'] ?? '—',
            __('admin.unit_ownerships.transfer.owned_from') => $cert['owned_from'] ?? '—',
            __('admin.unit_ownerships.transfer.monthly_assessment') => $money((float) $cert['monthly_assessment']),
            __('admin.unit_ownerships.transfer.billed') => $money((float) $cert['assessments_billed']),
            __('admin.unit_ownerships.transfer.paid') => $money((float) $cert['assessments_paid']),
        ];

        $html = '<dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">';
        foreach ($rows as $label => $value) {
            $html .= '<dt class="text-gray-500">'.e($label).'</dt><dd class="font-medium">'.e($value).'</dd>';
        }

        $outstanding = (float) $cert['outstanding'];
        $tone = $outstanding > 0.005 ? 'text-danger-600' : 'text-success-600';
        $html .= '<dt class="text-gray-500">'.e(__('admin.unit_ownerships.transfer.outstanding')).'</dt>'
            .'<dd class="font-semibold '.$tone.'">'.e($money($outstanding)).'</dd>';
        $html .= '</dl>';

        return new HtmlString($html);
    }
}
