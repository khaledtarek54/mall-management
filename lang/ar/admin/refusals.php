<?php

/**
 * **الرفض** — كل استثناء `DomainException` قد يواجهه المشغّل، بلغته.
 *
 * On 2026-08-28 **62 of the 259 refusal messages raised by `app/Models` and `app/Services` were
 * raw English strings** (24%), and they were not spread evenly: they clustered in the money
 * immutability guards and the posting engine — exactly the sentences an Egyptian accountant working
 * the panel in Arabic reads most. `bootstrap/app.php` renders a `DomainException` as a toast, so
 * these are not developer errors; they are the app talking to a person.
 *
 * Two things were wrong and only one was visible. The message was English — and it interpolated the
 * raw COLUMN NAME, so the operator was told *"A captured payment's payment_date is immutable"*, half
 * a sentence of database schema in the middle of a business rule. Field names now resolve through
 * `admin.fields.*`, the same catalogue the forms label from, so the refusal names the field the way
 * the screen does — «تاريخ الدفع», not `payment_date`.
 *
 * Keys are grouped by what refused, not by which class raised it: an operator meets the rule, not
 * the file.
 */

return [
    'refusals' => [
        'cheque_deposit_future' => 'لا يمكن إيداع شيك بتاريخ مستقبلي — فإما أنك سلّمته للبنك أو لا. استخدم اليوم الذي قدّمته فيه فعلًا.',
        'payroll_approval_in_progress' => 'يوجد اعتماد آخر لهذا الشهر قيد التنفيذ. انتظر انتهاءه ثم أعد تحميل المسير قبل الاعتماد.',
        'announcement_window_closed' => 'ينتهي هذا الإعلان في :expired وهو تاريخ مضى — سيصل كل مستأجر إلى إعلان لا يمكنه فتحه، والإعلان المُرسَل لا يمكن تعديله. قدِّم تاريخ الانتهاء أولًا.',
        'tenant_request_needs_a_unit' => 'لا يوجد لهذا الحساب محل لتقديم الطلب عليه — عقد منتهٍ، أو وحدة لم يتم تسليمها بعد. يرجى مراجعة المشغّل.',
        'bank_account_is_a_posting_role' => 'الحساب :account هو حساب ترحيل افتراضي — إليه تذهب المستندات التي لا تحدّد حسابًا بنكيًا. وربط بنك حقيقي به يخلط الاثنين، فتُعرض كل القيود غير المنسوبة عند تسوية هذا البنك. امنح هذا الحساب فرعًا خاصًا به في الدليل.',
        'bank_account_shares_a_chart_account' => 'هذا الحساب في الدليل مرتبط بالفعل بـ :other. وجود بنكين على حساب واحد يعني أن تسوية أيّهما تعرض قيود الآخر كمطابقات — مطابقة خاطئة ومتوازنة في الوقت نفسه. لكل بنك حسابه.',
        'payment_credit_overdrawn' => 'لا يمكن إعادة توزيع هذا الإيصال بالكامل: تم بالفعل تطبيق :shortfall منه على فاتورة أخرى كرصيد تحت الحساب. تراجَع عن ذلك التطبيق أولًا.',
        'credit_note_void_is_terminal' => 'إشعار الخصم الملغى مغلق نهائيًا — فقد عُكس قيده في دفتر الأستاذ. أصدر إشعارًا جديدًا إذا كان هناك رصيد مستحق.',
        'credit_note_status_is_an_act' => 'يُصدَر إشعار الخصم أو يُلغى من زره الخاص، لا باختيار حالة. هذه الأفعال تتحقق من الفترة المحاسبية وتُرحّل إلى دفتر الأستاذ وتسجّل السبب — استخدم «إصدار» أو «إلغاء».',
        'cam_carve_out_needs_a_stated_share' => 'العقد :lease متشال من مقام القسمة بس عقده مش قايل نسبته. العقد اللي بره المقام مفيش عنده مساحة يتحسب منها — اكتب نسبته، أو رجّعه للمقام.',
        'cam_pool_has_unbilled_allocations' => 'لسه في :count تخصيص محدش فوتره في المجمّع ده. السنة ما تتقفلش وحصة مستأجر لسه ماتصرّفش فيها — فوترها، أو ألغِ اللي مش المفروض يتفوتر.',
        'immutable_committed_money' => 'هذا المستند مُثبَت في الدفاتر، لذا لا يمكن تعديل :field — قم بعكسه وإعادة إدخاله بدلاً من ذلك.',
        'not_a_money_document' => 'هذا المستند لا يُرحَّل إلى الأستاذ العام، فليس هناك ما يُعكس.',
        'immutable_lease' => 'عقد الإيجار في حالة «:status» غير قابل للتعديل — قم بعكسه أو تجديده بدلاً من ذلك.',
        'immutable_payment' => 'لا يمكن تعديل :field في إيصال مُحصَّل — قم بإلغاء الإيصال وإعادة تسجيله بدلاً من ذلك.',
        'immutable_invoice' => 'لا يمكن تعديل :field في فاتورة مُصدَرة — قم بإلغائها وإعادة إصدارها بدلاً من ذلك.',
        'immutable_credit_note' => 'لا يمكن تعديل :field في إشعار دائن مُصدَر — قم بإلغائه وإصدار إشعار جديد.',
        'immutable_vendor_bill' => 'فاتورة المورّد المعتمدة غير قابلة للتعديل — قم بإلغائها وإعادة إدخالها بدلاً من تعديل المبلغ أو المورّد أو التصنيف أو أمر الشراء المرتبط.',
        'immutable_disbursement' => 'الصرفية في حالة «:status» غير قابلة للتعديل.',
        'immutable_cheque' => 'الشيك الآجل في حالة «:status» غير قابل للتعديل.',
        'invoice_no_return_to_draft' => 'لا يمكن إعادة فاتورة مُصدَرة إلى حالة مسودة — قم بإلغائها أو أصدر إشعاراً دائناً بدلاً من ذلك.',
        'invoice_write_off_is_an_act' => 'لا يمكن إعدام الفاتورة بتغيير حالتها — استخدم إجراء «إعدام الدين»، فهو يسجّل السبب ويقيّد الديون المعدومة.',
        'invoice_ar_already_relieved' => 'الفاتورة :number في حالة «:status» — تم إسقاط مديونيتها بالفعل، فلا يمكن تحصيل أي مبلغ إضافي عليها.',
        'credit_note_no_return_to_draft' => 'لا يمكن إعادة إشعار دائن مُصدَر إلى حالة مسودة — قم بإلغائه وإصدار إشعار جديد بدلاً من ذلك.',
        'credit_note_still_applied' => 'لا يمكن حذف إشعار دائن ما زال رصيده مُطبَّقاً — قم بعكس التطبيق أولاً ثم احذفه.',
        'bill_pr_other_property' => 'أمر الشراء المرتبط يخص عقاراً آخر غير عقار الفاتورة.',
        'cheque_invoice_other_property' => 'الفاتورة المرتبطة تخص عقاراً آخر غير عقار الشيك.',
        'cheque_invoice_other_tenant' => 'الفاتورة المرتبطة تخص مستأجراً آخر غير مستأجر الشيك.',
        'pr_warehouse_other_property' => 'المخزن المختار يخص عقاراً آخر غير عقار الطلب.',
        'pr_locked_after_approval' => 'لا يمكن تغيير العقار أو المخزن أو المبرر في طلب شراء بعد اعتماده.',
        'cf_model_not_extensible' => '[:model] ليس نوع سجل يقبل الحقول المخصّصة.',
        'cf_bad_key' => '[:key] ليس مفتاح حقل صالحاً — استخدم حروفاً صغيرة وأرقاماً وشرطات سفلية، على أن يبدأ بحرف.',
        'cf_key_immutable' => 'لا يمكن تغيير مفتاح الحقل المخصّص — فكل قيمة مسجَّلة محفوظة تحته. غيّر التسمية بدلاً من ذلك.',
        'cf_choice_needs_option' => 'حقل الاختيار يحتاج إلى خيار واحد على الأقل.',
        'cheque_deposit_state' => 'لا يمكن إيداع سوى شيك محفوظ (أو مرتدّ أُعيد تقديمه).',
        'cheque_clear_state' => 'لا يمكن تحصيل سوى شيك محفوظ أو مُودَع.',
        'cheque_bounce_state' => 'لا يرتدّ سوى شيك محفوظ أو مُودَع.',
        'cheque_cleared_cancel' => 'لا يمكن إلغاء شيك تم تحصيله — قم بإلغاء الدفعة الخاصة به بدلاً من ذلك.',
        'payroll_deductions_exceed_gross' => 'الاستقطاعات تتجاوز إجمالي الرواتب؛ صحّح المبالغ قبل الاعتماد.',
        'bill_cancel_has_payments' => 'لا يمكن إلغاء فاتورة عليها دفعات. قم بإلغاء الدفعات أولاً (الدفعات ← إلغاء الدفعة) ثم ألغِ الفاتورة.',
        'payment_void_state' => 'لا يمكن إلغاء سوى إيصال مُحصَّل.',
        'invoice_void_eta_filed' => 'هذه الفاتورة مُقدَّمة لمصلحة الضرائب ولا يمكن إلغاؤها داخلياً — أصدر إشعاراً دائناً بدلاً من ذلك.',
        'invoice_void_has_cash' => 'لا يمكن إلغاء فاتورة عليها دفعات مُحصَّلة — ألغِ الإيصال أولاً ثم ألغِ الفاتورة.',
        'write_off_positive' => 'مبلغ إعدام الدين يجب أن يكون أكبر من صفر.',
        'disb_needs_finalised_run' => 'لا يمكن جدولة صرفية إلا مقابل كشف مالك مُعتمَد.',
        'disb_amount_positive' => 'مبلغ الصرفية يجب أن يكون موجباً.',
        'disb_exceeds_remaining' => 'المبلغ يتجاوز :remaining المتبقية المستحقة للمالك.',
        'disb_approve_state' => 'لا يمكن اعتماد سوى صرفية مجدولة.',
        'disb_approve_tier' => 'لا تملك صلاحية اعتماد صرفية بهذا المبلغ.',
        'disb_approve_tier_lost' => 'لا تملك مستوى الاعتماد الذي كان مطلوباً عند جدولة هذه الصرفية.',
        'disb_pay_state' => 'لا يمكن تسجيل الدفع إلا لصرفية معتمدة.',
        'disb_paid_no_cancel' => 'لا يمكن إلغاء صرفية تم دفعها.',
        'run_finalise_state' => 'لا يمكن اعتماد سوى تشغيلة كشف مالك في حالة مسودة.',
        'run_revise_state' => 'لا يمكن تعديل سوى تشغيلة كشف مالك معتمدة.',
        'map_missing' => 'لا يوجد ربط حساب للدور المحاسبي «:role».',
        'map_account_missing' => 'ربط الحساب «:role» يشير إلى حساب لم يعد موجوداً.',
        'map_account_not_postable' => 'ربط الحساب «:role» يشير إلى حساب تجميعي (:code) لا يقبل الترحيل.',
        'je_post_voided' => 'لا يمكن ترحيل قيد ملغى.',
        'je_void_state' => 'لا يمكن إلغاء سوى قيد مُرحَّل.',
        'je_needs_two_lines' => 'القيد يحتاج إلى سطرين على الأقل.',
        'je_line_unknown_account' => 'السطر :line: حساب غير معروف في دليل الحسابات.',
        'je_line_summary_account' => 'السطر :line: الحساب :code حساب تجميعي ولا يقبل الترحيل.',
        'je_line_inactive_account' => 'السطر :line: الحساب :code غير نشط.',
        'je_line_negative' => 'السطر :line: لا يمكن أن تكون المبالغ سالبة.',
        'je_line_two_sided' => 'السطر :line: السطر إمّا مدين أو دائن، وليس الاثنين معاً.',
        'je_line_empty' => 'السطر :line: يجب أن يحمل السطر مبلغاً مديناً أو دائناً.',
        'je_zero_amount' => 'يجب أن يحرّك القيد مبلغاً غير صفري.',
        'je_unbalanced' => 'القيد غير متوازن: المدين :debit لا يساوي الدائن :credit.',
        'je_unknown_account' => 'أحد سطور القيد يشير إلى حساب غير معروف في دليل الحسابات.',
        'je_void_no_open_period' => 'تعذّر الإلغاء: لا فترة القيد الأصلي ولا الفترة الحالية مفتوحة. أعد فتح فترة أولاً.',
        'je_no_period' => 'لا توجد فترة محاسبية معرّفة للتاريخ :date.',
        'je_period_closed' => 'الفترة المحاسبية :month مقفلة — لا يمكن الترحيل إليها.',
        // ── أُضيفت في 2026-08-30 — تسعة رفضٍ كانت لا تزال بالإنجليزية ───────────────────────
        'cam_basis_locked_after_billing' => 'لا يمكن تغيير أساس استرداد مصروفات المناطق المشتركة بعد فوترة أي توزيع — ألغِ التوزيعات المفوترة أولاً.',
        'vendor_not_dispatchable' => 'لا يمكن إسناد أمر عمل إلى المورّد :vendor: فهو محظور أو غير نشط، أو انتهت صلاحية شهادة التأمين الخاصة به.',
        'overlapping_charge_schedule' => 'يحتوي عقد الإيجار :reference على سطور جدول رسوم متداخلة عن :period (:detail). يجب ألا يغطي الفترة أكثر من سطر واحد لكل نوع رسم — أغلق السطر الأقدم قبل بداية الأحدث.',
        'write_off_not_live' => 'الفاتورة :number حالتها :status — لا يُشطب إلا دين قائم.',
        'write_off_nothing_outstanding' => 'الفاتورة :number ليس عليها رصيد مستحق — لا يوجد دين لشطبه.',
        'write_off_already_full' => 'الفاتورة :number مشطوبة بالكامل بالفعل (:written من :outstanding).',
        'write_off_exceeds_remaining' => 'لا يمكن شطب :amount من الفاتورة :number: المتبقي القابل للشطب هو :remaining فقط.',
        'write_off_exceeds_remaining_partial' => 'لا يمكن شطب :amount من الفاتورة :number: المتبقي القابل للشطب هو :remaining فقط (:written من :outstanding مشطوبة بالفعل).',
        'owner_statement_finalised_exists' => 'يوجد بالفعل كشف معتمد لهذه الجهة العقارية وهذه الفترة — عدّله بإصدار نسخة مُنقّحة بدلاً من إعادة توليده.',
        'owner_statement_has_active_disbursements' => 'لا يمكن تنقيح هذه الدورة وبها صرفيات نشطة — ألغِ المدفوعات المجدولة أو المعتمدة أولاً. وإذا كان المالك قد استلم مستحقاته بالفعل، فصحّح الفرق في الفترة التالية بدلاً من تنقيح كشف مدفوع.',
        'lease_option_not_open' => 'هذا الخيار :status — لا يُستخدَم إلا خيار مفتوح.',
        'cam_cap_term_incomplete' => 'سقف مصاريف المناطق المشتركة من نوع :type يحتاج :fields. وبدونها لا ينتج السقف قيمة، فيُحاسَب المستأجر بالكامل بينما يظهر على العقد سقف قائم.',
    ],
];
