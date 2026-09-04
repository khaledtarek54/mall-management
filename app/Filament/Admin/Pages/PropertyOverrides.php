<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Support\PropertySettings;
use App\Support\TenantScope;
use App\Support\ValueSets;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
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

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            // The Save lives in the action strip, not in the Blade — which is the whole of this fix.
            //
            // Measured at 83624504: `resources/views/filament/pages/property-overrides.blade.php`
            // rendered `<x-filament::button type="submit">` unconditionally, and the gated
            // `getFormActions()` that used to sit lower in this file was called by NOTHING —
            // `Filament\Pages\Page` does not use `InteractsWithFormActions`, and
            // `grep -rn getCachedFormActions app resources` finds no caller. So the gate was
            // written, reviewed and dead. `canAccess()` is `settings.view` while the write is
            // `settings.manage`, and three roles hold the first without the second (measured on the
            // dev database 2026-09-04: manager, viewer, mall_admin) — every one of them was shown a
            // Save button whose only possible outcome is the raw 403 from `save()`.
            //
            // `->authorize()` is both layers at once: it folds into `isHidden()`, so the button is
            // not offered, and `App\Support\Filament\AuthorizedAction::call()` aborts on it at
            // dispatch. `save()` keeps its own `abort_unless` regardless — the same double gate
            // `Settings`, this screen's deliberate twin, has always used.
            Action::make('save')
                ->label(__('admin.actions.save'))
                ->icon('heroicon-o-check')
                ->color('primary')
                ->authorize(fn (): bool => static::canSave())
                ->action('save'),
        ];
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

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

    /**
     * May the signed-in operator WRITE an override?
     *
     * Named once because it is asked twice — by the action that offers the button and by `save()`
     * itself. `canAccess()` above is the READ right and this is the WRITE right; they are
     * deliberately different permissions, which is precisely why the two must not drift.
     */
    public static function canSave(): bool
    {
        return Auth::user()?->can('settings.manage') ?? false;
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
            ->map(function (array $meta, string $key) {
                $portfolio = PropertySettings::portfolio($key);
                $inherited = is_bool($portfolio) ? ($portfolio ? '1' : '0') : (string) $portfolio;

                $hint = fn ($state) => filled($state)
                    ? __('admin.property_overrides.overridden')
                    : __('admin.property_overrides.inherited');

                // A BOOLEAN setting is a three-state choice, not a number.
                //
                // Every override rendered as a numeric text box, which is fine for a percentage and
                // wrong for `auto_apply_tenant_credit`: the operator was asked to express "off" by
                // typing `0`, with nothing on screen saying so, and the round-trip then returned an
                // int where the portfolio returns a bool. A Toggle cannot express the third state
                // this screen exists for — INHERIT — so it is a Select.
                if (is_bool($portfolio)) {
                    return Select::make(self::field($key))
                        ->label(__('admin.settings.fields.'.self::name($key)))
                        ->options([
                            '1' => __('admin.property_overrides.yes'),
                            '0' => __('admin.property_overrides.no'),
                        ])
                        ->placeholder(__('admin.property_overrides.inherit_option', [
                            'value' => $portfolio ? __('admin.property_overrides.yes') : __('admin.property_overrides.no'),
                        ]))
                        ->native(false)
                        ->helperText(__('admin.property_overrides.inherits', [
                            'value' => $portfolio ? __('admin.property_overrides.yes') : __('admin.property_overrides.no'),
                        ]))
                        ->hintColor('gray')
                        ->hint($hint);
                }

                // A setting whose portfolio value is a STRING is a choice, not a number.
                //
                // Every non-boolean override rendered as a NUMERIC text box, and
                // `billing.proration_method` is one of `actual | thirty_day | year_365 |
                // whole_month`. So the field could not hold its own value: the operator was shown a
                // number box for a word, anything they typed was rejected as non-numeric or
                // silently discarded, and the setting was unsettable from the one screen that
                // exists to set it. Same class as the boolean case directly above, which was fixed
                // and not generalised.
                //
                // The options come from `ValueSets`, the register that already says what the column
                // may hold — never a second list beside it.
                if (is_string($portfolio) && ($choices = self::choicesFor($key)) !== []) {
                    return Select::make(self::field($key))
                        ->label(__('admin.settings.fields.'.self::name($key)))
                        ->options($choices)
                        ->placeholder(__('admin.property_overrides.inherit_option', [
                            'value' => $choices[$portfolio] ?? $portfolio,
                        ]))
                        ->native(false)
                        ->helperText(__('admin.property_overrides.inherits', [
                            'value' => $choices[$portfolio] ?? $portfolio,
                        ]))
                        ->hintColor('gray')
                        ->hint($hint);
                }

                return TextInput::make(self::field($key))
                    ->label(__('admin.settings.fields.'.self::name($key)))
                    ->numeric()
                    ->minValue(0)
                    // Whole numbers where the portfolio holds one: a billing day of `25.7` was
                    // accepted and silently truncated.
                    ->when(is_int($portfolio), fn (TextInput $f) => $f->integer())
                    // The portfolio's answer, twice: as the ghost text inside the empty box and as
                    // the sentence under it. A blank field that looks like a zero is the whole risk
                    // of this screen.
                    ->placeholder($inherited)
                    ->helperText(__('admin.property_overrides.inherits', ['value' => $inherited]))
                    ->hintColor('gray')
                    ->hint($hint);
            })
            ->values()
            ->all();
    }

    public function save(): void
    {
        abort_unless(static::canSave(), 403);

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

    /**
     * The values a string-valued override may take, labelled.
     *
     * Read from `ValueSets` — the register that already answers "what may this column hold" — so a
     * new proration method is offered here by being registered there, not by anyone remembering
     * this screen. Returns `[]` when the setting maps to no known column, which falls the field
     * back to a plain text box rather than offering an empty dropdown.
     *
     * @return array<string, string>
     */
    private static function choicesFor(string $key): array
    {
        $column = match ($key) {
            'billing.proration_method' => ['leases', 'proration_method'],
            default => null,
        };

        if ($column === null) {
            return [];
        }

        return collect(ValueSets::allowed(...$column))
            ->mapWithKeys(fn (string $value): array => [
                $value => __('admin.proration_methods.'.$value),
            ])
            ->all();
    }
}
