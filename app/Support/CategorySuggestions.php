<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * The built-in category suggestions, and the one rule for displaying them bilingually.
 *
 * `fixed_assets.category` and `warehouses.category` are **free-form string columns**: the
 * operator may type anything, and the Select merely seeds the list with suggestions and offers
 * a "create" affordance. That freedom is deliberate and stays.
 *
 * But the seeded suggestions are OUR strings, not the operator's — and they were English
 * literals inlined in the form (`'furniture', 'equipment', 'HVAC', …`), rendered straight
 * through as both the option label and the table cell. So an Arabic operator opened the
 * Category dropdown and read English, which is what a bilingual panel must not do.
 *
 * The fix cannot be "translate the stored value", because then a rename would orphan every
 * existing row, and an operator-typed category has no translation by definition. So:
 *
 *   · the **stored value stays the key** — `HVAC` is still `HVAC` in the database, and no
 *     migration is needed;
 *   · the **label is looked up**, falling back to the stored value when there is no entry.
 *
 * That fallback is what makes the field still free-form: a category the operator invents shows
 * exactly as they typed it, while the ones we ship read in their language. Both directions are
 * pinned by `CategorySuggestionsTest`.
 */
final class CategorySuggestions
{
    /** Fixed-asset categories seeded into the Select. Values are the stored strings. */
    public const FIXED_ASSET = ['furniture', 'equipment', 'HVAC', 'IT', 'vehicles', 'fit-out'];

    /** Warehouse (stock-location) categories. */
    public const WAREHOUSE = ['spare_parts', 'machines', 'consumables'];

    /**
     * The display label for a stored category value.
     *
     * Returns the translation when the value is one we ship, and the raw value otherwise — an
     * operator-created category is their words and must not be mangled.
     */
    public static function label(string $group, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = "admin.enums.category_suggestions.{$group}.{$value}";

        return Lang::has($key) ? __($key) : $value;
    }

    /**
     * Options for the Select: every suggestion, everything already in use, and the field's own
     * current state — keyed by the stored value, labelled for display.
     *
     * The current state must be included or Filament's implicit `in:options` rule rejects a
     * stored-but-unlisted value on save (this is why the original inline version pushed
     * `$get('category')`).
     *
     * @param  array<int, string>  $suggestions
     * @param  iterable<int, string|null>  $inUse
     * @return array<string, string>
     */
    public static function options(string $group, array $suggestions, iterable $inUse, ?string $current = null): array
    {
        return collect($suggestions)
            ->merge($inUse)
            ->push($current)
            ->filter()
            ->unique()
            ->mapWithKeys(fn (string $value): array => [$value => self::label($group, $value)])
            ->all();
    }
}
