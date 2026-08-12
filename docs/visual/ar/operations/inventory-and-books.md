# المخزون في الدفاتر

<p class="eyebrow">دفتر المخزون مرسومًا</p>

قطع الغيار والفلاتر ومواد النظافة — المخزون هو ما يسحب منه فريقك لإبقاء المول يعمل. ويتتبعه أتريوم كـ**دفتر يُضاف إليه فقط**: لا تعدّل رصيدًا أبدًا، بل تضيف حركات فحسب، والمتاح دائمًا هو **مجموعها**.

## أربعة أنواع من الحركة

<div class="branch" style="border-top:none;padding-top:4px;"><div class="row"><span class="pill p-green">استلام</span><span>يصل مخزون (شراء) — <b>يضيف</b> إلى المتاح.</span></div><div class="row"><span class="pill p-red">استهلاك</span><span>يُستخدم مخزون (قطعة رُكِّبت في إصلاح مثلًا) — <b>يخصم</b> من المتاح.</span></div><div class="row"><span class="pill p-amber">تسوية</span><span>تصحيح جرد — تعديل بإشارة، زيادةً (وُجد) أو نقصًا (فَقْد).</span></div><div class="row"><span class="pill p-grey">تحويل</span><span>نقل بين المستودعات — يصفّى إلى صفر، و<b>لا</b> أثر له في الدفتر.</span></div></div>

<div class="rule"><span class="lbl">ثابت · المتاح مشتق لا مخزَّن</span>لا يوجد حقل «الكمية المتاحة» ليخرج عن التزامن — فالمتاح هو <b>مجموع الحركات بإشاراتها</b>، يُعاد احتسابه في كل مرة. والتصحيحات حركة تسوية <em>جديدة</em>، لا تعديل. ولا يمكن للاستهلاك أن يدفع المخزون إلى السالب أبدًا: فهو يقفل الصنف ويعيد التحقق من التوافر داخل المعاملة، فلا يفوز تذكرتان تتسابقان على آخر قطعة كلتاهما.</div>

## ماذا تُرحِّل كل حركة

<p class="sub">استلام ١٠٠ وحدة بسعر ٥٠ للوحدة (٥٬٠٠٠)، ثم استهلاك إصلاحٍ لعشر وحدات (٥٠٠).</p>

<div class="books"><div class="tcard"><div class="cap">الاستلام — دخول المخزون</div><p class="say">تنتقل القيمة إلى المخزون؛ ويُودَع الطرف «غير المفوتر بعد» في حساب وسيط.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">مخزون متاح</span><br><small>11301001 · المخزون</small></td><td class="amt dr">مدين ٥٬٠٠٠</td></tr><tr><td class="acc"><span class="crc">مستحق، لم تصل فاتورته</span><br><small>21701001 · بضاعة مستلمة لم تُفوتَر</small></td><td class="amt crc">دائن ٥٬٠٠٠</td></tr></table></div><div class="tcard"><div class="cap">الاستهلاك — استخدام قطعة</div><p class="say">يخرج المخزون ويصبح تكلفة صيانة.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">تكلفة الصيانة</span><br><small>51102001 · إصلاحات وصيانة</small></td><td class="amt dr">مدين ٥٠٠</td></tr><tr><td class="acc"><span class="crc">مخزون متاح</span><br><small>11301001 · المخزون</small></td><td class="amt crc">دائن ٥٠٠</td></tr></table></div></div>

<div class="plain"><b>بضاعة مستلمة لم تُفوتَر</b> هي اللمسة الذكية: فحين يصل المخزون قبل فاتورة المورّد، تقبع قيمته في هذا الالتزام الوسيط — <em>لا</em> في الذمم الدائنة — فتبقى ذممك الدائنة مطابقة دائمًا لفواتير موردين حقيقية. وحين تصل الفاتورة، تُقفل ذلك الحساب.</div>

## تصحيح جرد

<p class="sub">تنقص وحدتان عند الجرد (فَقْد) — يُعدَم ١٠٠.</p>

<div class="tcard"><div class="cap">تسوية — فَقْد</div><p class="say">تستقر الخسارة في بند مصروف خاص بها، فيبقى الاستهلاك الحقيقي نظيفًا.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">خسارة مخزون</span><br><small>51108001 · تسوية مخزون</small></td><td class="amt dr">مدين ١٠٠</td></tr><tr><td class="acc"><span class="crc">مخزون متاح</span><br><small>11301001 · المخزون</small></td><td class="amt crc">دائن ١٠٠</td></tr></table></div>

<div class="legend"><span>المخزون الذي يُعثر عليه يعكس هذا — <b class="dr">مدين</b> المخزون / <b class="crc">دائن</b> تسوية المخزون.</span></div>

_مصدر الحقيقة: `app/Services/StockMovementService.php` و`app/Services/Accounting/Journalizers/InventoryMovementJournalizer.php` و`docs/modules/22-inventory.md`._
