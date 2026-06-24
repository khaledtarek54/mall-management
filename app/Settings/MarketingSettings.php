<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Marketing module configuration (FR MKT-2). The levy rate is operator-tunable
 * from /admin/settings. The rate in force is captured on each marketing Charge
 * at creation time, so changing it never alters historical charges (the
 * "versioned / effective-dated" requirement is met by per-charge capture
 * rather than a separate rates table).
 */
class MarketingSettings extends Settings
{
    /** Marketing Fund / Promotional Levy as a percentage of each lease's base rent. */
    public float $levy_rate_percent = 5.0;

    public static function group(): string
    {
        return 'marketing';
    }
}
