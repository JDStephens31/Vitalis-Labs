# Vitalis Labs — WordPress + WooCommerce store

> **Both storefronts are live.** This WordPress store runs alongside the static
> `index.html` + Supabase site — it does **not** replace it. Features asked for
> generically (like the site password gate) generally need building in both.

A WordPress + WooCommerce store: a custom dark theme matching the original design,
the four catalog products, **offline/manual payment methods**, and **native order
tracking** (WooCommerce → Orders).

Everything runs in Docker. You bring the stack up once, run one setup command, and
the store provisions itself.

---

## What you get

| Piece | How it's handled |
|---|---|
| Products | 4 WooCommerce products seeded (BPC-157, GHK-Cu, Reta 10mg, Reta 30mg) with images, stock, prices |
| Payment | **Venmo only** — manual. Order goes on hold and the customer is emailed the QR code, link, amount and order number |
| Order tracking | Built into WooCommerce: **wp-admin → WooCommerce → Orders**, with statuses |
| Design | Custom `vitalis` theme — dark palette, Space Grotesk/Mono, purple accents (matches old site) |
| Customer accounts | WooCommerce **My Account** page — customers see their own order history + status |
| Site password | Shared invite password + 21+ / not-for-human-consumption gate on entry, and again at checkout (see below) |

---

## First-time setup

### 1. Start Docker Desktop
Docker is installed but not running. Open **Docker Desktop** and wait until it says *Engine running*.

### 2. Bring up WordPress + MySQL
From the repo root (`Vitalis Labs/`):

```bash
docker compose up -d
```

Wait ~30–60s the first time (it downloads the WordPress + MySQL images and copies core files).

### 3. Provision the store (one command)
```bash
docker compose run --rm wpcli sh /setup.sh
```

This installs WordPress, installs & activates WooCommerce, activates the Vitalis theme,
turns on the three offline payment methods, imports the vial images, and creates the
four products. It's **idempotent** — safe to re-run.

> **Using Git Bash instead of PowerShell?** Git Bash rewrites the `/setup.sh` argument
> into a Windows path. Prefix the command with `MSYS_NO_PATHCONV=1`:
> `MSYS_NO_PATHCONV=1 docker compose run --rm wpcli sh /setup.sh`. In PowerShell/CMD the
> plain command works as-is.

### 4. Open the site
- **Storefront:** http://localhost:8080
- **Shop:** http://localhost:8080/shop/
- **Admin:** http://localhost:8080/wp-admin/  → log in with `admin` / `vitalis-admin`
  (change these in `.env` *before* step 3, or change the password in wp-admin after).

---

## Managing orders (what you asked for)

Every order lands in **wp-admin → WooCommerce → Orders**.

The status lifecycle (maps closely to your old Supabase one):

| WooCommerce status | Meaning | Old site equivalent |
|---|---|---|
| **Pending payment** | placed, not yet paid | `new` |
| **On hold** | placed, awaiting the Venmo payment (where every order starts) | `new` |
| **Processing** | payment confirmed, preparing/packing | `in_progress` / `ready_for_delivery` |
| **Completed** | shipped / fulfilled | `delivered` |
| **Cancelled** | voided (no payment) | `cancelled` |
| **Refunded** | money returned | — |

Your manual flow: every order arrives as **On hold** with the customer already emailed
their Venmo instructions. When you see the matching Venmo payment (the note carries the
order number), open the order and set it to **Processing**, then **Completed** when it
ships. The customer sees each change under their **My Account → Orders**. You can also
add private/customer notes on each order.

---

## The site password gate

One shared password guards the storefront in two places:

1. **On entry** — a full-screen prompt asking for the password plus a required
   confirmation that the visitor is **21 or older** and understands the products are
   **not for human consumption**. Until it passes, no page renders at all: it's a
   server-side block on `template_redirect` returning `401` with no page content, so
   view-source and `curl` can't walk around it. "Remember me" keeps the visitor in for
   30 days; otherwise the unlock dies with the browser session.
2. **At checkout** — the same password again, right above *Place order*, validated
   server-side. A wrong password blocks the order outright.

The password is stored **only as a hash** (`wp_hash_password()`) in the
`vitalis_gate_hash` option and checked on the server, so it never appears in the page
source — the same property as the Supabase `verify_referral_password()` design.

- **Change it:** wp-admin → **Settings → Vitalis Gate**. Changing it immediately
  re-gates everyone.
- **Starting value:** `V1T4L1S` (matches the Supabase site's password).
- **Admins are never gated**, so you can't lock yourself out of your own store.
  `wp-admin` and `wp-login.php` are always reachable.
- **Code:** `wordpress/wp-content/themes/vitalis/inc/gate.php`. It lives in the theme,
  which is bind-mounted — edits show on refresh. Note the tradeoff: switching themes
  would disable the gate. If that ever becomes a risk, move the file to a mu-plugin.

## Accounts and ordering

Ordering **requires an account** — that's what ties an order to a customer so it shows
under **My Account → Orders**, and it's the WooCommerce equivalent of the Supabase
site's per-user order history.

Configured via WP-CLI (already applied):

| Option | Value | Effect |
|---|---|---|
| `woocommerce_enable_myaccount_registration` | `yes` | Sign-up form on **My Account** |
| `woocommerce_enable_signup_and_login_from_checkout` | `yes` | Can register during checkout |
| `woocommerce_enable_checkout_login_reminder` | `yes` | Existing customers prompted to log in |
| `woocommerce_enable_guest_checkout` | `no` | No anonymous orders |

> Registration was **off** on both My Account and checkout, which is why creating an
> account didn't work. That's now enabled.

The **Cart** and **Checkout** pages were switched from the WooCommerce block versions to
the classic shortcodes (`[woocommerce_cart]` / `[woocommerce_checkout]`) — the theme's
CSS was written for the classic markup, and the classic checkout is what lets the
password field render and validate in plain PHP. The original block markup is preserved
on each page in the `_vitalis_block_backup` post meta if you ever want to switch back.

> **Heads up:** order #27 (`stephensjonathan52@gmail.com`) was placed as a *guest* before
> guest checkout was disabled, so it has no `customer_id` and won't appear under anyone's
> My Account. You can attach it to a customer from wp-admin → WooCommerce → Orders.

## Payment: Venmo

The three offline gateways (bank transfer / check / cash on delivery) are **disabled**.
The only method is a custom **Venmo** gateway — no money moves through the site.

When an order is placed it goes **on hold** and the customer is **emailed** everything
needed to pay:

- the **amount** to send and their **order number**
- the **Venmo handle** and the **QR code** (`assets/venmo.png` in the theme)
- an **Open in Venmo** button (deep-links to the app on a phone)
- a **note to paste** — `Order <number> / <name>` — so you can match the payment
- the three payment steps, plus your refund/rejection note

**The payment details appear only in that email — never on the website.** The thank-you
page just confirms the email was sent and to which address. That keeps the QR, handle and
amount in exactly one place: the customer's inbox, with no page to share, cache, or
screenshot them from.

You mark the order **Processing** once the Venmo payment lands, then **Completed** when
it ships. The customer sees each change in **My Account → Orders**.

Settings — handle, link, QR URL, wording — are at
**WooCommerce → Settings → Payments → Venmo**. Code: `inc/venmo.php`.

> The Venmo note is deliberately just the order number and name. Venmo transaction notes
> can be publicly visible, so the phone number and shipping address the static site put
> there are left out.

## Email delivery

**This was completely broken** — the WordPress image has no `sendmail` binary, so
`wp_mail()` returned `false` and *no* order email ever sent. Fixed by routing mail over
SMTP (`inc/mail.php`, configured by the `VITALIS_*` constants).

Locally, mail goes to a **mailpit** container instead of real inboxes:

```bash
docker compose up -d          # brings up mailpit alongside the rest
```

Read everything the site sends at **http://localhost:8025**.

**To watch an order email arrive:** open http://localhost:8025 in one tab, place an order
in another, and the *"Your Vitalis Labs order has been received!"* message appears
instantly — QR code and all. Since payment details are email-only now, this is also how
you check what a customer actually receives.

**For production:** point the `VITALIS_SMTP_*` values in `.env` at a real SMTP provider
and remove the `mailpit` service from `docker-compose.yml`. Nothing in the theme changes.

> `docker-compose.yml` passes these through `WORDPRESS_CONFIG_EXTRA`, but the WordPress
> image only writes `wp-config.php` when one doesn't already exist — so on this existing
> install they were set directly with `wp config set`. A fresh `docker compose down -v`
> install picks them up from the compose file automatically.

## The referral field

Checkout requires the **full name of the person who referred you** (first *and* last —
a single word is rejected). It's stored on the order as `_vitalis_referral` and shown in
wp-admin on the order screen, in both the customer and admin emails, and on the
customer's own order view. Code: `inc/referral.php`.

## Everyday commands

```bash
docker compose up -d          # start the site
docker compose stop           # stop it (data is kept)
docker compose down           # stop + remove containers (DB + files kept in volumes)
docker compose down -v        # DANGER: also deletes the database + uploads
docker compose logs -f wordpress
```

Run any WP-CLI command:
```bash
docker compose run --rm wpcli wp option get siteurl
docker compose run --rm wpcli wp wc product list --user=admin
```

---

## Customizing

- **Theme design:** edit `wordpress/wp-content/themes/vitalis/style.css` — it's bind-mounted,
  so changes show on refresh. Design tokens (colors/fonts) are at the top of the file.
- **Products / prices / stock:** wp-admin → Products (or re-run setup after editing `docker/setup.sh`).
- **Venmo handle / link / QR / wording:** wp-admin → WooCommerce → Settings → Payments →
  **Venmo**. Drives both the thank-you page and the payment email.
- **Site password:** wp-admin → Settings → **Vitalis Gate**.
- **Admin/site credentials & DB passwords:** `.env` (gitignored).

---

## Notes on the other storefront

The static site (`index.html`, `doc-page.js`, `support.js`, `dont-push/*.sql`, Supabase)
is **still live and maintained** — it is not retired. Nothing here migrates data between
them: this is a separate WooCommerce catalog with its own accounts and orders.

The two share only a *design*, not data. The site password gate exists on both, but each
stores its own hash — WordPress in the `vitalis_gate_hash` option, Supabase in
`public.referral_gate`. **Rotating the password means changing it in both places**:
Settings → Vitalis Gate here, and the `insert` at the bottom of
`dont-push/supabase-referral-gate.sql` there.

The Venmo payment flow is **not** carried over; WooCommerce uses the standard offline
gateways instead. If you later want a Venmo-style manual option with a QR image, that can
be added as a small custom gateway on top of this.

## Files added

```
docker-compose.yml                       # WordPress + MySQL + wp-cli
.env                                      # local credentials (gitignored)
docker/setup.sh                          # one-shot provisioning script
wordpress/wp-content/themes/vitalis/      # custom dark theme
  style.css  functions.php  header.php  footer.php  index.php  front-page.php
  assets/venmo.png                        # Venmo QR (resized for email)
  inc/gate.php                            # site password gate + checkout check
  inc/referral.php                        # required "referred by" field
  inc/venmo.php                           # Venmo gateway + payment email
  inc/mail.php                            # SMTP delivery
```
