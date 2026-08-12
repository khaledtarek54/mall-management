<?php

namespace App\Support;

use App\Settings\BillingSettings;

/**
 * Where the AR ageing buckets end — the operator's policy, not a constant.
 *
 * 30/60/90 is the common shape and it was hard-coded, which made "show me 45/90/120" a deploy. It
 * is a real request: a mall whose leases pay quarterly ages nothing meaningfully at 30 days, and
 * the buckets are the first thing an owner reads on the AR report.
 *
 * ## It also removes a duplication that could not have survived contact
 *
 * The ranges lived in `ReportService::AGING_BUCKETS` **and again as literals** inside
 * `agingBucketKey()` — the classifier every invoice goes through. The const's own docblock warned
 * that it "is not allowed to be copied", and it had been. Changing one would have left the summary
 * totals and the drill-down disagreeing about which bucket an invoice is in, which reads as a
 * reporting bug and is a policy bug.
 *
 * There is now one list. {@see keyFor()} derives from the same boundaries the labels do.
 *
 * ## The keys are identifiers, not descriptions
 *
 * `d_1_30` stays `d_1_30` whatever the first boundary becomes. It is a URL parameter, a saved-view
 * parameter, a colour lookup and a translation key in six places; renaming it when a boundary moves
 * would break bookmarks to say something the LABEL already says. The label is derived and reads
 * "1–45 days" when the boundary is 45.
 */
class AgingBuckets
{
    /** The `current` bucket — not overdue at all. Never configurable: zero days late is zero. */
    public const CURRENT = 'current';

    /**
     * The bucket keys, in order. Stable identifiers — see the class docblock.
     *
     * @var array<int, string>
     */
    public const KEYS = [self::CURRENT, 'd_1_30', 'd_31_60', 'd_61_90', 'd_90_plus'];

    /**
     * The boundaries an unconfigured install ages at, and the floor under a mistyped setting.
     *
     * @var array<int, int>
     */
    public const DEFAULTS = [30, 60, 90];

    /**
     * The three upper bounds, ascending — the operator's setting, made safe.
     *
     * Clamped rather than validated-and-thrown: an ageing report must not stop rendering because
     * somebody typed the boundaries out of order. A non-ascending or non-positive set falls back to
     * the default, so the report is always answerable and the settings screen is where the mistake
     * gets fixed.
     *
     * @return array<int, int>
     */
    public static function boundaries(): array
    {
        $configured = app(BillingSettings::class)->ar_aging_bucket_days;

        $days = collect(is_array($configured) ? $configured : [])
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->values()
            ->all();

        if (count($days) !== 3) {
            return self::DEFAULTS;
        }

        // Ascending, or the ranges overlap and an invoice lands in whichever bucket was asked first.
        return ($days[0] < $days[1] && $days[1] < $days[2]) ? $days : self::DEFAULTS;
    }

    /**
     * key => [from, to] in days overdue, inclusive; `null` = unbounded.
     *
     * The shape `ReportService::AGING_BUCKETS` had, now derived.
     *
     * @return array<string, array{0: ?int, 1: ?int}>
     */
    public static function all(): array
    {
        [$first, $second, $third] = self::boundaries();

        return [
            self::CURRENT => [null, 0],
            'd_1_30' => [1, $first],
            'd_31_60' => [$first + 1, $second],
            'd_61_90' => [$second + 1, $third],
            'd_90_plus' => [$third + 1, null],
        ];
    }

    /** Which bucket an invoice `$days` overdue belongs in. */
    public static function keyFor(int $days): string
    {
        [$first, $second, $third] = self::boundaries();

        return match (true) {
            $days <= 0 => self::CURRENT,
            $days <= $first => 'd_1_30',
            $days <= $second => 'd_31_60',
            $days <= $third => 'd_61_90',
            default => 'd_90_plus',
        };
    }

    /**
     * What to call a bucket on screen — derived from the boundaries, so a label can never claim a
     * range the classifier does not use.
     */
    public static function label(string $key): string
    {
        [$first, $second, $third] = self::boundaries();

        return match ($key) {
            self::CURRENT => __('admin.widgets.ar_aging.current'),
            'd_1_30' => __('admin.widgets.ar_aging.range', ['from' => 1, 'to' => $first]),
            'd_31_60' => __('admin.widgets.ar_aging.range', ['from' => $first + 1, 'to' => $second]),
            'd_61_90' => __('admin.widgets.ar_aging.range', ['from' => $second + 1, 'to' => $third]),
            'd_90_plus' => __('admin.widgets.ar_aging.over', ['days' => $third]),
            default => $key,
        };
    }

    /**
     * key => label for every bucket, in order.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return collect(self::KEYS)->mapWithKeys(fn (string $key) => [$key => self::label($key)])->all();
    }

    /**
     * The overdue buckets only — `current` is not an ageing bucket, it is the absence of one.
     *
     * @return array<string, string>
     */
    public static function overdueLabels(): array
    {
        return collect(self::labels())->except(self::CURRENT)->all();
    }
}
