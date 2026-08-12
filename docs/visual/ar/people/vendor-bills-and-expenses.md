# فواتير الموردين والمصروفات

<p class="eyebrow">دورة حياة + الدفاتر</p>

حين ينفّذ مورّد عملًا، تصبح مدينًا له — وتلك هي **الذمم الدائنة**، صورة ذمم المستأجرين في المرآة. فاتورة المورّد تُعتمد، ثم تُسدَّد على مراحل. أما التكاليف الصغيرة الفورية فتتخطى مرحلة الأجل وتُقيَّد **مصروفات** مباشرةً.

## دورة حياة فاتورة المورّد

<div class="track"><span class="pill p-grey">مسودة<small>أُدخلت</small></span><span class="conn">→</span><span class="pill p-teal">معتمدة<small>صارت التزامًا</small></span><span class="conn">→</span><span class="pill p-teal">مسدَّدة جزئيًا<small>سُدِّد بعضها</small></span><span class="conn">→</span><span class="pill p-green">مسدَّدة<small>أُقفلت</small></span></div>

<div class="branch"><div class="row"><span class="pill p-red">ملغاة</span><span>أُبطلت — لكن ذلك <b>يُرفض</b> إن كانت قد سُدِّدت عليها أي دفعة (اعكس الدفعات أولًا).</span></div></div>

## ماذا تُرحِّل الفاتورة

<p class="sub">مقاول نظافة يفوتر ١١٬٤٠٠ (١٠٬٠٠٠ عن العمل + ١٬٤٠٠ ضريبة)، وتُسدَّد لاحقًا من البنك.</p>

<div class="books"><div class="tcard"><div class="cap">الاعتماد — الاعتراف بالفاتورة</div><p class="say">تُقيَّد التكلفة الآن؛ وتصبح مدينًا للمورّد بالإجمالي كله.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">التكلفة</span><br><small>51104001 · نظافة وأمن</small></td><td class="amt dr">مدين ١٠٬٠٠٠</td></tr><tr><td class="acc"><span class="dr">ضريبة قابلة للاسترداد</span><br><small>11401001 · ضريبة قيمة مضافة قابلة للاسترداد</small></td><td class="amt dr">مدين ١٬٤٠٠</td></tr><tr><td class="acc"><span class="crc">مستحق للمورّد</span><br><small>21101001 · ذمم موردين دائنة</small></td><td class="amt crc">دائن ١١٬٤٠٠</td></tr></table></div><div class="tcard"><div class="cap">السداد — إقفال الفاتورة</div><p class="say">تخرج النقدية؛ ويُقفَل ما كان مستحقًا عليك.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">لم يعد مستحقًا</span><br><small>21101001 · ذمم موردين دائنة</small></td><td class="amt dr">مدين ١١٬٤٠٠</td></tr><tr><td class="acc"><span class="crc">نقدية خارجة</span><br><small>11102001 · حساب بنكي</small></td><td class="amt crc">دائن ١١٬٤٠٠</td></tr></table></div></div>

<div class="rule"><span class="lbl">قاعدة · ضريبة المدخلات مال يعود، والرصيد مشتق</span><b>الضريبة القابلة للاسترداد</b> التي تدفعها للمورّد <b>أصل</b> — تستردّها من مصلحة الضرائب مقابل الضريبة التي <em>تحمّلها</em> على المستأجرين. وتمامًا كفاتورة المستأجر، فإن <b>المسدَّد</b> و<b>الرصيد</b> على فاتورة المورّد يُجمَعان من دفعاتها ولا يُضبَطان يدويًا أبدًا — فتبقى الذمم الدائنة مطابقة لفواتير حقيقية غير مسدَّدة.</div>

## تُدفع فورًا — المصروفات والتسويق

<div class="plain">ليس كل شيء يمر عبر التزام آجل. فـ<b>المصروف المباشر</b> (نثريات، أو بند لمرة واحدة) يُقيَّد ويُدفع في آن: <b>مدين</b> التكلفة + <b>مدين</b> الضريبة القابلة للاسترداد / <b>دائن</b> النقدية أو البنك — بلا مرحلة اعتماد، لأن المال خرج بالفعل. و<b>إنفاق التسويق</b> بالشكل نفسه (<b>مدين</b> 51105001 تسويق / <b>دائن</b> النقدية)، ويستهلك صندوق الترويج الخاص بالعقار — والجانب الآخر منه هو <b>رسم التسويق ٥٪</b> الذي تفوتره على المستأجرين. وصحة الصندوق ببساطة هي <em>الرسم المحصَّل − الإنفاق</em>.</div>

_مصدر الحقيقة: `app/Services/Accounting/Journalizers/{VendorBill,VendorBillPayment,Expense,MarketingSpend}Journalizer.php` و`docs/modules/13-marketing.md`._
