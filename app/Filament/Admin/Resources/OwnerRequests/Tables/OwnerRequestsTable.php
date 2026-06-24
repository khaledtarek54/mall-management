<?php

namespace App\Filament\Admin\Resources\OwnerRequests\Tables;

use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Models\OwnerRequest;
use App\Services\OwnerRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
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
                    ->searchable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('subject')
                    ->label(__('admin.tables.owner_request.subject'))
                    ->searchable()
                    ->limit(40)
                    ->weight('medium'),
                TextColumn::make('creator.name')
                    ->label(__('admin.tables.owner_request.owner'))
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.tables.owner_request.property'))
                    ->placeholder('—'),
                TextColumn::make('priority')
                    ->label(__('admin.tables.owner_request.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $s) => Str::headline($s))
                    ->color(fn (string $s) => match ($s) {
                        'high' => 'danger',
                        'medium' => 'warning',
                        default => 'gray',
                    }),
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
                TextColumn::make('created_at')
                    ->label(__('admin.tables.owner_request.created'))
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.tables.owner_request.status'))
                    ->options(fn () => collect(OwnerRequest::STATUSES)->mapWithKeys(fn ($s) => [$s => Str::headline($s)])),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('respond')
                    ->label(__('admin.actions.respond'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('primary')
                    ->visible(fn (OwnerRequest $r) => OwnerRequestResource::canEdit($r) && ! $r->isTerminal())
                    ->modalHeading(fn (OwnerRequest $r) => $r->reference)
                    ->modalDescription(fn (OwnerRequest $r) => $r->subject . ' — ' . $r->body)
                    ->fillForm(fn (OwnerRequest $r) => ['status' => $r->status])
                    ->schema([
                        Select::make('status')
                            ->label(__('admin.tables.owner_request.status'))
                            ->options(fn () => collect(OwnerRequest::STATUSES)->mapWithKeys(fn ($s) => [$s => Str::headline($s)]))
                            ->required()
                            ->native(false),
                        Textarea::make('resolution_notes')
                            ->label(__('admin.fields.resolution_notes'))
                            ->rows(3),
                    ])
                    ->action(function (OwnerRequest $r, array $data) {
                        app(OwnerRequestService::class)->transition($r, $data['status'], $data);

                        Notification::make()
                            ->title(__('admin.actions.owner_request_updated'))
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
