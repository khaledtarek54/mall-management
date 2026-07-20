<?php

namespace App\Filament\Admin\Resources\CreditNotes\Tables;

use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Exports\CreditNoteExporter;
use App\Models\CreditNote;
use App\Services\CreditNotePdfService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Forms\Components\DatePicker;
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
                    ->alignRight(),
                TextColumn::make('applied_amount')
                    ->label(__('admin.tables.credit_note.applied'))
                    ->money('EGP')
                    ->color('info')
                    ->alignRight(),
                TextColumn::make('balance')
                    ->label(__('admin.tables.credit_note.balance'))
                    ->money('EGP')
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                    ->weight('bold')
                    ->alignRight(),
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
                SelectFilter::make('tenant_id')
                    ->label(__('admin.filters.tenant'))
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload(),
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
                            $indicators[] = __('admin.filters.issued_from') . ': ' . \Carbon\Carbon::parse($data['issued_from'])->format('d/m/Y');
                        }
                        if ($data['issued_until'] ?? null) {
                            $indicators[] = __('admin.filters.issued_until') . ': ' . \Carbon\Carbon::parse($data['issued_until'])->format('d/m/Y');
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
                    ->color('gray'),
            ])
            ->recordActions([
                Action::make('downloadPdf')
                    ->label(__('admin.actions.pdf'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    // Gate in BOTH authorize() (UI) and action() (the real gate — mountAction ignores visible()).
                    ->authorize(fn (CreditNote $record) => CreditNoteResource::canView($record))
                    ->action(function (CreditNote $record) {
                        abort_unless(CreditNoteResource::canView($record), 403);
                        $svc = app(CreditNotePdfService::class);
                        $pdf = $svc->build($record);

                        return response()->streamDownload(
                            fn () => print($pdf),
                            $svc->filename($record),
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
                EditAction::make()
                    ->visible(fn ($record) => CreditNoteResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(CreditNoteExporter::class)
                        ->label(__('admin.actions.export')),
                    DeleteBulkAction::make()
                        ->visible(fn () => CreditNoteResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('issue_date', 'desc')
            ->emptyStateIcon('heroicon-o-receipt-refund')
            ->emptyStateHeading(__('admin.empty.credit_notes.heading'))
            ->emptyStateDescription(__('admin.empty.credit_notes.description'))
            ->emptyStateActions([
                \Filament\Actions\CreateAction::make()
                    ->label(__('admin.empty.credit_notes.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
