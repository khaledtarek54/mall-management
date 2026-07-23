<?php

namespace App\Filament\Admin\Resources\PostDatedCheques\Pages;

use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use App\Services\PostDatedChequeService;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

class ListPostDatedCheques extends ListRecords
{
    protected static string $resource = PostDatedChequeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->visible(fn () => PostDatedChequeResource::canCreate()),

            // Lodge a whole SERIES at once — the Egyptian norm (a tenant hands over a year of
            // monthly cheques up front). Entering them one at a time is slow and error-prone.
            Action::make('lodge_series')
                ->label(__('admin.post_dated_cheques.actions.lodge_series'))
                ->icon('heroicon-o-square-2-stack')
                ->color('primary')
                ->visible(fn () => PostDatedChequeResource::canCreate())
                ->authorize(fn () => PostDatedChequeResource::canCreate())
                ->schema([
                    Select::make('asset_id')
                        ->label(__('admin.post_dated_cheques.fields.property'))
                        ->options(fn () => TenantScope::selectableAssetOptions())
                        ->default(fn () => TenantScope::currentAssetId())
                        ->disabled(fn () => TenantScope::currentAssetId() !== null)
                        ->dehydrated()
                        ->required()
                        ->native(false),
                    Select::make('tenant_id')
                        ->label(__('admin.post_dated_cheques.fields.tenant'))
                        ->options(fn () => TenantScope::selectableTenantOptions())
                        ->searchable()
                        ->required()
                        ->native(false),
                    TextInput::make('bank_name')
                        ->label(__('admin.post_dated_cheques.fields.bank_name')),
                    TextInput::make('first_cheque_number')
                        ->label(__('admin.post_dated_cheques.fields.first_cheque_number'))
                        ->helperText(__('admin.post_dated_cheques.fields.first_cheque_number_hint'))
                        ->required(),
                    TextInput::make('amount')
                        ->label(__('admin.post_dated_cheques.fields.amount_each'))
                        ->prefix('EGP')
                        ->numeric()->minValue(0.01)->required()
                        ->live(onBlur: true),
                    TextInput::make('count')
                        ->label(__('admin.post_dated_cheques.fields.count'))
                        ->numeric()->minValue(1)->maxValue(60)->default(12)->required()
                        ->live(onBlur: true),
                    Select::make('interval_months')
                        ->label(__('admin.post_dated_cheques.fields.interval'))
                        ->options([
                            1 => __('admin.post_dated_cheques.intervals.monthly'),
                            3 => __('admin.post_dated_cheques.intervals.quarterly'),
                            6 => __('admin.post_dated_cheques.intervals.biannual'),
                        ])
                        ->default(1)->required()->native(false)->live(),
                    DatePicker::make('first_cheque_date')
                        ->label(__('admin.post_dated_cheques.fields.first_maturity'))
                        ->default(now()->addMonthNoOverflow()->startOfMonth())
                        ->required()->native(false)->live(),
                    DatePicker::make('received_date')
                        ->label(__('admin.post_dated_cheques.fields.received_date'))
                        ->default(now())
                        ->native(false),
                    // Live preview: an operator committing 12 cheques should see exactly what will
                    // be created — total value, and the first→last maturity span — before they do.
                    Placeholder::make('preview')
                        ->label(__('admin.post_dated_cheques.series_preview'))
                        ->content(function (Get $get): string {
                            $count = (int) ($get('count') ?: 0);
                            $amount = (float) ($get('amount') ?: 0);
                            $interval = max(1, (int) ($get('interval_months') ?: 1));

                            if ($count < 1 || $amount <= 0 || blank($get('first_cheque_date'))) {
                                return __('admin.post_dated_cheques.series_preview_empty');
                            }

                            $first = \Illuminate\Support\Carbon::parse($get('first_cheque_date'));
                            $last = $first->copy()->addMonthsNoOverflow(($count - 1) * $interval);

                            return __('admin.post_dated_cheques.series_preview_text', [
                                'count' => $count,
                                'each' => number_format($amount, 2),
                                'total' => number_format($amount * $count, 2),
                                'from' => $first->format('M Y'),
                                'to' => $last->format('M Y'),
                            ]);
                        })
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    PostDatedChequeResource::assertAssetInScope($data['asset_id']);

                    try {
                        $created = app(PostDatedChequeService::class)->lodgeSeries($data);
                    } catch (\DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('admin.post_dated_cheques.notices.series_lodged'))
                        ->body(__('admin.post_dated_cheques.notices.series_lodged_body', [
                            'count' => $created->count(),
                            'total' => number_format((float) $created->sum('amount'), 2),
                        ]))
                        ->success()
                        ->send();
                }),
        ];
    }
}
