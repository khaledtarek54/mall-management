<?php

namespace App\Filament\Admin\Resources\Payments\Tables;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Exports\PaymentExporter;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Tenant;
use App\Services\ReceiptPdfService;
use App\Support\Exports;
use App\Support\Filament\BankAccountColumn;
use App\Support\Filament\BankAccountFilter;
use App\Support\Filament\EntitySelectFilter;
use App\Support\Filament\PdfDownloadAction;
use App\Support\Filament\TableGroup;
use App\Support\StatusOptions;
use Carbon\Carbon;
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
use Filament\Support\Icons\Heroicon;
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
                    // The catalogue, not a lang key: an operator-added rail has no key and would render
                    // raw on the very screen whose filter lists it.
                    ->formatStateUsing(fn (?string $state) => PaymentMethod::labelFor($state))
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
                        'failed', 'bounced', 'refunded', 'voided' => 'danger',
                        default => 'gray',
                    }),
                BankAccountColumn::make(),
            ])
            ->filters([
                SelectFilter::make('method')
                    ->label(__('admin.tables.payment.method'))
                    // Was an `->only()` list of five, hand-kept beside the seven the column accepts —
                // so `wallet` and `other` could be recorded and never filtered for. The catalogue
                // answers both questions with one list.
                    ->options(fn () => PaymentMethod::filterOptionsFor('payments.method')),
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    // Derived, exactly like the rail filter above it. The `->only()` this replaces
                    // offered 4 of the 9 the column accepts (measured 2026-09-05 against
                    // `ValueSets::allowed('payments','status')`): `initiated`, `authorized`,
                    // `settled`, `bounced` and `voided` were unfilterable. `voided` shipped on
                    // 2026-08-28 to say money was NOT returned, and it is in no worklist tab
                    // either — so it could be read on the register and named by nothing.
                    //
                    // A TAB set is a curated worklist and legitimately not exhaustive; the FILTER
                    // is the exhaustive tool, which is why the fix belongs here.
                    ->options(fn () => StatusOptions::for('payments')),
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
                BankAccountFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->headerActions([
                ExportAction::make()
                    ->exporter(PaymentExporter::class)
                    ->label(__('admin.actions.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => Exports::allowed(PaymentResource::class))
                    ->authorize(fn (): bool => Exports::allowed(PaymentResource::class)),
            ])
            // Method is the reconciliation axis: cash, bank transfer and cheques are counted
            // against different places, and the summariser totals each group.
            ->groups([
                TableGroup::byColumn($table, 'method'),
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
                PdfDownloadAction::make('downloadReceipt')
                    ->label(__('admin.actions.download_receipt'))
                    ->icon(Heroicon::OutlinedReceiptPercent)
                    ->service(ReceiptPdfService::class)
                    // The payer's own language is the default; the picker is for the case their
                    // stored preference cannot know about.
                    ->recipient(fn (Payment $record) => $record->tenant)
                    // Only a RECEIVED payment (captured/reconciled/settled) has real cash to
                    // receipt. Gated in BOTH visible() (the UI) and authorize() (the real gate,
                    // which `AuthorizedAction::call()` runs at dispatch).
                    ->visible(fn (Payment $record): bool => $record->isReceived())
                    ->authorize(fn (Payment $record): bool => $record->isReceived()),
                EditAction::make()
                    ->visible(fn ($record) => PaymentResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(PaymentExporter::class)
                        ->label(__('admin.actions.export'))
                        ->visible(fn (): bool => Exports::allowed(PaymentResource::class))
                        ->authorize(fn (): bool => Exports::allowed(PaymentResource::class)),
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
