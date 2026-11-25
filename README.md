# LOTAL GHANA — PHP application

Complete, dependency-free PHP 8 build of the LOTAL GHANA store: graded used-laptop catalogue,
account registration, checkout, Paystack payments, unique order-tracking codes and a staff
operations console. Plain PHP + PDO against PostgreSQL (Supabase) — no framework, no Composer
packages to install, nothing to compile.

This folder is the **PHP system**. The sibling Next.js storefront (`../src`) is the same product
built for the Node runtime; both read and write the *same* database tables and share the same
tracking-code and password-hash formats, so an account created in one works in the other.

---

## 1. What is in here

```
php/
├── api/
│   ├── index.php            front controller — every route, form action and JSON endpoint
│   ├── webhook.php          standalone Paystack webhook receiver (paste this URL in Paystack)
│   ├── inc/
│   │   ├── bootstrap.php    .env loader, config, money/date helpers, CSRF, tracking-code engine
│   │   ├── db.php           PDO connection, DB-backed sessions, all SQL for catalogue + orders
│   │   ├── auth.php         register / login / logout / password reset (PBKDF2-SHA256)
│   │   ├── store.php        session cart, totals, order placement in one transaction
│   │   ├── paystack.php     initialise, verify, webhook signature check + event handling
│   │   ├── pages.php         layout, nav, footer, product card, shared fragments
│   │   ├── storefront.php   home, inventory, product detail
│   │   ├── pages-cart.php   cart, checkout, payment, order, tracking, account, auth, 404
│   │   └── admin.php        operations console (login + 4 tabs)
│   └── ...
├── assets/                  app.css, app.js, img/ (photography + icon)
├── schema.sql               tables, indexes and seed data (idempotent)
├── vercel.json              PHP runtime + rewrites so every path reaches api/index.php
├── .htaccess                same routing for Apache / cPanel hosting
├── router.php               `php -S localhost:8080 router.php` for local work
├── index.php                document-root entry for non-Vercel hosts
├── admin.php / admin.html   staff entry points
└── .env.example             copy to .env and fill in
```

### Routes

| URL | Purpose |
| --- | --- |
| `/` | Home — hero with rotating word carousel, inventory with sticky brand rail, grading process, warranty, delivery zones, testimonial carousel, image carousel |
| `/shop` | Inventory with brand chips, search and sorting (`?brand=`, `?q=`, `?sort=`) |
| `/product/{slug}` | Full spec table, battery-health bar, honest condition note, add-to-cart, delivery estimates, related units |
| `/cart` | Session cart, quantity changes, totals |
| `/login` · `/register` · `/forgot-password` · `/reset-password` | Account flow — a signed-in account is required to buy |
| `/checkout` | Delivery details + payment method, live delivery-fee recalculation |
| `/pay/{code}` | Paystack inline checkout for that order (card, bank, MoMo, Telecel Cash, AT, USSD, QR) |
| `/order/{code}` | Confirmation, unique tracking code, live status rail, event log, receipt |
| `/track` | Public tracking — works with no account; validates the code checksum first |
| `/account` | Profile, order history with tracking codes and progress rails, sign out |
| `/admin` `/admin.php` `/admin.html` | Operations console — **never linked from the storefront** |
| `/install` | Creates tables, indexes and seed data, then the admin account |
| `/paystack/webhook` | Signed Paystack events (`charge.success` → order becomes `paid`) |
| `/health` | JSON liveness probe |

---

## 2. Database (Supabase / PostgreSQL)

1. Create a Supabase project → **Project Settings → Database** → copy the connection string.
   Use the **session pooler (port 5432)** locally and the **transaction pooler (port 6543)** on
   Vercel (`PGBOUNCER_URL`).
2. Put it in `php/.env` as `DATABASE_URL`.
3. Open `https://your-site/install` — it applies `schema.sql` statement by statement
   (`users`, `php_sessions`, `password_reset_tokens`, `products`, `orders`, `order_items`,
   `order_events`, `testimonials`, `admin_audit`) and seeds 12 laptops, testimonials and the admin.
   Or run it yourself: `psql "$DATABASE_URL" -f schema.sql`.
4. Set `ALLOW_INSTALL=0` afterwards.

For the full Supabase hardening layer (Auth sync trigger, Row Level Security, Realtime
publication for live tracking, Storage bucket, pgvector column) apply **`../supabase/schema.sql`**
as well — the table names and columns are identical.

## 3. Paystack

Add your keys to `php/.env` (or Vercel environment variables) — they are read at runtime and are
never written to the database:

```
PAYSTACK_SECRET_KEY=sk_live_…      # server: initialise, verify, sign webhooks
PAYSTACK_PUBLIC_KEY=pk_live_…      # browser: inline checkout popup
PAYSTACK_CURRENCY=GHS
```

* Prices are stored in **pesewas** (GH₵ × 100), which is exactly the minor unit Paystack bills for
  GHS, so the order total is passed straight through — no conversion drift.
* The payment `reference` is the order's tracking code without dashes (`LTN4KZP92QF7M`), so any
  payment can be traced back to its order without storing extra IDs.
* Flow: `POST /paystack/start` → inline popup → `POST /paystack/confirm` (server-side
  `transaction/verify`) → order moves to **paid** and a tracking event is written.
* `GET /paystack/callback?reference=…` is the fallback redirect for the hosted page.
* Webhook: put `https://your-domain/paystack/webhook` (or `/api/webhook.php` on shared hosting) in
  Paystack → Settings → Integrations → Webhooks. Subscribes to `charge.success` and `charge.failed`.
  The handler requires `X-Paystack-Signature` to match `hash_hmac('sha512', rawBody, SECRET_KEY)`
  and rejects anything else with 401. It is idempotent — replayed events cannot move an order
  backwards.
* Until keys are live the store still works: the payment page shows your bank-transfer details
  (`PAYSTACK_BANK_NAME`, `PAYSTACK_BANK_ACCOUNT`, `PAYSTACK_BANK_HOLDER`) and pay-on-delivery.
* **Admin → Payments** tab shows which variables are set (masked), the current mode
  (`LIVE` / `TEST MODE` / `NOT CONFIGURED`), the webhook URL to paste and the offline fallback.

## 4. Deploy to Vercel

```bash
cd php
vercel link                 # create/select the project
vercel env add DATABASE_URL            # then paste your Supabase string
vercel env add PAYSTACK_SECRET_KEY
vercel env add PAYSTACK_PUBLIC_KEY
vercel env add SITE_URL                # https://your-project.vercel.app
vercel env add ADMIN_PASSWORD
vercel --prod
```

`vercel.json` pins the PHP runtime (`vercel-php@0.9.0` → PHP 8.5; switch to `0.8.0` for PHP 8.4),
declares `api/index.php` and `api/webhook.php` as functions, and rewrites every path — including
`/admin.html` and `/admin.php` — to the front controller. Static files under `/assets/` are served
straight from the CDN. If you prefer the dashboard: import the repo, set **Root Directory** to
`php`, framework: **Other**, and leave the build command empty.

Then open `/<…>/install` once, sign in at `/admin.html`, and set `ALLOW_INSTALL=0`.

### Shared hosting (Apache / cPanel)

Upload the contents of `php/` into `public_html`. `.htaccess` rewrites everything to `index.php`,
blocks `api/inc`, `.env` and `schema.sql`, and the same `/admin.html` and `/admin.php` URLs work.
Create `.env` from `.env.example`.

### Local development

```bash
cd php
cp .env.example .env      # fill in DATABASE_URL + Paystack test keys
composer serve            # or: php -S localhost:8080 router.php
```

## 5. Security notes

* All SQL uses prepared statements; all output is escaped with `htmlspecialchars`.
* Passwords: PBKDF2-HMAC-SHA256, 100 000 iterations, per-user 16-byte salt
  (`hash_pbkdf2` ↔ Node `crypto.pbkdf2Sync`, format `pbkdf2$iterations$salt$hash`). Legacy
  `scrypt$…` hashes written by the Next.js app are still recognised.
* Sessions: 32-byte random token in an `HttpOnly`, `SameSite=Lax`, `Secure` cookie, stored in
  `php_sessions` with a 30-day expiry — required because Vercel functions are stateless, so PHP
  session files would be lost between invocations. The cart and CSRF token live in the same row.
* CSRF: every state-changing POST is checked; Paystack's webhook is exempted and verified by HMAC.
* Customers can only pay/see orders that belong to them (ownership check on `user_id`);
  the console requires `users.role = 'admin'`.
* Tracking codes are 8 cryptographically random characters from an unambiguous alphabet
  (`ABCDEFGHJKLMNPQRSTUVWXYZ23456789`) plus a check character, enforced unique by
  `orders.code`, so guesses fail instantly and mistypes are caught before hitting the database.
* Admin activity can be recorded in `admin_audit`; the console is `noindex` and unlinked.

## 6. Store policy

`WARRANTY_DAYS=14` (with `RETURN_DAYS=7` and `DIAGNOSTIC_POINTS=32`) drives every warranty line in
the UI — product pages, cart, checkout, account, footer and tracking events — so changing the
policy means editing one variable, not hunting through templates.
