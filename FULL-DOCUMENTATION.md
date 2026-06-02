# Atriom — Complete Technical Documentation

**Version:** 1.0 | **Last Updated:** June 2, 2026 | **Project:** Mall Management System

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [Architecture & Tech Stack](#architecture--tech-stack)
3. [Project Structure](#project-structure)
4. [Database Models](#database-models)
5. [API Documentation](#api-documentation)
6. [Core Features](#core-features)
7. [Authentication & Authorization](#authentication--authorization)
8. [Frontend Panels](#frontend-panels)
9. [Console Commands](#console-commands)
10. [Development Guide](#development-guide)
11. [Testing](#testing)
12. [Deployment](#deployment)

---

## Project Overview

### What is Atriom?

Atriom is a comprehensive **Egyptian mall operations platform** designed for property management. It handles the complete lifecycle of retail property management — from lease agreements to billing, tenant communications, and financial reconciliation.

**Core Purpose:**

- Centralized management of multi-property retail assets
- Automated monthly billing with Egyptian tax rules (VAT regulations)
- Tenant-side transparency portal for payments and communications
- Owner/investor portfolio oversight
- Admin console for operators and property managers

**Key Statistics:**

- ~4,888 lines of PHP code in `app/` directory
- 23 core Eloquent models
- 184 Pest test cases (parallel testing ~3.5s)
- 18 Playwright E2E spec files
- 3 role-aware portals (Admin, Owner, Tenant)
- 6 RBAC roles with 81 permissions

---

## Architecture & Tech Stack

### Technology Stack

| Layer | Technology | Version |
| ------- | --------- | -------- |
| **Framework** | Laravel | 13.8 |
| **PHP** | PHP | 8.4 |
| **Admin Panel** | Filament | 4.0 |
| **Database** | MySQL | 8.0+ |
| **Frontend** | Vite + Blade/Vue | Latest |
| **Authentication** | Laravel Sanctum | 4.3 |
| **PDF Generation** | mPDF | 8.3 (Arabic support) |
| **Testing** | Pest + Playwright | 4.7 + Latest |
| **Package Manager** | Composer + npm | - |

### Core Dependencies

**Production:**

- `filament/filament` — Admin panel framework (Tenancy-aware)
- `spatie/laravel-permission` — Role & permission management (81 permissions, 6 roles)
- `spatie/laravel-activitylog` — Audit trail with field diffs
- `spatie/laravel-medialibrary` — Document/photo attachments
- `spatie/laravel-settings` — Module feature flags
- `mpdf/mpdf` — PDF with Arabic text shaping + bidirectional rendering
- `laravel/sanctum` — Token-based API auth for mobile
- `stephenjude/filament-two-factor-authentication` — 2FA support

**Development:**

- `pestphp/pest` — Modern test framework (parallel execution)
- `brianium/paratest` — Parallel test runner
- `laravel/pint` — Code style fixer (PSR-12)
- `playwright` — E2E browser testing

### Design Principles

1. **Multi-Tenancy by Property**
   - Every operation scoped to a property (mall)
   - URL-based routing: `/admin/{property-code}/...`
   - Sanctum API tokens linked to tenants, not admins
   - Property switcher in UI for users with multiple assignments

2. **RBAC with Property Granularity**
   - 6 roles: super_admin, manager, leasing_manager, maintenance_manager, viewer, owner
   - 81 named permissions (e.g., `view_invoices`, `edit_leases`, `delete_credit_notes`)
   - `asset_user` pivot: users assigned to specific properties
   - New users default to ALL properties (editable per user)

3. **Action Classes for Writes**
   - API endpoints use single-action classes: `App\Actions\Api\V1\*\*Action.php`
   - Web forms use Filament schemas
   - Consistent service layer for billing, reconciliation, PDFs

4. **Feature Flags (Module Toggle)**
   - Modules controllable from `/admin/settings`
   - Stored in `spatie/laravel-settings`
   - Modules: credit_notes, maintenance, tenant_sales, cam, utility_meters, vendors, notes, reports, activity_log, eta
   - Disabled modules hidden from sidebar, dashboard, and route access

5. **Idempotent Billing**
   - Monthly billing generates per-period, per-tenant
   - Re-running for same period creates no duplicates
   - Supports backfill and forecast

---

## Project Structure

### Directory Hierarchy

```text
mall-management/
├── app/
│   ├── Actions/
│   │   ├── Api/Auth/              # Legacy v0 mobile auth
│   │   └── Api/V1/                # Versioned API actions
│   │       ├── Auth/              # Login, password reset, MFA
│   │       ├── Devices/           # Push token registration
│   │       ├── Maintenance/       # Maintenance request lifecycle
│   │       ├── Profile/           # Tenant profile & balance
│   │       └── Sales/             # Sales declarations
│   ├── Console/Commands/
│   │   ├── RunMonthlyBillingCommand        # Lease-triggered invoice generation
│   │   ├── CamAnnualReconciliationCommand  # CAM pools + allocations
│   │   ├── ApplyLateFeesCommand            # Interest accrual
│   │   ├── AutoCloseMaintenanceRequestsCommand
│   │   ├── ScanMaintenanceSlaBreachesCommand
│   │   └── ExpireVendorContractsCommand
│   ├── Filament/
│   │   ├── Admin/
│   │   │   ├── Resources/         # Filament CRUD resources
│   │   │   │   ├── Assets/        # Property management
│   │   │   │   ├── Tenants/       # Tenant CRUD
│   │   │   │   ├── Leases/        # Lease wizard + renewals
│   │   │   │   ├── Invoices/      # Billing & statements
│   │   │   │   ├── Payments/      # Payment entry + reconciliation
│   │   │   │   ├── CreditNotes/   # Debit memos
│   │   │   │   ├── MaintenanceRequests/
│   │   │   │   ├── CamExpensePools/
│   │   │   │   ├── TenantSalesDeclarations/
│   │   │   │   └── [other resources]
│   │   │   ├── Pages/
│   │   │   │   ├── Dashboard.php        # KPI widgets
│   │   │   │   ├── ActivityLog.php      # Audit trail
│   │   │   │   ├── Reports.php          # Canned reports
│   │   │   │   ├── Settings.php         # Module toggles
│   │   │   │   ├── OccupancyMap.php     # Visual floor plan
│   │   │   │   └── ArAging.php          # A/R aging schedule
│   │   │   ├── RelationManagers/  # Inline CRUD editors
│   │   │   └── Concerns/          # Shared traits
│   │   ├── Owner/                 # Owner portal (read-only)
│   │   └── Tenant/                # Tenant portal + mobile API
│   ├── Http/
│   │   ├── Controllers/Api/V1/    # RESTful API endpoints
│   │   ├── Middleware/            # Auth, locale, throttling
│   │   └── Requests/              # Form request validation
│   ├── Models/                    # 23 Eloquent models
│   ├── Services/                  # Business logic
│   │   ├── BillingService.php     # Invoice generation
│   │   ├── EtaService.php         # e-invoicing integration
│   │   ├── PaymobService.php      # Payment gateway
│   │   └── [domain services]
│   └── Events/Listeners/          # Activity logging, notifications
├── routes/
│   ├── api.php                    # /api/v1/* endpoints
│   ├── web.php                    # Paymob callbacks
│   └── console.php                # Artisan commands
├── resources/
│   ├── views/
│   │   ├── welcome.blade.php      # Landing page
│   │   └── [Filament views]
│   └── css/js/                    # Compiled by Vite
├── database/
│   ├── migrations/                # Schema (21st century MySQL)
│   ├── factories/                 # Model factories for testing
│   └── seeders/                   # Demo data (demo accounts, sample properties)
├── tests/
│   ├── Feature/                   # End-to-end test scenarios
│   ├── Unit/                      # Model, service, permission tests
│   └── Pest.php                   # Pest configuration
├── config/
│   ├── filament.php               # Panel setup
│   ├── permission.php             # RBAC config
│   └── [Laravel configs]
├── docs/
│   ├── api/
│   │   ├── MOBILE-API.md          # Mobile API reference
│   │   └── v1.md                  # OpenAPI/detailed API
│   └── gap-analysis/              # Feature gap vs. Eltizam
├── FEATURES.md                    # Full feature list
├── TECH-DEEPDIVE.md               # Scaling, security, ETA
├── MASTER-PLAN.md                 # Strategy & competitive context
└── [project docs]
```

### Key Files by Purpose

| Purpose | File/Path |
| --------- | ----------- |
| **Database Schema** | `database/migrations/*.php` |
| **Roles & Permissions** | `config/permission.php` + Filament resource policies |
| **API Routes** | `routes/api.php` |
| **Admin Routes** | Filament auto-discovery + `App\Filament\Admin\Resources\*` |
| **Demo Data** | `database/seeders/DatabaseSeeder.php` |
| **Settings/Flags** | `config/settings.php` |
| **Billing Logic** | `app/Services/BillingService.php` |
| **e-Invoice (ETA)** | `app/Services/EtaService.php` |
| **Payment (Paymob)** | `app/Services/PaymobService.php` |
| **Activity Audit** | Events + Spatie ActivityLog config |

---

## Database Models

### 23 Core Eloquent Models

#### 1. **Asset** (Properties)

```php
// Represents a mall/property
belongsTo: User (owner — if hierarchy exists)
hasMany: Unit, Tenant, Lease, Invoice, MaintenanceRequest
hasMany: User (through asset_user pivot — managers, admins)
```

**Key Fields:**

- `code` — Unique property identifier (e.g., "HAYA01")
- `name` — "Haya Walk"
- `address`, `city`, `country` — Location
- `phone`, `email` — Contact
- `opening_date` — Operational start
- `vat_registered` — Tax status

**Relationships:**

- Pivot: `asset_user` — Many-to-many with Users (property assignments)
- One-to-many: Units, Tenants, Leases, Invoices, Payments

---

#### 2. **Unit** (Retail Spaces)

```php
// Individual shop/kiosk/space within a property
belongsTo: Asset
hasMany: Lease, MaintenanceRequest
```

**Key Fields:**

- `number` — "A-01"
- `type` — "shop" | "kiosk" | "f&b" | "service"
- `area_sqm` — Floor area
- `floor` — Level number
- `rent_per_sqm` — Base rate
- `status` — "vacant" | "occupied" | "maintenance" | "reserved"

---

#### 3. **Tenant** (Retailers/Businesses)

```php
// Entity leasing unit(s)
belongsTo: Asset (primary property)
hasMany: Lease, TenantSalesDeclaration, MaintenanceRequest
hasMany: Contact (legal entity contacts)
hasMany: Document (contracts, IDs, certifications)
```

**Key Fields:**

- `business_name` — "Café Crema"
- `business_type` — Retail category
- `registration_number` — Tax ID
- `owner_name`, `owner_email`, `owner_phone` — Primary contact
- `lat_address` — Arabic name

---

#### 4. **Lease** (Rental Agreements)

```php
// Binding contract between Tenant ↔ Unit
belongsTo: Tenant, Unit, Asset
hasMany: Charge (rent, service, utilities)
hasMany: Invoice (billing from lease)
hasMany: TenantSalesDeclaration (if percentage rent enabled)
```

**Key Fields:**

- `ref_number` — "LAS-2026-001"
- `status` — "draft" | "active" | "expired" | "terminated" | "renewed"
- `start_date`, `end_date` — Term
- `rent_amount` — Monthly base rent
- `base_rent_per_sqm` — Rate × area
- `service_charge_per_sqm` — CAM/maintenance component
- `percentage_rent_percentage` — If applicable (0–10%)
- `renewal_behavior` — Auto-renew? How many times?
- `charges_inherited` — Copy charges from prior lease on renewal?

**Statuses:**

- `draft` — Pre-signature
- `active` — Binding, currently in force
- `expired` — Reached end date naturally
- `terminated` — Broken early
- `renewed` — Converted to new lease on expiry

**Special Logic:**

- Renewal wizard copies charges if `charges_inherited = true`
- Termination preserves paid invoices (no orphan logic)
- Percentage rent auto-bills at month end if declaration received

---

#### 5. **Charge** (Recurring Lease Costs)

```php
// Line item in a lease (base rent, service, utilities)
belongsTo: Lease
```

**Key Fields:**

- `type` — "base_rent" | "service_charge" | "utility" | "late_fee"
- `amount` — Monthly cost (EGP)
- `tax_treatment` — "exempt" | "vat_14" | "vat_5" | "vat_0"
- `start_date`, `end_date` — Applicability window
- `is_active` — Toggleable (e.g., suspend utilities mid-lease)

**Tax Rules (Egyptian VAT):**

- Base rent: Exempt (0%)
- Service charge: 14% VAT
- Utilities: 5% VAT
- Late fees: Calculated per policy

---

#### 6. **Invoice** (Billing Documents)

```php
// Issued monthly to tenant for charges due
belongsTo: Lease, Tenant, Asset
hasMany: InvoiceItem
hasMany: Payment
hasManyThrough: CreditNote (reversals)
```

**Key Fields:**

- `number` — "INV-2026-001234" (unique per property)
- `period` — "2026-06" (YYYY-MM)
- `status` — "draft" | "issued" | "partially_paid" | "paid" | "overdue" | "cancelled"
- `issue_date`, `due_date` — Billing dates
- `subtotal` — Pre-tax (rent exempt + service 14%)
- `vat_amount` — Calculated 14% on service
- `total` — Subtotal + VAT
- `paid_amount` — Sum of applied payments
- `remaining` — Total − paid
- `eta_status` — "pending" | "submitted" | "accepted" | "rejected" | null (if ETA disabled)
- `eta_document_id` — ETA system reference
- `pdf_url` — Cached PDF location

**Invoice Generation:**

- Triggered by `RunMonthlyBillingCommand` (one-click UI button)
- Queries active leases → generates line items from charges
- Applies Egyptian VAT rules
- Idempotent: re-running same period doesn't duplicate
- Supports backfill (historical months) & forecast (future months)

---

#### 7. **InvoiceItem** (Line Items in Invoice)

```php
belongsTo: Invoice
```

**Key Fields:**

- `charge_id` — Reference to Lease charge
- `description` — "Base Rent (A-01)" (for display)
- `amount` — Line subtotal
- `tax_treatment` — Inherited from charge
- `vat` — Calculated

---

#### 8. **Payment** (Received Money)

```php
// Cash/card/bank payment applied to invoices
belongsTo: Tenant, Asset
hasMany: PaymentAllocation (line-by-line invoice reconciliation)
```

**Key Fields:**

- `ref_number` — "PAY-2026-5234"
- `amount` — EGP received
- `method` — "cash" | "card" | "bank_transfer" | "paymob" | "cheque"
- `payment_date` — When received
- `reference` — External ref (bank slip, card Auth)
- `notes` — Admin notes
- `paymob_order_id` — If via Paymob
- `eta_invoice_id` — If pre-reconciled to ETA

**Paymob Integration:**

- Payment gateway for card/wallet/bank transfers
- Sandbox mode by default; flip `PAYMOB_ENABLED=true` for production
- Mobile app initiates Paymob iframe session
- Server callback posts payment confirmation
- Payment entry can be manual (admin) or auto (Paymob callback)

---

#### 9. **PaymentAllocation** (Pivot: Payment ↔ Invoice)

```php
belongsTo: Payment, Invoice
```

**Purpose:**

- One payment may cover multiple invoices
- Tracks partial payments ("Invoice #1: EGP 5k of 10k")
- Audit trail for reconciliation

**Key Fields:**

- `amount_allocated` — EGP credited to this invoice
- `allocated_at` — Timestamp

---

#### 10. **CreditNote** (Reversals/Debit Memos)

```php
// Negative invoice (refund, correction, etc.)
belongsTo: Tenant, Invoice, Asset
hasMany: CreditNoteItem
```

**Key Fields:**

- `ref_number` — "CN-2026-456"
- `type` — "reversal" | "correction" | "discount" | "adjustment"
- `reason` — Admin notes
- `status` — "draft" | "issued" | "applied"
- `invoice_id` — Original invoice (if reversal)
- `amount` — EGP (negative balance)

---

#### 11. **Lease Related Models**

#### **TenantSalesDeclaration**

```php
// Monthly sales report for percentage-rent leases
belongsTo: Lease, Tenant, Asset
```

**Key Fields:**

- `period` — "2026-06"
- `declared_sales` — Gross sales (EGP)
- `percentage_rent_due` — Calculated (sales × lease rate)
- `status` — "draft" | "submitted" | "locked" | "invoiced"
- `submitted_at` — Tenant submission time
- `locked_at` — Admin lock (prevents tenant edit)

**Workflow:**

1. Tenant submits monthly sales via portal
2. Admin reviews & locks (validates plausibility)
3. Percentage rent auto-bills next month
4. Invoice created with percentage rent + base rent

---

#### **CamExpensePool** (Common-Area Maintenance)

```php
// Annual CAM reconciliation pool
belongsTo: Asset
hasMany: CamAllocation
```

**Key Fields:**

- `period` — "2026" (fiscal year)
- `total_expenses` — EGP (utilities, security, maintenance)
- `status` — "draft" | "finalized"
- `finalized_at` — Freeze date

**Workflow:**

- Admin enters annual expenses (e.g., EGP 500k security, EGP 100k utilities)
- `CamAnnualReconciliationCommand` calculates pro-rata by leasable area
- Each lease allocated share: `(lease_area / total_area) × pool_expenses`
- Allocations auto-billed in Q1 next year

---

#### **CamAllocation** (Individual CAM Charges)

```php
belongsTo: Lease, CamExpensePool
```

**Key Fields:**

- `pro_rata_amount` — Tenant's share (EGP)
- `billed` — Boolean (whether invoice generated)

---

#### 12. **Maintenance Models**

#### **MaintenanceRequest**

```php
// Tenant-submitted work order
belongsTo: Tenant, Unit, Asset
hasMany: MaintenanceRequestComment (polymorphic notes)
hasMany: Media (attached photos)
```

**Key Fields:**

- `number` — "MR-2026-789"
- `title`, `description` — Issue description
- `priority` — "low" | "medium" | "high" | "urgent"
- `status` — "open" | "assigned" | "in_progress" | "completed" | "cancelled"
- `category` — "plumbing" | "electrical" | "hvac" | "structural" | "aesthetic"
- `created_at` — Submission
- `assigned_to` — Staff (if set)
- `scheduled_date` — Expected visit
- `completed_at` — Resolution
- `sla_hours` — Service level target

**Features:**

- Tenant uploads photos via mobile API
- Admin assigns to maintenance staff + sets SLA
- `ScanMaintenanceSlaBreachesCommand` flags overdue
- `AutoCloseMaintenanceRequestsCommand` closes if no activity
- Polymorphic comments: calls, site visits, messages logged

---

#### **MaintenanceRequestComment** (Polymorphic)

```php
belongsTo: MaintenanceRequest
```

**Key Fields:**

- `type` — "call" | "message" | "site_visit" | "email" | "whatsapp"
- `body` — Formatted note
- `author` — User/admin name
- `created_at`

---

#### 13. **Utility Tracking**

#### **UtilityMeter**

```php
// Electric, water, gas meter
belongsTo: Asset, Unit
hasMany: MeterReading
```

**Key Fields:**

- `meter_number` — "E-001"
- `utility_type` — "electricity" | "water" | "gas"
- `unit_id` — Specific unit, or null for property-wide

---

#### **MeterReading**

```php
// Monthly consumption snapshot
belongsTo: UtilityMeter
```

**Key Fields:**

- `period` — "2026-06"
- `reading` — kWh / m³
- `consumption` — (current − prior)
- `cost_per_unit` — Rate
- `total_cost` — Consumption × rate

---

#### 14. **User & Permissions**

#### **User** (Platform Accounts)

```php
// Staff, admins, owners
hasMany: Asset (via asset_user pivot)
hasPermissions: Spatie Laravel Permission
```

**Key Fields:**

- `name`, `email`, `phone`
- `role` — Actually set via Spatie (not a column)
- `assets` — Pivot relationship
- `assigned_properties` — JSON cache of property codes
- `2fa_enabled` — Boolean
- `2fa_secret` — Encrypted totp seed
- `last_login_at` — Audit

**RBAC:**

- Roles: super_admin, manager, leasing_manager, maintenance_manager, viewer, owner
- Permissions: 81 named abilities (e.g., `view_invoices`, `delete_leases`)
- Policies: Filament gates access per resource
- Pivot: `asset_user` controls property-level access

---

#### **DeviceToken** (Push Notifications)

```php
// Mobile app device registrations
belongsTo: Tenant
```

**Key Fields:**

- `token` — Firebase/OneSignal token
- `device_type` — "ios" | "android"
- `device_name` — "iPhone 15"
- `registered_at`

---

#### 15. **Vendor Management** (Optional Module)

#### **Vendor**

```php
// Third-party contractors
belongsTo: Asset
hasMany: VendorContact, VendorContract
```

**Fields:** name, category, rating, contact info

#### **VendorContract**

```php
// Expiring vendor agreements
belongsTo: Vendor, Asset
```

**Fields:** start, end, amount, status; `ExpireVendorContractsCommand` alerts nearing expiry

---

#### 16. **Polymorphic Notes** (Communications Log)

#### **Note** (Tenant Communications)

```php
// Call log, WhatsApp, meetings, site visits
belongsTo: Tenant
```

**Key Fields:**

- `type` — "call" | "whatsapp" | "meeting" | "site_visit" | "email"
- `body` — Narrative
- `user_id` — Author (staff)
- `created_at`

**Use Case:** Collections team logs all tenant interactions for follow-up.

---

### Relationship Map (Simplified)

```Asset (Property)
├── Unit
├── Tenant
│   ├── Lease
│   │   ├── Charge
│   │   ├── Invoice
│   │   │   ├── InvoiceItem
│   │   │   └── Payment
│   │   │       └── PaymentAllocation (↔ Invoice)
│   │   ├── TenantSalesDeclaration
│   │   └── MaintenanceRequest
│   │       └── MaintenanceRequestComment
│   ├── Note
│   ├── CreditNote
│   └── Device Token
├── CamExpensePool
│   └── CamAllocation
├── UtilityMeter
│   └── MeterReading
└── User (via asset_user pivot)
```

---

## API Documentation

### Authentication

**Endpoint:** `POST /api/v1/auth/login`

**Request:**

```json
{
  "email": "tenant1@haya.test",
  "password": "password"
}
```

**Response (200 OK):**

```json
{
  "data": {
    "user_id": 2,
    "email": "tenant1@haya.test",
    "name": "Café Crema",
    "token": "1|AbCdEfGhIjKlMnOpQrStUvWxYz..."
  },
  "message": "Login successful"
}
```

**Auth Headers:**
All authenticated endpoints require:

```http
Authorization: Bearer {token}
```

---

### Public Endpoints (Unauthenticated)

#### 1. Login

```http
POST /api/v1/auth/login
Rate Limit: 5 requests/minute
```

#### 2. Forgot Password

```http
POST /api/v1/auth/forgot-password
Payload: { "email": "..." }
Rate Limit: 3 requests/minute (anti-abuse)
Response: Sends reset link via email
```

#### 3. Reset Password

```http
POST /api/v1/auth/reset-password
Payload: { "token": "...", "password": "...", "password_confirmation": "..." }
Rate Limit: 3 requests/minute
```

---

### Authenticated Endpoints

**Rate Limit (all):** 60 requests/minute per token

#### Profile & Account

##### Get Profile

```http
GET /api/v1/me
Response: {
  "data": {
    "user_id": 2,
    "email": "tenant1@haya.test",
    "name": "Café Crema",
    "phone": "+201234567890",
    "property": { "code": "HAYA01", "name": "Haya Walk" }
  }
}
```

##### Update Profile

```http
PATCH /api/v1/me
Payload: {
  "phone": "+201234567890",
  "email": "new@haya.test"  // optional, if not already claimed
}
```

##### Get Balance

```http
GET /api/v1/me/balance
Response: {
  "data": {
    "total_billed": 50000,
    "total_paid": 30000,
    "remaining_balance": 20000,
    "overdue_invoices": 5,
    "overdue_amount": 10000
  }
}
```

##### Get Leases

```http
GET /api/v1/me/leases
Response: {
  "data": [
    {
      "lease_id": 1,
      "ref_number": "LAS-2026-001",
      "unit": "A-01",
      "start_date": "2025-01-01",
      "end_date": "2027-01-01",
      "status": "active",
      "rent_amount": 5000,
      "percentage_rent_percentage": 2
    }
  ]
}
```

##### Change Password

```http
POST /api/v1/auth/change-password
Payload: {
  "current_password": "oldpass",
  "password": "newpass",
  "password_confirmation": "newpass"
}
```

##### Logout

```http
POST /api/v1/auth/logout
```

---

#### Invoices

##### List Invoices

```http
GET /api/v1/me/invoices?period=2026-06&status=overdue
Query Params:
  - period: YYYY-MM (optional, filters by month)
  - status: draft|issued|partially_paid|paid|overdue|cancelled (optional)
  - page: 1 (optional, paginated 15/page)
  - sort: -issue_date (optional)

Response: {
  "data": [
    {
      "invoice_id": 1,
      "number": "INV-2026-001",
      "period": "2026-06",
      "issue_date": "2026-06-01",
      "due_date": "2026-06-15",
      "status": "partially_paid",
      "subtotal": 10000,
      "vat": 1400,
      "total": 11400,
      "paid": 5000,
      "remaining": 6400
    }
  ],
  "meta": { "total": 42, "per_page": 15, "current_page": 1 }
}
```

##### Show Invoice Detail

```
GET /api/v1/me/invoices/{id}
Response: {
  "data": {
    "invoice_id": 1,
    "number": "INV-2026-001",
    "items": [
      { "description": "Base Rent (A-01)", "amount": 10000, "tax_treatment": "exempt", "vat": 0 },
      { "description": "Service Charge", "amount": 0, "tax_treatment": "vat_14", "vat": 1400 }
    ],
    "total": 11400,
    "remaining": 6400,
    "pdf_url": "/storage/invoices/INV-2026-001.pdf"
  }
}
```

##### Download Invoice PDF

```
GET /api/v1/me/invoices/{id}/pdf
Response: Binary PDF (Content-Type: application/pdf)
```

##### Account Statement

```
GET /api/v1/me/statement?from=2026-01&to=2026-12
Response: {
  "data": {
    "period": "2026-01 to 2026-12",
    "opening_balance": 0,
    "invoices": [...],
    "payments": [...],
    "closing_balance": 20000
  }
}
```

---

#### Payments

##### List Payments

```
GET /api/v1/me/payments?from=2026-01-01&to=2026-06-30
Query Params:
  - from, to: Filter by date range (optional)
  - method: cash|card|bank_transfer|paymob (optional)
  - page: Pagination

Response: {
  "data": [
    {
      "payment_id": 1,
      "ref_number": "PAY-2026-123",
      "amount": 5000,
      "method": "paymob",
      "payment_date": "2026-06-10",
      "status": "confirmed"
    }
  ]
}
```

##### Show Payment Detail

```
GET /api/v1/me/payments/{id}
Response: {
  "data": {
    "payment_id": 1,
    "ref_number": "PAY-2026-123",
    "amount": 5000,
    "method": "paymob",
    "allocations": [
      { "invoice_id": 1, "amount": 3000 },
      { "invoice_id": 2, "amount": 2000 }
    ]
  }
}
```

---

#### Paymob Payment Gateway

##### Initiate Payment Session

```
POST /api/v1/me/invoices/{invoice}/paymob-session
Request: {} (empty body)
Response: {
  "data": {
    "session_id": "...",
    "iframe_token": "...",
    "payment_key": "...",
    "merchant_order_id": 12345
  },
  "message": "Session initiated"
}
```

**Idempotent:** Requests within REUSE_WINDOW_SECONDS (600s default) return cached session.

**Flow:**

1. Mobile app calls endpoint
2. Backend initiates Paymob order
3. Returns iframe token
4. Frontend loads Paymob iframe
5. User enters card/payment method
6. Paymob POSTs callback to `/paymob/callback`
7. Backend creates Payment record
8. Payment allocated to invoice

---

#### Maintenance Requests

##### List Maintenance Requests

```
GET /api/v1/me/maintenance-requests?status=open&priority=urgent
Query: status, priority, page

Response: {
  "data": [
    {
      "request_id": 1,
      "number": "MR-2026-001",
      "title": "AC not working",
      "description": "Unit A-01 AC unit off",
      "priority": "urgent",
      "status": "assigned",
      "category": "hvac",
      "created_at": "2026-06-01T10:00:00Z",
      "assigned_to": "Mohamed",
      "scheduled_date": "2026-06-02"
    }
  ]
}
```

##### Create Maintenance Request

```
POST /api/v1/me/maintenance-requests
Payload: {
  "title": "Door lock broken",
  "description": "Main entrance lock not functioning",
  "priority": "high",
  "category": "structural"
}
Response: 201 Created
{
  "data": {
    "request_id": 1,
    "number": "MR-2026-001",
    "status": "open"
  }
}
```

##### Show Request Detail

```
GET /api/v1/me/maintenance-requests/{id}
```

##### Add Comment

```
POST /api/v1/me/maintenance-requests/{id}/comments
Payload: {
  "body": "We visited on 2026-06-02, issue confirmed.",
  "type": "site_visit"
}
Response: 201 Created
```

##### Cancel Request

```
POST /api/v1/me/maintenance-requests/{id}/cancel
Payload: { "reason": "Issue resolved on-site" }
Response: 200 OK
```

---

#### Sales Declarations (Percentage Rent)

##### List Declarations

```
GET /api/v1/me/sales-declarations?period=2026-06&status=submitted
Response: {
  "data": [
    {
      "declaration_id": 1,
      "period": "2026-06",
      "declared_sales": 50000,
      "percentage_rent_due": 1000,  // 50000 × 2%
      "status": "submitted",
      "submitted_at": "2026-07-05T15:00:00Z"
    }
  ]
}
```

##### Submit Sales Declaration

```
POST /api/v1/me/sales-declarations
Payload: {
  "period": "2026-06",
  "declared_sales": 50000
}
Response: 201 Created
```

##### Show Declaration

```
GET /api/v1/me/sales-declarations/{id}
```

---

#### Device Push Tokens

##### Register Device

```
POST /api/v1/me/devices
Payload: {
  "token": "firebase_token_here",
  "device_type": "ios",
  "device_name": "iPhone 15 Pro"
}
```

##### Unregister Device

```
DELETE /api/v1/me/devices/{id}
```

---

### Error Handling

All endpoints return standard envelopes:

**Success (200, 201):**

```json
{
  "data": { /* payload */ },
  "message": "Success message"
}
```

**Validation Error (422):**

```json
{
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password must be at least 8 characters."]
  }
}
```

**Unauthorized (401):**

```json
{
  "message": "Unauthenticated"
}
```

**Forbidden (403):**

```json
{
  "message": "This action is unauthorized."
}
```

**Not Found (404):**

```json
{
  "message": "Resource not found"
}
```

**Rate Limited (429):**

```json
{
  "message": "Too many requests. Please try again in X seconds."
}
```

---

## Core Features

### 1. Lease Lifecycle Management

**Wizard-based lease creation:**

- Step 1: Tenant selection (existing or new tenant creation inline)
- Step 2: Lease terms (unit, dates, rent, charges)
- Auto-populates unit details (area, floor) → calculates rent if per-sqm
- Supports multiple units per tenant (cross-property leases possible)

**Renewals:**

- Old lease → expiry date reached → admin clicks "Renew"
- New lease auto-created with next start date
- If `charges_inherited = true` on old lease, copy all charges forward
- Charge dates adjusted (new lease period)
- Old lease status → "renewed"

**Terminations:**

- Mark lease "terminated"
- Previous invoices remain (no orphaning)
- Any unused advance payments preserved
- Triggers CAM reconciliation if configured

**Charge Management:**

- Base rent (exempt from VAT)
- Service charge (14% VAT)
- Utilities (5% VAT)
- Late fees (tiered, on overdue amounts)
- Can enable/disable charges mid-lease

---

### 2. Monthly Billing Engine

**Trigger:** Admin clicks "Run Monthly Billing" button or cron runs `RunMonthlyBillingCommand`

**Process:**

1. Query all active leases for the target period
2. For each lease:
   - Iterate charges with `start_date ≤ period ≤ end_date`
   - Group by tax treatment (rent exempt, service 14%, utilities 5%)
   - Sum into subtotal
   - Calculate VAT: `service_subtotal × 0.14`
   - Create invoice with status "draft"
3. Batch-create InvoiceItems for each charge
4. Admin reviews in "Invoices" → confirms (status → "issued")
5. Tenants see in portal immediately

**Idempotency:**

- Before creating, check if invoice exists for (lease, period)
- If exists, skip (no duplicate)
- Admin can delete draft and re-run to fix errors

**Support for Backfill & Forecast:**

- Backfill: Run for old periods (e.g., "missed March 2026")
- Forecast: Generate invoices for future months (prepay scenarios)

---

### 3. Egyptian VAT Tax Rules

```
Base Rent (rent component):
  - VAT Treatment: EXEMPT (0%)
  - Appears on invoice as-is

Service Charge (CAM allocation):
  - VAT Treatment: 14% VAT
  - Invoice shows: Service Charge + VAT (calculated)
  - Example: EGP 1000 service → EGP 140 VAT → Total EGP 1140

Utilities (water, electric, gas):
  - VAT Treatment: 5% VAT
  - Invoice shows: Utility + VAT

PDF Invoice Layout (mPDF, Arabic-shaped):
  - Header: Property name, logo
  - Tenant name, lease ref, period
  - Table: Description | Amount | VAT% | VAT Amount
  - Summary: Subtotal | Total VAT | Grand Total
  - Terms: Due by, payment methods
  - Footer: Tax ID, contact
```

---

### 4. Tenant Sales Declarations & Percentage Rent

**Trigger:** Lease configured with `percentage_rent_percentage = 2` (example)

**Monthly Workflow:**

1. Around 5th of month, tenant portal shows "Declare Sales" prompt
2. Tenant enters: "June 2026: EGP 50,000 gross sales"
3. Admin reviews → locks (freezes entry)
4. Backend calculates: `50,000 × 2% = EGP 1,000 percentage rent`
5. Next month's invoice auto-includes percentage rent line item
6. Invoice issued, tenant pays

**Admin Reporting:**

- Dashboard widget shows: "X% of tenants declare on time"
- Overdue declarations flagged (red)
- Sales history chart for trending

---

### 5. CAM Reconciliation

**Annual Process:** Triggered by `CamAnnualReconciliationCommand` (or manual button)

**Setup:**

- Admin enters: "2026 CAM Pool: EGP 500,000 total"
  - Security: EGP 300,000
  - Utilities: EGP 100,000
  - Maintenance: EGP 100,000

**Calculation:**

- Total leasable area: 5,000 sqm
- Lease A (Café Crema, A-01): 200 sqm → 10% of pool
  - Allocated: EGP 50,000
- Lease B (Optix, A-02): 100 sqm → 5% of pool
  - Allocated: EGP 25,000

**Billing:**

- Allocations status: "pending" → "billed"
- Invoices generated in Q1 next year (e.g., March 2027 CAM bill)
- Sends to all leases in property

**Admin Dashboard:**

- CAM reconciliation page shows:
  - Pool status (draft / finalized)
  - Per-lease allocation breakdown
  - Invoice generation history

---

### 6. ETA e-Invoicing

**Egyptian Tax Authority (ETA) Integration:**

**When:** Invoice issued → system can submit to ETA for tax compliance

**Toggle:** Module-enabled in `/admin/settings`; `ETA_MOCK=true` (default) uses mock responses

**Workflow:**

1. Invoice created, status = "draft"
2. Admin reviews details
3. Admin clicks "Confirm & Submit to ETA"
4. Backend calls ETA API:
   - Builds JSON document (tenant, items, VAT, signatures)
   - Signs with digital certificate
   - POSTs to ETA `/api/v1/documents`
5. ETA returns: `document_id`, `submission_ref`
6. Invoice status → "submitted"
7. ETA may respond: "accepted" or "rejected" (errors list)
8. Invoice status updates to "accepted" or "rejected"

**API Responses:**

- Accepted: Status "green" → document legally binding
- Rejected: Reasons list (e.g., "missing tax ID", "invalid unit price")

**Audit:**

- Invoice stores: `eta_status`, `eta_document_id`, `eta_errors`
- Activity log tracks submission timestamps
- PDF shows ETA barcode once accepted

**Sandbox vs. Live:**

- Sandbox (`ETA_MOCK=true`): Mock endpoints, instant acceptance
- Production (`ETA_MOCK=false`): Real ETA submission, credentials required

---

### 7. Multi-Property Support

**Property Scoping:**

- Every operation linked to `Asset` (property/mall)
- URL structure: `/admin/{property-code}/...`
- Example: `/admin/HAYA01/invoices`, `/admin/HAYA02/leases`

**User Assignment:**

- Pivot table: `asset_user`
- User can belong to multiple properties
- Dashboard widget shows: "You manage X properties"
- Top-nav switcher: "Haya Walk" | "City Center" | "All Properties"
- "All Properties" view bypasses scoping (portfolio level)

**Aggregation:**

- Reports can be: per-property or rolled up
- Owner portal: Views ALL assigned properties (read-only)

---

### 8. RBAC (Role-Based Access Control)

**6 Roles:**

1. **super_admin** — All permissions, all properties
2. **manager** — Full operations (billing, leases, payments), assigned properties
3. **leasing_manager** — Lease creation/renewal, unit setup, property registration
4. **maintenance_manager** — Maintenance requests, SLA tracking
5. **viewer** — Read-only dashboard, reports
6. **owner** — Own portal (read-only), invoices, payments, portfolio

**81 Permissions** (examples):

- `view_dashboard` — Access dashboard
- `view_invoices`, `edit_invoices`, `delete_invoices`
- `view_leases`, `create_leases`, `edit_leases`, `renew_leases`
- `view_payments`, `create_payments`, `edit_payments`
- `view_tenants`, `create_tenants`, `edit_tenants`
- `manage_users`, `manage_roles`, `manage_permissions`
- `access_reports`, `access_activity_log`
- `manage_modules` — Toggle feature flags

**Filament Policies:**
Each resource has a policy: `app/Policies/LeasePolicy.php`, etc.

```php
public function view(User $user, Lease $lease): bool
{
    return $user->can('view_leases') && $user->properties->contains($lease->asset_id);
}
```

---

### 9. Feature Flags (Module Toggles)

**Available Modules** (stored in `spatie/laravel-settings`):

- `credit_notes` — Reversals/debit memos
- `maintenance` — Work orders
- `tenant_sales` — Percentage rent
- `cam` — Common-area reconciliation
- `utility_meters` — Energy tracking
- `vendors` — Contractor management
- `notes` — Communication log
- `reports` — Canned dashboards
- `activity_log` — Audit trail
- `eta` — e-invoicing

**Toggle Location:** `/admin/{property}/settings` → "Modules" section

**Behavior When Disabled:**

- Resource hidden from Filament sidebar
- Dashboard widgets disabled
- Routes 403 (Forbidden)
- Mobile API may still return data, but admin forms blocked

---

### 10. Maintenance Request Tracking

**SLA Management:**

- Admin sets `sla_hours` (e.g., 24 for urgent, 72 for normal)
- `ScanMaintenanceSlaBreachesCommand` runs hourly (via cron)
- Flags overdue requests in dashboard (red badge)
- Email/SMS alert sent to assigned staff

**Workflow:**

1. Tenant submits request: "AC broken" (priority: urgent, category: HVAC)
2. Admin reviews, assigns to "Mohamed", sets SLA 24h
3. Mohamed visits, adds comment: "Compressor dead, ordering part"
4. Status → "in_progress"
5. Part arrives, Mohamed completes
6. Status → "completed", SLA met (green checkmark)

**Photo Attachments:**

- Tenant uploads photos via API
- Photos stored in Spatie MediaLibrary
- Admin views in request detail page

**Polymorphic Comments:**

- Type: "call", "message", "site_visit", "email", "whatsapp"
- Maintains full interaction log for audits

---

### 11. Activity Logging (Audit Trail)

**Tracked Events:**

- Every create/update/delete on governance entities
- Fields logged: old value → new value
- Human-readable diffs (strikethrough old, highlight new)
- XSS-safe HTML output

**Entities Tracked:**

- Lease, Invoice, Payment, CreditNote
- MaintenanceRequest, TenantSalesDeclaration
- User (permission changes)
- CamExpensePool, Charge, Unit, Tenant

**UI:**

- `/admin/{property}/activity-log` page
- Filters: Date range (6 presets + custom), Entity type, User
- Shows: Timestamp, User, Action, Before/After (colored diff)

**API:**

- Event listeners dispatch activity logs post-save
- Spatie listener handles persistence to `activity_log` table

---

### 12. Document Management

**Spatie MediaLibrary Integration:**

- Every model can attach documents
- Collections: "contracts", "identity", "photos", "permits"

**Examples:**

- Tenant: Store business license, tax certificate, ID copies
- Lease: Attach signed contract PDF
- MaintenanceRequest: Attach before/after photos
- Unit: Floor plans, CAD drawings

**Storage:**

- Default: `storage/app/media/`
- AWS S3 support (configurable)
- Automatic resizing for images
- Metadata: MIME type, size, upload date

---

## Authentication & Authorization

### Session-Based Auth (Web Panels)

**Filament Panel Setup:**

- Registered in `config/filament.php`
- Three panels:
  1. Admin (`/admin/{property}`) — Full CRUD
  2. Owner (`/owner`) — Read-only portfolio
  3. Tenant (`/portal`) — Personal invoices/requests

**Login Flow:**

1. User posts credentials to Filament guard
2. Session created (Laravel session store)
3. Redirect to dashboard
4. Property scope enforced by middleware `scoped-to-property`

**2FA:** Optional via `stephenjude/filament-two-factor-authentication`

- Enable in user settings
- TOTP codes via authenticator app

### Token-Based Auth (Mobile API)

**Laravel Sanctum Token Scheme:**

**Setup:**

- Guard: `auth:tenant-api` (custom, keyed on `tenants` provider)
- Token issued on `/api/v1/auth/login`
- Token stored in `personal_access_tokens` table

**Token Flow:**

```
1. Mobile app: POST /api/v1/auth/login { email, password }
2. Backend: Verifies tenant (via User where role = tenant)
   - Creates token: `$user->createToken('mobile-app')`
3. Response: { token: "1|abc..." }
4. Mobile stores token (secure storage: Keychain/Keystore)
5. Subsequent requests: Header: "Authorization: Bearer 1|abc..."
6. Middleware `auth:tenant-api` verifies token
```

**Token Revocation:**

- `POST /api/v1/auth/logout` deletes token
- Clears on password change
- Revoked tokens rejected on next API call

**Token Lifespan:**

- No automatic expiry (unless `SANCTUM_EXPIRATION` env var set)
- Manual revocation via logout

---

### Permission Model

**Spatie Laravel Permission Structure:**

```php
// User → Role → Permission
$user->assignRole('manager');
$user->hasRole('manager');
$user->hasPermission('view_invoices');

// Gate checks in policies
Gate::define('view-invoice', fn(User $user, Invoice $invoice) => 
    $user->can('view_invoices') && 
    $user->properties->contains($invoice->asset_id)
);
```

**Custom Guard:** `property-scoped`

- User must have role
- User must be assigned to property
- Checks both before allowing resource access

---

## Frontend Panels

### 1. Admin Console (`/admin/{property}`)

**Dashboard:**

- KPI widgets: Revenue YTD, occupancy rate, AR aging
- Expiring leases (30-day warning)
- Pending maintenance (SLA breaches)
- Sales declarations due
- Activity feed

**Sidebar (Property-Scoped):**

- Dashboard
- Tenants (CRUD)
- Units (CRUD, occupancy map)
- Leases (wizard, renewals, terminations)
- Invoices (list, detail, confirm ETA, download PDF)
- Payments (entry, reconciliation)
- Credit Notes
- Maintenance Requests (triage, SLA tracking)
- Reports (AR aging, occupancy, tenant directory)
- Tenant Sales Declarations
- CAM Reconciliation
- Utilities (meters, readings)
- Communications Log
- Activity Log
- Settings (module toggles, property info)

**Property Switcher:**

- Top-right dropdown
- "All Properties" option (if user assigned to 2+)
- Redirects to `/admin/{code}/...` on switch

**Key Pages:**

**Occupancy Map:** Visual floor plan (clickable units)

**Tenant Directory:** Consolidated list (unit + lease + status)

**Lease Wizard:**

- Step 1: Select tenant (+ create new inline)
- Step 2: Choose unit, dates, charges
- Calculates rent if per-sqm configured
- Submit → lease created, status "draft"
- Admin confirms (status → "active")

**Invoice Management:**

- "Run Monthly Billing" button (one-click invoice generation)
- Filters: Period, status, tenant, overdue
- Row actions: View detail, download PDF, confirm/submit to ETA, email tenant
- Bulk actions: Confirm all, email all

**Report Pages:**

- AR Aging Schedule (30–90+ day buckets)
- Occupancy Analysis (area, count, revenue %)
- Tenant Performance (sales trends, late payment patterns)

---

### 2. Owner Portal (`/owner`)

**Read-Only Portfolio View:**

- All assigned properties on one dashboard
- Invoices, payments, leases (aggregated across properties)
- Visibility: Units, tenants, AR
- No edit permissions

**Widgets:**

- Total portfolio revenue
- Occupancy by property
- Top tenants by rent
- Activity feed (last 10 events)

**Reports:**

- Portfolio P&L
- Tenant roster
- Lease expiry calendar

---

### 3. Tenant Portal (`/portal`)

**Self-Service UI:**

- Profile: Business name, contact, stored docs
- Balance: Overdue amount, due dates
- Invoices: Searchable list, detail view, PDF download
- Payments: History, make payment (via Paymob iframe)
- Leases: Active + historical, term details
- Maintenance: Submit requests, track status, upload photos
- Communications: Call log, messages
- Sales Declarations (if percentage-rent lease): Submit monthly sales

**Payment Flow:**

1. Tenant clicks "Pay" on invoice
2. Frontend calls `/api/v1/me/invoices/{id}/paymob-session`
3. Backend returns session token
4. Paymob iframe loads (card entry, bank account, e-wallet options)
5. Tenant authorizes
6. Paymob POSTs callback to backend
7. Payment recorded, invoice status updated
8. Tenant sees "Payment Successful"

---

## Console Commands

### Billing & Financial

#### 1. RunMonthlyBillingCommand

```bash
php artisan billing:run-monthly --period=2026-06 --property=HAYA01
```

**Purpose:** Generate invoices for all active leases in a property for a period.

**Flow:**

1. Query active leases
2. For each, create invoice + items
3. Apply Egyptian VAT rules
4. Status "draft" (awaiting admin confirmation)
5. Supports backfill (historical) & forecast (future)

**Idempotency:** Won't duplicate if invoice already exists.

---

#### 2. CamAnnualReconciliationCommand

```bash
php artisan cam:reconcile --year=2026 --property=HAYA01
```

**Purpose:** Calculate pro-rata CAM charges and generate invoices.

**Flow:**

1. Retrieve CamExpensePool for year
2. Calculate per-lease allocations (area-based)
3. Create CamAllocation records
4. Invoice generation (next quarter)

---

#### 3. ApplyLateFeesCommand

```bash
php artisan billing:apply-late-fees --property=HAYA01
```

**Purpose:** Calculate and add interest to overdue invoices (if configured).

**Logic:**

- Query invoices past due by N days
- Calculate interest: `remaining × interest_rate × (days_late / 365)`
- Create charge, generate supplemental invoice
- Configurable: interest rate, threshold days

---

### Maintenance

#### 4. ScanMaintenanceSlaBreachesCommand

```bash
php artisan maintenance:scan-sla-breaches
```

**Purpose:** Identify overdue maintenance requests.

**Action:**

- Query open/in-progress requests
- Compare `scheduled_date + sla_hours` vs. now
- Flag breaches (set status flag, send notification)
- Admin dashboard shows red badge

**Frequency:** Cron job (hourly recommended)

---

#### 5. AutoCloseMaintenanceRequestsCommand

```bash
php artisan maintenance:auto-close --idle-days=7
```

**Purpose:** Close stale requests (no activity for N days).

**Precaution:** Requires manual confirmation or dry-run mode.

---

### Contracts & Expiry

#### 6. ExpireVendorContractsCommand

```bash
php artisan vendors:expire-contracts --alert-days=30
```

**Purpose:** Notify admins of expiring vendor contracts.

**Logic:**

- Query contracts where `end_date ≤ TODAY + 30 days`
- Send email alert to assigned manager
- Dashboard widget shows count

---

### System

#### 7. MergeCoverageCommand

```bash
php artisan test:merge-coverage
```

**Purpose:** Merge Pest + Playwright coverage reports (internal dev tool).

---

## Development Guide

### Setup (First-Time)

```bash
# Clone repo
git clone <repo> mall-management
cd mall-management

# Backend setup
composer install
cp .env.example .env
php artisan key:generate

# Frontend setup
npm install

# Database
php artisan migrate:fresh --seed

# Serve
php artisan serve          # Terminal 1: PHP (port 8000)
npm run dev               # Terminal 2: Vite (port 5173)
```

**Demo Accounts (password: `password`):**

- Admin: `admin@mall.test`
- Manager: `manager@mall.test`
- Leasing: `leasing@mall.test`
- Maintenance: `maintenance@mall.test`
- Viewer: `viewer@mall.test`
- Owner: `owner@jawad.test`
- Tenant 1: `tenant1@haya.test` (Café Crema)
- Tenant 2: `tenant2@haya.test` (Optix Eyewear)
- Tenant 3: `tenant3@haya.test` (Burger Joint)

### Concise Dev Commands

```bash
# Run dev servers (all 4 in one command)
composer run dev

# Database
php artisan migrate              # Apply pending migrations
php artisan migrate:fresh --seed # Rebuild demo state
php artisan migrate:rollback     # Revert last batch
php artisan tinker               # Interactive shell

# Code quality
./vendor/bin/pint                # Fix style issues
./vendor/bin/pest --parallel     # Run all tests
./vendor/bin/pest tests/Feature/LeaseTest.php  # Single file
npm run build                    # Production JS/CSS

# Local debugging
php artisan pail                 # Real-time logs
php artisan queue:listen         # Watch job queue
php artisan horizon              # Queue dashboard (if installed)
```

### Code Organization

**Actions (Single-Responsibility):**

- `app/Actions/Api/V1/Auth/LoginTenantAction.php` — Validates & returns token
- `app/Actions/Api/V1/Maintenance/CreateMaintenanceRequestAction.php` — Validates + creates + notifies

**Services (Multi-Step Workflows):**

- `app/Services/BillingService.php` — Invoice generation logic
- `app/Services/EtaService.php` — ETA submission, PDF signing
- `app/Services/PaymobService.php` — Payment gateway integration

**Models (Eloquent with Scopes):**

```php
// app/Models/Invoice.php
public function scopeOverdue($query)
{
    return $query->where('status', 'issued')
                 ->where('due_date', '<', now());
}

$overdue = Invoice::overdue()->get();
```

**Filament Resources:**

- `app/Filament/Admin/Resources/InvoiceResource.php`
  - Defines: Table schema, form schema, actions, policies
  - Auto-wired to `/admin/{property}/invoices` route

**Middleware:**

- `app/Http/Middleware/ScopeToProperty.php` — Enforces property scoping
- `app/Http/Middleware/SetLocale.php` — Switches AR/EN from session

### API Endpoint Template

```php
// app/Http/Controllers/Api/V1/Invoices/ListInvoicesController.php
<?php

namespace App\Http\Controllers\Api\V1\Invoices;

use App\Http\Resources\InvoiceResource;
use Illuminate\Http\JsonResponse;

class ListInvoicesController
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $request->user();
        $invoices = $tenant->leases()
            ->with('invoices')
            ->get()
            ->pluck('invoices')
            ->flatten()
            ->paginate(15);

        return response()->json([
            'data' => InvoiceResource::collection($invoices),
            'message' => 'Invoices retrieved',
        ]);
    }
}
```

### Event & Listener Pattern

```php
// app/Events/InvoiceIssued.php
public function __construct(Invoice $invoice) { $this->invoice = $invoice; }

// app/Listeners/LogInvoiceActivity.php
public function handle(InvoiceIssued $event)
{
    activity()
        ->on($event->invoice)
        ->log('Invoice issued: ' . $event->invoice->number);
}

// config/event.php or register in EventServiceProvider
'App\Events\InvoiceIssued' => ['App\Listeners\LogInvoiceActivity'],
```

### Configuration

**Environment Variables (.env):**

```bash
APP_NAME=Atriom
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mall_management
DB_USERNAME=root
DB_PASSWORD=

# Paymob Integration
PAYMOB_ENABLED=false
PAYMOB_API_KEY=pk_...
PAYMOB_SECRET_KEY=...

# ETA e-Invoicing
ETA_ENABLED=false
ETA_MOCK=true
ETA_CERT_PATH=/path/to/cert.pfx
ETA_CERT_PASSWORD=...

# Localization
APP_LOCALE=ar
FALLBACK_LOCALE=en

# Feature Flags (overrides in Settings)
FEATURE_MAINTENANCE=true
FEATURE_CAM=true
FEATURE_TENANT_SALES=true
```

---

## Testing

### Unit & Feature Tests (Pest)

```bash
# Run all tests
vendor/bin/pest --parallel

# Specific file
vendor/bin/pest tests/Feature/BillingTest.php

# With coverage
vendor/bin/pest --coverage
```

**Test Structure:**

```php
// tests/Feature/BillingTest.php
it('generates invoices for active leases', function () {
    $lease = Lease::factory()->active()->create();
    $charge = Charge::factory()->for($lease)->create();

    (new RunMonthlyBillingCommand())->handle();

    $this->assertDatabaseHas('invoices', [
        'lease_id' => $lease->id,
        'period' => now()->format('Y-m'),
    ]);
});
```

**Test Suite Coverage:**

- **Tenancy:** Property scoping, user assignment
- **Models:** Relationships, scopes, casting
- **Services:** Billing, ETA, Paymob logic
- **Widgets:** Dashboard KPIs
- **RBAC:** Permission checks, policies
- **Activity Log:** Field diffs, auditing
- **Auth:** Login, token generation, 2FA

### E2E Tests (Playwright)

```bash
# Run all specs
npx playwright test

# Watch mode (development)
npx playwright test --watch

# Single spec
npx playwright test tests/e2e/auth.spec.js
```

**Example E2E Test:**

```javascript
// tests/e2e/billing.spec.js
test('admin can run monthly billing', async ({ page }) => {
    await page.goto('http://localhost:8000/admin/HAYA01');
    await page.fill('input[name="email"]', 'admin@mall.test');
    await page.fill('input[name="password"]', 'password');
    await page.click('button:has-text("Login")');

    await page.click('text="Invoices"');
    await page.click('button:has-text("Run Monthly Billing")');
    await page.click('button:has-text("Confirm")');

    await expect(page.locator('text="Billing completed"')).toBeVisible();
});
```

---

## Deployment

### Production Checklist

- [ ] `.env` configured with production DB, Paymob, ETA credentials
- [ ] `php artisan optimize` (config caching)
- [ ] Verify SSL/TLS certificate installed
- [ ] Database backups scheduled
- [ ] Log rotation configured (Laravel logs, MySQL logs)
- [ ] Cron jobs registered (scheduler):

  ```bash
  * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
  ```

- [ ] Queue worker running (if async jobs enabled):

  ```bash
  php artisan queue:work --daemon
  ```

- [ ] Paymob credentials validated (test transaction)
- [ ] ETA credentials & SSL cert installed
- [ ] Email service configured (Swift/AWS SES)
- [ ] File storage path writable (`storage/app`, `public/storage`)
- [ ] Session driver configured (file/database/redis)

### Scaling Considerations (From TECH-DEEPDIVE.md)

- **Database:** Index on `(asset_id, status, period)` for invoices
- **API:** Implement Redis caching for frequently-accessed data (invoice PDFs)
- **Jobs:** Queue long-running operations (PDF generation, ETA submission)
- **Sessions:** Use Redis for session store (multi-server deployments)

---

## Additional Resources

| Document | Purpose |
|----------|---------|
| [FEATURES.md](FEATURES.md) | Complete feature list with implementation notes |
| [TECH-DEEPDIVE.md](TECH-DEEPDIVE.md) | Security, scaling, ETA architecture, testing strategy |
| [MASTER-PLAN.md](MASTER-PLAN.md) | Business strategy, competitive positioning |
| [MOBILE-APP-BRIEF.md](MOBILE-APP-BRIEF.md) | Mobile app requirements & roadmap |
| [docs/api/MOBILE-API.md](docs/api/MOBILE-API.md) | API reference for mobile developers |
| [DEMO.md](DEMO.md) | Live demo script |

---

## Key Contacts & Support

**Project Owner:** Atriom Team | Code: Triple Tech  
**License:** Proprietary — Commercial product  
**Status:** Production-ready with demo/pilot deployments

---

**Generated:** 2 June 2026  
**Version:** 1.0
