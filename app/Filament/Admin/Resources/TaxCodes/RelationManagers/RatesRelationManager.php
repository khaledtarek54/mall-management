<?php

namespace App\Filament\Admin\Resources\TaxCodes\RelationManagers;

use App\Models\TaxRate;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The rate ladder — every rate this tax has carried, and the day each came into force.
 *
 * **A rate change is a new rung, not an edit.** That is the whole reason this is a dated list
 * rather than a number on the parent: an accountant can enter next January's rate today and it
 * starts applying by itself, and a document dated before the change still resolves the rate that
 * was in force when it was raised.
 *
 * There is no end date. A rung runs until the next one starts, which makes overlapping and missing
 * windows unrepresentable — the data error that makes a legacy charge schedule bill nothing.
 */
class RatesRelationManager extends RelationManager
{
    protected static string $relationship = 'rates';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.tax_codes.rate_ladder');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('rate')
                ->label(__('admin.fields.tax_rate'))
                ->suffix('%')
                ->numeric()
                ->required()
                // **A WITHHOLDING RUNG IS STORED NEGATIVE, AND A FLAT FLOOR OF ZERO REFUSED EVERY
                // ONE OF THEM.** "WH -1%" is the operator's own notation, because the tax comes OFF
                // what is paid to a supplier rather than being added to it — the seeder writes the
                // rungs that way and `TaxCatalogueConformanceTest` asserts they stay that way.
                // Measured on the dev database 2026-09-04: 8 withholding rungs, every one negative,
                // and not one of them savable through this form. It failed as *invalid* on a field
                // the operator had not touched (they were editing the note), and next year's rate
                // could not be entered at all — for the one family whose rates Egypt revises by
                // decree, on a ladder that exists to be dated in advance.
                //
                // The bounds are the tax code's own, so the sign convention has ONE home and this
                // screen cannot disagree with the seeder that wrote the rows underneath it.
                ->minValue(fn (): float => $this->getOwnerRecord()->rateBounds()[0])
                ->maxValue(fn (): float => $this->getOwnerRecord()->rateBounds()[1])
                // Only where the sign is surprising. On VAT, stamp and schedule the "%" suffix has
                // already said everything there is to say.
                ->helperText(fn (): ?string => $this->getOwnerRecord()->rateIsNegative()
                    ? __('admin.helpers.tax_rate_withheld')
                    : null)
                // Three decimals, because Egyptian withholding runs to half a percent and a field
                // that cannot express the statutory figure is worse than no configuration at all.
                ->step('0.001'),

            DatePicker::make('effective_from')
                ->label(__('admin.fields.effective_from'))
                ->required()
                ->native(false)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tax_code_id', $this->getOwnerRecord()->getKey()))
                ->helperText(__('admin.helpers.tax_effective_from'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.tax_effective_from')),

            TextInput::make('note')
                ->label(__('admin.fields.note'))
                ->maxLength(255)
                ->columnSpanFull()
                ->helperText(__('admin.helpers.tax_rate_note')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('rate')
            // A ladder is three or four rows, identified by a date and a percentage. There is
            // nothing to type that would find one, and a box that can never match reads as a
            // feature that is broken.
            ->searchable(false)
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('effective_from')
                    ->label(__('admin.fields.effective_from'))
                    ->date('d/m/Y')
                    ->sortable()
                    // Which rung is the live one, without making the operator compare dates against
                    // today themselves.
                    ->description(fn (TaxRate $record) => $record->effective_from->isFuture()
                        ? __('admin.tax_codes.scheduled')
                        : null),

                TextColumn::make('rate')
                    ->label(__('admin.fields.tax_rate'))
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).'%')
                    ->badge(),

                TextColumn::make('note')
                    ->label(__('admin.fields.note'))
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.tax_codes.add_rate'))
                    ->modalHeading(__('admin.tax_codes.add_rate'))
                    ->authorize(fn () => auth()->user()?->can('tax_codes.edit') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize(fn () => auth()->user()?->can('tax_codes.edit') ?? false),
                // A rung posts nothing and settles nothing — issued documents carry their own rate
                // and are never re-rated — so removing one changes what is billed NEXT and no
                // history. Registered as parent-managed in `DeletionPolicy`.
                DeleteAction::make()
                    ->authorize(fn () => auth()->user()?->can('tax_codes.edit') ?? false),
            ]);
    }
}
