# الصيانة الوقائية والموردون

<p class="eyebrow">دورتا حياة مساندتان</p>

أفضل طلب هو الذي لا يحدث أصلًا. **الصيانة الوقائية** تخدم المعدات وفق جدول فلا تتعطل؛ و**الموردون** هم الجهات الخارجية التي تنفّذ العمل في الغالب. وكلاهما يعمل على آلة حالات خاصة به.

## من خطة إلى عمل منجَز

<p class="sub">الخطة الدورية تصدر أمر شغل عند استحقاقها — تلقائيًا، في كل دورة.</p>

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">الخطة</span><span class="d">«خدمة فلاتر التكييف، شهريًا» — مع قائمة فحص.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">الاستحقاق</span><span class="d">يرى المسح الليلي أن موعد الاستحقاق التالي قد حلّ.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٣</span><span class="t">أمر الشغل</span><span class="d">يصدر عمل واحد (الحالة: مفتوح)، وتُنسَخ قائمة الفحص إليه.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٤</span><span class="t">الإنجاز</span><span class="d">ينهي الفريق قائمة الفحص؛ وتنتقل الخطة إلى الدورة التالية.</span></div></div>

## دورة حياة أمر الشغل

<div class="track"><span class="pill p-amber">مفتوح<small>صدر</small></span><span class="conn">→</span><span class="pill p-teal">قيد التنفيذ<small>يُعمل عليه</small></span><span class="conn">→</span><span class="pill p-green">منجَز<small>اكتمل</small></span></div>

<div class="branch"><div class="row"><span class="pill p-red">ملغى</span><span>أُوقف قبل الإنجاز. وهو كـ<b>المنجَز</b> نهائي وغير قابل للتعديل — فلا يمكن إعادة فتح عمل منتهٍ أو ملغى.</span></div></div>

<div class="plain">المولِّد دقيق: فهو <b>يصدر أمر شغل واحدًا لكل خطة مستحقة، ثم يقدّم تاريخ استحقاقها التالي دورةً واحدة</b> — وثمة حاجز يمنع أمرًا ثانيًا لدورة لها أمر بالفعل. والخطة التي ظلّت خاملة تلحق الركب دورةً واحدة في كل تشغيل، فلا تُغرقك بسنة من الأعمال الفائتة دفعة واحدة.</div>

## دورة حياة عقد المورّد

<p class="sub">يعمل الموردون بعقود مؤرَّخة؛ ويُنهيها أتريوم يوم انقضائها.</p>

<div class="track"><span class="pill p-grey">مسودة<small>قيد الإعداد</small></span><span class="conn">→</span><span class="pill p-green">نشط<small>ساري</small></span><span class="conn">→</span><span class="pill p-grey">منتهٍ<small>انقضى تاريخ نهايته</small></span></div>

<div class="branch"><div class="row"><span class="pill p-red">مفسوخ</span><span>أُنهي مبكرًا، باختيار الطرفين.</span></div></div>

<div class="rule"><span class="lbl">قاعدة · العقود تنتهي من تلقاء نفسها</span>مسح <code>vendors:expire-contracts</code> يقلب العقد <b>النشط</b> إلى <b>منتهٍ</b> لحظة انقضاء تاريخ نهايته — بعد أن يقفل الصف ويعيد التحقق من التاريخ أولًا، وباستخدام تحديث حقيقي ليصل التغيير إلى سجل النشاط. فلا يحتاج أحد أن يتذكّر فعل ذلك.</div>

_مصدر الحقيقة: `app/Services/GeneratePreventiveWorkOrdersService.php` و`app/Models/ServicePlan.php` و`app/Models/VendorContract.php` و`docs/modules/12-vendors.md` و`26-facility.md`._
