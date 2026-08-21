<?php

namespace Database\Seeders;

use App\Models\VendorDocument;
use App\Models\VendorDocumentType;
use Illuminate\Database\Seeder;

/**
 * The six types `VendorDocument`'s constants held, as rows.
 *
 * Names come from the EXISTING `admin.vendors.documents.types` group and `blocks_dispatch` is set
 * from `VendorDocument::BLOCKING_TYPES` — so a database that runs this dispatches exactly the
 * vendors a database that does not would. Nothing changes on deploy; what changes is that the
 * operator can now revise it.
 *
 * Idempotent on `code`, and deliberately does not touch `is_active` or `blocks_dispatch` on a row
 * that already exists. Both are rulings: an operator who decided a lapsed social-insurance
 * certificate blocks site work must not have that quietly reverted by the next deploy.
 */
class VendorDocumentTypeSeeder extends Seeder
{
    /**
     * code => [EN, AR]
     *
     * @var array<string, array{0:string,1:string}>
     */
    private const TYPES = [
        VendorDocument::TYPE_INSURANCE_COI => ['Insurance certificate (COI)', 'شهادة تأمين'],
        VendorDocument::TYPE_TAX_CARD => ['Tax card', 'بطاقة ضريبية'],
        VendorDocument::TYPE_COMMERCIAL_REGISTER => ['Commercial register', 'سجل تجاري'],
        VendorDocument::TYPE_SOCIAL_INSURANCE => ['Social insurance certificate', 'شهادة تأمينات اجتماعية'],
        VendorDocument::TYPE_TRADE_LICENSE => ['Trade licence', 'رخصة مزاولة'],
        VendorDocument::TYPE_OTHER => ['Other', 'أخرى'],
    ];

    public function run(): void
    {
        $sort = 0;

        foreach (self::TYPES as $code => [$en, $ar]) {
            $sort += 10;

            $existing = VendorDocumentType::query()->where('code', $code)->first();

            if ($existing !== null) {
                $existing->fill(['name_en' => $en, 'name_ar' => $ar, 'sort_order' => $sort])->save();

                continue;
            }

            VendorDocumentType::create([
                'code' => $code,
                'name_en' => $en,
                'name_ar' => $ar,
                'blocks_dispatch' => in_array($code, VendorDocument::BLOCKING_TYPES, true),
                'sort_order' => $sort,
            ]);
        }
    }
}
