# التشغيل — إبقاء المول يعمل

<p class="eyebrow">غرفة المحركات</p>

التأجير يُدخل المال؛ و**التشغيل يُنفق ليبقى المبنى جديرًا بالتأجير** — ويسترد ما يمكن استرداده من المستأجرين. وهي أربع مهام في آنٍ واحد: إصلاح ما يتعطّل (**الطلبات**)، ومنع العطل قبل وقوعه (**الخطط**)، واسترداد التكاليف المشتركة (**المصروفات المشتركة والعدادات**)، وإدارة **المخزون والموردين** الذين يغذّون ذلك كله.

## الحلقة التفاعلية — حين يتعطّل شيء

<p class="sub">يبلّغ مستأجر عن مشكلة؛ ويفرزها فريقك وينفّذها ويغلقها — وكل تسليم متتبَّع.</p>

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">الطلب</span><span class="d">يرفع مستأجر مشكلة (بوابة، هاتف، واتساب…).</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">الإسناد</span><span class="d">استلام، وتحديد أولوية، وإسناد إلى شخص أو مورّد.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٣</span><span class="t">التنفيذ</span><span class="d">الإصلاح — باستهلاك قطع غيار من المخزون أو عبر مورّد.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٤</span><span class="t">الحل</span><span class="d">وضعه محلولًا مع ملاحظات؛ ويمكن للمستأجر تقييمه.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٥</span><span class="t">الإغلاق</span><span class="d">مغلق نهائيًا (أو يُغلق تلقائيًا بعد ٧ أيام هدوء).</span></div></div>

## المحركات الدورية — تعمل في الخلفية

<p class="sub">ليس كل شيء ينتظر شكوى. أربعة مسوح مجدولة تُبقي التشغيل منضبطًا من تلقاء نفسها.</p>

<div class="emap"><div class="enode"><span class="name">الصيانة الوقائية</span><span class="role">تصدر أوامر شغل من الخطط المستحقة، فتُخدَم المعدات قبل أن تتعطل</span><span class="rels"><span class="rel">facility:generate-preventive</span></span></div><div class="enode"><span class="name">استرداد المصروفات المشتركة</span><span class="role">يسوّي التكاليف المشتركة ويفوتر كل مستأجر حصته العادلة</span><span class="rels"><span class="rel">cam:reconcile</span></span></div><div class="enode"><span class="name">مستوى الخدمة والإغلاق التلقائي</span><span class="role">يرصد المواعيد المخالَفة؛ ويغلق الطلبات المحلولة منذ مدة</span><span class="rels"><span class="rel">requests:scan-sla-breaches</span></span></div><div class="enode"><span class="name">انتهاء العقود</span><span class="role">ينهي عقود الموردين يوم انقضائها</span><span class="rels"><span class="rel">vendors:expire-contracts</span></span></div></div>

<div class="rule"><span class="lbl">ثابت · كل مسح آمن للتكرار ومحمي بقفل</span>هذه تعمل دون إشراف، فيجب ألّا تتصرف مرتين أبدًا. كلٌّ منها <b>يقفل الصف ويعيد التحقق من الشرط داخل المعاملة</b> قبل التصرف — شغّل أيًّا منها مرتين ولن يحدث شيء في المرة الثانية. وهو الانضباط نفسه عبر النظام كله.</div>

## السجلات، وكيف تتصل ببعضها

<div class="emap"><div class="enode"><span class="name">طلب المستأجر</span><span class="role">مشكلة أو طلب من مستأجر (بأي نوع)</span><span class="rels"><span class="rel">يتبع مستأجرًا · وحدة</span><span class="rel">مُسنَد إلى مستخدم أو مورّد</span><span class="rel has">يستهلك مخزونًا</span></span></div><div class="enode"><span class="name">المورّد</span><span class="role">مورّد / مقاول</span><span class="rels"><span class="rel has">له عقود كثيرة</span><span class="rel has">يتسلّم طلبات</span></span></div><div class="enode"><span class="name">المستودع · الصنف · حركة المخزون</span><span class="role">دفتر المخزون المُضاف إليه فقط</span><span class="rels"><span class="rel">الحركة تتبع مستودعًا · صنفًا</span></span></div><div class="enode"><span class="name">خطة الصيانة ← أمر الشغل</span><span class="role">الجدول الوقائي والأوامر التي يصدرها</span><span class="rels"><span class="rel has">الخطة لها أوامر كثيرة</span></span></div><div class="enode"><span class="name">وعاء المصروفات ← التوزيع</span><span class="role">تكاليف سنة مشتركة، مقسّمة على العقود</span><span class="rels"><span class="rel has">الوعاء له توزيعات كثيرة</span></span></div><div class="enode"><span class="name">العداد ← القراءة</span><span class="role">نقطة قياس وقراءاتها المؤرَّخة</span><span class="rels"><span class="rel has">العداد له قراءات كثيرة</span></span></div></div>

## للتعمّق أكثر

- **[حياة الطلب ←](/ar/operations/request-lifecycle)** — آلة الحالات الكاملة للفرز
- **[استرداد المصروفات المشتركة ←](/ar/operations/cam-recovery)** — كيف تُعاد التكاليف المشتركة بعدالة
- **[المخزون في الدفاتر ←](/ar/operations/inventory-and-books)** — دفتر المخزون وما يُرحِّله
- **[الصيانة الوقائية والموردون ←](/ar/operations/preventive-and-vendors)** — الخطط وأوامر الشغل والعقود

_القواعد المكتوبة كاملة: `docs/modules/08-cam.md`، `10-utility-meters.md`، `11-tenant-requests.md`، `12-vendors.md`، `22-inventory.md`، `26-facility.md`._
