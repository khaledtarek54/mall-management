<?php

namespace App\Filament\Admin\Resources\WorkPermits\Schemas;

use App\Models\Area;
use App\Models\FacilityWorkOrder;
use App\Models\Unit;
use App\Models\Vendor;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class WorkPermitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.work_permits.sections.work'))
                ->columns(2)
                ->components([
                    PropertyField::make(),

                    Select::make('type')
                        ->label(__('admin.fields.permit_type'))
                        ->options(__('admin.enums.work_permit_type'))
                        ->required()
                        ->native(false)
                        ->helperText(__('admin.work_permits.help.type')),

                    Textarea::make('description')
                        ->label(__('admin.fields.permit_description'))
                        ->required()
                        ->rows(2)
                        ->columnSpanFull()
                        ->helperText(__('admin.work_permits.help.description')),

                    EntitySelect::make('facility_work_order_id')
                        ->label(__('admin.fields.permit_work_order'))
                        ->entity(FacilityWorkOrder::class)
                        ->searchable()
                        ->native(false)
                        ->helperText(__('admin.work_permits.help.work_order')),

                    EntitySelect::make('unit_id')
                        ->label(__('admin.fields.unit'))
                        ->entity(Unit::class)
                        ->searchable()
                        ->native(false),

                    EntitySelect::make('area_id')
                        ->label(__('admin.fields.area'))
                        ->entity(Area::class)
                        ->searchable()
                        ->native(false),

                    TextInput::make('location')
                        ->label(__('admin.fields.permit_location'))
                        ->maxLength(160)
                        ->helperText(__('admin.work_permits.help.location')),
                ]),

            Section::make(__('admin.work_permits.sections.who'))
                ->columns(2)
                ->components([
                    EntitySelect::make('vendor_id')
                        ->label(__('admin.fields.vendor'))
                        ->entity(Vendor::class)
                        ->searchable()
                        ->native(false)
                        ->helperText(__('admin.work_permits.help.vendor')),

                    TextInput::make('contractor_name')
                        ->label(__('admin.fields.permit_contractor'))
                        ->maxLength(120)
                        ->helperText(__('admin.work_permits.help.contractor')),

                    TextInput::make('contractor_phone')
                        ->label(__('admin.fields.permit_contractor_phone'))
                        ->tel()
                        ->maxLength(40),
                ]),

            Section::make(__('admin.work_permits.sections.window'))
                ->columns(2)
                ->components([
                    // To the HOUR. "Permitted on Tuesday" is not a permit.
                    DateTimePicker::make('valid_from')
                        ->label(__('admin.fields.permit_valid_from'))
                        ->required()
                        ->seconds(false)
                        ->native(false)
                        ->default(now()),

                    DateTimePicker::make('valid_to')
                        ->label(__('admin.fields.permit_valid_to'))
                        ->required()
                        ->seconds(false)
                        ->native(false)
                        ->after('valid_from')
                        ->default(now()->addHours(4))
                        ->helperText(__('admin.work_permits.help.valid_to'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.permit_valid_to')),

                    Textarea::make('conditions')
                        ->label(__('admin.fields.permit_conditions'))
                        ->rows(4)
                        ->columnSpanFull()
                        // Not `required()` on the FORM: a draft is allowed to be incomplete, and the
                        // refusal that matters is at ISSUE, in the service, where every path hits it.
                        ->helperText(__('admin.work_permits.help.conditions')),
                ]),
        ]);
    }
}
