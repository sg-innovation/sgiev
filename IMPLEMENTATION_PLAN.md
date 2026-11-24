# Trans EV — Implementation Plan

**Brand:** Trans EV · **Domain:** transevrecharge.com · **Tagline:** Charge · Drive · Repeat
**Logo files:** [brand/logo.svg](brand/logo.svg) (light backgrounds), [brand/logo-dark.svg](brand/logo-dark.svg) (dark backgrounds), [brand/logo-mark.svg](brand/logo-mark.svg) (icon / favicon / app icon)

---

> **Status & later decisions (26 Nov 2026)**
> - **SaaS marketplace:** many charging companies on one platform. Each company has its own stations, staff, rules, GSTIN and invoices; Tranzacta (Trans EV) is the platform operator. Customers see every company's stations on one map.
> - **Payments:** SabPaisa replaces Razorpay. Each company connects **its own SabPaisa merchant account**, so customer money goes directly to the operator. The platform charges operators separately.
> - **GST:** invoices use a GSTIN registered in the station's state (the station's own, or the company's if it is in that state); the supply is intra-state.
> - **Built so far:** Phases 0–2, Phase 3 payments (SabPaisa), multi-company, admin dashboard, blog, legal pages, contact inbox and customer vehicles. Covered by 85 automated tests.
> - **SGI EV SRS:** full gap analysis and the plan for the remaining scope (OCPP, mobile apps, walk-in, no-show, waiting list, notifications, reviews, plans) are in [SGI_EV_GAP_ANALYSIS.md](SGI_EV_GAP_ANALYSIS.md).

## 1. Goals

A production-ready EV charging platform with three parts:

1. **Customer site**: find nearby stations on a GPS map or as a list, accept the station's rules, book a slot, pay online, and download a payment receipt and a GST tax invoice.
2. **Admin console**: full CRUD for stations and chargers, opening hours, status (Online / Offline / Maintenance), pricing and GST, rules, bookings, transactions, refunds, users, and accounting reports.
3. **Accounting core**: double-entry ledger, GST-compliant invoice numbering, settlement reconciliation with the payment gateway, and exportable reports.

---

## 2. Tech Stack

| Layer | Choice | Why |
|---|---|---|
| Backend | **Laravel 12 (PHP 8.3)** | Mature MVC, queues, scheduler, mail, policies built in |
| Database | **MySQL 8.0+** (InnoDB, utf8mb4) | Spatial `POINT SRID 4326` column + `ST_Distance_Sphere` for "nearest station" |
| Customer frontend | **Inertia.js 2 + React 19 + TypeScript** (Laravel React starter kit) | SPA feel with Laravel routing and SSR for SEO. No separate API to maintain |
| UI | **Tailwind CSS v4 + shadcn/ui + Framer Motion** | Modern design, dark mode, smooth animation |
| Admin panel | **Filament 4** | Fast, polished CRUD, tables, filters, charts, actions and exports for `/admin` |
| Icons | lucide-react + simple-icons (social) | |
| Auth | Laravel Fortify (starter kit) + **Socialite** (Google) + phone OTP | Sessions for web. **Sanctum** tokens later for a mobile app |
| Roles | **spatie/laravel-permission** | USER / STATION_MANAGER / ADMIN / SUPER_ADMIN, plus Laravel Policies |
| Maps | **MapLibre GL + OpenStreetMap tiles** (or Google Maps if you have a key) | Free, interactive, clustering |
| Payments | **SabPaisa PG 3.0** (REST API, no SDK needed): UPI, cards, net-banking, wallets | HMAC-SHA256 signed requests, hosted checkout, signed return URL, webhooks, status enquiry and refunds API. Behind a `PaymentGateway` interface so another gateway can be added later |
| PDF | **spatie/laravel-pdf** (Browsershot/Chromium) or barryvdh/laravel-dompdf | Invoice and receipt Blade templates |
| QR codes | simplesoftwareio/simple-qrcode | Check-in and invoice verification |
| Excel/CSV | **maatwebsite/excel** | GST and transaction exports |
| Audit log | **spatie/laravel-activitylog** | Before/after on every admin change |
| Media | spatie/laravel-medialibrary | Station photos |
| Email/SMS | Laravel Mail (SMTP/SES/Resend) + MSG91 (SMS/OTP) via Notifications | Booking confirmations, invoices |
| Jobs | **Laravel Queues (Redis or database driver) + Scheduler**, monitored with Horizon | Slot-hold expiry, reminders, maintenance auto-revert |
| Validation | Form Requests (server) + React Hook Form + Zod (client) | |
| Charts | Filament chart widgets (admin), Recharts (customer) | |
| Hosting | VPS (Ubuntu + Nginx + PHP-FPM + MySQL + Redis) managed with Laravel Forge/Ploi, or Laravel Cloud | Supervisor for queue workers, cron for scheduler |
| Testing | **Pest** (unit + feature) + Playwright (E2E) | |

---

## 3. Design System

- **Palette** (from the logo): Volt Green `#00E5A8`, Electric Blue `#0077FF`, Ink `#0B1B2B`, Slate `#5B6B7B`, Surface `#F6F9FC`; dark mode uses Ink as the base.
- **Typography:** Poppins (headings) and Inter (body).
- **Style:** glassmorphism cards on a soft gradient mesh, rounded-2xl corners, animated charging-pulse indicators, skeleton loaders, micro-interactions on hover and selection.
- **Status chips:** 🟢 Online · 🔴 Offline · 🟠 Maintenance · ⚪ Closed (outside hours).
- Responsive first (mobile 360px and up). PWA installable, with the logo mark as the app icon.

---

## 4. Modules & Features

### 4.1 Public / Customer

| Page | Features |
|---|---|
| **Home** | Hero with animated EV, "Find nearest charger" CTA (asks for GPS), live stats (stations, kWh delivered, CO₂ saved), how-it-works, pricing teaser, testimonials, FAQ |
| **Find Stations** `/stations` | Toggle between **Map** and **List** views. Browser Geolocation API finds the user's position (manual city/pin search if GPS is denied). Sorted by distance (MySQL `ST_Distance_Sphere` on a spatial index). Filters: connector type (CCS2, Type 2, CHAdeMO, Bharat AC-001/DC-001, GB/T), power (kW), AC/DC, open now, available now, price. Marker clustering, colour-coded by status. Route link to Google Maps |
| **Station Detail** `/stations/[slug]` | Photos, address, amenities, opening hours (today highlighted), chargers with live status, tariff table (energy ₹/kWh, session fee, idle fee, GST %), **station rules**, reviews |
| **Booking Wizard** `/book/[stationId]` | 1. Choose charger and connector → 2. Choose date and slot (30/60-minute grid; booked, maintenance and closed slots disabled) → 3. Vehicle details (make, model, reg. no., battery kWh, current %, target %) → 4. Personal details (name, phone, email, optional GSTIN for a B2B invoice) → 5. **Rules acceptance** (scroll-to-end + checkbox, rule version stored) → 6. Review with a cost estimate and GST breakdown → 7. Pay |
| **Payment** | SabPaisa hosted checkout. Slot is held for 10 minutes, then released. Confirmed only after a server-side check (webhook or status enquiry). Pending, failed and retry states |
| **Booking Success** | Booking ID, QR code for check-in at the station, payment receipt, **tax invoice PDF**, add-to-calendar, directions |
| **My Account** | Profile, saved vehicles, bookings (upcoming/past/cancelled), cancel or reschedule (per policy), invoices and receipts download, wallet/refund history |
| **Static** | About, Contact (form), Pricing, Terms, Privacy, Refund & Cancellation Policy, Charging Safety Guidelines |
| **Footer** | Logo, quick links, contact, **social icons**: Facebook, Instagram, X (Twitter), LinkedIn, YouTube, WhatsApp (admin-editable URLs) |

### 4.2 Admin Console `/admin`

| Section | Features |
|---|---|
| **Dashboard** | Today's revenue, bookings, kWh, utilisation %, station status map, revenue chart (day/week/month), top stations, recent transactions |
| **Stations (CRUD)** | Create, edit, delete (soft) and view. Fields: name, slug, address, city, state, pincode, lat/lng (pick on map), photos, amenities, contact, **legal entity / GSTIN / state code per station**, **opening and closing hours per weekday** plus holiday overrides, 24×7 toggle, slot duration, buffer between slots |
| **Station status control** | One-click **Online / Offline / Maintenance** with reason, start/end window and auto-revert. Maintenance windows block slots. Affected bookings can be notified and auto-refunded or rescheduled. Full status history log |
| **Chargers (CRUD)** | Per station: charger ID, OEM, AC/DC, power kW, connector types, individual status |
| **Pricing & Tax** | Per station and charger: energy rate ₹/kWh, or time-based ₹/min, session/booking fee, idle fee, peak/off-peak, **GST rate (default 18%), SAC code (default 998714, admin-editable)**, CGST+SGST vs IGST logic, price effective dates (price history kept) |
| **Rules** | Rule sets (global plus per-station override), rich text, **versioning**. A new version forces re-acceptance. Acceptance log (user, version, timestamp, IP) |
| **Bookings** | List, filter and export. View, check-in, mark completed with actual kWh (final bill), cancel, refund, no-show |
| **Transactions & Accounting** | All payments, refunds and settlements. Gateway reconciliation, ledger view, credit notes, GST reports (B2B/B2C, GSTR-1 style export), P&L per station, CSV/Excel export |
| **Invoices** | Invoice template settings (logo, company details, bank details, T&Cs, signature), numbering series per financial year (e.g. `TEV/25-26/000123`), regenerate/resend, credit notes |
| **Users** | User CRUD, block/unblock, roles, station-manager assignment (sees only their stations) |
| **CMS / Settings** | Company profile, social links, homepage content, FAQ, email templates, payment gateway keys (encrypted), cancellation policy windows |
| **Audit Log** | Every admin action recorded (who, what, before/after) |

---

## 5. Data Model (MySQL tables / Eloquent models, summary)

Money is stored as `DECIMAL(12,2)` and energy as `DECIMAL(10,3)`. Never use floats. Timestamps are stored in UTC and displayed in `Asia/Kolkata`. JSON fields use MySQL `JSON` columns with Eloquent casts.

```
users(id, name, email, phone, password, gstin?, blocked_at, ...)   + spatie roles tables
Vehicle(id, userId, make, model, regNo, batteryKwh, connectorType)
Company(id, legalName, tradeName, gstin, pan, address, stateCode, logo, bankDetails, invoicePrefix, socialLinks JSON)
Station(id, companyId, name, slug, address, city, state, stateCode, pincode,
        lat DECIMAL(10,7), lng DECIMAL(10,7), location POINT SRID 4326 (SPATIAL INDEX), gstin, status[ONLINE|OFFLINE|MAINTENANCE],
        is24x7, slotMinutes, bufferMinutes, amenities[], photos[], deletedAt)
OperatingHours(id, stationId, weekday 0-6, opensAt, closesAt, isClosed)
HolidayOverride(id, stationId, date, opensAt?, closesAt?, isClosed)
StatusLog(id, stationId|chargerId, from, to, reason, startsAt, endsAt, byUserId)
Charger(id, stationId, code, type[AC|DC], powerKw, connectors[], status)
Tariff(id, stationId?, chargerId?, ratePerKwh, ratePerMin?, sessionFee, idleFeePerMin,
       gstRate, sacCode, peakRules JSON, effectiveFrom, effectiveTo)
RuleSet(id, stationId?, version, title, contentHtml, isActive, publishedAt)
RuleAcceptance(id, userId, ruleSetId, version, bookingId, ip, userAgent, acceptedAt)
Booking(id, code, userId, stationId, chargerId, vehicleId, startAt, endAt,
        status[HELD|CONFIRMED|CHECKED_IN|COMPLETED|CANCELLED|NO_SHOW|EXPIRED],
        estKwh, actualKwh?, estAmount, finalAmount?, customerSnapshot JSON)
Payment(id, bookingId, gateway, gatewayOrderId, gatewayPaymentId, amount, status, method, raw JSON)
Refund(id, paymentId, amount, reason, gatewayRefundId, status)
Invoice(id, number, fy, bookingId, type[TAX_INVOICE|CREDIT_NOTE], supplierSnapshot, buyerSnapshot,
        lines JSON, taxable, cgst, sgst, igst, total, placeOfSupply, pdfUrl, issuedAt)
LedgerEntry(id, txnId, account, debit, credit, stationId, refType, refId, createdAt)
AuditLog(id, actorId, action, entity, entityId, before JSON, after JSON, ip, userAgent, reason, prevHash, hash, at)
ApprovalRequest(id, type[REFUND|TARIFF|GST|JOURNAL|WAIVER|COMPANY], payload JSON, makerId, checkerId?, status, decidedAt, note)
AccountingPeriod(id, fy, month, status[OPEN|LOCKED], lockedBy, lockedAt)
InvoiceSequence(id, companyId, gstin, fy, prefix, lastNumber)            -- row-locked on allocation
JournalEntry(id, date, periodId, refType, refId, narration, createdBy, prevHash, hash) → LedgerEntry lines
Settlement(id, gatewaySettlementId, amount, fees, tax, utr, settledAt) · ReconciliationException(id, type, refs, status, ownerId, resolution)
ConsentRecord(id, userId, purpose, noticeVersion, grantedAt, withdrawnAt)
SecurityEvent(id, userId?, type, ip, userAgent, meta JSON, at)
AccountingOutbox(id uuid, event, aggregateType, aggregateId, payload JSON, status, attempts, nextAttemptAt, externalId, lastError)
ExternalMapping(id, localType, localId, provider, externalId, externalRef, syncedAt)
LedgerAccountMap(id, localAccount, provider, externalCode)
```

The table names above are shorthand. Migrations use snake_case plural tables (`stations`, `operating_hours`, `bookings`, ...) with foreign keys and indexes on `(charger_id, start_at, end_at)` and `status`. Stations use `SoftDeletes`.

**Slot double-booking protection:** MySQL has no exclusion constraints, so `BookingService::hold()` runs in `DB::transaction()`. It locks the charger row with `lockForUpdate()`, checks for overlapping active bookings (`start_at < :end AND end_at > :start`), then inserts. A 10-minute `HELD` state is expired by a scheduled job.

**Nearest-station query:**
```sql
SELECT *, ST_Distance_Sphere(location, ST_SRID(POINT(:lng, :lat), 4326)) AS distance_m
FROM stations
WHERE deleted_at IS NULL
  AND MBRContains(:bounding_box, location)   -- uses the spatial index
ORDER BY distance_m
LIMIT 50;
```

---

## 6. Key Business Logic

1. **Availability = station status ∩ operating hours ∩ holiday overrides ∩ maintenance windows ∩ charger status ∩ existing bookings.** It is computed server-side and is the only source of truth.
2. **Pricing estimate:** `energy = (target% − current%) × batteryKwh / efficiency(0.9)`, capped by `chargerKw × slotHours`. `amount = energy × ratePerKwh + sessionFee`, then GST is added.
3. **Final bill (optional, post-session):** the admin or station manager enters actual kWh. The difference is refunded, or a top-up is collected, and the final invoice or credit note is issued.
4. **GST:** if the station's state code equals the place of supply, use **CGST + SGST (9% + 9%)**, otherwise **IGST 18%**. Rate and SAC are admin-configurable.
5. **Invoice numbering:** a gapless sequence per company per financial year (April–March), generated inside a DB transaction.
6. **Cancellation:** admin-configurable windows (for example >2 h: 100% refund; 30 min–2 h: 50%; <30 min: none). Refunds go through the SabPaisa refunds API and generate a credit note.
7. **Payment security:** verify SabPaisa HMAC signatures (return URL and webhook), then confirm with the status-enquiry API; a booking is confirmed **only after server-side verification**. Webhooks are de-duplicated on `idempotency_key`.
8. **Rules:** booking cannot proceed without accepting the latest active version for that station.

---

## 7. Invoice & Receipt Templates

**Tax Invoice (A4 PDF):**
- Header: Trans EV logo, legal name, GSTIN, PAN, address, transevrecharge.com, support contact
- Invoice no., date, booking ID, place of supply (state + code), reverse charge: No
- Bill-to: customer name, phone, email, address, GSTIN (if B2B)
- Service details: station name/address, charger ID and type, connector, vehicle reg. no., slot start–end, kWh delivered
- Line items: SAC, description, qty (kWh), rate, taxable value, CGST/SGST or IGST (rate and amount), total
- Amount in words, payment method, gateway transaction ID, payment timestamp
- QR code (verification link to `/verify/invoice/{id}`), terms, authorised signatory, "computer-generated invoice"

**Payment Receipt:** a short format with amount paid, mode, transaction/UTR ID, status, booking summary, QR check-in code.

Both are emailed automatically and downloadable from My Account and the admin panel.

---

## 8. Seed Data (reference tariffs, India, 2026)

The tariffs below are market reference ranges collected from public sources. **Admin must confirm them before launch.**

| Charger type | Typical public price (₹/kWh, excl. GST) | Seed value |
|---|---|---|
| AC slow, 3.3–7.4 kW (Type 2 / Bharat AC-001) | ₹10–18 | **₹14** |
| AC 22 kW | ₹14–18 | **₹16** |
| DC 30–60 kW (CCS2) | ₹18–24 | **₹21** |
| DC 120 kW+ highway hub (CCS2) | ₹22–26 | **₹24** |

- Session/booking fee: ₹20 · Idle fee: ₹2/min after a 10-minute grace period
- GST: **18%, SAC 998714** (charging is generally billed as a service at 18%; confirm with your CA)
- Seeded: 1 company, ~12 stations across Delhi NCR, Mumbai, Pune, Bengaluru, Hyderabad and Chennai (real-looking coordinates, fictional names such as "Trans EV Hub – Connaught Place"), 2–4 chargers each, weekday hours (e.g. 06:00–23:00, some 24×7), one station in Maintenance and one Offline for demo, default rule set v1, admin user, demo user, sample bookings, payments and invoices.

**Default station rules (seed v1):**
1. Arrive within 10 minutes of slot start or the booking may be marked a no-show.
2. Use only compatible connectors. Do not force plugs.
3. Engine/vehicle off, handbrake on, no smoking or open flames within 5 m.
4. Do not leave the vehicle unattended beyond the slot. Idle fees apply after the grace period.
5. Report any damage, sparks, burning smell or fault to staff or the helpline immediately.
6. Do not charge in standing water or with damaged cables or vehicle inlet.
7. Children and pets must not handle charging equipment.
8. Trans EV is not liable for damage from vehicle defects or misuse. Follow your OEM charging guidelines.
9. Cancellation and refund per the published policy.
10. CCTV in operation. Follow staff instructions.

---

## 9. Project Structure

```
trans-ev/                                  (Laravel 12)
├─ app/
│  ├─ Enums/          StationStatus · BookingStatus · ChargerType · ConnectorType · PaymentStatus
│  ├─ Models/         User · Vehicle · Company · Station · OperatingHour · HolidayOverride · Charger · Tariff
│  │                  RuleSet · RuleAcceptance · Booking · Payment · Refund · Invoice · LedgerEntry · StatusLog
│  ├─ Http/
│  │  ├─ Controllers/ HomeController · StationController · BookingController · PaymentController
│  │  │               SabPaisaReturnController · SabPaisaWebhookController · InvoiceController · Account/*
│  │  ├─ Requests/    StoreBookingRequest · AcceptRulesRequest · ...
│  │  └─ Middleware/  EnsureRulesAccepted · VerifySabPaisaSignature
│  ├─ Services/       AvailabilityService · PricingService · GstService · BookingService
│  │                  InvoiceNumberService · InvoiceService · LedgerService · Payments/SabPaisaGateway
│  ├─ Jobs/           ExpireHeldBookings · SendBookingReminder · RevertMaintenanceStatus · GenerateInvoicePdf
│  ├─ Notifications/  BookingConfirmed · InvoiceIssued · StationStatusChanged · RefundProcessed
│  ├─ Policies/       StationPolicy · BookingPolicy · InvoicePolicy
│  └─ Filament/       Resources/ (Station, Charger, Tariff, RuleSet, Booking, Payment, Invoice, User)
│                     Pages/ (Dashboard, GstReport, Reconciliation, Settings) · Widgets/ (Revenue, Utilisation, StatusMap)
├─ database/
│  ├─ migrations/
│  ├─ factories/
│  └─ seeders/        CompanySeeder · StationSeeder · TariffSeeder · RuleSetSeeder · UserSeeder · DemoBookingSeeder
├─ resources/
│  ├─ js/ (Inertia + React + TS)
│  │  ├─ pages/       Home · Stations/Index · Stations/Show · Booking/Wizard · Booking/Success · Account/* · Legal/*
│  │  ├─ components/  ui/ (shadcn) · map/StationMap · booking/SlotGrid · layout/Header · layout/Footer (social icons)
│  │  └─ lib/         geo.ts · format.ts
│  └─ views/pdf/      tax-invoice.blade.php · receipt.blade.php · credit-note.blade.php
├─ routes/            web.php · auth.php · console.php (scheduler) · webhooks in web.php (CSRF-exempt)
├─ public/brand/      logo.svg · logo-dark.svg · logo-mark.svg · favicons
└─ tests/             Feature/ · Unit/ (Pest) · e2e/ (Playwright)
```

**Main routes:**
- `GET /stations?lat=&lng=&radius=&filters` for map and list (Inertia page plus a JSON endpoint for map panning)
- `GET /stations/{slug}`
- `GET /stations/{station}/availability?date=` returns the slot grid as JSON
- `POST /bookings` (hold the slot) → `POST /bookings/{booking}/rules` (accept rules) → `POST /bookings/{booking}/pay` (create a SabPaisa payment session)
- `GET /payments/sabpaisa/return` (customer comes back from checkout) and `POST /webhooks/sabpaisa` confirm the payment and issue the invoice
- `GET /invoices/{invoice}/pdf`, `GET /verify/invoice/{uuid}`
- `/admin/*` is served by Filament

---

## 10. Delivery Phases

| Phase | Scope | Est. |
|---|---|---|
| **0. Setup** | Laravel 12 + React starter kit (Inertia, TS, Tailwind, shadcn), MySQL 8 + Redis (Laravel Sail/Herd), Filament 4, spatie packages, Pest, CI, brand assets, layout, header and footer with social icons | 2 days |
| **1. Stations & Map** | Station/charger/hours migrations and models, Filament CRUD, status control with logs, public map and list, GPS nearest search (MySQL spatial), filters, station detail | 5 days |
| **2. Rules & Booking** | Rule sets and versioning, acceptance log, AvailabilityService, slot grid, booking wizard, transactional hold with row lock, expiry job | 5 days |
| **3. Payments** | SabPaisa payment sessions, hosted checkout, signed return URL, webhook, status enquiry, refunds, cancellation policy, receipts | 4 days |
| **4. Accounting & Invoices** | GST engine, invoice numbering, Blade PDF templates, credit notes, ledger, reconciliation, GST and P&L reports, Excel exports | 5 days |
| **5. User & Admin polish** | My Account, vehicles, Filament dashboard widgets, user management, roles/station managers (scoped queries), settings, audit log, email/SMS notifications | 4 days |
| **6. Seed & QA** | Seed script, Pest unit and feature tests (pricing, GST, availability, ledger balancing, invoice sequence), Playwright E2E (book → pay → invoice → refund → credit note), SEO, PWA | 3 days |
| **7. Audit readiness** | Security hardening (section 11), financial controls review (section 12), SAST/DAST scans, third-party VAPT and fixes, audit documentation pack (section 14), UAT with the finance and security teams | 5–7 days |
| **8. Launch** | Production deploy to transevrecharge.com, backups verified by a restore test, monitoring and alerting live, go-live sign-off by the finance and security teams | 2 days |

| **Later: SGI switch-on** | SGI driver, ledger mapping, backfill, daily reconciliation (section 13.4) | 4–5 days |

**Total: about 7–8 weeks for one developer**, plus VAPT vendor turnaround. Phase 4 includes about 2 extra days for the SGI-ready outbox and mappings.

---

## 11. Security Controls (built for a security audit)

Target standard: **OWASP ASVS 4.0 Level 2** and the OWASP Top 10, with Indian regulatory requirements below. Each control is mapped to an ASVS requirement in the controls matrix (section 14).

### 11.1 Authentication & Access
- **Mandatory MFA (TOTP) for all admin, finance and station-manager accounts.** Optional MFA for customers
- Passwords hashed with Argon2id. Minimum 12 characters, checked against breached-password lists (`Password::uncompromised()`)
- Account lockout and progressive delays after failed logins. Login alerts for new devices
- Session security: `Secure`, `HttpOnly`, `SameSite=Lax` cookies. Session regenerated on login. 15-minute idle timeout for admin, 30 days with remember-me for customers
- **Role-Based Access Control** with least privilege (spatie permissions plus Laravel Policies on every controller, Filament resource and action):

| Role | Can do | Cannot do |
|---|---|---|
| Customer | Own bookings, invoices, vehicles | Anything else |
| Station Manager | Status, hours, check-ins for **assigned stations only** | Tariffs, GST, refunds, users |
| Operations Admin | Stations, chargers, rules, bookings | Approve refunds, change tax settings, see ledger |
| Finance | Ledger, invoices, refunds (approve), reports, period close | Edit stations or users |
| Auditor (read-only) | Read everything, including the audit log and exports | Any write |
| Super Admin | User and role management | Delete financial records (nobody can) |

- **Segregation of duties:** whoever creates a refund or tariff change cannot approve it (see 12.3)
- Admin panel optionally restricted by IP allow-list or VPN. Separate admin subdomain (`admin.transevrecharge.com`)

### 11.2 Application Security
- Server-side validation on every input (Form Requests). Eloquent and parameter binding only, no raw SQL with user input
- Output escaping (Blade/React), plus a strict **Content Security Policy**, HSTS (preload), `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` and `frame-ancestors 'none'` (via a security-headers middleware)
- CSRF on all state-changing routes. The webhook is exempted but protected by HMAC signature and an IP allow-list
- IDOR protection: authorisation on every record access. Public references use UUIDs and booking codes, never sequential IDs
- File uploads (station photos): MIME and extension allow-list, size limit, re-encoded images, stored outside the web root, malware scan
- Rate limiting on login, OTP, booking, payment and contact endpoints. CAPTCHA (Cloudflare Turnstile) on public forms
- Mass-assignment protection (`$fillable`), `APP_DEBUG=false` in production, generic error pages, no stack traces exposed
- Dependency security: `composer audit` and `npm audit` in CI, Dependabot, pinned versions
- **SAST** (PHPStan level 8 + Larastan, Semgrep) and **DAST** (OWASP ZAP baseline scan) in the CI pipeline. The build fails on high findings

### 11.3 Data Protection
- TLS 1.2+ only (A+ on SSL Labs). TLS for app-to-MySQL and app-to-Redis connections
- Encryption at rest: MySQL disk/volume encryption, and **field-level encryption** (Laravel `encrypted` cast) for phone, GSTIN-linked addresses and gateway secrets
- **No card or UPI credentials ever touch our servers**: SabPaisa's hosted checkout handles them (PCI DSS **SAQ-A** scope; RBI card-storage rules satisfied)
- Secrets in a secrets manager or a locked-down `.env` (never in git). Keys are rotated and the procedure documented
- PII masked in logs and admin lists (e.g. `98******21`)
- **DPDP Act 2023 & DPDP Rules 2025:** a clear consent notice at signup and booking, consent records stored, purpose limitation, data-principal rights (access, correct, erase) through My Account, a grievance officer contact, a breach-notification procedure, and erasure that keeps the financial records the law requires (anonymised customer link)

### 11.4 Logging, Monitoring & Incident Response
- **Security event log:** logins, failed logins, MFA changes, role changes, permission denials, exports and admin actions, with user, IP, user agent and timestamp
- **CERT-In Directions (2022):** logs retained for **180 days within India**, servers synced to NTP (IST), cyber incidents reported to CERT-In **within 6 hours**. An incident response runbook is documented
- Centralised logs (e.g. Grafana Loki or ELK) with alerts for brute force, webhook signature failures, unusual refunds and admin logins from new locations
- Uptime and error monitoring (Sentry with PII scrubbing, UptimeRobot)

### 11.5 Infrastructure
- Hosting in an **Indian region** (data localisation, CERT-In). Hardened Ubuntu LTS, SSH keys only, no root login, firewall (only 80/443 public), fail2ban, automatic security patches
- MySQL not exposed publicly. Separate DB users for the app (least privilege) and for migrations
- Cloudflare WAF and DDoS protection in front of the site
- Separate **dev / staging / production** environments. Production data is never copied to dev unanonymised
- **Backups:** encrypted daily full plus binlog point-in-time recovery, kept off-site in India, with a quarterly restore test documented. Target RPO ≤ 15 min, RTO ≤ 4 h
- Shared cPanel hosting is **not recommended**, because it is unlikely to pass a security audit

### 11.6 Security Testing Before Go-Live
- Third-party **VAPT** (preferably by a CERT-In empanelled auditor). All critical and high findings fixed and retested before launch
- Annual re-test, and a re-test after major releases

---

## 12. Financial Controls (built for a financial audit)

### 12.1 Ledger & Record Integrity
- **Double-entry ledger:** every money event (payment, refund, gateway fee, GST, settlement) posts balanced journal lines (Σ debit = Σ credit, enforced in `LedgerService` and verified by a nightly check). Chart of accounts: Gateway Receivable, Bank, Charging Revenue, Booking Fee Revenue, Idle Fee Revenue, Output CGST/SGST/IGST, Gateway Fees, Customer Refunds Payable, Customer Advances
- **Immutable financial records:** payments, invoices, credit notes and ledger entries can **never be edited or deleted**, not even by Super Admin. This is enforced by model guards and by MySQL triggers that block `UPDATE`/`DELETE`. Corrections happen only through reversing entries or credit notes
- **Tamper-evident audit trail:** each audit-log and ledger row stores a SHA-256 hash of its content plus the previous row's hash (hash chain). A verification command (`php artisan audit:verify`) detects any tampering
- Every financial row records who, when, from where (IP) and why (mandatory reason for manual actions)
- Amounts are `DECIMAL`, with rounding rules documented (GST computed per line and rounded to 2 decimals; invoice total rounded per Rule 46 conventions)

### 12.2 GST Compliance
- Tax invoice contents as required by **Rule 46 of the CGST Rules**: supplier GSTIN, consecutive serial number unique per FY (max 16 characters), date, buyer details (GSTIN for B2B), SAC, taxable value, rate and amount of CGST/SGST/IGST, place of supply, and signature (digital)
- **Credit notes (Section 34)** for refunds and cancellations, linked to the original invoice. Never delete or edit the invoice itself
- **E-invoicing (IRN + signed QR) support** through a GSP/IRP API, toggled on when turnover crosses the threshold (currently ₹5 crore AATO), with a dynamic QR for B2C if it becomes applicable
- Separate invoice series per GSTIN (if stations sit under different state registrations)
- **Reports:** GSTR-1 ready export (B2B, B2CS, B2CL, CDNR, HSN/SAC summary), GSTR-3B summary, and a tax liability register by month and state

### 12.3 Approvals & Segregation of Duties (Maker–Checker)
| Action | Maker | Checker |
|---|---|---|
| Manual / partial refund | Operations or Finance | A different Finance user |
| Tariff or GST rate change | Operations Admin | Finance |
| Waiver / discount above a threshold | Operations | Finance |
| Write-off, manual journal entry | Finance | Finance Head / Super Admin (different person) |
| Bank / company / GSTIN detail change | Super Admin | Finance Head |

Automatic refunds under the published cancellation policy need no approval, but they are logged and reported.

### 12.4 Reconciliation
- **Daily automated three-way reconciliation:** bookings ↔ SabPaisa payments (status-enquiry API) ↔ SabPaisa settlement reports ↔ bank credits (CSV/statement import). SabPaisa's docs list no settlement API, so settlements are imported from their reports until one is confirmed
- An exceptions queue covers paid-but-not-confirmed, confirmed-but-not-paid, amount mismatches and missing settlements, and assigns an owner and resolution note to each
- Gateway fees and the GST on fees are recorded as expenses
- Webhook gaps are covered by a scheduled job that calls the SabPaisa status-enquiry API for any payment still pending after 15 minutes
- **Built (B8):** daily run + Run now; gateway status/amount re-enquiry, pending, paid-not-booked/duplicate, booked-unpaid, amount mismatch, refunds stuck/failed/over-refunded, missing invoice/credit note, wallet fee/recharge gaps → deduplicated exceptions queue with severity, one-click fixes, assign, resolve/ignore notes, auto-clear and reopen, staff email alert. **Still to do:** settlement-report import (SabPaisa payouts and UTRs) and bank-statement matching; gateway fees as expenses (with SGI Accounting)

### 12.5 Period Close
- **Month-end close and lock:** once Finance locks a period, nothing can post to it. Adjustments go into the current open period with a reference
- Close checklist inside the admin panel: reconciliation cleared, GST report generated, ledger balanced, trial balance exported
- Financial year runs April–March. The invoice series resets per FY

### 12.6 Reports for Auditors
- Trial balance, general ledger, P&L by station and month, revenue register, invoice register, credit note register, refund register, GST registers, reconciliation report, user access report, and audit-log export
- Every export is itself logged (who exported what, when). Exports are available as Excel/CSV and as PDF with a generated-on timestamp
- Voucher-level export (CSV/Excel) for the accounting team until SGI Accounting is switched on (section 13)

### 12.7 Data Retention
- Financial records (invoices, ledger, payments, audit trail) are kept for **at least 8 years** (covers CGST Act Section 36 and Companies Act Section 128), then archived, not deleted, unless legal approves otherwise
- Security logs are kept for at least 180 days (CERT-In). 1 year is recommended

---

## 13. External Accounting: SGI Accounting (ready now, switched on later)

**Decision:** at launch, Trans EV runs its **own built-in accounting** (sections 6, 7, 12). It is the books of record. The code is built **SGI-ready**: one setting switches on syncing to [SGI Accounting](https://sgiaccounting.tech/developers) when the business grows, with no rework.

### 13.1 What SGI Accounting's API offers (checked 24 Nov 2026, OpenAPI 3.1 at `/api/v1/openapi.json`)
- REST, base `https://sgiaccounting.tech/api/v1`. One key per company: `Authorization: Bearer ks_live_…`. Read keys allow `GET`; write keys allow create, edit and cancel. Keys are created in SGI → Developers (owners only; the plan must include API access)
- Endpoints we need: `POST /parties` (customers), `POST /items` (services with SAC and GST rate), `POST /sales` (tax invoice: SGI works out POS, CGST/SGST vs IGST, GSTR-1 section, e-invoice IRN and the ledger posting), `POST /credit-notes`, `POST /receipts`, `POST /payments`, `POST /journals`, `POST /contra`, `POST /projects`, `POST /sales/{vid}/cancel`, `GET /vouchers/{vid}/print`, `GET /gst/einvoice/{vid}`, `GET /reports/*`, `GET /books/*`, `GET /validate/gstin/{gstin}`
- Reliability features: an `Idempotency-Key` header (24 h replay) and an `external_ref` field (up to 64 characters, unique per kind; a repeat returns `409 duplicate_external_ref` with the existing id)
- Limits and errors: per-plan rate limit (`429` + `Retry-After`). Errors come as `{"detail": ...}`: `422` field errors, `409 consent_required` (entry crosses a legal limit and needs a recorded reason), `422 compliance_block` (law does not allow it)
- No outgoing webhooks are listed in the spec, so we read status back by polling

### 13.2 Built now (Phase 4, about +2 days)
- **`AccountingSync` interface** (`app/Services/Accounting/Contracts/AccountingSync.php`) with drivers:
  - `NullAccountingSync`: the **default**. It does nothing externally, and internal accounting carries on
  - `SgiAccountingSync`: the SGI driver, built in the later phase (13.4)
  - Selected by `config/accounting.php` → `ACCOUNTING_SYNC_DRIVER=null|sgi` (plus an admin toggle that only Super Admin + Finance can change, with maker–checker)
- **Transactional outbox** table `accounting_outbox` (id uuid, event, aggregate_type, aggregate_id, payload JSON, status `PENDING|SENT|FAILED|NEEDS_REVIEW|SKIPPED`, attempts, next_attempt_at, external_id, last_error, timestamps). Every money event writes an outbox row **in the same DB transaction** as the invoice/payment/refund, so nothing is lost even if SGI is down. While the driver is `null`, rows are stored as `SKIPPED` (kept for a later backfill)
- **`external_mappings`** table (local_type, local_id, provider, external_id, external_ref, synced_at) mapping our ids to SGI `party_id`, `item_id`, `project_id` and `voucher_id`
- **`ledger_account_map`** settings: our chart of accounts → SGI ledger codes (from `GET /books/accounts`), e.g. SabPaisa clearing → a bank/clearing ledger, Gateway Fees → an expense ledger
- **Stable external refs from day one** so a later backfill can never double-book: `user-{id}`, `station-{id}`, `svc-{tariffType}`, `inv-{invoiceNo}`, `rcpt-{paymentId}`, `cn-{creditNoteNo}`, `ref-{refundId}`, `fee-{settlementId}`, `stl-{settlementId}`
- Data we store now in an SGI-compatible shape: customer `state_code`, optional GSTIN/PAN, `is_individual`, line `hsn` (SAC 998714), `gst_rate`, `qty` (kWh), `rate`, `place_of_supply`, `pay_mode` (upi/card/online/…) and `ref_no` (SabPaisa transaction id)

### 13.3 Mapping (Trans EV → SGI)
| Trans EV | SGI endpoint | Key fields |
|---|---|---|
| Customer (user) | `POST /parties` | `name, phone, email, gstin, state_code, is_individual, kind:"customer", external_ref` |
| Charging service / booking fee / idle fee | `POST /items` | `name, hsn:"998714", gst_rate:18, is_service:true, unit:"KWH"` |
| Station | `POST /projects` | one project per station, so SGI shows revenue and profit per station; `project_id` is sent on all its vouchers |
| Tax invoice | `POST /sales` | `date, party_id, place_of_supply, lines[], number` (our Rule 46 number), `project_id, external_ref` |
| Payment received (SabPaisa) | `POST /receipts` | `date, amount, party_id, cash_account` (SabPaisa clearing ledger), `pay_mode, ref_no, external_ref` |
| Refund / cancellation | `POST /credit-notes` (`ref_voucher_id` = original sale) + `POST /payments` (money out) | |
| Gateway fees + GST on fees | `POST /payments` with `expense_account` | |
| SabPaisa settlement to bank | `POST /contra` (clearing → bank) | |
| Voided invoice | `POST /sales/{vid}/cancel` | |
| Manual journals (approved) | `POST /journals` | |

### 13.4 Switch-on phase (later, about 4–5 days)
1. Buy an SGI plan with API access. Create a **write** key (sync) and a **read** key (reconciliation). Store them encrypted, and never log them
2. Build and test `SgiAccountingSync` against an SGI test company: retries with exponential backoff, `Retry-After` respected, `Idempotency-Key` = outbox uuid, `409 duplicate_external_ref` treated as success (store the returned id)
3. `409 consent_required` and `422 compliance_block` → outbox `NEEDS_REVIEW` → Finance exceptions queue (consent is sent only after Finance approval, with a recorded reason)
4. Map ledgers in the admin panel and create the station projects and service items
5. **Cut-over at the start of a month or FY:** `php artisan accounting:backfill --from=YYYY-MM-DD` pushes opening balances and history (dry-run mode first)
6. **Source of truth after switch-on:** SGI becomes the books of record and the GST return source. Trans EV keeps its ledger as a sub-ledger for reconciliation. Invoice numbering stays in Trans EV (sent as `number`) so the series never breaks. E-invoice IRN comes from SGI (`GET /gst/einvoice/{vid}`) and is stamped on our PDF
7. **Daily reconciliation job:** our totals vs SGI (`/books/daybook`, `/reports/trial-balance`) per day and per station; differences go to the exceptions queue
8. Admin "Accounting Sync" screen: outbox status, retry, failed items, last sync time, SGI connection test

---

## 14. Audit Documentation Pack (delivered with the system)

1. System architecture and **data-flow diagrams** (booking, payment, refund, invoice, settlement)
2. **Controls matrix:** each control → OWASP ASVS / CERT-In / DPDP / GST rule reference → how it is implemented → evidence (test, screenshot, log)
3. Role and permission matrix, plus the user access review procedure (quarterly)
4. Chart of accounts and accounting policy (revenue recognition: at session completion; advance payments held as customer advances until then)
5. GST computation logic with worked examples
6. SOPs: refund handling, reconciliation, period close, station maintenance, key rotation, backup/restore, incident response
7. Change management: every production change goes through a Git pull request with review, CI checks and tagged releases. No direct production edits
8. VAPT report with the remediation record
9. Test reports (unit, feature, E2E coverage for financial flows)

---

## 15. Acceptance Checklist

**Functional**
- [ ] User sees the nearest stations by GPS on the map and in the list, sorted by distance
- [ ] Offline, Maintenance and closed stations and slots cannot be booked
- [ ] Admin can create, edit and (soft) delete stations and chargers, and set hours, holidays, status, tariff, GST and rules
- [ ] User must accept the current rules before paying. The acceptance is stored with its version
- [ ] Payment succeeds via SabPaisa. Booking is confirmed only after signature check and status enquiry
- [ ] Receipt and GST tax invoice PDFs are generated, emailed and downloadable, with the correct CGST/SGST/IGST split
- [ ] Cancellation refunds and credit notes work
- [ ] Footer shows working social icons
- [ ] Fully responsive, light/dark mode, Lighthouse ≥ 90

**Financial audit**
- [ ] Ledger balances for every transaction. The nightly check passes
- [ ] Financial records cannot be edited or deleted (tested at app and DB level)
- [ ] Invoice numbers are gapless and unique per FY under concurrent load
- [ ] Maker–checker enforced for refunds, tariff/GST changes and manual journals
- [ ] Daily reconciliation runs and the exceptions queue works
- [ ] Locked periods reject postings
- [ ] GSTR-1 export matches the invoice and credit-note registers

**Security audit**
- [ ] MFA enforced for all staff roles. RBAC matrix verified by tests
- [ ] Zero open critical/high VAPT findings
- [ ] Security headers in place (A grade on securityheaders.com and SSL Labs A+)
- [ ] Audit-log hash chain verifies. Logs retained 180+ days in India
- [ ] Backup restore tested and documented
- [ ] DPDP consent and data-rights flows working

---

## 16. Decisions Needed From You

1. ~~Payment gateway~~ **Decided:** SabPaisa. Need staging and production credentials (Merchant ID, API key, secret key, webhook secret) and to register the webhook URL with SabPaisa.
2. Map provider: free OpenStreetMap/MapLibre, or Google Maps (needs an API key and billing)?
3. Company legal details for invoices: legal name, GSTIN(s), PAN, registered address, and whether stations sit under one GSTIN or several state GSTINs.
4. Billing model: prepaid estimate only, or estimate plus a final true-up on actual kWh?
5. Social media handle URLs for the footer.
6. Hosting: a VPS in an Indian region with Forge/Ploi (recommended), or Laravel Cloud / AWS Mumbai?
7. ~~Accounting software~~ **Decided:** SGI Accounting, switched on later. Built SGI-ready now (section 13).
8. Is annual turnover expected to cross ₹5 crore (e-invoicing needed from day one)?
9. Will the VAPT be done by your own security team or by an external CERT-In empanelled auditor?

---

## 17. Extended Scope & Roadmap (SGI EV SRS)

The full SGI EV requirement set was compared against this plan in [SGI_EV_GAP_ANALYSIS.md](SGI_EV_GAP_ANALYSIS.md). Items that were missing from this plan are added here; the gap analysis holds the detail and acceptance criteria.

| Phase | Scope added from the SRS | Status |
|---|---|---|
| **A. Foundation** | ✅ Integrations & credentials centre · ✅ receipt access in admin · ✅ connectors as bookable units with live status · ✅ owner self-registration with KYC (/partner) · ✅ company and station approval · ✅ station photos, parking, slot lengths · ✅ Customers screen · ✅ Plans & features (Free/Premium, station limits, feature switches) · ✅ Audit log viewer · ✅ Roles & permissions (create/edit custom roles) · ✅ charger electrical specs and site power supply | Done |
| **B. Payments & billing** | ✅ SabPaisa · ✅ coupons & discounts · ✅ **GST tax invoices & credit notes** (auto-issued, gapless per GSTIN/FY, immutable, PDF + email, verify link, Billing & GST register, ZIP, GSTR-1 export) · ✅ **operator cancellation & no-show policies** (saved on each booking) · ✅ **peak/off-peak pricing and parking charges** (itemised on estimate, receipt and invoice) · ✅ **platform fees billed to operators** (commission per booking, negotiable at onboarding; monthly Trans EV invoices; ✅ **prepaid operator wallet**: bookings accepted only while the wallet covers each booking's commission, fee taken automatically on payment, returned on refunds; or monthly invoice billing) · daily reconciliation · DSC / e-invoice IRN (B5b) | Payments, coupons, invoices done; rest planned |
| **C. Station operations** | QR check-in · mobile Staff Console · walk-in bookings with cash/UPI · charging sessions (manual now, OCPP later) with final bill and true-up · no-show automation · reschedule · waiting list · live station board with estimated next availability · customer session tracking (WebSocket) | Planned |
| **D. Engagement** | ✅ **Support tickets** for every user type, resolved by assigned agents with SLA · notification centre (15 events × email/SMS/WhatsApp/push) · ratings & reviews | In progress |
| **E. API & apps** | REST API `/api/v1` (Sanctum) · mobile OTP login · Flutter Android/iOS apps · FCM · store release | Planned |
| **F. OCPP** | Central system (OCPP 1.6J first, 2.0.1 later) · remote start/stop · meter values → automatic billing · fault alerts · non-OCPP fallback · test on a real charger | Planned |
| **G. Reports** | Owner and platform reports with PDF/Excel · electricity cost and gross profit · franchise module (premium) | Planned |
| **H. Launch** | VAPT, payment/load/hardware/app testing, UAT, production deployment, SRS deliverables (ER diagram, API docs, deployment docs) | Planned |

**Where invoices and receipts are found:** customers in My bookings → booking; operators and platform admins in Admin → Bookings → booking (Invoice / Credit notes tab) and in the Admin → Invoices register. Until then, paid bookings show a **View / download receipt** button in Admin → Bookings and Admin → Payments.

**Where the customer list is:** Admin → Bookings & Payments → **Customers**. Platform super admins see every registered customer; each partner company sees only the customers who booked at its own stations (with bookings and spend for its stations only). Open a customer to see their profile, bookings and vehicles. Super admins can block or unblock a customer; company owners and super admins can export the list as CSV. Every block and export is audit-logged.

**Coupons & discounts:** Admin → Bookings & Payments → **Coupons & offers**. Super admins create platform coupons (Trans EV pays) or coupons for any operator; operators on the Premium plan create their own. Customers enter a code, or tap a public offer, in the booking summary; the discount appears on the booking page, receipt and admin bill, and GST is charged on the discounted value.

**Receipt & invoice contents:** see the table in SGI_EV_GAP_ANALYSIS.md ("Receipt & invoice contents"). Receipts now show the charging point (charge point ID, connector, electrical rating, energy meter), payment references (RRN/UTR, paid-at time) and the amount in words. Actual metered session data (start/stop readings, energy delivered, duration, idle fee) arrives with charging sessions (Phase C) and OCPP (Phase F); the formal GST tax invoice and credit notes are B5.

**No hard-coding principle:** business data and rules live in the database and are managed in admin (plans, roles/permissions, coupons, ticket topics and SLA, tariffs, rules, company and invoice details, integration keys). Business rules (Admin → Settings → Business rules), lists & options (Admin → Settings → Lists & options) and website pages (Admin → Content → Website pages, with version history) are database-driven. Website copy (homepage, About, Pricing, Safety, Contact: Admin → Content → Site content) and every email/SMS wording (Admin → Content → Message templates) are database-driven too. Only interface labels, workflow states, connector standards, plan feature keys and security allow-lists stay in code by design (A13).

**My bookings** (customer): tabs (Upcoming, Past, Cancelled, All) with counts, search, lifetime stats (sessions, kWh, spent, saved), and per-booking actions: pay, directions, GST invoice, receipt, get help.

**Roles & permissions principle:** every admin capability is a named permission, including platform work (partner approval, plans, integrations, roles, GST exports, blog, support tickets), so a super admin can create roles like *Content writer*, *Support agent* or *GST accountant* and assign work. Platform-only permissions take effect only for Trans EV staff; partner companies can never gain them.

**How Trans EV collects commission (wallet mode):** each operator recharges a prepaid Trans EV wallet (online or by bank transfer). When a customer books, the wallet must cover that booking's platform fee (the commission agreed at onboarding plus GST); when the customer pays, the fee is taken from the wallet automatically, and it is returned in proportion if the customer is refunded. When the wallet runs low the operator is emailed, and bookings pause until it is recharged. Operators on monthly invoice billing receive a platform-fee invoice instead.

**Credentials policy:** only the platform super admin manages platform-wide credentials (email, SMS, WhatsApp, push, maps, payment defaults) in Admin → Settings → Integrations. Each operator manages only its own SabPaisa keys on its company page. Secrets are encrypted at rest, never displayed after saving, and every change is audit-logged without the secret value.

---

## 18. Final close-out checklist (last step of the project)

When every phase in section 17 is complete, review these two screens so that no feature or permission is missed:

| Screen | What to check |
|---|---|
| **Plans & features** (Admin → Settings → Plans & features, `/admin/plans`) | Every module from Phases A–H has a feature switch, is on the right plan (Free / Premium / new plans), and the code respects it. Free plan matches SRS §22. Prices, descriptions and station limits are final. |
| **Roles & permissions** (Admin → Settings → Roles & permissions, `/admin/roles-matrix`) | Every admin screen and action has a permission with a clear label and group; platform-only permissions are flagged; built-in roles (Company owner, Operations admin, Finance, Station manager, Auditor) have exactly the right permissions; custom roles are reviewed. |

Then run the full test suite and review the audit log for anything done without a matching permission.

