<?php

namespace App\Filament\Admin\Resources\FacilityWorkOrders\Schemas;

use App\Filament\Actions\EvidenceUpload;
use App\Models\Department;
use App\Models\Equipment;
use App\Models\FacilityWorkOrder;
use App\Models\Trade;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Support\EquipmentPicker;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class FacilityWorkOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        // A done/cancelled order is terminal — read-only.
        $locked = fn (?FacilityWorkOrder $record) => $record !== null && $record->isTerminal();

        return $schema->columns(2)->components([
            // Frozen once the job exists as well as pinned: the property decides the zone, the
            // equipment and the SLA clock, so re-homing a live work order would strand all three.
            PropertyField::make(alsoDisabledWhen: fn (?FacilityWorkOrder $record) => $record !== null)
                ->label(__('admin.facility.fields.property'))
                ->live(),
            EntitySelect::make('unit_id')
                ->label(__('admin.facility.fields.unit'))
                ->entity(Unit::class)
                // Clamped: asset_id is ->live() and client-supplied, so the raw value would
                // enumerate an invisible property's units.
                ->modifyOptionsQuery(fn ($query, Get $get) => ($assetId = TenantScope::clampAssetId($get('asset_id'))) !== null
                    ? $query->where('asset_id', $assetId)
                    : $query->whereRaw('1 = 0'))
                ->disabled($locked),
            Select::make('equipment_id')
                ->live()
                ->afterStateUpdated(function ($state, Set $set, string $operation): void {
                    // Only on CREATE: re-picking the machine on an existing job must not silently
                    // re-grade a priority someone already decided.
                    if ($operation !== 'create' || ! $state) {
                        return;
                    }

                    $equipment = Equipment::find($state);

                    if ($equipment instanceof Equipment) {
                        $set('priority', $equipment->defaultWorkOrderPriority());
                    }
                })
                ->label(__('admin.facility.equipment.singular'))
                ->helperText(__('admin.facility.equipment_wo_hint'))
                // The machine this job is against (FR-PPM-03). Copied from the plan on a
                // generated order; chosen here for an ad-hoc one.
                //
                // The record's own stored machine is always included, even if since
                // deactivated or soft-deleted: Filament validates the CURRENT value against
                // the `in:` rule derived from these options, so filtering to ->active()
                // alone made an open work order uneditable — you couldn't even reschedule
                // it — the moment its machine was retired. Blanking the field escaped that,
                // but destroyed the FR-PPM-03 record of which machine the job was against.
                ->options(fn (Get $get, ?FacilityWorkOrder $record) => EquipmentPicker::options($get('asset_id'), $record?->equipment_id))
                ->searchable()
                ->preload()
                ->native(false)
                ->disabled($locked),
            TextInput::make('title')
                ->label(__('admin.facility.fields.title'))
                ->required()
                ->maxLength(255)
                ->disabled($locked),
            // التخصص — a ROW now, not a translation key. Required with NO default: the trade
            // routes the work, decides which vendors may be dispatched and is the axis every
            // maintenance-spend report groups by, and defaulting it to "Other" would make it
            // meaningless on exactly the jobs nobody stopped to think about.
            Select::make('trade_id')
                ->label(__('admin.facility.fields.trade'))
                ->options(fn (?FacilityWorkOrder $record) => Trade::options($record?->trade_id))
                ->required()
                ->native(false)
                ->searchable()
                // Live so the vendor picker below regroups the moment the trade is chosen — the
                // coordinator sees who does HVAC without leaving the form.
                ->live()
                ->helperText(__('admin.facility.help.trade'))
                ->disabled($locked),
            // ---- What this job is EXPECTED to cost (Maximo §3/§4: the planned half) ----
            //
            // Replaced the old hand-typed `job_value`, which existed only to feed the SLA
            // percent-of-value basis and duplicated the service estimate. The penalty now reads
            // this — and the ACTUAL service cost once a bill has landed.
            TextInput::make('est_labour_hours')
                ->label(__('admin.facility.fields.est_labour_hours'))
                ->numeric()
                ->minValue(0)
                // A ceiling, because without one a mistyped figure is a 500 rather than a message:
                // the column is `decimal(8,2)` and MySQL answers an overflow with
                // `SQLSTATE[22003] … 1264 Out of range value`. The number lives on the model that
                // owns the column — see `FacilityWorkOrder::MAX_EST_LABOUR_HOURS`.
                ->maxValue(FacilityWorkOrder::MAX_EST_LABOUR_HOURS)
                ->helperText(__('admin.facility.help.est_labour_hours'))
                ->disabled($locked),

            // The ceiling a contractor may spend without coming back (ServiceChannel §3).
            // Defaulted from the trade when the job is raised; an approved quote raises it.
            TextInput::make('nte_amount')
                ->label(__('admin.facility.fields.nte'))
                ->numeric()
                ->minValue(0)
                ->prefix('EGP')
                ->helperText(__('admin.facility.help.nte'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.nte'))
                ->disabled($locked),

            TextInput::make('est_service_cost')
                ->label(__('admin.facility.fields.est_service_cost'))
                ->numeric()
                ->minValue(0)
                ->prefix('EGP')
                ->helperText(__('admin.facility.help.est_service_cost'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.est_service_cost'))
                ->disabled($locked),
            Select::make('priority')
                ->label(__('admin.facility.fields.priority'))
                ->options(fn () => __('admin.facility.priorities'))
                ->default('medium')
                ->required()
                ->native(false)
                // Criticality pre-fills this when a machine is picked, and the operator sees the
                // value before saving. Deliberately visible rather than applied on save: a system
                // that silently disagrees with an explicit choice teaches people to distrust it.
                ->helperText(__('admin.facility.helpers.priority_from_criticality'))
                ->disabled($locked),
            DatePicker::make('scheduled_for')
                ->label(__('admin.facility.fields.scheduled_for'))
                ->default(now())
                ->required()
                ->native(false)
                ->disabled($locked),
            EntitySelect::make('department_id')
                ->label(__('admin.facility.fields.department'))
                ->entity(Department::class)
                ->searchable()
                ->native(false)
                ->disabled($locked),
            // **WHICH SIDE THE JOB IS ON — the classification this form never asked for, while
            // offering both of its answers.**
            //
            // `execution_type` is the corrective classification (FR-CM-02) and `FacilityWorkOrder`
            // enforces it as a real XOR: an internal job may not also name a vendor, an external
            // one may not also name a technician. This form had no control for it AT ALL and
            // rendered both assignee pickers unconditionally, so the ordinary act — an in-house
            // job that turns out to need a contractor — was a dead end in both directions.
            //
            // MEASURED 2026-09-04 (throwaway probe on a fresh sqlite schema, since deleted):
            // create a `cm` with `execution_type = internal` carrying a technician, then
            // `update(['vendor_id' => …])` exactly as this form does, and the model throws
            //   InvalidArgumentException: An internal corrective work order is handled in-house;
            //   it cannot also name a vendor.
            // That is not a `DomainException`, so `bootstrap/app.php` renders the 500 PAGE and the
            // operator loses the form they filled in. Sending the new classification alongside it
            // does not help either — the technician is still on the row, so the mirror refusal
            // fires. Both halves reproduced.
            //
            // Only a CORRECTIVE order is classified, and that is load-bearing:
            // `FacilityWorkOrder::saving()` returns before the XOR for a preventive order, which
            // legitimately carries a department AND a vendor at once, so a PPM job keeps both
            // pickers exactly as it had them.
            Select::make('execution_type')
                ->label(__('admin.facility.fields.execution_type'))
                ->options(fn () => __('admin.facility.execution_types'))
                ->helperText(__('admin.facility.help.execution_type'))
                ->required()
                ->native(false)
                // Live, so the two pickers below follow the answer while it is being typed — the
                // shape `CorrectiveWorkOrderForm` already uses at origination, for the reason it
                // states there: "a form that offered both would just produce an error the user
                // can't act on".
                ->live()
                // Clearing the excluded side is not cosmetic. Both pickers below are
                // `dehydratedWhenHidden()`, and Filament VALIDATES a hidden field that is still
                // dehydrated (`Schemas\Components\Concerns\HasState::isNeitherDehydratedNor
                // Validated()` returns false once `isDehydratedWhenHidden()` is true) — so a
                // technician left in state would be checked against `technicianOptions()`, which
                // excludes staff since re-assigned to another mall: a validation error on a field
                // the operator can no longer see.
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    if ($state === FacilityWorkOrder::EXECUTION_EXTERNAL) {
                        $set('assigned_to_user_id', null);
                    }

                    if ($state === FacilityWorkOrder::EXECUTION_INTERNAL) {
                        $set('vendor_id', null);
                    }
                })
                ->visible(fn ($record) => $record instanceof FacilityWorkOrder && $record->isCorrective())
                ->disabled($locked),
            // **WHO IS DOING IT — and this form had no way to say so after creation.**
            // `assigned_to_user_id` existed on the model, drove `notifyAssignee()` and was rendered
            // on the CORRECTIVE form only, i.e. at creation from a tenant request. So a job could
            // be assigned once and never REASSIGNED: the technician who is off sick keeps it, the
            // one who picks it up is not told, and the model's own assignment notification is
            // unreachable for every job that changes hands. A supervisor's most ordinary act had no
            // screen.
            Select::make('assigned_to_user_id')
                ->label(__('admin.facility.cm.assignee'))
                ->helperText(__('admin.facility.cm.assignee_hint'))
                // Staff who can actually reach this property — never another mall's roster. The
                // grouping in the closure is load-bearing: ungrouped, the OR escapes the property
                // clause and hands the picker everyone.
                ->options(fn ($record) => self::technicianOptions(
                    $record?->asset_id ?? TenantScope::currentAssetId(),
                ))
                ->searchable()
                ->native(false)
                ->visible(fn ($record, Get $get) => self::executionType($record, $get) !== FacilityWorkOrder::EXECUTION_EXTERNAL)
                // **HIDING IS NOT CLEARING.** A hidden component is not dehydrated at all
                // (`HasState::isDehydrated()` is false unless `dehydratedWhenHidden()`), so
                // re-classifying the job would DROP this key from the payload, leave the technician
                // standing on the row and hit the mirror refusal — the trap the bank-account rail
                // field records. So it is dehydrated when hidden, and nulled SERVER-SIDE here:
                // `afterStateUpdated` above is a browser round-trip and never a gate, because the
                // Livewire payload still carries whatever the client put in it. Same rule
                // `RaiseCorrectiveWorkOrderService::create()` applies at origination.
                ->dehydratedWhenHidden()
                ->dehydrateStateUsing(fn ($state, $record, Get $get) => self::executionType($record, $get) === FacilityWorkOrder::EXECUTION_EXTERNAL
                    ? null
                    : $state)
                ->disabled($locked),
            Select::make('vendor_id')
                ->label(__('admin.facility.fields.vendor'))
                // Only dispatchable vendors (active + COI not lapsed); the saving guard is the
                // real gate. Grouped by whether they do the chosen trade — a suggestion, not a
                // filter, so an unusual but legitimate pick is still possible.
                ->options(fn ($record, Get $get) => Vendor::assignableOptions(
                    $record?->vendor_id,
                    filled($get('trade_id')) ? (int) $get('trade_id') : null,
                ))
                ->searchable()
                ->native(false)
                ->visible(fn ($record, Get $get) => self::executionType($record, $get) !== FacilityWorkOrder::EXECUTION_INTERNAL)
                // The mirror of the technician picker above, for the same two reasons.
                ->dehydratedWhenHidden()
                ->dehydrateStateUsing(fn ($state, $record, Get $get) => self::executionType($record, $get) === FacilityWorkOrder::EXECUTION_INTERNAL
                    ? null
                    : $state)
                ->disabled($locked),
            Textarea::make('notes')
                ->label(__('admin.facility.fields.notes'))
                ->rows(2)
                ->columnSpanFull()
                ->disabled($locked),
            // Evidence for the job. NOT disabled by `$locked`: a photograph is the one thing an
            // engineer legitimately adds after the fact — the job is done, the phone is in their
            // pocket, and refusing the upload because the order reached a terminal state is how a
            // record ends up with no evidence at all. The commercial fields stay frozen.
            //
            // What it ACCEPTS is no longer stated here (SW-126). These three lines were the only
            // place in the app that let a PDF onto the `evidence` collection, so the operator's own
            // "Attach evidence" button and the contractor's portal door both refused the signed
            // permit this form takes. `EvidenceUpload::accepting()` is the one answer for all three;
            // everything else on this chain is genuinely this door's own — removal included, which
            // is why the field is still built here rather than by `EvidenceUpload::make()`.
            EvidenceUpload::accepting(SpatieMediaLibraryFileUpload::make('evidence'))
                ->label(__('admin.facility.fields.evidence'))
                ->helperText(__('admin.facility.helpers.evidence'))
                ->collection('evidence')
                ->multiple()
                ->appendFiles()
                ->reorderable()
                ->downloadable()
                ->openable()
                ->preserveFilenames()
                ->columnSpanFull(),
        ]);
    }

    /**
     * The classification this save will be judged against — the live form state where the operator
     * has stated one, the stored column otherwise.
     *
     * Null for anything that is NOT corrective, which is the load-bearing half:
     * {@see FacilityWorkOrder::saving()} returns before the XOR for a preventive order, and a PPM
     * job legitimately carries a department AND a vendor at once, so both pickers must stay.
     *
     * `mixed $record` deliberately — a schema resolves `$record` by NAME and it is null on a
     * create page, so a typed parameter buys nothing and pushes resolution down the by-TYPE path.
     */
    private static function executionType(mixed $record, Get $get): ?string
    {
        if (! $record instanceof FacilityWorkOrder || ! $record->isCorrective()) {
            return null;
        }

        $state = $get('execution_type');

        // A cleared Select sends '', which is not an answer. Falling back to the row rather than
        // reading a blank as "neither" matters: "neither" would show BOTH pickers again and
        // re-open exactly the combination this closes.
        return is_string($state) && $state !== '' ? $state : $record->execution_type;
    }

    /**
     * Staff who can reach this property, for the assignee picker.
     *
     * GROUPED deliberately — `whereHas(...)->orWhereDoesntHave(...)` ungrouped is only correct
     * while nothing else is in the query: add one `->where()` above it and the OR escapes its
     * scope, turning this into "(other AND assigned-here) OR unassigned", which hands every
     * property's roster to the picker.
     *
     * @return array<int, string>
     */
    private static function technicianOptions(?int $assetId): array
    {
        return User::query()
            ->when($assetId !== null, fn ($q) => $q->where(
                fn ($q) => $q->whereHas('assignedAssets', fn ($a) => $a->where('assets.id', $assetId))
                    ->orWhereDoesntHave('assignedAssets')
            ))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
