# Vitalis Labs — Checkout Setup

The store has a full cart → checkout → Venmo confirmation flow. The cart lives in the
browser's `localStorage`; placing an order writes it to a **write-only** Supabase table
and shows a confirmation with your Venmo QR and a copy-paste note.

Access is **two-tier**:
- **Website** (public `anon` key) → can only **INSERT** orders. It can never read,
  edit, or delete, and the order status is forced server-side.
- **Your Electron admin app** (signed in with Supabase Auth, on the `admins` allowlist)
  → full read / update / delete to manage the order lifecycle.

The site's config (Supabase URL + key, Venmo handle/link/QR) is **already filled in**.
The one remaining step to go live is creating the database (below).

## 1. Create the database

1. In your Supabase project open **SQL Editor → New query**, paste the contents of
   [`supabase-schema.sql`](supabase-schema.sql), and **Run**. This creates:
   - `orders` — insert-only for the website; a trigger forces new orders to
     status `new` so a tampered request can't self-mark an order delivered.
   - `admins` — the allowlist of people who can manage orders.
   - `is_admin()` + RLS policies wiring the two tiers together.
2. **Add the products + stock layer.** In the SQL Editor, run these two files
   (in order), each once:
   - [`supabase-products.sql`](supabase-products.sql) — creates the `products`
     table (name, price, live `stock`, image pointer), a public
     **`product-images`** storage bucket, the anon-read / admin-write policies,
     seeds the four catalog items, and adds `referral` + `shipping_cents` columns
     to `orders`.
   - [`supabase-stock-functions.sql`](supabase-stock-functions.sql) — the trigger
     that **decrements product stock** whenever a new order is placed, plus an
     `adjust_stock(id, delta)` helper for restocking.

   Then upload your vial images to **Storage → product-images** using the
   filenames in the seed (`bpc-157.png`, `ghk-cu.png`, `reta-10.png`,
   `reta-30.png`). The catalog loads live from this table — item info, price, and
   the quantity dropdown's max all come from `products.stock`. If the fetch ever
   fails, the site falls back to a built-in copy of the catalog.
4. **Create your admin account** in the Electron app (Supabase Auth email/password).
5. **Promote yourself** — back in the SQL Editor, run (with your email):
   ```sql
   insert into public.admins (user_id, email)
   select id, email from auth.users
   where email = 'you@example.com'
   on conflict (user_id) do nothing;
   ```
6. Optional but recommended: **Authentication → Settings**, turn **off** public
   sign-ups once your account exists (the allowlist already blocks data access).

## 2. Site config (already set)

Near the top of the `class Component` block in `Vitalis Labs.html`
(search `─── CONFIG ───`) these are filled in — shown here for reference:

```js
SUPABASE_URL  = 'https://roocyinfalcqgpdajoar.supabase.co';
SUPABASE_ANON = 'eyJ...';                              // anon public key (safe to expose)
VENMO_HANDLE  = '@Vitalislabs';                        // shown under the QR
VENMO_LINK    = 'https://venmo.com/code?user_id=...';  // Open-in-Venmo button
```

> The anon key is **meant to be public** — safe in front-end code. With the
> insert-only policy, the worst anyone can do with it is submit an order. Never put
> the **service_role** key in the website or the shipped Electron app.

`VENMO_LINK` powers the **Open in Venmo** button on the confirmation screen — on a
phone it opens the Venmo app straight to your profile.

## 3. Venmo QR image

Your QR code lives at:

```
assets/venmo.png
```

(Already added.) It renders at 180×180 on the confirmation screen. To swap it, replace
that file — get a fresh one from the Venmo app: **Me → the QR icon (top) → Share**.

## How it works

- **Cart** persists in `localStorage` (`vitalis_cart`) across reloads.
- **Checkout** collects name, email, phone, and shipping address (validated).
- **Place order** generates an order number client-side (`VL-YYMMDD-####`), computes
  totals, and `POST`s the order to Supabase. A copy is also mirrored to
  `localStorage` (`vitalis_orders`) as a safety net.
- **Confirmation** shows the order number, amount, your Venmo QR, and a pre-filled
  note (Order #, Name, Phone, Address) with a **Copy** button. No payment is taken
  on the site — you fulfill an order once the matching Venmo payment arrives.

## Managing orders (Electron admin app)

Your admin app signs in with Supabase Auth; once your user is in the `admins`
allowlist, RLS grants it full read/update/delete on `orders`. The status lifecycle is:

| status               | meaning                                   |
|----------------------|-------------------------------------------|
| `new`                | just placed — verify the Venmo payment    |
| `in_progress`        | payment received, preparing the order     |
| `ready_for_delivery` | packed, awaiting dispatch                 |
| `delivered`          | shipped / handed off                      |
| `cancelled`          | voided (no payment, etc.)                 |

Build your dashboard buckets by filtering on `status` (indexed), e.g.
`select * from orders where status = 'new' order by created_at desc`. `updated_at`
auto-bumps on every change. Until a user is added to `admins`, they see nothing — so
you can safely reuse the same anon key in the Electron app for auth.

## Testing before going live

- Add items, reload → cart should persist.
- Go to checkout, submit with a bad email/phone → inline errors block submission.
- With real keys filled in, place a valid order → a row appears in the Supabase
  Table editor, and the confirmation screen shows the QR + copyable note.
