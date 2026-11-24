# SGI EV SRS: Gap Analysis & Implementation Plan

**Compared:** "SGI EV – Complete EV Charging Management & Smart Slot Booking Platform" SRS v1.0 against the system built so far in `trans-ev/` (status as of 26 Nov 2026).
**Companion documents:** [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) (architecture, audit controls, SabPaisa, SGI Accounting).

**Legend:** ✅ Built and tested · 🟡 Partly built · 📋 Already planned (not built) · ❌ Missing (new work from the SRS)

---

## 1. Summary

| Area | Status | Notes |
|---|---|---|
| Multi-company SaaS (owners, data isolation) | ✅ | Companies, company owner role, staff, per-company scoping, suspension. Built and covered by tests. |
| Station & charger management | 🟡 | Stations, chargers, hours, holidays, tariffs and status work. Missing: **connectors as separate bookable units**, serial numbers, OCPP statuses, station images, parking. |
| Customer website: search, map, booking | ✅ | GPS search, map/list, filters, booking wizard, rules acceptance, double-booking protection. |
| Online payment (SabPaisa) | ✅ | Built 27 Nov 2026: per-company SabPaisa checkout, signed return + webhook, status enquiry, auto-refunds, policy-based cancellation refunds, receipts, admin payments screen. Local simulator for testing. Needs real staging credentials for a live test. |
| Invoices, GST, accounting | 📋 | GST engine built. Invoices, credit notes and ledger are planned (Phase 4). |
| Admin & owner dashboards | 🟡 | KPI boxes and 6 charts built. Missing: per-charger **live station board**, the SRS stat list (busy/reserved/faulty chargers, pending payments). |
| Station approval workflow, owner self-registration | ❌ | Today the platform admin onboards companies and stations go live immediately. |
| Walk-in, check-in/QR, start/stop session | ❌ | |
| No-show automation & fees, reschedule | ❌ | Status exists; no grace-period job, fee rules or reschedule. |
| Waiting list / queue | ❌ | |
| Live charging session, expected availability | ❌ | Depends on OCPP or manual session entry. |
| **OCPP hardware integration** | ❌ | Largest new module (central system server). |
| Notifications (email/SMS/WhatsApp/push) | ❌ | Only password-reset emails today. |
| Ratings, reviews, support tickets | 🟡 | Contact form + admin inbox exist. No reviews, ticket threads or owner replies. |
| Reports with PDF/Excel download | 🟡 | Dashboard charts only. |
| Offers & coupons, commission, franchise, plans | ❌ | |
| **Mobile apps (Android/iOS) + REST API** | ❌ | Website is mobile-responsive; no native apps or public API yet. |
| Mobile OTP login | ❌ | Email + password today. |
| Security & audit | ✅ / 📋 | RBAC, data isolation, audit logs, append-only DB triggers, rate limits, sanitising done. VAPT, backups and the OCPP security profile remain. |

Roughly **40% of the SRS scope is built**. The biggest remaining items are OCPP, the mobile apps with their API, payments/billing, and the operations modules (walk-in, no-show, waiting list, live sessions).

---

## 2. Decisions needed before building

| # | Decision | Options | Recommendation |
|---|---|---|---|
| D1 | **Brand name** | The SRS says "SGI EV"; the site is built as "Trans EV" (transevrecharge.com). | Confirm whether the product is **SGI EV**, **Trans EV**, or "Trans EV, powered by SGI EV". Renaming is cheap now (config + logo + seed text). |
| D2 | **Admin / owner panel technology** | The SRS asks for React/Next.js. The built panel is **Filament (Laravel)**. | **Keep Filament** for the admin and owner panels: it is built, tested, audit-friendly and mobile-responsive. Use React for the customer site (already). Revisit only if a fully custom owner UI is required. |
| D3 | **Maps** | SRS: Google Maps. Built: OpenFreeMap (free, no key). | Use **Google Maps for the mobile apps** (best on Android/iOS). Make the website map provider configurable, with Google as an option (needs a billing-enabled key). |
| D4 | **OCPP server approach** | (a) Write our own OCPP central system; (b) run open-source **SteVe** (OCPP 1.6J) or **CitrineOS** (2.0.1) and connect it to Laravel. | **(b) for speed and reliability**: SteVe for 1.6J first (most Indian chargers), talking to Laravel through a small adapter. Add 2.0.1 via CitrineOS when a 2.0.1 charger is available. A custom server is possible but slower and riskier. |
| D5 | **Wallet** | SRS lists wallet accounts. | **Defer.** A stored-value wallet is a prepaid payment instrument under RBI rules (licence needed). Use "refund to source" plus optional booking credits (non-cash) instead. |
| D6 | **Commission / platform fee** | SRS: commission management + owner settlements. Each owner is paid into their own SabPaisa account (decided). | Charge owners a **monthly SaaS fee and/or per-booking platform fee, invoiced by Tranzacta** (GST invoice to the owner). No money is held on owners' behalf. |
| D7 | **SMS / WhatsApp / push providers** | | **MSG91** (SMS + OTP, DLT-registered templates), the **WhatsApp Cloud API** (Meta) or a BSP such as Gupshup, **Firebase FCM** for push, and **Resend or AWS SES** for email. |
| D8 | **Station approval** | The SRS says stations appear only after admin approval. | Yes: add a review step (draft → submitted → approved/rejected) with KYC documents. |
| D9 | **Mobile app build** | In-house Flutter or an agency | Any. The REST API (section 4, M1) must come first. |

---

## 3. Detailed gap list (SRS section → status → what to build)

### 3.1 Platforms (SRS §2)
| Requirement | Status | Action |
|---|---|---|
| Super Admin web panel | ✅ | Filament `/admin` with platform super admin |
| Station Owner web panel | ✅ | Same panel, scoped to the owner's company |
| Customer Android / iOS apps | ❌ | Flutter apps (Module M2) |
| Customer web booking | ✅ | |
| Staff mobile-responsive panel | 🟡 | The admin panel is responsive. Add a simplified **Staff Console** (check-in, walk-in, start/stop) optimised for phones (Module O1) |

### 3.2 Roles (SRS §3)
| Role | Built | Missing |
|---|---|---|
| Super Admin | Owners/companies, all stations, users, live status, audit, blog, support inbox, suspension | Station approval, commission, offers, franchise, notification centre, software settings, refunds UI, global reports export |
| Station Owner | Multiple stations, chargers, AC/DC/kW, bookings, customer details, cancellation, staff, pricing, dashboard | Walk-in, reschedule, no-show management, charging history, report downloads, slot grid view |
| Station Manager / Staff | Status update, assigned-station scoping, bookings view | Check-in, QR scan, session start/stop, walk-in entry, payment status, support replies |
| Customer | Profile, vehicles, search, booking, history, cancel, contact | **OTP login**, QR, payment, reschedule, waiting list, session tracking, invoices, reviews, tickets |

### 3.3 Admin panel modules (SRS §4)
| # | Module | Status |
|---|---|---|
| 1 | Dashboard | 🟡: add busy/reserved/faulty chargers, total customers, today's sessions, pending payments, no-shows |
| 2 | Station Owners | ✅ (Companies) + ❌ self-registration & KYC |
| 3 | Charging Stations | ✅ + ❌ approval, images, parking |
| 4 | Chargers | 🟡: ❌ connectors table, serial no., OCPP identity |
| 5 | Customers | ✅ Admin → Bookings & Payments → **Customers** (A8a) |
| 6 | Bookings | ✅ (read-only register, staff cancel) + ❌ reschedule, refund |
| 7 | Live Charging Sessions | ❌ |
| 8 | Walk-in Bookings | ❌ |
| 9 | Waiting List | ❌ |
| 10 | No-Show Management | ❌ |
| 11 | Payment Management | 📋 Phase 3 |
| 12 | Refund Management | 📋 Phase 3 |
| 13 | Commission Management | ❌ (see D6) |
| 14 | Offers & Coupons | ❌ |
| 15 | Franchise Management | ❌ (future) |
| 16 | Staff Management | ✅ |
| 17 | Reports & Analytics | 🟡 charts; ❌ PDF/Excel reports |
| 18 | Notifications | ❌ |
| 19 | Complaints & Support | 🟡 inbox; ❌ tickets with threads |
| 20 | CMS Management | 🟡 blog; ❌ editable pages/FAQ/banners |
| 21 | App Settings | ❌ |
| 22 | API & OCPP Settings | ❌ |
| 23 | Subscription / Plan Settings | ❌ |
| 24 | Audit Logs | 🟡 recorded (activity log, status logs, rule acceptances); ❌ viewer screen |
| 25 | Roles & Permissions | 🟡 fixed role matrix; ❌ editable permissions screen |

### 3.4 Station & charger data (SRS §5)
| Requirement | Status | Action |
|---|---|---|
| Owner registration fields (name, business, mobile, GST optional, address, map location, bank details, documents, images, hours) | 🟡 | Add self-registration, KYC document upload (private storage) and station images |
| Admin approval before public | ❌ | `stations.approval_status` + review queue |
| Parking availability, amenities, holidays | 🟡 | Add parking fields |
| Charger ID, name, **serial number**, type, connector type, kW, **number of connectors**, rate, **OCPP connection status**, operational/maintenance status | 🟡 | New `connectors` table (one row per connector, with its own type, max kW and status); charger `serial_no`, `ocpp_identity`, `ocpp_protocol`, `last_heartbeat_at` |
| Statuses: Available, Reserved, Preparing, Charging, Finishing, Occupied, Faulted, Offline, Maintenance, **per connector** | ❌ | Connector live status (OCPP-aligned) kept separate from admin availability (online/offline/maintenance), plus a status history table |
| Live station dashboard (per charger: customer, vehicle, booking time, start, kWh, ETA, next availability) | ❌ | Module O3 |
| Expected available time (shown as an estimate, not guaranteed) | ❌ | Module O3 |

### 3.5 Customer app (SRS §6)
| Requirement | Status |
|---|---|
| Mobile OTP login | ❌ (Module M1) |
| Profile photo, city, vehicle type | ❌ small additions |
| Multiple vehicles | ✅ |
| Home: nearby, location, available/booked/busy chargers, next available time, AC/DC, distance, rate, rating | 🟡: ❌ busy/next-available, ratings |
| Map with directions, hours, connectors, price/kWh | ✅ (web) |
| Expected waiting time | ❌ |
| Station page: images, available/charging/reserved counts, next available, reviews | 🟡 |

### 3.6 Smart slot booking (SRS §7)
| Requirement | Status | Action |
|---|---|---|
| Search → station → charger type → slot → vehicle → duration/target → price → pay → confirm → QR | 🟡 | Built up to the estimate and slot hold. Add payment (Phase 3), **QR code** and **target-energy (kWh) option** |
| Statuses: Pending Payment, Confirmed, Upcoming, Checked In, Charging, Completed, Cancelled, No-Show, Expired, Refund Pending, Refunded | 🟡 | Have held/confirmed/checked_in/completed/cancelled/expired/no_show. Add `charging`; derive "upcoming"; track refund status on the refund record |
| Slot durations 15/30/45/60/custom | 🟡 | Supported via slot length (15-minute steps). Add custom values and per-station allowed list |
| One active reservation per **connector**; no double booking | 🟡 | Protected today **per charger**. Move to per connector (M-F1) |
| Slot released on payment failure | ✅ (hold expiry) / 📋 explicit release on SabPaisa FAILED |
| SMS / push confirmation to customer **and owner** | ❌ |

### 3.7 Operations (SRS §8–10, 12)
| Module | Status |
|---|---|
| No-show: reminders, "notify station", reschedule/cancel, grace + extra grace, automatic no-show, owner-set fixed/% fee, free-cancellation window, policy shown before booking | ❌ |
| Waiting list: join, queue number, ETA, notify on free charger, confirm window, pass to next | ❌ |
| Walk-in: entry, vehicle, charger, start/stop, cash/UPI/online, invoice, blocks online slots | ❌ |
| Live session: kWh, duration, cost, target, ETA, stop (customer); meter, revenue (owner); final bill + GST + invoice | ❌ |

### 3.8 OCPP (SRS §11)
All items ❌: central system, 1.6J and 2.0.1, BootNotification/Heartbeat/StatusNotification/Authorize/Start/Stop/TransactionEvent/MeterValues/Remote start-stop/Firmware/Diagnostics, live voltage/current/power/meter, faults, and a manual/API fallback for non-OCPP chargers.

### 3.9 Payments & billing (SRS §13, 16)
| Requirement | Status |
|---|---|
| UPI, cards, net-banking via gateway | ✅ SabPaisa hosted checkout |
| Wallet | ❌ deferred (D5) |
| Cash / manual UPI entry for walk-in | ❌ |
| Advance payment, final settlement (true-up), no-show fee, cancellation fee, refunds, history, reconciliation | 📋 / ❌ (no-show and cancellation fees new) |
| Owner settlement / commission | ❌ (D6) |
| Billing: energy × rate, **parking charges**, booking fee, tax, **discount**, other charges, all shown before payment | 🟡: energy, booking fee and GST done; parking and discount missing |
| Pricing: per kWh, AC/DC, **peak/normal hours**, parking, booking fee, **cancellation fee**, per station **and connector** | 🟡 |

### 3.10 Reports, notifications, ratings, franchise, plans (SRS §14, 15, 17, 18, 22)
| Requirement | Status |
|---|---|
| Owner reports (daily…yearly revenue, sessions, units, charger/station-wise, online vs walk-in, cancellations, no-show fees, pending, refunds, **electricity cost, gross profit**) with **PDF/Excel** | 🟡 charts only |
| 15 notification events over push/SMS/WhatsApp/email | ❌ |
| Station & charger ratings, feedback, complaints/tickets with owner reply, refund complaints, problem reports | ❌ (inbox only) |
| Franchise registration/approval, regional managers, franchise reports, owner settlements | ❌ (future) |
| Free plan + premium features switchable from admin | ❌ |

### 3.11 Database tables (SRS §19)
| SRS table | Current equivalent | Action |
|---|---|---|
| users, user_roles, permissions, user_permissions | users + spatie roles/permissions | ✅ |
| customer_profiles / station_owner_profiles / staff | users (+ company_id) / companies | 🟡 add customer profile fields; owner KYC table |
| vehicles | vehicles | ✅ |
| stations, station_operating_hours, station_amenities | stations, operating_hours, holiday_overrides, stations.amenities (JSON) | ✅ |
| station_images | stations.photos (unused) | ❌ table with media |
| chargers / **connectors** | chargers (connectors as JSON) | ❌ connectors table |
| charger_status_logs | status_logs | ✅ (extend to connectors) |
| charger_tariffs | tariffs (versioned) | ✅ + peak/parking/cancellation fields |
| charger_maintenance_logs | status_logs (maintenance entries) | 🟡 add a work-order style log |
| bookings, booking_slots | bookings (slot = start/end) | ✅ |
| booking_payments, payments, payment_transactions, refunds | — | 📋 Phase 3 |
| booking_cancellations, booking_reschedules, no_show_records, check_in_records | bookings columns | ❌ dedicated tables |
| waiting_lists | — | ❌ |
| charging_sessions, charging_meter_values, charging_transactions, connector_status_history | — | ❌ |
| charging_invoices | — | 📋 Phase 4 (invoices) |
| wallet_accounts, wallet_transactions | — | ❌ deferred (D5) |
| owner_settlements, commissions | — | ❌ |
| ocpp_* (6 tables) | — | ❌ |
| notifications, reviews, complaints, support_tickets, coupons, franchises, system_settings | contact_messages | ❌ |
| audit_logs | activity_log + status_logs + rule_acceptances | ✅ |

### 3.12 Technology stack (SRS §20) vs built
| SRS | Built / planned | Gap |
|---|---|---|
| Laravel + MySQL | Laravel 12 + MySQL 8 | ✅ |
| Admin/Owner panel React/Next.js | Filament 4 (see D2) | Decision |
| Flutter Android/iOS | — | ❌ |
| REST API | — (web uses Inertia) | ❌ `/api/v1` with Sanctum |
| WebSocket real-time | — | ❌ Laravel Reverb |
| OCPP 1.6J/2.0.1 | — | ❌ (D4) |
| Google Maps | OpenFreeMap | D3 |
| UPI payment gateway | SabPaisa (planned) | 📋 |
| Firebase FCM | — | ❌ |
| Redis | database queue/cache | ❌ switch to Redis + Horizon |
| Ubuntu VPS + Nginx, HTTPS | planned | 📋 |
| JWT/Sanctum/RBAC | sessions + RBAC | 🟡 add Sanctum tokens |

---

## 4. Implementation plan for the gaps

The SRS rule applies: **design first, then module by module, with testing and approval after each phase.** Durations assume one experienced full-stack developer, plus a Flutter developer for M2. They run in parallel where marked.

### Phase A: Foundation changes · ~2.5 weeks
| ID | Work |
|---|---|
| A1 ✅ | **Connectors**: `connectors` table (charger_id, connector_no, type, max_kw, live_status, admin_status). Migrate the existing JSON. Bookings move from per-charger to **per-connector**, with the lock on the connector row. Availability grid per connector. Charger `serial_no`, `ocpp_identity`, `ocpp_protocol`, `last_heartbeat_at`. |
| A2 ✅ | **Connector live status model** (OCPP-aligned): Available, Reserved, Preparing, Charging, SuspendedEV/EVSE, Finishing, Occupied, Faulted, Unavailable/Offline, plus admin Maintenance. `connector_status_history` (append-only). |
| A3 ✅ | **Owner self-registration & KYC**: public "Register your station" flow → company in `pending` status. Documents (GST certificate, PAN, address proof, bank proof) in private storage. Admin review queue: approve, reject with reason, request changes. |
| A4 ✅ | **Station approval**: `approval_status` (draft/submitted/approved/rejected). Only approved and published stations are public. Re-approval when legal or location fields change. |
| A5 ✅ | Station **images** (gallery, cover), **parking** (available, type, charges), allowed **slot durations** (15/30/45/60/custom). |
| A6 ✅ | **Plans & feature flags**: `plans` (Free, Premium), `plan_features`, company → plan. Gate premium modules from the admin. Free plan per SRS §22. |
| A7 ✅ | **Integrations & credentials centre** (platform super admin only): email (SMTP / Amazon SES / Resend, from-address, *send test email*), SMS & OTP (MSG91: auth key, sender ID, DLT template IDs), WhatsApp Cloud API (token, phone number ID, templates), push (Firebase FCM service account), maps (provider, Google Maps API key, map style), payments (default SabPaisa environment and base URLs, simulator switch for local), storage/backups. Secrets stored **encrypted**, never shown again after saving (masked "saved" state), every change written to the audit log without the secret value, and a *test connection* action per integration. Values override `.env` at runtime. Per-company SabPaisa keys stay on each company's page (owners manage their own). |
| A8a ✅ | Admin → Bookings & Payments → **Customers**: list with phone, vehicles, paid bookings, total spent, last booking, verified and blocked status; filters (has bookings, verified, blocked, joined in 30 days); customer page with profile, activity totals, bookings and vehicles; block/unblock with reason (platform super admin only, audit-logged); CSV export (company owners and super admin, audit-logged, formula-injection safe). **Scoping:** the platform sees every customer; a partner company sees only customers who booked at its stations, and counts and spend cover only its own bookings; station managers only their stations' customers. |
| A11 ✅ | **Dashboards for the new plan**: admin *Needs your attention* centre (partner applications, KYC, stations awaiting approval, stations without a state GSTIN, paid bookings without an invoice, refunds in progress/failed, payments not connected, customer queries, held slots); *Billing & GST this month* (invoiced, net GST payable, refunds, new/unique customers and coupon savings); partner *Go-live checklist* and plan card (station limit, premium features). Customer *My account*: GST invoices & credit notes, offers, savings. |
| A12 ✅ | **Every capability as a permission.** New permissions: `platform.all_companies`, `partners.review`, `plans.manage`, `integrations.manage`, `roles.manage`, `customers.block`, `invoices.export`, `tickets.view/reply/assign/manage` (plus existing blog, support, stations, bookings, payments, coupons, audit). Platform-only permissions (flagged in the database) only work for Trans EV staff. Permission labels, groups and the platform-only flag are stored in the `permissions` table. Super admins combine them into roles (e.g. *Content writer* = blog.*, *Support agent* = tickets.view + tickets.reply, *Partner reviewer* = partners.review + platform.all_companies) and assign them in Staff. |
| A13 ✅ | **No hard-coding: configuration lives in the database.** (1) **Business rules** (Admin → Settings → Business rules, permission `settings.manage`): slot hold, checkout extension, unpaid holds per customer, days ahead, minimum notice, longest booking, no-show window, charging efficiency and power utilisation for estimates, customer refund tiers, ticket auto-close and attachment limits, KYC file size, partner terms version, webhook tolerance. Stored in `system_settings` (`rule.*`), seeded once, audit-logged. (2) **Lists & options** (`lookups.manage`): KYC document types (required / asked at sign-up), amenities, parking types, contact-form topics, popular EV models. The partner sign-up form and approval checks follow the KYC list. (3) **Website pages** (`content.manage`): Terms, Privacy and Refund policy are database pages with a rich-text editor, placeholders filled from live data (company name, address, GSTIN, support contacts, hold time, no-show window, refund table built from the refund tiers), server-side sanitising, append-only version history with restore, and custom pages at `/p/{slug}`. **Still in code by design:** workflow states (booking, ticket, approval statuses), connector standards (CCS2, Type 2…), plan feature keys (they switch code paths on and off) and security allow-lists (file types). (4) **Site content** (Admin → Content → Site content, `content.manage`): every homepage block (hero, compatible vehicles, how it works, charger types, calculator, popular stations, network, why Trans EV, fleets & partners, FAQ, closing call to action, SEO title/description) and the About, Pricing, Safety and Contact pages, with live values in {braces} ({cities}, {max_kw}, {session_fee}, {gst_rate}, {idle_fee}…); the Pricing page now shows live tariff ranges and fees instead of fixed numbers. (5) **Message templates** (Admin → Content → Message templates): wording of every email (booking confirmed, GST invoice and credit note, partner and station decisions, all ticket emails, verify email, reset password) and SMS (with DLT template ID), with variables, preview and an on/off switch; customer-entered values are escaped so they cannot inject links. **Still in code by design:** interface labels and buttons (to be moved to translation files when Hindi/regional languages are added), workflow states, connector standards, plan feature keys, security allow-lists. |
| D2 ✅ | **Support tickets.** Anyone signed in raises tickets (My account → Support tickets, or *Get help* on a booking); staff and partners raise them from admin. Topics and priorities with SLA targets are database records (Admin → Support → Ticket topics, Priorities & SLA). Routing: operator topics go to the operator of the booked station, everything else to Trans EV. Threaded replies, internal notes (never shown to requesters), private attachments (JPG/PNG/PDF, 3 × 5 MB), assignment to eligible agents, statuses (open, in progress, waiting on requester, resolved, closed), first-reply and resolution SLA badges, overdue filter, rating after resolution, auto-close 7 days after resolution, email notifications, append-only messages. Access by permission: `tickets.view`, `tickets.reply`, `tickets.assign`, `tickets.manage`. |
| A8b ✅ | Admin → Settings → **Audit log** (append-only in MySQL; filters by record, event, person and date; partners see only their own records) and **Roles & permissions**: role cards, a permission matrix, and for super admins *New role*, *Edit* (tick permissions per area) and *Delete* (custom roles with no staff). Custom roles are company-wide staff roles and appear in Staff → role. Every change is audit-logged with granted and revoked permissions. Permissions themselves are defined in code, because each one is enforced by a policy. |
| A10 ✅ | **Charger electrical specification & site supply**: per charger: make/model, supply voltage, phases, DC output voltage range, max current, standard (IEC 61851 / IS 17017 / Bharat AC-001 / DC-001), energy-meter serial and accuracy class, firmware, commissioning date. Per station: electricity provider (DISCOM), consumer number, LT/HT supply, supply voltage, sanctioned load. Shown on the station page, admin booking page and receipt. |
| A9 ✅ | **Documents in admin (interim until B5):** on Bookings and Payments, *View / download receipt* for paid bookings, visible to the platform and to the operating company only. |

### Phase B: Payments, billing & fees (SabPaisa) · ~3 weeks
| ID | Work |
|---|---|
| B1 | SabPaisa integration per company (credentials already stored per company): payment session, hosted checkout, signed return URL, webhook (idempotent), status-enquiry fallback job. Booking confirmed only after server-side verification. |
| B2 | `payments`, `payment_transactions`, `refunds` tables; refund API; **Refund Pending → Refunded** tracking; failed-payment slot release. |
| B3 ✅ | **Cancellation & no-show policies** (Admin → Bookings & Payments → Cancellation policies, permission `cancellation.manage`). Each operator sets tiers (refund % by minutes before the start, plus an optional fixed cancellation fee taken off the refund, never charged on top), a no-show refund %, and a note for customers; a station can have its own policy. Resolution: station → operator → Trans EV standard policy (which replaced the platform-wide refund tiers rule and drives the Refund policy page table). The policy is shown on the station page and at checkout, **saved on each booking** with its version, and refunds always use the saved copy, so later changes never affect existing bookings. Staff can **mark a no-show** after the no-show window (Business rules) and the agreed no-show refund is paid. Operator cancellations and station outages are always full refunds. **Later (Phase C):** automatic no-shows from check-in data. |
| B4 ✅ | **Pricing v2.** Each tariff can have **peak / off-peak time bands** (name, days of the week, from–until, crossing midnight allowed, ₹/kWh) and **parking charges** (per booking and/or per hour of the slot, own SAC 996743). The estimate splits energy across the slot's bands by minutes and lists every charge (energy per band, booking fee, parking, coupon discount, GST); the wizard re-prices when a slot is picked and warns when prices vary by time of day. The itemised lines are saved on the booking and used on the booking page, receipt and **GST invoice** (one line per band, parking on its own SAC). The station page shows bands and parking per tariff. Per-charger tariffs (overrides) were already supported. Coupons: B6. |
| B5 ✅ | **GST tax invoices & credit notes.** Invoice issued automatically when a booking is paid (time of supply = payment); credit note when a refund succeeds (GST reversed pro rata, never more than the invoice). Gapless numbering per supplier GSTIN, per financial year (Apr–Mar IST), per document type: `TEV/2627/000001`, `TEVC/2627/00001` (≤16 characters, Rule 46(b)). Rule 46 particulars: supplier name/address/GSTIN/PAN/state, invoice no. and date, recipient name (and GSTIN for B2B), place of supply, reverse charge, SAC 998714, quantity (kWh) and rate, discount, taxable value, CGST/SGST or IGST with rates, total, amount in words, authorised-signatory block. Plus charge point, connector, electrical rating, meter, vehicle, payment reference and RRN. Immutable: supplier/recipient snapshot and SHA-256 fingerprint; MySQL refuses deletes and changes to amounts or numbers. PDF (dompdf) emailed to the customer; public **verify link** on each invoice. **Where:** customer → booking page (*GST invoice*, credit notes) and My account dashboard; admin → Bookings → booking (*GST documents*); Admin → **Billing & GST → Tax invoices** (filters by date, B2B/B2C, GSTIN, station, operator; totals; **ZIP of PDFs**; **GSTR-1 export**: b2b, b2cs net of credit notes, cdnr, hsn, docs CSVs) and **Credit notes**. Hourly `invoices:issue-missing` retries. A station without a GSTIN in its state gets no invoice and is flagged on the dashboard. **Later (B5b):** DSC/e-sign, B2C dynamic QR and e-invoice IRN for operators above the turnover limits, supplementary invoice for post-session true-up, tax adviser review of marketplace (e-commerce operator) treatment. |
| B6 ✅ | **Coupons & discounts** (Admin → Bookings & Payments → Coupons & offers): percentage (with optional cap) or flat amount; minimum order; valid stations; AC/DC only; start and end dates; total and per-customer limits; first booking only; public offer (listed on the booking page) or private code; activate/deactivate. **Funding:** platform coupons (Trans EV pays, valid at every operator) or operator coupons (that company only, Premium plan feature). **Tax:** the discount is shown on the receipt/invoice at the time of supply, so GST is charged on the discounted value (CGST Act s.15(3)(a)). At least ₹1 always stays payable. Limits are checked again under a row lock at checkout; cancelled and expired bookings free up the coupon. Booking stores coupon code, subtotal, discount and who funded it (for B7/B8 settlement). **Later:** auto-applied offers, referral codes, coupon usage report and export, platform-funded discount settlement to operators. |
| B7 ✅ | **Platform fees billed to operators** (Admin → Billing & GST → Platform fees, permission `platform_fees.manage`). Commission is collected **on every booking**: a % of the operator's net charging sales (excl. GST, reduced in proportion to refunds) and/or a fixed fee per paid booking, plus the plan's monthly subscription. Rates come from the plan (Plans & features) and can be **negotiated per operator at onboarding**: the *Approve* dialog records the agreed plan, commission %, per-booking fee and terms note with the date; they can be changed later on the company page (Trans EV staff with `plans.manage`). Drafts are generated on the 1st of each month (or on demand); issuing numbers them as Trans EV tax invoices (`TEVP/2627/00001`, GST 18% CGST+SGST or IGST by the operator's state, SAC from Business rules), locks the amounts and emails the PDF to the operator's owners. Discounts Trans EV funded through platform coupons are credited back as a separate settlement line (net payable = fees − reimbursement). Payments are recorded manually (amount + UTR) until B7b. Operators see their issued statements and the rates in force on their dashboard. |
| B7b ✅ | **Prepaid operator wallet** (chosen over split settlement): each operator is either on **wallet** billing (default for new partners, set in Business rules and chosen in the Approve dialog) or monthly **invoice** billing. In wallet mode a slot can be reserved only when the wallet's available balance (balance − fees held by unpaid bookings) covers that booking's platform fee (agreed commission % + per-booking fee + 18% GST); otherwise the customer is told the station isn't taking online bookings and the operator is emailed. The fee is debited when the customer pays, returned pro rata on refunds, and Trans EV coupon discounts are credited automatically. Operators recharge online (Billing & GST → Trans EV wallet, paid into Trans EV's own SabPaisa account, minimum in Business rules) and Trans EV staff can add bank-transfer funds or corrections. Append-only ledger, low-balance alerts (threshold per operator), dashboard alert and go-live step. Monthly statements for wallet operators are issued already paid; the plan subscription is debited from the wallet. **Tax adviser to confirm:** GST timing on wallet recharges (advance vs deposit) and on coupon reimbursements. **Optional later:** auto-recharge via UPI AutoPay. |
| B8 ✅ | **Reconciliation** (Admin → Billing & GST → Reconciliation; `reconciliation.manage` for Trans EV staff, `reconciliation.view` lets an operator see its own). Runs daily (time in Business rules → Reconciliation; also **Run now** and `php artisan reconciliation:run`). Checks: **gateway vs our records** (SabPaisa enquiry for the last N days: status and amount), payments still **pending**, **paid but not booked / duplicate** with no refund, **confirmed without payment**, **paid ≠ booking total**, refunds **stuck / failed / more than paid**, **GST invoice or credit note missing** after a grace period (names the cause, e.g. no GSTIN in the station's state), **wallet fee not taken** and **recharge not credited**. Each problem is one **exception** (deduplicated, counts runs seen) with severity, operator, booking, expected vs found; **one-click fix** where safe (re-enquire payment, poll refunds, issue invoice / credit note, take wallet fee, credit recharge), **assign** to a team member, **resolve / ignore** with a note (audited), bulk actions. Problems that go away **clear automatically**; one resolved by hand but still found **reopens**; ignored stays closed. Run history shows gateway checks and the previous day's collections and refunds. New critical/high exceptions email staff (template `reconciliation.alert`). Thresholds in Business rules. *Later:* SabPaisa settlement-report matching (payout UTRs) once their settlement API is enabled. |

### Receipt & invoice contents (researched)
What EV charging receipts and Indian GST invoices are expected to show, and where Trans EV stands. Sources: CGST Rule 46 (tax invoice particulars), Germany's Eichrecht / OCMF (signed start and stop meter data per session), OCPI CDR fields (location, EVSE, connector, total cost), and Indian payment references (RRN/UTR).

| Detail | Status |
|---|---|
| Operator legal name, address, GSTIN (station-state GSTIN) | ✅ |
| Customer name, contact, GSTIN (B2B), billing state | ✅ |
| Booking code, slot date and time | ✅ |
| **Charging point:** station name, code and address; charge point ID (OCPP identity), charger code, AC/DC and kW; connector number and type | ✅ (this round) |
| **Electrical rating:** supply voltage and phases, DC output range, max current, standard; charger make/model and serial | ✅ (this round) |
| **Energy meter:** meter serial and accuracy class | ✅ (this round) |
| Energy (kWh) × rate, booking fee, coupon discount, taxable value, CGST/SGST or IGST, total | ✅ |
| SAC 998714, place of supply | ✅ |
| Amount in words (Indian numbering) | ✅ (this round) |
| Payment mode, paid-at time, gateway transaction, bank reference, **RRN/UTR**, order ID | ✅ (this round) |
| Refunds with policy rule | ✅ |
| **Actual session data:** session/transaction ID, start and stop time, start and stop meter readings, energy delivered, duration, idle minutes and idle fee, true-up against the pre-paid estimate | ⏳ Phase C2/C3 (manual) and F (OCPP meter values) |
| **Signed meter data** (OCMF-style) for tamper-evident billing | ⏳ Phase F |
| **GST tax invoice** (separate from the receipt): "Tax Invoice" heading, FY-wise serial number per GSTIN (max 16 chars), invoice date, reverse-charge flag, authorised signature or digital signature, credit notes for refunds | ⏳ B5 |
| Dynamic QR code on B2C invoices / e-invoice IRN (only when the operator crosses the turnover limits) | ⏳ B5 (switch per company) |
| Masked card number / UPI VPA and authorisation code (if SabPaisa returns them) | ⏳ B8 |
| Vehicle registration on the invoice | ✅ receipt; ⏳ invoice (B5) |

### Phase C: Station operations · ~3 weeks
| ID | Work |
|---|---|
| C1 ✅ | **QR check-in.** The customer's booking page shows a **check-in pass**: a QR holding a signed token (HMAC, booking + expiry) valid a few minutes, refreshed automatically (screenshots stop working). **Staff scan** it with any phone camera → confirm page showing customer, phone, vehicle and plate, charger → **Check in** (only staff with `bookings.manage` for that station). **Charger sticker:** every charger has a random, unguessable QR key; Admin → Station → Chargers → **QR sticker** prints a 100 mm sticker; the customer scans it, signs in and taps **Check in & start** (wrong charger refused). **Check-in desk** (Bookings & Payments): today's arrivals, late flags, counts, and a box for a handheld scanner or typed booking code; **Check in** button on the admin booking page too. Window: from N minutes before the slot (Business rules) until the slot ends, paid bookings only; idempotent. Each check-in writes `check_in_records` (method, staff, time, minutes early/late, IP, device), sets the booking to *Charging* and the connector to *Preparing*, and is in the audit log. |
| C2 ✅ | **Staff Console** at `/staff` (mobile-first; link under Admin → Bookings & Payments). Station picker limited to the stations the user works at (station managers: only assigned ones). **Arrivals:** today's bookings with plate, vehicle, phone (tap to call), charger/connector, battery plan, late flags and counts; **Check in**, **Start charging** (optional meter reading; checks in first if needed), **Stop charging** (meter reading, or kWh shown on the charger when there is no meter; reason and note; refuses readings lower than the start or more energy than the charger could deliver), **No-show** after the grace period. **Chargers:** each connector's live status, running session and next booking; **Change status** (available / occupied / faulted / offline, with note; not while charging). Scan box for booking codes or links, plus in-browser camera scanning where the phone supports it. Auto-refreshes every 30 s. Sessions go to `charging_sessions` (who, when, meter start/stop, energy, source, stop reason; audited); stopping completes the booking and frees the connector (a fault stop marks it faulted). New permission `sessions.manage`. *Walk-ins and taking payment at the station come in C3; the final bill and true-up against the estimate in C4.* |
| C3 ✅ | **Walk-ins** from the Staff Console (**Walk-in** button; permission `walkins.manage`). Staff pick a free connector (shows its price and how long it is free before the next online booking), enter plate, make/model, driver name and mobile, optional email (invoice is emailed) and GSTIN, an optional meter reading, and confirm the driver was told the price and rules. Charging starts at once; the walk-in is a booking with source *walk-in* and no customer account, so the **connector is blocked for online slots** for the chosen time (refused if it would clash with a booking). When charging stops the **bill is made from the energy actually delivered** (tariff bands, session fee, parking for the real minutes, GST) and the connector is freed immediately. **Take payment:** *Cash*, *UPI to the station* (UTR required), or an **online link** (SabPaisa) shown as a QR the driver pays on their own phone; the console updates when it arrives and the driver gets a public receipt page with a signed invoice link. The **GST invoice** is issued as soon as it is paid. Offline payments are never sent to the gateway by reconciliation and can't be refunded online; unpaid walk-ins show a warning in the console and in reconciliation. Platform fees on walk-in sales are off by default (Business rules → *Charge platform fees on walk-in sales*); walk-in length capped in Business rules. |
| C4 ✅ | **Charging sessions & final bill.** `charging_sessions` (C2) plus `charging_meter_values`: start, interim (**Add reading** in the Staff Console: meter register or kWh, power, battery %) and stop readings, ready for OCPP to fill later. When a prepaid booking's session stops, the bill is **re-made with the energy actually delivered** (same tariff bands, session fee, parking for the booked slot, same coupon) and compared with the prepaid amount: **less** → the difference is **refunded automatically** (GST credit note; wallet platform-fee share returned); **more** → **absorbed by the operator** by default, or, with Business rules → *Collect the balance*, staff collect it at the station (cash / UPI / payment link) and a **GST debit note** is issued (own series, e.g. `TEVD/2627/00001`, included in GSTR-1 CDNR and the documents summary). Differences under a set amount are ignored. The original invoice and payment stay unchanged; final kWh, final bill, difference and outcome are stored on the booking and shown to the customer (*Final bill* card + email), in the console and in admin (*Final bill* section, **Retry final-bill refund**). Reconciliation flags final-bill refunds not made (one-click fix) and balances not collected after a day. |
| C5 ✅ | **No-show automation** (scheduled every minute). **Reminders** at the minutes set in Business rules (default 60, 15 and at the start time), each sent once; if the scheduler misses one, only the latest due reminder goes out. **Running late:** from an hour before the slot the customer taps *I'm running late*, picks an ETA (5–30 min) and can add a message; staff see *On the way · ETA* and the no-show time in the Staff Console, and the slot is held until the ETA, capped by *Extra grace when running late* (default 15 min). **Auto no-show** once the grace (plus any late extension) passes without check-in: refund per the no-show policy agreed at checkout, connector released for others, customer emailed, booking page explains what was refunded and kept. Every no-show (auto or staff) writes `no_show_records` (method, who, grace given, late notice and ETA, refund %, paid / refunded / kept, refund link). Can be switched off (Business rules → *Mark no-shows automatically*). Email wording in Message templates (reminder, starting now, no-show). *Reschedule is C6.* |
| C6 | **Reschedule** (customer and owner) within policy, with history in `booking_reschedules`. |
| C7 | **Waiting list**: join when full, queue number, estimated wait, notify on a free connector, time-limited claim, then pass to the next customer. |
| C8 | **Live Station Board** (owner/admin): per-charger and per-connector status, customer, vehicle, booking time, start, kWh, ETA. **Expected next availability** from the booking end, target energy and live power, always labelled "estimated". |
| C9 | Customer **session tracking** page (kWh, duration, running cost, ETA, stop where allowed) via WebSocket (Laravel Reverb). |

### Phase D: Notifications, support & reviews · ~1.5 weeks
| ID | Work |
|---|---|
| D1 | Notification centre: the 15 SRS events × channels (email, SMS with DLT templates, WhatsApp templates, push), user preferences, admin template editor, delivery log. |
| D2 | **Support tickets**: customer and owner tickets with threads, categories (refund, charging problem, booking), owner reply, admin escalation, SLA timers. The contact form feeds tickets. |
| D3 | **Ratings & reviews** (station + charger), only after a completed session, with moderation and owner reply. Average rating shown in search and on the station page. |

### Phase E: API & mobile apps · ~6–8 weeks (Flutter developer, in parallel with C/D)
| ID | Work |
|---|---|
| M1 | **REST API `/api/v1`** (Laravel Sanctum tokens): auth with **mobile OTP** (MSG91), profile, vehicles, search, station, availability, estimate, booking, payment session, QR, sessions, invoices, reviews, tickets, waiting list, notifications. OpenAPI documentation. Rate limits. Separate API for the staff app if needed. |
| M2 | **Flutter apps** (Android + iOS): onboarding/OTP, Google Maps home, filters, station details, booking wizard, SabPaisa checkout (WebView/SDK), QR, live session, history, invoices, reviews, support, push (FCM). Premium UI per SRS §26.9. |
| M3 | OTP login on the website as well (passwordless option), keeping email + password for staff (with mandatory 2FA for staff, per the security plan). |
| M4 | Store submission (Play Store, App Store), crash reporting, analytics. |

### Phase F: OCPP hardware integration · ~4–6 weeks (+ hardware availability)
| ID | Work |
|---|---|
| F1 | **Central system** (D4): SteVe (OCPP 1.6J) or a custom WebSocket server, secured with WSS + Basic Auth / certificates (OCPP Security Profile 2/3). Charger registry linked to `chargers.ocpp_identity`. |
| F2 | Laravel ⇄ OCPP adapter over Redis/queue: BootNotification, Heartbeat, StatusNotification → connector live status; Authorize (booking QR / idTag); Start/StopTransaction and TransactionEvent → charging sessions; MeterValues → `charging_meter_values`; fault alerts. |
| F3 | Commands from the panel and app: RemoteStart/RemoteStop, Reset, UnlockConnector, ChangeAvailability; Firmware/Diagnostics where supported. `ocpp_commands`, `ocpp_messages`, `ocpp_logs`, `ocpp_fault_logs`. |
| F4 | **Automatic billing** from meter values → final bill → invoice → refund/collect the difference. |
| F5 | OCPP 2.0.1 support (CitrineOS or our own server) when a 2.0.1 charger is available. |
| F6 | **Non-OCPP fallback**: manual entry in the Staff Console (C2/C4) or a manufacturer-API connector per brand. |
| F7 | End-to-end test on **at least one real charger** (acceptance criterion), plus a simulator for CI. |

### Phase G: Reports & business tools · ~1.5 weeks
| ID | Work |
|---|---|
| G1 | Owner reports: daily/weekly/monthly/yearly revenue, sessions, kWh, charger-wise and station-wise, online vs walk-in, cancellations, no-show fees, pending, refunds. **Electricity cost** (owner enters tariff per station/month) → **gross profit estimate**. PDF + Excel export. |
| G2 | Admin global reports (all companies), GST reports, platform fee reports. |
| G3 | Franchise module (future, premium): franchise entity over several companies/stations, regional manager role, franchise reports and commissions. |

### Phase H: Launch & testing · ~2 weeks
Security testing/VAPT, payment testing (SabPaisa staging → production), load testing (target: thousands of stations, lakhs of customers), hardware testing, Android/iOS testing, UAT with owners, production deployment (Ubuntu VPS, Nginx, Redis, Horizon, Reverb, backups, monitoring), deliverables per SRS §24 (ER diagram, API docs, deployment docs, Git access, source ownership).

### Suggested order & timeline
```
Weeks  1–2   A  Foundation (connectors, approval, KYC, plans)
Weeks  3–5   B  Payments, fees, invoices, coupons, platform fees
Weeks  6–8   C  Operations (QR, walk-in, sessions, no-show, waiting list, live board)
Weeks  9–10  D  Notifications, tickets, reviews        |  E  API + Flutter apps (weeks 4–12, parallel)
Weeks 11–16  F  OCPP (needs a real charger by week 12)
Weeks 15–16  G  Reports & exports
Weeks 17–18  H  Testing, VAPT, launch
```
**Total: about 18 weeks** for the full SRS, with one backend developer and one Flutter developer working in parallel. A smaller **launch scope** (A + B + C1–C5 + D1 + G1 on the website, without OCPP or apps) is possible in **about 9 weeks**, adding the apps and OCPP after launch.

---

## 5. SRS acceptance criteria (§25): current state

| Criterion | Now | Delivered in |
|---|---|---|
| Owner registration & station approval | 🟡 admin onboarding only | A3, A4 |
| Owner adds multiple stations | ✅ | |
| Multiple chargers per station | ✅ | |
| Customer sees nearby stations | ✅ | |
| Customer books available slots | ✅ (until payment) | B1 |
| No double booking per connector | ✅ per connector (row lock) | A1 |
| Booking payment works | ✅ (simulator; live test pending SabPaisa staging keys) | B1 |
| Cancellation & refund | ✅ policy refunds + operator full refunds | B2 |
| No-show policy | ❌ | B3, C5 |
| Waiting list | ❌ | C7 |
| Walk-in and online together | ❌ | C3 |
| Charger status updates correctly | 🟡 manual | A2, F2 |
| Real OCPP hardware data | ❌ | F |
| Charging session & billing | ❌ | C4, F4 |
| Owner revenue reports | 🟡 dashboard | G1 |
| Admin manages all stations | ✅ | |
| Correct info in app and owner panel | 🟡 web only | E |

---

## Final close-out (do these last, after every phase is complete)

These two screens must be reviewed at the very end so that nothing built during the project is missed:

1. **Plans & features (Admin → Settings → Plans & features).** Make sure every module built in Phases A–H appears as a feature switch, is assigned to the right plan (Free or Premium, or a new plan), and that the code checks the switch. Keep the Free plan in line with SRS §22. Update the plan descriptions, prices and station limits, and re-check the partner go-live checklist and plan card on the dashboard.
2. **Roles & permissions (Admin → Settings → Roles & permissions).** Make sure every admin screen and action has a permission, that each permission has a clear label and group, that platform-only permissions are flagged, and that the built-in roles (Company owner, Operations admin, Finance, Station manager, Auditor) have the right permissions. Re-check custom roles created during the project.
3. Run the full test suite, then review the audit log for any capability that was used but is not covered by a permission.

