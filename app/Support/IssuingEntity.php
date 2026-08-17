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
 * `seller_legal_name` is a go-live gate item (docs/GO-LIVE.md).
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
     * The issuer block as view data, so a service states it in one line and no template has to reach
     * for a setting itself.
     *
     * `sellerLegalName` rather than a second `issuerLegalName` key: the first cut of this returned
     * both, the tax documents passed `sellerLegalName` separately on top, and the result was two
     * names for one value where a template author would reasonably pick either — and get an
     * undefined variable on ten of the twelve documents. One key, one name.
     *
     * @return array{issuerName: string, sellerLegalName: string, sellerTrn: string}
     */
    public static function forView(?Asset $asset = null): array
    {
        return [
            'issuerName' => self::tradingName($asset),
            'sellerLegalName' => self::legalName(),
            'sellerTrn' => self::taxRegistrationNumber(),
        ];
    }
}
