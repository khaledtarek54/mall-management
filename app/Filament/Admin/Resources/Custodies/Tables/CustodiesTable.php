<?php

namespace App\Filament\Admin\Resources\Custodies\Tables;

use App\Filament\Admin\Resources\Custodies\CustodyResource;
use App\Models\Custody;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustodiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee', 'asset']))
            ->columns([
                TextColumn::make('custody_date')
                    ->label(__('admin.custodies.fields.custody_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('employee.name')
                    ->label(__('admin.custodies.fields.custodian'))
                    ->description(fn (Custody $record) => $record->reference)
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.custodies.fields.property'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label(__('admin.custodies.fields.amount'))
                    ->money('EGP')
                    ->sortable(),
                TextColumn::make('settled_sum')
                    ->label(__('admin.custodies.fields.settled'))
                    ->money('EGP')
                    ->default(0)
                    ->color('success'),
                TextColumn::make('outstanding')
                    ->label(__('admin.custodies.fields.outstanding'))
                    // amount − settled (derived from the withSum alias — no N+1).
                    ->state(fn (Custody $record) => round(max(0, (float) $record->amount - (float) ($record->settled_sum ?? 0)), 2))
                    ->money('EGP')
                    ->weight('bold')
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'gray'),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (Custody $record) => CustodyResource::canEdit($record)),
            ])
            ->defaultSort('custody_date', 'desc');
    }
}
