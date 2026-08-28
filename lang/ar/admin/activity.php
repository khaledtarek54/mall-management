<?php

return [
    'activity' => [
        'nav_label' => 'سجل النشاط',
        'page_title' => 'سجل النشاط',
        'when' => 'الوقت',
        'who' => 'بواسطة',
        'what' => 'النوع',
        'record' => 'السجل',
        'event' => 'الإجراء',
        'changes' => 'التغييرات',
        'subject' => 'الموضوع',
        'system' => 'النظام',
        'subjects' => [
            'work_order_proposal' => 'عرض سعر لأمر عمل',
            'failure_code' => 'كود عطل',
            'holiday' => 'إجازة',
            'payment_method' => 'قناة سداد',
            'retail_category' => 'تصنيف تجاري',
            'expense_category' => 'بند مصروف',
            'tenant_request_subcategory' => 'تصنيف فرعي للطلب',
            'document_template' => 'نص مستند',
            'payroll_rate' => 'نسبة رواتب',
            'recurring_expense' => 'مصروف دوري',
            'custom_field' => 'حقل مخصص',
            'violation_category' => 'بند مخالفة',
            'vendor_document_type' => 'نوع مستند مورّد',
            'work_order_labour' => 'عمالة أمر عمل',
            'trade' => 'تخصص',
            'unit_ownership' => 'ملكية وحدة',
            // spatie's fallback log name. Every model now declares its own, but rows written
            // BEFORE that fix are still filed under `default` and must still read as something.
            'default' => 'أخرى',
            'property_setting' => 'إعداد عقار',
            'tax_code' => 'كود ضريبي',
            'tax_rate' => 'نسبة ضريبية',
            'utility_tariff' => 'تعريفة مرافق',
            'utility_tariff_rate' => 'سعر مرافق',
            'bank_match' => 'مطابقة بنكية',
            'bank_account' => 'حساب بنكي',
            'bank_statement' => 'كشف بنكي',
            'charge_code' => 'كود رسوم',
            'rent_index' => 'رقم قياسي',
            'lease_clause' => 'بند العقد',
            'work_permit' => 'تصريح عمل',
            'tenant_document' => 'مستند مستأجر',
            'account_mapping' => 'سطر خريطة الترحيل',
            'floor' => 'طابق',
            'rentable_item' => 'عنصر مؤجَّر',
            'marketing_post' => 'منشور تسويقي',
            'lease' => 'عقد إيجار',
            'lease_option' => 'خيار تعاقدي',
            'invoice' => 'فاتورة',
            'payment' => 'دفعة',
            'tenant' => 'مستأجر',
            'charge' => 'بند مالي',
            'asset' => 'عقار',
            'tenant_sales' => 'إقرار مبيعات',
            'cam_pool' => 'مجمع مصروفات',
            'credit_note' => 'إشعار خصم',
            'vendor' => 'مورد',
            'vendor_contract' => 'عقد مورد',
            'note' => 'ملاحظة',
            'marketing_budget' => 'ميزانية تسويق',
            'marketing_spend' => 'مصروف تسويق',
            'department' => 'قسم',
            'user' => 'مستخدم',
            'owner_request' => 'طلب مالك',
            'access_control' => 'التحكم في الصلاحيات',
            'tenant_request' => 'طلب مستأجر',
            'tenant_sales_declaration' => 'إقرار مبيعات',
            'cam_expense_pool' => 'مجمع مصروفات مشتركة',
            'expense' => 'مصروف',
            'vendor_bill' => 'فاتورة مورد',
            'journal_entry' => 'قيد يومية',
            'ledger_account' => 'حساب أستاذ',
            'deposit_transaction' => 'حركة تأمين',
            'fixed_asset' => 'أصل ثابت',
            'fixed_asset_disposal' => 'استبعاد أصل',
            'depreciation_entry' => 'قيد إهلاك',
            'employee' => 'موظف',
            'employee_advance' => 'سلفة موظف',
            'employee_advance_repayment' => 'سداد سلفة',
            'payroll' => 'مسير رواتب',
            'custody' => 'عهدة',
            'custody_transaction' => 'حركة عهدة',
            'warehouse' => 'مخزن',
            'inventory_item' => 'صنف مخزون',
            'stock_movement' => 'حركة مخزون',
            'service_plan' => 'خطة خدمة',
            'work_order_part' => 'قطعة غيار أمر عمل',
            'purchase_request' => 'طلب شراء',
            'facility_work_order' => 'أمر شغل',
            'equipment' => 'معدة',
            'area' => 'منطقة',
            'violation' => 'مخالفة',
            'approval_rule' => 'قاعدة اعتماد',
            'disbursement' => 'صرف للمالك',
            'sla_penalty' => 'غرامة مستوى الخدمة',
            'owner_statement' => 'كشف حساب المالك',
            'owner_statement_run' => 'دورة كشوف الملاك',
            'post_dated_cheque' => 'شيك آجل',
            'sla_policy' => 'سياسة مستوى الخدمة',
            'vendor_contract_amendment' => 'تعديل عقد',
            'vendor_document' => 'مستند مورد',

            // ليس نموذجًا — صفحتا الإعدادات وتخصيصات العقار تسجّلان تحت هذا الاسم، حتى يترك
            // تغيير رقم مالي في شاشة إعدادات أثرًا في السجل كأي سجل آخر.
            'settings' => 'الإعدادات',

            // تُطلقها VoidVendorBillPaymentService — الدفعة نفسها ليست نموذجًا مسجَّلًا في سجل
            // النشاط، فهذا الاسم موجود هنا فقط.
            'vendor_bill_payment' => 'دفعة مورّد',
        ],
        'events' => [
            'created' => 'إنشاء',
            'updated' => 'تعديل',
            'deleted' => 'حذف',

            // أحداث تُطلقها خدمات الإلغاء والعكس. كانت غائبة حتى 2026-08-12، فكانت كل فاتورة
            // ملغاة وكل دفعة معكوسة تعرض النص الحرفي `admin.activity.events.voided` في شارتها،
            // في الإنجليزية أيضًا لا العربية وحدها.
            'voided' => 'إلغاء',
            'reversed' => 'عكس',
        ],

        // معنى الوصف المخزَّن في السجل. **الأوصاف مفاتيح لا جُمَل** — السجل يخزّن بيانات وهذا
        // الملف يحوّلها إلى كلمات، فيُقرأ السطر الواحد صحيحًا باللغتين ويصل تصحيح الصياغة إلى كل
        // سطر كُتب من قبل. السطور الأقدم من هذه القاعدة ترجع إلى نصها الإنجليزي المخزَّن.
        // متداخلة لا مسطّحة: الوصف المخزَّن يُقرأ عبر `__()` التي تعامل النقطة كتداخل في المصفوفة،
        // فالمفتاح الحرفي `'payment.voided' => …` لا يمكن العثور عليه أبدًا.
        'descriptions' => [
            'invoice' => [
                'voided' => 'إلغاء فاتورة',
                // قناتا التسوية اللتان يمكن للمشغّل عكسهما من شاشة الفاتورة. تُقيَّد على الفاتورة
                // نفسها لأن سطور التطبيق تُحذف حذفاً ناعماً عند العكس.
                'credit_reversed' => 'عكس رصيد مستأجر مُطبَّق',
                'deposit_reversed' => 'عكس تأمين مخصوم من الفاتورة',
            ],
            'payment' => ['voided' => 'إلغاء إيصال'],
            'vendor_bill' => ['cancelled' => 'إلغاء فاتورة مورّد'],
            'expense' => ['cancelled' => 'إلغاء مصروف'],
            'payroll' => ['cancelled' => 'إلغاء مسيّر رواتب'],
            'deposit_transaction' => ['cancelled' => 'إلغاء حركة تأمين'],
            'disbursement' => ['cancelled' => 'إلغاء صرفية مالك'],
            'credit_note' => [
                'voided' => 'إلغاء إشعار دائن',
                'reversed' => 'عكس تطبيقات إشعار دائن',
            ],
            'invoice_write_off' => ['reversed' => 'عكس إعدام دين'],
            'fixed_asset' => ['reversed' => 'عكس اقتناء أصل ثابت'],
            'marketing_spend' => ['cancelled' => 'إلغاء إنفاق تسويقي'],
            'employee_advance' => ['reversed' => 'عكس سلفة موظف'],
            'custody' => ['reversed' => 'عكس عهدة'],
            'vendor_bill_payment' => ['voided' => 'إلغاء دفعة مورّد'],
            'employee_advance_repayment' => ['reversed' => 'عكس سداد سلفة'],
            'custody_transaction' => ['reversed' => 'عكس حركة عهدة'],
            'settings' => ['updated' => 'تعديل إعدادات المحفظة'],
            'property_settings' => ['updated' => 'تعديل إعدادات العقار'],

            // AccessControlAudit::ACTIONS — مفاتيح مسطّحة من مقطع واحد، لأن القيمة المخزَّنة هي
            // نفسها اسم الحقل في attribute_changes، والسطور الأقدم من هذا الإصلاح تحملها بالفعل.
            'role_granted' => 'منح دور',
            'role_revoked' => 'سحب دور',
            'permission_granted' => 'منح صلاحية لدور',
            'permission_revoked' => 'سحب صلاحية من دور',
            'role_deleted' => 'حذف دور — فقده كل من كان يحمله',
            'property_access_change_blocked' => 'رفض تغيير صلاحية الوصول للعقار',
            'protected_role_change_blocked' => 'رفض تغيير دور محمي',
        ],

        // تسميات حقول خاصة بنموذج بعينه — الدرجة الأولى في ActivityVocabulary::field()، للعمود
        // النادر الذي يختلف معناه باختلاف النموذج. وما عدا ذلك يُقرأ من فهرس `admin.fields.*`
        // المشترك، فيُسمّي السجل الحقل بالاسم نفسه الذي يسمّيه به نموذج الإدخال.
        'fields' => [
            // «الوحدة» هنا وحدة قياس (قطعة / علبة / كجم)، لا وحدة قابلة للتأجير.
            'inventory_item' => ['unit' => 'وحدة القياس'],

            // سجل التدقيق يبتكر أسماء حقوله بنفسه (القيمة قائمة بأسماء أدوار أو صلاحيات)،
            // فمكانها هنا لا في فهرس حقول النماذج.
            'access_control' => [
                'role_granted' => 'الأدوار الممنوحة',
                'role_revoked' => 'الأدوار المسحوبة',
                'permission_granted' => 'الصلاحيات الممنوحة',
                'permission_revoked' => 'الصلاحيات المسحوبة',
                'role_deleted' => 'الصلاحيات التي كان يحملها الدور المحذوف',
                'property_access_change_blocked' => 'العقارات المرفوضة',
                'protected_role_change_blocked' => 'الأدوار المرفوضة',
            ],
        ],
        'empty_value' => '(فارغ)',
        'held_by' => 'مُسند إلى',
        'bool_true' => 'نعم',
        'bool_false' => 'لا',
        'period' => 'الفترة',
        'periods' => [
            'today' => 'اليوم',
            'yesterday' => 'أمس',
            'last_7_days' => 'آخر 7 أيام',
            'last_30_days' => 'آخر 30 يومًا',
            'this_month' => 'هذا الشهر',
            'last_month' => 'الشهر الماضي',
        ],
    ],

];
