# النظام كله في صفحة واحدة

<p class="eyebrow">الخريطة</p>

أتريوم كبير — ست وثلاثون وحدة، وثلاثة أبواب دخول، ودفتر أستاذ عام تحتها جميعًا. لكنه ليس معقّدًا بستٍّ وثلاثين طريقة مختلفة. **هناك عمود فقري واحد وأربعة أشياء تتفرّع عنه.** افهم العمود الفقري، ويصبح لكل وحدة موضع بديهي.

هذه الصفحة هي الخريطة. وكل صندوق فيها يقود إلى الرسم الذي يشرحه.

## الجملة الواحدة

<div class="rule"><span class="lbl">العمود الفقري</span><b>العقد يحوّل المساحة إلى مال مستحق؛ والفاتورة تحصّله؛ والدفتر يسجّله.</b><br><br>وكل ما عداه إمّا <b>يغذّي الفاتورة</b> (المصروفات المشتركة، الإيجار النسبي، المرافق، رسم التسويق)، وإمّا <b>ينفق مالًا</b> (الرواتب، الموردون، العهد، قطع الغيار)، وإمّا <b>يُبقي المبنى يعمل</b> (الطلبات، الصيانة الوقائية، المخزون). وكله يستقر في الدفاتر نفسها.</div>

## العمود الفقري مرسومًا

<p class="sub">خمس خطوات. إن لم تتعلم عن أتريوم إلا شيئًا واحدًا، فليكن هذا الخط.</p>

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">العقد</span><span class="d">يوقّع مستأجر على وحدة. وهذا يثبّت الإيجار والمدة.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">الرسوم</span><span class="d">إيجار + مقابل خدمات + رسم تسويق تُربَط بالعقد.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٣</span><span class="t">الفاتورة</span><span class="d">شهريًا تتحول الرسوم إلى فاتورة. وتُضاف الضريبة حيث تنطبق.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٤</span><span class="t">الدفعة</span><span class="d">يسدّد المستأجر. فينخفض الرصيد نحو الصفر.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٥</span><span class="t">الدفاتر</span><span class="d">كل خطوة أعلاه تُرحِّل نفسها إلى الدفتر.</span></div></div>

<div class="plain">الخطوة الخامسة هي ما يفوت الناس. <b>أنت لا «تعمل المحاسبة» في أتريوم أبدًا.</b> فكل مستند يُرحِّل قيده بنفسه تلقائيًا — والدفتر نتيجة لتشغيل المول، لا عمل منفصل يتذكّره أحد. <a href="/ar/accounting/the-ledger">كيف يعمل ذلك ←</a></div>

## من يدخل من أين

<p class="sub">ثلاثة أبواب إلى قاعدة بيانات واحدة. ومعظم الالتباس حول أتريوم هو في حقيقته التباس حول الباب.</p>

<div class="emap"><div class="enode"><span class="name">‏/admin</span><span class="role">فريق إلتزام والمُلّاك، محصورون بأدوارهم</span><span class="rels"><span class="rel">الهوية: User + الأدوار</span><span class="rel has">يرى العقار المختار</span></span></div><div class="enode"><span class="name">‏/portal</span><span class="role">التجار، على الويب</span><span class="rels"><span class="rel">الهوية: TenantUser</span><span class="rel has">لا يكتب إلا المسؤولون</span></span></div><div class="enode"><span class="name">‏/api/v1</span><span class="role">التجار، في تطبيق الهاتف</span><span class="rels"><span class="rel">الهوية: Tenant (Sanctum)</span><span class="rel has">عبر المستأجرين يعيد 404</span></span></div></div>

<div class="rule"><span class="lbl">قاعدة · عزل العقارات</span>لوحة الإدارة يكون فيها دائمًا <b>عقار واحد مختار</b> — وكل جدول وكل رقم محصور به. ووجود عقار وهمي باسم <b>«كل العقارات»</b> يعطي رؤية المحفظة. وهذا ليس فلترًا يمكن نسيانه: <b>كل نموذج مصنَّف</b> إمّا مملوكًا لعقار وإمّا مشتركًا، ويُفشل اختبارٌ البناءَ إن شُحن نموذج جديد دون تصنيف.</div>

## الأنظمة الفرعية الخمسة

<p class="sub">كل واحدة من الوحدات الست والثلاثين تعيش في واحد منها بالضبط. وهذا هو المنتج كله.</p>

### 🔑 التأجير — من هنا يبدأ المال

<div class="emap"><div class="enode"><span class="name">٠١ · العقارات والوحدات</span><span class="role">المولات والمحال داخلها</span></div><div class="enode"><span class="name">٠٢ · المستأجرون</span><span class="role">شركات التجزئة</span></div><div class="enode"><span class="name">٠٣ · مستخدمو البوابة</span><span class="role">أشخاص لدى التاجر يسجّلون الدخول</span></div><div class="enode"><span class="name">٠٤ · العقود</span><span class="role">التعاقد — الإيجار والمدة والتأمين والزيادة</span></div></div>

**[شاهدها مرسومة ←](/ar/leasing/)**

### 💵 المال والذمم — العمود الفقري نفسه

<div class="emap"><div class="enode"><span class="name">٠٥ · الفوترة والفواتير</span><span class="role">التشغيل الشهري والضريبة والتقسيم النسبي</span></div><div class="enode"><span class="name">٠٦ · المدفوعات</span><span class="role">دخول النقدية والتوزيع وغرامات التأخير</span></div><div class="enode"><span class="name">٠٧ · الإشعارات الدائنة</span><span class="role">العمود الفقري، عكسيًا</span></div><div class="enode"><span class="name">١٧ · التقارير</span><span class="role">إقفال الشهر وأعمار الذمم والكشوف</span></div></div>

**[شاهدها مرسومة ←](/ar/money/)**

### 🔧 التشغيل — غرفة المحركات

<div class="emap"><div class="enode"><span class="name">٠٨ · المصروفات المشتركة</span><span class="role">تكاليف مشتركة تُسترد بالتناسب</span></div><div class="enode"><span class="name">٠٩ · مبيعات المستأجرين والإيجار النسبي</span><span class="role">حصة مما يبيعه المحل</span></div><div class="enode"><span class="name">١٠ · عدادات المرافق</span><span class="role">استهلاك ← رسم</span></div><div class="enode"><span class="name">١١ · طلبات المستأجرين</span><span class="role">المستأجر يطلب شيئًا</span></div><div class="enode"><span class="name">٢٢ · المخزون</span><span class="role">قطع غيار، ومستودعات لكل مول</span></div><div class="enode"><span class="name">٢٦ · صيانة المرافق</span><span class="role">خطط وقائية وأعمال تصحيحية ومستوى خدمة</span></div><div class="enode"><span class="name">٢٧ · الإعلانات</span><span class="role">من المُشغِّل إلى المستأجرين، بثًّا</span></div></div>

**[شاهدها مرسومة ←](/ar/operations/)**

### 👥 الأفراد وخروج النقدية

<div class="emap"><div class="enode"><span class="name">١٢ · الموردون</span><span class="role">المقاولون وفواتيرهم وعقودهم</span></div><div class="enode"><span class="name">١٣ · التسويق</span><span class="role">رسم الـ ٥٪ والميزانيات والإنفاق</span></div><div class="enode"><span class="name">١٤ · الإدارات</span><span class="role">الهيكل التنظيمي والتوجيه</span></div><div class="enode"><span class="name">٢٤ · الموارد البشرية</span><span class="role">الرواتب والسلف وقسائم الرواتب</span></div><div class="enode"><span class="name">٢٥ · الخزينة والعهد</span><span class="role">نقدية في يد أمين عهدة</span></div><div class="enode"><span class="name">٢٨ · الاعتمادات</span><span class="role">من يعتمد ماذا، بحسب المبلغ</span></div></div>

**[شاهدها مرسومة ←](/ar/people/)**

### 📚 المحاسبة والإقفال — حيث يلتقي كل شيء

<div class="emap"><div class="enode"><span class="name">٢١ · دفتر الأستاذ العام</span><span class="role">دليل الحسابات والقيود والفترات والقوائم</span></div><div class="enode"><span class="name">٢٣ · الأصول الثابتة</span><span class="role">السجل والإهلاك الشهري</span></div><div class="enode"><span class="name">١٥ · طلبات المُلّاك</span><span class="role">المالك يسأل، وإلتزام تجيب</span></div><div class="enode"><span class="name">١٨ · الصلاحيات والنطاق</span><span class="role">من يرى ماذا، وأين</span></div><div class="enode"><span class="name">١٩ · التنبيهات والمسوح</span><span class="role">ما يحدث من تلقاء نفسه</span></div><div class="enode"><span class="name">٢٠ · واجهة الهاتف</span><span class="role">سطح التطبيق</span></div></div>

**[شاهدها مرسومة ←](/ar/accounting/)**

## ما الذي يصل إلى الدفاتر فعلًا

<p class="sub">أربعة وعشرون نوعًا من المستندات تُرحَّل إلى الدفتر. وكلها تُرحَّل بالطريقة نفسها، عبر سجل واحد.</p>

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">يتغيّر مستند</span><span class="d">تصدر فاتورة، أو تُقبض دفعة، أو تُطبَّق غرامة.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">مُرحِّله</span><span class="d">صنف صغير يحوّل ذلك المستند إلى مدين ودائن متوازنين.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٣</span><span class="t">السجل</span><span class="d">‏LedgerPoster يعرف أي مُرحِّل يخص أي مستند.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٤</span><span class="t">أربعة مداخل</span><span class="d">فوريًا عند الحفظ · المسح الليلي · بوابة الإقفال · فحص المطابقة — وكلها مشتقة من ذلك السجل الواحد.</span></div></div>

**[القائمة الحيّة مولَّدة من السجل نفسه ←](/ar/modules/)** — فلا يمكن أن تنحرف عن الكود.

<div class="rule"><span class="lbl">قاعدة · سجل واحد، تعلّمناها بالطريقة الصعبة</span>كانت تلك القائمة <b>منسوخة يدويًا في خمسة مواضع</b>، يربطها تعليق يقول «حافظ على تزامنها». فانحرفت. وكان لـ<b>غرامة مستوى الخدمة</b> مُرحِّل سليم تمامًا بينما كانت غائبة عن كل قائمة <em>تُطلِق</em> واحدًا — فكان تطبيق الغرامة <b>يخصم من فاتورة المورّد ولا يُرحِّل شيئًا</b>، وتُبالغ الدفاتر بهدوء فيما يدين به المول. وكانت الاختبارات تنجح، لأنها كانت تستدعي المُرحِّل مباشرة بدل تشغيل المسح الحقيقي.<br><br>وصار هناك الآن سجل <b>واحد</b>، ويشتق منه كل شيء، ويُفشل اختبار مطابقة البناءَ إن كان لمُرحِّل ما لا مدخل له. <em>أُصلح في ٢٠٢٦-٠٧-١٦.</em></div>

## ما يحدث دون أن يضغط أحد

<p class="sub">تقويم التشغيل. وإن لم يكن المجدول وعامل الطابور يعملان، فلا يحدث شيء من هذا — والفشل صامت.</p>

<div class="track"><span class="pill p-teal">يوميًا<small>مسح التأخير · غرامات التأخر · مخالفات مستوى الخدمة · الإغلاق التلقائي · الصيانة الوقائية · مزامنة الدفتر</small></span><span class="conn">→</span><span class="pill p-amber">شهريًا<small>تشغيل الفوترة · الإهلاك</small></span><span class="conn">→</span><span class="pill p-green">سنويًا<small>تسوية المصروفات المشتركة · إقفال نهاية السنة</small></span></div>

<div class="plain">تشغيل الفوترة الشهري وغرامات التأخير مجدولان كـ<b>مهام في الطابور</b> لا كأوامر — ولهذا فالبحث عن أسمائها كأوامر في المجدول لا يجد شيئًا. وهو التباس شائع ومكلف.</div>

## إلى أين تتعمّق

- **[المال والذمم ←](/ar/money/)** — ابدأ من هنا. العمود الفقري مرسومًا كما ينبغي.
- **[حياة الفاتورة ←](/ar/money/invoice-lifecycle)** — كل مرحلة تمر بها الفاتورة.
- **[الدفتر وقواعده ←](/ar/accounting/the-ledger)** — ما الذي يجعله كل حدث مدينًا ودائنًا.
- **[الإقفال والتسويات ←](/ar/accounting/close-and-reconcile)** — كيف يُقفل الشهر.

_التفصيل المكتوب في `docs/modules/NN-*.md` (ملف لكل وحدة — قواعد العمل ونقاط التوسعة والمزالق). والأعداد والتغطية الحيّة تُولَّد في `docs/PROJECT-MAP.md` عبر `php artisan atriom:dump-system-census`._
