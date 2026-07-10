# Frontend change — Sales declaration is now a **file upload**

Tenants no longer type a sales amount. They **upload their sales report** (image or PDF);
the property team reads the figure off it and enters it later. Three things change on the app:

1. Replace the **“Declared sales” number input** with a **file picker** (1–5 files, image or PDF).
2. Send the create request as **`multipart/form-data`** (not JSON).
3. Show `declaredSales` as **“Pending review”** while it’s `null`.

---

## 1. Submit a declaration — CHANGED

`POST /api/v1/me/sales-declarations` → now **`multipart/form-data`**

| field | required | notes |
|---|---|---|
| `leaseId` | ✅ | a percentage-rent lease the tenant owns |
| `periodStart` | ✅ | `YYYY-MM-DD` |
| `periodEnd` | ✅ | `YYYY-MM-DD`, ≥ `periodStart` |
| `attachments[]` | ✅ | **1–5 files**, `image/*` or `application/pdf`, ≤ 10 MB each |

> There is **no `declaredSales` field anymore.**

**React Native / fetch:**
```js
const form = new FormData();
form.append('leaseId', String(leaseId));
form.append('periodStart', '2026-05-01');
form.append('periodEnd', '2026-05-31');
files.forEach(f => form.append('attachments[]', { uri: f.uri, name: f.name, type: f.type }));

await fetch(`${BASE}/api/v1/me/sales-declarations`, {
  method: 'POST',
  headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  // ⚠️ Do NOT set Content-Type yourself — let the runtime set the multipart boundary.
  body: form,
});
```

**curl (for testing):**
```bash
curl -X POST "$BASE/api/v1/me/sales-declarations" \
  -H "Authorization: Bearer $TOKEN" \
  -F leaseId=9 -F periodStart=2026-05-01 -F periodEnd=2026-05-31 \
  -F "attachments[]=@may-sales.pdf"
```

**Responses**
- `201` → created (see shape below).
- `422` validation errors:
  - no file / empty → `errors.attachments`
  - wrong file type → `errors["attachments.0"]`
  - lease not yours or not percentage-rent → `errors.leaseId`
  - a declaration already exists for that period → `errors.periodStart`

---

## 2. Response shape — CHANGED (list + detail)

```json
{
  "id": 7,
  "periodStart": "2026-05-01",
  "periodEnd": "2026-05-31",
  "periodLabel": "May 2026",
  "declaredSales": null,
  "calculatedPercentageRent": 0,
  "status": "submitted",
  "isLocked": false,
  "hasReport": true,
  "attachments": [
    {
      "id": 12,
      "name": "may-sales.pdf",
      "mimeType": "application/pdf",
      "size": 84213,
      "url": "https://…/api/v1/me/sales-declarations/7/attachments/12"
    }
  ],
  "declaredAt": "2026-06-01T08:00:00+00:00",
  "lockedAt": null
}
```

- `declaredSales` is **`null`** and `calculatedPercentageRent` is **`0`** right after submission →
  render **“Pending review”**, not `0`.
- `status`: `submitted` → `locked` (staff entered the figure & billed) → or `disputed`.
- Once `status === "locked"`, `declaredSales` and `calculatedPercentageRent` are filled.
- `hasReport` is a quick boolean; `attachments[]` has the file list.

---

## 3. View / download the report — NEW endpoint

`GET /api/v1/me/sales-declarations/{id}/attachments/{media}`

- Use the `url` from each item in `attachments[]`.
- **Requires the `Authorization: Bearer <token>` header** — it streams the file inline (not a public link).
- Another tenant’s declaration → `404`.

---

## 4. UI checklist

- [ ] Remove the sales-amount input from the submit screen.
- [ ] Add a file picker (photo / camera / PDF), 1–5 files, image or PDF only.
- [ ] Submit as `multipart/form-data` with `attachments[]`.
- [ ] Show **“Pending review”** when `declaredSales` is `null`.
- [ ] On the detail screen, list the attached report(s) and open/download them via the authenticated `url`.

> Full API reference: [`docs/api/MOBILE-API.md`](./MOBILE-API.md) → “Sales declarations”.
