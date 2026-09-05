<?php

namespace App\Support;

use App\Services\Reports\DeliverSavedReportService;
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
     * The property the view was saved in — a reserved key, not a page property.
     *
     * Most report pages carry no `$assetId`: they scope with `TenantScope::currentAssetId()`, which
     * reads the property the operator is STANDING in. That is right on screen and unreproducible
     * off it — a scheduled delivery runs in a queue worker where there is no Filament tenant, so
     * `currentAssetId()` answers **null**, which every scoped query reads as *no property filter*.
     * A rent roll saved in one mall then delivered every month as the WHOLE PORTFOLIO, to whatever
     * addresses the schedule names — and the field's own help text invites the owner's external
     * accountant and auditor, who have no login here and no way to tell whose tenants they are
     * reading.
     *
     * So the standing property is part of what reproduces a view, exactly as its filters are, and
     * {@see snapshotForSavedView()} captures it with them. The double underscore keeps it out of the
     * declared-property namespace:
     * {@see apply()} skips any key the page does not declare, so this one can never be written onto
     * a page as a filter.
     */
    public const PROPERTY_KEY = '__asset_id';

    /**
     * The property a saved view was taken in, if it recorded one.
     *
     * Null means the view pre-dates this being captured. It is deliberately NOT read as "the whole
     * portfolio": {@see DeliverSavedReportService} refuses to deliver such a
     * view rather than guessing, because the two are indistinguishable here and one of them emails
     * another mall's rent roll to somebody outside the business.
     */
    public static function propertyOf(?array $parameters): ?int
    {
        $value = $parameters[self::PROPERTY_KEY] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

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
     * The same, PLUS the property the operator was standing in — for a view that must reproduce
     * itself off-screen.
     *
     * **Two consumers, and only one of them wants this.** `SavesReportViews` saves something a queue
     * worker will later re-render with no Filament tenant, so the standing property is part of what
     * reproduces it; `ReportPreferences::remember()` stores what to re-apply on the operator's own
     * NEXT VISIT, which is always on-screen with a tenant already selected.
     *
     * Adding it in `snapshot()` therefore reached the wrong consumer, and did two things there. It
     * was pure noise — {@see apply()} skips any key the page does not declare, so it can never be
     * re-applied to a preference — and it silently broke that consumer's clearing rule: `remember()`
     * deletes the row when nothing is left, because *"I deselected the property"* is itself the
     * preference, and a snapshot that always carries one key is never empty. An operator who stepped
     * out of a mall kept a preference row for it for ever.
     *
     * A page that DOES declare `$assetId` still gets both, deliberately: its own value is a report
     * PARAMETER the page validates and reads, and this is the tenant context the delivery has to
     * re-establish before that parameter means anything. They agree in practice because
     * `PropertyField` pins the declared one to the selected mall.
     *
     * @return array<string, scalar|null>
     */
    public static function snapshotForSavedView(Page $page): array
    {
        $values = self::snapshot($page);

        if (($assetId = TenantScope::currentAssetId()) !== null) {
            $values[self::PROPERTY_KEY] = $assetId;
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

        // Through the seam, because THIS is the call site that makes it worth having: `$query` is
        // built from whatever parameters the report DECLARES, so a collision with a route
        // parameter is not written by anybody — it appears the day a report declares a property
        // with an unlucky name, and the link would 404 with nothing to say why.
        return rescue(fn () => ResourceLink::page($page, $query), '#', false);
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
