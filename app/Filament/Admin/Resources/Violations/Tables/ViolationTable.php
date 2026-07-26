<?php

namespace App\Filament\Admin\Resources\Violations\Tables;

use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Models\Violation;
use App\Services\BillViolationFineService;
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
                // Whether the fine has been billed to the tenant, and to which invoice — so an
                // operator sees at a glance which fines are still uncollected revenue.
                TextColumn::make('billedInvoice.number')
                    ->label(__('admin.violations.fields.fine_invoice'))
                    ->badge()
                    ->color(fn (Violation $record) => $record->isBilled() ? 'success' : 'gray')
                    ->placeholder(__('admin.violations.not_billed'))
                    ->toggleable(),
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
                // Bill the recorded fine to the tenant as a VAT-exempt AR invoice — the path that did
                // not exist (fine_amount was recorded but never charged). Same triple-gate as
                // sendNotice; a DomainException (no active lease / no fine) surfaces as a toast.
                Action::make('billFine')
                    ->label(__('admin.violations.actions.bill_fine'))
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Violation $record) => $record->reference)
                    ->modalDescription(function (Violation $record) {
                        $tenant = $record->tenant;

                        return __('admin.violations.bill_fine_confirm', [
                            'amount' => 'EGP '.number_format((float) $record->fine_amount, 2),
                            'tenant' => $tenant instanceof \App\Models\Tenant ? $tenant->name : '—',
                        ]);
                    })
                    ->visible(fn (Violation $record) => ViolationResource::canBillFine($record))
                    ->authorize(fn (Violation $record) => ViolationResource::canBillFine($record))
                    ->action(function (Violation $record) {
                        abort_unless(ViolationResource::canBillFine($record), 403);

                        try {
                            $invoice = app(BillViolationFineService::class)->bill($record);
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('admin.violations.fine_billed'))
                            ->body(__('admin.violations.fine_billed_body', [
                                'number' => $invoice->number,
                                'total' => 'EGP '.number_format((float) $invoice->total, 2),
                            ]))
                            ->send();
                    }),
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
