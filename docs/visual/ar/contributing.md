# الإضافة إلى هذا الدليل

<p class="eyebrow">لفريق العمل</p>

هذا الدليل وُضع ليُ**عدَّل**، لا ليُتأمَّل. وهو ماركداون عادي مع طقم صغير من مكوّنات «الصور» الجاهزة للنسخ. إن كنت تستطيع تعديل ملف نصّي، فأنت تستطيع الإضافة إليه. وهذه الصفحة هي دليل الأسلوب الحيّ — فكل مكوّن أدناه يعرض **النتيجة المعروضة** و**الكود بالضبط** لنسخه.

## البنية

كل نظام فرعي مجلّد تحت `docs/visual/`، ويتبع النمط الثلاثي نفسه:

<div class="emap"><div class="enode"><span class="name">index.md</span><span class="role">المحور — رسم تدفق + خريطة الكيانات + القواعد الأساسية</span></div><div class="enode"><span class="name">…-lifecycle.md</span><span class="role">صفحة لكل سجل له حالات — آلة حالاته</span></div><div class="enode"><span class="name">…-in-the-books.md</span><span class="role">وقائع الدفتر، مرسومة كبطاقات حسابات</span></div></div>

## أضف صفحة في أربع خطوات

1. **أنشئ الملف** — مثلًا `docs/visual/operations/meters.md`. وابدأه بـ `# عنوان` وبطاقة `<p class="eyebrow">…</p>`.
2. **أضفه إلى القائمة** — افتح `docs/visual/.vitepress/config.mts`، وأضف نصًّا إلى خريطتي `en` و`ar`، ثم أضف سطرًا إلى المجموعة المناسبة داخل `allGroups()`:
   ```ts
   { text: t.utilityMeters, link: `${p}/operations/meters` },
   ```
3. **اكتب الصفحة العربية أيضًا** — `docs/visual/ar/operations/meters.md`، ثم أضف رابطها إلى قائمة `only` في لغة `ar`. **هذه القائمة مقصودة:** فالقائمة الجانبية العربية تعرض ما تُرجم بالضبط، فلا يشير عنصر قائمة إلى صفحة غير موجودة. الصفحة غير المترجمة تعني قائمةً أقصر؛ أمّا المدرجة والمفقودة فتعني خطأ ٤٠٤ لن يراه أبدًا من يقرأ الإنجليزية.
4. **شاهده حيًّا** — شغّل `npm run docs:dev` وافتح الرابط المحلي. يُحدَّث فورًا أثناء الكتابة.

هذا كل شيء. ولتغيير الألوان أو الخطوط للموقع كله، عدّل `docs/visual/.vitepress/theme/custom.css`.

## القواعد التي تُبقيه جديرًا بالثقة

<div class="rule"><span class="lbl">قواعد البيت</span><b>١. أسنِد كل حقيقة إلى الكود.</b> أنهِ الصفحة بالملف الذي جاءت منه (<code>مصدر الحقيقة: app/…</code>) ليتمكن القارئ من التحقق. <b>٢. استخدم أكواد الحسابات الحقيقية</b> — وهي في <code>database/seeders/ChartOfAccountsSeeder.php</code>. <b>٣. أبقِ كود كل مكوّن في سطر واحد</b> (بلا فواصل أسطر داخل كتلة <code>&lt;div class="flow"&gt;…&lt;/div&gt;</code>) — فالسطر الفارغ داخل HTML الخام يكسر عارض الماركداون. <b>٤. لا تكتب يدويًا أبدًا عددًا أو قائمة يحملها سجلٌّ بالفعل</b> — بل استخدم مكوّنًا مولَّدًا. فقد تقادمت خمس عبارات في هذا الدليل قبل وجود هذه القاعدة: قنوات السداد، وعدد المُرحِّلات مرتين، وعدد الوحدات، ولوحة كانت قد أُزيلت.</div>

<div class="rule"><span class="lbl">الاتجاه من اليمين · استخدم الخصائص المنطقية</span>يعكس القالب نفسه للعربية <b>بلا أي إضافة برمجية</b>، لأنه يستخدم <code>border-inline-start</code> و<code>margin-inline-end</code> و<code>text-align: start/end</code> بدل left/right. فإن أضفت CSS، فالتزم بالخصائص المنطقية — إذ إن <code>left</code> أو <code>right</code> واحدة هي ما سيُعيد <code>postcss-rtlcss</code> إلى البناء.</div>

---

## طقم المكوّنات

انسخ أي كتلة أدناه، وألصقها في ملف `.md` عندك، وغيّر الكلمات.

### العناوين الصغيرة

<p class="eyebrow">صورة ١ · تدفق</p>
<p class="sub">عنوان فرعي مائل يمهّد للصورة التي تحته.</p>

```html
<p class="eyebrow">صورة ١ · تدفق</p>
<p class="sub">عنوان فرعي مائل يمهّد للصورة التي تحته.</p>
```

### تدفق (خطوات متتابعة)

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">الأولى</span><span class="d">ما يحدث هنا.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">التالية</span><span class="d">ثم هذا.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٣</span><span class="t">النهاية</span><span class="d">أبرِز الوجهة بالصنف <code>step hl</code>.</span></div></div>

```html
<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">الأولى</span><span class="d">ما يحدث هنا.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">التالية</span><span class="d">ثم هذا.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٣</span><span class="t">النهاية</span><span class="d">أبرِز الوجهة بالصنف hl.</span></div></div>
```

### دورة حياة (كبسولات حالات ملوّنة)

<div class="track"><span class="pill p-grey">مسودة<small>غير سارية</small></span><span class="conn">→</span><span class="pill p-amber">معلّقة<small>بالانتظار</small></span><span class="conn">→</span><span class="pill p-green">منجَزة<small>نهائية</small></span></div>

```html
<div class="track"><span class="pill p-grey">مسودة<small>غير سارية</small></span><span class="conn">→</span><span class="pill p-amber">معلّقة<small>بالانتظار</small></span><span class="conn">→</span><span class="pill p-green">منجَزة<small>نهائية</small></span></div>
```

ألوان الكبسولات تحمل معنى — اختر من: `p-grey` (محايد / مسودة)، `p-amber` (انتظار / انتباه)، `p-teal` (قيد التنفيذ)، `p-green` (جيد / منجَز)، `p-red` (مشكلة / نهاية سيئة). واستخدم `<div class="branch">` مع `<div class="row">` للحالات الجانبية مع أوصافها.

### بطاقة حساب (قيد في الدفتر)

<div class="tcard"><div class="cap">الواقعة — ما الذي حدث</div><p class="say">جملة واحدة بسيطة عن الواقعة.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">شيء تملكه</span><br><small>11101001 · الخزينة الرئيسية</small></td><td class="amt dr">مدين ١٬٠٠٠</td></tr><tr><td class="acc"><span class="crc">إيراد تحقق</span><br><small>42101001 · إيرادات متنوعة</small></td><td class="amt crc">دائن ١٬٠٠٠</td></tr></table></div>

```html
<div class="tcard"><div class="cap">الواقعة — ما الذي حدث</div><p class="say">جملة واحدة بسيطة عن الواقعة.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">شيء تملكه</span><br><small>11101001 · الخزينة الرئيسية</small></td><td class="amt dr">مدين ١٬٠٠٠</td></tr><tr><td class="acc"><span class="crc">إيراد تحقق</span><br><small>42101001 · إيرادات متنوعة</small></td><td class="amt crc">دائن ١٬٠٠٠</td></tr></table></div>
```

لفّ بطاقتين داخل `<div class="books">…</div>` لوضعهما جنبًا إلى جنب. واستخدم الصنف `dr` (فيروزي) للسطور المدينة و`crc` (كهرماني) للدائنة.

### خريطة كيانات (من يرتبط بمن)

<div class="emap"><div class="enode"><span class="name">الشيء</span><span class="role">ما هو، في كلمات قليلة</span><span class="rels"><span class="rel">يتبع الأصل</span><span class="rel has">له أبناء كثيرون</span></span></div></div>

```html
<div class="emap"><div class="enode"><span class="name">الشيء</span><span class="role">ما هو، في كلمات قليلة</span><span class="rels"><span class="rel">يتبع الأصل</span><span class="rel has">له أبناء كثيرون</span></span></div></div>
```

استخدم `rel` (فيروزي) لعلاقة *يتبع* و`rel has` (كهرماني) لعلاقة *له كثيرون*.

### التنبيهات

<div class="rule"><span class="lbl">قاعدة · العنوان</span>استخدم <code>rule</code> لقاعدة عمل صارمة أو ثابت يستحق الإبراز في صندوق.</div>
<div class="plain">استخدم <code>plain</code> لملاحظة جانبية أو تعليق من نوع «الطريف هنا أن…».</div>

```html
<div class="rule"><span class="lbl">قاعدة · العنوان</span>استخدم rule لقاعدة عمل صارمة تستحق الإبراز.</div>
<div class="plain">استخدم plain لملاحظة جانبية أو تعليق من نوع «الطريف هنا أن…».</div>
```

---

### المكوّنات التفاعلية

أربعة مكوّنات Vue مسجَّلة عالميًا، فتعمل في أي صفحة وبأي لغة — دون أي استيراد.

```md
<PostingExplorer />                      <!-- كل مصادر الدفتر الأربعة والعشرين، من السجل -->
<StateMachine workflow="work_order" />   <!-- tenant_request | work_order | purchase_request -->
<PercentageRentCalculator />
<VatRateResolver />
```

<div class="rule"><span class="lbl">نوعان، والفرق بينهما مهم</span>المكوّنات <b>المشتقة</b> (<code>PostingExplorer</code> و<code>StateMachine</code>) تقرأ <code>.vitepress/data/*.json</code>، المُعاد توليدها بـ <code>php artisan atriom:dump-handbook-data</code> من سجلات الكود نفسها — فلا يمكنها أن تصف نظامًا غير موجود، ويُفشل اختبار مطابقة البناءَ إن تقادم التوليد. أمّا المكوّنات <b>التوضيحية</b> (الحاسبتان) فتحاكي سطرًا حسابيًا واحدًا وتسمّي الصنف صاحب المرجعية.<br><br><b>ولا تبنِ شيئًا بينهما.</b> فالمكوّن الذي يعيد تنفيذ نصف خدمة يصير رأيًا ثانيًا في المال نفسه — وهذا بالضبط سبب عدم توليد سطور المدين والدائن لكل مُرحِّل: فهي تُحسَم عبر جدول أكواد الرسوم أثناء التشغيل، وأي خريطة لها ستكون تخمينًا مرسومًا في هيئة رسم بياني.</div>

<div class="plain">هذا هو الطقم كله — ستة مكوّنات للرسم وأربعة تفاعلية تغطي كل صفحة في هذا الدليل. وحين تضيف نظامًا فرعيًا، انسخ واحدًا قائمًا (مثل <code>docs/visual/leasing/</code>) كهيكل وبدّل المحتوى. وكل شيء يضبط سمته للوضعين الفاتح والداكن تلقائيًا، ويعكس نفسه للعربية.</div>
