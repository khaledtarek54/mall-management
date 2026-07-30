<?php

namespace App\Support;

/**
 * Egypt's 27 governorates, for the ETA receiver address.
 *
 * A fixed list rather than a free-text box because this value goes onto a legal
 * tax document that the Egyptian Tax Authority validates: "Cairo", "cairo",
 * "القاهرة" and "Cairo Governorate" are four spellings of one place, and only some
 * of them are accepted. Typed freehand across hundreds of tenants they would all
 * appear.
 *
 * A PHP constant, not a database enum (project rule) and not a settings table:
 * the list changes when Egypt redraws its governorates, which is a legislative
 * event, not operator configuration. When that happens, edit this array — nothing
 * migrates, because the column is a string.
 *
 * The KEY is what is filed with ETA (English, as ETA's own documentation writes
 * it); the label is what the operator picks in their own language.
 */
class EgyptGovernorates
{
    /** @var array<string, array{en: string, ar: string}> */
    public const ALL = [
        'Cairo' => ['en' => 'Cairo', 'ar' => 'القاهرة'],
        'Giza' => ['en' => 'Giza', 'ar' => 'الجيزة'],
        'Alexandria' => ['en' => 'Alexandria', 'ar' => 'الإسكندرية'],
        'Qalyubia' => ['en' => 'Qalyubia', 'ar' => 'القليوبية'],
        'Port Said' => ['en' => 'Port Said', 'ar' => 'بورسعيد'],
        'Suez' => ['en' => 'Suez', 'ar' => 'السويس'],
        'Dakahlia' => ['en' => 'Dakahlia', 'ar' => 'الدقهلية'],
        'Sharqia' => ['en' => 'Sharqia', 'ar' => 'الشرقية'],
        'Gharbia' => ['en' => 'Gharbia', 'ar' => 'الغربية'],
        'Monufia' => ['en' => 'Monufia', 'ar' => 'المنوفية'],
        'Beheira' => ['en' => 'Beheira', 'ar' => 'البحيرة'],
        'Kafr El Sheikh' => ['en' => 'Kafr El Sheikh', 'ar' => 'كفر الشيخ'],
        'Damietta' => ['en' => 'Damietta', 'ar' => 'دمياط'],
        'Ismailia' => ['en' => 'Ismailia', 'ar' => 'الإسماعيلية'],
        'Faiyum' => ['en' => 'Faiyum', 'ar' => 'الفيوم'],
        'Beni Suef' => ['en' => 'Beni Suef', 'ar' => 'بني سويف'],
        'Minya' => ['en' => 'Minya', 'ar' => 'المنيا'],
        'Asyut' => ['en' => 'Asyut', 'ar' => 'أسيوط'],
        'Sohag' => ['en' => 'Sohag', 'ar' => 'سوهاج'],
        'Qena' => ['en' => 'Qena', 'ar' => 'قنا'],
        'Luxor' => ['en' => 'Luxor', 'ar' => 'الأقصر'],
        'Aswan' => ['en' => 'Aswan', 'ar' => 'أسوان'],
        'Red Sea' => ['en' => 'Red Sea', 'ar' => 'البحر الأحمر'],
        'New Valley' => ['en' => 'New Valley', 'ar' => 'الوادي الجديد'],
        'Matrouh' => ['en' => 'Matrouh', 'ar' => 'مطروح'],
        'North Sinai' => ['en' => 'North Sinai', 'ar' => 'شمال سيناء'],
        'South Sinai' => ['en' => 'South Sinai', 'ar' => 'جنوب سيناء'],
    ];

    /** Select options in the current locale, keyed by the value filed with ETA. */
    public static function options(): array
    {
        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';

        return array_map(fn (array $names) => $names[$locale], self::ALL);
    }

    /** The values ETA will accept — used by the form's `in:` validation. */
    public static function values(): array
    {
        return array_keys(self::ALL);
    }
}
