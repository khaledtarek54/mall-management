<?php

namespace App\Filament\Admin\Resources\CreditNotes\Tables;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Exports\CreditNoteExporter;
use App\Models\CreditNote;
use App\Models\Tenant;
use App\Services\CreditNotePdfService;
use App\Support\Exports;
use App\Support\Filament\EntitySelectFilter;
use App\Support\Filament\PdfDownloadAction;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CreditNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['tenant', 'invoice']))
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.credit_note.number'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('tenant.name')
                    ->label(__('admin.tables.credit_note.tenant'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('invoice.number')
                    ->label(__('admin.fields.invoice'))
                    ->placeholder('—')
                    ->fontFamily('mono'),
                TextColumn::make('reason')
                    ->label(__('admin.fields.credit_note_reason'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.credit_note_reason.{$state}"))
                    ->color('gray'),
                TextColumn::make('issue_date')
                    ->label(__('admin.fields.issue_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('total')
                    ->label(__('admin.tables.credit_note.total'))
                    ->money('EGP')
                    ->sortable()
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('applied_amount')
                    ->label(__('admin.tables.credit_note.applied'))
                    ->money('EGP')
                    ->color('info')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('balance')
                    ->label(__('admin.tables.credit_note.balance'))
                    ->money('EGP')
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                    ->weight('bold')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.credit_note.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'issued' => 'info',
                        'applied' => 'success',
                        'void' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.credit_note')),
                SelectFilter::make('reason')
                    ->label(__('admin.fields.credit_note_reason'))
                    ->options(fn () => __('admin.enums.credit_note_reason')),
                EntitySelectFilter::make('tenant_id')
                    ->label(__('admin.filters.tenant'))
                    ->relationship('tenant')
                    ->entity(Tenant::class),
                Filter::make('issue_date_range')
                    ->label(__('admin.fields.issue_date'))
                    ->schema([
                        DatePicker::make('issued_from')
                            ->label(__('admin.filters.issued_from'))
                            ->native(false),
                        DatePicker::make('issued_until')
                            ->label(__('admin.filters.issued_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['issued_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('issue_date', '>=', $date))
                        ->when($data['issued_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('issue_date', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['issued_from'] ?? null) {
                            $indicators[] = __('admin.filters.issued_from').': '.Carbon::parse($data['issued_from'])->format('d/m/Y');
                        }
                        if ($data['issued_until'] ?? null) {
                            $indicators[] = __('admin.filters.issued_until').': '.Carbon::parse($data['issued_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->headerActions([
                ExportAction::make()
                    ->exporter(CreditNoteExporter::class)
                    ->label(__('admin.actions.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => Exports::allowed(CreditNoteResource::class))
                    ->authorize(fn (): bool => Exports::allowed(CreditNoteResource::class)),
            ])
            ->recordActions([
                LedgerEntryAction::make(),
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => CreditNoteResource::canView($record))
                    ->authorize(fn ($record) => CreditNoteResource::canView($record)),
                PdfDownloadAction::make('downloadPdf')
                    ->service(CreditNotePdfService::class)
                    ->recipient(fn (CreditNote $record) => $record->tenant)
                    ->authorize(fn (CreditNote $record) => CreditNoteResource::canView($record)),
                EditAction::make()
                    ->visible(fn ($record) => CreditNoteResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(CreditNoteExporter::class)
                        ->label(__('admin.actions.export'))
                        ->visible(fn (): bool => Exports::allowed(CreditNoteResource::class))
                        ->authorize(fn (): bool => Exports::allowed(CreditNoteResource::class)),
                    DeleteBulkAction::make()
                        ->visible(fn () => CreditNoteResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('issue_date', 'desc')
            ->emptyStateIcon('heroicon-o-receipt-refund')
            ->emptyStateHeading(__('admin.empty.credit_notes.heading'))
            ->emptyStateDescription(__('admin.empty.credit_notes.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.credit_notes.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
