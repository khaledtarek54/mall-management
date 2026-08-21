<?php

namespace App\Support;

use App\Models\Asset;
use Carbon\CarbonImmutable;

/**
 * Which properties bill today.
 *
 * `billing.monthly_billing_day` became a per-property override (M-5), and there is one scheduler for
 * the whole portfolio — so both money runs fire DAILY and ask this. It lives here rather than in
 * each of them because it was copy-pasted into two, and two copies of a decision about WHEN MONEY IS
 * BILLED is two copies too many: they can drift onto different days, and then a mall's leases bill
 * on the 25th while its owner assessments bill on the 1st.
 */
final class BillingDay
{
    /**
     * `assetId => code` for the properties whose billing day falls on `$today`.
     *
     * @return array<int, string>
     */
    public static function propertiesDueOn(CarbonImmutable $today): array
    {
        $lastDayOfMonth = (int) $today->endOfMonth()->day;
        $due = [];

        foreach (Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->get() as $asset) {
            if (self::dueDayFor($asset->id, $lastDayOfMonth) === (int) $today->day) {
                $due[$asset->id] = $asset->code;
            }
        }

        return $due;
    }

    /**
     * The day of THIS month a property bills on, clamped to a day the month actually has.
     *
     * A property set to the 31st must still bill in February. Unclamped, a 31 would skip seven
     * months of the year and a 30 would skip four — silently, because nothing anywhere reports a run
     * that did not happen. Two properties set to the 30th and the 31st therefore both bill on the
     * 30th in a 30-day month, which is the intended reading of "bill at month end".
     *
     * A nonsense override (0, negative, past 31) is clamped rather than refused: the column is
     * operator-editable, and a run that silently skips a mall is worse than one that bills it on a
     * neighbouring day.
     */
    public static function dueDayFor(int $assetId, int $lastDayOfMonth): int
    {
        $day = (int) PropertySettings::get('billing.monthly_billing_day', $assetId);

        return min(max($day, 1), $lastDayOfMonth);
    }
}
