<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * The rails Atriom shipped with, plus the Egyptian ones it did not.
 *
 * The seven active codes are exactly the union of the four registries this replaced, so a database
 * that runs this behaves identically to one that does not: `ValueSets` keeps the same literal
 * values as its floor, and every code here is already in it.
 *
 * The four INACTIVE rows are the point of the change. Fawry, Meeza, Aman and Vodafone Cash are
 * ordinary in Egyptian retail and none of them existed here; shipping them switched off means an
 * operator turns one on with a tick instead of waiting for a deploy, and turning one on cannot
 * change anything already posted.
 *
 * `ledger_account_id` is null on every row. That is the floor — `cash` for cash, `bank` for the rest —
 * and it reproduces the ternary the journalizers carried. Pointing a rail at a clearing account is
 * the accountant's call and needs the real Egyptian chart, which has not been supplied.
 *
 * Idempotent on `code` so a reseed never doubles a rail, and `updateOrCreate` deliberately does NOT
 * touch `is_active` or `ledger_account_id` on an existing row: an operator who retired a rail or pointed
 * one at a clearing account must not have that undone by a deploy.
 */
class PaymentMethodSeeder extends Seeder
{
    /**
     * code, EN, AR, inbound, outbound, settlement days, active, sort
     *
     * @var array<int, array{0:string,1:string,2:string,3:bool,4:bool,5:int,6:bool,7:int}>
     */
    private const RAILS = [
        ['cash',          'Cash',            'نقدًا',                 true,  true,  0, true,  10],
        ['bank_transfer', 'Bank transfer',   'تحويل بنكي',            true,  true,  1, true,  20],
        ['cheque',        'Cheque',          'شيك',                   true,  true,  3, true,  30],
        // Outbound TOO: `card` is in the `vendor_bill_payments.method` floor, so a historical
        // bill paid by card must still validate on that picker.
        ['card',          'Card',            'بطاقة',                 true,  true,  2, true,  40],
        ['instapay',      'InstaPay',        'إنستاباي',              true,  true,  0, true,  50],
        ['wallet',        'Mobile wallet',   'محفظة إلكترونية',       true,  false, 1, true,  60],
        ['other',         'Other',           'أخرى',                  true,  true,  0, true,  99],

        // Present and switched OFF — a tick, not a deploy.
        ['fawry',         'Fawry',           'فوري',                  true,  false, 2, false, 70],
        ['meeza',         'Meeza',           'ميزة',                  true,  false, 2, false, 71],
        ['vodafone_cash', 'Vodafone Cash',   'فودافون كاش',           true,  false, 1, false, 72],
        ['aman',          'Aman',            'أمان',                  true,  false, 2, false, 73],
    ];

    public function run(): void
    {
        foreach (self::RAILS as [$code, $en, $ar, $in, $out, $days, $active, $sort]) {
            $existing = PaymentMethod::query()->where('code', $code)->first();

            if ($existing !== null) {
                // Names and direction are ours to correct; the operator's own decisions are not.
                $existing->fill([
                    'name_en' => $en,
                    'name_ar' => $ar,
                    'for_inbound' => $in,
                    'for_outbound' => $out,
                    'sort_order' => $sort,
                ])->save();

                continue;
            }

            PaymentMethod::create([
                'code' => $code,
                'name_en' => $en,
                'name_ar' => $ar,
                'for_inbound' => $in,
                'for_outbound' => $out,
                'settlement_days' => $days,
                'is_active' => $active,
                'sort_order' => $sort,
            ]);
        }
    }
}
