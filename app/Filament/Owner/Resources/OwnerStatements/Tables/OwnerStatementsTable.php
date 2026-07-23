<?php

namespace App\Filament\Owner\Resources\OwnerStatements\Tables;

use App\Models\OwnerStatement;
use App\Services\OwnerAccounting\OwnerStatementPdfService;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OwnerStatementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.owner_statements.fields.reference'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('asset.name')
                    ->label(__('admin.owner_statements.fields.property'))
                    ->toggleable(),
                TextColumn::make('run.period_end')
                    ->label(__('admin.owner_statements.fields.period'))
                    ->date('M Y')
                    ->sortable(),
                TextColumn::make('owner_share')
                    ->label(__('admin.owner_statements.fields.owner_share'))
                    ->money('EGP')
                    ->alignRight(),
                TextColumn::make('paid_to_date')
                    ->label(__('admin.owner_statements.fields.paid_to_date'))
                    ->money('EGP')
                    ->alignRight()
                    ->toggleable(),
                // What the owner actually wants to know: how much is still coming to them.
                TextColumn::make('outstanding')
                    ->label(__('admin.owner_statements.fields.outstanding'))
                    ->state(fn (OwnerStatement $record) => $record->outstanding())
                    ->money('EGP')
                    ->alignRight()
                    ->color(fn (OwnerStatement $record) => $record->outstanding() > 0.005 ? 'warning' : 'success'),
                TextColumn::make('status')
                    ->label(__('admin.owner_statements.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.owner_statements.statuses.{$state}"))
                    ->color(fn (string $state) => $state === OwnerStatement::STATUS_SENT ? 'success' : 'info'),
            ])
            ->recordActions([
                // Read the itemized statement in-portal — revenue by account, expenses by account,
                // net — from the frozen snapshot, without forcing a download.
                Action::make('view_breakdown')
                    ->label(__('admin.owner_statements.actions.view_working'))
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->schema(fn (OwnerStatement $record) => [
                        TextEntry::make('revenue')
                            ->label(__('admin.owner_statements.pdf.revenue'))
                            ->state(fn () => self::lines($record, 'revenue')),
                        TextEntry::make('expenses')
                            ->label(__('admin.owner_statements.pdf.expenses'))
                            ->state(fn () => self::lines($record, 'expense')),
                        TextEntry::make('net')
                            ->label(__('admin.owner_statements.fields.net_operating_income'))
                            ->state(fn () => 'EGP '.number_format((float) $record->run->net_operating_income, 2)),
                        TextEntry::make('share')
                            ->label(__('admin.owner_statements.fields.owner_share'))
                            ->state(fn () => 'EGP '.number_format((float) $record->owner_share, 2)),
                    ]),
                Action::make('download_pdf')
                    ->label(__('admin.owner_statements.actions.download_pdf'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->action(function (OwnerStatement $record) {
                        $svc = app(OwnerStatementPdfService::class);
                        $pdf = $svc->build($record);

                        return response()->streamDownload(
                            fn () => print($pdf),
                            $svc->filename($record),
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateIcon('heroicon-o-document-currency-dollar')
            ->emptyStateHeading(__('admin.owner_statements.owner_empty_heading'))
            ->emptyStateDescription(__('admin.owner_statements.owner_empty_description'));
    }

    /** Localized P&L lines from the statement run's frozen breakdown, one per line. */
    private static function lines(OwnerStatement $statement, string $side): string
    {
        $isRtl = app()->getLocale() === 'ar';
        $rows = (array) (($statement->run->income_breakdown ?? [])[$side] ?? []);

        if ($rows === []) {
            return __('admin.owner_statements.pdf.none');
        }

        return collect($rows)->map(function (array $r) use ($isRtl) {
            $name = $isRtl ? ($r['name_ar'] ?? $r['name_en'] ?? $r['code']) : ($r['name_en'] ?? $r['name_ar'] ?? $r['code']);

            return $name.' — EGP '.number_format((float) ($r['amount'] ?? 0), 2);
        })->join("\n");
    }
}
