<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\Tenant;
use App\Support\TenantLedger;
use App\Support\TenantScope;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The tenant ledger: every movement on this tenant's receivable, in date order, with a running
 * balance — Yardi's answer to "what do they owe, and how did it get there?".
 *
 * **Why it exists.** That question had exactly one complete answer and it was a PDF you had to
 * download. On screen the halves sat in separate tabs — invoices in one, payments in another —
 * with nothing netting them and no order between them, so an operator on a collections call held
 * both in their head and did the subtraction themselves.
 *
 * **It stores nothing.** Every row is derived by {@see TenantLedger}, and the closing balance is the
 * same figure the statement, the AR report and `billing:reconcile` produce. A stored running balance
 * would be a second truth about money that already has one.
 *
 * The relationship is `invoices` only to satisfy Filament's plumbing: `Table::records()` sets a data
 * source and `hasQuery()` is `! $dataSource`, so the base query is never used. Both `recordAction`
 * and `recordUrl` are cleared — a relation manager wires them typed `Model $record`, and these rows
 * are arrays.
 */
class TenantLedgerRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.ledger.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('date')
                    ->label(__('admin.fields.date'))
                    ->formatStateUsing(fn ($state) => $state?->format('d/m/Y') ?? '—'),
                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.ledger.types.'.$state))
                    ->color(fn (string $state) => $state === 'invoice' ? 'warning' : 'success'),
                TextColumn::make('reference')
                    ->label(__('admin.fields.reference'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->placeholder('—'),
                TextColumn::make('description')
                    ->label(__('admin.fields.description'))
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('debit')
                    ->label(__('admin.ledger.debit'))
                    ->money('EGP')
                    ->alignRight()
                    ->placeholder('')
                    ->formatStateUsing(fn ($state) => (float) $state > 0 ? 'EGP '.number_format((float) $state, 2) : ''),
                TextColumn::make('credit')
                    ->label(__('admin.ledger.credit'))
                    ->alignRight()
                    ->color('success')
                    ->formatStateUsing(fn ($state) => (float) $state > 0 ? 'EGP '.number_format((float) $state, 2) : ''),
                // The column the whole tab exists for. It is the SUBTRACTION made visible, which is
                // what an operator was doing in their head between two tabs.
                TextColumn::make('balance')
                    ->label(__('admin.ledger.balance'))
                    ->alignRight()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state) => 'EGP '.number_format((float) $state, 2))
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'success'),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('admin.ledger.empty_heading'))
            ->emptyStateDescription(__('admin.ledger.empty_body'))
            ->recordActions([])
            ->headerActions([])
            ->recordAction(null)
            ->recordUrl(null);
    }

    /** @return array<int, array<string, mixed>> */
    protected function rows(): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->getOwnerRecord();

        // Property-scoped like every other admin read: a restricted operator must not see a shared
        // tenant's movements in a mall they cannot open.
        return TenantLedger::for($tenant, TenantScope::visibleAssetIds())
            ->map(fn (array $row, int $i) => ['id' => $i] + $row)
            ->all();
    }
}
