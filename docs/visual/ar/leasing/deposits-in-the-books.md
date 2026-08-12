# التأمينات في الدفاتر

<p class="eyebrow">الدفتر مرسومًا</p>

التأمين هو المال الوحيد الذي يصلك و**ليس لك أن تحتفظ به**. أنت *تحتجزه* لحساب المستأجر — فهو في دفاترك **التزام** (شيء تردّه)، لا إيراد أبدًا. ولا يصبح إيرادًا إلا إذا صادرته. وهذا كيف تُرحَّل كل واقعة، بأكواد حساباتك الحقيقية.

## تستلمه، ثم تردّه

<p class="sub">يدفع المستأجر تأمينًا قدره ٣٠٬٠٠٠ (ثلاثة أضعاف الإيجار الشهري) عند التوقيع، ويستردّه عند مغادرته دون التزامات.</p>

<div class="books"><div class="tcard"><div class="cap">الاستلام — تأمين مدفوع عند التوقيع</div><p class="say">تصل النقدية، لكنها تستقر في <em>التزام</em> — أنت مدين بردّها.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">نقدية بالبنك</span><br><small>11102001 · حساب بنكي</small></td><td class="amt dr">مدين ٣٠٬٠٠٠</td></tr><tr><td class="acc"><span class="crc">تأمين مستحق الرد</span><br><small>21201001 · تأمينات مستأجرين محتجزة</small></td><td class="amt crc">دائن ٣٠٬٠٠٠</td></tr></table></div><div class="tcard"><div class="cap">الرد — المستأجر يغادر دون التزامات</div><p class="say">تردّه، فيُقفَل الالتزام إلى صفر.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">تأمين كان مستحق الرد</span><br><small>21201001 · تأمينات مستأجرين محتجزة</small></td><td class="amt dr">مدين ٣٠٬٠٠٠</td></tr><tr><td class="acc"><span class="crc">نقدية خارجة من البنك</span><br><small>11102001 · حساب بنكي</small></td><td class="amt crc">دائن ٣٠٬٠٠٠</td></tr></table></div></div>

<div class="legend"><span><b class="dr">مدين</b> = أصل يزيد، أو دَيْن عليك <b>ينقص</b></span><span><b class="crc">دائن</b> = دَيْن عليك <b>يزيد</b>، أو إيراد</span></div>

## أو تحتفظ به — عند إخلال المستأجر

<p class="sub">إن أخلّ المستأجر وصودر تأمينه، فالمال الذي كنت تحتجزه يصبح لك أخيرًا.</p>

<div class="tcard"><div class="cap">المصادرة — الاحتفاظ بالتأمين</div><p class="say">يُقفَل الالتزام لا بردّه، بل بالاعتراف به إيرادًا.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">تأمين لم يعد مستحق الرد</span><br><small>21201001 · تأمينات مستأجرين محتجزة</small></td><td class="amt dr">مدين ٣٠٬٠٠٠</td></tr><tr><td class="acc"><span class="crc">وقد صار إيرادًا</span><br><small>42101001 · إيرادات متنوعة</small></td><td class="amt crc">دائن ٣٠٬٠٠٠</td></tr></table></div>

## لماذا يهم هذا

<div class="rule"><span class="lbl">قاعدة · التأمين المحتجَز التزام لا ربح</span>طوال مدة الإيجار يقبع التأمين في <b>21201001 · تأمينات مستأجرين محتجزة</b>. وهو يضخّم رصيدك البنكي، لكن <b>لا يجوز عدّه ربحًا</b> — فقد تضطر لردّ كل جنيه منه غدًا. و<b>المصادرة</b> وحدها هي ما ينقله إلى الإيرادات. الخطأ في هذا طريق كلاسيكي لتضخيم الأرباح، وأتريوم يبقيه صادقًا بوضع كل تأمين في حساب التزام خاص به.</div>

_مصدر الحقيقة: `app/Services/Accounting/Journalizers/DepositTransactionJournalizer.php` و`docs/modules/04-leases.md`._
