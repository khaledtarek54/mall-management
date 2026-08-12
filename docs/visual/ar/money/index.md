# المال والذمم المدينة

<p class="eyebrow">العمود الفقري</p>

كل ما يتعلق بالمال في أتريوم يتفرّع عن مسار واحد. افهم هذا الخط، وتصبح بقية المنظومة مقروءة — فكل جزء آخر إمّا **يغذّي الفاتورة** وإمّا **يُنفق مالًا**، وكله ينتهي في الدفاتر.

## كيف يتحرك المال داخل المول

<p class="sub">اقرأه من اليمين إلى اليسار. كل صندوق شيء حقيقي يمكنك فتحه في التطبيق.</p>

<div class="flow"><div class="step"><span class="n">٠١</span><span class="t">العقد</span><span class="d">يوقّع المستأجر. وهذا يثبّت الإيجار ومقابل الخدمات والمدة.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٢</span><span class="t">الرسوم</span><span class="d">إيجار + مقابل خدمات + رسم تسويق تُربَط بالعقد.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٣</span><span class="t">الفاتورة</span><span class="d">مرة كل شهر تتحول الرسوم إلى فاتورة، بضريبة حيث تنطبق.</span></div><span class="arrow">→</span><div class="step"><span class="n">٠٤</span><span class="t">الدفعة</span><span class="d">يسدّد المستأجر. فينخفض رصيد الفاتورة نحو الصفر.</span></div><span class="arrow">→</span><div class="step hl"><span class="n">٠٥</span><span class="t">الدفاتر</span><span class="d">كل خطوة أعلاه تُرحَّل إلى الدفتر تلقائيًا.</span></div></div>

**الإشعار الدائن** هو الشيء الوحيد خارج هذا الخط — فهو يسير *عكسه*، مخفّضًا ما على المستأجر (ردّ أو تسوية ودّية). ويسدّد الفاتورة تمامًا كما تفعل الدفعة.

## القاعدة الواحدة التي تُبقي المال صادقًا

<div class="rule"><span class="lbl">ثابت · recomputeTotals()</span>لا يُكتب <b>المسدَّد</b> ولا <b>الرصيد</b> يدويًا أبدًا. بل يعيد أتريوم احتسابهما دائمًا من الوقائع:<br><br><code>المسدَّد = دفعات محصّلة + إشعارات دائنة مُطبَّقة + رصيد دائن للمستأجر + تأمين مُقاصّ</code><br><code>الرصيد = الإجمالي − المسدَّد</code><br><br>وحين يبلغ الرصيد صفرًا، تنقلب الفاتورة إلى <b>مسدَّدة</b> من تلقاء نفسها. هذه المعادلة الواحدة — في مكان واحد من الكود — هي سبب إمكانية الوثوق بالأرقام. ولا يُسمح لأي شيء آخر بضبط رصيد مباشرة.<br><br><b>القنوات أربع لا اثنتان</b>، وكل حساب يقرر «كم سُدِّد من هذه الفاتورة» يجب أن يَعُدّ الأربع. والأولى وحدها نقدية: فالإشعار الدائن والرصيد الدائن للمستأجر والتأمين المُقاصّ يسدّد كلٌّ منها فاتورة دون أن يصل جنيه واحد. وإغفال واحدة هو كيف يُدفَن فائض كذمم مدينة سالبة.</div>

## السجلات، وكيف تتصل ببعضها

<p class="sub">العناصر القليلة التي يتكوّن منها العمود الفقري للمال، ومن يرتبط بمن.</p>

<div class="emap"><div class="enode"><span class="name">العقد</span><span class="role">التعاقد مع المستأجر</span><span class="rels"><span class="rel">يتبع مستأجرًا</span><span class="rel has">له رسوم كثيرة</span><span class="rel has">له فواتير كثيرة</span></span></div><div class="enode"><span class="name">الرسم</span><span class="role">بند متكرر — إيجار، خدمات، رسم تسويق</span><span class="rels"><span class="rel">يتبع عقدًا</span></span></div><div class="enode"><span class="name">الفاتورة</span><span class="role">فاتورة شهر واحد</span><span class="rels"><span class="rel">تتبع عقدًا</span><span class="rel">تتبع مستأجرًا</span><span class="rel has">لها بنود كثيرة</span><span class="rel">تُسدَّد بدفعات</span></span></div><div class="enode"><span class="name">بند الفاتورة</span><span class="role">سطر واحد على الفاتورة (بضريبته)</span><span class="rels"><span class="rel">يتبع فاتورة</span></span></div><div class="enode"><span class="name">الدفعة</span><span class="role">مال مقبوض، يُوزَّع على الفواتير</span><span class="rels"><span class="rel">تتبع مستأجرًا</span><span class="rel">تُطبَّق على فواتير</span></span></div><div class="enode"><span class="name">الإشعار الدائن</span><span class="role">يخفّض ما على المستأجر</span><span class="rels"><span class="rel">يتبع مستأجرًا</span><span class="rel">يُطبَّق على فواتير</span></span></div></div>

<div class="plain">العلاقة بين <b>الدفعة</b> و<b>الفاتورة</b> متعددة الطرفين: فالدفعة الواحدة قد تسدّد عدة فواتير، والفاتورة الواحدة قد تُسدَّد بعدة دفعات. ولهذا يُعاد <em>احتساب</em> الرصيد ولا يُخزَّن يدويًا أبدًا.</div>

## للتعمّق أكثر

- **[حياة الفاتورة ←](/ar/money/invoice-lifecycle)** — كل مرحلة تمر بها الفاتورة
- **[حياة الإشعار الدائن ←](/ar/money/credit-note-lifecycle)** — كيف يعمل الردّ أو التسوية
- **[ماذا يحدث في الدفاتر ←](/ar/money/the-books)** — القيود الفعلية مرسومة كبطاقات

_القواعد المكتوبة والحالات الحدّية: `docs/modules/05-billing-invoices.md`، `06-payments.md`، `07-credit-notes.md`._
