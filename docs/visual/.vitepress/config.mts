import { defineConfig } from 'vitepress'

// Atriom Visual Handbook — an in-repo, browsable "pictures first" reference.
//
// Rooted at docs/visual/ so it builds cleanly and never trips over the Blade snippets ({{ }}) in
// the legacy docs/modules/*.md. Those stay canonical for deep detail; this layer draws the concepts
// on top of them.
//
// BILINGUAL. English is the root locale (/) and Arabic lives under /ar/ with dir="rtl". The
// navigation below is defined ONCE per language from the same shape, because a hand-maintained
// second sidebar drifts the moment a page is added to one and not the other — and the drift is
// invisible in the language you do not read. `npm run docs:build` fails on a dead link, which is
// what actually catches it.
//
// Served at /handbook (behind the admin login) — see App\Http\Controllers\HandbookController.

type Item = { text: string; link: string }
type Group = { text: string; collapsed?: boolean; items: Item[] }

/**
 * One sidebar, described once per locale.
 *
 * `only` trims it to the pages that actually exist in that language. The Arabic tree is being
 * filled in page by page, and a sidebar entry pointing at an untranslated page is a menu of 404s —
 * strictly worse than a shorter menu, and invisible to anyone who does not read that language. So
 * the AR locale lists what it has, and grows a line at a time as pages land.
 */
function sidebar(t: Record<string, string>, p: string, only?: string[]): Group[] {
  const groups = allGroups(t, p)

  if (! only) {
    return groups
  }

  return groups
    .map((g) => ({ ...g, items: g.items.filter((i) => only.includes(i.link)) }))
    .filter((g) => g.items.length > 0)
}

function allGroups(t: Record<string, string>, p: string): Group[] {
  return [
    {
      text: t.startHere,
      items: [
        { text: t.whatThisIs, link: `${p}/` },
        { text: t.wholeSystem, link: `${p}/map` },
        { text: t.monthInLife, link: `${p}/scenarios` },
      ],
    },
    {
      text: t.leasing,
      collapsed: false,
      items: [
        { text: t.emptyUnitToRent, link: `${p}/leasing/` },
        { text: t.lifeOfLease, link: `${p}/leasing/lease-lifecycle` },
        { text: t.unitsTenants, link: `${p}/leasing/unit-and-tenant` },
        { text: t.depositsBooks, link: `${p}/leasing/deposits-in-the-books` },
      ],
    },
    {
      text: t.money,
      collapsed: false,
      items: [
        { text: t.moneySpine, link: `${p}/money/` },
        { text: t.lifeOfInvoice, link: `${p}/money/invoice-lifecycle` },
        { text: t.lifeOfCreditNote, link: `${p}/money/credit-note-lifecycle` },
        { text: t.inTheBooks, link: `${p}/money/the-books` },
      ],
    },
    {
      text: t.operations,
      collapsed: false,
      items: [
        { text: t.keepingRunning, link: `${p}/operations/` },
        { text: t.lifeOfRequest, link: `${p}/operations/request-lifecycle` },
        { text: t.camRecovery, link: `${p}/operations/cam-recovery` },
        { text: t.inventoryBooks, link: `${p}/operations/inventory-and-books` },
        { text: t.preventiveVendors, link: `${p}/operations/preventive-and-vendors` },
      ],
    },
    {
      text: t.people,
      collapsed: false,
      items: [
        { text: t.whereCashLeaves, link: `${p}/people/` },
        { text: t.payroll, link: `${p}/people/payroll` },
        { text: t.advancesCustody, link: `${p}/people/advances-and-custody` },
        { text: t.vendorBillsExpenses, link: `${p}/people/vendor-bills-and-expenses` },
      ],
    },
    {
      text: t.accounting,
      collapsed: false,
      items: [
        { text: t.whereConverges, link: `${p}/accounting/` },
        { text: t.ledgerRules, link: `${p}/accounting/the-ledger` },
        { text: t.fixedAssets, link: `${p}/accounting/fixed-assets` },
        { text: t.closeReconcile, link: `${p}/accounting/close-and-reconcile` },
      ],
    },
    {
      text: t.everyModule,
      collapsed: false,
      items: [
        { text: t.moduleIndex, link: `${p}/modules/` },
      ],
    },
    {
      text: t.forTheTeam,
      items: [{ text: t.contributing, link: `${p}/contributing` }],
    },
  ]
}

const en = {
  startHere: 'Start here',
  whatThisIs: 'What this is',
  wholeSystem: 'The whole system, one page',
  monthInLife: 'A month in the life',
  leasing: 'Leasing — where the money starts',
  emptyUnitToRent: 'From empty unit to rent',
  lifeOfLease: 'Life of a lease',
  unitsTenants: 'Units & tenants',
  depositsBooks: 'Deposits in the books',
  money: 'Money & Accounts Receivable',
  moneySpine: 'The money spine',
  lifeOfInvoice: 'Life of an invoice',
  lifeOfCreditNote: 'Life of a credit note',
  inTheBooks: 'What happens in the books',
  operations: 'Operations — the engine room',
  keepingRunning: 'Keeping the mall running',
  lifeOfRequest: 'Life of a request',
  camRecovery: 'CAM cost recovery',
  inventoryBooks: 'Inventory in the books',
  preventiveVendors: 'Preventive & vendors',
  people: 'People & money-out',
  whereCashLeaves: 'Where cash leaves',
  payroll: 'Payroll',
  advancesCustody: 'Advances & custody',
  vendorBillsExpenses: 'Vendor bills & expenses',
  accounting: 'Accounting & close',
  whereConverges: 'Where everything converges',
  ledgerRules: 'The ledger & the rules',
  fixedAssets: 'Fixed assets & depreciation',
  closeReconcile: 'Close & reconcile',
  everyModule: 'Every module',
  moduleIndex: 'All 36 modules',
  forTheTeam: 'For the team',
  contributing: 'Adding to this handbook',
}

const ar = {
  startHere: 'ابدأ من هنا',
  whatThisIs: 'ما هذا الدليل',
  wholeSystem: 'النظام كله في صفحة واحدة',
  monthInLife: 'شهر في حياة المول',
  leasing: 'التأجير — من هنا يبدأ المال',
  emptyUnitToRent: 'من وحدة شاغرة إلى إيجار',
  lifeOfLease: 'حياة عقد الإيجار',
  unitsTenants: 'الوحدات والمستأجرون',
  depositsBooks: 'التأمينات في الدفاتر',
  money: 'المال والذمم المدينة',
  moneySpine: 'العمود الفقري للمال',
  lifeOfInvoice: 'حياة الفاتورة',
  lifeOfCreditNote: 'حياة الإشعار الدائن',
  inTheBooks: 'ماذا يحدث في الدفاتر',
  operations: 'التشغيل — غرفة المحركات',
  keepingRunning: 'إبقاء المول يعمل',
  lifeOfRequest: 'حياة الطلب',
  camRecovery: 'استرداد المصروفات المشتركة',
  inventoryBooks: 'المخزون في الدفاتر',
  preventiveVendors: 'الصيانة الوقائية والموردون',
  people: 'الأفراد وخروج النقدية',
  whereCashLeaves: 'من أين تخرج النقدية',
  payroll: 'الرواتب',
  advancesCustody: 'السلف والعهد',
  vendorBillsExpenses: 'فواتير الموردين والمصروفات',
  accounting: 'المحاسبة والإقفال',
  whereConverges: 'حيث يلتقي كل شيء',
  ledgerRules: 'الدفتر وقواعده',
  fixedAssets: 'الأصول الثابتة والإهلاك',
  closeReconcile: 'الإقفال والتسويات',
  everyModule: 'كل الوحدات',
  moduleIndex: 'الوحدات الست والثلاثون',
  forTheTeam: 'لفريق العمل',
  contributing: 'الإضافة إلى هذا الدليل',
}

export default defineConfig({
  title: 'Atriom Visual Handbook',
  description: 'The mall business, drawn — money flows, record lifecycles, and what lands in the books.',
  cleanUrls: true,
  lastUpdated: true,

  // Served under /handbook rather than at a domain root, so every asset URL has to carry the
  // prefix. Getting this wrong produces a page that renders unstyled from the webroot.
  base: '/handbook/',

  // Outside public/ deliberately: nginx serves anything under the webroot directly, which would
  // put the operator's posting rules and internal controls on a guessable public URL. The build
  // lands where only an authenticated route can read it.
  outDir: '../../storage/app/handbook',

  // A dead link is the ONE failure a bilingual docs tree produces silently — a page added in
  // English and not in Arabic reads as complete to anyone who does not open the other language.
  // Failing the build is what makes the second locale real rather than aspirational.
  ignoreDeadLinks: false,

  locales: {
    root: {
      label: 'English',
      lang: 'en-US',
      themeConfig: {
        nav: [
          { text: en.startHere, link: '/' },
          { text: en.wholeSystem, link: '/map' },
          { text: en.monthInLife, link: '/scenarios' },
          { text: en.everyModule, link: '/modules/' },
        ],
        sidebar: sidebar(en, ''),
        outline: { level: [2, 3], label: 'On this page' },
      },
    },
    ar: {
      label: 'العربية',
      lang: 'ar',
      dir: 'rtl',
      title: 'دليل أتريوم المصوّر',
      description: 'أعمال المول مرسومة — مسارات المال ودورة حياة المستندات وما يستقر في الدفاتر.',
      themeConfig: {
        nav: [
          { text: ar.startHere, link: '/ar/' },
          { text: ar.everyModule, link: '/ar/modules/' },
        ],
        // Translated so far. Add the page, then add its link here — the two go together, and
        // this list is what keeps the Arabic menu honest about which is which.
        sidebar: sidebar(ar, '/ar', ['/ar/', '/ar/modules/']),
        outline: { level: [2, 3], label: 'في هذه الصفحة' },
        docFooter: { prev: 'السابق', next: 'التالي' },
        darkModeSwitchLabel: 'المظهر',
        returnToTopLabel: 'العودة إلى الأعلى',
        sidebarMenuLabel: 'القائمة',
        langMenuLabel: 'تغيير اللغة',
        lastUpdatedText: 'آخر تحديث',
      },
    },
  },

  themeConfig: {
    search: {
      provider: 'local',
      options: {
        locales: {
          ar: {
            translations: {
              button: { buttonText: 'بحث', buttonAriaLabel: 'بحث' },
              modal: {
                displayDetails: 'عرض التفاصيل',
                resetButtonTitle: 'مسح البحث',
                backButtonTitle: 'رجوع',
                noResultsText: 'لا توجد نتائج',
                footer: {
                  selectText: 'اختيار',
                  navigateText: 'تنقّل',
                  closeText: 'إغلاق',
                },
              },
            },
          },
        },
      },
    },
  },
})
