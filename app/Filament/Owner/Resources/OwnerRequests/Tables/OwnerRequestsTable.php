<?php

namespace App\Filament\Owner\Resources\OwnerRequests\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class OwnerRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.owner_request.reference'))
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('recipient')
                    ->label(__('admin.tables.owner_request.recipient'))
                    ->badge()
                    ->formatStateUsing(fn (string $s) => Str::headline($s))
                    ->color('gray'),
                TextColumn::make('subject')
                    ->label(__('admin.tables.owner_request.subject'))
                    ->limit(40)
                    ->weight('medium'),
                TextColumn::make('status')
                    ->label(__('admin.tables.owner_request.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $s) => Str::headline($s))
                    ->color(fn (string $s) => match ($s) {
                        'open' => 'info',
                        'in_progress' => 'primary',
                        'resolved' => 'success',
                        'closed' => 'gray',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('resolution_notes')
                    ->label(__('admin.fields.resolution_notes'))
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('admin.tables.owner_request.created'))
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
