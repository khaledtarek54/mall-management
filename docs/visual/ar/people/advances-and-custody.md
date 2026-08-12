# السلف والعهد

<p class="eyebrow">دورة حياة + الدفاتر</p>

طريقتان تصل بهما النقدية إلى يد الموظف — وتُحاسَبان بشكل مختلف تمامًا. **السلفة** قرض يردّه. و**العهدة** رصيد سيُنفقه *لحساب الشركة* ويقدّم عنه حسابًا. ولا واحدة منهما مصروف لحظة تسليمها.

## السلفة — قرض يسدّده الموظف

<p class="sub">لا حقل حالة هنا — فدورة الحياة هي ببساطة الرصيد المتبقي.</p>

<div class="track"><span class="pill p-amber">قائمة<small>المبلغ &gt; المسدَّد</small></span><span class="conn">→</span><span class="pill p-green">مسدَّدة بالكامل<small>الرصيد صفر</small></span></div>

<div class="books"><div class="tcard"><div class="cap">الصرف — ٥٬٠٠٠ سلفة</div><p class="say">هي ذمة مدينة — الموظف مدين بردّها، فهي أصل لا تكلفة.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">مستحق على الموظف</span><br><small>11203001 · سلف موظفين</small></td><td class="amt dr">مدين ٥٬٠٠٠</td></tr><tr><td class="acc"><span class="crc">نقدية خارجة</span><br><small>11102001 · حساب بنكي</small></td><td class="amt crc">دائن ٥٬٠٠٠</td></tr></table></div><div class="tcard"><div class="cap">السداد — خصمًا من الراتب</div><p class="say">تعود النقدية؛ وتُقفَل الذمة المدينة.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">نقدية عائدة</span><br><small>11102001 · حساب بنكي</small></td><td class="amt dr">مدين ٥٬٠٠٠</td></tr><tr><td class="acc"><span class="crc">لم يعد مستحقًا</span><br><small>11203001 · سلف موظفين</small></td><td class="amt crc">دائن ٥٬٠٠٠</td></tr></table></div></div>

## العهدة — رصيد يُنفَق ثم يُحاسَب عليه

<p class="sub">مشتقة أيضًا من رصيدها — مال في يد أمين العهدة حتى يُنفَق أو يُردّ.</p>

<div class="track"><span class="pill p-amber">قائمة<small>المبلغ &gt; المسوَّى</small></span><span class="conn">→</span><span class="pill p-green">مسوّاة بالكامل<small>حوسب عن كل شيء</small></span></div>

<div class="books"><div class="tcard"><div class="cap">الصرف — عهدة ٣٬٠٠٠</div><p class="say">تنتقل النقدية إلى أصل «عهد» — محتجزة لا منفَقة.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">في يد أمين العهدة</span><br><small>11204001 · عهد مستديمة</small></td><td class="amt dr">مدين ٣٬٠٠٠</td></tr><tr><td class="acc"><span class="crc">نقدية خارجة</span><br><small>11102001 · حساب بنكي</small></td><td class="amt crc">دائن ٣٬٠٠٠</td></tr></table></div><div class="tcard"><div class="cap">التسوية — أُنفقت بإيصال</div><p class="say">أُنفق ٢٬٥٠٠ على إصلاحات ← فأصبحت تكلفة حقيقية؛ ورُدّ ٥٠٠ نقدًا.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">تكلفة الصيانة</span><br><small>51102001 · إصلاحات وصيانة</small></td><td class="amt dr">مدين ٢٬٥٠٠</td></tr><tr><td class="acc"><span class="dr">نقدية مردودة</span><br><small>11102001 · حساب بنكي</small></td><td class="amt dr">مدين ٥٠٠</td></tr><tr><td class="acc"><span class="crc">إقفال العهدة</span><br><small>11204001 · عهد مستديمة</small></td><td class="amt crc">دائن ٣٬٠٠٠</td></tr></table></div></div>

<div class="rule"><span class="lbl">قاعدة · ذمة مدينة في مقابل نقدية مستديمة — وليست ذمم مستأجرين أبدًا</span><b>السلفة</b> تقبع في <b>11203001 · سلف موظفين</b> (الموظف مدين بها). و<b>العهدة</b> تقبع في <b>11204001 · عهد</b> (نقديتك، في يده). وكلاهما مُبعَد عمدًا عن ذمم المستأجرين لتبقى مطابقة الذمم المدينة نظيفة. ولا تُسلَّم أيٌّ منهما لموظف منتهية خدمته — فالصرف يتحقق أولًا من أن الموظف على رأس العمل.</div>

_مصدر الحقيقة: `app/Services/{GrantEmployeeAdvance,RecordAdvanceRepayment,GrantCustody,SettleCustody}Service.php` و`docs/modules/25-treasury-custody.md`._
