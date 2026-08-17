<?php

namespace App\Filament\Admin\Resources\Payments\Tables;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Exports\PaymentExporter;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\ReceiptPdfService;
use App\Support\Filament\EntitySelectFilter;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tenant'))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.payment.reference'))
                    ->searchable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('tenant.name')
                    ->label(__('admin.tables.payment.tenant'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('amount')
                    ->label(__('admin.tables.payment.amount'))
                    ->money('EGP')
                    ->sortable()
                    ->color('success')
                    ->weight('bold')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('method')
                    ->label(__('admin.tables.payment.method'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.method.{$state}"))
                    ->color('info'),
                TextColumn::make('payment_date')
                    ->label(__('admin.tables.payment.date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.payment.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'captured', 'reconciled', 'settled' => 'success',
                        'initiated', 'authorized' => 'warning',
                        'failed', 'bounced', 'refunded' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('method')
                    ->label(__('admin.tables.payment.method'))
                    ->options(fn () => collect(__('admin.enums.method'))->only(['card', 'bank_transfer', 'instapay', 'cash', 'cheque'])->all()),
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => collect(__('admin.statuses.payment'))->only(['captured', 'reconciled', 'failed', 'refunded'])->all()),
                EntitySelectFilter::make('tenant_id')
                    ->label(__('admin.filters.tenant'))
                    ->relationship('tenant')
                    ->entity(Tenant::class),
                Filter::make('payment_date_range')
                    ->label(__('admin.tables.payment.date'))
                    ->schema([
                        DatePicker::make('payment_from')
                            ->label(__('admin.filters.payment_from'))
                            ->native(false),
                        DatePicker::make('payment_until')
                            ->label(__('admin.filters.payment_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['payment_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('payment_date', '>=', $date))
                        ->when($data['payment_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('payment_date', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['payment_from'] ?? null) {
                            $indicators[] = __('admin.filters.payment_from').': '.Carbon::parse($data['payment_from'])->format('d/m/Y');
                        }
                        if ($data['payment_until'] ?? null) {
                            $indicators[] = __('admin.filters.payment_until').': '.Carbon::parse($data['payment_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
                Filter::make('amount_range')
                    ->label(__('admin.tables.payment.amount'))
                    ->schema([
                        TextInput::make('amount_min')
                            ->label(__('admin.filters.amount_min'))
                            ->numeric()
                            ->prefix('EGP'),
                        TextInput::make('amount_max')
                            ->label(__('admin.filters.amount_max'))
                            ->numeric()
                            ->prefix('EGP'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['amount_min'] ?? null, fn (Builder $q, $value) => $q->where('amount', '>=', $value))
                        ->when($data['amount_max'] ?? null, fn (Builder $q, $value) => $q->where('amount', '<=', $value)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['amount_min'] ?? null) {
                            $indicators[] = __('admin.filters.amount_min').': '.$data['amount_min'];
                        }
                        if ($data['amount_max'] ?? null) {
                            $indicators[] = __('admin.filters.amount_max').': '.$data['amount_max'];
                        }

                        return $indicators;
                    }),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->headerActions([
                ExportAction::make()
                    ->exporter(PaymentExporter::class)
                    ->label(__('admin.actions.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray'),
            ])
            // Method is the reconciliation axis: cash, bank transfer and cheques are counted
            // against different places, and the summariser totals each group.
            ->groups([
                Group::make('method')->label(__('admin.tables.payment.method'))->collapsible(),
                Group::make('tenant.name')->label(__('admin.filters.tenant'))->collapsible(),
            ])
            ->recordActions([
                LedgerEntryAction::make(),
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => PaymentResource::canView($record))
                    ->authorize(fn ($record) => PaymentResource::canView($record)),
                Action::make('downloadReceipt')
                    ->label(__('admin.actions.download_receipt'))
                    ->icon('heroicon-o-receipt-percent')
                    ->color('gray')
                    // Only a RECEIVED payment (captured/reconciled/settled) has real cash to receipt.
                    // Gate in BOTH visible() (UI) and action() (the real gate — mountAction ignores visible()).
                    ->visible(fn (Payment $record): bool => $record->isReceived())
                    ->action(function (Payment $record) {
                        abort_unless($record->isReceived(), 403);
                        $svc = app(ReceiptPdfService::class);
                        $pdf = $svc->build($record);

                        return response()->streamDownload(
                            fn () => print ($pdf),
                            $svc->filename($record),
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
                EditAction::make()
                    ->visible(fn ($record) => PaymentResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(PaymentExporter::class)
                        ->label(__('admin.actions.export')),
                    DeleteBulkAction::make()
                        ->visible(fn () => PaymentResource::canDeleteAny()),
                    ForceDeleteBulkAction::make()
                        ->visible(fn () => PaymentResource::canForceDeleteAny()),
                    RestoreBulkAction::make()
                        ->visible(fn () => PaymentResource::canRestoreAny()),
                ]),
            ])
            ->defaultSort('payment_date', 'desc')
            ->emptyStateIcon('heroicon-o-credit-card')
            ->emptyStateHeading(__('admin.empty.payments.heading'))
            ->emptyStateDescription(__('admin.empty.payments.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.payments.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
