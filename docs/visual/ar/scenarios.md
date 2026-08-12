# شهر في حياة المول

<p class="eyebrow">السيناريوهات</p>

[الخريطة](/ar/map) تُظهر ما هو موجود. وهذه الصفحة تُظهره **وهو يعمل** — التسلسلات الحقيقية التي يمر بها المول، بالترتيب الذي تحدث به، والمال والدفاتر يتبعانها.

إن أردت أن تطمئن إلى فهمك لأتريوم من طرفه إلى طرفه، فاقرأ هذه التسعة. فهي تغطي كل نظام فرعي، وكلٌّ منها يسمّي الثابت الذي يجب ألّا يُكسَر.

## الإيقاع

<p class="sub">كل ما تحت يتوقف على هذا الإيقاع. ولا شيء هنا يعتمد على تذكّر شخص لفعل شيء — إلا حيث يُذكَر ذلك صراحةً.</p>

<div class="track"><span class="pill p-grey">اليوم ١<small>تشغيل الفوترة</small></span><span class="conn">→</span><span class="pill p-teal">كل يوم<small>المسوح ومستوى الخدمة ومزامنة الدفتر</small></span><span class="conn">→</span><span class="pill p-amber">نهاية الشهر<small>الإهلاك · المطابقة · الإقفال</small></span><span class="conn">→</span><span class="pill p-green">نهاية السنة<small>تسوية المصروفات المشتركة · قيد الإقفال</small></span></div>

---

## ١ · مستأجر ينتقل إلى الوحدة

<p class="sub">وحدة شاغرة ← عقد موقَّع ← أول فاتورة. بداية كل قصة مال.</p>

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">الوحدة شاغرة</span><span class="d">موجودة، ولها مساحة بالمتر المربع، ولا أحد فيها.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">توقيع العقد</span><span class="d">إيجار ومدة وتأمين وزيادة. وتصبح الوحدة مشغولة.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٣</span><span class="t">ربط الرسوم</span><span class="d">إيجار أساسي + مقابل خدمات + رسم التسويق ٥٪.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٤</span><span class="t">استلام التأمين</span><span class="d">نقدية داخلة — لكنها التزام لا إيراد.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٥</span><span class="t">أول فاتورة</span><span class="d">مقسَّمة نسبيًا إن انتقل في منتصف الشهر.</span></div></div>

<div class="rule"><span class="lbl">ثابت · التأمين ليس لك</span>التأمين <b>مال تحتجزه</b>، لا مال كسبته. فيُقيَّد <b>التزامًا</b> لا إيرادًا أبدًا — ولا يصبح إيرادًا إلا إن صودر. والخطأ في هذا يضخّم الربح ويقلّل ما عليك ردّه.</div>

**للتعمّق:** [حياة عقد الإيجار ←](/ar/leasing/lease-lifecycle) · [التأمينات في الدفاتر ←](/ar/leasing/deposits-in-the-books)

---

## ٢ · تشغيل الفوترة الشهري

<p class="sub">أكبر واقعة مالية منفردة. أمر واحد، وكل العقود، مرة في الشهر.</p>

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">كل عقد نشط</span><span class="d">يُمَرّ عليها واحدًا واحدًا؛ وفشل أحدها يجب ألّا يوقف البقية.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">الرسوم ← بنود</span><span class="d">كل رسم يصبح بندًا في الفاتورة، مقسَّمًا نسبيًا عند اللزوم.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٣</span><span class="t">تطبيق الضريبة</span><span class="d">١٤٪ على مقابل الخدمات. والإيجار الأساسي معفى.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٤</span><span class="t">إصدار الفاتورة</span><span class="d">تُرسَل بالبريد مع ملفها؛ وتُرحَّل إلى الدفتر.</span></div></div>

<div class="rule"><span class="lbl">ثابت · ضريبة ١٤٪، والإيجار معفى</span>مقابل الخدمات يحمل <b>ضريبة ١٤٪</b>؛ و<b>الإيجار الأساسي لا يحملها</b>. و<b>رسم التسويق خمسة بالمئة من الإيجار الأساسي</b>. هذه الأرقام الثلاثة أكثر الثوابت أثرًا في النظام — وهي مسجَّلة في <code>docs/BUSINESS-RULES.md</code> ليعتمدها المحاسب، لا مدفونة في الكود.</div>

<div class="plain"><b>القابلية للتكرار تهم هنا.</b> فإعادة تشغيل الشهر يجب ألّا تفوتر مرتين. والتشغيل آمن للتكرار — وهذا بالضبط ما يجعل أتمتته ممكنة أصلًا.</div>

**للتعمّق:** [حياة الفاتورة ←](/ar/money/invoice-lifecycle)

---

## ٣ · مستأجر يسدّد

<p class="sub">تصل النقدية. والحساب الوحيد الذي يجب ألّا يُكتب يدويًا أبدًا.</p>

<div class="track"><span class="pill p-grey">مسودة<small>غير مستحقة بعد</small></span><span class="conn">→</span><span class="pill p-amber">صادرة<small>مستحقة</small></span><span class="conn">→</span><span class="pill p-teal">مسدَّدة جزئيًا<small>وصل بعضها</small></span><span class="conn">→</span><span class="pill p-green">مسدَّدة<small>الرصيد صفر</small></span></div>

<div class="rule"><span class="lbl">ثابت · recomputeTotals()</span>لا يُضبَط <b>المسدَّد</b> ولا <b>الرصيد</b> مباشرةً أبدًا. بل يُعاد اشتقاقهما دائمًا:<br><br><code>المسدَّد = دفعات محصّلة + إشعارات دائنة مُطبَّقة + رصيد دائن للمستأجر + تأمين مُقاصّ</code><br><code>الرصيد = الإجمالي − المسدَّد</code><br><br>فالدفعة الواحدة قد تسدّد عدة فواتير؛ والفاتورة الواحدة قد تأخذ عدة دفعات. ولهذا يجب أن يُعاد <em>احتسابه</em>، لا أن يُخزَّن يدويًا. وحين يبلغ الرصيد صفرًا تنقلب الفاتورة إلى مسدَّدة من تلقاء نفسها. <b>هذه المعادلة الواحدة، في مكان واحد، هي سبب إمكانية الوثوق بالأرقام.</b></div>

**للتعمّق:** [حياة الفاتورة ←](/ar/money/invoice-lifecycle) · [ماذا يحدث في الدفاتر ←](/ar/money/the-books)

---

## ٤ · مستأجر يعترض على رسم

<p class="sub">العمود الفقري يسير عكسيًا. والإشعار الدائن ليس فاتورة سالبة.</p>

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">رفع الاعتراض</span><span class="d">فُوتر بشيء غير صحيح.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">إصدار إشعار دائن</span><span class="d">مستند قائم بذاته، وله اعتماده الخاص.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٣</span><span class="t">تطبيقه على الفاتورة</span><span class="d">يسدّد الرصيد تمامًا كما تفعل الدفعة.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٤</span><span class="t">عكس الدفاتر</span><span class="d">يخرج الإيراد. ويحتفظ أثر المراجعة بالاثنين.</span></div></div>

<div class="plain">ولمَ لا نعدّل الفاتورة فحسب؟ لأن <b>الفاتورة الصادرة إفادة قدّمتها لعميل ولمصلحة الضرائب.</b> فأنت لا تعيد كتابة التاريخ؛ بل تصدر مستندًا مصحِّحًا. وللسبب نفسه يُلغي الدفتر ولا يعدّل.</div>

**للتعمّق:** [حياة الإشعار الدائن ←](/ar/money/credit-note-lifecycle)

---

## ٥ · شيء يتعطّل

<p class="sub">الحلقة التشغيلية — والموضع الوحيد الذي يمكن للمول فيه أن يحمّل مقاولًا ثمن تأخره.</p>

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">ظهور العطل</span><span class="d">يبلّغ عنه مستأجر، أو يرسب فحص وقائي.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">إصدار أمر شغل</span><span class="d">فريق داخلي أو مورّد خارجي — لا الاثنان معًا.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٣</span><span class="t">تشغيل ساعة الخدمة</span><span class="d">لكل عقار ولكل أولوية. وتبدأ عند القبول.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٤</span><span class="t">سحب قطع الغيار</span><span class="d">يخرج المخزون من المستودع — ويُرحَّل تكلفةً.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٥</span><span class="t">تأخّر؟ غرامة</span><span class="d">تُخصَم من فاتورة المورّد التالية.</span></div></div>

<div class="rule"><span class="lbl">قاعدة · الغرامة تخفّض تكلفة ولا تكون إيرادًا</span>المال الآتي من مورّد يُفترَض أنه <b>يعدّل الثمن الذي دفعته له</b>، لا أنه إيراد جديد. ولهذا فغرامة مستوى الخدمة تجعل <b>المصروف نفسه</b> الذي حمّلته الفاتورة دائنًا — فالغرامة تتبع التكلفة. وبلا ضريبة: فالتعويضات المتفق عليها تعويض لا توريد.<br><br><b>⚠️ والمصروفات المشتركة لا تتبع تلقائيًا.</b> فإنفاق السنة الفعلي على المصروفات المشتركة يُدخله مشغّل يدويًا، ولا يُقرأ من الدفتر. ومن يسجّله عليه أن يستخدم الرقم <b>صافيًا من الغرامات</b> — وإلا سدّد المستأجرون زيادةً واحتفظ المول بالغرامة.</div>

**للتعمّق:** [حياة الطلب ←](/ar/operations/request-lifecycle) · [الصيانة الوقائية والموردون ←](/ar/operations/preventive-and-vendors)

---

## ٦ · محل يحقق سنة جيدة

<p class="sub">الإيجار النسبي — يأخذ المول حصة فوق حدٍّ معيّن.</p>

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">إقرار المبيعات</span><span class="d">يُبلّغ المستأجر بما باعه.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">الإقفال</span><span class="d">تُجمَّد الأرقام المُقرّة؛ ويُبلَّغ المستأجر.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٣</span><span class="t">اختبار حد التعادل</span><span class="d">لا تُحتسب إلا المبيعات فوق الحد.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٤</span><span class="t">تفويتر الفائض</span><span class="d">في فاتورة خاصة به، فورًا.</span></div></div>

<div class="plain">المبيعات <b>يدوية مرتين</b> اليوم: فالمستأجر يرفع تقريرًا، والموظفون ينقلون الرقم منه. وتكامل نقاط البيع على خارطة الطريق — وهي فجوة معلومة لا مخفية.</div>

**للتعمّق:** [الإيجار النسبي، محسوبًا ←](/ar/modules/)

---

## ٧ · استرداد التكاليف المشتركة

<p class="sub">المصروفات المشتركة — تسوية السنة الكبرى، وأصعب قاعدة في النظام.</p>

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">تجميع الوعاء</span><span class="d">أمن، ونظافة، وكهرباء المناطق المشتركة — طوال السنة.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">تفويتر التقديرات</span><span class="d">يسدّد المستأجرون شهريًا مقابل تقدير.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٣</span><span class="t">انتهاء السنة</span><span class="d">يُقارَن الإنفاق الفعلي بما حُصِّل.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٤</span><span class="t">التسوية</span><span class="d">تقسيم بالتناسب مع المساحة المؤجَّرة بالمتر المربع.</span></div></div>

<div class="rule"><span class="lbl">ثابت · التسوية تسير في الاتجاهين، بطريقتين مختلفتين</span><b>حُصِّل أقل من اللازم</b> ← فاتورة استرداد فورية.<br><b>حُصِّل أكثر من اللازم</b> ← <b>إشعار دائن</b>، يُطبَّق تلقائيًا — <em>لا</em> رسم سالب.<br><br>فالرسم السالب يُفسد تشغيل الفوترة التالي. وهذه قاعدة اكتُسبت بصعوبة عبر أربع جولات مراجعة؛ فلا تكسرها من جديد.</div>

**للتعمّق:** [استرداد المصروفات المشتركة ←](/ar/operations/cam-recovery)

---

## ٨ · صرف رواتب الموظفين

<p class="sub">خروج المال، والجزء الذي لا يخص المستأجرين إطلاقًا.</p>

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">مسيرة الرواتب</span><span class="d">إجمالي، وضريبة مستقطَعة، وتأمين مستقطَع، وصافٍ.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">مقاصّة السلف</span><span class="d">السلفة المأخوذة سابقًا تُستردّ هنا.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٣</span><span class="t">قسائم الرواتب</span><span class="d">ملف ثنائي اللغة لكل موظف.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٤</span><span class="t">الدفاتر</span><span class="d">مدين مصروف الرواتب / دائن الاستقطاعات + دائن البنك.</span></div></div>

<div class="plain">وما يُ<b>ستقطَع</b> ليس لك أيضًا — فضريبة الرواتب والتأمينات الاجتماعية التزامات حتى تورّدها، تمامًا كالتأمين.</div>

**للتعمّق:** [الرواتب ←](/ar/people/payroll) · [السلف والعهد ←](/ar/people/advances-and-custody)

---

## ٩ · إقفال الشهر

<p class="sub">نهاية القصة. وحيث يجب أن يتطابق كل خيط مما سبق.</p>

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">مزامنة الدفتر</span><span class="d">كل مستند رُحِّل؛ والمسح يشفي ما لم يُرحَّل.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">المطابقة</span><span class="d">يُعاد اشتقاق الدفاتر من المصدر وتُقارَن.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٣</span><span class="t">القوائم</span><span class="d">ميزان المراجعة والدخل والميزانية والتدفقات.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٤</span><span class="t">إيصاد الفترة</span><span class="d">أُقفلت. وتُرفض الترحيلات إليها.</span></div></div>

<div class="rule"><span class="lbl">البوابة · billing:reconcile</span><code>php artisan billing:reconcile</code> يعيد <b>اشتقاق الدفاتر من المصدر</b> مستقلًّا — البنود، والتوزيعات المحصّلة، والأرصدة الدائنة المُطبَّقة — ويتأكد أن الإجماليات المخزَّنة توافقها. وهو للقراءة فقط؛ ويخرج بقيمة غير صفرية عند أي اختلاف.<br><br><b>شغّله قبل أي إقفال أو إقرار ضريبي.</b> فهو الفرق بين تصديق الأرقام ومعرفتها. وتطابقا <b>الدفتر ↔ الذمم المدينة</b> و<b>الدفتر ↔ الذمم الدائنة</b> هما الأهم: فإن لم تساوِ ذمم الدفتر مجموع الفواتير المفتوحة، فثمة خطأ فيما سبق.</div>

<div class="plain"><b>الإقفال باب ذو اتجاه واحد — عن قصد.</b> فبمجرد إيصاد الفترة، لا يعود المستند المؤرَّخ بداخلها قادرًا على الترحيل. ولهذا تفحص بوابة الإقفال المستندات <em>المؤرَّخة</em> داخل الفترة والتي لم تُرحَّل بعد: فالإقفال فوق واحد منها يتركه عالقًا إلى الأبد.</div>

**للتعمّق:** [الإقفال والتسويات ←](/ar/accounting/close-and-reconcile)

---

## ما لا تغطيه هذه الصفحة

الصدق بشأن الحدود جزء من الخريطة:

- **كشوف المُلّاك ومستحقاتهم** — الكشف الدوري الذي يُسلَّم للمالك، وأمر الصرف الذي يسدّده. شُحن؛ ومرسوم في [كل الوحدات](/ar/modules/) لا هنا.
- **المشتريات** — طلب شراء ← اعتماد ← أمر ← استلام بضاعة، وإقفال «بضاعة لم تُفوتَر» عند وصول فاتورة المورّد. شُحن؛ وآلة حالاته في [كل الوحدات](/ar/modules/).
- **نسبة العطل إلى مُسبِّبه** — تحميل الإصلاح على المستأجر الذي تسبّب فيه. لم يُبنَ؛ وهو أكبر فجوة تجارية مفتوحة.
- **تقسيم الإيرادات** بين المالك والمُشغِّل — مؤجَّل بانتظار ورشة مالية.

والحالة الحيّة لكلٍّ منها في `docs/ROADMAP.md` — القائمة المرتّبة الوحيدة.

_مصدر الحقيقة لكل قاعدة أعلاه: `docs/modules/NN-*.md` و`docs/BUSINESS-RULES.md`._
