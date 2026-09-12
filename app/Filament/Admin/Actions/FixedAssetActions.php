<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Actions\ReversalReasonField;
use App\Filament\Actions\ReverseDocumentAction;
use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Models\Asset;
use App\Models\FixedAsset;
use App\Services\DisposeFixedAssetService;
use App\Services\TransferFixedAssetService;
use App\Support\AssignedAssets;
use App\Support\Filament\EntitySelect;
use App\Support\RowActionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * **Everything you can DO to a fixed asset, defined once.**
 *
 * `dispose` lived inline in `FixedAssetsTable`,
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
class FixedAssetActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            Action::make('dispose')
                ->label(__('admin.fixed_assets.actions.dispose'))
                ->icon('heroicon-o-archive-box-x-mark')
                ->color('danger')
                ->requiresConfirmation()
                // Only active assets, and only if the user may edit.
                ->visible(fn (FixedAsset $record) => $record->status === 'active' && FixedAssetResource::canEdit($record))
                ->authorize(fn (FixedAsset $record) => FixedAssetResource::canEdit($record))
                ->schema([
                    DatePicker::make('disposed_on')
                        ->label(__('admin.fixed_assets.fields.disposed_on'))
                        ->default(now())
                        ->required()
                        ->native(false),
                    TextInput::make('proceeds')
                        ->label(__('admin.fixed_assets.fields.proceeds'))
                        ->helperText(__('admin.fixed_assets.proceeds_hint'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->prefix('EGP'),
                    // **Always shown, and that is the fix.** It was conditioned on
                    // `(float) $get('proceeds') > 0` beside a `proceeds` field carrying no
                    // `->live()`, so nothing re-rendered the schema once the amount was typed and
                    // the picker could not appear at all — and a hidden Filament field is not
                    // dehydrated (`HasState::isHiddenAndNotDehydratedWhenHidden()` forgets the
                    // state path), so the answer never reached `$data`,
                    // `DisposeFixedAssetService` fell to its `?? 'cash'`, and
                    // `FixedAssetDisposalJournalizer` resolved the proceeds line through
                    // `MoneyAccount::for(null, 'cash', …)`. An asset sold and BANKED debited cash
                    // on hand. Measured 2026-09-04 (SW-190).
                    //
                    // Making the amount live would have re-rendered it, and that is the wrong
                    // repair for a money RAIL: the answer would then ride on a blur that races the
                    // submit, and a rail that is sometimes not asked is worse than one that is
                    // always asked. Same reasoning as `BankAccountField`, which is deliberately not
                    // hidden on a cash rail. The journalizer raises no cash line at all when
                    // proceeds are 0, so on a scrapping this costs one row on the modal and
                    // nothing else.
                    Select::make('proceeds_account')
                        ->label(__('admin.fixed_assets.fields.proceeds_account'))
                        ->helperText(__('admin.fixed_assets.proceeds_account_hint'))
                        ->options(fn () => __('admin.enums.cash_or_bank'))
                        ->default('cash')
                        ->required()
                        ->native(false),
                    Textarea::make('notes')
                        ->label(__('admin.fixed_assets.fields.notes'))
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data, FixedAsset $record): void {
                    // Server-side re-check (authorize can't see form tampering of a terminal record).
                    abort_unless(FixedAssetResource::canEdit($record) && $record->status === 'active', 403);
                    app(DisposeFixedAssetService::class)->dispose($record, $data);
                    Notification::make()->title(__('admin.fixed_assets.disposed'))->success()->send();
                }),

            // **Moved to another property** (meeting 2026-09-02, point 18) — a dated act with a
            // reason, SAP's ABUMN: cost and accumulated depreciation leave the old property's books
            // and join the new one's on the transfer date, history stays where it was, and
            // depreciation follows the asset from that month. The free edit of the property on
            // the form re-homed the WHOLE history (every posted month voided and re-posted into
            // the new mall) and is refused on a depreciating asset since the same day.
            //
            // The destination picker is the one deliberate exception to "no screen offers a
            // property other than the selected one": a transfer's destination is another mall by
            // definition. It offers the properties the ACTOR HOLDS (`AssignedAssets`, the same
            // reach the user form's grant picker uses), minus the one the asset is in, and the
            // service refuses anything outside that set — the picker is not the guard.
            Action::make('transfer')
                ->label(__('admin.fixed_assets.actions.transfer'))
                ->icon('heroicon-o-arrow-right-circle')
                ->visible(fn (FixedAsset $record) => $record->status === 'active' && FixedAssetResource::canEdit($record) && self::transferDestinations($record)->isNotEmpty())
                ->authorize(fn (FixedAsset $record) => FixedAssetResource::canEdit($record))
                ->schema([
                    EntitySelect::make('to_asset_id')
                        ->label(__('admin.fields.to_asset_id'))
                        ->entity(Asset::class)
                        // Stated at the call site, as `acrossProperties()` requires: this picker is
                        // ABOUT another mall, the option list is narrowed to what the actor holds,
                        // and the SERVICE re-checks the submitted value against the same set.
                        ->acrossProperties()
                        ->modifyOptionsQuery(fn ($query, FixedAsset $record) => self::narrowToTransferDestinations($query, $record))
                        ->required()
                        ->helperText(__('admin.fixed_assets.transfer_help.to_asset_id')),
                    DatePicker::make('transferred_on')
                        ->label(__('admin.fields.transferred_on'))
                        ->default(now())
                        ->maxDate(now())
                        ->required()
                        ->native(false)
                        ->helperText(__('admin.fixed_assets.transfer_help.transferred_on')),
                    ReversalReasonField::make('reason'),
                ])
                ->action(function (array $data, FixedAsset $record) {
                    abort_unless(FixedAssetResource::canEdit($record) && $record->status === 'active', 403);
                    $transfer = app(TransferFixedAssetService::class)->transfer($record, $data);
                    Notification::make()->title(__('admin.fixed_assets.transferred'))->success()->send();

                    // The asset is in another mall now, and this page is scoped to the one it
                    // left — a re-render here would 404. Follow it: open the same record under the
                    // receiving property, which the actor holds (the service refused otherwise).
                    return redirect(FixedAssetResource::getUrl('edit', ['record' => $record], tenant: $transfer->toAsset));
                }),

            // **Recorded in error**, which is a different act from DISPOSAL above. Disposing books
            // proceeds and a gain or loss because the company sold something; reversing says the
            // acquisition should never have been on the books at all, and the sweep voids the
            // asset's whole GL footprint. Offered only while the asset is still ACTIVE — once
            // disposed, the disposal is the document that speaks for it and reversing underneath it
            // would strand the disposal entry.
            //
            // Moved here from `FixedAssetsTable` (which carried a comment saying *"the list FINDS;
            // the record ACTS"* while keeping this in the row). It was invisible to
            // {@see RowActionPolicy} because a factory's `->action()` lives in its own file, so the
            // table reported ZERO write verbs while offering the reversal of a posted GL document.
            // Safe to move on the same measured test as `dispose`: it gates on
            // `FixedAssetResource::canEdit()`, which is exactly what reaching this page requires.
            ReverseDocumentAction::make(
                can: fn (FixedAsset $record) => FixedAssetResource::canEdit($record),
                label: 'admin.actions.reverse_acquisition',
                confirm: 'admin.actions.reverse_acquisition_confirm',
                done: 'admin.notifications.acquisition_reversed',
                when: fn (FixedAsset $record) => $record->status === 'active',
            ),
        ];
    }

    /**
     * The real properties this operator may move an asset TO: what they hold (null = every one),
     * never the pseudo-asset, never the one it is already in.
     *
     * @return \Illuminate\Support\Collection<int, Asset>
     */
    public static function transferDestinations(FixedAsset $record): Collection
    {
        return self::narrowToTransferDestinations(Asset::query(), $record)->orderBy('name')->get(['id', 'name']);
    }

    /** The one narrowing the picker and the visibility share, so the button cannot offer a modal with nothing to pick. */
    private static function narrowToTransferDestinations(Builder $query, FixedAsset $record): Builder
    {
        $held = AssignedAssets::idsForCurrentUser();

        return $query
            ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
            ->whereKeyNot($record->asset_id)
            ->when($held !== null, fn (Builder $q) => $q->whereIn('id', $held));
    }
}
