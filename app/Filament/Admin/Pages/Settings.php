<?php

namespace App\Filament\Admin\Pages;

use App\Settings\BillingSettings;
use App\Settings\EtaSettings;
use App\Settings\IntegrationsSettings;
use App\Settings\MaintenanceSettings;
use App\Settings\ModulesSettings;
use App\Support\Modules;
use BackedEnum;
use Filament\Forms\Components\TextInput;
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

    protected static ?int $navigationSort = 95;

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

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.settings');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('settings.view') ?? false;
    }

    public function mount(): void
    {
        $billing = app(BillingSettings::class);
        $maint = app(MaintenanceSettings::class);
        $integ = app(IntegrationsSettings::class);
        $eta = app(EtaSettings::class);
        $mods = app(ModulesSettings::class);

        $this->data = [
            'billing.late_fee_percent' => $billing->late_fee_percent,
            'billing.late_fee_grace_days' => $billing->late_fee_grace_days,
            'billing.late_fee_minimum' => $billing->late_fee_minimum,
            'billing.monthly_billing_day' => $billing->monthly_billing_day,
            'billing.monthly_billing_time' => $billing->monthly_billing_time,
            'billing.cam_reconciliation_month' => $billing->cam_reconciliation_month,
            'billing.cam_reconciliation_day' => $billing->cam_reconciliation_day,
            'billing.cam_reconciliation_time' => $billing->cam_reconciliation_time,

            'maintenance.sla_urgent_hours' => $maint->sla_urgent_hours,
            'maintenance.sla_high_hours' => $maint->sla_high_hours,
            'maintenance.sla_medium_hours' => $maint->sla_medium_hours,
            'maintenance.sla_low_hours' => $maint->sla_low_hours,

            'integrations.paymob_enabled' => $integ->paymob_enabled,
            'integrations.whatsapp_enabled' => $integ->whatsapp_enabled,

            'eta.enabled' => $eta->enabled,
            'eta.mock' => $eta->mock,
            'eta.issuer_name' => $eta->issuer_name,
            'eta.issuer_tax_registration_number' => $eta->issuer_tax_registration_number,
        ];

        foreach (Modules::KEYS as $key) {
            $this->data["modules.{$key}"] = (bool) ($mods->{$key} ?? true);
        }

        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Tabs::make('settings_tabs')
                ->tabs([
                    Tab::make(__('admin.settings.tabs.modules'))->icon('heroicon-o-squares-plus')->schema($this->modulesFields()),
                    Tab::make(__('admin.settings.tabs.billing'))->icon('heroicon-o-banknotes')->schema($this->billingFields()),
                    Tab::make(__('admin.settings.tabs.maintenance'))->icon('heroicon-o-wrench-screwdriver')->schema($this->maintenanceFields()),
                    Tab::make(__('admin.settings.tabs.eta'))->icon('heroicon-o-document-text')->schema($this->etaFields()),
                    Tab::make(__('admin.settings.tabs.integrations'))->icon('heroicon-o-bolt')->schema($this->integrationsFields()),
                ])
                ->columnSpanFull(),
        ]);
    }

    public function save(): void
    {
        if (! Auth::user()?->can('settings.manage')) {
            abort(403);
        }

        $state = $this->form->getState();

        $billing = app(BillingSettings::class);
        $billing->late_fee_percent = (float) $state['billing.late_fee_percent'];
        $billing->late_fee_grace_days = (int) $state['billing.late_fee_grace_days'];
        $billing->late_fee_minimum = (float) $state['billing.late_fee_minimum'];
        $billing->monthly_billing_day = (int) $state['billing.monthly_billing_day'];
        $billing->monthly_billing_time = (string) $state['billing.monthly_billing_time'];
        $billing->cam_reconciliation_month = (int) $state['billing.cam_reconciliation_month'];
        $billing->cam_reconciliation_day = (int) $state['billing.cam_reconciliation_day'];
        $billing->cam_reconciliation_time = (string) $state['billing.cam_reconciliation_time'];
        $billing->save();

        $maint = app(MaintenanceSettings::class);
        $maint->sla_urgent_hours = (int) $state['maintenance.sla_urgent_hours'];
        $maint->sla_high_hours = (int) $state['maintenance.sla_high_hours'];
        $maint->sla_medium_hours = (int) $state['maintenance.sla_medium_hours'];
        $maint->sla_low_hours = (int) $state['maintenance.sla_low_hours'];
        $maint->save();

        $integ = app(IntegrationsSettings::class);
        $integ->paymob_enabled = (bool) $state['integrations.paymob_enabled'];
        $integ->whatsapp_enabled = (bool) $state['integrations.whatsapp_enabled'];
        $integ->save();

        $eta = app(EtaSettings::class);
        $eta->enabled = (bool) $state['eta.enabled'];
        $eta->mock = (bool) $state['eta.mock'];
        $eta->issuer_name = (string) $state['eta.issuer_name'];
        $eta->issuer_tax_registration_number = (string) $state['eta.issuer_tax_registration_number'];
        $eta->save();

        $mods = app(ModulesSettings::class);
        foreach (Modules::KEYS as $key) {
            $mods->{$key} = (bool) ($state["modules.{$key}"] ?? true);
        }
        $mods->save();

        Notification::make()
            ->title(__('admin.settings.saved'))
            ->success()
            ->send();
    }

    /** @return array<int, mixed> */
    private function billingFields(): array
    {
        return [
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
                ]),
            Section::make(__('admin.settings.sections.schedules'))
                ->description(__('admin.settings.sections.schedules_description'))
                ->columns(2)
                ->components([
                    TextInput::make('billing.monthly_billing_day')
                        ->label(__('admin.settings.fields.monthly_billing_day'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(28)
                        ->required(),
                    TextInput::make('billing.monthly_billing_time')
                        ->label(__('admin.settings.fields.monthly_billing_time'))
                        ->placeholder('02:00')
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
                    TextInput::make('billing.cam_reconciliation_time')
                        ->label(__('admin.settings.fields.cam_reconciliation_time'))
                        ->placeholder('03:00')
                        ->required(),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private function maintenanceFields(): array
    {
        return [
            Section::make(__('admin.settings.sections.sla'))
                ->description(__('admin.settings.sections.sla_description'))
                ->columns(2)
                ->components([
                    TextInput::make('maintenance.sla_urgent_hours')->label(__('admin.settings.fields.sla_urgent_hours'))->numeric()->minValue(1)->suffix('hrs')->required(),
                    TextInput::make('maintenance.sla_high_hours')->label(__('admin.settings.fields.sla_high_hours'))->numeric()->minValue(1)->suffix('hrs')->required(),
                    TextInput::make('maintenance.sla_medium_hours')->label(__('admin.settings.fields.sla_medium_hours'))->numeric()->minValue(1)->suffix('hrs')->required(),
                    TextInput::make('maintenance.sla_low_hours')->label(__('admin.settings.fields.sla_low_hours'))->numeric()->minValue(1)->suffix('hrs')->required(),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private function etaFields(): array
    {
        return [
            Section::make(__('admin.settings.sections.eta_toggles'))
                ->description(__('admin.settings.sections.eta_description'))
                ->columns(2)
                ->components([
                    Toggle::make('eta.enabled')->label(__('admin.settings.fields.eta_enabled')),
                    Toggle::make('eta.mock')->label(__('admin.settings.fields.eta_mock')),
                    TextInput::make('eta.issuer_name')->label(__('admin.settings.fields.eta_issuer_name'))->columnSpan(2)->required(),
                    TextInput::make('eta.issuer_tax_registration_number')->label(__('admin.settings.fields.eta_issuer_trn'))->columnSpan(2)->required(),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    private function modulesFields(): array
    {
        return [
            Section::make(__('admin.settings.sections.modules'))
                ->description(__('admin.settings.sections.modules_description'))
                ->columns(2)
                ->components(array_map(
                    fn (string $key) => Toggle::make("modules.{$key}")
                        ->label(__("admin.permission_modules.{$key}")),
                    Modules::KEYS,
                )),
        ];
    }

    /** @return array<int, mixed> */
    private function integrationsFields(): array
    {
        return [
            Section::make(__('admin.settings.sections.payments'))
                ->columns(1)
                ->components([
                    Toggle::make('integrations.paymob_enabled')->label(__('admin.settings.fields.paymob_enabled'))->helperText(__('admin.settings.fields.paymob_enabled_helper')),
                ]),
            Section::make(__('admin.settings.sections.messaging'))
                ->columns(1)
                ->components([
                    Toggle::make('integrations.whatsapp_enabled')->label(__('admin.settings.fields.whatsapp_enabled'))->helperText(__('admin.settings.fields.whatsapp_enabled_helper')),
                ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label(__('admin.settings.save'))
                ->icon('heroicon-o-check')
                ->color('primary')
                ->visible(fn () => Auth::user()?->can('settings.manage') ?? false)
                ->action('save'),
        ];
    }
}
