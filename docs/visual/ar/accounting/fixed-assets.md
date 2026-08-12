# الأصول الثابتة والإهلاك

<p class="eyebrow">دورة حياة + الدفاتر</p>

الأصل الثابت — مولّد، أو مبرّد، أو أثاث المول — ليس مصروفًا يوم شرائه. بل أصل **ترسمله**، ثم **يبلى** قليلًا كل شهر (الإهلاك) على مدى عمره الإنتاجي، حتى **تستبعده** في النهاية.

## حياة الأصل

<div class="track"><span class="pill p-green">نشط<small>قيد الاستخدام · يُهلَك</small></span><span class="conn">→</span><span class="pill p-grey">مستبعَد<small>بيع أو إعدام</small></span></div>

<div class="plain">الأصل المستبعَد <b>يبقى في الدفاتر</b> — فتاريخه محفوظ. والإهلاك <b>بالقسط الثابت</b>: القسط نفسه كل شهر = (التكلفة − قيمة الخردة) ÷ عدد أشهر العمر الإنتاجي، ومجمّع الإهلاك دائمًا <b>مجموع</b> تلك الأقساط، لا رقمًا مخزَّنًا.</div>

## تشتريه، ثم يبلى

<p class="sub">أصل بـ ١٢٬٠٠٠ على ٦٠ شهرًا ← إهلاك ٢٠٠ شهريًا.</p>

<div class="books"><div class="tcard"><div class="cap">الاقتناء — الرسملة</div><p class="say">تتحول النقدية إلى أصل في الميزانية — لا إلى تكلفة.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">الأصل</span><br><small>12101001 · أثاث ومعدات</small></td><td class="amt dr">مدين ١٢٬٠٠٠</td></tr><tr><td class="acc"><span class="crc">نقدية خارجة</span><br><small>11102001 · حساب بنكي</small></td><td class="amt crc">دائن ١٢٬٠٠٠</td></tr></table></div><div class="tcard"><div class="cap">الإهلاك — بِلى شهر واحد</div><p class="say">تتحول شريحة من التكلفة إلى مصروف؛ وتنخفض قيمة الأصل بهدوء.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">بِلى هذا الشهر</span><br><small>51107001 · مصروف إهلاك</small></td><td class="amt dr">مدين ٢٠٠</td></tr><tr><td class="acc"><span class="crc">القيمة المستهلَكة (حتى الآن)</span><br><small>12201001 · مجمّع الإهلاك</small></td><td class="amt crc">دائن ٢٠٠</td></tr></table></div></div>

## تبيعه — وتسوّي الربح أو الخسارة

<p class="sub">بعد ٤٠ شهرًا يبلغ المجمّع ٨٬٠٠٠، فتصير القيمة الدفترية ٤٬٠٠٠. تبيعه بـ ٥٬٠٠٠ — بربح ١٬٠٠٠.</p>

<div class="tcard"><div class="cap">الاستبعاد — خارج الدفاتر</div><p class="say">تُخرج التكلفة الأصلية ومجمّع بِلاها، وتأخذ النقدية، وتعترف بالفرق.</p><table class="t"><tr><th>الحساب</th><th class="cr">مدين / دائن</th></tr><tr><td class="acc"><span class="dr">عكس البِلى</span><br><small>12201001 · مجمّع الإهلاك</small></td><td class="amt dr">مدين ٨٬٠٠٠</td></tr><tr><td class="acc"><span class="dr">متحصلات البيع</span><br><small>11102001 · حساب بنكي</small></td><td class="amt dr">مدين ٥٬٠٠٠</td></tr><tr><td class="acc"><span class="crc">إخراج التكلفة الأصلية</span><br><small>12101001 · أثاث ومعدات</small></td><td class="amt crc">دائن ١٢٬٠٠٠</td></tr><tr><td class="acc"><span class="crc">ربح البيع</span><br><small>42102001 · أرباح استبعاد أصول</small></td><td class="amt crc">دائن ١٬٠٠٠</td></tr></table></div>

<div class="rule"><span class="lbl">قاعدة · الربح أو الخسارة = المتحصلات − القيمة الدفترية</span>القيمة الدفترية الصافية = التكلفة − مجمّع الإهلاك = ١٢٬٠٠٠ − ٨٬٠٠٠ = <b>٤٬٠٠٠</b>. البيع فوقها ← <b>ربح</b> (42102001)؛ والبيع دونها ← <b>خسارة</b> (52102001). ويصفّي القيد حسابي الأثاث ومجمّع الإهلاك إلى صفر لذلك الأصل، فيخرج من الدفاتر تمامًا. والإهلاك والاستبعاد لا يمسّان الذمم المدينة <em>ولا</em> الدائنة — فلا يمكنهما كسر مطابقتهما أبدًا.</div>

_مصدر الحقيقة: `app/Services/DepreciationService.php` و`app/Services/Accounting/Journalizers/{FixedAssetAcquisition,DepreciationEntry,FixedAssetDisposal}Journalizer.php` و`docs/modules/23-fixed-assets.md`._
