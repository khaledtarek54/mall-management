<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('billing.late_fee_percent', (float) env('LATE_FEE_PERCENT', 2.0));
        $this->migrator->add('billing.late_fee_grace_days', (int) env('LATE_FEE_GRACE_DAYS', 7));
        $this->migrator->add('billing.late_fee_minimum', (float) env('LATE_FEE_MINIMUM', 50.00));

        $this->migrator->add('billing.monthly_billing_day', (int) env('MONTHLY_BILLING_DAY', 1));
        $this->migrator->add('billing.monthly_billing_time', env('MONTHLY_BILLING_TIME', '02:00'));

        $this->migrator->add('billing.cam_reconciliation_month', (int) env('CAM_RECONCILIATION_MONTH', 1));
        $this->migrator->add('billing.cam_reconciliation_day', (int) env('CAM_RECONCILIATION_DAY', 15));
        $this->migrator->add('billing.cam_reconciliation_time', env('CAM_RECONCILIATION_TIME', '03:00'));
    }
};
