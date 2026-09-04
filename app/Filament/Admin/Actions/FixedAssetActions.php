<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Actions\ReverseDocumentAction;
use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Models\FixedAsset;
use App\Services\DisposeFixedAssetService;
use App\Support\RowActionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

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
}
