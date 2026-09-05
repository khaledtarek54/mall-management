<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Models\Lease;
use App\Models\LeaseOption;
use App\Models\Unit;
use App\Services\ExerciseLeaseOptionService;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\TenureRange;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
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
use Illuminate\Support\HtmlString;

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
    use CountsItsRows;

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
                ->helperText(__('admin.lease_options.help.option_type'))
                ->options(collect(LeaseOption::TYPES)
                    ->mapWithKeys(fn (string $t) => [$t => __("admin.lease_options.types.{$t}")])->all())
                ->required()
                ->live()
                ->native(false),
            Select::make('status')
                ->label(__('admin.fields.status'))
                ->helperText(__('admin.lease_options.help.option_status'))
                ->options(collect(LeaseOption::STATUSES)
                    ->mapWithKeys(fn (string $s) => [$s => __("admin.lease_options.statuses.{$s}")])->all())
                ->default('open')
                ->required()
                ->native(false),

            // Both ends of the window. Serving notice too early is usually as invalid as too late,
            // and the scan alerts on each boundary separately.
            DatePicker::make('earliest_notice_date')
                ->label(__('admin.lease_options.earliest_notice_date'))
                ->helperText(__('admin.lease_options.help.earliest_notice_date'))
                ->native(false),
            DatePicker::make('latest_notice_date')
                ->label(__('admin.lease_options.latest_notice_date'))
                ->native(false)
                ->minDate(TenureRange::endsOnOrAfter('earliest_notice_date'))
                ->helperText(__('admin.helpers.lease_option_latest_notice'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.lease_option_latest_notice')),

            TextInput::make('term_months')
                ->label(__('admin.fields.term_months'))
                ->helperText(__('admin.lease_options.help.option_term_months'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(240)
                ->visible(fn (Get $get) => in_array($get('type'), ['renewal', 'expansion'], true)),
            Select::make('rent_basis')
                ->label(__('admin.lease_options.rent_basis'))
                ->helperText(__('admin.lease_options.help.rent_basis'))
                ->options(collect(LeaseOption::RENT_BASES)
                    ->mapWithKeys(fn (string $b) => [$b => __("admin.lease_options.rent_bases.{$b}")])->all())
                ->native(false)
                ->live()
                ->visible(fn (Get $get) => in_array($get('type'), ['renewal', 'expansion'], true)),
            TextInput::make('uplift_percent')
                ->label(__('admin.lease_options.rent_bases.uplift_percent'))
                ->helperText(__('admin.lease_options.help.uplift_percent'))
                ->numeric()
                ->suffix('%')
                ->minValue(0)
                ->maxValue(100)
                ->visible(fn (Get $get) => $get('rent_basis') === 'uplift_percent'),
            TextInput::make('fixed_rent')
                ->label(__('admin.lease_options.rent_bases.fixed'))
                ->helperText(__('admin.lease_options.help.fixed_rent'))
                ->numeric()
                ->prefix('EGP')
                ->minValue(0)
                ->visible(fn (Get $get) => $get('rent_basis') === 'fixed'),
            TextInput::make('penalty_amount')
                ->label(__('admin.lease_options.penalty_amount'))
                ->helperText(__('admin.lease_options.help.penalty_amount'))
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
                ->helperText(__('admin.lease_options.help.notice_given_at'))
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
                    ->label(__('admin.actions.add_option'))
                    ->modalHeading(__('admin.actions.add_option'))
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
                        // WHAT EXERCISING THIS ACTUALLY DOES, before the operator commits to it.
                        //
                        // The modal asked for a date, a reason and a document reference and said
                        // nothing about the outcome — on a decision that binds both parties for
                        // the option's whole term. Everything here is already derived by the
                        // service and the model; it was simply never shown.
                        //
                        // `projectedRent()` deliberately returns NULL for a market or CPI basis —
                        // neither is a number this system may invent — so the preview says the
                        // rent will be agreed rather than printing a figure nobody has set.
                        Placeholder::make('exercise_preview')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(function (LeaseOption $record, Get $get): HtmlString {
                                $lease = $record->lease;
                                $served = filled($get('notice_given_at'))
                                    ? CarbonImmutable::parse($get('notice_given_at'))
                                    : CarbonImmutable::now()->startOfDay();

                                $rows = [];

                                $rows[__('admin.lease_options.preview.window')] = $record->earliest_notice_date
                                    ? $record->earliest_notice_date->format('d/m/Y').' → '.($record->latest_notice_date?->format('d/m/Y') ?? '—')
                                    : '—';

                                // Said before the refusal, not after it: the operator can see the
                                // notice date is outside the window while they can still fix it.
                                $rows[__('admin.lease_options.preview.served')] = $served->format('d/m/Y')
                                    .($record->windowIsOpen($served) ? '' : ' ⚠️ '.__('admin.lease_options.preview.outside_window'));

                                if ($lease !== null) {
                                    $rows[__('admin.lease_options.preview.current_term')] =
                                        $lease->commencement_date?->format('d/m/Y').' → '.$lease->expiry_date?->format('d/m/Y');

                                    if ($record->type === 'renewal' || $record->type === 'expansion') {
                                        $from = filled($lease->expiry_date)
                                            ? CarbonImmutable::instance($lease->expiry_date)->addDay()
                                            : CarbonImmutable::now()->startOfDay();

                                        $rows[__('admin.lease_options.preview.new_term_starts')] = $from->format('d/m/Y')
                                            .($record->term_months ? ' · '.$record->term_months.' '.__('admin.lease_options.preview.months') : '');

                                        $projected = $record->projectedRent((float) $lease->base_rent_monthly);

                                        $rows[__('admin.lease_options.preview.rent')] = $projected !== null
                                            ? 'EGP '.number_format((float) $lease->base_rent_monthly, 2).' → '.number_format($projected, 2)
                                            : __('admin.lease_options.preview.rent_to_be_agreed', [
                                                'basis' => __('admin.enums.rent_basis.'.($record->rent_basis ?? 'market')),
                                            ]);
                                    }

                                    if ($record->penalty_amount !== null && (float) $record->penalty_amount > 0) {
                                        $rows[__('admin.lease_options.preview.penalty')] = 'EGP '.number_format((float) $record->penalty_amount, 2);
                                    }
                                }

                                $rows[__('admin.lease_options.preview.records')] = __('admin.lease_options.preview.records_value');

                                $html = '<dl class="grid gap-x-4 gap-y-1" style="grid-template-columns:auto 1fr">';
                                foreach ($rows as $label => $value) {
                                    $html .= '<dt class="text-sm opacity-70">'.e($label).'</dt>'
                                        .'<dd class="text-sm font-medium">'.e($value).'</dd>';
                                }

                                return new HtmlString($html.'</dl>');
                            }),
                        DatePicker::make('notice_given_at')
                            ->label(__('admin.lease_options.notice_given_at'))
                            ->live(onBlur: true)
                            ->helperText(__('admin.lease_options.notice_given_at_hint'))
                            ->default(fn (LeaseOption $record) => $record->notice_given_at ?? now())
                            ->required()
                            // THE WINDOW IS REFUSED ON THE FIELD, not in the action.
                            //
                            // A `catch` around the service call showed the refusal and then let the
                            // closure return normally, so Filament sent its success notification
                            // straight after it: the operator read "outside this option's window"
                            // and "Option marked exercised" together, and only the first was true.
                            // Reported from the panel.
                            //
                            // Validating here also keeps the modal OPEN with the date still in it,
                            // which a toast-and-close cannot: the operator fixes the day rather
                            // than re-typing the reason and the document reference. The service
                            // guard stays as the backstop — a disabled or absent field still
                            // arrives in the Livewire payload.
                            ->rules([
                                fn (LeaseOption $record): Closure => function (string $attribute, $value, Closure $fail) use ($record): void {
                                    if (blank($value) || $record->windowIsOpen(CarbonImmutable::parse($value)->startOfDay())) {
                                        return;
                                    }

                                    $fail(__('admin.errors.option_notice_outside_window', [
                                        'served' => CarbonImmutable::parse($value)->format('d/m/Y'),
                                        'from' => $record->earliest_notice_date?->format('d/m/Y') ?? '—',
                                        'to' => $record->latest_notice_date?->format('d/m/Y') ?? '—',
                                    ]));
                                },
                            ]),
                        Textarea::make('reason')
                            ->label(__('admin.actions.change_rent_reason'))
                            ->helperText(__('admin.lease_options.help.option_reason'))
                            ->rows(2),
                        TextInput::make('document_reference')
                            ->label(__('admin.lease_events.document'))
                            ->helperText(__('admin.lease_options.help.document_reference'))
                            ->maxLength(255),
                    ])
                    ->action(function (LeaseOption $record, array $data) {
                        // action() is the real gate — visible() is the UI.
                        abort_unless(self::canWrite(), 403);

                        // No try/catch. A DomainException is this codebase's refusal type and
                        // renders as its own message; catching one here and returning normally is
                        // what made the panel report a refusal and a success in the same breath.
                        app(ExerciseLeaseOptionService::class)->exercise($record, $data);
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
