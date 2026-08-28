<?php

/**
 * ملف لغة بوابة المقاولين — المقاول ليس مشغّلاً ولا مستأجراً، لذا تعيش صياغته منفصلة.
 */

return [
    'notifications' => [
        'dispatched_title' => 'عمل جديد لك',
        'dispatched_body' => ':reference — :title، في :property.',
    ],
    'jobs' => [
        'quote' => 'إرسال عرض سعر',
        'quote_confirm' => 'أرسل سعراً لهذا العمل. يوافق عليه المشغّل أو يرفضه، ولا يُلتزم بشيء قبل ذلك.',
        'quote_supplementary' => 'عمل إضافي فوق سعر متفق عليه',
        'quote_supplementary_helper' => 'اتركه مغلقاً لسعر العمل كاملاً. شغّله فقط إذا كان هناك سعر متفق عليه ووجدت عملاً إضافياً.',
        'quote_labour' => 'العمالة',
        'quote_material' => 'المواد',
        'quote_service' => 'مقاولة من الباطن / تأجير',
        'quote_amounts_helper' => 'بدون الضريبة. يجب أن يكون أحدها أكبر من صفر على الأقل.',
        'quote_scope' => 'ما يغطيه السعر',
        'quote_sent' => 'تم إرسال عرض السعر.',
        'evidence' => 'الصور',
        'evidence_helper' => 'صور العمل بعد إنجازه. تُضاف الصور الجديدة إلى الصور المرفقة سابقاً.',
        'evidence_attached' => 'تم إرفاق الصور.',
        'update' => 'إضافة تحديث',
        'update_body' => 'التحديث',
        'update_posted' => 'تم نشر التحديث.',
        'singular' => 'أمر عمل',
        'plural' => 'أعمالي',
        'reference' => 'المرجع',
        'title' => 'العمل',
        'property' => 'العقار',
        'priority' => 'الأولوية',
        'scheduled_for' => 'موعد التنفيذ',
        'status' => 'الحالة',
        'accepted_at' => 'تاريخ القبول',
        'not_accepted' => 'لم يُقبل بعد',
        'accept' => 'قبول',
        'accept_confirm' => 'القبول يؤكد استلامك لهذا العمل ويبدأ زمن الاستجابة المتفق عليه.',
        'accepted' => 'تم قبول العمل.',
        'accept_closed' => 'هذا العمل مغلق — لم يعد هناك ما يُقبل.',
        'empty_heading' => 'لا توجد أعمال بعد',
        'empty_description' => 'تظهر الأعمال هنا فور إسنادها إليك.',
    ],
];
