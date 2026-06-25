<?php

namespace App\Filament\Admin\Resources\Leases\Pages;

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Models\Lease;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditLease extends EditRecord
{
    protected static string $resource = LeaseResource::class;

    /** Pre-fill the additional-units selector from the lease's pivot (non-master units). */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['additional_unit_ids'] = $this->record->units()
            ->wherePivot('is_master', false)
            ->pluck('units.id')
            ->all();

        return $data;
    }

    /** Sync the full unit set (master = unit_id) after saving the lease. */
    protected function afterSave(): void
    {
        $additional = $this->data['additional_unit_ids'] ?? [];
        $this->record->syncUnits(
            [$this->record->unit_id, ...$additional],
            $this->record->unit_id,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->generateInvoiceAction(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function generateInvoiceAction(): Action
    {
        return Action::make('generateInvoice')
            ->label(__('admin.actions.generate_invoice'))
            ->icon('heroicon-o-document-plus')
            ->color('primary')
            ->visible(fn (Lease $record) => $record->status === 'active')
            ->modalHeading(fn (Lease $record) => __('admin.actions.generate_invoice_for', ['ref' => $record->reference]))
            ->modalDescription(__('admin.actions.generate_invoice_description'))
            ->modalSubmitActionLabel(__('admin.actions.generate'))
            ->schema([
                DatePicker::make('period')
                    ->label(__('admin.actions.billing_period'))
                    ->helperText(__('admin.actions.billing_period_helper'))
                    ->displayFormat('F Y')
                    ->format('Y-m-01')
                    ->required()
                    ->default(now()->startOfMonth()->toDateString())
                    ->native(false),
                Toggle::make('prorate')
                    ->label(__('admin.actions.prorate_first_period'))
                    ->helperText(__('admin.actions.prorate_helper'))
                    ->default(true),
            ])
            ->action(function (array $data, Lease $record): void {
                $period = CarbonImmutable::parse($data['period'])->startOfMonth();
                $result = app(MonthlyBillingService::class)
                    ->generateForLease($record, $period, (bool) ($data['prorate'] ?? false));

                if ($result['status'] === 'created') {
                    Notification::make()
                        ->title(__('admin.actions.invoice_created'))
                        ->body(__('admin.actions.invoice_created_body', [
                            'number' => $result['invoice']->number,
                            'total' => 'EGP ' . number_format((float) $result['invoice']->total, 2),
                        ]))
                        ->success()
                        ->send();

                    return;
                }

                if ($result['status'] === 'skipped' && ($result['reason'] ?? null) === 'already_billed') {
                    Notification::make()
                        ->title(__('admin.actions.already_billed_title'))
                        ->body(__('admin.actions.already_billed_body', ['period' => $period->format('F Y')]))
                        ->warning()
                        ->send();

                    return;
                }

                if ($result['status'] === 'skipped' && ($result['reason'] ?? null) === 'no_applicable_charges') {
                    Notification::make()
                        ->title(__('admin.actions.no_charges_title'))
                        ->body(__('admin.actions.no_charges_body'))
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('admin.actions.generation_failed'))
                    ->body(__('admin.actions.generation_failed_body'))
                    ->danger()
                    ->send();
            });
    }
}
