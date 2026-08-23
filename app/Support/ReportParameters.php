<?php

namespace App\Support;

use Filament\Pages\Page;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * What a report page's filters currently say, and how to put them back.
 *
 * A report's parameters are already public properties on its page — `$asOf`, `$bucket`, `$year`,
 * `$period`, `$assetId`, `$accountId`. Reading them by reflection means a saved view picks up a new
 * filter the day the report grows one, with nothing to register and nothing to forget. The
 * alternative was a per-report list of parameter names that would be right on the day it was
 * written and quietly incomplete afterwards — the shape this codebase keeps paying for.
 *
 * **Declared on the page itself, and scalar.** Filament's own machinery hangs public state on every
 * page (`$table*`, `$data`, component containers); none of it is a report parameter, and a snapshot
 * that swept it up would be enormous and meaningless. Restricting to properties the page class
 * declares, of a scalar type, keeps this to the handful of filters an operator actually set.
 *
 * ## Applying is deliberately lossy
 *
 * {@see apply()} sets only the keys the page STILL declares. A report's filters change as it grows,
 * and a saved view that half-matches is worse than one whose stale keys are dropped: the operator
 * sees a report they recognise with one filter defaulted, rather than a page that throws on a
 * property nobody declares any more.
 *
 * **It applies values, never trust.** Every parameter goes back through the page's own validation —
 * `assetId` is clamped to the operator's visible set, an unknown account resolves to none. A saved
 * view is a bookmark, and re-opening one is exactly as safe as typing the URL by hand.
 */
class ReportParameters
{
    /**
     * The filter values this page is currently carrying.
     *
     * @return array<string, scalar|null>
     */
    public static function snapshot(Page $page): array
    {
        $values = [];

        foreach (self::parametersOf($page::class) as $name => $property) {
            $value = $property->isInitialized($page) ? $property->getValue($page) : null;

            // Nulls are dropped rather than stored: "no property filter" and "the property filter
            // was never set" are the same thing to a report, and storing the null would make a
            // saved view override a page default that later changes.
            if ($value !== null) {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * Put a saved snapshot back onto a page, ignoring anything it no longer declares.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function apply(Page $page, array $parameters): void
    {
        $declared = self::parametersOf($page::class);

        foreach ($parameters as $name => $value) {
            if (! isset($declared[$name]) || $value === null) {
                continue;
            }

            $type = $declared[$name]->getType();
            $page->{$name} = $type instanceof ReflectionNamedType
                ? self::cast($value, $type->getName())
                : $value;
        }
    }

    /**
     * A URL that re-opens the report with these parameters.
     *
     * Query string rather than session state, so a saved view can be linked, bookmarked and shared
     * — and so re-opening one goes through exactly the same clamping a hand-typed URL does.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function urlFor(string $page, array $parameters, ?int $savedReport = null): string
    {
        $declared = array_keys(self::parametersOf($page));

        $query = collect($parameters)
            ->only($declared)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        // The saved report's COLUMN layout is far too big for a query string and Filament binds
        // none of it to the URL, so the link names the view and the page reads its columns back
        // (EG-32). An id, not a layout: what the reader sees is rebuilt from their own table.
        if ($savedReport !== null) {
            $query['savedReport'] = $savedReport;
        }

        return rescue(fn () => $page::getUrl($query), '#', false);
    }

    /**
     * Public, non-static, scalar properties the page class declares itself.
     *
     * @return array<string, ReflectionProperty>
     */
    public static function parametersOf(string $page): array
    {
        $parameters = [];
        $fromTraits = self::traitProperties($page);

        foreach ((new ReflectionClass($page))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            // Reflection reports a TRAIT's property as declared on the class that uses it, so
            // "declared here" is not enough on its own: `InteractsWithTable` alone contributes
            // `isTableLoaded`, `isTableReordering` and more, none of which is a report parameter
            // and all of which would end up in every saved view.
            if ($property->isStatic()
                || $property->getDeclaringClass()->getName() !== $page
                || in_array($property->getName(), $fromTraits, true)) {
                continue;
            }

            $type = $property->getType();

            if (! $type instanceof ReflectionNamedType
                || ! in_array($type->getName(), ['int', 'string', 'bool', 'float'], true)) {
                continue;
            }

            $parameters[$property->getName()] = $property;
        }

        return $parameters;
    }

    /**
     * Property names contributed by FRAMEWORK traits — the ones that are never report parameters.
     *
     * ## Why this is not simply "every trait"
     *
     * It was, and that was a bug that reached production. Reflection reports a trait's property as
     * declared on the class using it, so excluding every trait property is the obvious way to keep
     * Filament's `isTableLoaded` / `isTableReordering` out of saved views. It also silently excluded
     * `ScopesLedgerReport`, which declares `$year`, `$period` and `$assetId` — **the entire
     * parameter surface of the Income Statement, Balance Sheet, Cash Flow and Trial Balance.**
     *
     * Those four reports therefore had NO parameters at all. A saved view of them stored nothing,
     * `urlFor()` returned `#` because there was nothing to build a query string from, and a
     * scheduled delivery would have rendered the DEFAULT period — emailing an owner a statement
     * headed one quarter and filled with another's numbers. Silent in every direction.
     *
     * The line is ownership, not mechanism: a **first-party** trait under `App\` is our own code
     * factored out, and its public typed scalars are as much a parameter as one written inline. A
     * vendor trait is infrastructure the page did not choose. So only vendor traits are excluded.
     *
     * @return array<int, string>
     */
    private static function traitProperties(string $page): array
    {
        $names = [];

        for ($class = new ReflectionClass($page); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getTraits() as $trait) {
                if (self::isFirstParty($trait->getName())) {
                    continue;
                }

                foreach ($trait->getProperties() as $property) {
                    $names[] = $property->getName();
                }

                // A trait may itself use traits — Filament's do.
                foreach ($trait->getTraitNames() as $nested) {
                    if (self::isFirstParty($nested)) {
                        continue;
                    }

                    foreach ((new ReflectionClass($nested))->getProperties() as $property) {
                        $names[] = $property->getName();
                    }
                }
            }
        }

        return array_unique($names);
    }

    private static function isFirstParty(string $trait): bool
    {
        return str_starts_with($trait, 'App\\');
    }

    private static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            default => $value,
        };
    }
}
