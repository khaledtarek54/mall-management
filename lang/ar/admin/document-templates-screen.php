<?php

return [
    'document_templates_screen' => [
        'singular' => 'نص مستند',
        'plural' => 'نصوص المستندات',
        'house_default' => 'كل العقارات (النص الافتراضي)',
        'languages' => 'مكتوب بـ',
        'blocks' => [
            'invoice_footer' => 'الفاتورة — التذييل',
            'invoice_payment_instructions' => 'الفاتورة — طرق السداد',
            'invoice_terms' => 'الفاتورة — الشروط',
            'invoice_email_body' => 'بريد الفاتورة — نص التقديم',
            'dunning_overdue_reminder' => 'تذكير التأخر — بريد',
            'dunning_overdue_subject' => 'تذكير التأخر — عنوان الرسالة',
            'dunning_final_notice' => 'إنذار نهائي — بريد',
            'dunning_final_subject' => 'إنذار نهائي — عنوان الرسالة',
            'dunning_late_fee_subject' => 'احتساب غرامة تأخير — عنوان الرسالة',
            'receipt_payment_subject' => 'استلام دفعة — عنوان الرسالة',
            'lease_expiry_subject' => 'قرب انتهاء العقد — عنوان الرسالة',
            'dunning_late_fee_applied' => 'احتساب غرامة تأخير — بريد',
            'receipt_payment_received' => 'استلام دفعة — بريد',
            'lease_expiry_approaching' => 'قرب انتهاء العقد — بريد',
        ],
        'sections' => [
            'which' => 'أي نص',
            'wording' => 'صياغتك',
            'wording_description' => 'نص عادي. تُحفظ الأسطر كما كتبتها. واترك إحدى اللغتين فارغة فتُستخدم الأخرى.',
        ],
        'help' => [
            'key' => 'موضع ظهور النص. نص واحد لكل عقار، ولا يمكن تغيير الموضع لاحقًا.',
            'asset' => 'اتركه فارغًا ليسري على كل العقارات. واختر عقارًا لتجاوز النص الافتراضي فيه وحده.',
            'body' => 'يقبل تذييل الفاتورة {days} — ويُستبدل بمهلة السداد بالأيام.',
            'is_active' => 'الإيقاف يُعيد المستند إلى النص الافتراضي، أو إلى الصياغة المدمجة.',
        ],
    ],
];
