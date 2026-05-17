import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import fs from 'fs';

// Calls artisan tinker to render a PDF via the service and verifies bytes + content.
// This catches regressions in mPDF setup, blade compile, or model relations.

function renderViaArtisan(locale, kind) {
  const out = `/tmp/pw-${kind}-${locale}.pdf`;
  const phpForKind = {
    invoice: `$inv = App\\Models\\Invoice::whereHas("items")->with(["items","tenant","lease.unit.asset"])->first(); $pdf = app(App\\Services\\InvoicePdfService::class)->build($inv);`,
    statement: `$tnt = App\\Models\\Tenant::whereHas("invoices")->with(["leases.unit.asset"])->first(); $pdf = app(App\\Services\\TenantStatementPdfService::class)->build($tnt);`,
  }[kind];
  const code = `app()->setLocale("${locale}"); ${phpForKind} file_put_contents("${out}", $pdf); echo strlen($pdf);`;
  const raw = execSync(`php artisan tinker --no-interaction --execute='${code}' 2>/dev/null`, { encoding: 'utf8' });
  // artisan emits warnings on stdout — last numeric token in trimmed output is the byte count
  const match = raw.trim().match(/(\d+)\s*$/);
  return { out, size: match ? parseInt(match[1], 10) : 0 };
}

test.describe.configure({ mode: 'serial' });

for (const kind of ['invoice', 'statement']) {
  for (const locale of ['en', 'ar']) {
    test(`${kind} PDF renders in ${locale}`, async () => {
      const { out, size } = renderViaArtisan(locale, kind);
      expect(size).toBeGreaterThan(2000);
      const buf = fs.readFileSync(out);
      expect(buf.slice(0, 4).toString()).toBe('%PDF');
      // A trailer should appear at the end of any valid PDF
      const tail = buf.slice(-1024).toString('latin1');
      expect(tail).toMatch(/%%EOF/);
    });
  }
}
