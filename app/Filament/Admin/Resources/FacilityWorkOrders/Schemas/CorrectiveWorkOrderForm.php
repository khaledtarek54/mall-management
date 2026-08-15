<?php

namespace App\Filament\Admin\Resources\FacilityWorkOrders\Schemas;

use App\Models\FacilityWorkOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Support\AssignedAssets;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The fields for raising a corrective job (FR-CM-02/03/04) — shared by the "raise from a
 * failed check" action on the checklist and the "raise follow-up" action on a work order,
 * so the two entry points can't drift apart.
 *
 * Where the work is (property, unit, machine, category) is NOT asked: it is inherited from
 * the order the CM came out of, because those are facts about the fault rather than choices.
 */
class CorrectiveWorkOrderForm
{
    /**
     * @param  int|null  $assetId  the originating order's property — scopes the assignee list
     * @return array<int,\Filament\Schemas\Components\Component>
     */
    public static function fields(?int $assetId): array
    {
        return [
            TextInput::make('title')
                ->label(__('admin.facility.fields.title'))
                ->maxLength(255),

            // FR-CM-06 — the tier that decides the SLA once the job is accepted.
            Select::make('priority')
                ->label(__('admin.facility.fields.priority'))
                ->options(fn () => __('admin.facility.priorities'))
                ->default('medium')
                ->required()
                ->native(false),

            // FR-CM-02. Live, because it decides which assignee field applies — the model
            // enforces the XOR, and a form that offered both would just produce an error
            // the user can't act on.
            Select::make('execution_type')
                ->label(__('admin.facility.fields.execution_type'))
                ->options(fn () => __('admin.facility.execution_types'))
                ->default(FacilityWorkOrder::EXECUTION_INTERNAL)
                ->required()
                ->live()
                ->native(false),

            // FR-CM-03 — a technician OR a vendor, never both.
            Select::make('assigned_to_user_id')
                ->label(__('admin.facility.cm.assignee'))
                ->helperText(__('admin.facility.cm.assignee_hint'))
                // Staff who can actually reach this property — never leak another mall's roster.
                ->options(fn () => self::technicianOptions($assetId))
                ->visible(fn (Get $get) => $get('execution_type') === FacilityWorkOrder::EXECUTION_INTERNAL)
                ->searchable()
                ->native(false),

            Select::make('vendor_id')
                ->label(__('admin.facility.fields.vendor'))
                // Only dispatchable vendors (active + COI not lapsed); the saving guard is the real gate.
                ->options(fn ($record) => Vendor::assignableOptions($record?->vendor_id))
                ->visible(fn (Get $get) => $get('execution_type') === FacilityWorkOrder::EXECUTION_EXTERNAL)
                ->searchable()
                ->native(false),

            DatePicker::make('scheduled_for')
                ->label(__('admin.facility.fields.scheduled_for'))
                ->default(now())
                ->native(false),

            // FR-CM-08 — only the percent_of_value penalty basis needs this, so it is shown
            // for vendor jobs and left optional. Without it, a percent contract cannot
            // assess a penalty at all (the service returns null rather than charging 0).
            TextInput::make('job_value')
                ->label(__('admin.facility.penalty.job_value'))
                ->helperText(__('admin.facility.penalty.job_value_hint'))
                ->prefix('EGP')
                ->numeric()
                ->minValue(0)
                ->visible(fn (Get $get) => $get('execution_type') === FacilityWorkOrder::EXECUTION_EXTERNAL),

            // FR-CM-04 — what is wrong. Required here and in the model.
            Textarea::make('description')
                ->label(__('admin.facility.fields.description'))
                ->required()
                ->rows(3)
                ->columnSpanFull(),
        ];
    }

    /**
     * Staff assigned to the originating order's property (plus portfolio-wide users, who
     * are assigned to none). Vendors are the shared catalog, so they are not scoped.
     *
     * @return array<int,string>
     */
    private static function technicianOptions(?int $assetId): array
    {
        return User::query()
            ->when($assetId !== null, fn ($q) => $q->where(
                // Grouped deliberately. `whereHas(...)->orWhereDoesntHave(...)` ungrouped is
                // only correct while nothing else is in the query: add one ->where() above
                // it and the OR escapes its scope, turning this into
                // "(other AND assigned-here) OR unassigned" — which would hand every
                // property's roster to the picker. Group it now rather than leave the trap.
                fn ($q) => $q->whereHas('assignedAssets', fn ($a) => $a->where('assets.id', $assetId))
                    ->orWhereDoesntHave('assignedAssets')
            ))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
