<?php

namespace App\Support;

/**
 * In-app operator guidance: what a screen is for, and what it changes elsewhere.
 *
 * **This replaced a markdown dump, and the reasons are worth keeping.** The first version rendered
 * `docs/business-model/NN-*.md` into a modal. It avoided duplicating content, and it was wrong on
 * three counts: the docs are ENGLISH ONLY, so an Arabic operator got English help in an RTL panel;
 * it styled itself with `prose` classes that do not exist in this build (no typography plugin), so
 * it rendered as unspaced raw text; and a whole reference document dumped into a dialogue is not
 * guidance — someone who opens help is stuck on one thing, not looking for a chapter.
 *
 * So the in-app guide is now SHORT, STRUCTURED and TRANSLATED, and the module doc stays the deep
 * reference behind it. That is a deliberate second surface rather than a second source of truth: the
 * doc explains the module with worked numbers, this answers "what am I looking at and what happens
 * if I touch it".
 *
 * Four fields per screen, because they are the four questions actually asked:
 *   purpose — what this screen is, in one sentence
 *   steps   — how the everyday task is done
 *   affects — **what changes elsewhere in the system**, which is the one nothing else tells you
 *   rules   — the constraints that will otherwise surprise someone
 *
 * Content lives in `lang/{en,ar}/guides.php`, so it is translated like everything else and the
 * translation gate covers it.
 */
class ResourceGuides
{
    /**
     * Resource class => guide key in `guides.php`.
     *
     * @var array<class-string, string>
     */
    public const GUIDES = [
        \App\Filament\Admin\Resources\Assets\AssetResource::class => 'properties',
        \App\Filament\Admin\Resources\Units\UnitResource::class => 'units',
        \App\Filament\Admin\Resources\Tenants\TenantResource::class => 'tenants',
        \App\Filament\Admin\Resources\Leases\LeaseResource::class => 'leases',
        \App\Filament\Admin\Resources\Invoices\InvoiceResource::class => 'invoices',
        \App\Filament\Admin\Resources\Payments\PaymentResource::class => 'payments',
        \App\Filament\Admin\Resources\CreditNotes\CreditNoteResource::class => 'credit_notes',
        \App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource::class => 'cam',
        \App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource::class => 'sales_declarations',
        \App\Filament\Admin\Resources\RentableItems\RentableItemResource::class => 'rentable_items',
        \App\Filament\Admin\Resources\ChargeCodes\ChargeCodeResource::class => 'charge_codes',
        \App\Filament\Admin\Resources\AccountMappings\AccountMappingResource::class => 'posting_map',
    ];

    public static function keyFor(string $resource): ?string
    {
        return self::GUIDES[$resource] ?? null;
    }

    public static function has(string $resource): bool
    {
        return isset(self::GUIDES[$resource]);
    }

    /** One sentence: what this screen is. */
    public static function purpose(string $key): string
    {
        return __("guides.{$key}.purpose");
    }

    /**
     * The everyday task, in order.
     *
     * @return array<int, string>
     */
    public static function steps(string $key): array
    {
        $steps = __("guides.{$key}.steps");

        return is_array($steps) ? array_values($steps) : [];
    }

    /**
     * What changes elsewhere when this screen is used — the question nothing else answers.
     *
     * @return array<int, string>
     */
    public static function affects(string $key): array
    {
        $affects = __("guides.{$key}.affects");

        return is_array($affects) ? array_values($affects) : [];
    }

    /**
     * The constraints that would otherwise surprise someone.
     *
     * @return array<int, string>
     */
    public static function rules(string $key): array
    {
        $rules = __("guides.{$key}.rules");

        return is_array($rules) ? array_values($rules) : [];
    }
}
