<?php

namespace App\Support;

use App\Models\Asset;
use App\Settings\TaxSettings;

/**
 * Whose name is on a generated document.
 *
 * Every PDF this system produces is issued by the OPERATOR — Eltizam — whether it is a tax invoice
 * for a tenant, a statement for an owner, a payslip for an employee or a trial balance for the
 * accountant. Five of them printed **"Atriom"**, which is the software, not the issuer: the owner
 * statement, the payslip, the monthly-close pack, the facility work log and the four financial
 * statements. A document that names its vendor instead of its author is wrong on the one line a
 * reader uses to decide whose figures these are.
 *
 * Two questions, deliberately separate, because the documents answer them differently:
 *
 * - {@see tradingName()} — the name a COUNTERPARTY knows. On a tenant-facing document the mall is
 *   the trading address and the tenant recognises it, so the property's name leads and the
 *   registered entity appears beneath it. This is the composition the tax invoice settled on.
 * - {@see legalName()} — the registered entity alone. On a document about a property rather than
 *   from one (an owner statement names its property in the party block; a payslip's issuer is the
 *   employer) the operator leads and the property is stated elsewhere.
 *
 * Reads {@see TaxSettings}, not `Asset`, for the operator's identity, for the reason stated there:
 * Eltizam is ONE registered entity operating several malls, so the seller is the operator and the
 * building is only where it trades. If a second legal entity ever issues documents for its own
 * properties this grows a per-asset override — never a second copy of the field.
 *
 * `'Atriom'` survives as the last-resort fallback only. An unconfigured install has no registered
 * name to print and a blank document header is worse than a placeholder one; setting
 * `seller_legal_name` is a go-live gate item (docs/operations/GO-LIVE.md).
 */
final class IssuingEntity
{
    /** What an unconfigured install prints when it has nothing truer to say. */
    public const FALLBACK = 'Atriom';

    /**
     * The name a counterparty knows this document by: the property, falling back to the registered
     * entity.
     *
     * The property leads because a tenant reads "Atriom Walk" and knows which mall billed them;
     * they may never have heard the operator's registered name. The registered name belongs
     * underneath, which is what {@see legalName()} is rendered as on those documents.
     */
    public static function tradingName(?Asset $asset = null): string
    {
        $name = trim((string) $asset?->name);

        if ($name !== '') {
            return $name;
        }

        return self::legalName() ?: self::FALLBACK;
    }

    /**
     * The operator's registered legal name, or '' when it has not been configured.
     *
     * Returns the empty string rather than the fallback so a caller can tell "not configured" from
     * "configured" — the tax invoice prints this line only when it is set, and a document must
     * never state a registered name that is not one.
     */
    public static function legalName(): string
    {
        return trim(app(TaxSettings::class)->seller_legal_name);
    }

    /** {@see legalName()}, falling back to something printable. For a document header's top line. */
    public static function name(): string
    {
        return self::legalName() ?: self::FALLBACK;
    }

    /**
     * The operator's tax registration number, or '' when unset.
     *
     * Empty is the shipped default and the PDFs print the line only when it is set: a plausible
     * placeholder on a tax document is worse than a missing number, because it reads as valid, gets
     * filed by the counterparty, and fails on audit.
     */
    public static function taxRegistrationNumber(): string
    {
        return trim(app(TaxSettings::class)->seller_tax_registration_number);
    }

    /**
     * Is the operator registered for tax — i.e. may a document it issues call itself a TAX invoice?
     *
     * The predicate behind {@see InvoicePdfService::viewData()}'s document title, named here rather
     * than spelled `taxRegistrationNumber() !== ''` at the call site, because it is the same fact
     * `App\Support\ConfigurationHealth::sellerTaxIdentity()` reports as BLOCKING and the two must
     * not be able to disagree about what "registered" means.
     *
     * The rule it enforces: the invoice template printed the seller's registration number only when
     * it was set — with a comment above it saying a document titled "Tax Invoice" must carry one —
     * and then printed the TITLE unconditionally. So an unconfigured install issued documents that
     * ASSERTED a tax character with nothing behind them, which is the "confidently wrong" state the
     * conditional line exists to avoid, arriving one line higher up.
     */
    public static function isTaxRegistered(): bool
    {
        return self::taxRegistrationNumber() !== '';
    }

    /**
     * The address a counterparty writes to about a bill, or '' when unset.
     *
     * Same contract as {@see taxRegistrationNumber()} — empty means "not configured", and every
     * template prints the line only when it is set. Parameterless for the reason given in the class
     * docblock: the seller is the operator, not the building. A mall that genuinely needs its own
     * billing address grows an override HERE, so every document keeps resolving it in one place.
     */
    public static function billingEmail(): string
    {
        return trim(app(TaxSettings::class)->seller_billing_email);
    }

    /**
     * The issuer block as view data, so a service states it in one line and no template has to reach
     * for a setting itself.
     *
     * `sellerLegalName` rather than a second `issuerLegalName` key: the first cut of this returned
     * both, the tax documents passed `sellerLegalName` separately on top, and the result was two
     * names for one value where a template author would reasonably pick either — and get an
     * undefined variable on ten of the twelve documents. One key, one name.
     *
     * @return array{issuerName: string, sellerLegalName: string, sellerTrn: string, billingEmail: string, issuerLogo: string|null, issuerAddress: string}
     */
    /**
     * The mall's logo as a LOCAL FILE PATH, or null.
     *
     * A path, not `Asset::logoUrl()`: mpdf renders these documents server-side, and handing it a URL
     * makes every PDF depend on the box being able to fetch its own public URL — which fails behind
     * a private network, a self-signed certificate, or simply a slow request, and fails as a missing
     * image with no error anyone sees. The file is on the `public` disk and readable directly.
     *
     * Null when the property has no logo or the file has gone missing under the record; the templates
     * render the text header alone, which is exactly what they did before.
     */
    private static function logoPath(?Asset $asset): ?string
    {
        if ($asset === null) {
            return null;
        }

        $path = $asset->getFirstMedia('logo')?->getPath();

        return $path !== null && is_file($path) ? $path : null;
    }

    /**
     * The issuer block for a report whose scope is a LIST of properties.
     *
     * One mall selected means one mall's letterhead; two or more, or none, is a portfolio document
     * and carries the operator's identity without a property logo. Written here rather than in each
     * report service so the "exactly one" rule cannot drift between them — the trial balance, the
     * income statement, the balance sheet, the cash flow and the facility work log all share it.
     *
     * @param  array<int>|null  $assetIds  null = the whole portfolio
     * @return array<string, mixed>
     */
    public static function forViewScopedTo(?array $assetIds): array
    {
        $ids = array_values(array_unique(array_filter((array) $assetIds)));

        return self::forView(count($ids) === 1 ? Asset::find($ids[0]) : null);
    }

    public static function forView(?Asset $asset = null): array
    {
        return [
            'issuerName' => self::tradingName($asset),
            'sellerLegalName' => self::legalName(),
            'sellerTrn' => self::taxRegistrationNumber(),
            'billingEmail' => self::billingEmail(),
            'issuerLogo' => self::logoPath($asset),
            'issuerAddress' => self::addressOf($asset),
        ];
    }

    /**
     * The property's address as one line, or '' when it has none.
     *
     * Composed here rather than in each template because a blade `@if` chain around commas cannot
     * punctuate a list whose members may be absent: the invoice header read
     * "Wahat Road, 6th of October City , 6th of October , Egypt" — a stray space before two commas
     * and the city twice — and the credit note, receipt and purchase order each carried their own
     * slightly different copy of the same chain. Filtering the blanks first and joining once is the
     * only way this stays right when a property has no city, or no country, or neither.
     */
    private static function addressOf(?Asset $asset): string
    {
        if ($asset === null) {
            return '';
        }

        return collect([$asset->address, $asset->city, $asset->country])
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            // A property whose `city` is already spelled out inside `address` should not have it
            // printed twice, which is what the shipped header did.
            ->unique(fn (string $part) => mb_strtolower($part))
            ->reject(fn (string $part, $key) => $key > 0 && mb_stripos((string) $asset->address, $part) !== false)
            ->implode(', ');
    }
}
