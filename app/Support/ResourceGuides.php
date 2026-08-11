<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * In-app guidance: which operator guide explains each screen.
 *
 * **The guide IS the doc — there is no second copy.** `docs/business-model/NN-*.md` already explains
 * each module in plain language with worked numbers, written for an operator rather than a
 * developer. Re-typing that into the UI would create a second source of truth that drifts from the
 * first, which is the failure this codebase keeps finding elsewhere. So the screen renders the file.
 *
 * **What this is not.** Not a product tour. Tours break on every layout change, cost more to
 * maintain than they teach, and suit self-serve signup rather than a trained operations team who
 * use the same six screens daily. Yardi's own model is the one copied here: a Help control on the
 * screen that opens the manual section for that screen.
 *
 * **Coverage is deliberately partial and NOT gated.** Eight modules have an operator guide today;
 * the rest do not, and a registry padded with thirty exemption reasons would be noise pretending to
 * be rigour. `ResourceGuideConformanceTest` asserts only that every entry here points at a file
 * that exists and renders — writing the missing guides is a documentation task, tracked as one.
 */
class ResourceGuides
{
    /**
     * Resource class => the operator guide that explains it.
     *
     * @var array<class-string, string>
     */
    public const GUIDES = [
        \App\Filament\Admin\Resources\Assets\AssetResource::class => 'docs/business-model/01-properties-units.md',
        \App\Filament\Admin\Resources\Units\UnitResource::class => 'docs/business-model/01-properties-units.md',
        \App\Filament\Admin\Resources\Tenants\TenantResource::class => 'docs/business-model/02-tenants.md',
        \App\Filament\Admin\Resources\Leases\LeaseResource::class => 'docs/business-model/04-leases.md',
        \App\Filament\Admin\Resources\Invoices\InvoiceResource::class => 'docs/business-model/05-billing-invoices.md',
        \App\Filament\Admin\Resources\Payments\PaymentResource::class => 'docs/business-model/06-payments.md',
        \App\Filament\Admin\Resources\CreditNotes\CreditNoteResource::class => 'docs/business-model/07-credit-notes.md',
        \App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource::class => 'docs/business-model/08-cam.md',
        \App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource::class => 'docs/business-model/09-tenant-sales-percentage-rent.md',
    ];

    public static function pathFor(string $resource): ?string
    {
        return self::GUIDES[$resource] ?? null;
    }

    public static function has(string $resource): bool
    {
        return isset(self::GUIDES[$resource]);
    }

    /**
     * The guide as HTML, or null when the resource has none.
     *
     * The front-matter title line is dropped: the modal already carries the screen's name, and
     * repeating it wastes the first line of a panel an operator opened for an answer.
     */
    public static function render(string $resource): ?string
    {
        $path = self::pathFor($resource);

        if ($path === null) {
            return null;
        }

        $full = base_path($path);

        if (! File::exists($full)) {
            return null;
        }

        $markdown = File::get($full);
        $markdown = preg_replace('/\A#\s+.*\R+/', '', $markdown) ?? $markdown;

        return Str::markdown($markdown);
    }
}
