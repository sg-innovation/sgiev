# SGI EV SRS: Gap Analysis & Implementation Plan

**Compared:** "SGI EV – Complete EV Charging Management & Smart Slot Booking Platform" SRS v1.0 against the system built in `trans-ev/` (status as of **25 Nov 2026**: Phases A–D, F and G complete, Phase E API and sign-in security complete (the Flutter apps are next), final close-out of plans and roles done).
**Companion documents:** [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) (architecture, audit controls, payments, SGI Accounting) · [README.md](README.md) (setup and what is built).

**Legend:** ✅ Built and tested · 🟡 Partly built · 📋 Planned (not built) · ❌ Not started · ⏸ Deferred by decision

---

## 1. Summary

| Area | Status | Notes |
|---|---|---|
| Multi-company SaaS (owners, data isolation) | ✅ | Companies, roles, staff, per-company scoping, suspension, plans and feature gating. |
| Owner self-registration, KYC, station approval | ✅ | Partner sign-up with KYC documents, company and station approval queues. |
| Station & charger management | ✅ | Connectors as bookable units, OCPP-style live status, electrical specs, photos, parking, hours, holidays, tariffs with peak bands and parking charges, maintenance work orders per charger. |
| Customer website: search, map, booking | ✅ | GPS search, map/list, ratings on cards, booking wizard, coupons, rules acceptance, waiting list, reschedule. Fully mobile-responsive. |
| Online payments | ✅ | Each operator chooses **SabPaisa or Razorpay** and enters its own keys. Hosted checkout, signed return, webhook + status enquiry, refunds, walk-in payment links, reconciliation. Live test needs the operator's staging/test keys. |
| Invoices, GST, platform fees | ✅ | GST invoices, credit and debit notes, GSTR-1 exports, platform-fee invoices, prepaid operator wallet. SGI Accounting sync still to do. |
| Admin & owner dashboards | ✅ | Action centre, KPIs, charts, live station board, check-in desk, staff console, menu search in every sidebar. |
| Check-in (QR), walk-ins, start/stop session | ✅ | Rotating QR passes, charger stickers, check-in desk, staff console, manual sessions with meter readings. |
| No-shows, reschedule, waiting list | ✅ | Reminders, running late, auto no-show with policy refunds, reschedule, waiting list with time-limited offers. |
| Live charging session, expected availability | ✅ | Customer live card and live board, from manual readings or OCPP meter values. |
| Notifications | ✅ | Email, SMS (**MSG91 or SMS Birds**), WhatsApp (**Meta Cloud API or SGI Connects**) and push (Firebase). Provider chosen per channel in Integrations. Live once keys and DLT / WhatsApp template IDs are entered. |
| Ratings, reviews, support tickets | ✅ | Reviews with moderation and owner replies. Support is one threaded system: every contact-form query becomes a ticket (guests get a private link, no account needed) and replies by email land in the thread. SLA, pause, warnings and escalation. |
| Reports with PDF/Excel download | ✅ | Business reports (Reports → Business reports): revenue, sessions, kWh, online vs walk-in, cancellations, no-shows, refunds, GST, electricity cost and gross-profit estimate by day/week/month/year, station, charger and operator; pending money; platform fees and wallets. PDF and Excel download. |
| Offers, commission, franchise, plans | ✅ | Coupons, commission, wallet, plans, and franchises (regional partners, regional managers, monthly commission payouts). |
| **OCPP hardware integration** | ✅ | Own OCPP central system (1.6J and 2.0.1): live status, start/stop, meter values, remote commands, automatic billing, faults → work orders. Tested end to end with the built-in charge point simulator; a run on a real charger is still to do (F7). |
| **Mobile apps (Android/iOS) + REST API** | 🟡 | REST API v1 ✅ (49 endpoints, Sanctum tokens, developer portal with live console). The Flutter apps are next (M2). |
| Mobile OTP login & two-step login | ✅ | SMS code login on the website and the API; authenticator-app two-step login for everyone (required for staff). |
| Security & audit | ✅ / 📋 | RBAC, every capability a permission, encrypted keys, append-only audit and documents, rate limits. VAPT, backups and the OCPP security profile remain (Phases H and F). |

See **Progress** at the end for the percentage complete and what is left.

---

## 2. Decisions

| # | Decision | Outcome | Status |
|---|---|---|---|
| D1 | **Brand name** (SRS says "SGI EV"; site built as "Trans EV", transevrecharge.com) | Built as **Trans EV**. Renaming is still cheap (site content, logo, seed text are in the database). | 🟡 confirm before launch |
| D2 | **Admin / owner panel technology** (SRS: React/Next.js) | **Filament 4 (Laravel)** for admin and owner panels; React (Inertia) for the customer site. | ✅ decided |
| D3 | **Maps** (SRS: Google Maps) | Website map provider is a setting: OpenFreeMap (free) or Google Maps (key in Integrations). Google Maps for the mobile apps. | ✅ decided |
| D4 | **OCPP server approach** | Built **our own OCPP central system in Laravel** (`php artisan ocpp:serve`, WebSocket, 1.6J and 2.0.1) instead of SteVe: no second application or adapter, and sessions, billing and work orders use the same code as the Staff Console. | ✅ decided and built |
| D5 | **Customer wallet** | Deferred: a stored-value customer wallet is an RBI prepaid instrument. Refunds go back to source. (The **operator** prepaid wallet for platform fees, B7b, is a different thing and is built.) | ⏸ deferred |
| D6 | **Commission / platform fee** | Each operator is paid into its own gateway account; Trans EV bills commission % + per-booking fee + plan subscription, by prepaid wallet or monthly invoice (B7, B7b). | ✅ decided and built |
| D7 | **Message providers** | Email: SMTP / Amazon SES / Resend. SMS: **MSG91 or SMS Birds**. WhatsApp: **Meta Cloud API or SGI Connects**. Push: Firebase FCM. The provider for each channel is chosen in Admin → Integrations & keys. | ✅ decided and built |
| D8 | **Station approval** | Draft → submitted → approved / rejected, with KYC documents. | ✅ built (A3, A4) |
| D9 | **Mobile app build** | In-house Flutter or an agency; the REST API (M1) comes first. | 📋 open until Phase E |
| D10 | **Payment gateway** | **SabPaisa or Razorpay, chosen per operator** on its company page (default for new operators in Integrations → Payments). Payments keep the gateway that took them for refunds and status checks. | ✅ decided and built |

---

## 3. Detailed gap list (SRS section → status now)

> Updated on 25 Nov 2026 to show the **current** state of each SRS requirement. The ID in brackets is where it was built (section 4).

### 3.1 Platforms (SRS §2)
| Requirement | Status | Where / what is left |
|---|---|---|
| Super Admin web panel | ✅ | Filament `/admin`, platform super admin |
| Station Owner web panel | ✅ | Same panel, scoped to the owner's company |
| Customer Android / iOS apps | ❌ | Flutter apps (M2) |
| Customer web booking | ✅ | |
| Staff mobile-responsive panel | ✅ | Staff Console `/staff`, mobile-first (C2) |

### 3.2 Roles (SRS §3)
| Role | Built | Still to do |
|---|---|---|
| Super Admin | Companies and owners, partner and station approval, all stations, customers (block/unblock), live board, bookings, payments, refunds, reconciliation, commission and platform fees, coupons, plans, notification centre and templates, integrations, business rules, CMS, tickets, reviews moderation, audit log, roles & permissions | — |
| Station Owner | Stations, chargers, connectors, pricing, staff, bookings, customers, cancellation policy, walk-ins, check-in, sessions, no-shows, reschedule, waiting list, reviews replies, tickets, wallet and platform-fee statements, dashboard, live board | — |
| Station Manager / Staff | Staff Console: check-in, QR scan, walk-ins, take payment (cash / UPI / link), start/stop session, meter readings, no-show, charger status; assigned-station scoping | Mobile staff app if wanted (E) |
| Customer | Profile with photo and city, vehicles, search, booking, payment, QR pass, reschedule, running late, waiting list, live session, final bill, invoices, reviews, tickets, notification preferences | **Mobile OTP login** (M3), mobile apps (M2) |

### 3.3 Admin panel modules (SRS §4)
| # | Module | Status |
|---|---|---|
| 1 | Dashboard | ✅ Action centre, KPIs, billing & GST, go-live checklist (A11) |
| 2 | Station Owners | ✅ Companies + self-registration & KYC (A3) |
| 3 | Charging Stations | ✅ Approval, images, parking, hours (A4, A5) |
| 4 | Chargers | ✅ Connectors, serial no., OCPP identity, electrical spec, maintenance work orders (A1, A10, X4) |
| 5 | Customers | ✅ (A8a) |
| 6 | Bookings | ✅ Register, cancel, refund, move booking (B2, C6) |
| 7 | Live Charging Sessions | ✅ Live station board, Staff Console (C8, C2) |
| 8 | Walk-in Bookings | ✅ (C3) |
| 9 | Waiting List | ✅ (C7) |
| 10 | No-Show Management | ✅ (B3, C5) |
| 11 | Payment Management | ✅ SabPaisa or Razorpay per operator (B1, D10) |
| 12 | Refund Management | ✅ (B2) |
| 13 | Commission Management | ✅ Platform fees, operator wallet (B7, B7b) |
| 14 | Offers & Coupons | ✅ (B6) |
| 15 | Franchise Management | ✅ Applications, approval, stations, regional managers, payouts (G3) |
| 16 | Staff Management | ✅ |
| 17 | Reports & Analytics | ✅ Business reports with PDF/Excel, electricity costs (G1, G2) |
| 18 | Notifications | ✅ Templates, preferences, delivery log, provider choice (D1) |
| 19 | Complaints & Support | ✅ Threaded tickets with SLA; every query a ticket; guest private links; email replies (D2, X3) |
| 20 | CMS Management | ✅ Blog, legal pages with versions, site content, FAQ (A13) |
| 21 | App Settings | ✅ Business rules, lists & options (A13) |
| 22 | API & OCPP Settings | 🟡 Integrations & keys ✅ (A7), OCPP ✅ (F); API keys with E |
| 23 | Subscription / Plan Settings | ✅ (A6); final review at close-out |
| 24 | Audit Logs | ✅ Viewer (A8b) |
| 25 | Roles & Permissions | ✅ Editable roles (A8b); final review at close-out |

### 3.4 Station & charger data (SRS §5)
| Requirement | Status |
|---|---|
| Owner registration fields (name, business, mobile, GST, address, map location, bank details, documents, images, hours) | ✅ (A3, A5) |
| Admin approval before public | ✅ (A4) |
| Parking, amenities, holidays | ✅ (A5) |
| Charger ID, name, serial number, type, connectors, kW, rate, operational/maintenance status | ✅ (A1, A10) |
| OCPP connection status (heartbeat) | 🟡 fields ready; filled by OCPP (F2) |
| Connector statuses: Available, Reserved, Preparing, Charging, Finishing, Occupied, Faulted, Offline, Maintenance | ✅ staff-updated with history (A2); automatic with OCPP (F2) |
| Live station dashboard (customer, vehicle, booking time, start, kWh, ETA, next availability) | ✅ (C8) |
| Expected available time (labelled as an estimate) | ✅ (C8, C7) |

### 3.5 Customer app (SRS §6)
| Requirement | Status |
|---|---|
| Mobile OTP login | ✅ (M1, M3) |
| Profile photo, city, vehicle type | ✅ (X4) |
| Multiple vehicles | ✅ |
| Home: nearby, available/busy chargers, next available time, AC/DC, distance, rate, rating | ✅ (web) |
| Map with directions, hours, connectors, price/kWh | ✅ (web) |
| Expected waiting time | ✅ waiting-list estimate (C7) |
| Station page: images, available/charging/reserved counts, next available, reviews | ✅ (A5, C8, D3) |
| Android / iOS apps | ❌ (M2) |

### 3.6 Smart slot booking (SRS §7)
| Requirement | Status |
|---|---|
| Search → station → charger type → slot → vehicle → target → price → pay → confirm → QR | ✅ (B1, C1); target set as battery % from–to |
| Statuses: Pending Payment, Confirmed, Upcoming, Checked In, Charging, Completed, Cancelled, No-Show, Expired, Refund Pending, Refunded | ✅ held / confirmed / checked in / completed / cancelled / expired / no-show on the booking; *charging* comes from the running session; refund status on the refund record |
| Slot durations 15/30/45/60/custom | ✅ per-station allowed list (A5) |
| One active reservation per connector; no double booking | ✅ row lock per connector (A1) |
| Slot released on payment failure | ✅ |
| Confirmation to customer **and owner** (SMS / push) | ✅ customer (D1) and station staff: “New booking at your station” on email / SMS / WhatsApp / push (X4) |

### 3.7 Operations (SRS §8–10, 12)
| Module | Status |
|---|---|
| No-show: reminders, running late, reschedule/cancel, grace + extra grace, automatic no-show, owner-set refund %/fee, policy shown before booking | ✅ (B3, C5, C6) |
| Waiting list: join, queue number, ETA, notify, confirm window, pass to next | ✅ (C7) |
| Walk-in: entry, vehicle, charger, start/stop, cash/UPI/online, invoice, blocks online slots | ✅ (C3) |
| Live session: kWh, duration, cost, target, ETA, stop request (customer); meter, revenue (owner); final bill + GST + invoice | ✅ manual readings (C4, C8, C9); automatic with OCPP (F) |

### 3.8 OCPP (SRS §11)
| Requirement | Status |
|---|---|
| Central system, OCPP 1.6J and 2.0.1 | ✅ (F1, F5) |
| BootNotification, Heartbeat, StatusNotification, Authorize, Start/Stop/TransactionEvent, MeterValues | ✅ (F2) |
| Remote start/stop, reset, unlock, firmware, diagnostics | ✅ (F3) |
| Live voltage/current/power/meter, fault alerts | ✅ (F2); faults open work orders |
| Test on a real charger | 🟡 simulator ✅; real charger pending (F7) |
| Manual / API fallback for non-OCPP chargers | ✅ Staff Console (F6 = C2/C4) |

### 3.9 Payments & billing (SRS §13, 16)
| Requirement | Status |
|---|---|
| UPI, cards, net banking via gateway | ✅ SabPaisa or Razorpay (B1, D10) |
| Customer wallet | ⏸ deferred (D5) |
| Cash / manual UPI for walk-ins | ✅ (C3) |
| Advance payment, final settlement (true-up), no-show fee, cancellation fee, refunds, history, reconciliation | ✅ (B2, B3, B8, C4) |
| Owner settlement / commission | ✅ owners paid directly by the gateway; commission via platform fees (B7, B7b) |
| Billing: energy × rate, parking, booking fee, tax, discount, other charges, all shown before payment | ✅ (B4, B6) |
| Pricing: per kWh, AC/DC, peak/normal hours, parking, booking fee, cancellation fee, per station and charger | ✅ (B3, B4) |

### 3.10 Reports, notifications, ratings, franchise, plans (SRS §14, 15, 17, 18, 22)
| Requirement | Status |
|---|---|
| Owner reports (daily…yearly revenue, sessions, units, charger/station-wise, online vs walk-in, cancellations, no-show fees, pending, refunds, electricity cost, gross profit) with PDF/Excel | ✅ (G1) |
| Notification events over push / SMS / WhatsApp / email | ✅ 25+ events, four channels, two providers each for SMS and WhatsApp (D1) |
| Station and charger ratings, feedback, tickets with owner reply, refund complaints | ✅ (D2, D3, X3) |
| Franchise registration/approval, regional managers, franchise reports | ✅ (G3) |
| Free plan + premium features switchable from admin | ✅ (A6); review at close-out |

### 3.11 Database tables (SRS §19)
| SRS table | Built as | Status |
|---|---|---|
| users, user_roles, permissions, user_permissions | users + spatie roles/permissions (labels, groups, platform-only flag) | ✅ |
| customer_profiles / station_owner_profiles / staff | users (+ company_id, notification prefs) / companies + KYC documents | ✅ |
| vehicles | vehicles | ✅ |
| stations, station_operating_hours, station_amenities, station_images | stations, operating_hours, holiday_overrides, amenities, photos | ✅ |
| chargers / connectors | chargers, connectors | ✅ |
| charger_status_logs, connector_status_history | status_logs, connector status history | ✅ |
| charger_tariffs | tariffs (versioned, time bands, parking) | ✅ |
| charger_maintenance_logs | charger_work_orders (plus status logs) | ✅ |
| bookings, booking_slots | bookings | ✅ |
| booking_payments, payments, payment_transactions, refunds | payments, payment_events, refunds | ✅ |
| booking_cancellations, booking_reschedules, no_show_records, check_in_records | booking columns + booking_reschedules, no_show_records, check_in_records | ✅ |
| waiting_lists | waitlist_entries | ✅ |
| charging_sessions, charging_meter_values, charging_transactions | charging_sessions, charging_meter_values | ✅ |
| charging_invoices | invoices, credit notes / debit notes | ✅ |
| wallet_accounts, wallet_transactions | operator wallet + ledger (customer wallet deferred, D5) | ✅ / ⏸ |
| owner_settlements, commissions | platform-fee statements, wallet ledger | ✅ |
| ocpp_* (6 tables) | ocpp_messages, ocpp_commands, ocpp_faults + OCPP columns on chargers, charging_sessions, charging_meter_values | ✅ |
| notifications, reviews, complaints, support_tickets, coupons, franchises, system_settings | notification_deliveries, device_tokens, reviews, tickets, coupons, system_settings, franchises, franchise_payouts | ✅ |
| audit_logs | activity_log + status logs + rule acceptances + reconciliation runs | ✅ |

### 3.12 Technology stack (SRS §20)
| SRS | Built | Status |
|---|---|---|
| Laravel + MySQL | Laravel 12 + MySQL 8 | ✅ |
| Admin/Owner panel React/Next.js | Filament 4 (D2) | ✅ decided |
| Flutter Android/iOS | — | ❌ (M2) |
| REST API | `/api/v1` with Sanctum, OpenAPI file | ✅ (M1) |
| WebSocket real-time | polling every 5–30 s; payload ready for Laravel Reverb | 🟡 Reverb at launch (H) |
| OCPP 1.6J / 2.0.1 | own central system (Ratchet WebSocket server) | ✅ (F) |
| Google Maps | OpenFreeMap or Google, set in Integrations (D3) | ✅ |
| UPI payment gateway | SabPaisa or Razorpay per operator | ✅ |
| SMS / WhatsApp | MSG91 or SMS Birds; Meta Cloud API or SGI Connects | ✅ |
| Firebase FCM | FCM HTTP v1 sending, device tokens | ✅ (apps in E) |
| Redis | database queue and cache | 📋 Redis + Horizon at launch (H) |
| Ubuntu VPS + Nginx, HTTPS | demo server running | 🟡 production at launch (H) |
| JWT/Sanctum/RBAC | sessions, Sanctum tokens, RBAC, TOTP two-step login | ✅ |

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
| A7 ✅ | **Integrations & credentials centre** (platform super admin only): email (SMTP / Amazon SES / Resend, from-address, *send test email*), SMS & OTP (MSG91: auth key, sender ID, DLT template IDs), WhatsApp Cloud API (token, phone number ID, templates), push (Firebase FCM service account), maps (provider, Google Maps API key, map style), payments (default SabPaisa environment and base URLs, simulator switch for local), storage/backups. Secrets stored **encrypted**, never shown again after saving (masked "saved" state), every change written to the audit log without the secret value, and a *test connection* action per integration. Values override `.env` at runtime. Per-company gateway keys stay on each company's page (owners manage their own). **Later (X1):** provider choice per channel: SMS Birds as an alternative to MSG91, SGI Connects as an alternative to Meta, Razorpay as an alternative to SabPaisa, with *Test SMS* / *Test WhatsApp*. |
| A8a ✅ | Admin → Bookings & Payments → **Customers**: list with phone, vehicles, paid bookings, total spent, last booking, verified and blocked status; filters (has bookings, verified, blocked, joined in 30 days); customer page with profile, activity totals, bookings and vehicles; block/unblock with reason (platform super admin only, audit-logged); CSV export (company owners and super admin, audit-logged, formula-injection safe). **Scoping:** the platform sees every customer; a partner company sees only customers who booked at its stations, and counts and spend cover only its own bookings; station managers only their stations' customers. |
| A11 ✅ | **Dashboards for the new plan**: admin *Needs your attention* centre (partner applications, KYC, stations awaiting approval, stations without a state GSTIN, paid bookings without an invoice, refunds in progress/failed, payments not connected, customer queries, held slots); *Billing & GST this month* (invoiced, net GST payable, refunds, new/unique customers and coupon savings); partner *Go-live checklist* and plan card (station limit, premium features). Customer *My account*: GST invoices & credit notes, offers, savings. |
| A12 ✅ | **Every capability as a permission.** New permissions: `platform.all_companies`, `partners.review`, `plans.manage`, `integrations.manage`, `roles.manage`, `customers.block`, `invoices.export`, `tickets.view/reply/assign/manage` (plus existing blog, support, stations, bookings, payments, coupons, audit). Platform-only permissions (flagged in the database) only work for Trans EV staff. Permission labels, groups and the platform-only flag are stored in the `permissions` table. Super admins combine them into roles (e.g. *Content writer* = blog.*, *Support agent* = tickets.view + tickets.reply, *Partner reviewer* = partners.review + platform.all_companies) and assign them in Staff. |
| A13 ✅ | **No hard-coding: configuration lives in the database.** (1) **Business rules** (Admin → Settings → Business rules, permission `settings.manage`): slot hold, checkout extension, unpaid holds per customer, days ahead, minimum notice, longest booking, no-show window, charging efficiency and power utilisation for estimates, customer refund tiers, ticket auto-close and attachment limits, KYC file size, partner terms version, webhook tolerance. Stored in `system_settings` (`rule.*`), seeded once, audit-logged. (2) **Lists & options** (`lookups.manage`): KYC document types (required / asked at sign-up), amenities, parking types, contact-form topics, popular EV models. The partner sign-up form and approval checks follow the KYC list. (3) **Website pages** (`content.manage`): Terms, Privacy and Refund policy are database pages with a rich-text editor, placeholders filled from live data (company name, address, GSTIN, support contacts, hold time, no-show window, refund table built from the refund tiers), server-side sanitising, append-only version history with restore, and custom pages at `/p/{slug}`. **Still in code by design:** workflow states (booking, ticket, approval statuses), connector standards (CCS2, Type 2…), plan feature keys (they switch code paths on and off) and security allow-lists (file types). (4) **Site content** (Admin → Content → Site content, `content.manage`): every homepage block (hero, compatible vehicles, how it works, charger types, calculator, popular stations, network, why Trans EV, fleets & partners, FAQ, closing call to action, SEO title/description) and the About, Pricing, Safety and Contact pages, with live values in {braces} ({cities}, {max_kw}, {session_fee}, {gst_rate}, {idle_fee}…); the Pricing page now shows live tariff ranges and fees instead of fixed numbers. (5) **Message templates** (Admin → Content → Message templates): wording of every email (booking confirmed, GST invoice and credit note, partner and station decisions, all ticket emails, verify email, reset password) and SMS (with DLT template ID), with variables, preview and an on/off switch; customer-entered values are escaped so they cannot inject links. **Still in code by design:** interface labels and buttons (to be moved to translation files when Hindi/regional languages are added), workflow states, connector standards, plan feature keys, security allow-lists. |
| D2 ✅ | **Support tickets.** Anyone signed in raises tickets (My account → Support tickets, or *Get help* on a booking); staff and partners raise them from admin. Topics and priorities with SLA targets are database records (Admin → Support → Ticket topics, Priorities & SLA). Routing: operator topics go to the operator of the booked station, everything else to Trans EV. Threaded replies, internal notes (never shown to requesters), private attachments (JPG/PNG/PDF, 3 × 5 MB), assignment to eligible agents, statuses (open, in progress, waiting on requester, resolved, closed), first-reply and resolution SLA badges, overdue filter, rating after resolution, auto-close 7 days after resolution, email notifications, append-only messages. Access by permission: `tickets.view`, `tickets.reply`, `tickets.assign`, `tickets.manage`. |
| A8b ✅ | Admin → Settings → **Audit log** (append-only in MySQL; filters by record, event, person and date; partners see only their own records) and **Roles & permissions**: role cards, a permission matrix, and for super admins *New role*, *Edit* (tick permissions per area) and *Delete* (custom roles with no staff). Custom roles are company-wide staff roles and appear in Staff → role. Every change is audit-logged with granted and revoked permissions. Permissions themselves are defined in code, because each one is enforced by a policy. |
| A10 ✅ | **Charger electrical specification & site supply**: per charger: make/model, supply voltage, phases, DC output voltage range, max current, standard (IEC 61851 / IS 17017 / Bharat AC-001 / DC-001), energy-meter serial and accuracy class, firmware, commissioning date. Per station: electricity provider (DISCOM), consumer number, LT/HT supply, supply voltage, sanctioned load. Shown on the station page, admin booking page and receipt. |
| A9 ✅ | **Documents in admin (interim until B5):** on Bookings and Payments, *View / download receipt* for paid bookings, visible to the platform and to the operating company only. |

### Phase B: Payments, billing & fees · ~3 weeks
| ID | Work |
|---|---|
| B1 ✅ | SabPaisa integration per company (credentials already stored per company): payment session, hosted checkout, signed return URL, webhook (idempotent), status-enquiry fallback job. Booking confirmed only after server-side verification. **Razorpay** added later as an alternative per company (X1). |
| B2 ✅ | `payments`, `payment_transactions`, `refunds` tables; refund API; **Refund Pending → Refunded** tracking; failed-payment slot release. |
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
| **Charging point:** station name, code and address; charge point ID (OCPP identity), charger code, AC/DC and kW; connector number and type | ✅ |
| **Electrical rating:** supply voltage and phases, DC output range, max current, standard; charger make/model and serial | ✅ |
| **Energy meter:** meter serial and accuracy class | ✅ |
| Energy (kWh) × rate, booking fee, coupon discount, taxable value, CGST/SGST or IGST, total | ✅ |
| SAC 998714, place of supply | ✅ |
| Amount in words (Indian numbering) | ✅ |
| Payment mode, paid-at time, gateway transaction, bank reference, **RRN/UTR**, order ID | ✅ |
| Refunds with policy rule | ✅ |
| **Actual session data:** session ID, start and stop time, start and stop meter readings, energy delivered, duration, true-up against the pre-paid estimate | ✅ manual readings (C2–C4); automatic from OCPP meter values (F) |
| **Signed meter data** (OCMF-style) for tamper-evident billing | 📋 Phase F |
| **GST tax invoice** (separate from the receipt): "Tax Invoice" heading, FY-wise serial number per GSTIN (max 16 chars), invoice date, reverse-charge flag, authorised-signatory block, credit and debit notes | ✅ B5, C4 (digital signature 📋 B5b) |
| Dynamic QR code on B2C invoices / e-invoice IRN (only when the operator crosses the turnover limits) | 📋 B5b (switch per company) |
| Masked card number / UPI VPA (if the gateway returns them) | ✅ “VISA •••• 4242” / “UPI ra•••@okicici” on receipt, booking page and invoice (X4) |
| Vehicle registration on the invoice | ✅ receipt and invoice |

### Phase C: Station operations · ~3 weeks
| ID | Work |
|---|---|
| C1 ✅ | **QR check-in.** The customer's booking page shows a **check-in pass**: a QR holding a signed token (HMAC, booking + expiry) valid a few minutes, refreshed automatically (screenshots stop working). **Staff scan** it with any phone camera → confirm page showing customer, phone, vehicle and plate, charger → **Check in** (only staff with `bookings.manage` for that station). **Charger sticker:** every charger has a random, unguessable QR key; Admin → Station → Chargers → **QR sticker** prints a 100 mm sticker; the customer scans it, signs in and taps **Check in & start** (wrong charger refused). **Check-in desk** (Bookings & Payments): today's arrivals, late flags, counts, and a box for a handheld scanner or typed booking code; **Check in** button on the admin booking page too. Window: from N minutes before the slot (Business rules) until the slot ends, paid bookings only; idempotent. Each check-in writes `check_in_records` (method, staff, time, minutes early/late, IP, device), sets the booking to *Checked in* and the connector to *Preparing*, and is in the audit log. |
| C2 ✅ | **Staff Console** at `/staff` (mobile-first; link under Admin → Bookings & Payments). Station picker limited to the stations the user works at (station managers: only assigned ones). **Arrivals:** today's bookings with plate, vehicle, phone (tap to call), charger/connector, battery plan, late flags and counts; **Check in**, **Start charging** (optional meter reading; checks in first if needed), **Stop charging** (meter reading, or kWh shown on the charger when there is no meter; reason and note; refuses readings lower than the start or more energy than the charger could deliver), **No-show** after the grace period. **Chargers:** each connector's live status, running session and next booking; **Change status** (available / occupied / faulted / offline, with note; not while charging). Scan box for booking codes or links, plus in-browser camera scanning where the phone supports it. Auto-refreshes every 30 s. Sessions go to `charging_sessions` (who, when, meter start/stop, energy, source, stop reason; audited); stopping completes the booking and frees the connector (a fault stop marks it faulted). New permission `sessions.manage`. *Walk-ins and taking payment at the station come in C3; the final bill and true-up against the estimate in C4.* |
| C3 ✅ | **Walk-ins** from the Staff Console (**Walk-in** button; permission `walkins.manage`). Staff pick a free connector (shows its price and how long it is free before the next online booking), enter plate, make/model, driver name and mobile, optional email (invoice is emailed) and GSTIN, an optional meter reading, and confirm the driver was told the price and rules. Charging starts at once; the walk-in is a booking with source *walk-in* and no customer account, so the **connector is blocked for online slots** for the chosen time (refused if it would clash with a booking). When charging stops the **bill is made from the energy actually delivered** (tariff bands, session fee, parking for the real minutes, GST) and the connector is freed immediately. **Take payment:** *Cash*, *UPI to the station* (UTR required), or an **online link** (SabPaisa) shown as a QR the driver pays on their own phone; the console updates when it arrives and the driver gets a public receipt page with a signed invoice link. The **GST invoice** is issued as soon as it is paid. Offline payments are never sent to the gateway by reconciliation and can't be refunded online; unpaid walk-ins show a warning in the console and in reconciliation. Platform fees on walk-in sales are off by default (Business rules → *Charge platform fees on walk-in sales*); walk-in length capped in Business rules. |
| C4 ✅ | **Charging sessions & final bill.** `charging_sessions` (C2) plus `charging_meter_values`: start, interim (**Add reading** in the Staff Console: meter register or kWh, power, battery %) and stop readings, ready for OCPP to fill later. When a prepaid booking's session stops, the bill is **re-made with the energy actually delivered** (same tariff bands, session fee, parking for the booked slot, same coupon) and compared with the prepaid amount: **less** → the difference is **refunded automatically** (GST credit note; wallet platform-fee share returned); **more** → **absorbed by the operator** by default, or, with Business rules → *Collect the balance*, staff collect it at the station (cash / UPI / payment link) and a **GST debit note** is issued (own series, e.g. `TEVD/2627/00001`, included in GSTR-1 CDNR and the documents summary). Differences under a set amount are ignored. The original invoice and payment stay unchanged; final kWh, final bill, difference and outcome are stored on the booking and shown to the customer (*Final bill* card + email), in the console and in admin (*Final bill* section, **Retry final-bill refund**). Reconciliation flags final-bill refunds not made (one-click fix) and balances not collected after a day. |
| C5 ✅ | **No-show automation** (scheduled every minute). **Reminders** at the minutes set in Business rules (default 60, 15 and at the start time), each sent once; if the scheduler misses one, only the latest due reminder goes out. **Running late:** from an hour before the slot the customer taps *I'm running late*, picks an ETA (5–30 min) and can add a message; staff see *On the way · ETA* and the no-show time in the Staff Console, and the slot is held until the ETA, capped by *Extra grace when running late* (default 15 min). **Auto no-show** once the grace (plus any late extension) passes without check-in: refund per the no-show policy agreed at checkout, connector released for others, customer emailed, booking page explains what was refunded and kept. Every no-show (auto or staff) writes `no_show_records` (method, who, grace given, late notice and ETA, refund %, paid / refunded / kept, refund link). Can be switched off (Business rules → *Mark no-shows automatically*). Email wording in Message templates (reminder, starting now, no-show). *Reschedule is C6.* |
| C6 ✅ | **Reschedule.** *Customer* (booking page → **Change time**): pick a day and a free slot on any charger at the same station that suits their vehicle, same length; allowed up to N times per booking (default 2) and until a set time before the start (default 60 min), both in Business rules; a slot that would cost more than they paid can't be chosen (cancel and rebook instead), and anything cheaper is settled by the final bill (C4). *Operator* (admin booking → **Move booking**, needs `bookings.manage` for that station): any time before the start with a reason shown to the customer (e.g. charger under repair), no count limit. Availability ignores the booking itself; the move is re-checked under a lock. Reminders and running-late notices restart for the new time; payment and receipt stay the same. Every change is kept in `booking_reschedules` (from/to time and charger, customer or operator, who, reason), shown on the booking page, audited, and the customer is emailed the new time (template *Booking moved to a new time*). |
| C7 ✅ | **Waiting list.** From the booking page (*No time that suits you? Join the waiting list*) the customer picks a day, earliest and latest start, length and vehicle, accepts the station rules, and joins; refused if a suitable slot is already free (book it instead), max lists per customer in Business rules. **My account → Waiting lists** shows the place in line (per station, day and connector type), an *estimated* next-free time, and live offers. A matcher runs every minute: the first waiting customer whose window fits a newly free slot (cancellation, expiry, no-show, reschedule) is offered it as a **held booking** with N minutes to pay (default 10) and an email; paid → *booked*; not paid → the hold lapses, the entry is *passed on* and the next customer is offered. Entries expire when their window ends; customers can leave any time (an offered hold is released). Staff see every queue at Bookings & Payments → **Waiting list**. Reuses the normal hold, payment, wallet and availability checks, so a waiting-list booking is identical to any other. |
| C8 ✅ | **Live station board** (Admin → Network → Live station board; needs `stations.view` + `bookings.view`, station managers see only their stations). Refreshes every 15 s. Summary: available / charging / booked now / out of service, bookings, sessions and kWh today. Per charger and connector: live status and note, current customer, number plate, vehicle and slot (walk-ins marked, *not arrived yet* when late), session start, minutes, **energy so far vs target** with a progress bar (from the latest meter reading, or estimated from rated power × utilisation when there is none), power and battery %, **estimated finish** (remaining energy at the current power, never past the booked end), the **next booking**, and **next free ≈ time** (finish or booked end + station buffer; after the following booking when back-to-back). Every estimate is labelled *estimated*. |
| C9 ✅ | **Customer session tracking** on the booking page. Checked in → *Plug in your vehicle*; charging → a live card: kWh delivered vs planned with a progress bar, running timer, power, battery %, **cost so far** (the real final-bill calculation at the energy delivered, so it matches the bill) and *≈ refund if you stop now*, and an **estimated finish**; estimates are labelled until a meter reading arrives. When the session ends the page reloads into the final bill. **Stop charging**: chargers are manual today, so the button sends a stop request that staff see in the Staff Console (*Customer asked to stop*); OCPP remote stop plugs in here later. Updates by polling a JSON endpoint every 5 s; the same payload can be pushed over WebSockets (Laravel Reverb) when a Reverb server is run in production. |

### Phase D: Notifications, support & reviews · ~1.5 weeks
| ID | Work |
|---|---|
| D1 ✅ | **Notification centre.** Every message (25+ events: bookings, reminders, no-shows, reschedules, waiting-list offers, final bill, GST documents, tickets, partner/wallet/reconciliation alerts) is a template with up to four channels: **email**, **SMS** via MSG91 (DLT template / flow ID per message) or SMS Birds (X1), **WhatsApp** via Meta Cloud API or SGI Connects (X1) (approved template name; the body's variables fill {{1}}, {{2}}… in order) and **push** via Firebase Cloud Messaging HTTP v1 (service-account signed token, dead device tokens removed). A channel is used only when its template version is on, the customer hasn't switched it off (WhatsApp is opt-in), they have a mobile number or app device, and the provider keys are set in Integrations; in local/test environments sends are **simulated** and logged. Customers choose SMS / WhatsApp / app notifications at **Settings → Notifications** (email always on for receipts). Admin: **Message templates** edits every channel's wording, DLT IDs and WhatsApp template names, flags missing IDs, and **Send test**; **Settings → Notification log** (permission `notifications.log`) lists every send with channel, recipient, status (sent / simulated / skipped / failed), provider ID and error, plus provider status. `device_tokens` is ready for the mobile apps (Phase E). |
| D2 ✅ | **Support tickets** (built earlier: customer and partner tickets, threads, categories, operator/Trans EV routing, assignment, internal notes, attachments, auto-close) **plus SLA and contact form.** Each priority sets first-reply and resolution targets; the clock **pauses while waiting on the requester** (the deadline moves by the paused time). Every 5 minutes the assignee is **warned** before a deadline (Business rules → minutes), and a **breach** is marked, the ticket **escalated** (level shown on the ticket) and the support leads (`tickets.manage`, handling company and Trans EV) emailed. The ticket list shows SLA badges (on track / due soon / overdue / breached · L1 / paused), filters for breached and source, and **first reply / resolution on-time %** for the last 30 days; the Action Centre counts tickets past SLA. **Contact form → tickets:** signed-in customers get a ticket at once (category from the topic mapping set on each category); guests' messages are converted by staff with **Convert to ticket**, which links or creates the customer account and emails a set-password link. |
| D3 ✅ | **Ratings & reviews.** After a completed booking the customer gets a **Rate your charge** card (and an email/push a set time later): 1–5 stars for the station and the charger, quick tags (from Lists & options → Review tags) and an optional comment; editable within N days, one per booking. Star-only ratings publish at once; written reviews wait for Trans EV moderation (Business rules). Reviews show the first name and initial only. **Station page**: average, star breakdown, latest reviews with tags and the operator's public reply; **search cards and station header** show ★ average (count). Station and charger averages are recalculated from published reviews. Admin → Network → **Ratings & reviews**: operators see their stations' reviews and reply (`reviews.view`, `reviews.reply`; the customer is emailed), Trans EV publishes or hides with an internal reason (`reviews.moderate`, platform-only; pending count in the menu). Demo data includes sample reviews. |

### Additions during the project (on request, not in the SRS plan)
| ID | Work |
|---|---|
| X1 ✅ | **Provider choice on every channel.** *Payments:* each company picks **SabPaisa or Razorpay** on its company page (default for new companies in Integrations → Payments, which also lists every company's gateway and whether its keys are in place). Razorpay uses **Payment Links** (UPI, cards, net banking, wallets): link per payment with our transaction ID as reference, customer returned with a signed query, status always confirmed with the API, webhook `/webhooks/razorpay/{company}` (HMAC-SHA256, de-duplicated by event ID), refunds and refund status through the Refunds API; test or live is decided by the key (`rzp_test_` / `rzp_live_`). Each payment remembers its gateway, so refunds and status checks still work after a company switches. *SMS:* **MSG91 or SMS Birds** (HTTP API with username, API key, 6-letter sender, route, DLT template ID; configurable URL). *WhatsApp:* **Meta Cloud API or SGI Connects** (approved template by name or ID with comma-separated parameters; free text only when a 24-hour conversation is open; account check). Integrations has **Test SMS** and **Test WhatsApp** buttons; every send is in the Notification log with its provider. Keys encrypted, never shown again. |
| X3 ✅ | **Support as one threaded system.** Every contact-form query becomes a ticket at once: signed-in customers see it in My account, **guests get a ticket without an account** and an email with a **private signed link** to the conversation, where they reply, attach files, mark it solved and rate it (staff can **email a new private link**, which cancels old ones). **Replies by email:** every ticket email carries Reply-To `support+TKT-000123@…`; the mail provider's inbound webhook (`/webhooks/inbound-email/{secret}`, set in Integrations → Support email; Postmark, Mailgun, SendGrid or any JSON with from/to/subject/text) adds the reply to the right thread, from the requester or an agent who can work the ticket only, with quoted history and signatures removed and attachments kept; duplicates and strangers are ignored. The "reply from support" email now contains the reply text. Messages show *by email* in the thread. The old Contact queries inbox only appears while older messages still wait to be converted. |
| X4 ✅ | **Review gaps closed.** (1) **New booking alert** to the station's staff (owners, operations admins and the station's managers) when a customer books and pays, on email / SMS / WhatsApp / push per Message templates; on/off and a per-booking cap in Business rules, and each person can switch it off in their notification settings. (2) **Profile photo and city** for customers (photo re-encoded to a 256 px square JPEG, which strips location metadata); shown in the menu and in Admin → Customers. (3) **Masked payment method** stored and shown on the receipt, booking page and invoice: card network + last 4 digits, or the first two letters of the UPI ID (Razorpay card/UPI/net banking/wallet; SabPaisa when it returns them). (4) **Maintenance work orders** (Network → Maintenance; `maintenance.view` / `maintenance.manage`): fault, repair, preventive, inspection or calibration per charger or connector; priority, due time, assignee (emailed), vendor; optionally takes the charger **out of service** until the last such order is done or cancelled; start, hold, done (what was done, labour and parts cost, parts used), cancel; downtime measured; open count in the menu. |
| X5 ✅ | **Mobile layout fix**: decorative background glows made the public pages wider than the phone screen, pushing the menu button off screen; horizontal overflow is now clipped site-wide (checked at 375 px on home, stations, pricing, about, contact, franchise, partner, login, register, blog, safety) and the mobile menu panel scrolls when tall. |
| X2 ✅ | **Menu search in every sidebar:** admin panel (type to filter, `/` to focus, Enter opens the first match, Esc clears; collapsed groups open while searching) and the customer account sidebar. |

### Phase E: API & mobile apps · ~6–8 weeks (Flutter developer, in parallel with C/D)
| ID | Work |
|---|---|
| M1 ✅ | **REST API `/api/v1`** (Laravel Sanctum bearer tokens, JSON only, rate limits: 120/min per user, sign-in 6/min and 30/h per IP): **sign-in** by mobile OTP (sign up with name + email, never takes over another account's email) or email + password, two-step challenge for accounts with an authenticator app, logout; **account** (profile, photo, notification choices, FCM device tokens, vehicles); **catalogue** (config, station search/details/reviews, free slots, estimate — the website's own logic); **bookings** (reserve, pay → hosted checkout URL, payment status, cancel with policy refund, reschedule, running late, QR pass, live charging, stop, rate, GST invoice/credit-note PDFs via 30-minute signed links); **waiting list**; **support tickets** with attachments. 49 routes. Blocked accounts lose their tokens at once; tokens expire (Business rules). **Developer portal** `/developers`: Trans EV adds a developer in Admin → Settings → **API developers** with a **6-digit PIN** (hashed; set or generated, shown once; access can expire); the developer enters the PIN to see the full documentation (every endpoint, parameters, request and response examples), a **live console** that sends real requests and shows status, time, headers and the JSON response, cURL for each call, an **OpenAPI 3** download for Postman, and a **sandbox token** for their own test customer (Business rules can turn it off). Wrong PINs are limited per device and lock the portal for everyone after repeated failures. |
| M2 📋 | **Flutter apps** (Android + iOS) on top of the API: onboarding/OTP, Google Maps home, filters, station details, booking wizard, hosted checkout in a web view, QR pass, live session, history, invoices, reviews, support, push (FCM). Premium UI per SRS §26.9. |
| M3 ✅ | **Website: "Log in with mobile"** (SMS code; new numbers create an account) next to email + password. **Two-step login** with an authenticator app (Google / Microsoft Authenticator, Authy…) for every kind of user: Settings → Two-step login (QR code, confirm with a code, 8 one-time recovery codes, turn off with password), asked after password or mobile code, also on the API. **Staff must use it** in the admin panel (set-up forced at next login; Business rule can switch it off); the same secret works on the website and admin. Codes can't be replayed; secrets and recovery codes are encrypted; login codes are hashed, expire, have attempt limits, resend cooldown and an hourly cap, and never appear in the SMS log. A fixed test number/code can be set in Integrations for app-store reviewers. |
| M4 📋 | Store submission (Play Store, App Store), crash reporting, analytics. |

### Phase F: OCPP hardware integration · ~4–6 weeks (+ hardware availability)
| ID | Work |
|---|---|
| F1 ✅ | **Central system** (own, D4): `php artisan ocpp:serve` (Ratchet WebSocket, run under Supervisor, Nginx in front for **wss://**). Chargers connect to `<public URL>/<identity>` with sub-protocol `ocpp1.6` or `ocpp2.0.1`; **security profile 1/2**: HTTP Basic auth (identity + per-charger password, stored hashed, shown once). Network → **Charger control**: OCPP setup (identity, protocol, new password, server URL), online/offline, last seen, what the charger reported at boot (vendor, model, firmware, serial), connectors, open faults. Every frame is kept in `ocpp_messages` (OCPP log per charger). Security profile 3 (client certificates) can be added at Nginx. |
| F2 ✅ | **Messages handled**: BootNotification (interval from Business rules), Heartbeat, StatusNotification → connector live status (1.6 statuses and 2.0.1 connector statuses), Authorize (booking code as id tag), StartTransaction / StopTransaction (1.6) and TransactionEvent Started/Updated/Ended (2.0.1) → charging sessions, MeterValues → `charging_meter_values` (energy register Wh/kWh with 2.0.1 multipliers, power, SoC, voltage, current, signed meter data), DataTransfer, firmware/diagnostics/log status, NotifyEvent, SecurityEventNotification. Unknown actions get a proper CALLERROR. A charger silent for three heartbeat intervals (or whose socket closes) shows **offline** and its idle connectors Offline. |
| F3 ✅ | **Remote commands** (`ocpp.command`): Start charging (for a booking), Stop charging, Restart (soft/hard), Unlock connector, Change availability (charger or connector), Ask for status, Update firmware (URL), Upload diagnostics (URL), each sent in the charger's own protocol (RemoteStart/RequestStart, Reset Soft/Hard vs OnIdle/Immediate, GetDiagnostics vs GetLog…). Queued in `ocpp_commands`, sent by the server within a second, answer stored (accepted / rejected / failed / no answer after a minute). **Staff Console**: Start on an online OCPP charger also starts it remotely; Stop sends a remote stop and the bill waits for the charger's meter. **Customer** “Stop charging” sends the remote stop at once. |
| F4 ✅ | **Automatic billing**: the charger's StopTransaction / TransactionEvent Ended closes the session with energy = meter stop − meter start, then the same final bill as manual sessions (refund of the unused prepaid amount or balance due, GST credit/debit note, walk-in bill and invoice). Verified end to end: 27 kWh prepaid, 6 kWh delivered → ₹520.38 refunded automatically. |
| F5 ✅ | **OCPP 2.0.1** in the same server (TransactionEvent, RequestStart/StopTransaction, GetLog, EVSE ids); verified end to end with a 2.0.1 simulated charger and a remote start. |
| F6 ✅ | **Non-OCPP fallback**: manual entry in the Staff Console (C2/C4); chargers without an OCPP identity keep working that way. |
| F7 🟡 | **Simulator ✅**: `php artisan ocpp:simulate <identity> --password=… --protocol=1.6|2.0.1 --tag=<booking code> --kwh=6 --seconds=30 [--wait]` behaves like a charger (boot, heartbeats, statuses, authorize, start, meter values, stop; with `--wait` it obeys remote start/stop/reset/unlock). Automated tests cover both protocols, the WebSocket layer, commands, faults and billing. **Still to do:** one run on a real charger (acceptance criterion) once hardware is available; configure it with the identity, password and wss:// URL from Charger control. |

### Phase G: Reports & business tools · ~1.5 weeks
| ID | Work |
|---|---|
| G1 ✅ | **Business reports** (Admin → Reports → Business reports, permission `reports.view`; download `reports.export`). Any date range with quick picks (today, 7/30 days, this/last month, this/last financial year), grouped by **day, week, month or year**, for all stations or one. **Overview:** net collected (online vs at the station, refunds), net sales excl. GST and GST, gross profit (est.), bookings and walk-ins, energy and sessions, cancellations and no-shows with fees kept, a bar chart per period with hover figures, and a full table. **Stations** and **Chargers** tabs rank the same measures. **Pending money:** unpaid walk-ins, final-bill balances to collect, refunds in progress, final-bill refunds to retry. **Electricity costs** (Reports → Electricity costs, `reports.costs`): each station's ₹/kWh and fixed charges per month from the DISCOM bill. **Gross profit (est.)** = net sales − (kWh delivered × rate + fixed charges spread by day) − platform fees (excl. GST, from the Trans EV wallet). Figures come from the live tables: bookings by slot start, money by paid/refunded time, sales and GST from tax invoices, credit and debit notes. **Download PDF** (A4 landscape summary) and **Excel** (one sheet per section, numbers as numbers, formula-safe); downloads are audit-logged. Station managers see only their stations; operators only their company. |
| G2 ✅ | **Trans EV view of the same screen:** all operators or one, plus an **Operators** tab (every operator side by side), a **GST** tab (by supplier GSTIN, rate and B2B/B2C: invoices, CGST/SGST/IGST, credit and debit notes, net) and a **Platform fees** tab (statements with paid and outstanding, and each operator's wallet: fees charged, funds added, coupon credits, balance). Operators see the GST and platform-fee tabs for their own company. |
| G3 ✅ | **Franchises** (Admin → Network → Franchises; `franchises.manage`, Trans EV only). Public **Become a franchise partner** page (`/franchise`, linked in the footer) → application (pending) → Trans EV is emailed → **Approve** with the agreed commission % (applicant emailed), **Reject** with a reason, **Suspend / Reactivate**. A franchise covers a group of **stations** from any operator (a station belongs to at most one). **Regional managers** are Trans EV staff given the new *Regional manager* role: they see only their franchise’s stations across the admin (bookings, reviews, maintenance, live board, reports, read-only franchise page). **Franchise reports:** Business reports has a *Franchise* filter. **Commission payouts:** drafted on the 1st for the previous month (or *Draft payout*): net sales excl. GST of its stations × commission %, with a per-station breakdown; **Approve** → **Mark paid** with the UTR (franchise emailed); approved or paid months are never redrafted. All changes audit-logged. |

### Phase H: Launch & testing · ~2 weeks
Security testing/VAPT, payment testing (SabPaisa staging and Razorpay test keys → live), load testing (target: thousands of stations, lakhs of customers), hardware testing, Android/iOS testing, UAT with owners, production deployment (Ubuntu VPS, Nginx, Redis, Horizon, Reverb, backups, monitoring), deliverables per SRS §24 (ER diagram, API docs, deployment docs, Git access, source ownership).

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
**Actual progress (25 Nov 2026):** A, B, C and D are complete; G, E, F and H remain (see Progress).

**Total: about 18 weeks** for the full SRS, with one backend developer and one Flutter developer working in parallel. A smaller **launch scope** (A + B + C1–C5 + D1 + G1 on the website, without OCPP or apps) is possible in **about 9 weeks**, adding the apps and OCPP after launch.

---

---

## 5. SRS acceptance criteria (§25): current state

| Criterion | Now | Delivered in |
|---|---|---|
| Owner registration & station approval | ✅ | A3, A4 |
| Owner adds multiple stations | ✅ | |
| Multiple chargers per station | ✅ | A1 |
| Customer sees nearby stations | ✅ | |
| Customer books available slots | ✅ | B1 |
| No double booking per connector | ✅ row lock per connector | A1 |
| Booking payment works | ✅ simulator, SabPaisa and Razorpay tested against mocked APIs; live test needs an operator's staging / test keys | B1, D10, H |
| Cancellation & refund | ✅ | B2, B3 |
| No-show policy | ✅ | B3, C5 |
| Waiting list | ✅ | C7 |
| Walk-in and online together | ✅ | C3 |
| Charger status updates correctly | ✅ automatic from OCPP chargers; staff-updated for manual ones | A2, F2 |
| Real OCPP hardware data | 🟡 central system done and verified with the simulator; real charger test pending | F, F7 |
| Charging session & billing | ✅ manual readings and automatic from OCPP meter values | C4, F4 |
| Owner revenue reports | ✅ business reports with PDF/Excel | G1 |
| Admin manages all stations | ✅ | |
| Correct info in app and owner panel | 🟡 website and owner panel ✅; mobile apps pending | E |

---

## Progress (updated 25 Nov 2026)

| Phase | Items | Done | Estimated effort | Status |
|---|---|---|---|---|
| A Foundation | 12 | 12 | 2.5 weeks | ✅ complete |
| B Payments, billing & fees | 9 | 9 | 3 weeks | ✅ complete |
| C Station operations | 9 | 9 | 3 weeks | ✅ complete |
| D Notifications, support & reviews | 3 | 3 | 1.5 weeks | ✅ complete |
| E API & mobile apps | 4 | 2 (API + sign-in security; apps and store next) | 6–8 weeks | 🟡 API done, apps next |
| F OCPP hardware | 7 | 6 (F7 real-charger test pending) | 4–6 weeks | ✅ done except the real-charger test |
| G Reports & business tools | 3 | 3 | 1.5 weeks | ✅ complete |
| H Launch & testing | 1 | 0 | 2 weeks | ❌ not started |
| **Total** | **48** | **44** | **~25 weeks** | |

Added on request during the project and not counted above: provider choice for payments, SMS and WhatsApp (X1), menu search in every sidebar (X2) threaded support with guest links and email replies (X3) and the review gaps (X4).

**About 92% of the planned items are done (44 of 48), or about 80% of the estimated effort**; the remaining pieces are the Flutter apps and store submission (on top of the finished API), one real-charger test and the launch phase. The **website, admin and OCPP scope** (A, B, C, D, F and G) is **complete** apart from one test on a real charger; what remains for launch is Phase H and the go-live setup.

**Small gaps found in the review:** all closed (X4).

**What is left**
1. **E – Flutter apps (≈5–6 weeks, Flutter developer):** build the Android/iOS apps on the finished API (share the developer portal PIN), then store submission (M4).
2. **F7 – Real charger test (≈1–2 days with hardware):** connect one real charger (identity, password and wss:// URL from Charger control), run a booking end to end, check meter values and remote commands against the vendor's OCPP implementation.
3. **H – Launch (≈2 weeks):** run `ocpp:serve` under Supervisor behind Nginx (wss://), VAPT/security testing, gateway test → live (SabPaisa staging / Razorpay test keys), load test, Redis + Horizon, Reverb, hardware and app testing, owner UAT, backups and monitoring, production deployment.
4. **Go-live setup (no code):** each operator's gateway choice, keys and webhook; mail; support inbox with plus-addressing and the inbound webhook (Integrations → Support email); each station's electricity cost per month; SMS provider (MSG91 or SMS Birds) with DLT sender and template IDs; WhatsApp provider (Meta or SGI Connects) with approved template names; Firebase; real state GSTINs per station; confirm brand name (D1); SGI Accounting sync (planned).
5. **Final close-out** (below). The new modules (franchises, maintenance, reports) and the Regional manager role are part of that review.

---

## Final close-out (do these last, after every phase is complete)

**Done on 25 Nov 2026.** Results: plans now have 19 feature switches (Free 10, Premium 19). New switches: maintenance, waiting list, ratings & reviews (free) and OCPP charger integration (premium); the code now checks walk-ins, live dashboard, basic and advanced reports (exports, GST, charger-wise, electricity costs), booking alerts, waiting list, maintenance, OCPP (screen and charger connections) and joining a franchise; CRM, white-label app and accounting sync are marked “coming soon”; existing plans were updated by a data migration. Roles & permissions: all 61 permissions have a label and group, platform-only ones are flagged, every admin screen is guarded by a policy or a permission check, and the built-in roles cover the new areas (maintenance, charger control, reports, franchises; new read-only Regional manager role). Re-run this check whenever a new module is added.

The checklist used:

1. **Plans & features (Admin → Settings → Plans & features).** Make sure every module built in Phases A–H appears as a feature switch, is assigned to the right plan (Free or Premium, or a new plan), and that the code checks the switch. Keep the Free plan in line with SRS §22. Update the plan descriptions, prices and station limits, and re-check the partner go-live checklist and plan card on the dashboard.
2. **Roles & permissions (Admin → Settings → Roles & permissions).** Make sure every admin screen and action has a permission, that each permission has a clear label and group, that platform-only permissions are flagged, and that the built-in roles (Company owner, Operations admin, Finance, Station manager, Auditor) have the right permissions. Re-check custom roles created during the project.
3. Run the full test suite, then review the audit log for any capability that was used but is not covered by a permission.

