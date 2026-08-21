<?php

namespace Database\Seeders;

use App\Enums\TenantRequestType;
use App\Models\TenantRequestSubcategory;
use App\Models\Trade;
use Illuminate\Database\Seeder;

/**
 * What a tenant may report, and which trade each maintenance problem belongs to.
 *
 * The existing subcategories are seeded exactly as the enum listed them, each linked to its trade
 * where one exists — so a database that runs this behaves identically to one that does not, and the
 * seven codes that already string-matched a trade now match it by FOREIGN KEY instead.
 *
 * **The seven additions are the point of EG-13's sibling finding (D-3):** `elevator`, `generator`,
 * `fire_safety`, `pest_control`, `security`, `landscaping` and `waste` are trades the operator can
 * dispatch and a tenant could not name. They are seeded **ACTIVE**, unlike the switched-off rows in
 * the payment-rail and expense-category catalogues, and the difference is deliberate: activating one
 * of those changes ACCOUNTING, while activating one of these only lets a tenant describe a fault
 * more precisely. The status quo — reporting a stuck lift as "other" and raising a work order with
 * no trade at all — is the worse default.
 *
 * `fire_safety` links to the `fire-safety` trade. The codes differ by a hyphen, which under the old
 * string match would have resolved to nothing; the link is what makes that stop mattering.
 *
 * Idempotent on (request_type, code), and deliberately does not touch `is_active` or `trade_id` on
 * an existing row — an operator who retired a subcategory or re-pointed one must not have that
 * undone by a deploy.
 */
class TenantRequestSubcategorySeeder extends Seeder
{
    /**
     * type => [code => [EN, AR, trade code or null]]
     *
     * @var array<string, array<string, array{0:string,1:string,2:?string}>>
     */
    private const SUBCATEGORIES = [
        'maintenance' => [
            'electrical' => ['Electrical', 'كهرباء', 'electrical'],
            'plumbing' => ['Plumbing', 'سباكة', 'plumbing'],
            'hvac' => ['Air conditioning', 'تكييف', 'hvac'],
            'structural' => ['Structural', 'إنشائي', 'structural'],
            'cleaning' => ['Cleaning', 'نظافة', 'cleaning'],
            'safety' => ['Safety', 'سلامة', 'safety'],
            // The seven a tenant could not report. Each is a trade the operator already dispatches.
            'elevator' => ['Lift / escalator', 'مصعد أو سلم كهربائي', 'elevator'],
            'generator' => ['Generator / power', 'مولد كهرباء', 'generator'],
            'fire_safety' => ['Fire safety', 'مكافحة الحريق', 'fire-safety'],
            'pest_control' => ['Pests', 'مكافحة آفات', 'pest_control'],
            'security' => ['Security', 'أمن', 'security'],
            'landscaping' => ['Landscaping', 'تنسيق حدائق', 'landscaping'],
            'waste' => ['Waste', 'مخلفات', 'waste'],
            'other' => ['Other', 'أخرى', 'other'],
        ],
        'access' => [
            'keys_cards' => ['Keys & cards', 'مفاتيح وبطاقات', null],
            'parking' => ['Parking', 'مواقف', null],
            'after_hours' => ['After hours', 'خارج ساعات العمل', null],
            'visitor' => ['Visitor', 'زائر', null],
            'delivery' => ['Delivery', 'توصيل', null],
        ],
        'document' => [
            'lease_copy' => ['Lease copy', 'نسخة من العقد', null],
            'renewal' => ['Renewal', 'تجديد', null],
            'termination_notice' => ['Termination notice', 'إخطار إنهاء', null],
            'noc_certificate' => ['NOC certificate', 'شهادة عدم ممانعة', null],
        ],
        'permit' => [
            'fit_out' => ['Fit-out', 'تشطيب', null],
            'temporary_installation' => ['Temporary installation', 'تركيب مؤقت', null],
            'signage' => ['Signage', 'لافتات', null],
            'other' => ['Other', 'أخرى', null],
        ],
        'complaint' => [
            'noise' => ['Noise', 'ضوضاء', null],
            'cleanliness' => ['Cleanliness', 'نظافة', null],
            'conduct' => ['Conduct', 'سلوك', null],
            'other' => ['Other', 'أخرى', null],
        ],
    ];

    public function run(): void
    {
        $trades = Trade::query()->pluck('id', 'code')->all();

        foreach (self::SUBCATEGORIES as $type => $codes) {
            // Guard against a type that has been removed from the enum: seeding a subcategory under
            // one would create a row no form can ever reach.
            if (TenantRequestType::tryFrom($type) === null) {
                continue;
            }

            $sort = 0;

            foreach ($codes as $code => [$en, $ar, $tradeCode]) {
                $sort += 10;

                $existing = TenantRequestSubcategory::query()
                    ->where('request_type', $type)
                    ->where('code', $code)
                    ->first();

                if ($existing !== null) {
                    $existing->fill(['name_en' => $en, 'name_ar' => $ar, 'sort_order' => $sort])->save();

                    continue;
                }

                TenantRequestSubcategory::create([
                    'request_type' => $type,
                    'code' => $code,
                    'name_en' => $en,
                    'name_ar' => $ar,
                    // Null when the trade register does not have it — the link is an improvement,
                    // never a requirement, and an operator who renamed a trade must not break seeding.
                    'trade_id' => $tradeCode === null ? null : ($trades[$tradeCode] ?? null),
                    'sort_order' => $sort,
                ]);
            }
        }
    }
}
