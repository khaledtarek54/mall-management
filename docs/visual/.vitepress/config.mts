import { defineConfig } from 'vitepress'

// Atriom Visual Handbook — an in-repo, browsable "pictures first" reference.
// Rooted at docs/visual/ so it builds cleanly and never trips over the Blade
// snippets ({{ }}) in the legacy docs/modules/*.md. Those stay canonical for
// deep detail; this layer draws the concepts on top of them.
export default defineConfig({
  title: 'Atriom Visual Handbook',
  description: 'The mall business, drawn — money flows, record lifecycles, and what lands in the books.',
  lang: 'en-US',
  cleanUrls: true,
  ignoreDeadLinks: true,
  lastUpdated: true,
  themeConfig: {
    nav: [
      { text: 'Start here', link: '/' },
      { text: 'Leasing', link: '/leasing/' },
      { text: 'Money & AR', link: '/money/' },
    ],
    sidebar: [
      {
        text: 'Start here',
        items: [{ text: 'What this is', link: '/' }],
      },
      {
        text: 'Leasing — where the money starts',
        collapsed: false,
        items: [
          { text: 'From empty unit to rent', link: '/leasing/' },
          { text: 'Life of a lease', link: '/leasing/lease-lifecycle' },
          { text: 'Units & tenants', link: '/leasing/unit-and-tenant' },
          { text: 'Deposits in the books', link: '/leasing/deposits-in-the-books' },
        ],
      },
      {
        text: 'Money & Accounts Receivable',
        collapsed: false,
        items: [
          { text: 'The money spine', link: '/money/' },
          { text: 'Life of an invoice', link: '/money/invoice-lifecycle' },
          { text: 'Life of a credit note', link: '/money/credit-note-lifecycle' },
          { text: 'What happens in the books', link: '/money/the-books' },
        ],
      },
      {
        text: 'Coming next',
        items: [
          { text: 'Operations · People · Accounting', link: '/#the-plan' },
        ],
      },
      {
        text: 'For the team',
        items: [
          { text: 'Adding to this handbook', link: '/contributing' },
        ],
      },
    ],
    outline: { level: [2, 3], label: 'On this page' },
    search: { provider: 'local' },
  },
})
