# المحاسبة والإقفال

<p class="eyebrow">حيث يلتقي كل شيء</p>

كل واقعة مالية في هذا الدليل — فاتورة، دفعة، مسيرة رواتب، تأمين، حركة مخزون، إهلاك شهر — تصبّ في **مكان واحد**: دفتر الأستاذ العام. المحاسبة هي الوجهة، وهي الانضباط الذي يجعله جديرًا بأن يُسلَّم لبنك أو مراجع أو للمالك.

## كل مستند يصبح قيدًا

<p class="sub">أنت لا تُرحِّل إلى الدفتر يدويًا أبدًا. فلكل نوع من المستندات مترجمٌ يعرف مدينه ودائنه.</p>

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">المستند</span><span class="d">فاتورة، دفعة، فاتورة مورّد، مسيرة رواتب، استبعاد…</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">مُرحِّله</span><span class="d">مترجم صغير يعرف مدين ذلك المستند ودائنه.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٣</span><span class="t">الدفتر</span><span class="d">يُرحَّل قيد متوازن تلقائيًا.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٤</span><span class="t">القوائم</span><span class="d">ميزان المراجعة، وقائمة الدخل، والميزانية.</span></div></div>

<div class="rule"><span class="lbl">الجسر · LedgerPoster وأربعة وعشرون مُرحِّلًا</span>مسح ليلي (<code>accounting:sync-ledger</code>) يمرّر كل مستند على مُرحِّله و<b>يشفي نفسه</b>: فإن تغيّر المستند (غرامة تأخير رفعت إجمالي فاتورة)، ألغى القيد القديم ورحّل الجديد؛ وإن فقد المستند أثره (أُلغي أو رُدّ أو حُذف)، ألغى القيد. وقد رأيت عددًا من هؤلاء المترجمين عبر الدليل — والقائمة الكاملة للأربعة والعشرين، مولَّدة من السجل نفسه، في <a href="/ar/modules/">كل الوحدات ←</a></div>

## دليل الحسابات — السلال الخمس

<p class="sub">كل حساب من واحد من خمسة أنواع، ونوعه يحدد أي جانب ينمو فيه طبيعيًا: المدين أم الدائن.</p>

<div class="emap"><div class="enode"><span class="name">الأصول <small>1····</small></span><span class="role">ما تملكه أو ما هو مستحق لك — نقدية، ذمم، مخزون، معدات</span><span class="rels"><span class="rel">تنمو مدينةً</span></span></div><div class="enode"><span class="name">الخصوم <small>2····</small></span><span class="role">ما عليك — ذمم دائنة، ضرائب، تأمينات محتجزة، استقطاعات</span><span class="rels"><span class="rel has">تنمو دائنةً</span></span></div><div class="enode"><span class="name">حقوق الملكية <small>3····</small></span><span class="role">حصة المالك — رأس المال، الأرباح المحتجزة</span><span class="rels"><span class="rel has">تنمو دائنةً</span></span></div><div class="enode"><span class="name">الإيرادات <small>4····</small></span><span class="role">ما تحقق من دخل — إيجار، خدمات، مصروفات مشتركة، رسم تسويق</span><span class="rels"><span class="rel has">تنمو دائنةً</span></span></div><div class="enode"><span class="name">المصروفات <small>5····</small></span><span class="role">ما تحمّلته من تكاليف — رواتب، صيانة، إهلاك</span><span class="rels"><span class="rel">تنمو مدينةً</span></span></div></div>

<div class="plain">الرقم الأول <b>هو</b> النوع (١ أصل، ٢ خصم، ٣ حقوق ملكية، ٤ إيراد، ٥ مصروف) — وهو عرف محاسبي مصري صارم يفرضه أتريوم، فلا يمكن أن يُرمَّز حساب إيراد كمصروف بالخطأ. ولا تقبل القيود إلا الحسابات <b>الطرفية</b> الأعمق؛ أما الحسابات الأب فتجمع الإجماليات فقط. و<b>الرصيد الطبيعي مشتق من النوع</b> — لا يُضبَط يدويًا أبدًا.</div>

## للتعمّق أكثر

- **[الدفتر وقواعده ←](/ar/accounting/the-ledger)** — القيود والفترات وقوانين القيد المزدوج الصارمة
- **[الأصول الثابتة والإهلاك ←](/ar/accounting/fixed-assets)** — الرسملة والإهلاك والاستبعاد
- **[الإقفال والتسويات ←](/ar/accounting/close-and-reconcile)** — المطابقة ونهاية الشهر ونهاية السنة ورؤية المالك

_القواعد المكتوبة كاملة: `docs/modules/21-general-ledger.md`، `23-fixed-assets.md`، `17-reports.md`._
