<?php

return [
    'owner_requests' => [
        // الأولويات الثلاث التي يحددها المالك. كانت تُشتق من قيمة العمود عبر Str::headline()،
        // فتنتج كلمة إنجليزية لا يراها أي فحص للترجمة.
        'priorities' => [
            'low' => 'منخفضة',
            'medium' => 'متوسطة',
            'high' => 'عالية',
        ],
        'conversation' => 'المحادثة',
        'replies' => 'الردود',
        'set_status' => 'تغيير الحالة (اختياري)',
        'set_status_hint' => 'اتركها كما هي للرد فقط. الحل أو الإغلاق يُنهي الطلب.',
        'unknown_author' => 'غير معروف',
        'actions' => [
            'conversation' => 'قراءة المحادثة',
            'close' => 'إغلاق',
            'reply' => 'رد',
            'send' => 'إرسال الرد',
            'your_reply' => 'ردّك',
        ],
        'statuses' => [
            'open' => 'مفتوح',
            'in_progress' => 'قيد المعالجة',
            'resolved' => 'تم الحل',
            'closed' => 'مغلق',
            'cancelled' => 'ملغى',
        ],
        'notices' => [
            'replied' => 'تم إرسال الرد',
            'replied_body' => 'ردّك مُدرَج في المحادثة — الحالة: :status.',
        ],
        'errors' => [
            'terminal' => 'هذا الطلب مغلق ولا يمكن الرد عليه.',
            'empty_reply' => 'اكتب ردًا قبل الإرسال.',
        ],
    ],
];
