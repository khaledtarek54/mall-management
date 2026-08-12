<?php

namespace App\Filament\Admin\Pages;

use App\Support\PropertySettings;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * What THIS mall answers differently from the portfolio (CFG-03).
 *
 * A separate page from `/admin/settings` on purpose. That one is portfolio-wide, and putting both
 * tiers on one screen would leave the operator unsure which they were editing — the single most
 * expensive mistake available here, since these are late-fee rates and payment terms.
 *
 * ## Blank means inherit, and the screen has to say so
 *
 * Every field shows the portfolio's answer as its placeholder and repeats it in the helper text, so
 * an empty box reads as "this mall uses 2%" rather than "this mall charges nothing". That is the
 * whole UX risk of an override screen: a blank field that looks like a zero. Clearing a field
 * deletes the override row and restores the inherited answer — which is also why there is no
 * "delete" action to find.
 *
 * ## Scoped to the selected property, and only that one
 *
 * The page edits `TenantScope::currentAssetId()`. There is no property picker: the panel already
 * has one, and a second would let somebody write an override for a mall they are not looking at —
 * and, without clamping, for one they cannot see at all.
 */
class PropertyOverrides extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 96;

    protected string $view = 'filament.pages.property-overrides';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.property_overrides');
    }

    public function getTitle(): string
    {
        return __('admin.property_overrides.page_title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.settings');
    }

    /**
     * The same permission that governs the portfolio settings.
     *
     * An override is a settings change with a narrower blast radius, not a lesser one — the numbers
     * it moves are the numbers `/admin/settings` moves. Splitting the permission would let somebody
     * barred from setting a portfolio late fee set a property one instead.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can('settings.view') ?? false;
    }

    public function mount(): void
    {
        $assetId = TenantScope::currentAssetId();

        // Populated ONLY with the property's own overrides, deliberately. Pre-filling the inherited
        // values would make every field look overridden and turn the first Save into an override of
        // all nine at whatever the portfolio happened to say that day — freezing them against later
        // portfolio changes without anybody deciding to.
        $overrides = $assetId !== null ? PropertySettings::overridesFor($assetId) : [];

        $this->data = collect(PropertySettings::OVERRIDABLE)
            ->keys()
            ->mapWithKeys(fn (string $key) => [self::field($key) => $overrides[$key] ?? null])
            ->all();

        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(__('admin.property_overrides.sections.billing'))
                ->description(__('admin.property_overrides.sections.billing_description'))
                ->columns(2)
                ->components($this->overrideFields()),
        ]);
    }

    /** @return array<int, mixed> */
    private function overrideFields(): array
    {
        $assetId = TenantScope::currentAssetId();

        return collect(PropertySettings::OVERRIDABLE)
            ->map(function (array $meta, string $key) use ($assetId) {
                $portfolio = PropertySettings::portfolio($key);
                $inherited = is_bool($portfolio) ? ($portfolio ? '1' : '0') : (string) $portfolio;

                return TextInput::make(self::field($key))
                    ->label(__("admin.settings.fields.".self::name($key)))
                    ->numeric()
                    ->minValue(0)
                    // The portfolio's answer, twice: as the ghost text inside the empty box and as
                    // the sentence under it. A blank field that looks like a zero is the whole risk
                    // of this screen.
                    ->placeholder($inherited)
                    ->helperText(__('admin.property_overrides.inherits', ['value' => $inherited]))
                    ->hintColor('gray')
                    ->hint(fn ($state) => filled($state)
                        ? __('admin.property_overrides.overridden')
                        : __('admin.property_overrides.inherited'));
            })
            ->values()
            ->all();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('admin.actions.save'))
                ->submit('save')
                // `visible()` is not a gate — the real refusal is in save(). Both, so the intent is
                // stated where it is read and enforced where it is dispatched.
                ->authorize(fn () => Auth::user()?->can('settings.manage') ?? false),
        ];
    }

    public function save(): void
    {
        abort_unless(Auth::user()?->can('settings.manage'), 403);

        // Clamped, not taken from the URL. Every other write in this codebase that derives an
        // asset_id goes through the scope; an override screen is no different, and the panel is the
        // only thing that decides which property is being edited.
        $assetId = TenantScope::currentAssetId();

        abort_if($assetId === null || ! in_array($assetId, TenantScope::visibleAssetIds() ?? [$assetId], true), 403);

        $state = $this->form->getState();
        $changes = [];

        foreach (array_keys(PropertySettings::OVERRIDABLE) as $key) {
            $before = PropertySettings::overridesFor($assetId)[$key] ?? null;
            $after = $state[self::field($key)] ?? null;

            // Normalise before comparing: the form hands back strings, the store holds JSON scalars,
            // and without this every Save would log a "change" from 2 to "2".
            $normalised = ($after === null || $after === '') ? null : (float) $after;

            if ($before !== null && (float) $before === $normalised) {
                continue;
            }

            if ($before === null && $normalised === null) {
                continue;
            }

            PropertySettings::set($key, $assetId, $normalised);
            $changes[$key] = ['from' => $before, 'to' => $normalised];
        }

        // Same audit trail as the portfolio settings, and for the same reason: these are money
        // figures, and "who changed this, for which mall, and from what" is the first question
        // asked about one. Written only when something moved.
        if ($changes !== []) {
            activity('settings')
                ->causedBy(Auth::user())
                ->withProperties(['asset_id' => $assetId, 'changes' => $changes])
                ->log('property_settings.updated');
        }

        Notification::make()->title(__('admin.settings.saved'))->success()->send();
    }

    /** Filament reads dots as nested-array paths, so the state key flattens them. */
    private static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private static function name(string $key): string
    {
        return explode('.', $key, 2)[1];
    }
}
