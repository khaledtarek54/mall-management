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
      { text: 'Money & AR', link: '/money/' },
    ],
    sidebar: [
      {
        text: 'Start here',
        items: [{ text: 'What this is', link: '/' }],
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
          { text: 'Leasing · Operations · People · Accounting', link: '/#the-plan' },
        ],
      },
    ],
    outline: { level: [2, 3], label: 'On this page' },
    search: { provider: 'local' },
  },
})
