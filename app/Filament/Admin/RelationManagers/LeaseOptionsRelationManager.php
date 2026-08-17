<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\Lease;
use App\Models\LeaseOption;
use App\Models\Unit;
use App\Services\ExerciseLeaseOptionService;
use App\Support\Filament\EntitySelect;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Options & critical dates on a lease (OP-01/OP-02).
 *
 * A commercial lease is a bundle of options, and options are money. Atriom recorded none of them:
 * a renewal right at a contracted uplift existed only inside the uploaded PDF, so nothing could
 * alert on it and nothing could report it. The daily `leases:scan-option-windows` reads exactly
 * these rows — **an option that is not recorded here is an option nothing will ever remind anyone
 * about.**
 *
 * Write access rides on `leases.edit`, gated in BOTH visible() and the action closure per the
 * project's authz invariant. Delete is available because an option typed in error is data entry,
 * not history — an option that genuinely existed is RESOLVED (exercised / waived / lapsed), which
 * is what keeps the record.
 */
class LeaseOptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'options';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.lease_options.title');
    }

    private static function canWrite(): bool
    {
        return (bool) Auth::user()?->can('leases.edit');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')
                ->label(__('admin.fields.type'))
                ->options(collect(LeaseOption::TYPES)
                    ->mapWithKeys(fn (string $t) => [$t => __("admin.lease_options.types.{$t}")])->all())
                ->required()
                ->live()
                ->native(false),
            Select::make('status')
                ->label(__('admin.fields.status'))
                ->options(collect(LeaseOption::STATUSES)
                    ->mapWithKeys(fn (string $s) => [$s => __("admin.lease_options.statuses.{$s}")])->all())
                ->default('open')
                ->required()
                ->native(false),

            // Both ends of the window. Serving notice too early is usually as invalid as too late,
            // and the scan alerts on each boundary separately.
            DatePicker::make('earliest_notice_date')
                ->label(__('admin.lease_options.earliest_notice_date'))
                ->native(false),
            DatePicker::make('latest_notice_date')
                ->label(__('admin.lease_options.latest_notice_date'))
                ->native(false)
                ->afterOrEqual('earliest_notice_date')
                ->helperText(__('admin.helpers.lease_option_latest_notice'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.lease_option_latest_notice')),

            TextInput::make('term_months')
                ->label(__('admin.fields.term_months'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(240)
                ->visible(fn (Get $get) => in_array($get('type'), ['renewal', 'expansion'], true)),
            Select::make('rent_basis')
                ->label(__('admin.lease_options.rent_basis'))
                ->options(collect(LeaseOption::RENT_BASES)
                    ->mapWithKeys(fn (string $b) => [$b => __("admin.lease_options.rent_bases.{$b}")])->all())
                ->native(false)
                ->live()
                ->visible(fn (Get $get) => in_array($get('type'), ['renewal', 'expansion'], true)),
            TextInput::make('uplift_percent')
                ->label(__('admin.lease_options.rent_bases.uplift_percent'))
                ->numeric()
                ->suffix('%')
                ->minValue(0)
                ->maxValue(100)
                ->visible(fn (Get $get) => $get('rent_basis') === 'uplift_percent'),
            TextInput::make('fixed_rent')
                ->label(__('admin.lease_options.rent_bases.fixed'))
                ->numeric()
                ->prefix('EGP')
                ->minValue(0)
                ->visible(fn (Get $get) => $get('rent_basis') === 'fixed'),
            TextInput::make('penalty_amount')
                ->label(__('admin.lease_options.penalty_amount'))
                ->numeric()
                ->prefix('EGP')
                ->minValue(0)
                ->visible(fn (Get $get) => $get('type') === 'termination'),

            // The space an expansion/first-refusal right ties up. Property-scoped: a lease in one
            // mall must not be able to encumber a unit in another.
            EntitySelect::make('unit_id')
                ->label(__('admin.lease_options.encumbers'))
                ->entity(Unit::class)
                ->visible(fn (Get $get) => in_array($get('type'), LeaseOption::ENCUMBERING_TYPES, true))
                ->helperText(__('admin.helpers.lease_option_encumbers'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.lease_option_encumbers')),

            DatePicker::make('notice_given_at')
                ->label(__('admin.fields.notice_given_at'))
                ->native(false),
            Textarea::make('notes')
                ->label(__('admin.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->searchable(false)
            // Soonest deadline first — this table is a work-list, and the row that bites next
            // belongs at the top.
            ->defaultSort('latest_notice_date', 'asc')
            ->columns([
                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => __("admin.lease_options.types.{$state}")),
                TextColumn::make('window')
                    ->label(__('admin.lease_options.window'))
                    ->state(fn (LeaseOption $record): string => trim(
                        ($record->earliest_notice_date?->format('d/m/Y') ?? '—')
                        .' → '.($record->latest_notice_date?->format('d/m/Y') ?? '—')
                    )),
                TextColumn::make('days_left')
                    ->label(__('admin.lease_options.days_left'))
                    ->alignEnd()
                    ->badge()
                    // The number the whole feature exists for. Colour is urgency, not decoration.
                    ->state(function (LeaseOption $record): string {
                        $days = $record->daysUntilClose();

                        if ($days === null) {
                            return __('admin.lease_options.no_deadline');
                        }

                        return $days < 0 ? __('admin.lease_options.window_closed') : (string) $days;
                    })
                    ->color(function (LeaseOption $record): string {
                        $days = $record->daysUntilClose();

                        return match (true) {
                            ! $record->isOpen() => 'gray',
                            $days === null => 'gray',
                            $days < 0 => 'danger',
                            $days <= 30 => 'warning',
                            default => 'success',
                        };
                    }),
                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => __("admin.lease_options.statuses.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'open' => 'info',
                        'exercised' => 'success',
                        'lapsed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('projected_rent')
                    ->label(__('admin.lease_options.projected_rent'))
                    ->alignEnd()
                    // Only when it is knowable without a valuation: a market review or a CPI clause
                    // is not a number this system may invent.
                    ->state(function (LeaseOption $record): ?string {
                        $rent = $record->projectedRent((float) ($record->lease?->base_rent_monthly ?? 0));

                        return $rent === null ? null : 'EGP '.number_format($rent, 2);
                    })
                    ->placeholder('—'),
                TextColumn::make('unit.code')
                    ->label(__('admin.lease_options.encumbers'))
                    ->badge()
                    ->color('warning')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => self::canWrite())
                    ->authorize(fn () => self::canWrite()),
            ])
            ->recordActions([
                Action::make('exercise')
                    ->label(__('admin.lease_options.exercise'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (LeaseOption $record) => self::canWrite() && $record->isOpen())
                    ->schema([
                        DatePicker::make('notice_given_at')
                            ->label(__('admin.lease_options.notice_given_at'))
                            ->helperText(__('admin.lease_options.notice_given_at_hint'))
                            ->default(fn (LeaseOption $record) => $record->notice_given_at ?? now())
                            ->required(),
                        Textarea::make('reason')
                            ->label(__('admin.actions.change_rent_reason'))
                            ->rows(2),
                        TextInput::make('document_reference')
                            ->label(__('admin.lease_events.document'))
                            ->maxLength(255),
                    ])
                    ->action(function (LeaseOption $record, array $data) {
                        // action() is the real gate — visible() is the UI.
                        abort_unless(self::canWrite(), 403);

                        try {
                            app(ExerciseLeaseOptionService::class)->exercise($record, $data);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    })
                    ->successNotificationTitle(__('admin.lease_options.exercised_notice')),
                Action::make('waive')
                    ->label(__('admin.lease_options.waive'))
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (LeaseOption $record) => self::canWrite() && $record->isOpen())
                    ->action(function (LeaseOption $record) {
                        abort_unless(self::canWrite(), 403);

                        app(ExerciseLeaseOptionService::class)->resolveWithout($record, 'waived');
                    })
                    ->successNotificationTitle(__('admin.lease_options.waived_notice')),
                EditAction::make()
                    ->visible(fn () => self::canWrite())
                    ->authorize(fn () => self::canWrite()),
                DeleteAction::make()
                    ->visible(fn () => self::canWrite())
                    ->authorize(fn () => self::canWrite()),
            ])
            ->emptyStateHeading(__('admin.lease_options.empty'))
            ->emptyStateDescription(__('admin.lease_options.empty_description'));
    }
}
