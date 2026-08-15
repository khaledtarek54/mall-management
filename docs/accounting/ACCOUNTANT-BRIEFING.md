# بريفنج المحاسب — Accountant Briefing
### كل حاجة بتترحّل للأستاذ العام + الأسئلة اللي محتاجين إجابتها عشان نشغّل النظام
### Everything that posts to the General Ledger + the questions we need answered to go live

> **الغرض / Purpose.** المستند ده بيتسلّم للمحاسب في الاجتماع. فيه ٣ حاجات:
> **1)** خريطة الترحيل — كل حركة مالية في النظام بتترحّل على أنهي حساب مدين/دائن (عشان لو حاسس إن حاجة بتترحّل غلط نعدّلها).
> **2)** جدول ربط الأدوار بشجرة الحسابات — ده اللي بنعدّله من الشاشة من غير برمجة.
> **3)** أسئلة محتاجين إجاباتها منك عشان الموديول المحاسبي يشتغل صح من أول يوم.
>
> ⚠️ **`ACCOUNTANT-BRIEFING.pdf` الموجود جنب الملف ده أقدم منه** (آخر تحديث للأسئلة: 2026-08-12 — ترقيم المستندات، الدمغة وضريبة الجدول، والموازنة). ابعت الـ **Markdown** أو أعِد توليد الـ PDF قبل ما تبعته.
> ⚠️ **The PDF next to this file is older than it** — regenerate before sending.
>
> This hand-out has three parts: **(1)** the GL **posting map** — every money movement and the exact debit/credit account it hits, so you can flag anything posting to the wrong account and we reconfigure it; **(2)** the **account-role → chart mapping** — what we re-point from the screen with no code change; **(3)** the **questions** we need you to answer to operate a strong accounting module.
>
> _أُنشئ / Generated: 2026-07-23 · النظام / System: Atriom · العملة / Currency: EGP (بالقرش، منزلتين عشريتين)._

---

## طريقة القراءة / How to read the posting map

- كل حركة بترحّل قيد **متوازن** (إجمالي المدين = إجمالي الدائن).
- الحساب مكتوب كـ **الاسم العربي / English name (الكود)**. الكود ده من الشجرة المبدئية — قابل للتغيير (بص Part 2/3).
- **مدين = Debit (Dr)** · **دائن = Credit (Cr)**.
- "نقدية/بنك" يعني النظام بيختار **الصندوق العام (11101001)** لو الطريقة نقدي، أو **حساب بنكي (11102001)** لو تحويل/شيك.
- عمود **"بترحّل إمتى"** مهم: القيد مبيظهرش قبل الحالة دي (المسودّة مثلاً مبترحّلش).

---

# القسم ١ · خريطة الترحيل / Part 1 · GL Posting Map

## أ) دورة العملاء والإيراد / A) Receivables & Revenue

### A1 · إصدار فاتورة / Issue Invoice
**بترحّل إمتى:** عند إصدار الفاتورة (issued فأعلى). المسودّة (draft) والملغاة (cancelled) **مبترحّلش**. الإيراد بيتحسب **وقت الإصدار** (أساس الاستحقاق).

| مدين / Dr | دائن / Cr | القيمة / Amount |
|---|---|---|
| المدينون / Accounts Receivable (11201001) = **الإجمالي** | | إجمالي الفاتورة بالضريبة |
| | الإيراد **حسب نوع كل بند** (بدون ضريبة) — الجدول تحت | صافي كل بند |
| | ض.ق.م المستحقة / VAT Payable (21301001) | إجمالي الضريبة |

**نوع البند → حساب الإيراد / Invoice item type → revenue account:**

| نوع البند / Item type | حساب الإيراد / Revenue account |
|---|---|
| إيجار أساسي / base_rent | إيرادات الإيجار الأساسي / Base Rent Revenue (41101001) |
| رسوم خدمة / service_charge | إيرادات خدمات / Service Charge Revenue (41102001) |
| مرافق / utility | إيرادات مرافق / Utility Revenue (41104001) |
| مواقف / parking | إيرادات مواقف السيارات / Parking Revenue (41109001) |
| إيجار نسبي / percentage_rent | إيرادات إيجار نسبي / Percentage Rent Revenue (41105001) |
| رسوم تسويق / marketing | إيرادات رسوم التسويق / Marketing Levy Revenue (41106001) |
| غرامة تأخير / late_fee | إيرادات غرامات تأخير / Late Fee Income (41107001) |
| استرداد صيانة مشتركة / cam_recovery | إيرادات استرداد المصروفات المشتركة / CAM Recovery Revenue (41103001) |
| رسوم إدارة CAM / cam_admin_fee | إيرادات رسوم إدارة CAM / CAM Admin Fee Revenue (41108001) |
| أي نوع غير معروف / unknown | إيرادات متنوعة / Miscellaneous Income (42101001) |

> **الضريبة دلوقتي (افتراض محتاج تأكيدك):** الإيجار الأساسي **معفى (0%)**، الخدمات/المرافق/المواقف **14%**، الإيجار النسبي وغرامة التأخير واسترداد CAM **0%** (افتراض). — بص سؤال Q-TAX-1..5.

### A2 · تحصيل دفعة / Capture Payment
**بترحّل إمتى:** لما تكون الدفعة مستلمة فعلاً (captured / reconciled / settled). المعلّقة/المرتجعة **مبترحّلش**.

| مدين / Dr | دائن / Cr | القيمة / Amount |
|---|---|---|
| نقدية/بنك / Cash or Bank (11101001 / 11102001) = **المبلغ** | | المبلغ المستلم |
| | المدينون / Accounts Receivable (11201001) | المخصّص للفواتير (بحد أقصى المبلغ) |
| | إيرادات غير مكتسبة / Unearned Revenue (21501001) | الفائض (دفعة مقدمة) لو دفع أكتر من المستحق |

### A3 · إشعار خصم (مرتجع) / Credit Note
**بترحّل إمتى:** issued / applied.

| مدين / Dr | دائن / Cr | القيمة / Amount |
|---|---|---|
| مردودات المبيعات / Sales Returns & Allowances (43101001) | | الصافي (الإجمالي − الضريبة) |
| ض.ق.م المستحقة / VAT Payable (21301001) — **عكس الضريبة** | | الضريبة |
| | المدينون / Accounts Receivable (11201001) | الإجمالي |

### A4 · تطبيق رصيد دائن للمستأجر / Apply Tenant On-account Credit
**بترحّل إمتى:** وقت التطبيق (بتاريخ اليوم، مش تاريخ الدفعة الأصلية — عشان ميدخلش في فترة مقفولة).

| مدين / Dr | دائن / Cr | القيمة / Amount |
|---|---|---|
| إيرادات غير مكتسبة / Unearned Revenue (21501001) | | المبلغ المطبّق |
| | المدينون / Accounts Receivable (11201001) | المبلغ المطبّق |

---

## ب) دورة الموردين والمصروفات / B) Payables & Expenses

### B1 · فاتورة مورد / Vendor Bill
**بترحّل إمتى:** أي حالة غير draft/cancelled.

| مدين / Dr | دائن / Cr | القيمة / Amount |
|---|---|---|
| بضاعة واردة غير مفوترة / GRNI (21701001) — لو فيه مخزون اتستلم قبل الفاتورة | | قيمة البضاعة المستلمة (تسوية) |
| المصروف **حسب فئة الفاتورة** — الجدول تحت | | الباقي (الصافي − قيمة البضاعة) |
| ض.ق.م قابلة للخصم / VAT Recoverable (11401001) | | الضريبة |
| | الموردون / Accounts Payable (21101001) | الإجمالي بالضريبة |

### B2 · سداد لمورد / Vendor Bill Payment
| مدين / Dr | دائن / Cr | القيمة / Amount |
|---|---|---|
| الموردون / Accounts Payable (21101001) = **الإجمالي** | | إجمالي المستحق للمورد |
| | نقدية/بنك / Cash or Bank | المبلغ − الخصم والإضافة (اللي خرج فعلاً) |
| | ضريبة الخصم والإضافة المستحقة / Withholding Tax Payable (21303001) | المبلغ المحتجز (لو فيه) |

### B3 · غرامة تأخير مورد (SLA) / Applied SLA Penalty
**بترحّل إمتى:** الحالة **applied** ومربوطة بفاتورة مورد. (ده اللي كان بيسبّب خطأ قديم واتصلّح — دلوقتي بيقلّل المستحق للمورد **و** بيرحّل القيد.)

| مدين / Dr | دائن / Cr | القيمة / Amount |
|---|---|---|
| الموردون / Accounts Payable (21101001) — بنقلّل اللي مستحق للمورد | | مبلغ الغرامة |
| | **نفس حساب مصروف الفاتورة** the bill's own expense account | مبلغ الغرامة (تخفيض تكلفة، **بدون ضريبة**) |

> ⚠️ **سؤال Q-POL-4:** الغرامة دلوقتي بتتعامل كـ **تخفيض تكلفة** (بترجع فايدتها للمستأجرين عبر CAM). هل ده صح ولا المفروض **إيراد آخر** والمول يحتفظ بيها؟

### B4 · مصروف نقدي مباشر / Direct Expense
**بترحّل إمتى:** recorded.

| مدين / Dr | دائن / Cr | القيمة / Amount |
|---|---|---|
| المصروف **حسب الفئة** (نفس جدول الموردين) | | الصافي (الإجمالي − الضريبة) |
| ض.ق.م قابلة للخصم / VAT Recoverable (11401001) | | الضريبة |
| | نقدية/بنك / Cash or Bank | الإجمالي |

**فئة المصروف → حساب المصروف / Expense category → account** (بتستخدمه فاتورة المورد والمصروف المباشر):

| الفئة / Category | حساب المصروف / Expense account |
|---|---|
| صيانة / maintenance | صيانة وإصلاحات / Repairs & Maintenance (51102001) |
| مرافق / utilities | مصروف مرافق / Utilities Expense (51103001) |
| نظافة وأمن / cleaning_security | نظافة وأمن / Cleaning & Security (51104001) |
| تسويق / marketing | مصروف تسويق / Marketing Expense (51105001) |
| إداري / admin و "أخرى / other" | مصروفات إدارية وعمومية / General & Admin (51106001) |

---

## ج) الرواتب والعاملين / C) Payroll & Staff

### C1 · مسيّر رواتب / Payroll Run
**بترحّل إمتى:** approved.

| مدين / Dr | دائن / Cr | القيمة / Amount |
|---|---|---|
| الرواتب والأجور / Salaries & Wages (51101001) = **إجمالي الرواتب** | | الإجمالي (gross) |
| | ضريبة كسب العمل المستحقة / Salary Tax Payable (21302001) | ضريبة المرتبات المستقطعة |
| | التأمينات الاجتماعية المستحقة / Social Insurance Payable (21601001) | التأمينات المستقطعة من الموظف |
| | نقدية/بنك / Cash or Bank | الصافي المدفوع (net) |

> ⚠️ **سؤال Q-PAY-1 (مهم جداً 🔴):** النظام دلوقتي بيسجّل **المستقطَع من الموظف بس**. **حصة صاحب العمل من التأمينات + مخصص نهاية الخدمة مش متسجّلين**. لو مش متسجّلين في أي مكان، يبقى المصروف والالتزام في الدفاتر **ناقصين**.

### C2 · صرف سلفة موظف / Grant Employee Advance
| مدين / Dr | دائن / Cr |
|---|---|
| سلف وقروض العاملين / Employee Advances (11203001) | نقدية/بنك / Cash or Bank |

### C3 · سداد/خصم سلفة / Advance Repayment
| مدين / Dr | دائن / Cr |
|---|---|
| نقدية/بنك / Cash or Bank | سلف وقروض العاملين / Employee Advances (11203001) |

---

## د) الخزينة والعُهد / D) Treasury & Custody

### D1 · صرف عهدة / Grant Custody (عهدة نقدية)
| مدين / Dr | دائن / Cr |
|---|---|
| عُهد نقدية / Custodies (11204001) | نقدية/بنك / Cash or Bank |

### D2 · تسوية عهدة / Settle Custody
| النوع / Type | مدين / Dr | دائن / Cr |
|---|---|---|
| مصروف / expense | المصروف حسب الفئة / expense by category | عُهد نقدية / Custodies (11204001) |
| إرجاع نقدي / return | نقدية/بنك / Cash or Bank | عُهد نقدية / Custodies (11204001) |

### D3 · حركة تأمين مستأجر / Tenant Deposit Transaction
| النوع / Type | مدين / Dr | دائن / Cr |
|---|---|---|
| استلام / receipt | نقدية/بنك / Cash or Bank | تأمينات محتجزة / Deposits Held (21201001) |
| رد / refund | تأمينات محتجزة / Deposits Held (21201001) | نقدية/بنك / Cash or Bank |
| مصادرة / forfeit | تأمينات محتجزة / Deposits Held (21201001) | إيرادات متنوعة / Misc Income (42101001) |

---

## هـ) المخزون / E) Inventory (Stock Movement)

| الحركة / Movement | مدين / Dr | دائن / Cr |
|---|---|---|
| استلام / receipt | مخزون / Inventory (11301001) | بضاعة واردة غير مفوترة / GRNI (21701001) |
| صرف/استهلاك / consumption | صيانة وإصلاحات / Repairs & Maintenance (51102001) | مخزون / Inventory (11301001) |
| تسوية بالزيادة / adjustment (+) | مخزون / Inventory (11301001) | تسوية مخزون / Inventory Adjustment (51108001) |
| تسوية بالعجز / adjustment (−) | تسوية مخزون / Inventory Adjustment (51108001) | مخزون / Inventory (11301001) |
| تحويل بين مخازن / transfer | **لا يوجد قيد** (نفس حساب المخزون) | — |

> القيمة = |الكمية| × تكلفة الوحدة. **سؤال Q-POL-5:** طريقة تقييم المخزون (FIFO / متوسط / تكلفة معيارية)؟

---

## و) الأصول الثابتة / F) Fixed Assets

### F1 · شراء أصل / Acquisition
| مدين / Dr | دائن / Cr |
|---|---|
| أثاث ومعدات / Furniture & Equipment (12101001) = التكلفة | نقدية/بنك / Cash or Bank |

### F2 · إهلاك شهري / Monthly Depreciation
| مدين / Dr | دائن / Cr |
|---|---|
| مصروف إهلاك / Depreciation Expense (51107001) = القسط | مجمع الإهلاك / Accumulated Depreciation (12201001) |

### F3 · استبعاد أصل / Disposal
| مدين / Dr | دائن / Cr | القيمة |
|---|---|---|
| مجمع الإهلاك / Accumulated Depreciation (12201001) | | مجمع الإهلاك المتراكم |
| نقدية/بنك / Cash or Bank | | حصيلة البيع (proceeds) |
| | أثاث ومعدات / Furniture & Equipment (12101001) | التكلفة الأصلية (عكس) |
| **التوازن:** خسائر بيع أصول / Loss on Disposal (52102001) *(لو الحصيلة < صافي القيمة)* | أرباح بيع أصول / Gain on Disposal (42102001) *(لو الحصيلة > صافي القيمة)* | الفرق |

---

## ز) التسويق / G) Marketing Spend
| مدين / Dr | دائن / Cr |
|---|---|
| مصروف تسويق / Marketing Expense (51105001) | نقدية/بنك / Cash or Bank |

> **ملاحظة:** بدون فصل ضريبة حالياً. وشوف **سؤال Q-POL-3** عن رسوم التسويق 5% (إيراد ولا صندوق تسويق التزام؟).

---

## ح) الملاك / H) Owners

### H1 · اعتماد كشف مالك / Finalise Owner Statement
| مدين / Dr | دائن / Cr |
|---|---|
| توزيعات الملاك / Owner Distributions (34101001) = صافي المستحق | توزيعات مستحقة للملاك / Distributions Payable to Owners (21802001) |

### H2 · صرف مستحقات مالك / Owner Disbursement
**بترحّل إمتى:** paid.

| مدين / Dr | دائن / Cr |
|---|---|
| توزيعات مستحقة للملاك / Distributions Payable to Owners (21802001) | نقدية/بنك / Cash or Bank |

> H1 بيعمل الالتزام، H2 بيسدّده. لما كل الملاك يتدفعوا، حساب "توزيعات مستحقة للملاك" بيرجع صفر.

---

# القسم ٢ · ربط الأدوار بشجرة الحسابات / Part 2 · Account-Role → Chart Mapping

> ده **سطح الإعداد**. كل "دور" في الكود بيتربط بحساب من الشجرة. تقدر تعيد ربط أي دور بحساب تاني **من الشاشة من غير برمجة**، وكمان لكل عقار (property) على حدة لو حبيت. راجع الجدول ده وقولّي لو أي دور المفروض يروح لحساب مختلف.

| الدور / Role | الحساب الافتراضي / Default account | الكود / Code |
|---|---|---|
| cash | الصندوق العام / Main Cashier | 11101001 |
| bank | حساب بنكي / Bank Account | 11102001 |
| accounts_receivable | عملاء تجاريون (المستأجرون) / Tenant Receivables | 11201001 |
| employee_advances | سلف وقروض العاملين / Employee Advances | 11203001 |
| custody | عُهد نقدية / Custodies | 11204001 |
| accounts_payable | موردون تجاريون / Vendor Payables | 21101001 |
| deposits_held | تأمينات محتجزة / Tenant Deposits Held | 21201001 |
| vat_payable | ض.ق.م مستحقة / VAT Payable | 21301001 |
| vat_recoverable | ض.ق.م مدخلات / VAT Recoverable | 11401001 |
| salary_tax_payable | ضريبة كسب العمل مستحقة / Salary Tax Payable | 21302001 |
| withholding_tax_payable | خصم وإضافة مستحقة / Withholding Tax Payable | 21303001 |
| social_insurance_payable | تأمينات اجتماعية مستحقة / Social Insurance Payable | 21601001 |
| unearned_revenue | إيرادات غير مكتسبة / Unearned Revenue | 21501001 |
| accrued_expenses | مصروفات مستحقة / Accrued Expenses | 21401001 |
| inventory | مخزون / Inventory | 11301001 |
| inventory_grni | بضاعة واردة غير مفوترة / GRNI | 21701001 |
| furniture_equipment | أثاث ومعدات / Furniture & Equipment | 12101001 |
| accumulated_depreciation | مجمع إهلاك الأصول الثابتة / Accumulated Depreciation | 12201001 |
| owner_distributions | توزيعات الملاك / Owner Distributions | 34101001 |
| due_to_owner | توزيعات مستحقة للملاك / Distributions Payable to Owners | 21802001 |
| **الإيرادات / Revenue** | | |
| rent_revenue | إيرادات الإيجار الأساسي / Base Rent Revenue | 41101001 |
| service_charge_revenue | إيرادات خدمات / Service Charge Revenue | 41102001 |
| cam_recovery_revenue | إيرادات استرداد CAM / CAM Recovery Revenue | 41103001 |
| cam_admin_fee_revenue | إيرادات رسوم إدارة CAM / CAM Admin Fee Revenue | 41108001 |
| utility_revenue | إيرادات مرافق / Utility Revenue | 41104001 |
| parking_revenue | إيرادات مواقف / Parking Revenue | 41109001 |
| percentage_rent_revenue | إيرادات إيجار نسبي / Percentage Rent Revenue | 41105001 |
| marketing_revenue | إيرادات رسوم التسويق / Marketing Levy Revenue | 41106001 |
| late_fee_income | إيرادات غرامات تأخير / Late Fee Income | 41107001 |
| misc_income | إيرادات متنوعة / Miscellaneous Income | 42101001 |
| sales_returns | مردودات المبيعات / Sales Returns & Allowances | 43101001 |
| gain_on_disposal | أرباح بيع أصول / Gain on Disposal | 42102001 |
| **المصروفات / Expenses** | | |
| salaries_expense | رواتب وأجور / Salaries & Wages | 51101001 |
| maintenance_expense | صيانة وإصلاحات / Repairs & Maintenance | 51102001 |
| utilities_expense | مصروف مرافق / Utilities Expense | 51103001 |
| cleaning_security_expense | نظافة وأمن / Cleaning & Security | 51104001 |
| marketing_expense | مصروف تسويق / Marketing Expense | 51105001 |
| admin_expense | مصروفات إدارية وعمومية / General & Admin | 51106001 |
| depreciation_expense | مصروف إهلاك / Depreciation Expense | 51107001 |
| inventory_adjustment | تسوية مخزون / Inventory Adjustment | 51108001 |
| loss_on_disposal | خسائر بيع أصول / Loss on Disposal | 52102001 |
| bank_charges | مصروفات بنكية / Bank Charges | 52101001 |

> **حسابات موجودة في الشجرة لسه مش مربوطة بترحيل آلي** (ممكن تستخدمها يدوي أو نربطها لاحقاً): أوراق القبض/الدفع — شيكات آجلة (11205001 / 21102001)، مخصص الديون المشكوك فيها (11206001) ومصروف الديون (51109001)، مصروفات مدفوعة مقدماً (11501001)، أطراف ذات علاقة (11601001 / 21801001)، مخصص نهاية الخدمة/الإجازات (22201001 / 22201002)، رأس المال/أرباح محتجزة (31101001 / 32101001)، عمولات/فوائد بنكية (52103001 / 52104001).

---

# القسم ٣ · شجرة الحسابات — المطلوب منك / Part 3 · Chart of Accounts — What we need from you

**إحنا شغّالين دلوقتي على شجرة حسابات مصرية مبدئية** (اللي فوق). الملف اللي وصلنا قبل كده كان قالب **مقاولات سعودي** (زكاة، بدون ض.ق.م) واترفض.

**المطلوب / We need:**
1. **شجرة الحسابات المكوّدة الفعلية بتاعتك** (Excel/PDF) — نستبدل بيها المبدئية ولا نطابقها عليها؟ / Your real coded chart of accounts — replace ours or reconcile against it?
2. تأكيد إن **الأكواد والمسميات** مناسبة للممارسة المصرية. / Confirm codes & names fit Egyptian practice.
3. **الأرصدة الافتتاحية** (مدينون، نقدية/بنوك، تأمينات، موردون، مخزون، أصول ثابتة ومجمع إهلاكها…) — **بتاريخ إيه** ننقلها؟ / Opening balances — as of what date?
4. لو عايز **حساب لكل عقار (property)** يبقى منفصل — نعمل ربط لكل عقار على حدة. / Per-property account split if you want segment books.

---

# القسم ٤ · أسئلة للمحاسب / Part 4 · Questions for the Accountant

> **الأولوية / Priority:** 🔴 بيوقف التشغيل (إجابة غلط = ضريبة/فلوس/دفاتر غلط) · 🟠 بيوقف ميزة مستنياك · 🟡 بيغيّر رقم أو عرض مش الكود.
> **مهم:** لو مجاوبتش، النظام هيمشي بالافتراض المكتوب في "دلوقتي" — يعني السكوت قرار.
> اكتب إجابتك في عمود **الإجابة**. المجاوَب هيتنقل لـ [BUSINESS-RULES.md](../BUSINESS-RULES.md).

## ٤.١ · شجرة الحسابات وربط الترحيل / Chart & posting mapping

| # | السؤال / Question | دلوقتي / Today | أهمية | الإجابة |
|---|---|---|---|---|
| Q-COA-1 | راجع **جدول ربط الأدوار (القسم ٢)** — أي دور بيترحّل على حساب غلط؟<br>Review the role→account map (Part 2) — any role posting to the wrong account? | الربط المبدئي فوق | 🔴 | |
| Q-COA-2 | هتدّينا **شجرة الحسابات المكوّدة** بتاعتك؟ نستبدل ولا نطابق؟<br>Will you provide your coded chart — replace or reconcile? | شجرة مبدئية | 🔴 | |
| Q-COA-3 | **الأرصدة الافتتاحية** بتاريخ إيه، ومين مسؤول عن تجهيزها؟<br>Opening balances — as-of date & who prepares them? | مش محدّد | 🔴 | |
| Q-COA-4 | محتاج **حساب صندوق/بنك منفصل لكل مول**، ولا مشترك؟<br>Separate cash/bank per mall, or shared? | مشترك (قابل للفصل لكل عقار) | 🟡 | |
| Q-COA-5 | محتاج **تسلسل ترقيم** معيّن للقيود/الفواتير يطابق دفاترك؟ لو أيوه، ابعت **البادئة** لكل نوع مستند.<br>Specific numbering series? If yes, send the PREFIX for each document type. | **قابل للضبط من الإعدادات** ← المحاسبة ← ترقيم المستندات (من 2026-08-12): فاتورة `INV` · إشعار دائن `CN` · قيد `JE` · فاتورة مورد `BILL` · مصروف `EXP` · تأمين `DEP` · رواتب `PAY` · طلب شراء `PR` · عقد `LSE` · شيك آجل `PDC`.<br>⚠️ **لازم يتحدّد قبل التشغيل.** بعد أول فاتورة صادرة، البادئة مطبوعة على مستندات مش هنقدر نعيد ترقيمها؛ وتغييرها بعدها بيبدأ **سلسلة تانية** مش بيعيد ترقيم الأولى — وده اللي المراجع بيسأل عنه.<br>Configurable in Settings → Accounting; **must be fixed before go-live** — changing it later starts a SECOND series rather than renumbering the first. | 🔴 | |

## ٤.٢ · الضرائب / Taxes

| # | السؤال / Question | دلوقتي / Today | أهمية | الإجابة |
|---|---|---|---|---|
| Q-TAX-1 | **ض.ق.م 14%** على الخدمات والمرافق والمواقف، و**الإيجار الأساسي معفى** — صح؟<br>14% VAT on services/utilities/parking; base rent exempt — correct? | كده — **والنسبة بقت في كتالوج الضرائب** (/admin/tax-codes) مش في الإعدادات (من 2026-08-12): كل نسبة **درجة مؤرَّخة**، يعني تقدر تدخل زيادة قبل تاريخ سريانها والنظام يستعملها من التاريخ ده بس؛ التعديل يسري على ما يُفوتر بعده فقط، والفواتير الصادرة تحتفظ بنسبتها.<br>Rate is editable in Settings → Tax; applies going forward, issued invoices keep their rate. | 🔴 | |
| Q-TAX-2 | **الإيجار النسبي** معفى من الضريبة؟ / Is percentage rent VAT-exempt? | 0% | 🔴 | |
| Q-TAX-3 | **استرداد CAM ورسوم إدارة CAM** — معفيين ولا 14%؟<br>CAM recovery & CAM admin fee — exempt or 14%? | 0% (افتراض) | 🔴 | |
| Q-TAX-4 | **غرامات التأخير** معفية (خارج نطاق الضريبة)؟ / Late fees VAT-exempt? | 0% | 🔴 | |
| Q-TAX-5 | **خصم وإضافة (WHT)** على مدفوعات الموردين — النِسَب حسب نوع الخدمة (1%/3%/5%…)؟ وهل المستأجرين بيخصموا من الإيجار؟<br>Withholding tax rates per vendor service? Do tenants withhold on rent? | حساب WHT موجود (21303001)؛ النِسَب بتتدخل يدوي | 🔴 | |
| Q-TAX-6 | الفواتير بتصدر بـ **الرقم الضريبي للالتزام (Eltizam)** ولا رقم كل مالك؟<br>Invoices under Eltizam's TRN or each owner's? | مُصدِّر واحد | 🔴 | |
| Q-TAX-7 | **دمغة وضريبة الجدول** — الكتالوج بتاعك متسجّل بالكامل في النظام، بس **١١ كود منهم مقفول** لأن مفيش حساب في الشجرة بيترحّلوا عليه. محتاجين منك لكل عائلة: **(أ)** بتنطبق على إيه بالظبط عندنا (عقود؟ فواتير؟ أنواع بنود معيّنة؟) · **(ب)** الحساب في الشجرة للمستحق (التزام) · **(ج)** الحساب للمدفوع/المحمّل (مصروف أو أصل).<br>Stamp + schedule tax: your catalogue is fully entered, but **11 codes ship SWITCHED OFF** because no chart account is wired. For each family we need: what it applies to here, the liability account, and the expense/asset account.<br>**ملحوظة:** النظام **بيرفض تفعيل كود ضريبي من غير نسبة ودور ترحيل** — يعني الكتالوج الناقص ساكت مش فخ. مفيش شغل برمجي مستني غير الإجابة دي.<br>A tax code cannot be activated without a rate AND a posting role, so an incomplete catalogue is inert rather than a trap. Nothing is blocked here except this answer. | مسجّل ومقفول / entered, inactive | 🔴 | |
| Q-TAX-8 | **ضريبة عقارية** — بتتحمّل على الوحدات؟ تتحمّل للمستأجر ولا المالك؟<br>Real-estate/property tax — recharged or owner-borne? | مش ممثّلة | 🟠 | |
| Q-TAX-9 | **إقرار ض.ق.م شهري** — محتاج **تقرير ضريبة مخرجات** بالفاتورة للإقرار؟ وتقرير WHT (نموذج 41)؟<br>Monthly VAT return report + WHT report needed? | مش مبني | 🟠 | |
| Q-TAX-10 | فيه **مستأجرين معفيين** (منطقة حرة/حكومة/سفارة)؟<br>Any tax-exempt tenants? | مفيش تجاوز لكل مستأجر | 🟠 | |

## ٤.٣ · الاعتراف بالإيراد والإقفال / Revenue recognition & close

| # | السؤال / Question | دلوقتي / Today | أهمية | الإجابة |
|---|---|---|---|---|
| Q-REV-1 | **أساس الاستحقاق** (الإيراد وقت الإصدار)، ولا نقدي؟ محتاج **توزيع إيجار بالقسط الثابت** (فترات سماح/زيادات)؟<br>Accrual vs cash; straight-line rent spreading? | استحقاق، إيراد وقت الإصدار؛ بدون توزيع | 🟠 | |
| Q-REV-2 | **الإيجار المقدم** يتعامل كـ **إيراد مؤجل (غير مكتسب)** لحد ما يُكتسب؟<br>Rent-in-advance deferred until earned? | مش ممثّل كإيراد مؤجل | 🟠 | |
| Q-CLOSE-1 | **السنة المالية** يناير–ديسمبر؟ بتقفل الفترات لمنع التعديل بأثر رجعي؟<br>Fiscal year Jan–Dec? Lock periods? | إقفال فترات موجود؛ الفترة المقفولة بترفض الترحيل بأثر رجعي | 🟡 | |
| Q-CLOSE-2 | **تسوية بنكية** جوه النظام ولا خارجه؟<br>Bank reconciliation inside the system or external? | ❌ مش مبني — رصيد البنك مؤكَّد بالبناء مش مطابَق على كشف حساب | 🟠 | |
| Q-CLOSE-3 | القوائم المالية المطلوبة عند التشغيل (ميزان مراجعة، دخل، مركز مالي، تدفقات نقدية) وبأي شكل؟<br>Which financial statements at go-live & format? | ميزان/دخل/مركز/تدفقات موجودة | 🟡 | |
| Q-CLOSE-4 | تقارير **لكل عقار ومجمّعة**؟ ومين له حق يشوفها؟<br>Per-property + consolidated reports; who may see them? | العقار + مجمّع موجودين | 🟡 | |

## ٤.٤ · الرواتب / Payroll

| # | السؤال / Question | دلوقتي / Today | أهمية | الإجابة |
|---|---|---|---|---|
| Q-PAY-1 | **حصة صاحب العمل من التأمينات + مخصص نهاية الخدمة** بتتسجّل في أي مكان (ولو قيد مصروف شهري يدوي)؟<br>Are employer's social-insurance share + end-of-service gratuity recorded anywhere? | ⚠️ النظام بيسجّل **المستقطَع من الموظف بس** | 🔴 | |
| Q-PAY-2 | **مخصصات** نهاية الخدمة والإجازات (22201001/22201002) — تتحسب **شهرياً** آلياً؟<br>Accrue end-of-service & leave provisions monthly? | مش آلي | 🟠 | |
| Q-PAY-3 | شرائح ضريبة المرتبات ونِسَب التأمينات — النظام **يحسبها** ولا **تتدخل يدوي** كل شهر؟<br>Statutory tax/insurance — computed or keyed per run? | تتدخل يدوي لكل سطر | 🟡 | |

## ٤.٥ · الأصول والإهلاك / Fixed assets & depreciation

| # | السؤال / Question | دلوقتي / Today | أهمية | الإجابة |
|---|---|---|---|---|
| Q-FA-1 | **الأعمار الإنتاجية / نِسَب الإهلاك** لكل فئة أصل، وقيمة **الخردة (salvage)**؟<br>Useful lives / depreciation rates per class & salvage value? | قسط ثابت، بارامترات لكل أصل | 🟡 | |
| Q-FA-2 | محتاج **إهلاك ضريبي** (رصيد متناقص حسب قانون 91/2005) كـ **دفتر تاني** جنب القسط الثابت؟<br>Egyptian tax depreciation as a second book? | قسط ثابت بس | 🟠 | |

## ٤.٦ · سياسات محاسبية / Accounting policies

| # | السؤال / Question | دلوقتي / Today | أهمية | الإجابة |
|---|---|---|---|---|
| Q-POL-1 | **الديون المعدومة / المخصص** — السياسة ومين بيعتمد؟ (حسابات المخصص 11206001 والمصروف 51109001 جاهزة).<br>Bad-debt / allowance policy & approver? | مش ممثّل كتدفّق عمل | 🟠 | |
| Q-POL-2 | **CAM** يتعرض **إجمالي** (إيراد استرداد + مصروفات كلٌ على حدة) ولا **صافي**؟ وإيه اللي داخل مجمع CAM؟<br>CAM gross vs net; what's in the pool? | إجمالي (إيراد منفصل عن المصروف) | 🟡 | |
| Q-POL-3 | **رسوم التسويق 5%** — **إيراد** (زي دلوقتي) ولا **صندوق تسويق (التزام)** لازم يُصرف على التسويق مش ربح؟ وتظهر على فاتورة المستأجر ولا لأ؟<br>Marketing levy 5% — revenue, or a restricted marketing fund (liability)? Shown on the tenant invoice? | إيراد (marketing_revenue)؛ بيتفوتر كبند | 🔴 | |
| Q-POL-4 | **غرامة SLA للمورد** — **تخفيض تكلفة** (زي دلوقتي، فايدتها بترجع للمستأجرين عبر CAM) ولا **إيراد آخر** يحتفظ بيه المول؟<br>SLA penalty — cost reduction or other income? | تخفيض تكلفة | 🟠 | |
| Q-POL-5 | **طريقة تقييم المخزون** — FIFO / متوسط مرجّح / تكلفة معيارية؟<br>Inventory valuation method? | تكلفة الوحدة لكل حركة | 🟡 | |
| Q-POL-6 | **التأمين** التزام صافي بدون ضريبة، يُرَد عند الخروج بعد الخصومات (إيجار متأخر/تلفيات/ترميم)؟<br>Deposit = pure liability, refundable minus deductions? | التزام، قابل للرد | 🟡 | |
| Q-POL-7 | **الشيكات الآجلة (PDCs)** — تتحمّل على **أوراق القبض (11205001)** لحد التحصيل؟ نربطها بترحيل آلي؟<br>Post-dated cheques → Notes Receivable until cleared; wire to auto-posting? | سجل شيكات موجود؛ الترحيل الآلي وقت التحصيل كـ Payment | 🟡 | |

## ٤.٧ · مُلّاك الوحدات / Unit owners (module 37)

> الوحدات المُباعة بقت مسجّلة والصيانة بتتفوتر لملّاكها وبتترحّل زي أي فاتورة. الحاجتين دول بس اللي
> **موقفين** آخر جزء (أتعاب الإدارة وكشف حساب المالك) — محتاجين حساب في الشجرة، مش كود.
> Sold units are recorded and their صيانة is billed and posted like any invoice. These two are the
> ONLY thing blocking the last piece (management fee + owner statement) — each needs an account, not code.

| # | السؤال / Question | دلوقتي / Today | أهمية | الإجابة |
|---|---|---|---|---|
| Q-OWN-1 | لما التزام تأجّر وحدة **مملوكة** لحساب صاحبها وتاخد **أتعاب إدارة** — الأتعاب دي تترحّل على أنهي حساب إيراد؟ دي إيراد التزام مش إيراد العقار.<br>Management-fee income when Eltizam lets a SOLD unit for its owner — which revenue account? It is the operator's income, not the property's. | ⛔ مفيش حساب متحدّد — ده اللي واقف قدام الميزة<br>No account assigned — this is the blocker | 🔴 | |
| Q-OWN-2 | فيه **صندوق صيانة (احتياطي)** بيتجمّع من الملّاك للأعمال الرأسمالية؟ لو أيوه، أنهي حساب **التزامات**؟ دي فلوس محتجزة لالتزام مستقبلي **مش إيراد**.<br>Is a sinking/reserve fund collected from owners? If yes, which LIABILITY account? It is money held for a future obligation, not revenue. | ⛔ مش بيتجمّع. لو اترحّل كإيراد هيضخّم الدخل ويخفي التزام.<br>Not collected. Posting it as revenue would overstate income and hide a liability. | 🔴 | |
| Q-OWN-3 | **صيانة المالك** — إيراد للعقار زي رسوم خدمة المستأجر، ولا **استرداد تكلفة** المفروض ما يظهرش كدخل في كشف حساب مالك العقار؟<br>Owner's صيانة — property revenue like a tenant's service charge, or cost recovery that should not show as income on the property owner's statement? | بتترحّل زي رسوم الخدمة (نفس كود الرسم)<br>Posts like a service charge, same charge code | 🟠 | |
| Q-OWN-4 | **ض.ق.م على صيانة المالك** — خدمة خاضعة 14% زي رسوم الخدمة، صح؟<br>VAT on an owner's صيانة — a taxable supply at 14% like a service charge, correct? | أيوه، من كتالوج الضرائب (بيتغيّر بصف مش بنشر)<br>Yes, from the tax catalogue — a row, not a deploy | 🟡 | |

## ٤.٨ · الهجرة والتكامل / Migration & integration

| # | السؤال / Question | دلوقتي / Today | أهمية | الإجابة |
|---|---|---|---|---|
| Q-MIG-1 | **إيه اللي بيتنقل وكام سنة** (مدينون مفتوحين، تأمينات، تاريخ مدفوعات، شيكات، إشعارات)؟ تقدر تبعت **عينات ملفات**؟<br>What history migrates & how many years? Sample files? | نطاق الهجرة غير محدّد | 🔴 | |
| Q-MIG-2 | أصحاب المصلحة بيستخدموا **Oracle/SAP/Odoo** — محتاج **صيغة تصدير** معيّنة؟<br>Export format needed (Excel/Odoo-importable)? | Excel/PDF | 🟡 | |
| Q-INV-1 | **e-invoicing (ETA)** — النظام دلوقتي **Mock** (مفيش حاجة راحت للمصلحة). محتاجين بيانات الدخول والشهادة والأكواد الحقيقية للتفعيل.<br>ETA e-invoicing runs in mock; need live credentials/cert/codes. | Mock (`ETA_MOCK=true`) | 🔴 | |

## ٤.٩ · الموازنة (مقارنة الفعلي بالموازنة) / Budget vs actual

> **ليه بنسأل قبل ما نبني:** قايمة الدخل بقت بتقارن بـ **الفترة السابقة** و**نفس الفترة من السنة اللي فاتت** (شُحنت 2026-08-12). المقارنة بالموازنة هي الطلب التالي، وهي **مش عمود زيادة** — هي جدول جديد وشاشة إدخال، وشكلها بيتحدّد بإجابتك. لو بنينا الشكل الغلط، هتبقى شاشة محدش يقدر يستخدمها.
>
> **Why we ask before building:** the income statement now compares to prior period and prior year. Budget-vs-actual is the next ask and is **not a column** — it is a table plus an entry screen, and the shape depends on these answers.

| # | السؤال / Question | الافتراض لو مجاوبتش / Default if unanswered | أهمية | الإجابة |
|---|---|---|---|---|
| Q-BUD-1 | الموازنة بتتحط **لكل حساب في الشجرة**، ولا على مستوى أعلى (مجموعة/قسم)؟<br>Budget per chart ACCOUNT, or at a higher grouping? | لكل حساب / per account | 🟠 | |
| Q-BUD-2 | **شهرية** (12 رقم للسنة) ولا **رقم سنوي واحد** يتوزّع بالتساوي؟ لو شهرية — بتتعمل موسمية (رمضان/المدارس)؟<br>Monthly figures, or one annual figure spread evenly? Seasonal weighting? | شهرية / monthly | 🟠 | |
| Q-BUD-3 | **لكل عقار** ولا للمحفظة كلها؟ (ملاحظة: الملاك مختلفين لكل مول، وحزمة المالك بتتبني لكل عقار.)<br>Per property or portfolio-wide? Owners differ per mall. | لكل عقار / per property | 🟠 | |
| Q-BUD-4 | بتتعمل **موازنة مُعدَّلة** وسط السنة؟ لو أيوه — بنحتفظ بالأصلية والمعدّلة والاتنين يتقارنوا، ولا المعدّلة بتحلّ محل الأصلية؟<br>Revised budgets mid-year? Keep original + revision, or overwrite? | نسخة واحدة تُستبدل / single, overwritten | 🟠 | |
| Q-BUD-5 | مين له حق **إدخال/تعديل** الموازنة؟ ومحتاجة **اعتماد** قبل ما تظهر في التقارير؟<br>Who may enter/edit a budget, and does it need approval before it appears on reports? | `accounting` يدخل، من غير اعتماد | 🟠 | |
| Q-BUD-6 | هتدخلوها **من ملف Excel** (الأرجح) ولا من الشاشة؟ لو ملف — ابعت **عيّنة** بالأعمدة اللي بتستعملوها.<br>Imported from Excel or typed? If imported, send a sample with your columns. | استيراد CSV + شاشة / import + screen | 🟠 | |
| Q-BUD-7 | الانحراف يتعرض **بالقيمة والنسبة** — وإشارته إيه؟ (مصروف أقل من الموازنة = انحراف **إيجابي** عندك؟)<br>Variance shown as amount and %, and which sign is "good" for an expense? | قيمة + نسبة، بدون حكم "جيد/سيئ" | 🟡 | |

---

---

## التوقيع / Sign-off

| القسم / Section | المسؤول / Owner | التاريخ / Date |
|---|---|---|
| شجرة الحسابات + الربط + الضرائب + الرواتب + الأصول / COA, mapping, tax, payroll, assets | *المحاسب / accountant* | |
| مُلّاك الوحدات (٤.٧): حساب أتعاب الإدارة + حساب صندوق الصيانة / Unit owners (4.7): management-fee income account + sinking-fund liability account — **دول بس اللي واقفين قدام آخر جزء من الميزة / the only blockers on the last piece** | *المحاسب / accountant* | |
| الموازنة (٤.٩) + ترقيم المستندات (Q-COA-5) + الدمغة وضريبة الجدول (Q-TAX-7) / Budget, numbering, stamp & schedule tax | *المحاسب / accountant* | |

**مرتبط بـ / Related:** [OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md) (سجل كل الأسئلة الداخلي) · [BUSINESS-RULES.md](../BUSINESS-RULES.md) (كل قاعدة + مستوى المخاطرة) · [21-general-ledger.md](../modules/21-general-ledger.md) (تفاصيل موديول الأستاذ العام).

> **للتأكيد بعد الاجتماع / Verify after the meeting:** `php artisan billing:reconcile --deep` — بيعيد اشتقاق المدينون/الدائنون من المستندات المصدرية ويطابقهم على الأستاذ العام ويطبع الإجماليات (مفوتر/محصّل/دائن/مدينون قائمة/ض.ق.م) عشان تراجعهم على دفاترك.
