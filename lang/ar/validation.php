<?php

/*
|--------------------------------------------------------------------------
| Validation messages — Arabic
|--------------------------------------------------------------------------
| Laravel ships ONLY the English catalogue. With no `lang/ar/validation.php`,
| `__('validation.required')` fell through to the framework's English string —
| so an Arabic operator who left a field blank on ANY form in ANY resource got
| "The :attribute field is required." That is every form in the panel, both
| portals and the API, and it was invisible to a translation sweep that only
| looked at our own admin.php catalogues.
|
| `:attribute` is substituted with the FIELD LABEL, which Filament already
| passes through `__()` — so the sentence around it is the only part that was
| ever missing.
|
| Key structure mirrors the framework file exactly (including the between/gt/
| gte/lt/lte/max/min/size sub-arrays keyed by type). `TranslationKeyConformanceTest`
| fails the build if the two catalogues drift apart, so a Laravel upgrade that
| adds a rule cannot silently reintroduce an English message.
*/

return [
    'accepted' => 'يجب قبول :attribute.',
    'accepted_if' => 'يجب قبول :attribute عندما يكون :other هو :value.',
    'active_url' => 'حقل :attribute يجب أن يكون رابطًا صحيحًا.',
    'after' => 'حقل :attribute يجب أن يكون تاريخًا بعد :date.',
    'after_or_equal' => 'حقل :attribute يجب أن يكون تاريخًا بعد أو يساوي :date.',
    'alpha' => 'حقل :attribute يجب أن يحتوي على حروف فقط.',
    'alpha_dash' => 'حقل :attribute يجب أن يحتوي على حروف وأرقام وشرطات وشرطات سفلية فقط.',
    'alpha_num' => 'حقل :attribute يجب أن يحتوي على حروف وأرقام فقط.',
    'any_of' => 'حقل :attribute غير صحيح.',
    'array' => 'حقل :attribute يجب أن يكون مصفوفة.',
    'ascii' => 'حقل :attribute يجب أن يحتوي على حروف ورموز أحادية البايت فقط.',
    'before' => 'حقل :attribute يجب أن يكون تاريخًا قبل :date.',
    'before_or_equal' => 'حقل :attribute يجب أن يكون تاريخًا قبل أو يساوي :date.',
    'between' => [
        'array' => 'حقل :attribute يجب أن يحتوي على عدد عناصر بين :min و :max.',
        'file' => 'حقل :attribute يجب أن يكون بين :min و :max كيلوبايت.',
        'numeric' => 'حقل :attribute يجب أن يكون بين :min و :max.',
        'string' => 'حقل :attribute يجب أن يكون بين :min و :max حرفًا.',
    ],
    'boolean' => 'حقل :attribute يجب أن يكون صحيحًا أو خطأً.',
    'can' => 'حقل :attribute يحتوي على قيمة غير مصرح بها.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'contains' => 'حقل :attribute تنقصه قيمة مطلوبة.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => 'حقل :attribute يجب أن يكون تاريخًا صحيحًا.',
    'date_equals' => 'حقل :attribute يجب أن يكون تاريخًا يساوي :date.',
    'date_format' => 'حقل :attribute لا يطابق الصيغة :format.',
    'decimal' => 'حقل :attribute يجب أن يحتوي على :decimal منزلة عشرية.',
    'declined' => 'يجب رفض :attribute.',
    'declined_if' => 'يجب رفض :attribute عندما يكون :other هو :value.',
    'different' => 'حقل :attribute و :other يجب أن يكونا مختلفين.',
    'digits' => 'حقل :attribute يجب أن يتكوّن من :digits رقمًا.',
    'digits_between' => 'حقل :attribute يجب أن يتكوّن من عدد أرقام بين :min و :max.',
    'dimensions' => 'حقل :attribute يحتوي على أبعاد صورة غير صحيحة.',
    'distinct' => 'حقل :attribute يحتوي على قيمة مكررة.',
    'doesnt_contain' => 'حقل :attribute يجب ألا يحتوي على أي من: :values.',
    'doesnt_end_with' => 'حقل :attribute يجب ألا ينتهي بأي من: :values.',
    'doesnt_start_with' => 'حقل :attribute يجب ألا يبدأ بأي من: :values.',
    'email' => 'حقل :attribute يجب أن يكون بريدًا إلكترونيًا صحيحًا.',
    'encoding' => 'حقل :attribute يجب أن يستخدم ترميز :encoding.',
    'ends_with' => 'حقل :attribute يجب أن ينتهي بأحد التالي: :values.',
    'enum' => 'القيمة المختارة في :attribute غير صحيحة.',
    'exists' => 'القيمة المختارة في :attribute غير صحيحة.',
    'extensions' => 'حقل :attribute يجب أن يحمل أحد الامتدادات التالية: :values.',
    'file' => 'حقل :attribute يجب أن يكون ملفًا.',
    'filled' => 'حقل :attribute مطلوب.',
    'gt' => [
        'array' => 'حقل :attribute يجب أن يحتوي على أكثر من :value عنصرًا.',
        'file' => 'حقل :attribute يجب أن يكون أكبر من :value كيلوبايت.',
        'numeric' => 'حقل :attribute يجب أن يكون أكبر من :value.',
        'string' => 'حقل :attribute يجب أن يكون أكبر من :value حرفًا.',
    ],
    'gte' => [
        'array' => 'حقل :attribute يجب أن يحتوي على :value عنصرًا أو أكثر.',
        'file' => 'حقل :attribute يجب أن يكون أكبر من أو يساوي :value كيلوبايت.',
        'numeric' => 'حقل :attribute يجب أن يكون أكبر من أو يساوي :value.',
        'string' => 'حقل :attribute يجب أن يكون أكبر من أو يساوي :value حرفًا.',
    ],
    'hex_color' => 'حقل :attribute يجب أن يكون لونًا سداسيًا صحيحًا.',
    'image' => 'حقل :attribute يجب أن يكون صورة.',
    'in' => 'القيمة المختارة في :attribute غير صحيحة.',
    'in_array' => 'حقل :attribute يجب أن يكون موجودًا ضمن :other.',
    'in_array_keys' => 'حقل :attribute يجب أن يحتوي على أحد المفاتيح التالية على الأقل: :values.',
    'integer' => 'حقل :attribute يجب أن يكون رقمًا صحيحًا.',
    'ip' => 'حقل :attribute يجب أن يكون عنوان IP صحيحًا.',
    'ipv4' => 'حقل :attribute يجب أن يكون عنوان IPv4 صحيحًا.',
    'ipv6' => 'حقل :attribute يجب أن يكون عنوان IPv6 صحيحًا.',
    'json' => 'حقل :attribute يجب أن يكون نص JSON صحيحًا.',
    'list' => 'حقل :attribute يجب أن يكون قائمة.',
    'lowercase' => 'حقل :attribute يجب أن يكون بحروف صغيرة.',
    'lt' => [
        'array' => 'حقل :attribute يجب أن يحتوي على أقل من :value عنصرًا.',
        'file' => 'حقل :attribute يجب أن يكون أقل من :value كيلوبايت.',
        'numeric' => 'حقل :attribute يجب أن يكون أقل من :value.',
        'string' => 'حقل :attribute يجب أن يكون أقل من :value حرفًا.',
    ],
    'lte' => [
        'array' => 'حقل :attribute يجب ألا يحتوي على أكثر من :value عنصرًا.',
        'file' => 'حقل :attribute يجب أن يكون أقل من أو يساوي :value كيلوبايت.',
        'numeric' => 'حقل :attribute يجب أن يكون أقل من أو يساوي :value.',
        'string' => 'حقل :attribute يجب أن يكون أقل من أو يساوي :value حرفًا.',
    ],
    'mac_address' => 'حقل :attribute يجب أن يكون عنوان MAC صحيحًا.',
    'max' => [
        'array' => 'حقل :attribute يجب ألا يحتوي على أكثر من :max عنصرًا.',
        'file' => 'حقل :attribute يجب ألا يزيد عن :max كيلوبايت.',
        'numeric' => 'حقل :attribute يجب ألا يزيد عن :max.',
        'string' => 'حقل :attribute يجب ألا يزيد عن :max حرفًا.',
    ],
    'max_digits' => 'حقل :attribute يجب ألا يحتوي على أكثر من :max رقمًا.',
    'mimes' => 'حقل :attribute يجب أن يكون ملفًا من نوع: :values.',
    'mimetypes' => 'حقل :attribute يجب أن يكون ملفًا من نوع: :values.',
    'min' => [
        'array' => 'حقل :attribute يجب أن يحتوي على :min عنصرًا على الأقل.',
        'file' => 'حقل :attribute يجب أن يكون :min كيلوبايت على الأقل.',
        'numeric' => 'حقل :attribute يجب أن يكون :min على الأقل.',
        'string' => 'حقل :attribute يجب أن يكون :min حرفًا على الأقل.',
    ],
    'min_digits' => 'حقل :attribute يجب أن يحتوي على :min رقمًا على الأقل.',
    'missing' => 'حقل :attribute يجب ألا يكون موجودًا.',
    'missing_if' => 'حقل :attribute يجب ألا يكون موجودًا عندما يكون :other هو :value.',
    'missing_unless' => 'حقل :attribute يجب ألا يكون موجودًا إلا إذا كان :other هو :value.',
    'missing_with' => 'حقل :attribute يجب ألا يكون موجودًا عند وجود :values.',
    'missing_with_all' => 'حقل :attribute يجب ألا يكون موجودًا عند وجود :values.',
    'multiple_of' => 'حقل :attribute يجب أن يكون من مضاعفات :value.',
    'not_in' => 'القيمة المختارة في :attribute غير صحيحة.',
    'not_regex' => 'صيغة حقل :attribute غير صحيحة.',
    'numeric' => 'حقل :attribute يجب أن يكون رقمًا.',
    'password' => [
        'letters' => 'حقل :attribute يجب أن يحتوي على حرف واحد على الأقل.',
        'mixed' => 'حقل :attribute يجب أن يحتوي على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'حقل :attribute يجب أن يحتوي على رقم واحد على الأقل.',
        'symbols' => 'حقل :attribute يجب أن يحتوي على رمز واحد على الأقل.',
        'uncompromised' => 'ظهر :attribute المُدخل ضمن تسريب بيانات. يُرجى اختيار :attribute مختلف.',
    ],
    'present' => 'حقل :attribute يجب أن يكون موجودًا.',
    'present_if' => 'حقل :attribute يجب أن يكون موجودًا عندما يكون :other هو :value.',
    'present_unless' => 'حقل :attribute يجب أن يكون موجودًا إلا إذا كان :other هو :value.',
    'present_with' => 'حقل :attribute يجب أن يكون موجودًا عند وجود :values.',
    'present_with_all' => 'حقل :attribute يجب أن يكون موجودًا عند وجود :values.',
    'prohibited' => 'حقل :attribute محظور.',
    'prohibited_if' => 'حقل :attribute محظور عندما يكون :other هو :value.',
    'prohibited_if_accepted' => 'حقل :attribute محظور عند قبول :other.',
    'prohibited_if_declined' => 'حقل :attribute محظور عند رفض :other.',
    'prohibited_unless' => 'حقل :attribute محظور إلا إذا كان :other ضمن :values.',
    'prohibits' => 'حقل :attribute يمنع وجود :other.',
    'regex' => 'صيغة حقل :attribute غير صحيحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_array_keys' => 'حقل :attribute يجب أن يحتوي على مدخلات لـ: :values.',
    'required_if' => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_if_accepted' => 'حقل :attribute مطلوب عند قبول :other.',
    'required_if_declined' => 'حقل :attribute مطلوب عند رفض :other.',
    'required_unless' => 'حقل :attribute مطلوب إلا إذا كان :other ضمن :values.',
    'required_with' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_with_all' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_without' => 'حقل :attribute مطلوب عند عدم وجود :values.',
    'required_without_all' => 'حقل :attribute مطلوب عند عدم وجود أي من :values.',
    'same' => 'حقل :attribute و :other يجب أن يتطابقا.',
    'size' => [
        'array' => 'حقل :attribute يجب أن يحتوي على :size عنصرًا.',
        'file' => 'حقل :attribute يجب أن يكون :size كيلوبايت.',
        'numeric' => 'حقل :attribute يجب أن يكون :size.',
        'string' => 'حقل :attribute يجب أن يكون :size حرفًا.',
    ],
    'starts_with' => 'حقل :attribute يجب أن يبدأ بأحد التالي: :values.',
    'string' => 'حقل :attribute يجب أن يكون نصًا.',
    'timezone' => 'حقل :attribute يجب أن يكون منطقة زمنية صحيحة.',
    'unique' => 'قيمة :attribute مستخدمة من قبل.',
    'uploaded' => 'فشل رفع :attribute.',
    'uppercase' => 'حقل :attribute يجب أن يكون بحروف كبيرة.',
    'url' => 'حقل :attribute يجب أن يكون رابطًا صحيحًا.',
    'ulid' => 'حقل :attribute يجب أن يكون ULID صحيحًا.',
    'uuid' => 'حقل :attribute يجب أن يكون UUID صحيحًا.',

    /*
    |--------------------------------------------------------------------------
    | Custom validation language lines
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'رسالة مخصصة',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom validation attributes
    |--------------------------------------------------------------------------
    | Left empty deliberately: Filament passes the field's own translated label
    | as :attribute, so duplicating labels here would create a second, silently
    | diverging set of Arabic field names.
    */

    'attributes' => [],
];
