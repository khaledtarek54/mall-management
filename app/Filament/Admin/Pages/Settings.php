<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\TaxCode;
use App\Services\GratuityService;
use App\Settings\TaxSettings;
use App\Support\DeletionPolicy;
use App\Support\DocumentNumbering;
use App\Support\FiscalYearStart;
use App\Support\Modules;
use App\Support\ProrationMethod;
use App\Support\SettingsRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Settings page — tabs for every settings group registered in config/settings.php.
 * Backed by Spatie's laravel-settings so values persist in the `settings` table
 * and can be edited at runtime without touching .env or restarting the app.
 *
 * Gated by the `settings.manage` permission (super_admin only by default;
 * custom roles can grant it).
 */
class Settings extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected string $view = 'filament.pages.settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.settings');
    }

    public function getTitle(): string
    {
        return __('admin.settings.page_title');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('settings.view') ?? false;
    }

    public function mount(): void
    {
        // Derived from the settings classes themselves — see App\Support\SettingsRegistry. This
        // used to be a hand-written map of every field, beside a second one in save() and a third
        // in the schema below. Three places, and the failure was silent in the worst direction: a
        // field missing from save() renders, accepts a value, says "Saved" and changes nothing.
        //
        // Filament treats dots in field names as nested-array paths, so the state is nested by
        // settings GROUP (data['billing']['late_fee_percent']) rather than flat dotted keys.
        $this->data = SettingsRegistry::currentState();

        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Tabs::make('settings_tabs')
                ->tabs([
                    Tab::make(__('admin.settings.tabs.modules'))->icon('heroicon-o-squares-plus')->schema($this->modulesFields()),
                    Tab::make(__('admin.settings.tabs.accounting'))->icon('heroicon-o-calculator')->schema($this->accountingFields()),
                    Tab::make(__('admin.settings.tabs.billing'))->icon('heroicon-o-banknotes')->schema($this->billingFields()),
                    Tab::make(__('admin.settings.tabs.sla'))->icon('heroicon-o-wrench-screwdriver')->schema($this->slaFields()),
                    Tab::make(__('admin.settings.tabs.tax'))->icon('heroicon-o-receipt-percent')->schema($this->taxFields()),
                    Tab::make(__('admin.settings.tabs.payroll'))->icon('heroicon-o-users')->schema($this->payrollFields()),
                    Tab::make(__('admin.settings.tabs.integrations'))->icon('heroicon-o-bolt')->schema($this->integrationsFields()),
                    Tab::make(__('admin.settings.tabs.housekeeping'))->icon('heroicon-o-trash')->schema($this->housekeepingFields()),
                ])
                ->columnSpanFull(),
        ]);
    }

    public function save(): void
    {
        if (! Auth::user()?->can('settings.manage')) {
            abort(403);
        }

        $state = $this->withoutModuleChanges($this->form->getState());

        // Moving the fiscal year start re-dates the PERIODS, so a document posted into an open one
        // can land inside a closed one — or an entry the accountant has closed and reported becomes
        // editable again. Refused rather than warned about: there is no safe migration of posted
        // history, and a warning an operator can click through is not a guard.
        FiscalYearStart::assertChangeable((int) ($state['accounting']['fiscal_year_start_month'] ?? 1));

        // Two document types sharing a prefix would interleave one sequence — no unique index
        // complains, because the index is per table, and a ledger simply reads as though documents
        // had gone missing.
        // Drop the blanks BEFORE validating or persisting. Each prefix field dehydrates to null
        // when left empty (meaning "keep the shipped letters"), so an untouched page would hand
        // over ['invoice' => null, ...] where the stored value is [] — a difference that is not a
        // change, and which made pressing Save on an untouched page write an audit entry every
        // time. Caught by the gate that asserts exactly that.
        $state['accounting']['document_prefixes'] = array_filter(
            (array) ($state['accounting']['document_prefixes'] ?? []),
            fn ($prefix) => filled($prefix),
        );

        DocumentNumbering::assertValid($state['accounting']['document_prefixes']);

        $changes = SettingsRegistry::persist($state);

        // Who moved the late-fee percent, when, and from what. `settings.manage` gates who MAY;
        // nothing recorded who DID, which in a system where money records are undeletable and the
        // charge-code catalogue is activity-logged left these numbers as the one place a figure
        // could change leaving no history. Logged only when something actually changed, so pressing
        // Save on an untouched page writes nothing.
        if ($changes !== []) {
            activity('settings')
                ->causedBy(Auth::user())
                ->withProperties(['changes' => $changes])
                ->log('settings.updated');
        }

        Notification::make()
            ->title(__('admin.settings.saved'))
            ->success()
            ->send();
    }

    /** @return array<int, mixed> */
    private function accountingFields(): array
    {
        return [
            Section::make(__('admin.settings.sections.fiscal_calendar'))
                ->description(__('admin.settings.sections.fiscal_calendar_description'))
                ->components([
                    Select::make('accounting.fiscal_year_start_month')
                        ->label(__('admin.settings.fields.fiscal_year_start_month'))
                        ->options(fn (): array => FiscalYearStart::options())
                        ->helperText(__('admin.settings.fields.fiscal_year_start_month_help'))
                        ->native(false)
                        ->required()
                        // Read-only once anything is posted. `disabled()` is the UI half; the
                        // refusal that matters is in save(), because a disabled Select is a
                        // rendering decision and not a guard.
                        ->disabled(fn (): bool => JournalEntry::query()->where('status', 'posted')->exists())
                        ->dehydrated(),
                ]),
            Section::make(__('admin.settings.sections.leasing_defaults'))
                ->description(__('admin.settings.sections.leasing_defaults_description'))
                ->components([
                    TextInput::make('accounting.default_lease_term_months')
                        ->label(__('admin.settings.fields.default_lease_term_months'))
                        ->numeric()->minValue(1)->maxValue(600)
                        ->suffix(__('admin.fields.months'))
                        ->required(),
                ]),
            Section::make(__('admin.settings.sections.records_retention'))
                ->description(__('admin.settings.sections.records_retention_description'))
                ->components([
                    TextInput::make('accounting.activity_log_retention_days')
                        ->label(__('admin.settings.fields.activity_log_retention_days'))
                        ->helperText(__('admin.settings.fields.activity_log_retention_days_help'))
                        ->numeric()
                        ->minValue(0)
                        // ~27 years. Not a real ceiling, a typo guard: the field is in DAYS and an
                        // operator thinking in years will eventually type one.
                        ->maxValue(10000)
                        ->suffix(__('admin.fields.days'))
                        ->required(),
                ]),
            Section::make(__('admin.settings.sections.document_numbering'))
                ->description(__('admin.settings.sections.document_numbering_description'))
                ->columns(3)
                ->components(array_merge(
                    // The reset scheme first: it shapes every number below it, so choosing a prefix
                    // before seeing what series it belongs to is the wrong order to read the screen in.
                    [Select::make('accounting.document_number_reset')
                        ->label(__('admin.settings.fields.document_number_reset'))
                        ->options(collect(DocumentNumbering::RESET_SCHEMES)
                            ->mapWithKeys(fn (string $scheme): array => [
                                $scheme => __("admin.settings.fields.document_number_reset_options.{$scheme}"),
                            ])->all())
                        ->helperText(__('admin.settings.fields.document_number_reset_help'))
                        ->native(false)
                        ->required()
                        ->columnSpanFull()],
                    collect(DocumentNumbering::TYPES)
                        ->map(fn (array $meta, string $type) => TextInput::make("accounting.document_prefixes.{$type}")
                            ->label(__("admin.document_types.{$type}"))
                            ->placeholder($meta['default'])
                            ->helperText(__('admin.settings.fields.document_prefix_help', ['default' => $meta['default']]))
                            ->maxLength(6)
                            // Uppercased on the way in, so `inv` and `INV` are not two series.
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? strtoupper(trim((string) $state)) : null))
                        ->values()
                        ->all(),
                )),
        ];
    }

    /** @return array<int, mixed> */
    private function billingFields(): array
    {
        return [
            // The accountant's switch (RA-02). Off until they rule on it — flipping it changes what
            // the P&L says about every stepped or abated lease, and changes NOTHING about what any
            // tenant is invoiced. Placed first because it is the highest-consequence toggle here.
            Section::make(__('admin.settings.sections.revenue_recognition'))
                ->description(__('admin.settings.sections.revenue_recognition_description'))
                ->components([
                    Toggle::make('billing.straight_line_rent_enabled')
                        ->label(__('admin.settings.fields.straight_line_rent_enabled'))
                        ->helperText(__('admin.settings.fields.straight_line_rent_enabled_help')),
                ]),
            // Policy that used to be a constant, and therefore a deploy.
            Section::make(__('admin.settings.sections.receivables_policy'))
                ->description(__('admin.settings.sections.receivables_policy_description'))
                ->columns(2)
                ->components([
                    TextInput::make('billing.default_payment_terms_days')
                        ->label(__('admin.settings.fields.default_payment_terms_days'))
                        ->helperText(__('admin.settings.fields.default_payment_terms_days_helper'))
                        ->suffix(__('admin.fields.days'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(365)
                        ->required(),
                    TagsInput::make('billing.ar_aging_bucket_days')
                        ->label(__('admin.settings.fields.ar_aging_bucket_days'))
                        ->helperText(__('admin.settings.fields.ar_aging_bucket_days_helper'))
                        ->placeholder('30')
                        ->nestedRecursiveRules(['integer', 'min:1', 'max:3650'])
                        ->reorderable(),
                ]),
            Section::make(__('admin.settings.sections.marketing_levy'))
                ->description(__('admin.settings.sections.marketing_levy_description'))
                ->components([
                    TextInput::make('marketing.levy_rate_percent')
                        ->label(__('admin.settings.fields.levy_rate_percent'))
                        ->helperText(__('admin.settings.fields.levy_rate_percent_helper'))
                        ->suffix('%')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->required(),
                ]),
            Section::make(__('admin.settings.sections.late_fees'))
                ->description(__('admin.settings.sections.late_fees_description'))
                ->columns(3)
                ->components([
                    TextInput::make('billing.late_fee_percent')
                        ->label(__('admin.settings.fields.late_fee_percent'))
                        ->numeric()
                        ->suffix('%')
                        ->minValue(0)
                        ->maxValue(100)
                        ->required(),
                    TextInput::make('billing.late_fee_grace_days')
                        ->label(__('admin.settings.fields.late_fee_grace_days'))
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('billing.late_fee_minimum')
                        ->label(__('admin.settings.fields.late_fee_minimum'))
                        ->numeric()
                        ->prefix('EGP')
                        ->minValue(0)
                        ->required(),
                    // The other half of the clause. 0 = no ceiling, which is what every install had
                    // before this existed — so an unset value must keep meaning exactly that.
                    Select::make('billing.proration_method')
                        ->label(__('admin.settings.fields.proration_method'))
                        ->helperText(__('admin.settings.fields.proration_method_helper'))
                        ->options(fn (): array => collect(ProrationMethod::METHODS)
                            ->mapWithKeys(fn (string $m): array => [$m => __("admin.proration_methods.{$m}")])
                            ->all())
                        ->native(false)
                        ->required(),
                    TextInput::make('billing.late_fee_maximum')
                        ->label(__('admin.settings.fields.late_fee_maximum'))
                        ->helperText(__('admin.settings.fields.late_fee_maximum_helper'))
                        ->numeric()
                        ->prefix('EGP')
                        ->minValue(0)
                        ->required(),
                    // 0 = charge once, and that is how it ships. Whether a penalty recurs at all is
                    // the sharpest term in a late-fee clause, so it is opt-in per lease.
                    TextInput::make('billing.late_fee_recurrence_days')
                        ->label(__('admin.settings.fields.late_fee_recurrence_days'))
                        ->helperText(__('admin.settings.fields.late_fee_recurrence_days_helper'))
                        ->suffix(__('admin.fields.days'))
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    // 1A-16. 0 = chase once, which is what every install has done since the tenant
                    // reminder existed — so no tenant receives a message on deploy they would not
                    // have received yesterday. How hard you chase is a commercial judgement about
                    // tenants you have to keep, which is why it is off until someone rules on it.
                    TextInput::make('billing.dunning_followup_days')
                        ->label(__('admin.settings.fields.dunning_followup_days'))
                        ->helperText(__('admin.settings.fields.dunning_followup_days_helper'))
                        ->suffix(__('admin.fields.days'))
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('billing.dunning_max_notices')
                        ->label(__('admin.settings.fields.dunning_max_notices'))
                        ->helperText(__('admin.settings.fields.dunning_max_notices_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('billing.default_security_deposit_months')
                        ->label(__('admin.settings.fields.default_security_deposit_months'))
                        ->helperText(__('admin.settings.fields.default_security_deposit_months_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    // 0 = off, and that is how it ships. The action that charges it stays hidden
                    // until a figure is set, so nothing appears on an invoice by surprise.
                    TextInput::make('billing.nsf_fee_amount')
                        ->label(__('admin.settings.fields.nsf_fee_amount'))
                        ->helperText(__('admin.settings.fields.nsf_fee_amount_helper'))
                        ->numeric()
                        ->prefix('EGP')
                        ->minValue(0)
                        ->required(),
                ]),
            Section::make(__('admin.settings.sections.credit_and_holdover'))
                ->description(__('admin.settings.sections.credit_and_holdover_description'))
                ->columns(2)
                ->components([
                    Toggle::make('billing.auto_apply_tenant_credit')
                        ->label(__('admin.settings.fields.auto_apply_tenant_credit'))
                        ->helperText(__('admin.settings.fields.auto_apply_tenant_credit_helper')),
                    TextInput::make('billing.holdover_default_rate_pct')
                        ->label(__('admin.settings.fields.holdover_default_rate_pct'))
                        ->helperText(__('admin.settings.fields.holdover_default_rate_pct_helper'))
                        ->suffix('%')
                        ->numeric()
                        // This box is the ONLY thing the Convert-to-holdover modal prefills itself
                        // from, so a default below that modal's own floor is a value the operator is
                        // refused for the moment they use it — on a field they never touched. One
                        // number, read by the modal, by the service and by this: measured 2026-09-03,
                        // it was 100 / "greater than zero" / 0 in the three places.
                        ->minValue(Lease::HOLDOVER_MIN_RATE_PCT)
                        ->required(),
                ]),
            Section::make(__('admin.settings.sections.schedules'))
                ->description(__('admin.settings.sections.schedules_description'))
                ->columns(2)
                ->components([
                    TextInput::make('billing.monthly_billing_day')
                        ->label(__('admin.settings.fields.monthly_billing_day'))
                        ->numeric()
                        ->minValue(1)
                        // 31, not 28. The cap existed because a global `->monthlyOn(29)` simply does
                        // not fire in February — but the run is daily now and `BillingDay` clamps a
                        // 29/30/31 to the month's last day, so "bill at month end" is expressible.
                        // Leaving the cap here while the per-property override accepted 31 would
                        // have been two different answers to one question.
                        ->maxValue(31)
                        ->integer()
                        ->required()
                        ->helperText(__('admin.settings.fields.monthly_billing_day_helper')),
                    // A TIME, so it is picked rather than typed — the calendar pair below has
                    // always been a TimePicker and these two were free text with a placeholder.
                    // What a malformed value costs is not this job: `->dailyAt('24:00')` builds an
                    // invalid cron expression, and `Schedule::dueEvents()` evaluates every event in
                    // one eager filter with no try/catch, so `schedule:run` aborts before ANY task
                    // runs. `ScheduleSetting::billingTime()` is the layer that actually holds — a row
                    // can also arrive from a seeder, an import or a restored backup — and this stops
                    // the panel offering the mistake.
                    TimePicker::make('billing.monthly_billing_time')
                        ->label(__('admin.settings.fields.monthly_billing_time'))
                        ->seconds(false)
                        ->required(),
                    TextInput::make('billing.cam_reconciliation_month')
                        ->label(__('admin.settings.fields.cam_reconciliation_month'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(12)
                        ->required(),
                    TextInput::make('billing.cam_reconciliation_day')
                        ->label(__('admin.settings.fields.cam_reconciliation_day'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(31)
                        ->required(),
                    TimePicker::make('billing.cam_reconciliation_time')
                        ->label(__('admin.settings.fields.cam_reconciliation_time'))
                        // `->seconds(false)` is not decoration: without it the picker dehydrates
                        // `03:00:00`, which is a CHANGE on every save of an untouched form — and the
                        // settings audit trail said so. Caught by SettingsPageConformanceTest's
                        // "writes no audit entry when nothing changed", which is exactly the row an
                        // audit-everything trail exists to keep meaningful.
                        ->seconds(false)
                        ->required(),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private function slaFields(): array
    {
        return [
            Section::make(__('admin.settings.sections.working_calendar'))
                ->description(__('admin.settings.sections.working_calendar_description'))
                ->columns(2)
                ->components([
                    CheckboxList::make('calendar.working_days')
                        ->label(__('admin.settings.fields.working_days'))
                        ->helperText(__('admin.settings.fields.working_days_helper'))
                        ->options(fn (): array => __('admin.settings.weekdays'))
                        ->columns(2)
                        ->columnSpanFull(),
                    TimePicker::make('calendar.day_opens_at')
                        ->label(__('admin.settings.fields.day_opens_at'))
                        ->seconds(false),
                    TimePicker::make('calendar.day_closes_at')
                        ->label(__('admin.settings.fields.day_closes_at'))
                        ->seconds(false)
                        // Without this, 17:00–09:00 makes every day unworked: the calendar silently
                        // dies, every deadline falls through to the plain-hours fallback, and the
                        // screen says nothing. `WorkingCalendar` clamps back to the shipped window
                        // as a backstop, but the operator should be told at the point of typing.
                        ->after('calendar.day_opens_at'),
                    // The ruling this whole feature waits on. Empty = every clock stays on the
                    // calendar, which is what the system did before the working week existed.
                    CheckboxList::make('sla.sla_working_clock_priorities')
                        ->label(__('admin.settings.fields.sla_working_clock_priorities'))
                        ->helperText(__('admin.settings.fields.sla_working_clock_priorities_helper'))
                        ->options(fn (): array => __('admin.facility.priorities'))
                        ->columns(4)
                        ->columnSpanFull(),
                ]),
            Section::make(__('admin.settings.sections.sla'))
                ->description(__('admin.settings.sections.sla_description'))
                ->columns(2)
                ->components([
                    // `hrs` was a hardcoded English abbreviation on all eight of these boxes — an
                    // English word inside the Arabic form, invisible to the Arabic-chrome gate,
                    // which sweeps labels and never affixes.
                    TextInput::make('sla.sla_urgent_hours')->label(__('admin.settings.fields.sla_urgent_hours'))->numeric()->minValue(1)->suffix(__('admin.fields.hrs'))->required(),
                    TextInput::make('sla.sla_high_hours')->label(__('admin.settings.fields.sla_high_hours'))->numeric()->minValue(1)->suffix(__('admin.fields.hrs'))->required(),
                    TextInput::make('sla.sla_medium_hours')->label(__('admin.settings.fields.sla_medium_hours'))->numeric()->minValue(1)->suffix(__('admin.fields.hrs'))->required(),
                    TextInput::make('sla.sla_low_hours')->label(__('admin.settings.fields.sla_low_hours'))->numeric()->minValue(1)->suffix(__('admin.fields.hrs'))->required(),
                    // The second clock. Resolution runs from ACCEPTANCE (FR-CM-07) so an engineer
                    // is not charged for queue time; response runs from creation, so queue time is
                    // charged to somebody. Without it, never accepting a job meant no deadline.
                    TextInput::make('sla.sla_urgent_respond_hours')->label(__('admin.settings.fields.sla_urgent_respond_hours'))->numeric()->minValue(1)->suffix(__('admin.fields.hrs'))->required(),
                    TextInput::make('sla.sla_high_respond_hours')->label(__('admin.settings.fields.sla_high_respond_hours'))->numeric()->minValue(1)->suffix(__('admin.fields.hrs'))->required(),
                    TextInput::make('sla.sla_medium_respond_hours')->label(__('admin.settings.fields.sla_medium_respond_hours'))->numeric()->minValue(1)->suffix(__('admin.fields.hrs'))->required(),
                    TextInput::make('sla.sla_low_respond_hours')->label(__('admin.settings.fields.sla_low_respond_hours'))->numeric()->minValue(1)->suffix(__('admin.fields.hrs'))->required(),
                    // Ships OFF. Switching it on refuses the next completion every engineer
                    // attempts, on jobs they have already finished — so the helper text says what
                    // it does before the toggle does it.
                    Toggle::make('sla.require_completion_evidence')
                        ->label(__('admin.settings.fields.require_completion_evidence'))
                        ->helperText(__('admin.settings.fields.require_completion_evidence_helper'))
                        ->columnSpanFull(),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private function taxFields(): array
    {
        return [
            // The particulars a document titled "Tax Invoice" must carry. Placed FIRST because an
            // unset TRN silently strips a required line off every invoice already issued, which is
            // the kind of omission nobody notices until a tenant's auditor does.
            Section::make(__('admin.settings.sections.seller_identity'))
                ->description(__('admin.settings.sections.seller_identity_description'))
                ->columns(2)
                ->components([
                    TextInput::make('tax.seller_tax_registration_number')
                        ->label(__('admin.settings.fields.seller_trn'))
                        ->helperText(__('admin.settings.fields.seller_trn_helper'))
                        ->maxLength(50),
                    TextInput::make('tax.seller_legal_name')
                        ->label(__('admin.settings.fields.seller_legal_name'))
                        ->helperText(__('admin.settings.fields.seller_legal_name_helper'))
                        ->maxLength(255),
                    TextInput::make('tax.seller_billing_email')
                        ->label(__('admin.settings.fields.seller_billing_email'))
                        ->helperText(__('admin.settings.fields.seller_billing_email_helper'))
                        ->email()
                        ->maxLength(255),
                ]),
            Section::make(__('admin.settings.sections.vat'))
                ->description(__('admin.settings.sections.vat_description'))
                ->columns(2)
                ->components([
                    // NEITHER the rate nor which supplies it applies to is set here any more, and
                    // this pointer is the whole section. An operator who comes to Settings looking
                    // for the VAT rate — because that is where it lived until 2026-08-12 — has to
                    // land somewhere better than an absence.
                    //
                    // The rate is a dated rung on the VAT_STD tax code, because a rate has a day it
                    // came into force and a settings field cannot carry one. WHICH supplies are
                    // taxable is a column on each charge code, so parking, the marketing levy and
                    // any code the accountant adds are ruled on in one place. A per-supply toggle
                    // lived on this screen until 2026-08-11 and was a second answer to the same
                    // question.
                    Placeholder::make('tax.rates_moved')
                        ->label(__('admin.settings.fields.vat_rates_moved'))
                        ->content(__('admin.settings.fields.vat_rates_moved_helper'))
                        ->columnSpanFull(),
                ]),
            Section::make(__('admin.settings.sections.wht'))
                ->description(__('admin.settings.sections.wht_description'))
                ->columns(2)
                ->components([
                    Toggle::make('tax.wht_enabled')
                        ->label(__('admin.settings.fields.wht_enabled'))
                        ->helperText(__('admin.settings.fields.wht_enabled_helper'))
                        ->live(),
                    // A CODE, not a percentage. Which nature we assume is policy — what settings
                    // are for; the rate that nature carries belongs in the catalogue with every
                    // other rate. Not `required()`: there is no defensible default nature, and an
                    // empty one withholds nothing, which is the safe direction.
                    Select::make('tax.wht_default_tax_code')
                        ->label(__('admin.settings.fields.wht_default_tax_code'))
                        ->helperText(__('admin.settings.fields.wht_default_tax_code_helper'))
                        // `keep:` is what stops an operator retiring this code at /admin/tax-codes
                        // and bricking the WHOLE screen. Measured 2026-09-03: with the code gone
                        // from the options the Select emits `in:` — an empty list — and because all
                        // eight tabs are one schema, `save()` validating the form refused every
                        // setting in the app on a field nobody had touched.
                        //
                        // The PERSISTED value, never `$this->data`: that is client state, and
                        // appending it would make any string a valid option.
                        ->options(fn () => TaxCode::options(
                            TaxCode::PURCHASES,
                            families: [TaxCode::FAMILY_WITHHOLDING],
                            keep: app(TaxSettings::class)->wht_default_tax_code ?: null,
                        ))
                        ->native(false)
                        ->placeholder(__('admin.settings.fields.wht_default_none')),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private function payrollFields(): array
    {
        return [
            // The three statutory rates left this tab on 2026-08-22 (EG-03). They were undated, so a
            // January run generated in March computed on March's numbers and a rise could not be
            // entered in advance — and Egypt moves them every January. They are dated rungs now, at
            // /admin/payroll-rates, which also carries the insurable-wage band a flat rate could
            // never express. Settings hold policy; master data holds rates.
            Section::make(__('admin.settings.sections.payroll_deductions'))
                ->description(__('admin.settings.sections.payroll_deductions_description'))
                ->components([
                    // Same shape, and the same reason, as `tax.rates_moved` above.
                    Placeholder::make('payroll.rates_moved')
                        ->label(__('admin.settings.fields.payroll_rates_moved'))
                        ->content(__('admin.settings.fields.payroll_rates_moved_helper'))
                        ->columnSpanFull(),
                ]),

            // End-of-service gratuity. OFF by default and deliberately so: Labour Law 12/2003
            // Art. 122 applies to workers NOT covered by the social insurance law, and in Egypt
            // most are — so accruing a provision nobody owes overstates the liability exactly as
            // surely as omitting a real one understates it. The live exposure figure is shown
            // beside the toggle so the decision is made against a number rather than a feeling.
            Section::make(__('admin.settings.sections.payroll_gratuity'))
                ->description(__('admin.settings.sections.payroll_gratuity_description'))
                ->columns(2)
                ->schema([
                    Toggle::make('payroll.gratuity_enabled')
                        ->label(__('admin.settings.fields.payroll_gratuity_enabled'))
                        ->helperText(__('admin.settings.fields.payroll_gratuity_enabled_helper'))
                        ->columnSpanFull(),
                    TextInput::make('payroll.gratuity_days_first_five')
                        ->label(__('admin.settings.fields.payroll_gratuity_days_first_five'))
                        ->suffix(__('admin.settings.fields.payroll_gratuity_days_suffix'))
                        ->numeric()->minValue(0)->maxValue(365)->required(),
                    TextInput::make('payroll.gratuity_days_thereafter')
                        ->label(__('admin.settings.fields.payroll_gratuity_days_thereafter'))
                        ->suffix(__('admin.settings.fields.payroll_gratuity_days_suffix'))
                        ->numeric()->minValue(0)->maxValue(365)->required(),
                    Placeholder::make('gratuity_exposure')
                        ->label(__('admin.settings.fields.payroll_gratuity_exposure'))
                        ->columnSpanFull()
                        ->content(function (): string {
                            $exposure = app(GratuityService::class)->exposure();

                            return __('admin.settings.fields.payroll_gratuity_exposure_value', [
                                'amount' => 'EGP '.number_format($exposure['total'], 2),
                                'headcount' => $exposure['headcount'],
                            ]);
                        }),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private function modulesFields(): array
    {
        $mayToggle = self::mayToggleModules();

        $sections = [
            // Said once, at the top, in the operator's own language: this tab is the platform
            // owner's. A control an operator can see, can move, and that then silently does not
            // save is worse than a disabled one — so the switches below are disabled for everyone
            // else, and save() refuses the payload regardless (see assertMayToggleModules()).
            Placeholder::make('modules_locked_notice')
                ->hiddenLabel()
                ->visible(! $mayToggle)
                ->content(fn (): string => __('admin.settings.sections.modules_locked'))
                ->columnSpanFull(),
        ];

        // Grouped, never one flat wall of switches. `Modules::GROUPS` orders them the way
        // App\Support\Navigation orders the sidebar, so "where do I switch this off" has the same
        // answer as "where do I find it".
        foreach (array_keys(Modules::GROUPS) as $group) {
            // `toggleableIn()`, never the raw group: a FROZEN module (App\Support\Modules::FROZEN)
            // answers false whatever the row says, so rendering its switch would offer the operator
            // a control that cannot do anything — and, worse, would advertise unfinished work as a
            // feature they are choosing to leave off.
            $keys = Modules::toggleableIn($group);

            if ($keys === []) {
                continue;
            }

            $sections[] = Section::make(__("admin.groups.{$group}"))
                ->columns(2)
                ->collapsible()
                ->components(array_map(
                    fn (string $key) => Toggle::make("modules.{$key}")
                        ->label(__("admin.permission_modules.{$key}"))
                        ->helperText(__("admin.settings.modules.{$key}"))
                        // The UI half. `dehydrated()` keeps the current value in the payload so a
                        // save by anyone else round-trips the module state unchanged instead of
                        // handing SettingsRegistry a missing key.
                        ->disabled(! $mayToggle)
                        ->dehydrated(),
                    $keys,
                ));
        }

        return [
            Section::make(__('admin.settings.sections.modules'))
                ->description(__('admin.settings.sections.modules_description'))
                ->components($sections),
        ];
    }

    /**
     * Switching a module on or off is the platform owner's act, and nobody else's.
     *
     * **Deliberately a ROLE check, not a permission.** `settings.manage` is grantable — a
     * super_admin can hand it to a custom role, and `roles.edit` can hand `settings.manage` to
     * itself — so gating the module switches on it would mean the answer to "who may turn Payroll
     * off for the whole portfolio" is whatever the roles matrix happens to say this week. The same
     * reasoning that puts deletion behind `hasRole('super_admin')` in
     * {@see DeletionPolicy} rather than behind a `{module}.delete` permission: a
     * decision that reaches every property and every user is not a CRUD right.
     *
     * Everything ELSE on this screen stays on `settings.manage`. A finance lead moving the late-fee
     * percent is ordinary configuration; a mall admin removing Owner Statements from the system is
     * not.
     */
    public static function mayToggleModules(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /**
     * Drop every `modules.*` key from a submitted state unless the actor may set them.
     *
     * The disabled switches above are a RENDERING decision and not a guard — this codebase has the
     * rule written down in three other places for the same reason: a disabled input's value still
     * arrives in the Livewire payload, and `modules` is a plain array in `$this->data` that a
     * crafted `$set` reaches directly. Stripping rather than refusing is deliberate: a manager
     * pressing Save on the Billing tab has a full `modules` array in their form state through no
     * act of their own, and 403-ing an honest save would make the whole screen unusable for them.
     * {@see SettingsRegistry::persist()} leaves an absent key alone, so removing the
     * group here is exactly "change nothing about the modules".
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function withoutModuleChanges(array $state): array
    {
        if (self::mayToggleModules()) {
            return $state;
        }

        unset($state['modules']);

        return $state;
    }

    /** @return array<int, mixed> */
    /**
     * How long the system keeps what it generates (D2-09).
     *
     * A tab of its own rather than more rows under Accounting's "records retention", where
     * `activity_log_retention_days` lives: that period answers a STATUTORY question (Egyptian book
     * retention, and the audit trail must outlive the books it describes). These five answer an
     * operational one, and folding them together would invite an operator to set the audit trail by
     * the same reasoning they set a failed-jobs table.
     *
     * Every field carries the same 0-means-keep-everything note, because the convention is only
     * useful if it is visible at the field rather than in a docblock.
     */
    private function housekeepingFields(): array
    {
        $days = fn (string $key) => TextInput::make("housekeeping.{$key}")
            ->label(__("admin.settings.fields.{$key}"))
            ->helperText(__("admin.settings.fields.{$key}_help"))
            ->numeric()
            ->minValue(0)
            // The same typo guard the audit-trail field carries: the unit is DAYS and an operator
            // thinking in years will eventually type one.
            ->maxValue(10000)
            ->suffix(__('admin.fields.days'))
            ->required();

        return [
            Section::make(__('admin.settings.sections.housekeeping'))
                ->description(__('admin.settings.sections.housekeeping_description'))
                ->columns(2)
                ->components([
                    $days('notification_retention_days'),
                    $days('export_retention_days'),
                    $days('import_retention_days'),
                    $days('failed_job_retention_days'),
                    $days('expired_token_grace_days'),
                ]),
        ];
    }

    private function integrationsFields(): array
    {
        return [
            Section::make(__('admin.settings.sections.payments'))
                ->columns(1)
                ->components([
                    Toggle::make('integrations.paymob_enabled')->label(__('admin.settings.fields.paymob_enabled'))->helperText(__('admin.settings.fields.paymob_enabled_helper')),
                ]),
            Section::make(__('admin.settings.sections.email'))
                ->columns(1)
                ->components([
                    Toggle::make('integrations.mail_enabled')->label(__('admin.settings.fields.mail_enabled'))->helperText(__('admin.settings.fields.mail_enabled_helper')),
                ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            Action::make('save')
                ->label(__('admin.settings.save'))
                ->icon('heroicon-o-check')
                ->color('primary')
                ->visible(fn () => Auth::user()?->can('settings.manage') ?? false)
                ->action('save'),
        ];
    }
}
