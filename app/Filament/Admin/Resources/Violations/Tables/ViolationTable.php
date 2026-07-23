<?php

namespace App\Filament\Admin\Resources\Violations\Tables;

use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Models\Violation;
use App\Services\SendViolationNoticeAction;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ViolationTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Eager-load the photo media so the evidence indicator doesn't N+1 across the list.
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'tenant', 'media']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.violations.fields.reference'))
                    ->fontFamily('mono')
                    ->weight('bold'),
                TextColumn::make('tenant.name')
                    ->label(__('admin.violations.fields.tenant'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label(__('admin.violations.fields.category'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.violations.categories.{$state}") : '—')
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('admin.violations.fields.description'))
                    ->limit(50)
                    ->wrap()
                    // Show at a glance whether the breach is backed by photos — a violation with
                    // evidence is a defensible one.
                    ->icon(fn (Violation $record) => $record->getMedia(Violation::PHOTOS_COLLECTION)->isNotEmpty()
                        ? 'heroicon-m-camera' : null)
                    ->iconColor('primary'),
                TextColumn::make('fine_amount')
                    ->label(__('admin.violations.fields.fine_amount'))
                    ->money('EGP')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('violation_date')
                    ->label(__('admin.violations.fields.violation_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.violations.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.violation.$state"))
                    ->color(fn (string $state) => match ($state) {
                        Violation::STATUS_RESOLVED => 'success',
                        default => 'warning',
                    }),
                TextColumn::make('notified_at')
                    ->label(__('admin.violations.fields.notified_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('admin.violations.not_notified'))
                    ->toggleable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.violations.fields.property'))
                    ->badge()->color('gray')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.violations.fields.status'))
                    ->options(fn () => collect(Violation::STATUSES)
                        ->mapWithKeys(fn (string $s) => [$s => __("admin.statuses.violation.$s")])),
                // Filter by kind — the point of categorising: "show me this quarter's signage breaches".
                SelectFilter::make('category')
                    ->label(__('admin.violations.fields.category'))
                    ->options(fn () => collect(Violation::CATEGORIES)
                        ->mapWithKeys(fn (string $c) => [$c => __("admin.violations.categories.{$c}")])),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // FR-REQ-17 — the explicit "Send notice" action (never auto on create).
                // Gated in BOTH visible() (the UI) and authorize()/action() (the real
                // dispatch gate — visible() is not a dispatch gate). `abort_unless` is
                // the server-side backstop against a crafted Livewire mount.
                Action::make('sendNotice')
                    ->label(__('admin.violations.actions.send_notice'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Violation $record) => $record->reference)
                    ->modalDescription(__('admin.violations.send_notice_confirm'))
                    ->visible(fn (Violation $record) => ViolationResource::canNotify($record))
                    ->authorize(fn (Violation $record) => ViolationResource::canNotify($record))
                    ->action(function (Violation $record) {
                        abort_unless(ViolationResource::canNotify($record), 403);

                        $sent = app(SendViolationNoticeAction::class)->handle($record);

                        if ($sent) {
                            Notification::make()
                                ->title(__('admin.violations.notice_sent'))
                                ->success()
                                ->send();
                        } else {
                            // Failure-contained: report, don't crash the click.
                            Notification::make()
                                ->title(__('admin.violations.notice_failed'))
                                ->warning()
                                ->send();
                        }
                    }),
                EditAction::make()->visible(fn (Violation $record) => ViolationResource::canEdit($record)),
            ])
            ->defaultSort('violation_date', 'desc');
    }
}
