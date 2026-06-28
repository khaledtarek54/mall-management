<?php

namespace App\Filament\Admin\Resources\Vendors\RelationManagers;

use App\Models\Asset;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'contracts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.sections.vendor_contracts');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->components([
                TextInput::make('reference')->label(__('admin.fields.reference'))->maxLength(100),
                TextInput::make('name')->label(__('admin.fields.name') ?: 'Name')->required()->maxLength(200),
                Select::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->options(fn () => __('admin.statuses.vendor_contract'))
                    ->required()
                    ->default('draft')
                    ->native(false),
                Select::make('asset_id')
                    ->label(__('admin.resources.asset.singular'))
                    ->options(fn () => \App\Support\TenantScope::selectableAssetOptions())
                    ->searchable()
                    ->placeholder('—')
                    ->default(fn () => \App\Support\TenantScope::currentAssetId())
                    ->disabled(fn () => \App\Support\TenantScope::currentAssetId() !== null)
                    ->dehydrated(),
                DatePicker::make('start_date')->label(__('admin.fields.start_date') ?: 'Start')->required()->native(false),
                DatePicker::make('end_date')->label(__('admin.fields.end_date') ?: 'End')->native(false)->afterOrEqual('start_date'),
                TextInput::make('value')
                    ->label(__('admin.fields.amount'))
                    ->prefix('EGP')
                    ->numeric()
                    ->minValue(0),
                Select::make('currency')
                    ->label(__('admin.fields.currency'))
                    ->options(['EGP' => 'EGP', 'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'SAR' => 'SAR', 'AED' => 'AED'])
                    ->default('EGP')
                    ->required()
                    ->native(false),
            ]),
            Section::make(__('admin.sections.notes'))->collapsed()->components([
                Textarea::make('scope')->label(__('admin.fields.description'))->rows(3)->columnSpanFull(),
                Textarea::make('notes')->label(__('admin.fields.notes'))->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->fontFamily('mono')->size('xs')->placeholder('—'),
                TextColumn::make('name')->weight('bold')->searchable(),
                TextColumn::make('asset.name')->placeholder(__('admin.fields.portfolio') ?: 'Portfolio')->color('gray'),
                TextColumn::make('start_date')->date('d/m/Y')->sortable(),
                TextColumn::make('end_date')->date('d/m/Y')->sortable()->placeholder('—'),
                TextColumn::make('value')->money('EGP')->alignRight(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.vendor_contract.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'expired', 'terminated' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.vendor_contract')),
            ])
            ->headerActions([
                CreateAction::make()->label(__('admin.actions.add_contract'))
                    ->visible(fn () => auth()->user()?->can('vendors.edit') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('vendors.edit') ?? false),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false),
            ])
            ->defaultSort('start_date', 'desc');
    }
}
