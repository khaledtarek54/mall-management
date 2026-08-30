<?php

namespace App\Filament\Admin\Actions;

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
use Filament\Schemas\Components\Utilities\Get;

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
                    Select::make('proceeds_account')
                        ->label(__('admin.fixed_assets.fields.proceeds_account'))
                        ->options(fn () => __('admin.enums.cash_or_bank'))
                        ->default('cash')
                        ->native(false)
                        // Only matters when money actually came in.
                        ->visible(fn (Get $get) => (float) $get('proceeds') > 0),
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
        ];
    }
}
