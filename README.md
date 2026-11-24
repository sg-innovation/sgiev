# Trans EV — EV charging marketplace

Trans EV (transevrecharge.com) is a multi-company EV charging marketplace. Drivers find a station, book a charger slot and pay online. Charging operators (partner companies) run their stations, prices, staff and payouts from an admin panel. Trans EV approves partners, sets plans and platform fees, and keeps every money movement reconciled and audit-ready.

| Folder | What it holds |
|---|---|
| [`trans-ev/`](trans-ev) | The application (Laravel 12 + Inertia/React + Filament admin) |
| [`IMPLEMENTATION_PLAN.md`](IMPLEMENTATION_PLAN.md) | Architecture, data model, finance/GST rules, roles, roadmap and the final close-out checklist |
| [`SGI_EV_GAP_ANALYSIS.md`](SGI_EV_GAP_ANALYSIS.md) | Phase-by-phase plan (A–H) with ✅ status for each delivered item |
| [`brand/`](brand) | Logos (SVG) |

## Stack

- **Backend:** PHP 8.4, Laravel 12, MySQL 8
- **Customer site:** Inertia + React 19 + TypeScript + Tailwind CSS v4
- **Admin panel:** Filament 4 (`/admin`) with a custom theme
- **Access control:** spatie/laravel-permission, with roles and permissions editable in the admin panel
- **Audit:** spatie/laravel-activitylog (append-only)
- **Payments:** SabPaisa (per-operator keys, encrypted). A built-in simulator is used in local development and tests.
- **GST documents:** barryvdh/laravel-dompdf
- **Accounting:** SGI Accounting integration is planned.

## What's built

**Customers**
- Station search and network map
- Slot booking with live availability and price estimate (peak/off-peak bands, parking)
- Coupons
- Online payment
- Receipts, GST invoices and credit notes
- Cancellation per the policy shown at booking, with automatic refunds
- My bookings
- Support tickets

**Operators**
- Self-service onboarding with KYC and approval
- Stations and chargers, including electrical specs, photos, parking and opening hours
- Tariffs, station rules and cancellation / no-show policies
- Coupons and staff roles
- Bookings, customers and refunds
- Invoice registers with ZIP and GSTR-1 exports
- Prepaid **Trans EV wallet** for platform fees
- Dashboards

**Trans EV platform**
- Partner review, plans and feature gating
- Commission and per-booking fees negotiated per operator, billed by wallet or monthly invoice
- Platform-fee invoices
- Roles & permissions matrix (custom roles too)
- Database-driven content and configuration: business rules, lists, website pages with version history, site content and email templates. No hard-coding.
- Audit log
- **Daily reconciliation** with an exceptions queue (B8)

See `SGI_EV_GAP_ANALYSIS.md` for the full list and what comes next (Phase C: QR check-in, staff console, walk-ins, charging sessions…).

## Running locally

Requirements: PHP 8.4 (with `bcmath`, `intl`, `pdo_mysql`, `gd`, `zip`), Composer, Node 22, MySQL 8.

```bash
cd trans-ev
composer install
npm install
cp .env.example .env
php artisan key:generate
```

1. Edit `.env` to set your database credentials and a `SEED_DEMO_PASSWORD` for the demo users.
2. Create the databases `trans_ev` (app) and `trans_ev_test` (tests).
3. Set up and run the app:

```bash
php artisan migrate --seed
npm run build
composer run dev
```

`composer run dev` runs the web server, queue, logs and Vite together.

- Site: http://localhost:8000
- Admin: http://localhost:8000/admin

Seeded demo users (password = `SEED_DEMO_PASSWORD` from `.env`):

- `admin@transevrecharge.test` (super admin)
- `owner@` / `ops@` / `finance@` / `manager@` / `auditor@transevrecharge.test` (Trans EV staff)
- `owner@greengrid.test` (a demo partner operator)

In local development, payments go to the SabPaisa simulator, so no real money moves.

### Scheduler

Production needs `php artisan schedule:run` every minute. That one entry runs these jobs:

- Release expired slot holds
- Payment status enquiry and refund polling
- Issue missing invoices
- Auto-close tickets
- Monthly platform-fee statements
- Daily **reconciliation**

To run reconciliation by hand:

```bash
php artisan reconciliation:run              # full run, including gateway enquiries
php artisan reconciliation:run --no-enquiry # database checks only
```

## Tests

```bash
cd trans-ev
php -d memory_limit=512M vendor/bin/phpunit
```

- The feature tests run against MySQL (`trans_ev_test`), because they rely on MySQL triggers. Invoices, credit notes, the wallet ledger and the audit log are append-only, and triggers enforce it.
- The workflows in `trans-ev/.github/` come from the starter kit and assume SQLite. They are not active from this repository root.

## Security notes

- Never commit `.env`, `.env.production` or `.env.backup`.
- Never commit uploaded files under `storage/app` (they include KYC documents).
- Secrets and bank details are stored encrypted. Gateway keys are entered in **Admin → Integrations** or per operator, never in code.
