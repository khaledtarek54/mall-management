<?php

namespace App\Filament\Portal\Pages;

use App\Filament\Actions\GuideAction;
use App\Models\Tenant;
use App\Support\Portal;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The retailer's own contact details, maintained by the retailer.
 *
 * **Why this exists.** The panel already ships a profile page — but that one edits the signed-in
 * `TenantUser` (their name, email, password). The COMPANY's phone, WhatsApp, address and contact
 * person live on `Tenant`, and nothing outside the admin panel could write them. So the operator
 * maintained a retailer's contact details by hand, from whatever was said at signing, and they went
 * stale silently — while those are exactly the fields the overdue reminders, the SLA notices and
 * the collections chase are addressed to. The tenant who could correct them in ten seconds had no
 * screen to do it on.
 *
 * ## What is deliberately NOT here
 *
 * - **Legal identity** — `name`, `legal_name`, `tax_id`, `national_id`, `commercial_register`.
 *   These are what the landlord BILLS, and what an invoice and a tax filing carry. A tenant editing
 *   their own tax ID would rewrite the counterparty on documents already issued against it. Those
 *   change by agreement, through the operator.
 * - **`email` / `password`** — the `Tenant` row is also a Sanctum identity for `/api/v1`
 *   (`tenant-api`). Editing them here would change how the mobile app authenticates, from a screen
 *   about contact details.
 * - **The store directory** (`trade_name`, `public_description`, `is_listed`, module 36). That is
 *   what a SHOPPER sees, and the mall curates it. A tenant rewriting their own public listing is a
 *   different feature with a different approval question.
 * - **Bank details** — not in the schema at all, and adding a payout field nobody asked for is how
 *   you end up storing account numbers with no decision about who may read them.
 *
 * Writes are `Portal::isAdmin()` only. A read-only staff login must not be able to redirect where
 * the mall's notices go — that is the whole portal's rule, and it matters more here than on a list
 * screen because this changes where money conversations are addressed.
 *
 * Every change is recorded: `Tenant` logs activity, so "who changed the number and when" is
 * answerable afterwards rather than a mystery between two parties.
 */
class CompanyProfile extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $slug = 'company-profile';

    /** Last in the nav: read your bills first, edit your own details rarely. */
    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.company-profile';

    /** @var array<string, mixed> */
    public array $data = [];

    /** The columns this screen may write. Named once — `mount()` and `save()` both read it. */
    public const EDITABLE = [
        'phone',
        'whatsapp',
        'contact_person',
        'contact_person_phone',
        'address_governorate',
        'address_city',
        'address_street',
        'address_building_number',
    ];

    public static function getNavigationLabel(): string
    {
        return __('admin.company_profile.title');
    }

    public function getTitle(): string
    {
        return __('admin.company_profile.title');
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
        ];
    }

    public function mount(): void
    {
        $tenant = Portal::tenant();

        $this->data = collect(self::EDITABLE)
            ->mapWithKeys(fn (string $field) => [$field => $tenant?->{$field}])
            ->all();

        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        // Read-only for a staff login rather than hidden: they should be able to SEE the number the
        // mall will call, and know it is not theirs to change.
        $canWrite = Portal::isAdmin();

        return $schema->statePath('data')->components([
            Section::make(__('admin.company_profile.sections.contact'))
                ->description(__('admin.company_profile.sections.contact_description'))
                ->columns(2)
                ->components([
                    TextInput::make('phone')
                        ->label(__('admin.fields.phone'))
                        ->tel()
                        ->maxLength(32)
                        ->disabled(! $canWrite)
                        ->helperText(__('admin.company_profile.helpers.phone')),
                    TextInput::make('whatsapp')
                        ->label(__('admin.fields.whatsapp'))
                        ->tel()
                        ->maxLength(32)
                        ->disabled(! $canWrite)
                        ->helperText(__('admin.company_profile.helpers.whatsapp')),
                    TextInput::make('contact_person')
                        ->label(__('admin.fields.contact_person'))
                        ->maxLength(120)
                        ->disabled(! $canWrite),
                    TextInput::make('contact_person_phone')
                        ->label(__('admin.fields.contact_person_phone'))
                        ->tel()
                        ->maxLength(32)
                        ->disabled(! $canWrite),
                ]),

            Section::make(__('admin.company_profile.sections.address'))
                ->description(__('admin.company_profile.sections.address_description'))
                ->columns(2)
                ->components([
                    TextInput::make('address_governorate')
                        ->label(__('admin.fields.address_governorate'))
                        ->maxLength(120)
                        ->disabled(! $canWrite),
                    TextInput::make('address_city')
                        ->label(__('admin.fields.address_city'))
                        ->maxLength(120)
                        ->disabled(! $canWrite),
                    TextInput::make('address_street')
                        ->label(__('admin.fields.address_street'))
                        ->maxLength(255)
                        ->disabled(! $canWrite),
                    TextInput::make('address_building_number')
                        ->label(__('admin.fields.address_building_number'))
                        ->maxLength(32)
                        ->disabled(! $canWrite),
                ]),
        ]);
    }

    public function save(): void
    {
        // The real gate. A disabled field's value still arrives in the Livewire payload, so the
        // form being read-only proves nothing about what a crafted request can write — the same
        // reason the admin invoice form re-derives its debtor instead of trusting the field.
        abort_unless(Portal::isAdmin(), 403);

        $tenant = Portal::tenant();

        abort_if($tenant === null, 403);

        $state = $this->form->getState();

        // Only the whitelisted columns, taken by key rather than by mass-assigning the payload:
        // `Tenant::$fillable` includes `tax_id` and `name`, and the state is user-supplied.
        $tenant->fill(collect(self::EDITABLE)
            ->mapWithKeys(fn (string $field) => [$field => self::blankToNull($state[$field] ?? null)])
            ->all());

        $tenant->save();

        Notification::make()
            ->success()
            ->title(__('admin.company_profile.saved'))
            ->send();
    }

    /**
     * An emptied field means "I have no such number", not an empty string.
     *
     * The columns are nullable and the rest of the system tests them with `filled()`; storing `''`
     * makes a cleared WhatsApp look present and sends a reminder into nothing.
     */
    private static function blankToNull(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    /**
     * Only where there IS a tenant behind the login.
     *
     * @see Tenant
     */
    public static function canAccess(): bool
    {
        return Portal::tenant() !== null;
    }
}
