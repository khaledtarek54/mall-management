<?php

namespace App\Support;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Spatie\LaravelSettings\Settings;

/**
 * Reads and writes every registered settings class by reflection, so the Settings screen cannot
 * quietly lose a field.
 *
 * **What this replaces.** `App\Filament\Admin\Pages\Settings` mapped every field by hand TWICE —
 * once in `mount()` to fill the form and once in `save()` to write it back — beside the schema that
 * declares it. Three places, and the failure mode is silent in the worst direction: a field left
 * out of `save()` renders, accepts a value, says "Saved ✓" and changes nothing. A field left out of
 * `mount()` renders empty and then overwrites the real value with a blank on the next save.
 *
 * That is not hypothetical here. When this class was written, three live settings had no screen at
 * all — `auto_apply_tenant_credit` (whether tenant credit settles invoices by itself),
 * `holdover_default_rate_pct` (the uplift on a lease that runs past its expiry) and
 * `levy_rate_percent` (the marketing levy, which CLAUDE.md described as "configurable"). All three
 * are read by services on every relevant transaction; none could be changed without a deploy.
 *
 * **The state is derived from the classes, not from a list.** Add a property to a settings class and
 * it appears in the state and is written back; the only remaining job is to render a field for it,
 * which `SettingsPageConformanceTest` insists on. There is no third place to forget.
 *
 * Values are cast to the property's DECLARED type on the way in. A Filament text input hands back a
 * string for an `int` property, and assigning it to a typed property would throw at the moment the
 * operator pressed Save.
 */
class SettingsRegistry
{
    /**
     * The settings classes this application registers.
     *
     * @return array<int, class-string<Settings>>
     */
    public static function classes(): array
    {
        return array_values(array_filter(
            config('settings.settings', []),
            fn (string $class) => is_subclass_of($class, Settings::class),
        ));
    }

    /**
     * Every writable property of a settings class, keyed by name.
     *
     * Public, non-static, and declared on the class itself — spatie's base class carries internal
     * state that is none of the screen's business.
     *
     * @return array<string, ReflectionProperty>
     */
    public static function propertiesOf(string $class): array
    {
        $properties = [];

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $properties[$property->getName()] = $property;
        }

        return $properties;
    }

    /**
     * The current value of every setting, shaped as the form's state: group => name => value.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function currentState(): array
    {
        $state = [];

        foreach (self::classes() as $class) {
            $settings = app($class);

            foreach (array_keys(self::propertiesOf($class)) as $name) {
                $state[$class::group()][$name] = $settings->{$name};
            }
        }

        return $state;
    }

    /**
     * Write the submitted state back, and return what actually changed.
     *
     * A property the state does not mention is LEFT ALONE rather than blanked. That matters for a
     * tab an operator never opened and for a field a role cannot see: `getState()` returns what the
     * form holds, and treating an absent key as "set it to empty" would let opening the Settings
     * page and pressing Save wipe whatever the current user was not shown.
     *
     * @param  array<string, array<string, mixed>>  $state
     * @return array<string, array{old: mixed, new: mixed}> keyed "group.name"
     */
    public static function persist(array $state): array
    {
        $changes = [];

        foreach (self::classes() as $class) {
            $group = $class::group();

            if (! is_array($state[$group] ?? null)) {
                continue;
            }

            $settings = app($class);
            $dirty = false;

            foreach (self::propertiesOf($class) as $name => $property) {
                if (! array_key_exists($name, $state[$group])) {
                    continue;
                }

                $old = $settings->{$name};
                $new = self::cast($state[$group][$name], $property);

                if ($old === $new) {
                    continue;
                }

                $settings->{$name} = $new;
                $changes["{$group}.{$name}"] = ['old' => $old, 'new' => $new];
                $dirty = true;
            }

            if ($dirty) {
                $settings->save();
            }
        }

        return $changes;
    }

    /**
     * Coerce a submitted value to the property's declared type.
     *
     * Filament hands back strings for numeric inputs and `null` for a cleared one. Assigning either
     * to a typed property throws at exactly the wrong moment — after the operator pressed Save —
     * so the coercion happens here, once, rather than in twenty hand-written casts.
     */
    private static function cast(mixed $value, ReflectionProperty $property): mixed
    {
        $type = $property->getType();

        if (! $type instanceof ReflectionNamedType) {
            return $value;
        }

        // A nullable property may legitimately hold null; a non-nullable one must not, so an empty
        // input falls back to the property's own default rather than throwing.
        if ($value === null) {
            return $type->allowsNull() ? null : self::defaultFor($property);
        }

        return match ($type->getName()) {
            'bool' => (bool) $value,
            'int' => (int) $value,
            'float' => (float) $value,
            'string' => (string) $value,
            'array' => (array) $value,
            default => $value,
        };
    }

    private static function defaultFor(ReflectionProperty $property): mixed
    {
        if ($property->hasDefaultValue()) {
            return $property->getDefaultValue();
        }

        return match ($property->getType()?->getName()) {
            'bool' => false,
            'int' => 0,
            'float' => 0.0,
            'string' => '',
            'array' => [],
            default => null,
        };
    }
}
